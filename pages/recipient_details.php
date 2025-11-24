<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';

$pdo = getDBConnection();

// Get Recipient ID
$recipientId = $_GET['id'] ?? null;

if (!$recipientId) {
    header('Location: ' . BASE_URL . '/pages/recipients.php');
    exit;
}

// Fetch Recipient Details
$stmt = $pdo->prepare("SELECT * FROM recipients WHERE id = ?");
$stmt->execute([$recipientId]);
$recipient = $stmt->fetch();

if (!$recipient) {
    header('Location: ' . BASE_URL . '/pages/recipients.php');
    exit;
}

// Pagination Setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Fetch Total Logs Count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM email_logs WHERE recipient_id = ?");
$stmt->execute([$recipientId]);
$totalLogs = $stmt->fetchColumn();
$totalPages = ceil($totalLogs / $limit);

// Fetch Email Logs with Pagination
$stmt = $pdo->prepare("
    SELECT l.*, s.server_name, s.from_email 
    FROM email_logs l 
    LEFT JOIN smtp_configurations s ON l.smtp_config_id = s.id 
    WHERE l.recipient_id = ? 
    ORDER BY l.sent_at DESC
    LIMIT ? OFFSET ?
");
$stmt->bindParam(1, $recipientId, PDO::PARAM_INT);
$stmt->bindParam(2, $limit, PDO::PARAM_INT);
$stmt->bindParam(3, $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

// Fetch All Logs for Stats & Chart (Separate Query)
$stmt = $pdo->prepare("SELECT sent_at, status FROM email_logs WHERE recipient_id = ? ORDER BY sent_at DESC");
$stmt->execute([$recipientId]);
$allLogs = $stmt->fetchAll();

// Calculate Stats
// Calculate Stats (Using allLogs)
$totalSent = count($allLogs);
$successCount = 0;
$failedCount = 0;
$lastContacted = 'Never';

if ($totalSent > 0) {
    $lastContacted = date('M j, Y', strtotime($allLogs[0]['sent_at']));
    foreach ($allLogs as $log) {
        if ($log['status'] === 'success') $successCount++;
        else $failedCount++;
    }
}

$successRate = $totalSent > 0 ? ($successCount / $totalSent) * 100 : 0;

// Prepare Chart Data (Last 30 Days)
$chartData = [];
$labels = [];
$data = [];

for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels[] = date('M j', strtotime($date));
    $count = 0;
    foreach ($allLogs as $log) {
        if (date('Y-m-d', strtotime($log['sent_at'])) === $date) {
            $count++;
        }
    }
    $data[] = $count;
}

ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="<?php echo BASE_URL; ?>/pages/recipients.php" class="bg-white p-2 rounded-lg border border-gray-200 text-gray-600 hover:text-blue-600 hover:border-blue-300 transition-all">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h3 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($recipient['company_name']); ?></h3>
            <p class="text-gray-500"><?php echo htmlspecialchars($recipient['position']); ?> • <?php echo htmlspecialchars($recipient['email']); ?></p>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-500 uppercase">Total Emails</span>
                <div class="w-8 h-8 bg-blue-50 rounded-full flex items-center justify-center text-blue-600">
                    <i class="fa-solid fa-envelope"></i>
                </div>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo $totalSent; ?></h3>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-500 uppercase">Success Rate</span>
                <div class="w-8 h-8 bg-green-50 rounded-full flex items-center justify-center text-green-600">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <h3 class="text-3xl font-bold text-gray-800"><?php echo number_format($successRate, 1); ?>%</h3>
        </div>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-500 uppercase">Last Contacted</span>
                <div class="w-8 h-8 bg-purple-50 rounded-full flex items-center justify-center text-purple-600">
                    <i class="fa-solid fa-clock"></i>
                </div>
            </div>
            <h3 class="text-xl font-bold text-gray-800"><?php echo $lastContacted; ?></h3>
        </div>
    </div>

    <!-- Chart Section -->
    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200">
        <h4 class="text-lg font-bold text-gray-800 mb-4">Activity (Last 30 Days)</h4>
        <div class="h-64">
            <canvas id="activityChart"></canvas>
        </div>
    </div>

    <!-- Activity Log -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <div class="p-6 border-b border-gray-100">
            <h4 class="text-lg font-bold text-gray-800">Email History</h4>
        </div>
        
        <?php if (empty($logs)): ?>
            <div class="p-8 text-center text-gray-500">
                <p>No emails sent to this recipient yet.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                        <tr>
                            <th class="px-6 py-4">Date</th>
                            <th class="px-6 py-4">Subject</th>
                            <th class="px-6 py-4">Status</th>
                            <th class="px-6 py-4">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($logs as $log): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php echo date('M j, Y H:i', strtotime($log['sent_at'])); ?>
                            </td>
                            <td class="px-6 py-4 font-medium text-gray-900">
                                <?php echo htmlspecialchars($log['subject']); ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($log['status'] === 'success'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        Success
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <button onclick="toggleDetails(<?php echo $log['id']; ?>)" class="text-blue-500 hover:text-blue-700 text-xs font-medium">
                                    View Content
                                </button>
                            </td>
                        </tr>
                        <!-- Expandable Details Row -->
                        <tr id="details-<?php echo $log['id']; ?>" class="hidden bg-gray-50">
                            <td colspan="4" class="px-6 py-4">
                                <div class="space-y-3 text-xs">
                                    <div>
                                        <span class="font-bold text-gray-700 block mb-1">Sent via:</span>
                                        <span class="text-gray-600"><?php echo htmlspecialchars($log['server_name'] . ' (' . $log['from_email'] . ')'); ?></span>
                                    </div>
                                    <?php if ($log['status'] === 'failed'): ?>
                                    <div>
                                        <span class="font-bold text-red-700 block mb-1">Error Message:</span>
                                        <code class="bg-red-100 text-red-800 px-2 py-1 rounded block whitespace-pre-wrap"><?php echo htmlspecialchars($log['error_message']); ?></code>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <span class="font-bold text-gray-700 block mb-1">Body Content:</span>
                                        <div class="bg-white border border-gray-200 p-3 rounded text-gray-600 max-h-40 overflow-y-auto whitespace-pre-line">
                                            <?php echo nl2br(htmlspecialchars($log['body'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between">
                <div class="text-sm text-gray-500">
                    Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalLogs); ?> of <?php echo $totalLogs; ?> entries
                </div>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="?id=<?php echo $recipientId; ?>&page=<?php echo $page - 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50">Previous</a>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?id=<?php echo $recipientId; ?>&page=<?php echo $page + 1; ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm text-gray-600 hover:bg-gray-50">Next</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Chart Initialization
const ctx = document.getElementById('activityChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($labels); ?>,
        datasets: [{
            label: 'Emails Sent',
            data: <?php echo json_encode($data); ?>,
            backgroundColor: 'rgba(59, 130, 246, 0.5)',
            borderColor: 'rgb(59, 130, 246)',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});

// Toggle Details Function
function toggleDetails(id) {
    const row = document.getElementById('details-' + id);
    if (row.classList.contains('hidden')) {
        row.classList.remove('hidden');
    } else {
        row.classList.add('hidden');
    }
}
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'Recipient Details');
?>
