<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/auth.php';

requireAuth();

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Determine if we're editing or creating
$templateId = $_GET['id'] ?? null;
$template = null;

if ($templateId) {
    // Fetch existing template
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ? AND user_id = ?");
    $stmt->execute([$templateId, getCurrentUserId()]);
    $template = $stmt->fetch();
    
    if (!$template) {
        header('Location: ' . BASE_URL . '/pages/templates.php');
        exit;
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($templateId) {
            // Update existing template
            $stmt = $pdo->prepare("UPDATE email_templates SET name = ?, subject = ?, body = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['name'], $_POST['subject'], $_POST['body'], $templateId, getCurrentUserId()]);
            $message = "Template updated successfully!";
        } else {
            // Create new template
            $stmt = $pdo->prepare("INSERT INTO email_templates (user_id, name, subject, body) VALUES (?, ?, ?, ?)");
            $stmt->execute([getCurrentUserId(), $_POST['name'], $_POST['subject'], $_POST['body']]);
            $message = "Template created successfully!";
        }
        $messageType = "success";
        
        // Redirect back to templates list after a short delay
        header('Location: ' . BASE_URL . '/pages/templates.php?message=' . urlencode($message));
        exit;
    } catch (PDOException $e) {
        $message = "Error saving template: " . $e->getMessage();
        $messageType = "error";
    }
}

$pageTitle = $templateId ? 'Edit Template' : 'Create Template';

ob_start();
?>

<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center gap-4">
        <a href="<?php echo BASE_URL; ?>/pages/templates.php" class="bg-white p-2 rounded-lg border border-gray-200 text-gray-600 hover:text-blue-600 hover:border-blue-300 transition-all">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <div>
            <h3 class="text-2xl font-bold text-gray-800"><?php echo $pageTitle; ?></h3>
            <p class="text-gray-500 mt-1">Create a reusable email template for your campaigns.</p>
        </div>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" id="templateForm" class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 space-y-6">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Template Name</label>
            <input type="text" name="name" id="templateName" required placeholder="e.g., Initial Outreach" value="<?php echo $template ? htmlspecialchars($template['name']) : ''; ?>" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
            <input type="text" name="subject" id="templateSubject" required placeholder="Job Application: {position}" value="<?php echo $template ? htmlspecialchars($template['subject']) : ''; ?>" class="w-full rounded-lg border-gray-300 border px-3 py-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-all">
            <p class="text-xs text-gray-400 mt-1">Available variables: {company_name}, {position}, {email}</p>
        </div>
        
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
                    <div class="flex items-center ml-1">
                        <input type="color" onchange="formatDoc('foreColor', this.value)" class="w-8 h-8 p-0 border-0 rounded cursor-pointer" title="Text Color">
                    </div>
                    <button type="button" onclick="formatDoc('removeFormat')" class="p-1.5 text-red-500 hover:bg-red-50 rounded ml-auto" title="Clear Formatting">
                        <i class="fa-solid fa-eraser"></i>
                    </button>
                </div>
                
                <!-- Editor Area -->
                <div id="editor" contenteditable="true" class="p-4 min-h-[400px] max-h-[600px] overflow-y-auto outline-none prose max-w-none"><?php echo $template ? $template['body'] : ''; ?></div>
            </div>
            <p class="text-xs text-gray-400 mt-1">HTML is supported. Available variables: {company_name}, {position}, {email}</p>
        </div>
        
        <div class="pt-4 flex justify-end gap-3 border-t border-gray-100">
            <a href="<?php echo BASE_URL; ?>/pages/templates.php" class="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors">Cancel</a>
            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-sm transition-colors font-medium">
                <?php echo $templateId ? 'Update Template' : 'Create Template'; ?>
            </button>
        </div>
    </form>
</div>

<script>
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

// Sync editor content to hidden input on form submit
document.getElementById('templateForm').addEventListener('submit', function() {
    document.getElementById('bodyInput').value = document.getElementById('editor').innerHTML;
});
</script>

<?php
$content = ob_get_clean();
renderLayout($content, $pageTitle);
?>
