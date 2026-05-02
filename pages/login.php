<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../utils/auth.php';

initSession();

// If already logged in, redirect to home
if (isAuthenticated()) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?)");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            // Check account status
            if ($user['account_status'] === 'banned') {
                $error = 'Your account has been banned. Please contact an administrator.';
            } elseif ($user['account_status'] === 'suspended') {
                $error = 'Your account has been suspended. Please contact an administrator.';
            } elseif ($user['account_status'] === 'inactive') {
                $error = 'Your account is inactive. Please contact an administrator.';
            } elseif ($user['account_status'] === 'active') {
                loginUser($user['id'], $user['username'], $user['email'], $user['role'], $user['name'] ?? null);
                header('Location: ' . BASE_URL . '/');
                exit;
            } else {
                $error = 'Invalid account status.';
            }
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo/Header -->
            <div>
                <div class="flex justify-center">
                    <div class="bg-blue-500 w-16 h-16 rounded-full flex items-center justify-center">
                        <i class="fa-solid fa-envelope text-white text-2xl"></i>
                    </div>
                </div>
                <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
                    <?php echo APP_NAME; ?>
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Sign in to your account
                </p>
            </div>

            <!-- Login Form -->
            <form class="mt-8 space-y-6" method="POST">
                <div class="bg-white shadow-md rounded-lg p-8 space-y-4">
                    <?php if ($error): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
                        <i class="fa-solid fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                            Username or Email
                        </label>
                        <input 
                            id="username" 
                            name="username" 
                            type="text" 
                            required 
                            autofocus
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                            placeholder="Enter your username or email"
                        >
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            Password
                        </label>
                        <input 
                            id="password" 
                            name="password" 
                            type="password" 
                            required 
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
                            placeholder="Enter your password"
                        >
                    </div>

                    <button 
                        type="submit" 
                        class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors flex items-center justify-center gap-2"
                    >
                        <i class="fa-solid fa-sign-in-alt"></i>
                        Sign In
                    </button>
                </div>
            </form>

            <!-- Footer Info -->
            <div class="text-center text-sm text-gray-500">
                <p class="font-mono bg-gray-100 px-2 py-1 rounded mt-1">Developed by <a href="https://www.dkly.net">Khoo Lay Yang</a></p>
            </div>
        </div>
    </div>
</body>
</html>
