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
    echo "Tables created: users, smtp_configurations, recipients, email_templates, email_logs, email_attachments\n";

    // Bootstrap a default admin user (idempotent — only inserts if missing)
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO users (username, email, password, role, account_status)
             VALUES (?, ?, ?, 'admin', 'active')"
        );
        $insert->execute(['admin', 'admin@example.com', $hash]);
        echo "Default admin user created (username: admin, password: admin123)\n";
        echo "IMPORTANT: Please change the default password after first login!\n";
    } else {
        echo "Admin user already exists; skipping default-admin creation.\n";
    }
} catch (PDOException $e) {
    die("Error executing schema: " . $e->getMessage() . "\n");
}
?>
