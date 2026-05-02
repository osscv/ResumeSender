<?php
// Authentication and Authorization Helper Functions

/**
 * Start session if not already started
 */
function initSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Check if user is authenticated
 */
function isAuthenticated() {
    initSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Require authentication - redirect to login if not authenticated
 */
function requireAuth() {
    if (!isAuthenticated()) {
        header('Location: ' . BASE_URL . '/pages/login.php');
        exit;
    }
}

/**
 * Check if current user is admin
 */
function isAdmin() {
    initSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Require admin role - show 403 if not admin
 */
function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        echo "Access Denied: Admin privileges required.";
        exit;
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    initSession();
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current username
 */
function getUsername() {
    initSession();
    return $_SESSION['username'] ?? null;
}

/**
 * Get current user's full name (falls back to username if name not set)
 */
function getCurrentUserName() {
    initSession();
    return $_SESSION['name'] ?? $_SESSION['username'] ?? null;
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    initSession();
    return $_SESSION['role'] ?? null;
}

/**
 * Get client IP address with support for proxies and testing
 */
function getClientIP() {
    // Allow IP override via ?ip= parameter for testing
    if (isset($_GET['ip']) && filter_var($_GET['ip'], FILTER_VALIDATE_IP)) {
        return $_GET['ip'];
    }
    
    // Check for client IP through proxy
    if (isset($_SERVER['HTTP_CLIENT_IP']) && filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    
    // Check for forwarded IP (common with load balancers/proxies)
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // X-Forwarded-For can contain multiple IPs, get the first one
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    
    // Default to remote address
    if (isset($_SERVER['REMOTE_ADDR']) && filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
        return $_SERVER['REMOTE_ADDR'];
    }
    
    return null;
}

/**
 * Update user's last login timestamp and IP address
 */
function updateLastLogin($userId, $ipAddress = null) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?");
    $stmt->execute([$ipAddress, $userId]);
}

/**
 * Login user
 */
function loginUser($userId, $username, $email, $role, $name = null) {
    initSession();
    $_SESSION['user_id'] = $userId;
    $_SESSION['username'] = $username;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $role;
    $_SESSION['name'] = $name;
    
    // Track login timestamp and IP
    $ipAddress = getClientIP();
    updateLastLogin($userId, $ipAddress);
}

/**
 * Logout user
 */
function logoutUser() {
    initSession();
    session_destroy();
    session_start();
}

/**
 * Get user filter for SQL queries
 * Returns WHERE clause for filtering by user_id (only for non-admin users)
 */
function getUserFilter($tableAlias = '') {
    if (isAdmin()) {
        return ''; // Admins see all data, including NULL user_id
    }
    
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    $userId = getCurrentUserId();
    return " AND {$prefix}user_id = " . intval($userId);
}

/**
 * Get user filter that excludes NULL user_ids for regular users
 * For admins, returns empty string (see all)
 */
function getUserFilterStrict($tableAlias = '') {
    if (isAdmin()) {
        return '';
    }
    
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    $userId = getCurrentUserId();
    return " AND ({$prefix}user_id = " . intval($userId) . " OR {$prefix}user_id IS NULL)";
}
?>
