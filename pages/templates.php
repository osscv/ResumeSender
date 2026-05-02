<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../layout.php';
require_once __DIR__ . '/../utils/auth.php';

requireAuth();

$pdo = getDBConnection();
$message = '';
$messageType = '';

// Handle success message from redirect
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $messageType = "success";
}

// Handle Form Submission (Delete only)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete_template') {
        try {
            $stmt = $pdo->prepare("DELETE FROM email_templates WHERE id = ? AND user_id = ?");
            $stmt->execute([$_POST['id'], getCurrentUserId()]);
            $message = "Template deleted successfully!";
            $messageType = "success";
        } catch (PDOException $e) {
            $message = "Error deleting template: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

// Fetch Templates
$stmt = $pdo->prepare("SELECT * FROM email_templates WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([getCurrentUserId()]);
$templates = $stmt->fetchAll();

ob_start();
?>

<div class="space-y-6">
    <!-- Header & Actions -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4">
        <div>
            <h3 class="text-2xl font-bold text-gray-800">Email Templates</h3>
            <p class="text-gray-500 mt-1">Manage reusable email templates for your campaigns.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/pages/template_edit.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg shadow-sm transition-colors flex items-center gap-2">
            <i class="fa-solid fa-plus"></i> Create Template
        </a>
    </div>

    <!-- Alert Message -->
    <?php if ($message): ?>
    <div class="p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Templates Grid -->
    <?php if (empty($templates)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12 text-center text-gray-500">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-file-lines text-2xl text-gray-400"></i>
            </div>
            <p class="text-lg font-medium">No templates found.</p>
            <p class="text-sm mt-1">Create your first template to get started.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($templates as $template): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start mb-4">
                    <h4 class="text-lg font-bold text-gray-800 truncate pr-4"><?php echo htmlspecialchars($template['name']); ?></h4>
                    <div class="flex gap-2">
                        <a href="<?php echo BASE_URL; ?>/pages/template_edit.php?id=<?php echo $template['id']; ?>" class="text-gray-400 hover:text-blue-500 transition-colors">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this template?');" class="inline-block">
                            <input type="hidden" name="action" value="delete_template">
                            <input type="hidden" name="id" value="<?php echo $template['id']; ?>">
                            <button type="submit" class="text-gray-400 hover:text-red-500 transition-colors">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="space-y-3 mb-4">
                    <div>
                        <span class="text-xs font-medium text-gray-500 uppercase block mb-1">Subject</span>
                        <p class="text-sm text-gray-700 truncate"><?php echo htmlspecialchars($template['subject']); ?></p>
                    </div>
                    <div>
                        <span class="text-xs font-medium text-gray-500 uppercase block mb-1">Preview</span>
                        <p class="text-sm text-gray-500 line-clamp-3"><?php echo strip_tags($template['body']); ?></p>
                    </div>
                </div>
                
                <div class="text-xs text-gray-400 pt-4 border-t border-gray-100 flex justify-between items-center">
                    <span>Created: <?php echo date('M j, Y', strtotime($template['created_at'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>



<?php
$content = ob_get_clean();
renderLayout($content, 'Email Templates');
?>
