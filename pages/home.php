<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';

$pdo = getDBConnection();

// Fetch Stats
$stats = [
    'recipients' => 0,
    'sent_total' => 0,
    'sent_success' => 0,
    'sent_failed' => 0
];

// Total Recipients
$stmt = $pdo->query("SELECT COUNT(*) FROM recipients");
$stats['recipients'] = $stmt->fetchColumn();

// Email Logs Stats
$stmt = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success, SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed FROM email_logs");
$row = $stmt->fetch();
$stats['sent_total'] = $row['total'] ?? 0;
$stats['sent_success'] = $row['success'] ?? 0;
$stats['sent_failed'] = $row['failed'] ?? 0;

// Recent Activity
$stmt = $pdo->query("
    SELECT l.*, r.email as recipient_email, r.company_name 
    FROM email_logs l 
    JOIN recipients r ON l.recipient_id = r.id 
    ORDER BY l.sent_at DESC 
    LIMIT 10
");
$recentLogs = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-8">
    <!-- Welcome Section -->
    <div class="bg-gradient-to-r from-gray-800 to-gray-900 rounded-2xl p-8 text-white shadow-lg">
        <h2 class="text-3xl font-bold mb-2">Welcome back!</h2>
        <p class="text-gray-300">Here's what's happening with your resume distribution.</p>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Recipients -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center text-blue-600">
                    <i class="fa-solid fa-users text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Total Candidates</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['recipients']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Active recipients</p>
        </div>

        <!-- Total Sent -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-purple-50 rounded-lg flex items-center justify-center text-purple-600">
                    <i class="fa-solid fa-paper-plane text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Emails Sent</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['sent_total']); ?></h3>
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
            <?php 
                $rate = $stats['sent_total'] > 0 ? ($stats['sent_success'] / $stats['sent_total']) * 100 : 0;
            ?>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($rate, 1); ?>%</h3>
            <p class="text-sm text-gray-500 mt-1"><?php echo number_format($stats['sent_success']); ?> successful</p>
        </div>

        <!-- Failed -->
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <div class="w-12 h-12 bg-red-50 rounded-lg flex items-center justify-center text-red-600">
                    <i class="fa-solid fa-exclamation-circle text-xl"></i>
                </div>
                <span class="text-xs font-medium text-gray-400 uppercase">Failed</span>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($stats['sent_failed']); ?></h3>
            <p class="text-sm text-gray-500 mt-1">Need attention</p>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-lg font-bold text-gray-800">Recent Activity</h3>
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
                            <th class="px-6 py-4">Recipient</th>
                            <th class="px-6 py-4">Subject</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recentLogs as $log): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($log['company_name']); ?></div>
                                <div class="text-xs text-gray-400"><?php echo htmlspecialchars($log['recipient_email']); ?></div>
                            </td>
                            <td class="px-6 py-4 truncate max-w-xs"><?php echo htmlspecialchars($log['subject']); ?></td>
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

<?php
$content = ob_get_clean();
renderLayout($content, 'Home');
?>
