<?php
session_start();
require 'config.php';

// Function to show time ago (FIXED)
function time_elapsed_string($datetime, $full = false) {
    if (empty($datetime)) {
        return 'baru saja';
    }
    
    $now = new DateTime;
    try {
        $ago = new DateTime($datetime);
    } catch (Exception $e) {
        return 'baru saja';
    }
    
    $diff = $now->diff($ago);

    $string = [
        'y' => 'tahun',
        'm' => 'bulan',
        'w' => 'minggu',
        'd' => 'hari',
        'h' => 'jam',
        'i' => 'menit',
        's' => 'detik',
    ];

    $values = [
        'y' => $diff->y,
        'm' => $diff->m,
        'w' => floor($diff->d / 7),
        'd' => $diff->d % 7,
        'h' => $diff->h,
        'i' => $diff->i,
        's' => $diff->s,
    ];

    $result = [];
    foreach ($string as $k => $v) {
        if ($values[$k] > 0) {
            $result[] = $values[$k] . ' ' . $v;
        }
    }

    if (!$full && !empty($result)) {
        $result = array_slice($result, 0, 1);
    }
    
    return !empty($result) ? implode(', ', $result) . ' lalu' : 'baru saja';
}

// Mapping kategori ke icon (FIXED)
function get_category_icon($category_name) {
    if (empty($category_name)) {
        return 'fa-tag';
    }
    
    $icon_map = [
        'mobil' => 'fa-car',
        'motor' => 'fa-motorcycle',
        'properti' => 'fa-home',
        'handphone' => 'fa-mobile-alt',
        'tablet' => 'fa-tablet-alt',
        'elektronik' => 'fa-plug',
        'fashion' => 'fa-tshirt',
        'hobi' => 'fa-gamepad',
        'olahraga' => 'fa-dumbbell',
        'rumah tangga' => 'fa-couch',
        'jasa' => 'fa-handshake',
        'lowongan kerja' => 'fa-briefcase',
        'hewan' => 'fa-paw',
        'makanan' => 'fa-utensils',
    ];
    
    $lower_name = strtolower(trim($category_name));
    foreach ($icon_map as $key => $icon) {
        if (strpos($lower_name, $key) !== false) {
            return $icon;
        }
    }
    
    return 'fa-tag';
}

// Initialize variables
$ads = [];
$categories = [];
$locations = [];
$category_name = '';
$error = '';
$info = [];

try {
    // Get unique locations with LIMIT for performance
    $loc_stmt = $pdo->prepare("SELECT DISTINCT location FROM ads WHERE location IS NOT NULL AND location != '' AND status = 'active' ORDER BY location ASC LIMIT 50");
    $loc_stmt->execute();
    $locations = $loc_stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    // Get all active categories with ad counts (FIXED: Added table alias consistency)
    $cat_stmt = $pdo->prepare("
        SELECT c.*, COUNT(a.id) as ad_count 
        FROM categories c 
        LEFT JOIN ads a ON c.id = a.category_id AND a.status = 'active'
        WHERE c.status = 1
        GROUP BY c.id 
        ORDER BY c.name ASC
    ");
    $cat_stmt->execute();
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get category name if filtered (FIXED: Added validation)
    if (isset($_GET['category_id']) && !empty($_GET['category_id'])) {
        $category_id = intval($_GET['category_id']);
        if ($category_id > 0) {
            $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ? AND status = 1");
            $cat_stmt->execute([$category_id]);
            $category = $cat_stmt->fetch();
            if ($category) {
                $category_name = htmlspecialchars($category['name']);
            }
        }
    }

    // Build search query with validation (FIXED)
    $where = ["ads.status = 'active'"];
    $params = [];

    if (isset($_GET['title']) && !empty(trim($_GET['title']))) {
        $where[] = "ads.title LIKE ?";
        $params[] = '%' . trim($_GET['title']) . '%';
    }
    
    if (isset($_GET['location']) && !empty(trim($_GET['location']))) {
        $where[] = "ads.location LIKE ?";
        $params[] = '%' . trim($_GET['location']) . '%';
    }
    
    if (isset($_GET['category_id']) && !empty($_GET['category_id'])) {
        $category_id = intval($_GET['category_id']);
        if ($category_id > 0) {
            $where[] = "ads.category_id = ?";
            $params[] = $category_id;
        }
    }

    // Base query with LIMIT (FIXED: Added proper table aliases)
    $sql = "SELECT ads.*, 
                   categories.name AS category_name, 
                   (SELECT image_path FROM ad_images WHERE ad_id = ads.id AND image_path IS NOT NULL AND image_path != '' ORDER BY id ASC LIMIT 1) AS image_path
            FROM ads 
            INNER JOIN categories ON ads.category_id = categories.id AND categories.status = 1
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ads.created_at DESC 
            LIMIT 12";

    // Execute query
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare filter info (FIXED: Added proper escaping)
    if (isset($_GET['title']) && !empty(trim($_GET['title']))) {
        $info[] = htmlspecialchars(trim($_GET['title']));
    }
    if (isset($_GET['location']) && !empty(trim($_GET['location']))) {
        $info[] = htmlspecialchars(trim($_GET['location']));
    }
    if (!empty($category_name)) {
        $info[] = htmlspecialchars($category_name);
    }
    
} catch(PDOException $e) {
    $error = "Terjadi kesalahan dalam memuat data.";
    error_log("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KF-OLX - Marketplace</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* ... (CSS styles remain the same, no changes needed) ... */
    </style>
</head>
<body class="text-gray-900">
    <!-- Background Elements -->
    <div class="fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute w-[500px] h-[500px] -top-64 -left-64 bg-gradient-to-br from-gray-100 to-transparent rounded-full animate-float opacity-10"></div>
        <div class="absolute w-[400px] h-[400px] -bottom-40 -right-40 bg-gradient-to-tr from-gray-100 to-transparent rounded-full animate-float opacity-10 animation-delay-2000"></div>
    </div>

    <!-- Navigation -->
    <nav class="sticky top-0 z-50 glass border-b border-gray-200 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="index.php" class="flex items-center space-x-3 group">
                    <div class="w-8 h-8 bg-black rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-store text-white text-sm"></i>
                    </div>
                    <span class="text-xl font-bold text-black">KF-OLX</span>
                </a>

                <!-- Desktop Search -->
                <div class="hidden lg:flex flex-1 max-w-2xl mx-8">
                    <form method="GET" action="index.php" class="flex w-full">
                        <div class="relative flex-1">
                            <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                <i class="fas fa-search"></i>
                            </div>
                            <input type="text" 
                                   name="title" 
                                   class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-l-lg focus:outline-none focus:border-gray-400 focus:bg-white transition-colors"
                                   placeholder="Cari barang..."
                                   value="<?= isset($_GET['title']) ? htmlspecialchars($_GET['title']) : '' ?>">
                        </div>
                        <div class="relative">
                            <select name="location" 
                                    class="h-full px-4 bg-gray-50 border-y border-gray-200 focus:outline-none focus:border-gray-400 focus:bg-white transition-colors">
                                <option value="">Semua Lokasi</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= htmlspecialchars($loc) ?>" 
                                        <?= (isset($_GET['location']) && $_GET['location'] == $loc) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="category_id" value="<?= isset($_GET['category_id']) ? intval($_GET['category_id']) : '' ?>">
                        <button type="submit" 
                                class="bg-black text-white px-6 py-3 rounded-r-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>

                <!-- Right Menu -->
                <div class="flex items-center space-x-4">
                    <!-- Mobile Search Toggle -->
                    <button id="mobileSearchToggle" class="lg:hidden p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <i class="fas fa-search text-gray-600"></i>
                    </button>

                    <?php if (isset($_SESSION['user_id'])): ?>
                        <!-- Sell Button -->
                        <a href="post-ad.php" 
                           class="hidden md:inline-flex items-center space-x-2 bg-black text-white px-4 py-2.5 rounded-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                            <i class="fas fa-plus"></i>
                            <span>Jual</span>
                        </a>

                        <!-- User Menu -->
                        <div class="relative group">
                            <button class="flex items-center space-x-2 p-1 hover:bg-gray-100 rounded-lg transition-colors">
                                <div class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-white text-sm"></i>
                                </div>
                                <span class="hidden lg:inline text-sm font-medium"><?= isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User' ?></span>
                                <i class="fas fa-chevron-down text-xs text-gray-500"></i>
                            </button>
                            
                            <div class="absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-xl border border-gray-200 invisible opacity-0 group-hover:visible group-hover:opacity-100 transition-all duration-300 z-50">
                                <div class="py-2">
                                    <div class="px-4 py-3 border-b border-gray-100">
                                        <p class="text-sm font-medium"><?= isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'User' ?></p>
                                        <?php if (isset($_SESSION['user_email'])): ?>
                                            <p class="text-xs text-gray-500"><?= htmlspecialchars($_SESSION['user_email']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <a href="profile.php" class="flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-user-circle mr-3 text-gray-500"></i>
                                        <span>Profil</span>
                                    </a>
                                    <a href="my_ads.php" class="flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-list mr-3 text-gray-500"></i>
                                        <span>Iklan Saya</span>
                                    </a>
                                    <a href="favorites.php" class="flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-heart mr-3 text-gray-500"></i>
                                        <span>Favorit</span>
                                    </a>
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="logout.php" class="flex items-center px-4 py-3 text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-sign-out-alt mr-3 text-gray-500"></i>
                                        <span>Keluar</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                    <?php else: ?>
                        <!-- Auth Buttons -->
                        <div class="hidden md:flex items-center space-x-3">
                            <a href="login.php" class="text-gray-700 hover:text-black px-4 py-2.5 rounded-lg hover:bg-gray-100 transition-colors">
                                Masuk
                            </a>
                            <a href="register.php" class="bg-black text-white px-4 py-2.5 rounded-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                                Daftar
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mobile Search -->
            <div id="mobileSearch" class="lg:hidden hidden py-4 animate-slide-up">
                <form method="GET" action="index.php" class="space-y-3">
                    <div class="relative">
                        <div class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                            <i class="fas fa-search"></i>
                        </div>
                        <input type="text" 
                               name="title" 
                               id="mobileSearchInput"
                               class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:border-gray-400 focus:bg-white"
                               placeholder="Cari barang..."
                               value="<?= isset($_GET['title']) ? htmlspecialchars($_GET['title']) : '' ?>">
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <select name="location" 
                                class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-lg focus:outline-none focus:border-gray-400 focus:bg-white">
                            <option value="">Semua Lokasi</option>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc) ?>" 
                                    <?= (isset($_GET['location']) && $_GET['location'] == $loc) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($loc) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="category_id" value="<?= isset($_GET['category_id']) ? intval($_GET['category_id']) : '' ?>">
                        <button type="submit" 
                                class="bg-black text-white px-4 py-3 rounded-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                            <i class="fas fa-search mr-2"></i>
                            Cari
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <!-- Error Message -->
        <?php if (!empty($error)): ?>
            <div class="mb-4 p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Categories -->
        <div class="mb-8 animate-fade-in">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-gray-900">Kategori</h2>
                <div class="flex items-center space-x-2">
                    <button onclick="scrollCategories(-200)" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button onclick="scrollCategories(200)" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
            </div>
            <div id="categoriesScroll" class="flex space-x-3 overflow-x-auto pb-4 scroll-smooth">
                <a href="index.php" 
                   class="flex flex-col items-center min-w-[90px] p-4 bg-white border border-gray-200 rounded-xl hover:border-gray-300 card-hover <?= !isset($_GET['category_id']) ? 'bg-gray-900 text-white border-gray-900' : '' ?>">
                    <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-3 <?= !isset($_GET['category_id']) ? 'bg-white' : 'bg-gray-100' ?>">
                        <i class="fas fa-th-large <?= !isset($_GET['category_id']) ? 'text-gray-900' : 'text-gray-600' ?>"></i>
                    </div>
                    <span class="text-xs font-medium text-center">Semua</span>
                </a>
                
                <?php foreach ($categories as $cat): 
                    $category_icon = get_category_icon($cat['name']);
                    $is_active = (isset($_GET['category_id']) && intval($_GET['category_id']) == $cat['id']);
                ?>
                    <a href="index.php?category_id=<?= $cat['id'] ?>" 
                       class="flex flex-col items-center min-w-[90px] p-4 bg-white border border-gray-200 rounded-xl hover:border-gray-300 card-hover <?= $is_active ? 'bg-gray-900 text-white border-gray-900' : '' ?>"
                       title="<?= htmlspecialchars($cat['name']) ?>">
                        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-3 <?= $is_active ? 'bg-white' : 'bg-gray-100' ?>">
                            <i class="fas <?= $category_icon ?> <?= $is_active ? 'text-gray-900' : 'text-gray-600' ?>"></i>
                        </div>
                        <span class="text-xs font-medium text-center"><?= htmlspecialchars($cat['name']) ?></span>
                        <?php if (isset($cat['ad_count']) && $cat['ad_count'] > 0): ?>
                            <span class="text-xs mt-1 <?= $is_active ? 'text-white/80' : 'text-gray-500' ?>">
                                <?= intval($cat['ad_count']) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Page Header -->
        <div class="mb-8 animate-slide-up">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">
                        <?php if (!empty($category_name)): ?>
                            <?= htmlspecialchars($category_name) ?>
                        <?php elseif (!empty($info)): ?>
                            Hasil Pencarian
                        <?php else: ?>
                            Iklan Terbaru
                        <?php endif; ?>
                    </h1>
                    
                    <?php if (!empty($info)): ?>
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <span class="text-sm text-gray-600">Filter:</span>
                            <?php foreach ($info as $filter): ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-gray-100 text-gray-700">
                                    <?= $filter ?>
                                    <a href="javascript:void(0)" onclick="removeFilter('<?= urlencode($filter) ?>')" class="ml-2 text-gray-500 hover:text-gray-700">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </span>
                            <?php endforeach; ?>
                            <a href="index.php" class="text-sm text-gray-600 hover:text-black transition-colors">
                                Hapus semua
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="post-ad.php" 
                       class="mt-4 md:mt-0 inline-flex items-center space-x-2 bg-black text-white px-5 py-3 rounded-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                        <i class="fas fa-plus-circle"></i>
                        <span>Pasang Iklan</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Products Grid -->
        <?php if (empty($ads)): ?>
            <div class="bg-white rounded-2xl border border-gray-200 p-12 text-center animate-scale-in">
                <div class="w-24 h-24 mx-auto mb-6">
                    <div class="w-full h-full bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center">
                        <i class="fas fa-search text-gray-400 text-3xl"></i>
                    </div>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Tidak ada iklan ditemukan</h3>
                <p class="text-gray-600 mb-8 max-w-md mx-auto">Coba gunakan kata kunci lain atau lihat kategori yang tersedia</p>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="post-ad.php" 
                       class="inline-flex items-center space-x-2 bg-black text-white px-6 py-3 rounded-lg hover:bg-gray-800 active:scale-95 transition-all duration-300">
                        <i class="fas fa-plus-circle"></i>
                        <span>Jadi yang pertama menjual</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($ads as $index => $ad): 
                    $image_path = !empty($ad['image_path']) ? htmlspecialchars($ad['image_path']) : '';
                    $has_image = !empty($ad['image_path']) && file_exists(str_replace('http://localhost/', '', $image_path));
                    
                    // Cek apakah iklan baru (dibuat dalam 7 hari terakhir)
                    $is_new = false;
                    if (!empty($ad['created_at'])) {
                        $created_time = strtotime($ad['created_at']);
                        $seven_days_ago = strtotime('-7 days');
                        $is_new = $created_time > $seven_days_ago;
                    }
                    
                    // Format price
                    $price = '0';
                    if (isset($ad['price']) && is_numeric($ad['price'])) {
                        $price_num = floatval($ad['price']);
                        if ($price_num >= 1000000) {
                            $price = number_format($price_num / 1000000, 1, ',', '.') . ' jt';
                        } elseif ($price_num >= 1000) {
                            $price = number_format($price_num / 1000, 1, ',', '.') . ' rb';
                        } else {
                            $price = number_format($price_num, 0, ',', '.');
                        }
                    }
                ?>
                    <div class="animate-fade-in" style="animation-delay: <?= $index * 0.1 ?>s">
                        <a href="detail.php?id=<?= intval($ad['id']) ?>" class="block">
                            <div class="bg-white border border-gray-200 rounded-xl overflow-hidden card-hover h-full">
                                <!-- Product Image -->
                                <div class="relative h-56 overflow-hidden bg-gray-100">
                                    <?php if ($has_image): ?>
                                        <img src="<?= $image_path ?>" 
                                             alt="<?= htmlspecialchars($ad['title']) ?>"
                                             class="w-full h-full object-cover transition-transform duration-700 hover:scale-110"
                                             loading="lazy"
                                             onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" width=\"400\" height=\"300\" viewBox=\"0 0 400 300\"%3E%3Crect width=\"400\" height=\"300\" fill=\"%23f3f4f6\"/%3E%3Ctext x=\"50%25\" y=\"50%25\" text-anchor=\"middle\" dy=\".3em\" font-family=\"Arial\" font-size=\"24\" fill=\"%239ca3af\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center">
                                            <i class="fas fa-image text-gray-300 text-5xl"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($is_new): ?>
                                        <span class="absolute top-3 left-3 bg-black text-white text-xs px-2 py-1 rounded">
                                            BARU
                                        </span>
                                    <?php endif; ?>
                                    
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent opacity-0 hover:opacity-100 transition-opacity duration-300"></div>
                                    
                                    <div class="absolute bottom-3 right-3">
                                        <button class="w-8 h-8 bg-white/90 backdrop-blur-sm rounded-full flex items-center justify-center hover:bg-white hover:scale-110 transition-all duration-300"
                                                onclick="event.preventDefault(); addToFavorite(<?= intval($ad['id']) ?>)">
                                            <i class="far fa-heart text-gray-600"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Product Info -->
                                <div class="p-5">
                                    <div class="flex items-center justify-between mb-3">
                                        <span class="text-xl font-bold text-gray-900">
                                            Rp <?= $price ?>
                                        </span>
                                        <?php if (isset($ad['is_negotiable']) && $ad['is_negotiable']): ?>
                                            <span class="text-xs font-medium px-2 py-1 bg-gray-100 text-gray-600 rounded">
                                                Nego
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <h3 class="text-gray-900 font-medium mb-4 line-clamp-2 leading-relaxed hover:text-gray-700 transition-colors">
                                        <?= htmlspecialchars($ad['title']) ?>
                                    </h3>
                                    
                                    <div class="flex items-center justify-between text-sm text-gray-500 pt-4 border-t border-gray-100">
                                        <div class="flex items-center space-x-1">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span class="truncate max-w-[120px]">
                                                <?= !empty($ad['location']) ? htmlspecialchars($ad['location']) : '-' ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center space-x-4">
                                            <span class="flex items-center space-x-1">
                                                <i class="far fa-clock"></i>
                                                <span><?= !empty($ad['created_at']) ? time_elapsed_string($ad['created_at']) : '-' ?></span>
                                            </span>
                                            <?php if (isset($ad['views']) && $ad['views'] > 0): ?>
                                                <span class="flex items-center space-x-1">
                                                    <i class="far fa-eye"></i>
                                                    <span><?= intval($ad['views']) ?></span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Load More Button -->
            <div class="mt-12 text-center animate-fade-in">
                <button id="loadMore" 
                        class="bg-white border border-gray-300 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-50 hover:border-gray-400 active:scale-95 transition-all duration-300">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Muat Lebih Banyak
                </button>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="mt-16 border-t border-gray-200 bg-white">
        <div class="container mx-auto px-4 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div>
                    <div class="flex items-center space-x-2 mb-4">
                        <div class="w-8 h-8 bg-black rounded-lg flex items-center justify-center">
                            <i class="fas fa-store text-white text-sm"></i>
                        </div>
                        <span class="text-xl font-bold">KF-OLX</span>
                    </div>
                    <p class="text-gray-600 text-sm leading-relaxed">
                        Platform jual beli terpercaya dengan pengalaman terbaik untuk semua pengguna.
                    </p>
                </div>
                
                <div>
                    <h4 class="font-semibold text-gray-900 mb-4">Perusahaan</h4>
                    <ul class="space-y-3">
                        <li><a href="about.php" class="text-gray-600 hover:text-black transition-colors">Tentang Kami</a></li>
                        <li><a href="careers.php" class="text-gray-600 hover:text-black transition-colors">Karir</a></li>
                        <li><a href="press.php" class="text-gray-600 hover:text-black transition-colors">Pers</a></li>
                        <li><a href="contact.php" class="text-gray-600 hover:text-black transition-colors">Kontak</a></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="font-semibold text-gray-900 mb-4">Bantuan</h4>
                    <ul class="space-y-3">
                        <li><a href="help.php" class="text-gray-600 hover:text-black transition-colors">Pusat Bantuan</a></li>
                        <li><a href="safety.php" class="text-gray-600 hover:text-black transition-colors">Keamanan</a></li>
                        <li><a href="privacy.php" class="text-gray-600 hover:text-black transition-colors">Privasi</a></li>
                        <li><a href="terms.php" class="text-gray-600 hover:text-black transition-colors">Syarat & Ketentuan</a></li>
                    </ul>
                </div>
                
                <div>
                    <h4 class="font-semibold text-gray-900 mb-4">Ikuti Kami</h4>
                    <div class="flex space-x-3">
                        <a href="#" class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                            <i class="fab fa-facebook-f text-gray-600"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                            <i class="fab fa-twitter text-gray-600"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                            <i class="fab fa-instagram text-gray-600"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center hover:bg-gray-200 transition-colors">
                            <i class="fab fa-youtube text-gray-600"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <div class="border-t border-gray-200 mt-8 pt-8 text-center">
                <p class="text-gray-500 text-sm">&copy; <?= date('Y') ?> KF-OLX. Semua hak dilindungi.</p>
            </div>
        </div>
    </footer>

    <script>
        // Mobile Search Toggle
        const mobileSearchToggle = document.getElementById('mobileSearchToggle');
        const mobileSearch = document.getElementById('mobileSearch');
        
        if (mobileSearchToggle && mobileSearch) {
            mobileSearchToggle.addEventListener('click', () => {
                mobileSearch.classList.toggle('hidden');
                if (!mobileSearch.classList.contains('hidden')) {
                    setTimeout(() => {
                        const mobileSearchInput = document.getElementById('mobileSearchInput');
                        if (mobileSearchInput) {
                            mobileSearchInput.focus();
                        }
                    }, 100);
                }
            });
        }

        // Scroll categories horizontally
        function scrollCategories(amount) {
            const scrollContainer = document.getElementById('categoriesScroll');
            if (scrollContainer) {
                scrollContainer.scrollBy({
                    left: amount,
                    behavior: 'smooth'
                });
            }
        }

        // Remove filter
        function removeFilter(filterText) {
            // Remove from URL parameters
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);
            
            // Check which parameter contains this filter
            if (params.get('title') === filterText) {
                params.delete('title');
            } else if (params.get('location') === filterText) {
                params.delete('location');
            } else if (params.get('category_id')) {
                // For category, we need to compare with category name
                // This would require additional logic or just remove category_id
                params.delete('category_id');
            }
            
            // Update URL
            window.location.href = url.pathname + '?' + params.toString();
        }

        // Add to favorite
        function addToFavorite(adId) {
            const btn = event.target.closest('button');
            const icon = btn.querySelector('i');
            
            // Toggle icon
            icon.classList.toggle('far');
            icon.classList.toggle('fas');
            
            // Add animation
            btn.classList.add('animate-scale-in');
            setTimeout(() => {
                btn.classList.remove('animate-scale-in');
            }, 500);
            
            // Send AJAX request
            fetch('add_favorite.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'ad_id=' + adId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    icon.classList.add('text-red-500');
                    showNotification('Ditambahkan ke favorit', 'success');
                } else {
                    icon.classList.remove('fas', 'text-red-500');
                    icon.classList.add('far');
                    showNotification(data.message || 'Gagal menambahkan ke favorit', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                icon.classList.remove('fas', 'text-red-500');
                icon.classList.add('far');
                showNotification('Terjadi kesalahan', 'error');
            });
        }

        // Load more functionality (simulated)
        const loadMoreBtn = document.getElementById('loadMore');
        if (loadMoreBtn) {
            loadMoreBtn.addEventListener('click', function() {
                const btn = this;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Memuat...';
                btn.disabled = true;
                
                // Simulate loading
                setTimeout(() => {
                    btn.innerHTML = '<i class="fas fa-sync-alt mr-2"></i>Muat Lebih Banyak';
                    btn.disabled = false;
                    showNotification('Tidak ada iklan lainnya', 'info');
                }, 1500);
            });
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg animate-slide-up ${
                type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' :
                type === 'error' ? 'bg-red-50 text-red-800 border border-red-200' :
                'bg-blue-50 text-blue-800 border border-blue-200'
            }`;
            notification.innerHTML = `
                <div class="flex items-center space-x-3">
                    <i class="fas ${
                        type === 'success' ? 'fa-check-circle' :
                        type === 'error' ? 'fa-exclamation-circle' :
                        'fa-info-circle'
                    }"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('opacity-0', 'transition-opacity', 'duration-300');
                setTimeout(() => notification.remove(), 300);
            }, 3000);
        }

        // Smooth scroll to top
        window.addEventListener('scroll', () => {
            const scrollBtn = document.getElementById('scrollTop');
            if (!scrollBtn && window.scrollY > 500) {
                const btn = document.createElement('button');
                btn.id = 'scrollTop';
                btn.className = 'fixed bottom-8 right-8 w-12 h-12 bg-black text-white rounded-full shadow-lg hover:bg-gray-800 active:scale-95 transition-all duration-300 z-40 flex items-center justify-center';
                btn.innerHTML = '<i class="fas fa-chevron-up"></i>';
                btn.onclick = () => window.scrollTo({ top: 0, behavior: 'smooth' });
                document.body.appendChild(btn);
            } else if (scrollBtn && window.scrollY <= 500) {
                scrollBtn.remove();
            }
        });

        // Image error handling
        document.addEventListener('DOMContentLoaded', function() {
            const images = document.querySelectorAll('img');
            images.forEach(img => {
                img.addEventListener('error', function() {
                    this.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"%3E%3Crect width="400" height="300" fill="%23f3f4f6"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" font-family="Arial" font-size="24" fill="%239ca3af"%3ENo Image%3C/text%3E%3C/svg%3E';
                    this.alt = 'Gambar tidak tersedia';
                });
            });
        });

        // Add ripple effect to buttons
        document.addEventListener('click', function(e) {
            const button = e.target.closest('button, a[href]');
            if (button && !button.closest('#mobileSearch')) {
                const rect = button.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const ripple = document.createElement('span');
                ripple.style.cssText = `
                    position: absolute;
                    border-radius: 50%;
                    background: rgba(0, 0, 0, 0.1);
                    transform: scale(0);
                    animation: ripple 0.6s linear;
                    pointer-events: none;
                    width: 100px;
                    height: 100px;
                    top: ${y - 50}px;
                    left: ${x - 50}px;
                `;
                
                button.style.position = 'relative';
                button.style.overflow = 'hidden';
                button.appendChild(ripple);
                
                setTimeout(() => ripple.remove(), 600);
            }
        });

        // Add CSS for ripple animation
        if (!document.querySelector('#ripple-style')) {
            const style = document.createElement('style');
            style.id = 'ripple-style';
            style.textContent = `
                @keyframes ripple {
                    to {
                        transform: scale(4);
                        opacity: 0;
                    }
                }
            `;
            document.head.appendChild(style);
        }
    </script>
</body>
</html>