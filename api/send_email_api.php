<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/email_sender.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

try {
    // Get form data
    $recipientId = $_POST['recipient_id'] ?? '';
    $smtpConfigId = $_POST['smtp_config_id'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $body = $_POST['body'] ?? '';
    
    // Validate required fields
    if (empty($recipientId) || empty($smtpConfigId) || empty($subject) || empty($body)) {
        jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
    }
    
    // Handle attachments
    $attachments = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $fileCount = count($_FILES['attachments']['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $fileName = $_FILES['attachments']['name'][$i];
                $fileTmpName = $_FILES['attachments']['tmp_name'][$i];
                $fileSize = $_FILES['attachments']['size'][$i];
                
                // Validate file size
                if ($fileSize > MAX_FILE_SIZE) {
                    jsonResponse(['success' => false, 'message' => "File $fileName exceeds maximum size of 10MB"], 400);
                }
                
                // Validate file extension
                $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                if (!in_array($extension, ALLOWED_EXTENSIONS)) {
                    jsonResponse(['success' => false, 'message' => "File type .$extension is not allowed"], 400);
                }
                
                // Generate unique filename
                $uniqueFileName = uniqid() . '_' . basename($fileName);
                $uploadPath = ATTACHMENT_DIR . '/' . $uniqueFileName;
                
                // Move uploaded file
                if (move_uploaded_file($fileTmpName, $uploadPath)) {
                    $attachments[] = [
                        'name' => $fileName,
                        'path' => $uploadPath
                    ];
                } else {
                    jsonResponse(['success' => false, 'message' => "Failed to upload file $fileName"], 500);
                }
            }
        }
    }
    
    // Send email
    $result = sendEmailToRecipient($recipientId, $smtpConfigId, $subject, $body, $attachments);
    
    if ($result['success']) {
        jsonResponse([
            'success' => true,
            'message' => 'Email sent successfully'
        ]);
    } else {
        // Clean up uploaded attachments if email failed
        foreach ($attachments as $attachment) {
            if (file_exists($attachment['path'])) {
                unlink($attachment['path']);
            }
        }
        
        jsonResponse([
            'success' => false,
            'message' => 'Failed to send email: ' . $result['error']
        ], 500);
    }
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
?>
