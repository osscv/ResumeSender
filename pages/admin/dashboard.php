<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../layout.php';
require_once __DIR__ . '/../../utils/auth.php';

requireAdmin(); // Only admins can access this page

$pdo = getDBConnection();

// Fetch Admin Dashboard Stats
$stats = [
    'total_users' => 0,
    'emails_sent_today' => 0,
    'total_recipients' => 0,
    'total_smtp_configs' => 0,
    'total_emails_sent' => 0,
    'success_rate' => 0,
    'active_users' => 0,
    'failed_today' => 0
];

// Total Users
$stmt = $pdo->query("SELECT COUNT(*) FROM users");
$stats['total_users'] = $stmt->fetchColumn();

// Active Users
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'active'");
$stats['active_users'] = $stmt->fetchColumn();

// Total Recipients
$stmt = $pdo->query("SELECT COUNT(*) FROM recipients");
$stats['total_recipients'] = $stmt->fetchColumn();

// Total SMTP Configurations
$stmt = $pdo->query("SELECT COUNT(*) FROM smtp_configurations");
$stats['total_smtp_configs'] = $stmt->fetchColumn();

// Total Emails Sent (all time)
$stmt = $pdo->query("SELECT COUNT(*) FROM email_logs");
$stats['total_emails_sent'] = $stmt->fetchColumn();

// Emails Sent Today
$stmt = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE DATE(sent_at) = CURDATE()");
$stats['emails_sent_today'] = $stmt->fetchColumn();

// Failed Emails Today
$stmt = $pdo->query("SELECT COUNT(*) FROM email_logs WHERE DATE(sent_at) = CURDATE() AND status = 'failed'");
$stats['failed_today'] = $stmt->fetchColumn();

// Success Rate (all time)
$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success
    FROM email_logs
");
$row = $stmt->fetch();
$stats['success_rate'] = $row['total'] > 0 ? ($row['success'] / $row['total']) * 100 : 0;

// User Activity Stats
$stmt = $pdo->query("
    SELECT 
        u.username,
        u.email,
        u.role,
        COUNT(DISTINCT r.id) as recipient_count,
        COUNT(DISTINCT s.id) as smtp_count,
        COUNT(e.id) as emails_sent
    FROM users u
    LEFT JOIN recipients r ON u.id = r.user_id
    LEFT JOIN smtp_configurations s ON u.id = s.user_id
    LEFT JOIN email_logs e ON s.id = e.smtp_config_id
    GROUP BY u.id
    ORDER BY emails_sent DESC
    LIMIT 10
");
$userActivity = $stmt->fetchAll();

// Recent Activity (last 20 emails)
$stmt = $pdo->query("
    SELECT 
        l.*,
        r.email as recipient_email,
        r.company_name,
        s.server_name as smtp_name,
        u.username
    FROM email_logs l
    JOIN recipients r ON l.recipient_id = r.id
    JOIN smtp_configurations s ON l.smtp_config_id = s.id
    JOIN users u ON s.user_id = u.id
    ORDER BY l.sent_at DESC
    LIMIT 20
");
$recentLogs = $stmt->fetchAll();

// Get filter parameter for SMTP host
$smtpHostFilter = isset($_GET['smtp_host']) ? trim($_GET['smtp_host']) : '';

// Get unique SMTP hosts for the filter dropdown
$stmt = $pdo->query("SELECT DISTINCT smtp_host FROM smtp_configurations ORDER BY smtp_host");
$smtpHosts = $stmt->fetchAll();

// SMTP Configuration Stats with optional host filter
$smtpQuery = "
    SELECT 
        s.server_name,
        s.smtp_host,
        s.provider,
        s.is_active,
        s.daily_quota,
        u.username,
        COUNT(e.id) as emails_sent_today
    FROM smtp_configurations s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN email_logs e ON s.id = e.smtp_config_id AND DATE(e.sent_at) = CURDATE()
";

// Apply host filter if set
if (!empty($smtpHostFilter)) {
    $smtpQuery .= " WHERE s.smtp_host = :smtp_host";
}

$smtpQuery .= "
    GROUP BY s.id
    ORDER BY s.is_active DESC, emails_sent_today DESC
    LIMIT 10
";

$stmt = $pdo->prepare($smtpQuery);
if (!empty($smtpHostFilter)) {
    $stmt->execute(['smtp_host' => $smtpHostFilter]);
} else {
    $stmt->execute();
}
$smtpConfigs = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-8">
    <!-- Welcome Section -->
    <div class="bg-gradient-to-r from-purple-800 to-indigo-900 rounded-2xl p-8 text-white shadow-lg">
        <h2 class="text-3xl font-bold mb-2">Admin Dashboard</h2>
        <p class="text-purple-200">System-wide statistics and monitoring</p>
    </div>

    <!-- Main Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Users -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
                    <i class="fa-solid fa-users text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Total Users</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_users']); ?></h3>
            <p class="text-sm text-gray-500 mt-1"><?php echo number_format($stats['active_users']); ?> active</p>
        </div>

        <!-- Emails Sent Today -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center text-green-600">
                    <i class="fa-solid fa-paper-plane text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Sent Today</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['emails_sent_today']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">
                <?php if ($stats['failed_today'] > 0): ?>
                    <span class="text-red-600"><?php echo number_format($stats['failed_today']); ?> failed</span>
                <?php else: ?>
                    All successful
                <?php endif; ?>
            </p>
        </div>

        <!-- Total Recipients -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center text-purple-600">
                    <i class="fa-solid fa-address-book text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Total Recipients</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_recipients']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">In database</p>
        </div>

        <!-- SMTP Configs -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-orange-50 rounded-lg flex items-center justify-center text-orange-600">
                    <i class="fa-solid fa-server text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">SMTP Configs</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_smtp_configs']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Configured</p>
        </div>
    </div>

    <!-- Secondary Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Total Emails Sent (All Time) -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-indigo-50 rounded-lg flex items-center justify-center text-indigo-600">
                    <i class="fa-solid fa-envelope-open-text text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Total Emails Sent</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['total_emails_sent']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Lifetime total</p>
        </div>

        <!-- Success Rate -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center text-green-600">
                    <i class="fa-solid fa-check-circle text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Success Rate</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['success_rate'], 1); ?>%</h3>
            <p class="text-sm text-gray-500 mt-1">Overall performance</p>
        </div>
    </div>

    <!-- Two Column Layout -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- User Activity -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <h3 class="text-lg font-bold text-gray-800">User Activity</h3>
                <p class="text-sm text-gray-500 mt-1">Top users by emails sent</p>
            </div>
            
            <?php if (empty($userActivity)): ?>
                <div class="p-8 text-center text-gray-500">
                    <p>No user activity yet.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                            <tr>
                                <th class="px-6 py-3">User</th>
                                <th class="px-6 py-3">Role</th>
                                <th class="px-6 py-3">Recipients</th>
                                <th class="px-6 py-3">SMTP</th>
                                <th class="px-6 py-3">Sent</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($userActivity as $user): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-3">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($user['email']); ?></div>
                                </td>
                                <td class="px-6 py-3">
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
                                <td class="px-6 py-3"><?php echo number_format($user['recipient_count']); ?></td>
                                <td class="px-6 py-3"><?php echo number_format($user['smtp_count']); ?></td>
                                <td class="px-6 py-3 font-semibold text-gray-800"><?php echo number_format($user['emails_sent']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- SMTP Configuration Status -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
            <div class="p-6 border-b border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800">SMTP Configurations</h3>
                        <p class="text-sm text-gray-500 mt-1">Active SMTP servers</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="smtp_host_filter" class="text-sm text-gray-600">Filter by Host:</label>
                        <select id="smtp_host_filter" onchange="filterBySMTPHost(this.value)" class="rounded-lg border-gray-300 border px-3 py-1.5 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <option value="">All Hosts</option>
                            <?php foreach ($smtpHosts as $host): ?>
                                <option value="<?php echo htmlspecialchars($host['smtp_host']); ?>" <?php echo ($smtpHostFilter === $host['smtp_host']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($host['smtp_host']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <?php if (empty($smtpConfigs)): ?>
                <div class="p-8 text-center text-gray-500">
                    <p>No SMTP configurations yet.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Server</th>
                                <th class="px-6 py-3">Host</th>
                                <th class="px-6 py-3">Owner</th>
                                <th class="px-6 py-3">Today</th>
                                <th class="px-6 py-3">Quota</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($smtpConfigs as $smtp): ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-3">
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($smtp['server_name']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($smtp['provider']); ?></div>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="text-xs font-mono bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($smtp['smtp_host']); ?></span>
                                </td>
                                <td class="px-6 py-3 text-gray-600"><?php echo htmlspecialchars($smtp['username']); ?></td>
                                <td class="px-6 py-3 font-semibold"><?php echo number_format($smtp['emails_sent_today']); ?></td>
                                <td class="px-6 py-3"><?php echo number_format($smtp['daily_quota']); ?></td>
                                <td class="px-6 py-3">
                                    <?php if ($smtp['is_active']): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-bold text-gray-800">Recent Email Activity</h3>
            <p class="text-sm text-gray-500 mt-1">Latest 20 emails sent across all users</p>
        </div>
        
        <?php if (empty($recentLogs)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No activity yet.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                        <tr>
                            <th class="px-6 py-4">User</th>
                            <th class="px-6 py-4">Recipient</th>
                            <th class="px-6 py-4">Subject</th>
                            <th class="px-6 py-4">SMTP</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentLogs as $log): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <span class="font-medium text-gray-700"><?php echo htmlspecialchars($log['username']); ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($log['company_name']); ?></div>
                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($log['recipient_email']); ?></div>
                            </td>
                            <td class="px-6 py-4 truncate max-w-xs"><?php echo htmlspecialchars($log['subject']); ?></td>
                            <td class="px-6 py-4 text-xs text-gray-500"><?php echo htmlspecialchars($log['smtp_name']); ?></td>
                            <td class="px-6 py-4">
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Success
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800" title="<?php echo htmlspecialchars($log['error_message']); ?>">
                                        Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-gray-400">
                                <?php echo date('M j, H:i', strtotime($log['sent_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function filterBySMTPHost(host) {
    const url = new URL(window.location.href);
    if (host) {
        url.searchParams.set('smtp_host', host);
    } else {
        url.searchParams.delete('smtp_host');
    }
    window.location.href = url.toString();
}
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'Admin Dashboard');
?>
