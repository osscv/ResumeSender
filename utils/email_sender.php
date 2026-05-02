<?php
/**
 * Native PHP SMTP Email Sender
 * No external dependencies required
 */
class SMTPMailer {
    private $smtp_host;
    private $smtp_port;
    private $smtp_username;
    private $smtp_password;
    private $smtp_encryption;
    private $from_email;
    private $from_name;
    private $socket;
    private $error;
    
    public function __construct($config) {
        $this->smtp_host = $config['smtp_host'];
        $this->smtp_port = $config['smtp_port'];
        $this->smtp_username = $config['smtp_username'];
        $this->smtp_password = $config['smtp_password'];
        $this->smtp_encryption = $config['smtp_encryption'];
        $this->from_email = $config['from_email'];
        $this->from_name = $config['from_name'];
    }
    
    public function sendEmail($to, $subject, $body, $attachments = []) {
        try {
            if (!$this->open()) {
                return false;
            }
            $this->sendOne($to, $subject, $body, $attachments);
            $this->close();
            return true;
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->forceCloseSocket();
            return false;
        }
    }

    /**
     * Open SMTP connection (for reuse across multiple sendOne calls).
     */
    public function open() {
        return $this->connect();
    }

    /**
     * Send a single message over an already-open connection.
     * Throws on error so callers can decide whether to continue or reopen.
     * The optional $encodedAttachments array lets callers reuse pre-encoded
     * attachment bodies across many recipients (avoids re-reading/encoding files).
     */
    public function sendOne($to, $subject, $body, $attachments = [], $encodedAttachments = null) {
        if (!$this->socket) {
            throw new Exception("SMTP connection not open");
        }
        $boundary = md5(uniqid((string)mt_rand(), true));

        $this->sendCommand("MAIL FROM: <{$this->from_email}>", 250);
        $this->sendCommand("RCPT TO: <{$to}>", 250);
        $this->sendCommand("DATA", 354);

        $headers = $this->buildHeaders($to, $subject, $attachments, $boundary);
        $message = $this->buildMessage($body, $attachments, $boundary, $encodedAttachments);

        $email = $headers . "\r\n" . $message . "\r\n.";
        fputs($this->socket, $email . "\r\n");
        $this->getResponse(250);
    }

    /**
     * Gracefully close the SMTP connection.
     */
    public function close() {
        if (!$this->socket) return;
        try {
            $this->sendCommand("QUIT", 221);
        } catch (Exception $e) {
            // ignore — we're closing anyway
        }
        $this->forceCloseSocket();
    }

    private function forceCloseSocket() {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function connect() {
        $errno = 0;
        $errstr = '';

        // Determine connection type
        if ($this->smtp_encryption == 'ssl') {
            $host = 'ssl://' . $this->smtp_host;
        } else {
            $host = $this->smtp_host;
        }

        // Open socket connection
        $this->socket = @fsockopen($host, $this->smtp_port, $errno, $errstr, 30);

        if (!$this->socket) {
            throw new Exception("Failed to connect: $errstr ($errno)");
        }

        // Get server response
        $this->getResponse(220);

        // EHLO must identify the *client*, not the server. Outlook/Office 365
        // rejects EHLO arguments that look like the server's own hostname or
        // are otherwise unparseable, so use the local hostname (FQDN if we
        // have one), and fall back to the from-domain or a sane literal.
        $clientHost = $this->getClientHostname();

        // Send EHLO
        $this->sendCommand("EHLO " . $clientHost, 250);

        // Start TLS if required
        if ($this->smtp_encryption == 'tls') {
            $this->sendCommand("STARTTLS", 220);
            // Microsoft 365 / Outlook deprecated TLS 1.0 and 1.1. PHP's
            // STREAM_CRYPTO_METHOD_TLS_CLIENT constant is bound to TLS 1.0 on
            // many builds, so request TLS 1.2/1.3 explicitly.
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!stream_socket_enable_crypto($this->socket, true, $crypto)) {
                throw new Exception("Failed to enable TLS encryption (server may require TLS 1.2+)");
            }
            // Send EHLO again after STARTTLS
            $this->sendCommand("EHLO " . $clientHost, 250);
        }

        // Authenticate
        $this->sendCommand("AUTH LOGIN", 334);
        $this->sendCommand(base64_encode($this->smtp_username), 334);
        $this->sendCommand(base64_encode($this->smtp_password), 235);

        return true;
    }

    private function getClientHostname() {
        $host = function_exists('gethostname') ? gethostname() : '';
        if ($host && strpos($host, '.') !== false) {
            return $host;
        }
        // Prefer the from-address domain — gives a meaningful, parseable EHLO.
        if (!empty($this->from_email) && strpos($this->from_email, '@') !== false) {
            return substr(strrchr($this->from_email, '@'), 1);
        }
        return $host ?: 'localhost.localdomain';
    }
    
    private function sendCommand($command, $expectedCode) {
        fputs($this->socket, $command . "\r\n");
        return $this->getResponse($expectedCode);
    }
    
    private function getResponse($expectedCode) {
        // SMTP multi-line responses repeat the code on every line, with `-`
        // after the code on continuation lines and ` ` after the code on the
        // final line. Track that explicitly per-line, because long EHLO lines
        // (Outlook lists many extensions) can exceed any single fgets read and
        // the previous "look at byte 3 of whatever fgets returned" check would
        // misfire when a line was split mid-way.
        $response = '';
        $finalLineSeen = false;
        while (!$finalLineSeen && ($line = fgets($this->socket, 1024)) !== false) {
            $response .= $line;
            // We only know we have a complete logical line when the buffer
            // ends with \n. If not, this was a partial read — keep going.
            if (substr($line, -1) !== "\n") {
                continue;
            }
            // Find the start of the last logical line in the buffer.
            $trimmed = rtrim($response, "\r\n");
            $lastNl = strrpos($trimmed, "\n");
            $lastLine = $lastNl === false ? $trimmed : substr($trimmed, $lastNl + 1);
            // Final line of an SMTP reply has a space at index 3 ("250 OK"),
            // continuation lines have a hyphen ("250-EXTENSION").
            if (strlen($lastLine) >= 4 && $lastLine[3] === ' ') {
                $finalLineSeen = true;
            }
        }

        $code = substr($response, 0, 3);
        if ((string)$code !== (string)$expectedCode) {
            throw new Exception("SMTP Error: Expected $expectedCode, got $code - " . trim($response));
        }

        return $response;
    }
    
    private function buildHeaders($to, $subject, $attachments, $boundary) {
        $headers = "From: {$this->from_name} <{$this->from_email}>\r\n";
        $headers .= "To: <{$to}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        
        if (!empty($attachments)) {
            $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
        } else {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        }
        
        return $headers;
    }
    
    private function buildMessage($body, $attachments, $boundary, $encodedAttachments = null) {
        if (empty($attachments)) {
            return $body;
        }

        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $message .= chunk_split(base64_encode($body)) . "\r\n";

        foreach ($attachments as $idx => $attachment) {
            if (isset($encodedAttachments[$idx])) {
                $content = $encodedAttachments[$idx];
            } elseif (file_exists($attachment['path'])) {
                $content = chunk_split(base64_encode(file_get_contents($attachment['path'])));
            } else {
                continue;
            }
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: application/octet-stream; name=\"{$attachment['name']}\"\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n";
            $message .= $content . "\r\n";
        }

        $message .= "--{$boundary}--";

        return $message;
    }

    /**
     * Pre-encode attachment file contents (base64) so a batch can reuse them
     * across many sendOne() calls without re-reading the file each time.
     */
    public static function encodeAttachments($attachments) {
        $out = [];
        foreach ($attachments as $idx => $attachment) {
            if (!empty($attachment['path']) && file_exists($attachment['path'])) {
                $out[$idx] = chunk_split(base64_encode(file_get_contents($attachment['path'])));
            }
        }
        return $out;
    }
    
    public function getError() {
        return $this->error;
    }
}

/**
 * Send email to a recipient
 */
function sendEmailToRecipient($recipientId, $smtpConfigId, $subject, $body, $attachments = []) {
    require_once __DIR__ . '/../config.php';
    
    $db = getDBConnection();
    
    try {
        // Get recipient details
        $stmt = $db->prepare("SELECT * FROM recipients WHERE id = ?");
        $stmt->execute([$recipientId]);
        $recipient = $stmt->fetch();
        
        if (!$recipient) {
            throw new Exception("Recipient not found");
        }
        
        // Get SMTP configuration
        $stmt = $db->prepare("SELECT * FROM smtp_configurations WHERE id = ? AND is_active = 1");
        $stmt->execute([$smtpConfigId]);
        $smtpConfig = $stmt->fetch();
        
        if (!$smtpConfig) {
            throw new Exception("SMTP configuration not found or inactive");
        }

        // Check if blocked by server
        if ($smtpConfig['quota_exceeded_at'] && date('Y-m-d', strtotime($smtpConfig['quota_exceeded_at'])) == date('Y-m-d')) {
            throw new Exception("SMTP Blocked: Daily quota exceeded on server.");
        }

        // Check local safety limit
        $stmt = $db->prepare("SELECT COUNT(*) FROM email_logs WHERE smtp_config_id = ? AND DATE(sent_at) = CURDATE() AND status = 'success'");
        $stmt->execute([$smtpConfigId]);
        $sentToday = $stmt->fetchColumn();

        if ($sentToday >= $smtpConfig['daily_quota']) {
            throw new Exception("Safety Limit: Daily quota of {$smtpConfig['daily_quota']} reached.");
        }
        
        // Replace placeholders in subject and body
        $subject = str_replace(
            ['{company_name}', '{position}', '{email}'],
            [$recipient['company_name'], $recipient['position'], $recipient['email']],
            $subject
        );
        
        $body = str_replace(
            ['{company_name}', '{position}', '{email}'],
            [$recipient['company_name'], $recipient['position'], $recipient['email']],
            $body
        );
        
        // Send email
        $mailer = new SMTPMailer($smtpConfig);
        $success = $mailer->sendEmail($recipient['email'], $subject, $body, $attachments);
        
        // Log email
        $status = $success ? 'success' : 'failed';
        $errorMessage = $success ? null : $mailer->getError();

        // Smart Error Detection: Check for quota exceeded errors
        if (!$success && $errorMessage) {
            $errorLower = strtolower($errorMessage);
            if (strpos($errorLower, 'quota') !== false || 
                strpos($errorLower, 'limit') !== false || 
                strpos($errorLower, '5.4.5') !== false) {
                
                // Mark SMTP as blocked for today
                $updateStmt = $db->prepare("UPDATE smtp_configurations SET quota_exceeded_at = NOW() WHERE id = ?");
                $updateStmt->execute([$smtpConfigId]);
                
                $errorMessage .= " [Auto-Blocked: Quota Exceeded]";
            }
        }
        
        $stmt = $db->prepare(
            "INSERT INTO email_logs (recipient_id, smtp_config_id, subject, body, status, error_message) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$recipientId, $smtpConfigId, $subject, $body, $status, $errorMessage]);
        
        $emailLogId = $db->lastInsertId();
        
        // Log attachments
        if ($success && !empty($attachments)) {
            $stmt = $db->prepare(
                "INSERT INTO email_attachments (email_log_id, file_name, file_path, file_size) 
                 VALUES (?, ?, ?, ?)"
            );
            
            foreach ($attachments as $attachment) {
                $fileSize = file_exists($attachment['path']) ? filesize($attachment['path']) : 0;
                $stmt->execute([$emailLogId, $attachment['name'], $attachment['path'], $fileSize]);
            }
        }
        
        return [
            'success' => $success,
            'error' => $errorMessage
        ];
        
    } catch (Exception $e) {
        // Log failed attempt
        if (isset($emailLogId)) {
            $stmt = $db->prepare(
                "UPDATE email_logs SET status = 'failed', error_message = ? WHERE id = ?"
            );
            $stmt->execute([$e->getMessage(), $emailLogId]);
        }
        
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
