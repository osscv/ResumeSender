<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/auth.php';

requireAuth();

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Fetch current user data
$userId = getCurrentUserId();
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    if ($action === 'update_profile') {
        $name = trim($_POST['name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $gender = !empty($_POST['gender']) ? $_POST['gender'] : null;
        $dob = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        
        // Validate required fields
        if (empty($username) || empty($email)) {
            $message = 'Username and email are required.';
            $messageType = 'error';
        } else {
            try {
                // Check if username is taken by another user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $stmt->execute([$username, $userId]);
                if ($stmt->rowCount() > 0) {
                    $message = 'Username is already taken.';
                    $messageType = 'error';
                } else {
                    // Check if email is taken by another user
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $stmt->execute([$email, $userId]);
                    if ($stmt->rowCount() > 0) {
                        $message = 'Email is already taken.';
                        $messageType = 'error';
                    } else {
                        // Update user profile
                        $stmt = $pdo->prepare("UPDATE users SET name = ?, username = ?, email = ?, gender = ?, date_of_birth = ? WHERE id = ?");
                        $stmt->execute([$name, $username, $email, $gender, $dob, $userId]);
                        
                        // Update session data
                        $_SESSION['username'] = $username;
                        $_SESSION['email'] = $email;
                        $_SESSION['name'] = $name;
                        
                        // Refresh user data
                        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch();
                        
                        $message = 'Profile updated successfully!';
                        $messageType = 'success';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Error updating profile: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $message = 'All password fields are required.';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 6) {
            $message = 'New password must be at least 6 characters long.';
            $messageType = 'error';
        } else {
            // Verify current password
            if (password_verify($currentPassword, $user['password'])) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $userId]);
                    
                    $message = 'Password changed successfully!';
                    $messageType = 'success';
                } catch (PDOException $e) {
                    $message = 'Error changing password: ' . $e->getMessage();
                    $messageType = 'error';
                }
            } else {
                $message = 'Current password is incorrect.';
                $messageType = 'error';
            }
        }
    }
}

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h3 class="text-2xl font-bold text-gray-800">My Profile</h3>
        <p class="text-gray-500 mt-1">Manage your personal information and account settings.</p>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Personal Information Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-100">
            <h4 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-user text-blue-500"></i>
                Personal Information
            </h4>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_profile">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                    <input type="text" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" placeholder="Enter your full name" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                    <select name="gender" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                        <option value="">Prefer not to say</option>
                        <option value="male" <?php echo ($user['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo ($user['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($user['date_of_birth'] ?? ''); ?>" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
            </div>
            
            <div class="pt-4 flex justify-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium flex items-center gap-2">
                    <i class="fa-solid fa-save"></i>
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- Security Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-100">
            <h4 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-lock text-yellow-500"></i>
                Security
            </h4>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="change_password">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Current Password <span class="text-red-500">*</span></label>
                    <input type="password" name="current_password" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">New Password <span class="text-red-500">*</span></label>
                    <input type="password" name="new_password" required minlength="6" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <p class="text-xs text-gray-400 mt-1">Min. 6 characters</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password <span class="text-red-500">*</span></label>
                    <input type="password" name="confirm_password" required minlength="6" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                </div>
            </div>
            
            <div class="pt-4 flex justify-end">
                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium flex items-center gap-2">
                    <i class="fa-solid fa-key"></i>
                    Change Password
                </button>
            </div>
        </form>
    </div>

    <!-- Account Info Card -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
        <div class="p-6 border-b border-gray-100">
            <h4 class="text-lg font-semibold text-gray-800 flex items-center gap-2">
                <i class="fa-solid fa-info-circle text-gray-500"></i>
                Account Information
            </h4>
        </div>
        
        <div class="p-6 space-y-3">
            <div class="flex justify-between items-center py-2">
                <span class="text-sm font-medium text-gray-600">Account Role</span>
                <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $user['role'] === 'admin' ? 'bg-purple-100 text-purple-700' : 'bg-gray-100 text-gray-700'; ?>">
                    <?php echo ucfirst($user['role']); ?>
                </span>
            </div>
            <div class="flex justify-between items-center py-2">
                <span class="text-sm font-medium text-gray-600">Account Status</span>
                <?php
                $statusColors = [
                    'active' => 'bg-green-100 text-green-700',
                    'inactive' => 'bg-gray-100 text-gray-700',
                    'suspended' => 'bg-yellow-100 text-yellow-700',
                    'banned' => 'bg-red-100 text-red-700'
                ];
                $status = $user['account_status'] ?? 'inactive';
                $colorClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-700';
                ?>
                <span class="px-3 py-1 rounded-full text-xs font-medium capitalize <?php echo $colorClass; ?>">
                    <?php echo $status; ?>
                </span>
            </div>
            <div class="flex justify-between items-center py-2">
                <span class="text-sm font-medium text-gray-600">Member Since</span>
                <span class="text-sm text-gray-700"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></span>
            </div>
            <?php if ($user['last_login_at']): ?>
            <div class="flex justify-between items-center py-2">
                <span class="text-sm font-medium text-gray-600">Last Login</span>
                <span class="text-sm text-gray-700"><?php echo date('M j, Y g:i A', strtotime($user['last_login_at'])); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
renderLayout($content, 'My Profile');
?>
