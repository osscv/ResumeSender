<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$db = getDBConnection();

try {
    switch ($method) {
        case 'GET':
            // Get all SMTP configurations
            $stmt = $db->query("SELECT * FROM smtp_configurations ORDER BY created_at DESC");
            $configs = $stmt->fetchAll();
            
            jsonResponse([
                'success' => true,
                'data' => $configs
            ]);
            break;
            
        case 'POST':
            // Create new SMTP configuration
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Validate required fields
            $requiredFields = ['server_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'from_email', 'from_name'];
            foreach ($requiredFields as $field) {
                if (empty($data[$field])) {
                    jsonResponse(['success' => false, 'message' => "Missing required field: $field"], 400);
                }
            }
            
            // Check if updating existing config
            if (!empty($data['id'])) {
                $stmt = $db->prepare("
                    UPDATE smtp_configurations 
                    SET server_name = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, 
                        smtp_password = ?, smtp_encryption = ?, from_email = ?, from_name = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['server_name'],
                    $data['smtp_host'],
                    $data['smtp_port'],
                    $data['smtp_username'],
                    $data['smtp_password'],
                    $data['smtp_encryption'],
                    $data['from_email'],
                    $data['from_name'],
                    $data['id']
                ]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'SMTP configuration updated successfully'
                ]);
            } else {
                // Insert new config
                $stmt = $db->prepare("
                    INSERT INTO smtp_configurations 
                    (server_name, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['server_name'],
                    $data['smtp_host'],
                    $data['smtp_port'],
                    $data['smtp_username'],
                    $data['smtp_password'],
                    $data['smtp_encryption'],
                    $data['from_email'],
                    $data['from_name']
                ]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'SMTP configuration created successfully',
                    'id' => $db->lastInsertId()
                ]);
            }
            break;
            
        case 'PUT':
            // Update SMTP configuration (toggle status or full update)
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID is required'], 400);
            }
            
            // If only toggling active status
            if (isset($data['is_active']) && count($data) == 2) {
                $stmt = $db->prepare("UPDATE smtp_configurations SET is_active = ? WHERE id = ?");
                $stmt->execute([$data['is_active'] ? 1 : 0, $data['id']]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'Status updated successfully'
                ]);
            } else {
                // Full update - same as POST with ID
                $requiredFields = ['server_name', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'from_email', 'from_name'];
                foreach ($requiredFields as $field) {
                    if (empty($data[$field])) {
                        jsonResponse(['success' => false, 'message' => "Missing required field: $field"], 400);
                    }
                }
                
                $stmt = $db->prepare("
                    UPDATE smtp_configurations 
                    SET server_name = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, 
                        smtp_password = ?, smtp_encryption = ?, from_email = ?, from_name = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['server_name'],
                    $data['smtp_host'],
                    $data['smtp_port'],
                    $data['smtp_username'],
                    $data['smtp_password'],
                    $data['smtp_encryption'],
                    $data['from_email'],
                    $data['from_name'],
                    $data['id']
                ]);
                
                jsonResponse([
                    'success' => true,
                    'message' => 'SMTP configuration updated successfully'
                ]);
            }
            break;
            
        case 'DELETE':
            // Delete SMTP configuration
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (empty($data['id'])) {
                jsonResponse(['success' => false, 'message' => 'ID is required'], 400);
            }
            
            $stmt = $db->prepare("DELETE FROM smtp_configurations WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            jsonResponse([
                'success' => true,
                'message' => 'SMTP configuration deleted successfully'
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
