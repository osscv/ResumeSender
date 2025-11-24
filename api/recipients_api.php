<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/csv_parser.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDBConnection();

try {
    switch ($method) {
        case 'GET':
            // Get all recipients with email history
            $stmt = $db->query("
                SELECT r.*, 
                       COUNT(el.id) as total_emails_sent,
                       MAX(el.sent_at) as last_sent_at
                FROM recipients r
                LEFT JOIN email_logs el ON r.id = el.recipient_id AND el.status = 'success'
                GROUP BY r.id
                ORDER BY r.created_at DESC
            ");
            $recipients = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $recipients
            ]);
            break;
            
        case 'POST':
            // Check if this is a CSV upload
            if (isset($_GET['action']) && $_GET['action'] === 'upload') {
                // Handle CSV upload
                if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                    jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error'], 400);
                }
                
                $file = $_FILES['csv_file'];
                
                // Validate file type
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($extension !== 'csv') {
                    jsonResponse(['success' => false, 'message' => 'Only CSV files are allowed'], 400);
                }
                
                // Move uploaded file
                $uploadPath = CSV_DIR . '/' . uniqid() . '_' . basename($file['name']);
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    jsonResponse(['success' => false, 'message' => 'Failed to save uploaded file'], 500);
                }
                
                // Parse CSV
                $result = parseCSV($uploadPath);
                
                if (!$result['success']) {
                    unlink($uploadPath);
                    jsonResponse(['success' => false, 'message' => $result['error']], 400);
                }
                
                // Import recipients
                $importResult = importRecipients($result['data']);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'CSV processed successfully',
                    'imported' => $importResult['imported'],
                    'skipped' => $importResult['skipped'],
                    'errors' => array_merge($result['errors'], $importResult['errors'])
                ]);
                
            } else {
                // Add single recipient manually
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Validate required fields
                if (empty($data['email']) || empty($data['company_name']) || empty($data['position'])) {
                    jsonResponse(['success' => false, 'message' => 'Email, company name, and position are required'], 400);
                }
                
                // Validate email format
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    jsonResponse(['success' => false, 'message' => 'Invalid email format'], 400);
                }
                
                // Insert recipient
                try {
                    $stmt = $db->prepare("
                        INSERT INTO recipients (email, company_name, position) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([
                        $data['email'],
                        $data['company_name'],
                        $data['position']
                    ]);
                    
                    jsonResponse([
                        'success' => true,
                        'message' => 'Recipient added successfully',
                        'id' => $db->lastInsertId()
                    ]);
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) { // Duplicate entry
                        jsonResponse(['success' => false, 'message' => 'This email already exists'], 400);
                    }
                    throw $e;
                }
            }
            break;
            
        case 'DELETE':
            // Delete recipient
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID is required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM recipients WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Recipient deleted successfully'
            ]);
            break;
            
        default:
            jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
?>
