<?php
/**
 * CSV Parser for Recipients Upload
 */

function parseCSV($filePath) {
    $recipients = [];
    $errors = [];
    $lineNumber = 0;
    
    if (!file_exists($filePath)) {
        return [
            'success' => false,
            'error' => 'File not found',
            'data' => []
        ];
    }
    
    $handle = fopen($filePath, 'r');
    
    if ($handle === false) {
        return [
            'success' => false,
            'error' => 'Failed to open file',
            'data' => []
        ];
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    $lineNumber++;
    
    if ($headers === false) {
        fclose($handle);
        return [
            'success' => false,
            'error' => 'Empty CSV file',
            'data' => []
        ];
    }
    
    // Normalize headers
    $headers = array_map('trim', $headers);
    $headers = array_map('strtolower', $headers);
    
    // Find column indexes with variations
    $emailIndex = array_search('email', $headers);
    
    $companyIndex = array_search('company_name', $headers);
    if ($companyIndex === false) $companyIndex = array_search('company name', $headers);
    if ($companyIndex === false) $companyIndex = array_search('company_name', $headers);
    
    $positionIndex = array_search('position', $headers);
    if ($positionIndex === false) $positionIndex = array_search('positions', $headers);
    
    // Validate required columns
    $missingColumns = [];
    if ($emailIndex === false) $missingColumns[] = 'email';
    if ($companyIndex === false) $missingColumns[] = 'company_name (or company name)';
    if ($positionIndex === false) $missingColumns[] = 'position (or positions)';
    
    if (!empty($missingColumns)) {
        fclose($handle);
        return [
            'success' => false,
            'error' => 'Missing required columns: ' . implode(', ', $missingColumns),
            'data' => []
        ];
    }
    
    // Parse data rows
    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        
        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }
        
        $email = isset($row[$emailIndex]) ? trim($row[$emailIndex]) : '';
        $companyName = isset($row[$companyIndex]) ? trim($row[$companyIndex]) : '';
        $position = isset($row[$positionIndex]) ? trim($row[$positionIndex]) : '';
        
        // Validate email
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Line $lineNumber: Invalid or missing email";
            continue;
        }
        
        // Validate company name
        if (empty($companyName)) {
            $errors[] = "Line $lineNumber: Missing company name";
            continue;
        }
        
        // Validate position
        if (empty($position)) {
            $errors[] = "Line $lineNumber: Missing position";
            continue;
        }
        
        $recipients[] = [
            'email' => $email,
            'company_name' => $companyName,
            'position' => $position
        ];
    }
    
    fclose($handle);
    
    return [
        'success' => true,
        'data' => $recipients,
        'errors' => $errors,
        'total' => count($recipients)
    ];
}

/**
 * Import recipients to database
 */
function importRecipients($recipients) {
    require_once __DIR__ . '/../config.php';
    
    $db = getDBConnection();
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    $stmt = $db->prepare(
        "INSERT INTO recipients (email, company_name, position) 
         VALUES (?, ?, ?) 
         ON DUPLICATE KEY UPDATE 
         company_name = VALUES(company_name), 
         position = VALUES(position),
         updated_at = CURRENT_TIMESTAMP"
    );
    
    foreach ($recipients as $recipient) {
        try {
            $stmt->execute([
                $recipient['email'],
                $recipient['company_name'],
                $recipient['position']
            ]);
            
            if ($stmt->rowCount() > 0) {
                $imported++;
            } else {
                $skipped++;
            }
        } catch (PDOException $e) {
            $errors[] = "Failed to import {$recipient['email']}: " . $e->getMessage();
            $skipped++;
        }
    }
    
    return [
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors
    ];
}
?>
