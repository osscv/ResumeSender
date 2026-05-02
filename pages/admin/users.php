<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../layout.php';
require_once __DIR__ . '/../../utils/auth.php';

requireAdmin(); // Only admins can access this page

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    if ($action === 'add_user') {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $name = trim($_POST['name'] ?? '');
        $password = $_POST['password'];
        $role = $_POST['role'];
        
        if (empty($username) || empty($email) || empty($password)) {
            $message = 'All fields are required.';
            $messageType = 'error';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                $stmt = $pdo->prepare("INSERT INTO users (username, email, name, password, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $email, $name, $hashedPassword, $role]);
                $message = "User '$username' created successfully.";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'edit_user') {
        $userId = $_POST['user_id'];
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $name = trim($_POST['name'] ?? '');
        $role = $_POST['role'];
        $accountStatus = $_POST['account_status'] ?? 'active';
        $gender = !empty($_POST['gender']) ? $_POST['gender'] : null;
        $dateOfBirth = !empty($_POST['date_of_birth']) ? $_POST['date_of_birth'] : null;
        
        try {
            $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ?, name = ?, role = ?, account_status = ?, gender = ?, date_of_birth = ? WHERE id = ?");
            $stmt->execute([$username, $email, $name, $role, $accountStatus, $gender, $dateOfBirth, $userId]);
            $message = "User updated successfully.";
            $messageType = 'success';
        } catch (PDOException $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    } elseif ($action === 'change_status') {
        $userId = $_POST['user_id'];
        $newStatus = $_POST['new_status'];
        
        // Validate status
        $validStatuses = ['active', 'inactive', 'suspended', 'banned'];
        if (!in_array($newStatus, $validStatuses)) {
            $message = 'Invalid status.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET account_status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $userId]);
                $message = "User status updated to " . ucfirst($newStatus) . ".";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = $_POST['user_id'];
        
        // Prevent deleting self
        if ($userId == getCurrentUserId()) {
            $message = 'You cannot delete your own account.';
            $messageType = 'error';
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $message = "User deleted successfully.";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'reset_password') {
        $userId = $_POST['user_id'];
        $newPassword = $_POST['new_password'];
        
        if (empty($newPassword)) {
            $message = 'Password cannot be empty.';
            $messageType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            try {
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $userId]);
                $message = "Password reset successfully.";
                $messageType = 'success';
            } catch (PDOException $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    }
}

// Get filter parameters
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$filterRole = isset($_GET['role']) ? $_GET['role'] : '';
$filterGender = isset($_GET['gender']) ? $_GET['gender'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';

// Build query with filters
$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if (!empty($searchTerm)) {
    $query .= " AND (username LIKE ? OR email LIKE ? OR name LIKE ?)";
    $searchPattern = '%' . $searchTerm . '%';
    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
}

if (!empty($filterRole)) {
    $query .= " AND role = ?";
    $params[] = $filterRole;
}

if (!empty($filterGender)) {
    $query .= " AND gender = ?";
    $params[] = $filterGender;
}

if (!empty($filterStatus)) {
    $query .= " AND account_status = ?";
    $params[] = $filterStatus;
}

$query .= " ORDER BY created_at DESC";

// Fetch filtered users
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h3 class="text-2xl font-bold text-gray-800">User Management</h3>
            <p class="text-gray-500 mt-1">Manage user accounts and permissions.</p>
        </div>
        <button onclick="document.getElementById('addUserModal').classList.remove('hidden')" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors font-medium flex items-center gap-2">
            <i class="fa-solid fa-user-plus"></i> Add User
        </button>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Search and Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <!-- Search -->
            <div class="md:col-span-2">
                <div class="relative">
                    <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($searchTerm); ?>" 
                           placeholder="Search by username, email, or name..." 
                           class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                </div>
            </div>
            
            <!-- Role Filter -->
            <div>
                <select name="role" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                    <option value="">All Roles</option>
                    <option value="admin" <?php echo $filterRole === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="user" <?php echo $filterRole === 'user' ? 'selected' : ''; ?>>User</option>
                </select>
            </div>
            
            <!-- Gender Filter -->
            <div>
                <select name="gender" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                    <option value="">All Genders</option>
                    <option value="male" <?php echo $filterGender === 'male' ? 'selected' : ''; ?>>Male</option>
                    <option value="female" <?php echo $filterGender === 'female' ? 'selected' : ''; ?>>Female</option>
                </select>
            </div>
            
            <!-- Status Filter -->
            <div>
                <select name="status" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none text-sm">
                    <option value="">All Status</option>
                    <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    <option value="suspended" <?php echo $filterStatus === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="banned" <?php echo $filterStatus === 'banned' ? 'selected' : ''; ?>>Banned</option>
                </select>
            </div>
            
            <!-- Action Buttons -->
            <div class="md:col-span-4 flex gap-2">
                <button type="submit" 
                        class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-filter"></i> Apply Filters
                </button>
                <a href="?" 
                   class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center gap-2">
                    <i class="fa-solid fa-times"></i> Clear
                </a>
                <div class="ml-auto text-sm text-gray-600 flex items-center">
                    <span class="font-medium"><?php echo count($users); ?></span> user(s) found
                </div>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-left text-xs text-gray-600">
            <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                <tr>
                    <th class="px-4 py-3 whitespace-nowrap">User</th>
                    <th class="px-4 py-3 whitespace-nowrap">Email</th>
                    <th class="px-4 py-3 whitespace-nowrap">Role</th>
                    <th class="px-4 py-3 whitespace-nowrap">Status</th>
                    <th class="px-4 py-3 whitespace-nowrap">Gender</th>
                    <th class="px-4 py-3 whitespace-nowrap">DOB</th>
                    <th class="px-4 py-3 whitespace-nowrap">Last Login</th>
                    <th class="px-4 py-3 whitespace-nowrap">IP</th>
                    <th class="px-4 py-3 whitespace-nowrap">Created</th>
                    <th class="px-4 py-3 text-right whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($users as $user): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3 whitespace-nowrap">
                        <div class="font-medium text-gray-900 text-xs">
                            <?php echo htmlspecialchars($user['name'] ?: $user['username']); ?>
                            <?php if ($user['id'] == getCurrentUserId()): ?>
                                <span class="text-xs text-blue-500">(You)</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($user['name'])): ?>
                            <div class="text-xs text-gray-500">@<?php echo htmlspecialchars($user['username']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs"><?php echo htmlspecialchars($user['email']); ?></td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                Admin
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                User
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap relative">
                        <?php
                        $statusColors = [
                            'active' => 'bg-green-100 text-green-800 hover:bg-green-200',
                            'inactive' => 'bg-gray-100 text-gray-800 hover:bg-gray-200',
                            'suspended' => 'bg-yellow-100 text-yellow-800 hover:bg-yellow-200',
                            'banned' => 'bg-red-100 text-red-800 hover:bg-red-200'
                        ];
                        $status = $user['account_status'] ?? 'inactive';
                        $colorClass = $statusColors[$status] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <div class="relative inline-block">
                            <button onclick="toggleStatusDropdown(<?php echo $user['id']; ?>)" 
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium capitalize cursor-pointer transition-colors <?php echo $colorClass; ?>">
                                <?php echo htmlspecialchars($status); ?>
                                <i class="fa-solid fa-chevron-down ml-1 text-xs"></i>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div id="status-dropdown-<?php echo $user['id']; ?>" 
                                 class="hidden absolute left-0 mt-1 w-32 bg-white rounded-lg shadow-lg border border-gray-200 z-50">
                                <form method="POST" class="py-1">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    
                                    <button type="submit" name="new_status" value="active" 
                                            class="w-full text-left px-3 py-2 text-xs hover:bg-green-50 text-gray-700 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-green-500"></span> Active
                                    </button>
                                    <button type="submit" name="new_status" value="inactive" 
                                            class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 text-gray-700 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-gray-500"></span> Inactive
                                    </button>
                                    <button type="submit" name="new_status" value="suspended" 
                                            class="w-full text-left px-3 py-2 text-xs hover:bg-yellow-50 text-gray-700 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-yellow-500"></span> Suspended
                                    </button>
                                    <button type="submit" name="new_status" value="banned" 
                                            class="w-full text-left px-3 py-2 text-xs hover:bg-red-50 text-gray-700 flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-red-500"></span> Banned
                                    </button>
                                </form>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ($user['gender']): ?>
                            <span class="capitalize text-xs text-gray-700"><?php echo htmlspecialchars($user['gender']); ?></span>
                        <?php else: ?>
                            <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ($user['date_of_birth']): ?>
                            <span class="text-xs text-gray-700"><?php echo date('M j, Y', strtotime($user['date_of_birth'])); ?></span>
                        <?php else: ?>
                            <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-xs">
                        <?php if ($user['last_login_at']): ?>
                            <?php echo date('M j, g:i A', strtotime($user['last_login_at'])); ?>
                        <?php else: ?>
                            <span class="text-gray-400">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ($user['last_login_ip']): ?>
                            <span class="font-mono text-xs bg-gray-100 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($user['last_login_ip']); ?></span>
                        <?php else: ?>
                            <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-xs">
                        <?php echo date('M j, Y', strtotime($user['created_at'])); ?>
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user)); ?>)" class="text-blue-500 hover:text-blue-700 p-1" title="Edit">
                            <i class="fa-solid fa-edit text-sm"></i>
                        </button>
                        <button onclick="openResetPasswordModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')" class="text-yellow-500 hover:text-yellow-700 p-1" title="Reset Password">
                            <i class="fa-solid fa-key text-sm"></i>
                        </button>
                        <?php if ($user['id'] != getCurrentUserId()): ?>
                        <button type="button" 
                                onclick="confirmDelete(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')" 
                                class="text-red-500 hover:text-red-700 p-1" 
                                title="Delete">
                            <i class="fa-solid fa-trash text-sm"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div id="addUserModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Add New User</h3>
            <button onclick="document.getElementById('addUserModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add_user">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-gray-400 font-normal">(Optional)</span></label>
                <input type="text" name="name" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Edit User</h3>
            <button onclick="document.getElementById('editUserModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="edit_user_id">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                <input type="text" name="username" id="edit_username" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="edit_email" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name <span class="text-gray-400 font-normal">(Optional)</span></label>
                <input type="text" name="name" id="edit_name" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                <select name="role" id="edit_role" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
            </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Account Status</label>
                <select name="account_status" id="edit_account_status" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="suspended">Suspended</option>
                    <option value="banned">Banned</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                <select name="gender" id="edit_gender" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="">Prefer not to say</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date of Birth</label>
                <input type="date" name="date_of_birth" id="edit_date_of_birth" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('editUserModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Update User</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Reset Password</h3>
            <button onclick="document.getElementById('resetPasswordModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">
            
            <p class="text-sm text-gray-600">Reset password for: <span id="reset_username" class="font-medium"></span></p>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="new_password" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('resetPasswordModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<!-- Confirmation Dialog Modal -->
<div id="confirmModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4 transform transition-all">
        <div class="p-6">
            <!-- Icon -->
            <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            
            <!-- Title -->
            <h3 class="text-xl font-bold text-gray-800 text-center mb-2" id="confirmTitle">Confirm Action</h3>
            
            <!-- Message -->
            <p class="text-sm text-gray-600 text-center mb-6" id="confirmMessage">Are you sure you want to proceed?</p>
            
            <!-- Actions -->
            <div class="flex gap-3">
                <button type="button" 
                        onclick="closeConfirmModal()" 
                        class="flex-1 px-4 py-2.5 text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors font-medium">
                    Cancel
                </button>
                <button type="button" 
                        id="confirmButton"
                        class="flex-1 px-4 py-2.5 text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors font-medium">
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openEditModal(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_name').value = user.name || '';
    document.getElementById('edit_role').value = user.role;
    document.getElementById('edit_account_status').value = user.account_status || 'active';
    document.getElementById('edit_gender').value = user.gender || '';
    document.getElementById('edit_date_of_birth').value = user.date_of_birth || '';
    document.getElementById('editUserModal').classList.remove('hidden');
}

function openResetPasswordModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('resetPasswordModal').classList.remove('hidden');
}

function toggleStatusDropdown(userId) {
    // Close all other dropdowns first
    document.querySelectorAll('[id^="status-dropdown-"]').forEach(dropdown => {
        if (dropdown.id !== `status-dropdown-${userId}`) {
            dropdown.classList.add('hidden');
        }
    });
    
    // Toggle the clicked dropdown
    const dropdown = document.getElementById(`status-dropdown-${userId}`);
    dropdown.classList.toggle('hidden');
    
    // Stop event propagation
    event.stopPropagation();
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('[id^="status-dropdown-"]') && !event.target.closest('button[onclick^="toggleStatusDropdown"]')) {
        document.querySelectorAll('[id^="status-dropdown-"]').forEach(dropdown => {
            dropdown.classList.add('hidden');
        });
    }
});

// Confirmation Modal Functions
let deleteUserId = null;

function confirmDelete(userId, username) {
    deleteUserId = userId;
    document.getElementById('confirmTitle').textContent = 'Delete User';
    document.getElementById('confirmMessage').textContent = `Are you sure you want to delete "${username}"? This action cannot be undone.`;
    document.getElementById('confirmModal').classList.remove('hidden');
}

function closeConfirmModal() {
    deleteUserId = null;
    document.getElementById('confirmModal').classList.add('hidden');
}

function executeDelete() {
    if (deleteUserId) {
        // Create and submit a form to delete the user
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_user">
            <input type="hidden" name="user_id" value="${deleteUserId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Attach delete action to confirm button
document.getElementById('confirmButton').addEventListener('click', executeDelete);

// Close modal on escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeConfirmModal();
    }
});
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'User Management');
?>
