<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/csv_parser.php';
require_once __DIR__ . '/../utils/auth.php';

requireAuth();

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'upload_csv') {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['csv_file']['tmp_name'];
                $parseResult = parseCSV($fileTmpPath);
                
                if ($parseResult['success']) {
                    $importResult = importRecipients($parseResult['data']);
                    $message = "Imported: {$importResult['imported']}, Skipped: {$importResult['skipped']}.";
                    if (!empty($parseResult['errors'])) {
                        $message .= " CSV Errors: " . count($parseResult['errors']);
                    }
                    $messageType = "success";
                } else {
                    $message = "Error parsing CSV: " . $parseResult['error'];
                    $messageType = "error";
                }
            } else {
                $message = "Error uploading file.";
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'add_manual') {
            try {
                $stmt = $pdo->prepare("INSERT INTO recipients (user_id, email, company_name, position) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), position = VALUES(position)");
                $stmt->execute([getCurrentUserId(), $_POST['email'], $_POST['company_name'], $_POST['position']]);
                $message = "Recipient added successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error adding recipient: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'delete_recipient') {
            try {
                $stmt = $pdo->prepare("DELETE FROM recipients WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['id'], getCurrentUserId()]);
                $message = "Recipient deleted successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error deleting recipient: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'edit_recipient') {
            try {
                $stmt = $pdo->prepare("UPDATE recipients SET email = ?, company_name = ?, position = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([$_POST['email'], $_POST['company_name'], $_POST['position'], $_POST['id'], getCurrentUserId()]);
                $message = "Recipient updated successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error updating recipient: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'delete_batch') {
            if (isset($_POST['ids']) && is_array($_POST['ids'])) {
                try {
                    $ids = array_map('intval', $_POST['ids']);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $params = $ids;
                    $params[] = getCurrentUserId();
                    
                    $stmt = $pdo->prepare("DELETE FROM recipients WHERE id IN ($placeholders) AND user_id = ?");
                    $stmt->execute($params);
                    
                    $deletedCount = $stmt->rowCount();
                    $message = "$deletedCount recipient(s) deleted successfully!";
                    $messageType = "success";
                } catch (PDOException $e) {
                    $message = "Error deleting recipients: " . $e->getMessage();
                    $messageType = "error";
                }
            }
        }
    }
}

// Handle user filter for admins
$selectedUserId = $_GET['filter_user'] ?? '';
$userFilterExtra = '';
if (isAdmin() && !empty($selectedUserId)) {
    $userFilterExtra = " AND r.user_id = " . intval($selectedUserId);
}

// Handle search
$searchQuery = $_GET['search'] ?? '';
$searchFilter = '';
if (!empty($searchQuery)) {
    $searchTerm = '%' . $searchQuery . '%';
    $searchFilter = " AND (r.email LIKE " . $pdo->quote($searchTerm) . 
                   " OR r.company_name LIKE " . $pdo->quote($searchTerm) . 
                   " OR r.position LIKE " . $pdo->quote($searchTerm) . ")";
}
    
// Handle status filter
$filterStatus = $_GET['filter_status'] ?? '';
$statusFilter = '';
if ($filterStatus === 'sent') {
    $statusFilter = " AND l.recipient_id IS NOT NULL";
} elseif ($filterStatus === 'not_sent') {
    $statusFilter = " AND l.recipient_id IS NULL";
}

// Pagination Setup
$userFilter = getUserFilter('r');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Count Total Records
$countQuery = "
    SELECT COUNT(DISTINCT r.id) 
    FROM recipients r 
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN email_logs l ON r.id = l.recipient_id 
    WHERE 1=1 $userFilter $userFilterExtra $searchFilter $statusFilter
";
$totalRecipients = $pdo->query($countQuery)->fetchColumn();
$totalPages = ceil($totalRecipients / $limit);

// Fetch all users for filter dropdown (admin only)
$users = [];
if (isAdmin()) {
    $stmt = $pdo->query("SELECT id, username FROM users ORDER BY username ASC");
    $users = $stmt->fetchAll();
}

// Calculate Statistics
// We want overall stats for the current user filter, IGNORING the search/status filters
$statsRecipients = "
    SELECT 
        COUNT(DISTINCT r.id) as total,
        COUNT(DISTINCT CASE WHEN l.recipient_id IS NOT NULL THEN r.id END) as sent_total
    FROM recipients r
    LEFT JOIN email_logs l ON r.id = l.recipient_id
    WHERE 1=1 $userFilter $userFilterExtra
";
$statsRow = $pdo->query($statsRecipients)->fetch();
$totalCount = $statsRow['total'] ?? 0;
$totalSent = $statsRow['sent_total'] ?? 0;
$totalNotSent = max(0, $totalCount - $totalSent);

// Sent Today Count (Successful sends only)
// Note: We need to join recipients to ensure we only count logs for valid recipients 
// that the current user has access to.
$sentTodayQuery = "
    SELECT COUNT(el.id) 
    FROM email_logs el 
    JOIN recipients r ON el.recipient_id = r.id 
    WHERE el.status = 'success' 
    AND DATE(el.sent_at) = CURDATE()
    $userFilter $userFilterExtra
";
$sentToday = $pdo->query($sentTodayQuery)->fetchColumn() ?? 0;

// Fetch Recipients with Last Sent Date
$query = "
    SELECT r.*, 
    u.username as owner_username,
    MAX(l.sent_at) as last_sent 
    FROM recipients r 
    LEFT JOIN users u ON r.user_id = u.id
    LEFT JOIN email_logs l ON r.id = l.recipient_id 
    WHERE 1=1 $userFilter $userFilterExtra $searchFilter $statusFilter
    GROUP BY r.id 
    ORDER BY r.created_at DESC
    LIMIT $limit OFFSET $offset
";
$stmt = $pdo->query($query);
$recipients = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-6">
    <!-- Header & Actions -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4">
        <div>
            <h3 class="text-2xl font-bold text-gray-800">Recipients</h3>
            <p class="text-gray-500 mt-1">Manage your contact list for resume distribution.</p>
        </div>
        <div class="flex items-center gap-3">
            <?php if (isAdmin() && !empty($users)): ?>
            <form method="GET" class="flex items-center gap-2">
                <?php if (!empty($searchQuery)): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                <?php endif; ?>
                <?php if (!empty($filterStatus)): ?>
                <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                <?php endif; ?>
                <select name="filter_user" onchange="this.form.submit()" class="rounded-lg border-gray-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <option value="">All Users</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <form method="GET" class="flex-1 flex max-w-xl">
                <?php if (!empty($selectedUserId)): ?>
                <input type="hidden" name="filter_user" value="<?php echo htmlspecialchars($selectedUserId); ?>">
                <?php endif; ?>
                <select name="filter_status" onchange="this.form.submit()" class="rounded-lg border-gray-300 border px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none mr-2">
                    <option value="">All Status</option>
                    <option value="sent" <?php echo $filterStatus === 'sent' ? 'selected' : ''; ?>>Sent</option>
                    <option value="not_sent" <?php echo $filterStatus === 'not_sent' ? 'selected' : ''; ?>>Not Sent</option>
                </select>
                <div class="relative flex-1">
                    <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search by email, company, or position..." class="w-full rounded-lg border-gray-300 border px-4 py-2 pl-10 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    <i class="fa-solid fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                </div>
            </form>
            <button onclick="document.getElementById('uploadCsvModal').classList.remove('hidden')" class="bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-file-csv text-green-600"></i> Upload CSV
            </button>
            <button onclick="deleteBatch()" id="deleteBatchBtn" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2 hidden disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="fa-solid fa-trash"></i> Delete Selected
            </button>
            <button onclick="document.getElementById('addRecipientModal').classList.remove('hidden')" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Add Recipient
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Recipients -->
        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-gray-500 text-sm font-medium">Total Recipients</p>
                    <span class="bg-purple-100 text-purple-800 text-xs px-2 py-0.5 rounded-full font-medium">Database</span>
                </div>
                <h4 class="text-3xl font-bold text-gray-800"><?php echo number_format($totalCount); ?></h4>
                <p class="text-xs text-gray-400 mt-2">All contacts in your list</p>
            </div>
            <i class="fa-solid fa-address-book absolute -bottom-4 -right-4 text-8xl text-purple-100 opacity-50 transform -rotate-12"></i>
        </div>

        <!-- Sent Today -->
        <div class="bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl p-6 text-white shadow-sm relative overflow-hidden">
            <div class="relative z-10">
                <p class="text-blue-100 text-sm font-medium mb-1">Sent Today</p>
                <h4 class="text-3xl font-bold"><?php echo number_format($sentToday); ?></h4>
                <p class="text-xs text-blue-200 mt-2">Successful emails today</p>
            </div>
            <i class="fa-solid fa-paper-plane absolute -bottom-4 -right-4 text-8xl text-blue-400 opacity-20 transform rotate-12"></i>
        </div>

        <!-- Not Sent Yet -->
        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-gray-500 text-sm font-medium">Not Sent Yet</p>
                    <span class="bg-yellow-100 text-yellow-800 text-xs px-2 py-0.5 rounded-full font-medium">Potential</span>
                </div>
                <h4 class="text-3xl font-bold text-gray-800"><?php echo number_format($totalNotSent); ?></h4>
                <div class="w-full bg-gray-100 rounded-full h-1.5 mt-3">
                    <?php $notSentPercent = $totalCount > 0 ? ($totalNotSent / $totalCount) * 100 : 0; ?>
                    <div class="bg-yellow-400 h-1.5 rounded-full" style="width: <?php echo $notSentPercent; ?>%"></div>
                </div>
                <p class="text-xs text-gray-400 mt-2"><?php echo number_format($notSentPercent, 1); ?>% of total recipients</p>
            </div>
        </div>

        <!-- Total Sent -->
        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm relative overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-gray-500 text-sm font-medium">Total Sent</p>
                    <span class="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded-full font-medium">Lifetime</span>
                </div>
                <h4 class="text-3xl font-bold text-gray-800"><?php echo number_format($totalSent); ?></h4>
                <div class="w-full bg-gray-100 rounded-full h-1.5 mt-3">
                    <?php $sentPercent = $totalCount > 0 ? ($totalSent / $totalCount) * 100 : 0; ?>
                    <div class="bg-green-500 h-1.5 rounded-full" style="width: <?php echo $sentPercent; ?>%"></div>
                </div>
                <p class="text-xs text-gray-400 mt-2"><?php echo number_format($sentPercent, 1); ?>% of total recipients</p>
            </div>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Recipients List -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
        <?php if (empty($recipients)): ?>
            <div class="p-12 text-center text-gray-500">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-users text-2xl text-gray-400"></i>
                </div>
                <p class="text-lg font-medium">No recipients found.</p>
                <p class="text-sm mt-1">Upload a CSV or add manually to get started.</p>
            </div>
        <?php else: ?>
            <!-- Selection Stats -->
            <div id="selectionStats" class="bg-blue-50 px-6 py-2 border-b border-blue-100 flex gap-4 text-xs font-medium text-blue-800 hidden">
                <span>Selected: <span id="countSelected" class="font-bold">0</span></span>
                <span class="text-blue-300">|</span>
                <span>New: <span id="countNew" class="font-bold">0</span></span>
                <span class="text-blue-300">|</span>
                <span>Follow-up: <span id="countFollowup" class="font-bold">0</span></span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                        <tr>
                            <th class="px-6 py-4 w-4">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                            </th>
                            <th class="px-6 py-4">Name / Company</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Position</th>
                            <?php if (isAdmin()): ?>
                            <th class="px-6 py-4">Owner</th>
                            <?php endif; ?>
                            <th class="px-6 py-4">Last Sent</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recipients as $recipient): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <input type="checkbox" name="recipient_ids[]" value="<?php echo $recipient['id']; ?>" data-is-new="<?php echo empty($recipient['last_sent']) ? '1' : '0'; ?>" class="recipient-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer">
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($recipient['company_name']); ?></div>
                            </td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($recipient['email']); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                    <?php echo htmlspecialchars($recipient['position']); ?>
                                </span>
                            </td>
                            <?php if (isAdmin()): ?>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                    <i class="fa-solid fa-user mr-1"></i>
                                    <?php echo htmlspecialchars($recipient['owner_username'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td class="px-6 py-4">
                                <?php if ($recipient['last_sent']): ?>
                                    <span class="text-green-600 flex items-center gap-1">
                                        <i class="fa-solid fa-check-circle text-xs"></i>
                                        <?php echo date('M j, Y', strtotime($recipient['last_sent'])); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">Never</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="<?php echo BASE_URL; ?>/pages/recipient_details.php?id=<?php echo $recipient['id']; ?>" class="text-blue-500 hover:text-blue-700 p-2 rounded-full hover:bg-blue-50 transition-colors mr-1" title="View Details">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                                <button onclick='openEditModal(<?php echo json_encode($recipient); ?>)' class="text-green-500 hover:text-green-700 p-2 rounded-full hover:bg-green-50 transition-colors mr-1" title="Edit">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this recipient?');" class="inline-block">
                                    <input type="hidden" name="action" value="delete_recipient">
                                    <input type="hidden" name="id" value="<?php echo $recipient['id']; ?>">
                                    <button type="submit" class="text-gray-400 hover:text-red-500 transition-colors">
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

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex justify-between items-center bg-white px-4 py-3 border-t border-gray-200 sm:px-6 rounded-lg shadow-sm">
        <div class="flex-1 flex justify-between sm:hidden">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchQuery); ?>&filter_user=<?php echo urlencode($selectedUserId); ?>&filter_status=<?php echo urlencode($filterStatus); ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Previous
            </a>
            <?php else: ?>
            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-gray-50 cursor-not-allowed">
                Previous
            </span>
            <?php endif; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchQuery); ?>&filter_user=<?php echo urlencode($selectedUserId); ?>&filter_status=<?php echo urlencode($filterStatus); ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Next
            </a>
            <?php else: ?>
            <span class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-gray-50 cursor-not-allowed">
                Next
            </span>
            <?php endif; ?>
        </div>
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Showing <span class="font-medium"><?php echo ($offset + 1); ?></span> to <span class="font-medium"><?php echo min($offset + $limit, $totalRecipients); ?></span> of <span class="font-medium"><?php echo $totalRecipients; ?></span> results
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <!-- Previous -->
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchQuery); ?>&filter_user=<?php echo urlencode($selectedUserId); ?>&filter_status=<?php echo urlencode($filterStatus); ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Previous</span>
                        <i class="fa-solid fa-chevron-left h-5 w-5"></i>
                    </a>
                    <?php else: ?>
                    <span class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-gray-50 text-sm font-medium text-gray-300 cursor-not-allowed">
                        <span class="sr-only">Previous</span>
                        <i class="fa-solid fa-chevron-left h-5 w-5"></i>
                    </span>
                    <?php endif; ?>
                    
                    <!-- Page Numbers (Simple range for now) -->
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1) {
                         echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                        <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchQuery); ?>&filter_user=<?php echo urlencode($selectedUserId); ?>&filter_status=<?php echo urlencode($filterStatus); ?>" aria-current="page" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium <?php echo $i === $page ? 'bg-blue-50 text-blue-600 z-10 border-blue-500' : 'bg-white text-gray-500 hover:bg-gray-50'; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($endPage < $totalPages): ?>
                         <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>
                    <?php endif; ?>

                    <!-- Next -->
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchQuery); ?>&filter_user=<?php echo urlencode($selectedUserId); ?>&filter_status=<?php echo urlencode($filterStatus); ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Next</span>
                        <i class="fa-solid fa-chevron-right h-5 w-5"></i>
                    </a>
                    <?php else: ?>
                    <span class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-gray-50 text-sm font-medium text-gray-300 cursor-not-allowed">
                        <span class="sr-only">Next</span>
                        <i class="fa-solid fa-chevron-right h-5 w-5"></i>
                    </span>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Upload CSV Modal -->
<div id="uploadCsvModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Upload Recipients CSV</h3>
            <button onclick="document.getElementById('uploadCsvModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
            <input type="hidden" name="action" value="upload_csv">
            
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">Select CSV File</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-blue-500 transition-colors cursor-pointer bg-gray-50" onclick="document.getElementById('csv_file').click()">
                    <i class="fa-solid fa-cloud-arrow-up text-3xl text-gray-400 mb-2"></i>
                    <p class="text-sm text-gray-500">Click to upload or drag and drop</p>
                    <p class="text-xs text-gray-400 mt-1">Format: email, company_name, position</p>
                </div>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required class="hidden" onchange="this.form.submit()">
            </div>
            
            <div class="bg-blue-50 p-4 rounded-lg text-sm text-blue-700">
                <p class="font-semibold mb-1"><i class="fa-solid fa-info-circle mr-1"></i> CSV Format Required:</p>
                <p>The CSV file must have headers: <code>email</code>, <code>company_name</code>, <code>position</code>.</p>
            </div>
        </form>
    </div>
</div>

<!-- Add Recipient Modal -->
<div id="addRecipientModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Add Manual Recipient</h3>
            <button onclick="document.getElementById('addRecipientModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="add_manual">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" name="company_name" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                <input type="text" name="position" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('addRecipientModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Add Recipient</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Recipient Modal -->
<div id="editRecipientModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Edit Recipient</h3>
            <button onclick="document.getElementById('editRecipientModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="edit_recipient">
            <input type="hidden" name="id" id="edit_id">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                <input type="email" name="email" id="edit_email" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" name="company_name" id="edit_company_name" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Position</label>
                <input type="text" name="position" id="edit_position" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            </div>
            
            <div class="pt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('editRecipientModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(recipient) {
    document.getElementById('edit_id').value = recipient.id;
    document.getElementById('edit_email').value = recipient.email;
    document.getElementById('edit_company_name').value = recipient.company_name;
    document.getElementById('edit_position').value = recipient.position;
    document.getElementById('editRecipientModal').classList.remove('hidden');
}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('selectAll');
    const recipientCheckboxes = document.querySelectorAll('.recipient-checkbox');
    const deleteBatchBtn = document.getElementById('deleteBatchBtn');
    
    // Toggle Delete Button visibility and Update Stats
    function updateSelectionStats() {
        const checkedBoxes = document.querySelectorAll('.recipient-checkbox:checked');
        const checkedCount = checkedBoxes.length;
        
        // Update Delete Button
        if (checkedCount > 0) {
            deleteBatchBtn.classList.remove('hidden');
            deleteBatchBtn.innerHTML = `<i class="fa-solid fa-trash"></i> Delete Selected (${checkedCount})`;
            document.getElementById('selectionStats').classList.remove('hidden');
        } else {
            deleteBatchBtn.classList.add('hidden');
            document.getElementById('selectionStats').classList.add('hidden');
        }

        // Update Stats
        let isNew = 0;
        let followup = 0;
        
        checkedBoxes.forEach(cb => {
            if (cb.getAttribute('data-is-new') === '1') {
                isNew++;
            } else {
                followup++;
            }
        });
        
        document.getElementById('countSelected').textContent = checkedCount;
        document.getElementById('countNew').textContent = isNew;
        document.getElementById('countFollowup').textContent = followup;
    }
    
    // Select All
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            recipientCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateSelectionStats();
        });
    }
    
    // Individual Checkboxes
    recipientCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateSelectionStats();
            // Update Select All checkbox state
            if (selectAllCheckbox) {
                const allChecked = document.querySelectorAll('.recipient-checkbox:checked').length === recipientCheckboxes.length;
                selectAllCheckbox.checked = allChecked;
            }
        });
    });
});

function deleteBatch() {
    const checkedBoxes = document.querySelectorAll('.recipient-checkbox:checked');
    if (checkedBoxes.length === 0) return;
    
    if (confirm(`Are you sure you want to delete ${checkedBoxes.length} recipient(s)? This action cannot be undone.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_batch';
        form.appendChild(actionInput);
        
        checkedBoxes.forEach(box => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = box.value;
            form.appendChild(input);
        });
        
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'Recipients');
?>
