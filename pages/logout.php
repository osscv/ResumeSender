<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';

logoutUser();

header('Location: ' . BASE_URL . '/pages/login.php');
exit;
