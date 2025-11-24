<?php
require_once __DIR__ . '/config.php';

echo "Connecting to database...\n";
$pdo = getDBConnection();

echo "Reading schema file...\n";
$sql = file_get_contents(__DIR__ . '/database_schema.sql');

if (!$sql) {
    die("Error: Could not read database_schema.sql\n");
}

try {
    echo "Executing schema...\n";
    $pdo->exec($sql);
    echo "Database setup completed successfully!\n";
    echo "Tables created: smtp_configurations, recipients, email_logs, email_attachments\n";
} catch (PDOException $e) {
    die("Error executing schema: " . $e->getMessage() . "\n");
}
?>
