<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/email_sender.php';
require_once __DIR__ . '/../utils/auth.php';

requireAuth();

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Fetch SMTP Configurations with usage stats
$stmt = $pdo->query("
    SELECT s.*, 
    (SELECT COUNT(*) FROM email_logs l 
     WHERE l.smtp_config_id = s.id 
     AND DATE(l.sent_at) = CURDATE() 
     AND l.status = 'success') as used_today
    FROM smtp_configurations s 
    WHERE s.is_active = 1 
    ORDER BY s.server_name ASC
");
$smtpConfigs = $stmt->fetchAll();

// Fetch Recipients for Selection List
$userFilter = getUserFilter('r');
$stmt = $pdo->query("
    SELECT r.*, MAX(l.sent_at) as last_sent 
    FROM recipients r 
    LEFT JOIN email_logs l ON r.id = l.recipient_id 
    WHERE 1=1 $userFilter
    GROUP BY r.id 
    ORDER BY r.company_name ASC
");
$recipientList = $stmt->fetchAll();

// Fetch Templates
$stmt = $pdo->prepare("SELECT * FROM email_templates WHERE user_id = ? ORDER BY name ASC");
$stmt->execute([getCurrentUserId()]);
$templates = $stmt->fetchAll();

// Handle AJAX Requests
if (isset($_GET['action']) && $_GET['action'] === 'get_recipients') {
    header('Content-Type: application/json');
    try {
        $targetAudience = $_GET['target_audience'] ?? '';
        $recipientsToSend = [];

        // Fetch all recipients with last_sent info for filtering
        $stmt = $pdo->query("
            SELECT r.id, MAX(l.sent_at) as last_sent 
            FROM recipients r 
            LEFT JOIN email_logs l ON r.id = l.recipient_id 
            WHERE 1=1 $userFilter
            GROUP BY r.id
        ");
        $allRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($targetAudience === 'all') {
            $recipientsToSend = array_column($allRecipients, 'id');
        } elseif ($targetAudience === 'new') {
            foreach ($allRecipients as $r) {
                if (empty($r['last_sent'])) {
                    $recipientsToSend[] = $r['id'];
                }
            }
        } elseif ($targetAudience === 'followup') {
            foreach ($allRecipients as $r) {
                if (!empty($r['last_sent'])) {
                    $recipientsToSend[] = $r['id'];
                }
            }
        }
        
        echo json_encode(['success' => true, 'ids' => $recipientsToSend]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $smtpConfigId = $_POST['smtp_config_id'];
    $subject = $_POST['subject'];
    $body = $_POST['body'];
    $action = $_POST['action'];
    
    // Handle Attachments
    $attachments = [];
    if (isset($_FILES['attachments']) && !empty($_FILES['attachments']['name'][0])) {
        $fileCount = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                $fileName = $_FILES['attachments']['name'][$i];
                $fileTmp = $_FILES['attachments']['tmp_name'][$i];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if (in_array($fileExt, ALLOWED_EXTENSIONS)) {
                    $newFileName = uniqid() . '_' . $fileName;
                    $destination = ATTACHMENT_DIR . '/' . $newFileName;
                    
                    if (move_uploaded_file($fileTmp, $destination)) {
                        $attachments[] = [
                            'name' => $fileName,
                            'path' => $destination
                        ];
                    }
                }
            }
        }
    }
    
    if ($action === 'test_send') {
        $testEmail = $_POST['test_email'];
        // Create a dummy recipient for testing or just use the mailer directly
        // Using mailer directly is better for testing
        try {
            $stmt = $pdo->prepare("SELECT * FROM smtp_configurations WHERE id = ?");
            $stmt->execute([$smtpConfigId]);
            $config = $stmt->fetch();
            
            if ($config) {
                $mailer = new SMTPMailer($config);
                if ($mailer->sendEmail($testEmail, $subject, $body, $attachments)) {
                    $message = "Test email sent successfully to $testEmail";
                    $messageType = "success";
                } else {
                    $message = "Failed to send test email: " . $mailer->getError();
                    $messageType = "error";
                }
            }
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = "error";
        }
    } elseif ($action === 'send_batch') {
        $targetAudience = $_POST['target_audience'];
        $recipientsToSend = [];

        // Fetch all recipients with last_sent info for filtering
        $stmt = $pdo->query("
            SELECT r.id, MAX(l.sent_at) as last_sent 
            FROM recipients r 
            LEFT JOIN email_logs l ON r.id = l.recipient_id 
            GROUP BY r.id
        ");
        $allRecipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($targetAudience === 'all') {
            $recipientsToSend = array_column($allRecipients, 'id');
        } elseif ($targetAudience === 'new') {
            foreach ($allRecipients as $r) {
                if (empty($r['last_sent'])) {
                    $recipientsToSend[] = $r['id'];
                }
            }
        } elseif ($targetAudience === 'followup') {
            foreach ($allRecipients as $r) {
                if (!empty($r['last_sent'])) {
                    $recipientsToSend[] = $r['id'];
                }
            }
        } elseif ($targetAudience === 'specific') {
            $recipientsToSend = $_POST['recipient_ids'] ?? [];
        }
        
        $successCount = 0;
        $failCount = 0;
        
        if (empty($recipientsToSend)) {
            $message = "No recipients selected for the chosen target audience.";
            $messageType = "warning";
        } else {
            foreach ($recipientsToSend as $recipientId) {
                $result = sendEmailToRecipient($recipientId, $smtpConfigId, $subject, $body, $attachments);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $failCount++;
                }
            }
            
            $message = "Batch sending completed. Success: $successCount, Failed: $failCount";
            $messageType = ($failCount == 0) ? "success" : "warning";
        }
    }
    
    // Clean up attachments if needed? 
    // For now we keep them as they are referenced in logs.
}

ob_start();
?>

<div class="space-y-6">
    <!-- Header -->
    <div>
        <h3 class="text-2xl font-bold text-gray-800">Send Email</h3>
        <p class="text-gray-500 mt-1">Compose and send emails to your recipients.</p>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : ($messageType === 'warning' ? 'bg-yellow-50 text-yellow-700 border border-yellow-200' : 'bg-red-50 text-red-700 border border-red-200'); ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Form -->
        <div class="lg:col-span-2 space-y-6">
            <form method="POST" enctype="multipart/form-data" id="sendForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 space-y-6">
                
                <!-- SMTP Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select SMTP Server</label>
                    <select name="smtp_config_id" required class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-white">
                        <?php foreach ($smtpConfigs as $config): ?>
                            <?php 
                                $remaining = max(0, $config['daily_quota'] - $config['used_today']);
                                $isBlocked = ($config['quota_exceeded_at'] && date('Y-m-d', strtotime($config['quota_exceeded_at'])) == date('Y-m-d'));
                                $isLimitReached = ($remaining <= 0);
                                $disabled = $isBlocked || $isLimitReached;
                                
                                $label = htmlspecialchars($config['server_name']);
                                if ($isBlocked) {
                                    $label .= " (BLOCKED - Quota Exceeded)";
                                } elseif ($isLimitReached) {
                                    $label .= " (Limit Reached)";
                                } else {
                                    $label .= " ({$remaining}/{$config['daily_quota']} left)";
                                }
                            ?>
                            <option value="<?php echo $config['id']; ?>" <?php echo $disabled ? 'disabled' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($smtpConfigs)): ?>
                        <p class="text-xs text-red-500 mt-1">No SMTP configurations found. Please add one in Settings.</p>
                    <?php endif; ?>
                </div>

                <!-- Target Audience -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Target Audience</label>
                    <select name="target_audience" id="target_audience" onchange="toggleRecipientSelection()" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-white">
                        <option value="all">All Recipients</option>
                        <option value="new">New Candidates (Not Contacted)</option>
                        <option value="followup">Follow-up (Previously Contacted)</option>
                        <option value="specific">Specific Recipients</option>
                    </select>
                </div>

                <!-- Specific Recipients List (Hidden by default) -->
                <div id="specific_recipients_container" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Select Recipients</label>
                    <div class="border border-gray-300 rounded-lg overflow-hidden">
                        <div class="bg-gray-50 px-3 py-2 border-b border-gray-200 flex flex-col gap-2">
                            <div class="flex justify-between items-center flex-wrap gap-2">
                                <span class="text-xs text-gray-500">Select individuals to email</span>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" onclick="selectNewRecipients(50)" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 transition-colors">Select 50 New</button>
                                    <button type="button" onclick="selectNewRecipients(100)" class="text-xs bg-blue-100 text-blue-700 px-2 py-1 rounded hover:bg-blue-200 transition-colors">Select 100 New</button>
                                    <div class="w-px h-4 bg-gray-300 mx-1"></div>
                                    <button type="button" onclick="selectAllRecipients(true)" class="text-xs text-blue-600 hover:text-blue-800">Select All</button>
                                    <button type="button" onclick="selectAllRecipients(false)" class="text-xs text-gray-500 hover:text-gray-700">Deselect All</button>
                                </div>
                            </div>
                            <input type="text" 
                                   id="recipientSearch" 
                                   onkeyup="filterRecipients()" 
                                   placeholder="Search by name, email, or position..." 
                                   class="w-full text-xs border border-gray-300 rounded px-2 py-1.5 focus:ring-1 focus:ring-blue-500 focus:border-blue-500 outline-none">
                            <div class="mt-1 flex gap-3 text-xs text-gray-600 font-medium">
                                <span>Selected: <span id="countSelected" class="text-blue-600">0</span></span>
                                <span class="text-gray-400">|</span>
                                <span>New: <span id="countNew" class="text-green-600">0</span></span>
                                <span class="text-gray-400">|</span>
                                <span>Follow-up: <span id="countFollowup" class="text-orange-600">0</span></span>
                            </div>
                        </div>
                        <div class="max-h-64 overflow-y-auto p-2 space-y-1" id="recipientList">
                            <?php foreach ($recipientList as $recipient): ?>
                            <label class="recipient-item flex items-center p-2 hover:bg-gray-50 rounded cursor-pointer transition-colors duration-150">
                                <input type="checkbox" name="recipient_ids[]" value="<?php echo $recipient['id']; ?>" data-is-new="<?php echo empty($recipient['last_sent']) ? '1' : '0'; ?>" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex justify-between">
                                        <p class="text-sm font-medium text-gray-900 truncate search-target"><?php echo htmlspecialchars($recipient['company_name']); ?></p>
                                        <?php if ($recipient['last_sent']): ?>
                                            <span class="text-xs text-green-600 bg-green-50 px-1.5 rounded">Sent: <?php echo date('M j', strtotime($recipient['last_sent'])); ?></span>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 rounded">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-gray-500 truncate search-target"><?php echo htmlspecialchars($recipient['email']); ?> • <?php echo htmlspecialchars($recipient['position']); ?></p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                            <div id="noRecipientsFound" class="hidden p-4 text-center text-sm text-gray-500">
                                No recipients found matching your search.
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Template Selection -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Load Template (Optional)</label>
                    <select id="template_select" onchange="loadTemplate(this.value)" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all bg-white">
                        <option value="">-- Select a Template --</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?php echo $template['id']; ?>">
                                <?php echo htmlspecialchars($template['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Subject -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                    <input type="text" name="subject" required placeholder="Job Application: {position}" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                    <p class="text-xs text-gray-400 mt-1">Available variables: {company_name}, {position}, {email}</p>
                </div>
                
                <!-- Body (Rich Text Editor) -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email Body</label>
                    <input type="hidden" name="body" id="bodyInput">
                    
                    <div class="border border-gray-300 rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 transition-all">
                        <!-- Toolbar -->
                        <div class="bg-gray-50 border-b border-gray-200 p-2 flex flex-wrap gap-1">
                            <button type="button" onclick="formatDoc('bold')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Bold">
                                <i class="fa-solid fa-bold"></i>
                            </button>
                            <button type="button" onclick="formatDoc('italic')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Italic">
                                <i class="fa-solid fa-italic"></i>
                            </button>
                            <button type="button" onclick="formatDoc('underline')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Underline">
                                <i class="fa-solid fa-underline"></i>
                            </button>
                            <div class="w-px h-6 bg-gray-300 mx-1"></div>
                            <button type="button" onclick="formatDoc('justifyLeft')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Align Left">
                                <i class="fa-solid fa-align-left"></i>
                            </button>
                            <button type="button" onclick="formatDoc('justifyCenter')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Align Center">
                                <i class="fa-solid fa-align-center"></i>
                            </button>
                            <button type="button" onclick="formatDoc('justifyRight')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Align Right">
                                <i class="fa-solid fa-align-right"></i>
                            </button>
                            <div class="w-px h-6 bg-gray-300 mx-1"></div>
                            <button type="button" onclick="formatDoc('insertUnorderedList')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Bullet List">
                                <i class="fa-solid fa-list-ul"></i>
                            </button>
                            <button type="button" onclick="formatDoc('insertOrderedList')" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Numbered List">
                                <i class="fa-solid fa-list-ol"></i>
                            </button>
                            <div class="w-px h-6 bg-gray-300 mx-1"></div>
                            <button type="button" onclick="addLink()" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Insert Link">
                                <i class="fa-solid fa-link"></i>
                            </button>
                            <button type="button" onclick="addImage()" class="p-1.5 text-gray-600 hover:bg-gray-200 rounded" title="Insert Image">
                                <i class="fa-regular fa-image"></i>
                            </button>
                            <div class="flex items-center ml-1">
                                <input type="color" onchange="formatDoc('foreColor', this.value)" class="w-8 h-8 p-0 border-0 rounded cursor-pointer" title="Text Color">
                            </div>
                            <button type="button" onclick="formatDoc('removeFormat')" class="p-1.5 text-red-500 hover:bg-red-50 rounded ml-auto" title="Clear Formatting">
                                <i class="fa-solid fa-eraser"></i>
                            </button>
                        </div>
                        
                        <!-- Editor Area -->
                        <div id="editor" contenteditable="true" class="p-4 min-h-[300px] outline-none prose max-w-none"></div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1">HTML is supported. Available variables: {company_name}, {position}, {email}</p>
                </div>
                
                <!-- Attachments -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Attachments</label>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-blue-500 transition-colors cursor-pointer bg-gray-50" onclick="document.getElementById('attachments').click()">
                        <i class="fa-solid fa-paperclip text-2xl text-gray-400 mb-2"></i>
                        <p class="text-sm text-gray-500">Click to select files</p>
                        <p class="text-xs text-gray-400 mt-1">Supported: PDF, DOC, DOCX, JPG, PNG</p>
                    </div>
                    <input type="file" name="attachments[]" id="attachments" multiple class="hidden" onchange="updateFileCount(this)">
                    <p id="fileCount" class="text-sm text-gray-600 mt-2 hidden"></p>
                </div>

                <!-- Actions -->
                <div class="pt-4 border-t border-gray-100 flex justify-between items-center">
                    <button type="button" onclick="document.getElementById('testModal').classList.remove('hidden')" class="text-gray-600 hover:text-gray-800 font-medium px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors">
                        <i class="fa-solid fa-vial mr-2"></i> Send Test Email
                    </button>
                    
                    <button type="button" onclick="startBatchSend()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> <span id="sendButtonText">Send Emails</span>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Sidebar Info -->
        <div class="space-y-6">
            <div class="bg-blue-50 rounded-xl p-6 border border-blue-100">
                <h4 class="font-semibold text-blue-800 mb-2"><i class="fa-solid fa-lightbulb mr-2"></i> Tips</h4>
                <ul class="text-sm text-blue-700 space-y-2 list-disc list-inside">
                    <li>Use variables like <strong>{company_name}</strong> to personalize your emails.</li>
                    <li>Upload your resume and cover letter as attachments.</li>
                    <li>Always send a test email to yourself first to check formatting.</li>
                    <li>Emails are sent individually to each recipient.</li>
                </ul>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                <h4 class="font-semibold text-gray-800 mb-4">Variable Reference</h4>
                <div class="space-y-3">
                    <div class="flex items-center justify-between text-sm">
                        <code class="bg-gray-100 px-2 py-1 rounded text-gray-600">{company_name}</code>
                        <span class="text-gray-500">Recipient's Company</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <code class="bg-gray-100 px-2 py-1 rounded text-gray-600">{position}</code>
                        <span class="text-gray-500">Target Position</span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <code class="bg-gray-100 px-2 py-1 rounded text-gray-600">{email}</code>
                        <span class="text-gray-500">Recipient's Email</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Progress Modal -->
<div id="progressModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm" data-backdrop="static">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg m-4 overflow-hidden">
        <div class="p-6 border-b border-gray-100">
            <h3 class="text-xl font-bold text-gray-800">Sending Emails</h3>
            <p class="text-sm text-gray-500 mt-1" id="progressStatus">Initializing...</p>
        </div>
        
        <div class="p-6 space-y-4">
            <!-- Progress Bar -->
            <div class="w-full bg-gray-100 rounded-full h-4 overflow-hidden">
                <div id="progressBar" class="bg-blue-500 h-4 rounded-full transition-all duration-300" style="width: 0%"></div>
            </div>
            
            <div class="flex justify-between text-sm text-gray-600">
                <span id="progressCount">0 / 0</span>
                <span id="progressPercent">0%</span>
            </div>
            
            <!-- Log Area -->
            <div class="bg-gray-50 rounded-lg p-3 h-48 overflow-y-auto font-mono text-xs text-gray-600 border border-gray-200" id="progressLog">
                <!-- Logs will appear here -->
            </div>
            
            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 mt-4 hidden" id="completionStats">
                <div class="bg-green-50 p-3 rounded-lg border border-green-100 text-center">
                    <span class="block text-xl font-bold text-green-600" id="successCount">0</span>
                    <span class="text-xs text-green-700">Successful</span>
                </div>
                <div class="bg-red-50 p-3 rounded-lg border border-red-100 text-center">
                    <span class="block text-xl font-bold text-red-600" id="failCount">0</span>
                    <span class="text-xs text-red-700">Failed</span>
                </div>
            </div>

            <div class="pt-2 flex justify-end">
                <button type="button" id="closeProgressBtn" onclick="location.reload()" class="bg-gray-800 hover:bg-gray-900 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium hidden">
                    Close & Refresh
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Test Email Modal -->
<div id="testModal" class="fixed inset-0 bg-gray-900/50 hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md m-4">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="text-xl font-bold text-gray-800">Send Test Email</h3>
            <button onclick="document.getElementById('testModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="fa-solid fa-times text-xl"></i>
            </button>
        </div>
        
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-4">Enter an email address to receive a test copy of your email.</p>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Test Email Address</label>
                    <input type="email" id="test_email_input" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
                </div>
                
                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" onclick="document.getElementById('testModal').classList.add('hidden')" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</button>
                    <button type="button" onclick="submitTestEmail()" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">Send Test</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function toggleRecipientSelection() {
    const audience = document.getElementById('target_audience').value;
    const container = document.getElementById('specific_recipients_container');
    const btnText = document.getElementById('sendButtonText');
    
    if (audience === 'specific') {
        container.classList.remove('hidden');
        btnText.textContent = 'Send to Selected';
    } else if (audience === 'all') {
        container.classList.add('hidden');
        btnText.textContent = 'Send to All';
    } else if (audience === 'new') {
        container.classList.add('hidden');
        btnText.textContent = 'Send to New Candidates';
    } else if (audience === 'followup') {
        container.classList.add('hidden');
        btnText.textContent = 'Send Follow-ups';
    }
}

async function startBatchSend() {
    // 1. Validation and Confirmation
    const audience = document.getElementById('target_audience').value;
    const smtpId = document.querySelector('select[name="smtp_config_id"]').value;
    const subject = document.querySelector('input[name="subject"]').value;
    // Sync editor to body input first
    document.getElementById('bodyInput').value = document.getElementById('editor').innerHTML;
    const body = document.getElementById('bodyInput').value;
    const attachments = document.getElementById('attachments').files;

    if (!smtpId) return alert('Please select an SMTP server.');
    if (!subject) return alert('Please enter a subject.');
    if (!body) return alert('Please enter email body.');
    
    // 2. Identify Recipients
    let recipientIds = [];
    
    if (audience === 'specific') {
        const checkboxes = document.querySelectorAll('input[name="recipient_ids[]"]:checked');
        checkboxes.forEach(cb => recipientIds.push(cb.value));
        if (recipientIds.length === 0) return alert('Please select at least one recipient.');
    } else {
        // Fetch IDs via AJAX for "All", "New", "Follow-up"
        try {
            const response = await fetch(`?action=get_recipients&target_audience=${audience}`);
            const data = await response.json();
            if (data.success) {
                recipientIds = data.ids;
            } else {
                return alert('Failed to fetch recipient groups: ' + (data.error || 'Unknown error'));
            }
        } catch (e) {
            return alert('Error fetching recipients: ' + e.message);
        }
    }
    
    if (recipientIds.length === 0) return alert('No recipients found for the selected group.');
    
    if (!confirm(`Are you sure you want to send this email to ${recipientIds.length} recipients?`)) return;

    // 3. Setup UI
    const modal = document.getElementById('progressModal');
    const progressBar = document.getElementById('progressBar');
    const progressStatus = document.getElementById('progressStatus');
    const progressCount = document.getElementById('progressCount');
    const progressPercent = document.getElementById('progressPercent');
    const log = document.getElementById('progressLog');
    
    modal.classList.remove('hidden');
    
    let processed = 0;
    let success = 0;
    let failed = 0;
    const total = recipientIds.length;

    // Tunables — chunked + parallel keeps wall-clock low without blowing past
    // PHP max_execution_time or hammering one SMTP server with too many sockets.
    const CHUNK_SIZE = 10;
    const CONCURRENCY = 3;

    const appendLog = (cls, text) => {
        const line = document.createElement('div');
        line.className = 'mb-1 ' + cls;
        line.textContent = text;
        log.appendChild(line);
        log.scrollTop = log.scrollHeight;
    };

    const refreshProgress = () => {
        const pct = total === 0 ? 0 : Math.round((processed / total) * 100);
        progressBar.style.width = pct + '%';
        progressCount.textContent = `${processed} / ${total}`;
        progressPercent.textContent = pct + '%';
        progressStatus.textContent = `Sending... ${processed} / ${total}`;
    };

    // 4. Upload attachments ONCE (instead of re-uploading per recipient)
    let attachmentRefs = [];
    if (attachments.length > 0) {
        progressStatus.textContent = 'Uploading attachments...';
        const upFd = new FormData();
        for (let j = 0; j < attachments.length; j++) {
            upFd.append('attachments[]', attachments[j]);
        }
        try {
            const upRes = await fetch('../api/upload_attachments_api.php', { method: 'POST', body: upFd });
            const upData = await upRes.json();
            if (!upData.success) {
                appendLog('text-red-600', '[ERR] Attachment upload failed: ' + (upData.message || 'unknown'));
                progressStatus.textContent = 'Attachment upload failed';
                document.getElementById('closeProgressBtn').classList.remove('hidden');
                return;
            }
            attachmentRefs = upData.attachments || [];
            appendLog('text-gray-600', `[INFO] Uploaded ${attachmentRefs.length} attachment(s) once for the whole batch.`);
        } catch (err) {
            appendLog('text-red-600', '[ERR] Upload network error: ' + err.message);
            progressStatus.textContent = 'Attachment upload failed';
            document.getElementById('closeProgressBtn').classList.remove('hidden');
            return;
        }
    }

    // 5. Build chunks and run in parallel via a small promise pool
    const chunks = [];
    for (let i = 0; i < recipientIds.length; i += CHUNK_SIZE) {
        chunks.push(recipientIds.slice(i, i + CHUNK_SIZE));
    }
    let nextChunk = 0;

    async function worker() {
        while (true) {
            const idx = nextChunk++;
            if (idx >= chunks.length) return;
            const chunk = chunks[idx];

            const fd = new FormData();
            chunk.forEach(id => fd.append('recipient_ids[]', id));
            fd.append('smtp_config_id', smtpId);
            fd.append('subject', subject);
            fd.append('body', body);
            attachmentRefs.forEach(a => {
                fd.append('attachment_tokens[]', a.token);
                fd.append('attachment_names[]', a.name);
            });

            try {
                const res = await fetch('../api/send_batch_api.php', { method: 'POST', body: fd });
                let data;
                try {
                    data = await res.json();
                } catch (parseErr) {
                    data = { success: false, message: 'Invalid response (Status: ' + res.status + ')' };
                }

                if (data.success && Array.isArray(data.results)) {
                    data.results.forEach(r => {
                        appendLog(r.success ? 'text-green-600' : 'text-red-600',
                                  (r.success ? '[OK] ' : '[ERR] ') + `ID: ${r.recipient_id} - ` + (r.message || ''));
                        if (r.success) success++; else failed++;
                        processed++;
                    });
                } else {
                    chunk.forEach(id => {
                        appendLog('text-red-600', `[ERR] ID: ${id} - Batch error: ` + (data.message || 'unknown'));
                        failed++; processed++;
                    });
                }
            } catch (err) {
                chunk.forEach(id => {
                    appendLog('text-red-600', `[ERR] ID: ${id} - Network error: ${err.message}`);
                    failed++; processed++;
                });
            }
            refreshProgress();
        }
    }

    refreshProgress();
    const workers = [];
    for (let w = 0; w < Math.min(CONCURRENCY, chunks.length); w++) {
        workers.push(worker());
    }
    await Promise.all(workers);

    // 6. Done
    progressStatus.textContent = 'Completed!';
    document.getElementById('completionStats').classList.remove('hidden');
    document.getElementById('successCount').textContent = success;
    document.getElementById('failCount').textContent = failed;
    document.getElementById('closeProgressBtn').classList.remove('hidden');
}

function selectAllRecipients(select) {
    const checkboxes = document.querySelectorAll('.recipient-item:not(.hidden) input[name="recipient_ids[]"]');
    checkboxes.forEach(cb => cb.checked = select);
    updateSelectionStats();
}

function filterRecipients() {
    const input = document.getElementById('recipientSearch');
    const filter = input.value.toLowerCase();
    const list = document.getElementById('recipientList');
    const items = list.getElementsByClassName('recipient-item');
    const noResult = document.getElementById('noRecipientsFound');
    let hasVisible = false;

    for (let i = 0; i < items.length; i++) {
        const item = items[i];
        // We look for text within search-target classes (company, email, position)
        // Actually, just checking the entire item's text content is easier and covers everything.
        const text = item.textContent || item.innerText;
        
        if (text.toLowerCase().indexOf(filter) > -1) {
            item.classList.remove('hidden');
            hasVisible = true;
        } else {
            item.classList.add('hidden');
        }
    }
    
    // Show/hide no result message
    if (!hasVisible && items.length > 0) {
        noResult.classList.remove('hidden');
    } else {
        noResult.classList.add('hidden');
    }
}

function selectNewRecipients(limit) {
    // First deselect all visible
    selectAllRecipients(false);
    
    // Find all visible checkboxes that are marked as new
    const checkboxes = document.querySelectorAll('.recipient-item:not(.hidden) input[data-is-new="1"]');
    
    let count = 0;
    for (let i = 0; i < checkboxes.length; i++) {
        if (count >= limit) break;
        checkboxes[i].checked = true;
        count++;
    }
    updateSelectionStats();
}

function updateSelectionStats() {
    const checkboxes = document.querySelectorAll('input[name="recipient_ids[]"]:checked');
    let selected = 0;
    let isNew = 0;
    let followup = 0;
    
    checkboxes.forEach(cb => {
        selected++;
        if (cb.getAttribute('data-is-new') === '1') {
            isNew++;
        } else {
            followup++;
        }
    });
    
    document.getElementById('countSelected').textContent = selected;
    document.getElementById('countNew').textContent = isNew;
    document.getElementById('countFollowup').textContent = followup;
}

// Add event listeners for checkboxes
document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'recipient_ids[]') {
        updateSelectionStats();
    }
});

function formatDoc(cmd, value = null) {
    if (value) {
        document.execCommand(cmd, false, value);
    } else {
        document.execCommand(cmd);
    }
    document.getElementById('editor').focus();
}

function addLink() {
    const url = prompt('Enter the URL:');
    if (url) {
        formatDoc('createLink', url);
    }
}

function addImage() {
    const url = prompt('Enter the Image URL:');
    if (url) {
        formatDoc('insertImage', url);
    }
}

// Sync editor content to hidden input on form submit
document.getElementById('sendForm').addEventListener('submit', function() {
    document.getElementById('bodyInput').value = document.getElementById('editor').innerHTML;
});

function updateFileCount(input) {
    const count = input.files.length;
    const display = document.getElementById('fileCount');
    if (count > 0) {
        display.textContent = count + ' file(s) selected';
        display.classList.remove('hidden');
    } else {
        display.classList.add('hidden');
    }
}

function submitTestEmail() {
    const email = document.getElementById('test_email_input').value;
    if (!email) {
        alert('Please enter an email address');
        return;
    }
    
    // Sync content first
    document.getElementById('bodyInput').value = document.getElementById('editor').innerHTML;
    
    const form = document.getElementById('sendForm');
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'test_email';
    input.value = email;
    form.appendChild(input);
    
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'test_send';
    form.appendChild(actionInput);
    
    form.submit();
}

// Initialize editor with default content if needed (e.g. after failed submit)
window.onload = function() {
    const existingBody = `<?php echo isset($_POST['body']) ? addslashes($_POST['body']) : ''; ?>`;
    if (existingBody) {
        document.getElementById('editor').innerHTML = existingBody;
    }
};

// Template Loading
const templates = <?php echo json_encode($templates); ?>;

function loadTemplate(templateId) {
    if (!templateId) return;
    
    const template = templates.find(t => t.id == templateId);
    if (template) {
        if (confirm('This will overwrite the current subject and body. Continue?')) {
            document.querySelector('input[name="subject"]').value = template.subject;
            document.getElementById('editor').innerHTML = template.body;
        } else {
            document.getElementById('template_select').value = "";
        }
    }
}
</script>

<?php
$content = ob_get_clean();
renderLayout($content, 'Send Email');
?>
