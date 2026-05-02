-- Resume Sender Database Schema
-- Database: resumesender

-- Drop existing tables if they exist (children first for FK ordering)
DROP TABLE IF EXISTS email_attachments;
DROP TABLE IF EXISTS email_logs;
DROP TABLE IF EXISTS email_templates;
DROP TABLE IF EXISTS recipients;
DROP TABLE IF EXISTS smtp_configurations;
DROP TABLE IF EXISTS users;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(100) NULL,
    gender ENUM('male', 'female') NULL DEFAULT NULL,
    date_of_birth DATE NULL DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    account_status ENUM('active', 'inactive', 'suspended', 'banned') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL DEFAULT NULL,
    last_login_ip VARCHAR(45) NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_last_login (last_login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SMTP Configurations Table
CREATE TABLE smtp_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    server_name VARCHAR(255) NOT NULL,
    provider VARCHAR(50) DEFAULT 'custom',
    smtp_host VARCHAR(255) NOT NULL,
    smtp_port INT NOT NULL DEFAULT 587,
    smtp_username VARCHAR(255) NOT NULL,
    smtp_password VARCHAR(255) NOT NULL,
    smtp_encryption ENUM('none', 'tls', 'ssl') DEFAULT 'tls',
    daily_quota INT DEFAULT 500,
    quota_exceeded_at DATETIME NULL DEFAULT NULL,
    from_email VARCHAR(255) NOT NULL,
    from_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recipients Table
CREATE TABLE recipients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    company_name VARCHAR(255) NOT NULL,
    position VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_email (email),
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    INDEX idx_company (company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Templates Table
CREATE TABLE email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Logs Table
CREATE TABLE email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_id INT NOT NULL,
    smtp_config_id INT NOT NULL,
    subject VARCHAR(500) NOT NULL,
    body TEXT NOT NULL,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('success', 'failed') DEFAULT 'success',
    error_message TEXT,
    FOREIGN KEY (recipient_id) REFERENCES recipients(id) ON DELETE CASCADE,
    FOREIGN KEY (smtp_config_id) REFERENCES smtp_configurations(id) ON DELETE CASCADE,
    INDEX idx_recipient (recipient_id),
    INDEX idx_smtp (smtp_config_id),
    INDEX idx_sent_at (sent_at),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Attachments Table
CREATE TABLE email_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email_log_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (email_log_id) REFERENCES email_logs(id) ON DELETE CASCADE,
    INDEX idx_email_log (email_log_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

