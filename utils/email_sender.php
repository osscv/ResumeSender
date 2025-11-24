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
            // Connect to SMTP server
            if (!$this->connect()) {
                return false;
            }
            
            // Generate a unique boundary
            $boundary = md5(uniqid(time()));

            // Send MAIL FROM
            $this->sendCommand("MAIL FROM: <{$this->from_email}>", 250);
            
            // Send RCPT TO
            $this->sendCommand("RCPT TO: <{$to}>", 250);
            
            // Send DATA
            $this->sendCommand("DATA", 354);
            
            // Build email headers
            $headers = $this->buildHeaders($to, $subject, $attachments, $boundary);
            
            // Build email body
            $message = $this->buildMessage($body, $attachments, $boundary);
            
            // Send the complete email
            $email = $headers . "\r\n" . $message . "\r\n.";
            fputs($this->socket, $email . "\r\n");
            $this->getResponse(250);
            
            // Close connection
            $this->sendCommand("QUIT", 221);
            fclose($this->socket);
            
            return true;
            
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            if ($this->socket) {
                fclose($this->socket);
            }
            return false;
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
        
        // Send EHLO
        $this->sendCommand("EHLO " . $this->smtp_host, 250);
        
        // Start TLS if required
        if ($this->smtp_encryption == 'tls') {
            $this->sendCommand("STARTTLS", 220);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new Exception("Failed to enable TLS encryption");
            }
            // Send EHLO again after STARTTLS
            $this->sendCommand("EHLO " . $this->smtp_host, 250);
        }
        
        // Authenticate
        $this->sendCommand("AUTH LOGIN", 334);
        $this->sendCommand(base64_encode($this->smtp_username), 334);
        $this->sendCommand(base64_encode($this->smtp_password), 235);
        
        return true;
    }
    
    private function sendCommand($command, $expectedCode) {
        fputs($this->socket, $command . "\r\n");
        return $this->getResponse($expectedCode);
    }
    
    private function getResponse($expectedCode) {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        
        $code = substr($response, 0, 3);
        if ($code != $expectedCode) {
            throw new Exception("SMTP Error: Expected $expectedCode, got $code - $response");
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
    
    private function buildMessage($body, $attachments, $boundary) {
        if (empty($attachments)) {
            return $body;
        }
        
        $message = "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $body . "\r\n\r\n";
        
        // Add attachments
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['path'])) {
                $content = chunk_split(base64_encode(file_get_contents($attachment['path'])));
                $message .= "--{$boundary}\r\n";
                $message .= "Content-Type: application/octet-stream; name=\"{$attachment['name']}\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n";
                $message .= "Content-Disposition: attachment; filename=\"{$attachment['name']}\"\r\n\r\n";
                $message .= $content . "\r\n";
            }
        }
        
        $message .= "--{$boundary}--";
        
        return $message;
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
