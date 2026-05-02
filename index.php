<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/utils/auth.php';

// Check if user is authenticated
requireAuth();

// Redirect to home page
header('Location: ' . BASE_URL . '/pages/home.php');
exit;
?>
