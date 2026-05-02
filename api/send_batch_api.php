<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/email_sender.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');
@set_time_limit(0);
ignore_user_abort(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

$smtpConfigId = $_POST['smtp_config_id'] ?? '';
$subjectTpl   = $_POST['subject'] ?? '';
$bodyTpl      = $_POST['body'] ?? '';
$recipientIds = $_POST['recipient_ids'] ?? [];

if (!is_array($recipientIds)) {
    $recipientIds = [$recipientIds];
}
$recipientIds = array_values(array_filter(array_map('intval', $recipientIds)));

if (empty($recipientIds) || empty($smtpConfigId) || $subjectTpl === '' || $bodyTpl === '') {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Resolve attachments — clients send a token (basename) issued by the upload
// endpoint; we rebuild the full path server-side and verify the file exists
// inside ATTACHMENT_DIR to prevent path traversal.
$attachments = [];
$tokens = $_POST['attachment_tokens'] ?? [];
$names  = $_POST['attachment_names']  ?? [];
$attachmentRoot = realpath(ATTACHMENT_DIR);

if (!is_array($tokens)) $tokens = [$tokens];
if (!is_array($names))  $names  = [$names];

foreach ($tokens as $i => $tok) {
    if ($tok === '' || $tok === null) continue;
    // Strip any directory components — token must be a plain filename.
    $tok = basename($tok);
    $candidate = ATTACHMENT_DIR . DIRECTORY_SEPARATOR . $tok;
    $real = realpath($candidate);
    if ($real === false || $attachmentRoot === false || strpos($real, $attachmentRoot) !== 0) {
        jsonResponse(['success' => false, 'message' => 'Invalid attachment token'], 400);
    }
    $attachments[] = [
        'name' => $names[$i] ?? $tok,
        'path' => $real,
    ];
}

$db = getDBConnection();

// Load SMTP config once
$stmt = $db->prepare("SELECT * FROM smtp_configurations WHERE id = ? AND is_active = 1");
$stmt->execute([$smtpConfigId]);
$smtpConfig = $stmt->fetch();

if (!$smtpConfig) {
    jsonResponse(['success' => false, 'message' => 'SMTP configuration not found or inactive'], 400);
}

if ($smtpConfig['quota_exceeded_at'] && date('Y-m-d', strtotime($smtpConfig['quota_exceeded_at'])) == date('Y-m-d')) {
    jsonResponse(['success' => false, 'message' => 'SMTP Blocked: Daily quota exceeded on server.'], 400);
}

// Load all recipients in this batch in one query
$placeholders = implode(',', array_fill(0, count($recipientIds), '?'));
$stmt = $db->prepare("SELECT * FROM recipients WHERE id IN ($placeholders)");
$stmt->execute($recipientIds);
$recipientsById = [];
foreach ($stmt->fetchAll() as $r) {
    $recipientsById[(int)$r['id']] = $r;
}

// Today's send count for safety quota check
$stmt = $db->prepare("SELECT COUNT(*) FROM email_logs WHERE smtp_config_id = ? AND DATE(sent_at) = CURDATE() AND status = 'success'");
$stmt->execute([$smtpConfigId]);
$sentToday = (int)$stmt->fetchColumn();
$dailyQuota = (int)$smtpConfig['daily_quota'];

// Pre-encode attachment bodies once for the whole batch
$encodedAttachments = SMTPMailer::encodeAttachments($attachments);
$attachmentSizes = [];
foreach ($attachments as $idx => $a) {
    $attachmentSizes[$idx] = file_exists($a['path']) ? filesize($a['path']) : 0;
}

$logInsert = $db->prepare(
    "INSERT INTO email_logs (recipient_id, smtp_config_id, subject, body, status, error_message)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$attachInsert = $db->prepare(
    "INSERT INTO email_attachments (email_log_id, file_name, file_path, file_size)
     VALUES (?, ?, ?, ?)"
);
$blockSmtp = $db->prepare("UPDATE smtp_configurations SET quota_exceeded_at = NOW() WHERE id = ?");

$mailer = new SMTPMailer($smtpConfig);
$connectionOpen = false;
$connectError = null;
try {
    $mailer->open();
    $connectionOpen = true;
} catch (Exception $e) {
    $connectError = $e->getMessage();
}

$results = [];
$smtpBlockedMidBatch = false;

foreach ($recipientIds as $rid) {
    $recipient = $recipientsById[$rid] ?? null;

    if (!$recipient) {
        $results[] = ['recipient_id' => $rid, 'success' => false, 'message' => 'Recipient not found'];
        continue;
    }

    $recipEmail = $recipient['email'];
    $recipCompany = $recipient['company_name'];

    if ($smtpBlockedMidBatch) {
        $logInsert->execute([$rid, $smtpConfigId, $subjectTpl, $bodyTpl, 'failed', 'SMTP blocked earlier in batch (quota)']);
        $results[] = ['recipient_id' => $rid, 'success' => false, 'email' => $recipEmail, 'company' => $recipCompany, 'message' => 'Skipped — SMTP blocked'];
        continue;
    }

    if (!$connectionOpen) {
        $errMsg = 'SMTP connect failed: ' . ($connectError ?? $mailer->getError() ?? 'unknown');
        $logInsert->execute([$rid, $smtpConfigId, $subjectTpl, $bodyTpl, 'failed', $errMsg]);
        $results[] = ['recipient_id' => $rid, 'success' => false, 'email' => $recipEmail, 'company' => $recipCompany, 'message' => $errMsg];
        continue;
    }

    if ($sentToday >= $dailyQuota) {
        $errMsg = "Safety Limit: Daily quota of {$dailyQuota} reached.";
        $logInsert->execute([$rid, $smtpConfigId, $subjectTpl, $bodyTpl, 'failed', $errMsg]);
        $results[] = ['recipient_id' => $rid, 'success' => false, 'email' => $recipEmail, 'company' => $recipCompany, 'message' => $errMsg];
        continue;
    }

    $subject = str_replace(
        ['{company_name}', '{position}', '{email}'],
        [$recipient['company_name'], $recipient['position'], $recipient['email']],
        $subjectTpl
    );
    $body = str_replace(
        ['{company_name}', '{position}', '{email}'],
        [$recipient['company_name'], $recipient['position'], $recipient['email']],
        $bodyTpl
    );

    $sendError = null;
    try {
        $mailer->sendOne($recipEmail, $subject, $body, $attachments, $encodedAttachments);
        $success = true;
    } catch (Exception $e) {
        $success = false;
        $sendError = $e->getMessage();

        // Quota detection
        $errLower = strtolower($sendError);
        if (strpos($errLower, 'quota') !== false ||
            strpos($errLower, 'limit') !== false ||
            strpos($errLower, '5.4.5') !== false) {
            $blockSmtp->execute([$smtpConfigId]);
            $sendError .= ' [Auto-Blocked: Quota Exceeded]';
            $smtpBlockedMidBatch = true;
        }

        // Try to recover the connection for the next recipient. If RSET fails,
        // close and reopen on the next iteration.
        try {
            // best-effort RSET — if connection is already dead, close & reopen
            $mailer->close();
        } catch (Exception $e2) { /* ignore */ }
        $connectionOpen = false;
        try {
            $mailer->open();
            $connectionOpen = true;
        } catch (Exception $e3) {
            $connectError = $e3->getMessage();
        }
    }

    $status = $success ? 'success' : 'failed';
    $logInsert->execute([$rid, $smtpConfigId, $subject, $body, $status, $sendError]);
    $emailLogId = $db->lastInsertId();

    if ($success) {
        $sentToday++;
        foreach ($attachments as $idx => $a) {
            $attachInsert->execute([$emailLogId, $a['name'], $a['path'], $attachmentSizes[$idx] ?? 0]);
        }
        $results[] = ['recipient_id' => $rid, 'success' => true, 'email' => $recipEmail, 'company' => $recipCompany, 'message' => 'Sent to ' . $recipEmail];
    } else {
        $results[] = ['recipient_id' => $rid, 'success' => false, 'email' => $recipEmail, 'company' => $recipCompany, 'message' => 'Failed: ' . $recipEmail . ' - ' . $sendError];
    }
}

if ($connectionOpen) {
    $mailer->close();
}

jsonResponse(['success' => true, 'results' => $results]);
