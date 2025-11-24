<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/csv_parser.php';

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
                $stmt = $pdo->prepare("INSERT INTO recipients (email, company_name, position) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE company_name = VALUES(company_name), position = VALUES(position)");
                $stmt->execute([$_POST['email'], $_POST['company_name'], $_POST['position']]);
                $message = "Recipient added successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error adding recipient: " . $e->getMessage();
                $messageType = "error";
            }
        } elseif ($_POST['action'] === 'delete_recipient') {
            try {
                $stmt = $pdo->prepare("DELETE FROM recipients WHERE id = ?");
                $stmt->execute([$_POST['id']]);
                $message = "Recipient deleted successfully!";
                $messageType = "success";
            } catch (PDOException $e) {
                $message = "Error deleting recipient: " . $e->getMessage();
                $messageType = "error";
            }
        }
    }
}

// Fetch Recipients with Last Sent Date
$query = "
    SELECT r.*, MAX(l.sent_at) as last_sent 
    FROM recipients r 
    LEFT JOIN email_logs l ON r.id = l.recipient_id 
    GROUP BY r.id 
    ORDER BY r.created_at DESC
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
        <div class="flex gap-3">
            <button onclick="document.getElementById('uploadCsvModal').classList.remove('hidden')" class="bg-white text-gray-700 border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-file-csv text-green-600"></i> Upload CSV
            </button>
            <button onclick="document.getElementById('addRecipientModal').classList.remove('hidden')" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> Add Recipient
            </button>
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
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs uppercase font-semibold text-gray-500">
                        <tr>
                            <th class="px-6 py-4">Name / Company</th>
                            <th class="px-6 py-4">Email</th>
                            <th class="px-6 py-4">Position</th>
                            <th class="px-6 py-4">Last Sent</th>
                            <th class="px-6 py-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($recipients as $recipient): ?>
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-6 py-4">
                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($recipient['company_name']); ?></div>
                            </td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($recipient['email']); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-50 text-blue-700">
                                    <?php echo htmlspecialchars($recipient['position']); ?>
                                </span>
                            </td>
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

<?php
$content = ob_get_clean();
renderLayout($content, 'Recipients');
?>
