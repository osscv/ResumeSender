<?php
require_once __DIR__ . '/config.php';

function renderLayout($content, $title = 'Dashboard') {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
    if ($currentPage == 'index') $currentPage = 'home';
    
    // Define menu items
    $menuItems = [
        'home' => ['icon' => 'fa-home', 'label' => 'Home', 'link' => BASE_URL . '/pages/home.php'],
        'send' => ['icon' => 'fa-paper-plane', 'label' => 'Send', 'link' => BASE_URL . '/pages/send.php'],
        'recipients' => ['icon' => 'fa-users', 'label' => 'Recipients', 'link' => BASE_URL . '/pages/recipients.php'],
        'settings' => ['icon' => 'fa-cog', 'label' => 'Settings', 'link' => BASE_URL . '/pages/settings.php'],
    ];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $title; ?> - <?php echo APP_NAME; ?></title>
    
    <!-- Tailwind CSS (Static v2 for reliability) -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        /* Custom scrollbar for sidebar if needed */
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 antialiased h-screen flex overflow-hidden">

    <!-- Sidebar -->
    <aside class="w-64 bg-white border-r border-gray-200 flex flex-col h-full shadow-sm z-10 transition-all duration-300">
        <div class="p-6 border-b border-gray-100 flex items-center gap-3">
            <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center text-white font-bold">
                <i class="fa-solid fa-envelope"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-800 tracking-tight">ResumeSender</h1>
        </div>
        
        <nav class="flex-1 overflow-y-auto py-6 px-3 space-y-1">
            <?php foreach ($menuItems as $key => $item): 
                $isActive = ($currentPage == $key);
                $activeClass = $isActive ? 'bg-gray-100 text-blue-600 font-semibold' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700';
            ?>
            <a href="<?php echo $item['link']; ?>" class="flex items-center gap-3 px-4 py-3 rounded-lg transition-colors duration-200 <?php echo $activeClass; ?>">
                <i class="fa-solid <?php echo $item['icon']; ?> w-5 text-center <?php echo $isActive ? 'text-blue-500' : 'text-gray-400'; ?>"></i>
                <span><?php echo $item['label']; ?></span>
            </a>
            <?php endforeach; ?>
        </nav>
        
        <div class="p-4 border-t border-gray-100">
            <div class="flex items-center gap-3 px-4 py-2">
                <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-500">
                    <i class="fa-solid fa-user"></i>
                </div>
                <div class="text-sm">
                    <p class="font-medium text-gray-700">User</p>
                    <p class="text-xs text-gray-400">Admin</p>
                </div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden relative">
        <!-- Top Header (Optional, for mobile toggle or breadcrumbs) -->
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm">
            <h2 class="text-lg font-semibold text-gray-800"><?php echo $title; ?></h2>
            <div class="flex items-center gap-4">
                <button class="p-2 text-gray-400 hover:text-gray-600 transition-colors">
                    <i class="fa-regular fa-bell"></i>
                </button>
            </div>
        </header>

        <!-- Scrollable Content Area -->
        <div class="flex-1 overflow-y-auto p-8 bg-gray-50">
            <div class="max-w-7xl mx-auto">
                <?php echo $content; ?>
            </div>
        </div>
    </main>

</body>
</html>
<?php
}
?>
