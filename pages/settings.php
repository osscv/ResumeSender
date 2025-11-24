<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add_smtp') {
            try {
                $stmt = $pdo->prepare("INSERT INTO smtp_configurations (server_name, provider, smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name, daily_quota) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $_POST['server_name'],
                    $_POST['provider'],
                    $_POST['smtp_host'],
                    $_POST['smtp_port'],
                    $_POST['smtp_username'],
                    $_POST['smtp_password'],
                    $_POST['smtp_encryption'],
                    $_POST['from_email'],
                    $_POST['from_name'],
                    $_POST['daily_quota']
                ]);
                $message = "SMTP Configuration added successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error adding SMTP: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'edit_smtp') {
            try {
                $stmt = $pdo->prepare("UPDATE smtp_configurations SET server_name = ?, provider = ?, smtp_host = ?, smtp_port = ?, smtp_username = ?, smtp_password = ?, smtp_encryption = ?, from_email = ?, from_name = ?, daily_quota = ? WHERE id = ?");
                $stmt->execute([
                    $_POST['server_name'],
                    $_POST['provider'],
                    $_POST['smtp_host'],
                    $_POST['smtp_port'],
                    $_POST['smtp_username'],
                    $_POST['smtp_password'],
                    $_POST['smtp_encryption'],
                    $_POST['from_email'],
                    $_POST['from_name'],
                    $_POST['daily_quota'],
                    $_POST['id']
                ]);
                $message = "SMTP Configuration updated successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error updating SMTP: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'delete_smtp') {
            try {
                $stmt = $pdo->prepare("DELETE FROM smtp_configurations WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = "SMTP Configuration deleted successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error deleting SMTP: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// Fetch SMTP Configurations with usage stats
$stmt = $pdo->query("
    SELECT s.*, 
    (SELECT COUNT(*) FROM email_logs l 
     WHERE l.smtp_config_id = s.id 
     AND DATE(l.sent_at) = CURDATE() 
     AND l.status = 'success') as used_today
    FROM smtp_configurations s 
    ORDER BY s.created_at DESC
");
$smtpConfigs = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-6">
    <!-- Header & Actions -->
    <div class="flex justify-between items-center">
        <div>
            <h3 class="text-2xl font-bold text-gray-800">SMTP Configurations</h3>
            <p class="text-gray-500 mt-1">Manage your email server settings.</p>
        </div>
        <button onclick="openAddSmtp()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
            <i class="fa-solid fa-plus"></i> Add New SMTP
        </button>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- SMTP List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <?php if (empty($smtpConfigs)): ?>
            <div class="p-8 text-center text-gray-500">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-server text-2xl text-gray-400"></i>
                </div>
                <p class="text-lg font-medium">No SMTP configurations found.</p>
                <p class="text-sm mt-1">Add a new server to start sending emails.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                        <tr>
                            <th class="px-6 py-4">Provider</th>
                            <th class="px-6 py-4">Server Name</th>
                            <th class="px-6 py-4">Host</th>
                            <th class="px-6 py-4">Port</th>
                            <th class="px-6 py-4">From Email</th>
                            <th class="px-6 py-4">Quota (Today)</th>
                            <th class="px-6 py-4">Encryption</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($smtpConfigs as $config): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <?php 
                                    $provider = $config['provider'] ?? 'custom';
                                    $iconType = 'fa-solid';
                                    $icon = 'fa-server';
                                    $color = 'text-gray-400';
                                    
                                    if (strpos($provider, 'gmail') !== false) {
                                        $iconType = 'fa-brands';
                                        $icon = 'fa-google';
                                        $color = 'text-red-500';
                                    } elseif (strpos($provider, 'outlook') !== false) {
                                        $iconType = 'fa-brands';
                                        $icon = 'fa-microsoft';
                                        $color = 'text-blue-500';
                                    } elseif (strpos($provider, 'yahoo') !== false) {
                                        $iconType = 'fa-brands';
                                        $icon = 'fa-yahoo';
                                        $color = 'text-purple-500';
                                    } elseif (strpos($provider, 'zoho') !== false) {
                                        $iconType = 'fa-solid';
                                        $icon = 'fa-z';
                                        $color = 'text-yellow-500';
                                    }
                                ?>
                                <div class="flex items-center gap-2">
                                    <i class="<?php echo $iconType; ?> <?php echo $icon; ?> <?php echo $color; ?> text-lg w-6 text-center"></i>
                                    <span class="capitalize text-sm font-medium text-gray-700"><?php echo str_replace('_', ' ', $provider); ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-medium text-gray-900"><?php echo htmlspecialchars($config['server_name']); ?></td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($config['smtp_host']); ?></td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($config['smtp_port']); ?></td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($config['from_email']); ?></td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-24 bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo min(100, ($config['used_today'] / $config['daily_quota']) * 100); ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-600 font-medium">
                                        <?php echo $config['used_today']; ?>/<?php echo $config['daily_quota']; ?>
                                    </span>
                                </div>
                                <?php if ($config['quota_exceeded_at'] && date('Y-m-d', strtotime($config['quota_exceeded_at'])) == date('Y-m-d')): ?>
                                    <span class="text-xs text-red-500 font-bold block mt-1">BLOCKED (Limit Reached)</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                    <?php echo htmlspecialchars(strtoupper($config['smtp_encryption'])); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <button onclick='openEditSmtp(<?php echo htmlspecialchars(json_encode($config), ENT_QUOTES, 'UTF-8'); ?>)' class="text-blue-500 hover:text-blue-700 p-2 rounded-full hover:bg-blue-50 transition-colors mr-1" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this configuration?');" class="inline-block">
                                    <input type="hidden" name="action" value="delete_smtp">
                                    <input type="hidden" name="id" value="<?php echo $config['id']; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 p-2 rounded-full hover:bg-red-50 transition-colors" title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit SMTP Modal -->
<div id="addSmtpModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl m-4 max-h-[90vh] overflow-y-auto">
        <div class="p-4 border-b border-gray-100 flex justify-between items-center sticky top-0 bg-white z-10">
            <h3 id="modalTitle" class="text-lg font-bold text-gray-800">Add New SMTP Configuration</h3>
            <button onclick="document.getElementById('addSmtpModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" id="smtpForm" class="p-4 space-y-4">
            <input type="hidden" name="action" id="formAction" value="add_smtp">
            <input type="hidden" name="id" id="smtpId" value="">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Configuration Name</label>
                    <input type="text" name="server_name" id="server_name" required placeholder="e.g., Gmail Personal" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>

                <div class="col-span-2 md:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Provider</label>
                    <select name="provider" id="provider" onchange="updateProviderSettings()" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        <option value="custom">Custom</option>
                        <option value="gmail">Gmail</option>
                        <option value="outlook">Outlook / Hotmail</option>
                        <option value="yahoo">Yahoo Mail</option>
                        <option value="zoho">Zoho Mail</option>
                    </select>
                </div>

                <div class="col-span-2 md:col-span-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Daily Quota</label>
                    <input type="number" name="daily_quota" id="daily_quota" required value="500" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                    <p class="text-xs text-gray-400 mt-1">Safety limit for this app only.</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Host</label>
                    <input type="text" name="smtp_host" id="smtp_host" required placeholder="smtp.gmail.com" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">SMTP Port</label>
                    <input type="number" name="smtp_port" id="smtp_port" required placeholder="587" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
                    <input type="text" name="smtp_username" id="smtp_username" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="smtp_password" id="smtp_password" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Encryption</label>
                    <select name="smtp_encryption" id="smtp_encryption" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        <option value="tls">TLS</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </select>
                </div>
                
                <div class="col-span-2 border-t border-gray-100 pt-4 mt-2">
                    <h4 class="text-sm font-semibold text-gray-900 mb-4">Sender Details</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From Email</label>
                            <input type="email" name="from_email" id="from_email" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">From Name</label>
                            <input type="text" name="from_name" id="from_name" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('addSmtpModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" id="submitBtn" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Save Configuration</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateProviderSettings() {
    const provider = document.getElementById('provider').value;
    const hostInput = document.getElementById('smtp_host');
    const portInput = document.getElementById('smtp_port');
    const encryptionInput = document.getElementById('smtp_encryption');
    const quotaInput = document.getElementById('daily_quota');
    
    const settings = {
        'gmail': {
            host: 'smtp.gmail.com',
            port: 587,
            encryption: 'tls',
            quota: 500
        },
        'outlook': {
            host: 'smtp.office365.com',
            port: 587,
            encryption: 'tls',
            quota: 300
        },
        'yahoo': {
            host: 'smtp.mail.yahoo.com',
            port: 465,
            encryption: 'ssl',
            quota: 500
        },
        'zoho': {
            host: 'smtp.zoho.com',
            port: 587,
            encryption: 'tls',
            quota: 150
        }
    };
    
    if (settings[provider]) {
        const config = settings[provider];
        hostInput.value = config.host;
        portInput.value = config.port;
        encryptionInput.value = config.encryption;
        quotaInput.value = config.quota;
    }
}

function openAddSmtp() {
    document.getElementById('smtpForm').reset();
    document.getElementById('formAction').value = 'add_smtp';
    document.getElementById('smtpId').value = '';
    document.getElementById('modalTitle').textContent = 'Add New SMTP Configuration';
    document.getElementById('submitBtn').textContent = 'Save Configuration';
    document.getElementById('addSmtpModal').classList.remove('hidden');
}

function openEditSmtp(config) {
    document.getElementById('formAction').value = 'edit_smtp';
    document.getElementById('smtpId').value = config.id;
    document.getElementById('modalTitle').textContent = 'Edit SMTP Configuration';
    document.getElementById('submitBtn').textContent = 'Update Configuration';
    
    document.getElementById('server_name').value = config.server_name;
    document.getElementById('provider').value = config.provider || 'custom';
    document.getElementById('daily_quota').value = config.daily_quota;
    document.getElementById('smtp_host').value = config.smtp_host;
    document.getElementById('smtp_port').value = config.smtp_port;
    document.getElementById('smtp_username').value = config.smtp_username;
    document.getElementById('smtp_password').value = config.smtp_password;
    document.getElementById('smtp_encryption').value = config.smtp_encryption;
    document.getElementById('from_email').value = config.from_email;
    document.getElementById('from_name').value = config.from_name;
    
    document.getElementById('addSmtpModal').classList.remove('hidden');
}
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'Settings');
?>
