<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'resumesender');
define('DB_USER', 'resumesender');
define('DB_PASS', 'RcFfmAFwNMnXyyFe');
define('DB_CHARSET', 'utf8mb4');

// Application Configuration
define('APP_NAME', 'Resume Sender');
define('BASE_URL', '');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('ATTACHMENT_DIR', UPLOAD_DIR . '/attachments');
define('CSV_DIR', UPLOAD_DIR . '/csv');

// Maximum file upload size (in bytes) - 10MB
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

// Allowed file extensions for attachments
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png']);

// Create upload directories if they don't exist
if (!is_dir(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!is_dir(ATTACHMENT_DIR)) {
    mkdir(ATTACHMENT_DIR, 0755, true);
}
if (!is_dir(CSV_DIR)) {
    mkdir(CSV_DIR, 0755, true);
}

// Database Connection using PDO
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Helper function to return JSON response
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
