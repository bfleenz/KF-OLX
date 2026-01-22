<?php
session_start();
require 'config.php';

// Function to show time ago (sama seperti di index.php)
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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    echo "<script>alert('Iklan tidak ditemukan!');window.location='index.php';</script>";
    exit;
}

try {
    // Get ad details with PDO
    $sql = "SELECT ads.*, categories.name AS category_name, users.name AS user_name, 
                   users.email, users.whatsapp, users.created_at AS member_since
            FROM ads
            JOIN categories ON ads.category_id = categories.id
            JOIN users ON ads.user_id = users.id
            WHERE ads.id = :id AND ads.status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ad) {
        echo "<script>alert('Iklan tidak ditemukan atau tidak aktif!');window.location='index.php';</script>";
        exit;
    }

    // Periksa apakah deskripsi kosong
    if (empty($ad['description']) || trim($ad['description']) == '') {
        $ad['description'] = "Penjual belum menambahkan deskripsi untuk produk ini.";
    }
    
    // Periksa kondisi produk
    if (empty($ad['condition'])) {
        $ad['condition'] = 'unknown';
    }

    // Increment view count
    $update_views = $pdo->prepare("UPDATE ads SET views = COALESCE(views, 0) + 1 WHERE id = :id");
    $update_views->bindParam(':id', $id, PDO::PARAM_INT);
    $update_views->execute();

    // Get ad images
    $img_stmt = $pdo->prepare("SELECT image_path FROM ad_images WHERE ad_id = :ad_id ORDER BY id ASC");
    $img_stmt->bindParam(':ad_id', $id, PDO::PARAM_INT);
    $img_stmt->execute();
    $image_records = $img_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process images
    $images = [];
    foreach ($image_records as $record) {
        if (!empty($record['image_path'])) {
            $images[] = $record['image_path'];
        }
    }
    
    // Tentukan gambar utama
    if (!empty($images)) {
        $main_image = $images[0];
    } else {
        $main_image = '';
    }
    
    $has_images = !empty($images);
    $total_images = count($images);
    
    // Get similar ads
    $similar_stmt = $pdo->prepare("
        SELECT ads.*, categories.name AS category_name,
               (SELECT image_path FROM ad_images WHERE ad_id = ads.id LIMIT 1) AS image_path
        FROM ads 
        JOIN categories ON ads.category_id = categories.id
        WHERE ads.category_id = :category_id 
        AND ads.id != :ad_id 
        AND ads.status = 'active'
        ORDER BY ads.created_at DESC 
        LIMIT 4
    ");
    $similar_stmt->execute([
        ':category_id' => $ad['category_id'],
        ':ad_id' => $id
    ]);
    $similar_ads = $similar_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ad['title']) ?> - KF-OLX</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: #fafafa;
            padding-top: 4rem;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes scaleIn {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.6s ease-out;
        }
        
        .animate-slide-up {
            animation: slideUp 0.8s ease-out;
        }
        
        .animate-scale-in {
            animation: scaleIn 0.5s ease-out;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Glass Effect */
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        /* Card Hover Effects */
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.08);
        }
        
        /* Line Clamp */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .line-clamp-3 {
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Image Gallery */
        .main-image-container {
            position: relative;
            width: 100%;
            height: 400px;
            overflow: hidden;
            border-radius: 12px;
            background: #f8f9fa;
        }
        
        .main-image-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            transition: transform 0.3s ease;
        }
        
        .thumbnail-container {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        
        .thumbnail {
            width: 80px;
            height: 80px;
            flex-shrink: 0;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
            background: #f8f9fa;
        }
        
        .thumbnail:hover {
            border-color: #000;
        }
        
        .thumbnail.active {
            border-color: #000;
        }
        
        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-counter {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .nav-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .nav-arrow:hover {
            background: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .nav-arrow.prev {
            left: 12px;
        }
        
        .nav-arrow.next {
            right: 12px;
        }
        
        /* Product Info */
        .product-title {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 8px;
        }
        
        .product-price {
            font-size: 32px;
            font-weight: 700;
            color: #000;
            margin: 16px 0;
        }
        
        /* Seller Info */
        .seller-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
        }
        
        /* Action Buttons */
        .btn-whatsapp {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            background: #25D366;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
        }
        
        .btn-call {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            background: #007AFF;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            margin-top: 8px;
        }
        
        .btn-call:hover {
            background: #0056CC;
            color: white;
        }
        
        /* Similar Products */
        .similar-product-card {
            display: block;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        
        .similar-product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.1);
            color: inherit;
        }
        
        .similar-product-image {
            width: 100%;
            height: 160px;
            overflow: hidden;
            background: #f8f9fa;
        }
        
        .similar-product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .similar-product-card:hover .similar-product-image img {
            transform: scale(1.05);
        }
        
        /* Empty Description */
        .empty-description {
            background: #FFF3CD;
            border-left: 4px solid #FFC107;
            padding: 16px;
            border-radius: 8px;
            color: #856404;
            margin: 16px 0;
        }
        
        /* Breadcrumb */
        .breadcrumb-custom {
            font-size: 14px;
            color: #666;
            margin-bottom: 24px;
        }
        
        .breadcrumb-custom a {
            color: #666;
            text-decoration: none;
            transition: color 0.2s ease;
        }
        
        .breadcrumb-custom a:hover {
            color: #000;
        }
        
        .breadcrumb-custom .active {
            color: #000;
            font-weight: 500;
        }
        
        /* Navigation */
        .navbar-glass {
            background: rgba(255, 255, 255, 0.98) !important;
            backdrop-filter: blur(20px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(20px) saturate(180%) !important;
            border-bottom: 1px solid rgba(0, 0, 0, 0.08) !important;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05) !important;
        }
    </style>
</head>
<body class="text-gray-900">
    <!-- Navigation -->
    <nav class="navbar-glass fixed top-0 w-full z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                 <div class="flex items-center space-x-8">
    <!-- Logo Container -->
    <a href="index.php" class="flex items-center space-x-3 group">
        <div class="relative">
            <!-- Main logo box -->
            <div class="w-9 h-9 bg-gradient-to-br from-gray-900 to-black rounded-lg 
                        flex items-center justify-center shadow-lg
                        transform group-hover:scale-110 group-hover:rotate-3 
                        transition-all duration-300 ease-out">
                <i class="fas fa-store text-white text-sm"></i>
            </div>
            <!-- Glow effect -->
            <div class="absolute -inset-0.5 bg-gradient-to-r from-blue-400 to-purple-400 
                        rounded-lg opacity-0 group-hover:opacity-30 blur 
                        transition-opacity duration-300"></div>
        </div>
        <span class="text-xl font-bold text-gray-900 tracking-tight">
            KF<span class="text-blue-600">-</span>OLX
        </span>
    </a>

    <!-- Beranda Navigation -->
    <a href="index.php" class="group">
        <div class="relative flex items-center space-x-2 px-4 py-2 
                    rounded-full transition-all duration-300
                    hover:bg-gray-50 hover:shadow-sm">
            <i class="fas fa-home text-gray-500 text-sm 
                      group-hover:text-blue-500 transition-colors duration-300"></i>
            <span class="text-sm font-semibold text-gray-700
                        group-hover:text-gray-900 transition-colors duration-300">
                Beranda
            </span>
            
            <!-- Dot indicator -->
            <div class="absolute -top-1 -right-1 w-2 h-2 bg-blue-500 
                        rounded-full opacity-0 group-hover:opacity-100 
                        transition-opacity duration-300"></div>
        </div>
    </a>
</div>

                <!-- Right Menu -->
                <div class="flex items-center space-x-4">
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
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <!-- Breadcrumb -->
        <nav class="breadcrumb-custom mb-6 animate-fade-in">
            <a href="index.php" class="hover:text-black transition-colors">Beranda</a>
            <span class="mx-2">›</span>
            <a href="index.php?category_id=<?= $ad['category_id'] ?>" class="hover:text-black transition-colors">
                <?= htmlspecialchars($ad['category_name']) ?>
            </a>
            <span class="mx-2">›</span>
            <span class="active"><?= htmlspecialchars(mb_strimwidth($ad['title'], 0, 50, '...')) ?></span>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left Column - Images -->
            <div class="lg:col-span-2 animate-fade-in">
                <!-- Image Gallery -->
                <div class="mb-8">
                    <div class="main-image-container">
                        <?php if ($has_images): ?>
                            <img src="<?= htmlspecialchars($main_image) ?>" 
                                 alt="<?= htmlspecialchars($ad['title']) ?>" 
                                 id="currentImage"
                                 class="animate-scale-in">
                            
                            <?php if ($total_images > 1): ?>
                                <div class="image-counter">1/<?= $total_images ?></div>
                                <button class="nav-arrow prev" onclick="prevImage()">
                                    <i class="fas fa-chevron-left"></i>
                                </button>
                                <button class="nav-arrow next" onclick="nextImage()">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                <i class="fas fa-image text-6xl mb-4"></i>
                                <p>Tidak ada gambar</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($has_images && $total_images > 1): ?>
                    <div class="thumbnail-container">
                        <?php foreach ($images as $index => $image): ?>
                            <div class="thumbnail <?= $index === 0 ? 'active' : '' ?>" 
                                 onclick="changeImage('<?= htmlspecialchars($image) ?>', <?= $index ?>)"
                                 data-index="<?= $index ?>">
                                <img src="<?= htmlspecialchars($image) ?>" 
                                     alt="Thumbnail <?= $index + 1 ?>"
                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" width=\"80\" height=\"80\" viewBox=\"0 0 80 80\"%3E%3Crect width=\"80\" height=\"80\" fill=\"%23f3f4f6\"/%3E%3Ctext x=\"50%25\" y=\"50%25\" text-anchor=\"middle\" dy=\".3em\" font-family=\"Arial\" font-size=\"12\" fill=\"%239ca3af\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Product Description -->
                <div class="bg-white rounded-xl p-6 shadow-sm mb-6 animate-slide-up">
                    <h2 class="text-xl font-bold mb-4">Deskripsi</h2>
                    <div class="prose max-w-none">
                        <?php if (empty(trim($ad['description'])) || $ad['description'] == "Penjual belum menambahkan deskripsi untuk produk ini."): ?>
                            <div class="empty-description">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <?= htmlspecialchars($ad['description']) ?>
                            </div>
                        <?php else: ?>
                            <div class="text-gray-700 leading-relaxed">
                                <?= nl2br(htmlspecialchars($ad['description'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Product Details -->
                <div class="bg-white rounded-xl p-6 shadow-sm animate-slide-up">
                    <h2 class="text-xl font-bold mb-4">Detail Produk</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <div class="text-sm text-gray-500 mb-1">Lokasi</div>
                            <div class="font-medium flex items-center">
                                <i class="fas fa-map-marker-alt mr-2 text-gray-400"></i>
                                <?= !empty($ad['location']) ? htmlspecialchars($ad['location']) : 'Tidak diketahui' ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500 mb-1">Dilihat</div>
                            <div class="font-medium flex items-center">
                                <i class="fas fa-eye mr-2 text-gray-400"></i>
                                <?= number_format($ad['views'] ?? 0) ?> kali
                            </div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-500 mb-1">Diposting</div>
                            <div class="font-medium flex items-center">
                                <i class="far fa-clock mr-2 text-gray-400"></i>
                                <?= time_elapsed_string($ad['created_at']) ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column - Product Info & Seller -->
            <div class="animate-fade-in">
                <!-- Product Info Card -->
                <div class="bg-white rounded-xl p-6 shadow-sm mb-6 top-24">
                    <h1 class="product-title"><?= htmlspecialchars($ad['title']) ?></h1>
                    
                    <div class="flex items-center gap-2 mb-4">
                        <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm font-medium">
                            <?= htmlspecialchars($ad['category_name']) ?>
                        </span>
                    </div>
                    
                    <div class="product-price">Rp <?= number_format($ad['price'], 0, ',', '.') ?></div>
                    
                    <div class="text-sm text-gray-500 mb-6">
                        <i class="far fa-clock mr-1"></i>
                        Diposting <?= time_elapsed_string($ad['created_at']) ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="space-y-3">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <?php if ($_SESSION['user_id'] != $ad['user_id']): ?>
                                <?php if (!empty($ad['whatsapp'])): 
                                    $phone_number = preg_replace('/[^0-9]/', '', $ad['whatsapp']);
                                    $phone_number = ltrim($phone_number, '0');
                                    if (substr($phone_number, 0, 2) !== '62') {
                                        $phone_number = '62' . $phone_number;
                                    }
                                ?>
                                    <a href="https://wa.me/<?= $phone_number ?>?text=Halo%20<?= urlencode($ad['user_name']) ?>%2C%20saya%20tertarik%20dengan%20iklan%20Anda%3A%20%22<?= urlencode($ad['title']) ?>%22%20-%20KF-OLX"
                                       target="_blank"
                                       class="btn-whatsapp">
                                        <i class="fab fa-whatsapp"></i> Chat via WhatsApp
                                    </a>
                                    
                                    <a href="tel:+<?= $phone_number ?>" 
                                       class="btn-call">
                                        <i class="fas fa-phone-alt"></i> Telepon Penjual
                                    </a>
                                <?php else: ?>
                                    <div class="text-center p-4 bg-gray-100 rounded-lg">
                                        <i class="fas fa-info-circle text-gray-400 mb-2"></i>
                                        <p class="text-sm text-gray-600">Penjual belum menambahkan kontak</p>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center p-4 bg-gray-100 rounded-lg">
                                    <i class="fas fa-user text-gray-400 mb-2"></i>
                                    <p class="text-sm text-gray-600">Ini adalah iklan Anda</p>
                                </div>
                                <a href="edit_ad.php?id=<?= $ad['id'] ?>" 
                                   class="block w-full text-center bg-black text-white py-3 rounded-lg hover:bg-gray-800 transition-colors">
                                    <i class="fas fa-edit mr-2"></i> Edit Iklan
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="login.php?redirect=detail.php?id=<?= $id ?>" 
                               class="block w-full text-center bg-gray-900 text-white py-3 rounded-lg hover:bg-black transition-colors">
                                <i class="fas fa-sign-in-alt mr-2"></i> Login untuk Hubungi Penjual
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Seller Card -->
                <div class="seller-card mb-6">
                    <div class="flex items-start space-x-3 mb-4">
                        <div class="w-12 h-12 bg-gray-800 rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-white"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg"><?= htmlspecialchars($ad['user_name']) ?></h3>
                            <p class="text-sm text-gray-500">
                                <i class="fas fa-user-clock mr-1"></i>
                                Member sejak <?= date('M Y', strtotime($ad['member_since'])) ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] != $ad['user_id']): ?>
                        <a href="profile.php?user_id=<?= $ad['user_id'] ?>" 
                           class="block w-full text-center border border-gray-300 text-gray-700 py-2.5 rounded-lg hover:bg-gray-50 transition-colors">
                            <i class="far fa-user mr-2"></i> Lihat Profil Penjual
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Safety Tips -->
                <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5">
                    <div class="flex items-center mb-3">
                        <i class="fas fa-shield-alt text-yellow-500 mr-2"></i>
                        <h4 class="font-bold text-yellow-800">Tips Transaksi Aman</h4>
                    </div>
                    <ul class="space-y-2 text-sm text-yellow-700">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-yellow-500 mr-2 mt-0.5"></i>
                            <span>Bertemu langsung di tempat umum yang aman</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-yellow-500 mr-2 mt-0.5"></i>
                            <span>Periksa barang dengan teliti sebelum membeli</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-yellow-500 mr-2 mt-0.5"></i>
                            <span>Jangan transfer uang sebelum bertemu</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-yellow-500 mr-2 mt-0.5"></i>
                            <span>Simpan bukti transaksi dan percakapan</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </main>

    <!-- Similar Products -->
    <?php if (!empty($similar_ads)): ?>
    <section class="container mx-auto px-4 py-8">
        <h2 class="text-2xl font-bold mb-6 animate-fade-in">Produk Serupa Lainnya</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($similar_ads as $index => $similar_ad): 
                $image_path = !empty($similar_ad['image_path']) ? $similar_ad['image_path'] : '';
                $has_similar_image = !empty($image_path);
            ?>
                <div class="animate-fade-in" style="animation-delay: <?= $index * 0.1 ?>s">
                    <a href="detail.php?id=<?= $similar_ad['id'] ?>" class="similar-product-card">
                        <div class="similar-product-image">
                            <?php if ($has_similar_image): ?>
                                <img src="<?= htmlspecialchars($image_path) ?>" 
                                     alt="<?= htmlspecialchars($similar_ad['title']) ?>"
                                     onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" width=\"300\" height=\"200\" viewBox=\"0 0 300 200\"%3E%3Crect width=\"300\" height=\"200\" fill=\"%23f3f4f6\"/%3E%3Ctext x=\"50%25\" y=\"50%25\" text-anchor=\"middle\" dy=\".3em\" font-family=\"Arial\" font-size=\"14\" fill=\"%239ca3af\"%3ENo Image%3C/text%3E%3C/svg%3E'">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-400">
                                    <i class="fas fa-image text-4xl"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <h3 class="font-medium text-gray-900 line-clamp-2 mb-2">
                                <?= htmlspecialchars($similar_ad['title']) ?>
                            </h3>
                            <div class="text-lg font-bold text-gray-900 mb-2">
                                Rp <?= number_format($similar_ad['price'], 0, ',', '.') ?>
                            </div>
                            <div class="flex items-center text-sm text-gray-500">
                                <i class="fas fa-map-marker-alt mr-2"></i>
                                <span class="truncate"><?= !empty($similar_ad['location']) ? htmlspecialchars($similar_ad['location']) : '-' ?></span>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

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
        // Image Gallery
        let currentImageIndex = 0;
        const totalImages = <?= $total_images ?>;
        const images = <?= json_encode($images) ?>;
        
        function changeImage(imageSrc, index) {
            const mainImage = document.getElementById('currentImage');
            if (mainImage) {
                mainImage.src = imageSrc;
                currentImageIndex = index;
                
                // Update active thumbnail
                document.querySelectorAll('.thumbnail').forEach(thumb => {
                    thumb.classList.remove('active');
                });
                
                const activeThumb = document.querySelector(`.thumbnail[data-index="${index}"]`);
                if (activeThumb) {
                    activeThumb.classList.add('active');
                }
                
                // Update counter
                const counter = document.querySelector('.image-counter');
                if (counter) {
                    counter.textContent = (index + 1) + '/' + totalImages;
                }
            }
        }
        
        function prevImage() {
            if (totalImages <= 1) return;
            
            let newIndex = currentImageIndex - 1;
            if (newIndex < 0) {
                newIndex = totalImages - 1;
            }
            
            if (images[newIndex]) {
                changeImage(images[newIndex], newIndex);
            }
        }
        
        function nextImage() {
            if (totalImages <= 1) return;
            
            let newIndex = currentImageIndex + 1;
            if (newIndex >= totalImages) {
                newIndex = 0;
            }
            
            if (images[newIndex]) {
                changeImage(images[newIndex], newIndex);
            }
        }
        
        // Keyboard navigation
        document.addEventListener('keydown', function(e) {
            if (totalImages <= 1) return;
            
            if (e.key === 'ArrowLeft') {
                prevImage();
            } else if (e.key === 'ArrowRight') {
                nextImage();
            }
        });
        
        // Handle image errors
        document.querySelectorAll('img').forEach(img => {
            img.addEventListener('error', function() {
                this.src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300" viewBox="0 0 400 300"%3E%3Crect width="400" height="300" fill="%23f3f4f6"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" font-family="Arial" font-size="14" fill="%239ca3af"%3ENo Image%3C/text%3E%3C/svg%3E';
            });
        });
    </script>
</body>
</html>