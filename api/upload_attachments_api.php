<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isAuthenticated()) {
    jsonResponse(['success' => false, 'message' => 'Not authenticated'], 401);
}

$uploaded = [];

if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) {
    jsonResponse(['success' => true, 'attachments' => []]);
}

$fileCount = count($_FILES['attachments']['name']);

for ($i = 0; $i < $fileCount; $i++) {
    if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'Upload error for file ' . $_FILES['attachments']['name'][$i]], 400);
    }

    $fileName = $_FILES['attachments']['name'][$i];
    $fileTmp  = $_FILES['attachments']['tmp_name'][$i];
    $fileSize = $_FILES['attachments']['size'][$i];

    if ($fileSize > MAX_FILE_SIZE) {
        jsonResponse(['success' => false, 'message' => "File $fileName exceeds maximum size of 10MB"], 400);
    }

    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS)) {
        jsonResponse(['success' => false, 'message' => "File type .$ext is not allowed"], 400);
    }

    $unique = uniqid() . '_' . basename($fileName);
    $dest   = ATTACHMENT_DIR . '/' . $unique;

    if (!move_uploaded_file($fileTmp, $dest)) {
        jsonResponse(['success' => false, 'message' => "Failed to store file $fileName"], 500);
    }

    $uploaded[] = [
        'name'  => $fileName,
        'token' => $unique, // basename only — server reconstructs the full path
    ];
}

jsonResponse(['success' => true, 'attachments' => $uploaded]);
