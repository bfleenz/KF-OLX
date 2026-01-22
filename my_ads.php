<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'config.php';

try {
    $user_id = $_SESSION['user_id'];
    $status_filter = isset($_GET['status']) && $_GET['status'] !== 'all' ? $_GET['status'] : null;
    
    // Base query
    $query = "
        SELECT ads.*, 
               categories.name AS category_name,
               (SELECT image_path FROM ad_images WHERE ad_id = ads.id LIMIT 1) AS image_path
        FROM ads 
        JOIN categories ON ads.category_id = categories.id 
        WHERE ads.user_id = :user_id 
    ";
    
    // Add status filter if specified
    if ($status_filter) {
        $query .= " AND ads.status = :status ";
    }
    
    $query .= " ORDER BY ads.created_at DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    
    if ($status_filter) {
        $stmt->bindParam(':status', $status_filter, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $ads = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_ads,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active_ads,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END), 0) as sold_ads,
            COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) as inactive_ads,
            COALESCE(SUM(views), 0) as total_views
        FROM ads 
        WHERE user_id = :user_id
    ");
    $stats_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        $stats = [
            'total_ads' => 0,
            'active_ads' => 0,
            'sold_ads' => 0,
            'inactive_ads' => 0,
            'total_views' => 0
        ];
    }
    
} catch(PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    $error = "Terjadi kesalahan. Silakan coba lagi nanti.";
    $ads = [];
    $stats = [
        'total_ads' => 0,
        'active_ads' => 0,
        'sold_ads' => 0,
        'inactive_ads' => 0,
        'total_views' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iklan Saya - Marketplace</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: #fafafa;
            padding-top: 64px;
        }
        
        .navbar {
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        /* Animation */
        .animate-slide-up {
            animation: slideUp 0.3s ease-out;
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        @media (min-width: 640px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .stat-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.25rem;
        }
        
        .stat-total { background: #e0f2fe; color: #0369a1; }
        .stat-active { background: #dcfce7; color: #16a34a; }
        .stat-sold { background: #fef3c7; color: #d97706; }
        .stat-views { background: #f3e8ff; color: #9333ea; }
        
        .stat-number {
            font-size: 1.875rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 0.5rem;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            color: #6b7280;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .tab:hover {
            background: #f9fafb;
            color: #374151;
        }
        
        .tab.active {
            background: #1f2937;
            color: white;
        }
        
        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(1, 1fr);
            gap: 1.5rem;
        }
        
        @media (min-width: 640px) {
            .products-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1024px) {
            .products-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        .product-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .product-image {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: #f9fafb;
        }
        
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .product-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active { background: #dcfce7; color: #16a34a; }
        .status-sold { background: #fef3c7; color: #d97706; }
        .status-inactive { background: #f3f4f6; color: #6b7280; }
        
        .product-content {
            padding: 1.25rem;
        }
        
        .product-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 0.5rem;
        }
        
        .product-title {
            font-size: 1rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.75rem;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .product-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .product-category, .product-views {
            display: flex;
            align-items: center;
            gap: 0.375rem;
        }
        
        .product-actions {
            padding: 0 1.25rem 1.25rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-action {
            flex: 1;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            text-align: center;
            text-decoration: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.375rem;
        }
        
        .btn-view { background: #e0f2fe; color: #0369a1; }
        .btn-view:hover { background: #bae6fd; }
        
        .btn-edit { background: #dcfce7; color: #16a34a; }
        .btn-edit:hover { background: #bbf7d0; }
        
        .btn-delete { background: #fee2e2; color: #dc2626; }
        .btn-delete:hover { background: #fecaca; }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 1rem;
        }
        
        .empty-icon {
            width: 80px;
            height: 80px;
            background: #f3f4f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            color: #9ca3af;
            font-size: 2rem;
        }
        
        .empty-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
            margin-bottom: 0.5rem;
        }
        
        .empty-text {
            color: #6b7280;
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        .btn-create {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #1f2937;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.2s;
        }
        
        .btn-create:hover {
            background: #374151;
        }
        
        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar fixed top-0 left-0 right-0 z-50">
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
                        <div class="hidden md:flex items-center space-x-4">
                            <a href="post-ad.php" class="bg-black text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-800 transition-colors">
                                <i class="fas fa-plus mr-2"></i>Jual
                            </a>
                            
                            <a href="profile.php" class="flex items-center space-x-2 text-gray-700 hover:text-black">
                                <div class="w-8 h-8 bg-gray-800 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-white text-sm"></i>
                                </div>
                                <span class="hidden lg:inline text-sm font-medium"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <!-- Header -->
        <div class="mb-8 animate-slide-up">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                <div>
                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 mb-2">Iklan Saya</h1>
                    <p class="text-gray-600">Kelola semua iklan yang Anda pasang</p>
                </div>
                
                <a href="post-ad.php" class="mt-4 md:mt-0 bg-black text-white px-5 py-3 rounded-lg font-semibold hover:bg-gray-800 transition-colors inline-flex items-center space-x-2">
                    <i class="fas fa-plus-circle"></i>
                    <span>Pasang Iklan Baru</span>
                </a>
            </div>
        </div>

        <!-- Error Message -->
        <?php if (isset($error)): ?>
            <div class="alert alert-danger animate-fade-in">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid animate-fade-in">
            <div class="stat-card">
                <div class="stat-icon stat-total">
                    <i class="fas fa-cube"></i>
                </div>
                <div class="stat-number"><?= htmlspecialchars($stats['total_ads']) ?></div>
                <div class="stat-label">Total Iklan</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon stat-active">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?= htmlspecialchars($stats['active_ads']) ?></div>
                <div class="stat-label">Aktif</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon stat-sold">
                    <i class="fas fa-tag"></i>
                </div>
                <div class="stat-number"><?= htmlspecialchars($stats['sold_ads']) ?></div>
                <div class="stat-label">Terjual</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon stat-views">
                    <i class="fas fa-eye"></i>
                </div>
                <div class="stat-number"><?= number_format($stats['total_views']) ?></div>
                <div class="stat-label">Total Dilihat</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8 animate-slide-up">
            <div class="tabs">
                <a href="my_ads.php?status=all" class="tab <?= (!isset($_GET['status']) || $_GET['status'] === 'all') ? 'active' : '' ?>">
                    <i class="fas fa-th"></i>
                    Semua
                </a>
                <a href="my_ads.php?status=active" class="tab <?= (isset($_GET['status']) && $_GET['status'] === 'active') ? 'active' : '' ?>">
                    <i class="fas fa-check-circle"></i>
                    Aktif
                </a>
                <a href="my_ads.php?status=sold" class="tab <?= (isset($_GET['status']) && $_GET['status'] === 'sold') ? 'active' : '' ?>">
                    <i class="fas fa-tag"></i>
                    Terjual
                </a>
                <a href="my_ads.php?status=inactive" class="tab <?= (isset($_GET['status']) && $_GET['status'] === 'inactive') ? 'active' : '' ?>">
                    <i class="fas fa-pause-circle"></i>
                    Nonaktif
                </a>
            </div>

            <!-- Ads Grid -->
            <?php if (empty($ads)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h3 class="empty-title">Belum ada iklan</h3>
                    <p class="empty-text">Mulai pasang iklan pertama Anda dan jual barang-barang Anda dengan mudah</p>
                    <a href="post-ad.php" class="btn-create">
                        <i class="fas fa-plus-circle"></i>
                        Pasang Iklan Pertama
                    </a>
                </div>
            <?php else: ?>
                <?php 
                // Filter ads based on status parameter
                $filtered_ads = $ads;
                $status_label = '';
                
                if (isset($_GET['status']) && $_GET['status'] !== 'all') {
                    $status_filter = $_GET['status'];
                    $filtered_ads = array_filter($ads, function($ad) use ($status_filter) {
                        return ($ad['status'] ?? '') === $status_filter;
                    });
                    
                    // Set status label for display
                    $status_labels = [
                        'active' => 'Aktif',
                        'sold' => 'Terjual',
                        'inactive' => 'Nonaktif'
                    ];
                    $status_label = $status_labels[$status_filter] ?? $status_filter;
                }
                
                if (empty($filtered_ads)): 
                ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-filter"></i>
                        </div>
                        <h3 class="empty-title">Tidak ada iklan</h3>
                        <p class="empty-text">Tidak ada iklan dengan status "<?= htmlspecialchars($status_label) ?>"</p>
                        <a href="my_ads.php?status=all" class="btn-create">
                            Lihat Semua Iklan
                        </a>
                    </div>
                <?php else: ?>
                    <div class="products-grid">
                        <?php foreach ($filtered_ads as $ad): 
                            // Process image path
                            $image_path = '';
                            $has_image = false;
                            
                            if (!empty($ad['image_path'])) {
                                $image_path = $ad['image_path'];
                                // Check if path exists
                                if (file_exists($image_path)) {
                                    $has_image = true;
                                } else {
                                    // Try to find image in common directories
                                    $filename = basename($image_path);
                                    $possible_paths = [
                                        'uploads/' . $filename,
                                        '../uploads/' . $filename,
                                        '../../uploads/' . $filename,
                                        'uploads/ads/' . $filename,
                                        '../uploads/ads/' . $filename,
                                        $filename
                                    ];
                                    
                                    foreach ($possible_paths as $test_path) {
                                        if (file_exists($test_path)) {
                                            $image_path = $test_path;
                                            $has_image = true;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // Determine status
                            $status_class = '';
                            $status_text = '';
                            switch ($ad['status'] ?? 'active') {
                                case 'active':
                                    $status_class = 'status-active';
                                    $status_text = 'Aktif';
                                    break;
                                case 'sold':
                                    $status_class = 'status-sold';
                                    $status_text = 'Terjual';
                                    break;
                                case 'inactive':
                                    $status_class = 'status-inactive';
                                    $status_text = 'Nonaktif';
                                    break;
                                default:
                                    $status_class = 'status-active';
                                    $status_text = 'Aktif';
                            }
                            
                            // Format price
                            $price = isset($ad['price']) ? number_format($ad['price'], 0, ',', '.') : '0';
                        ?>
                            <div class="product-card animate-fade-in">
                                <div class="product-image">
                                    <?php if ($has_image && !empty($image_path)): ?>
                                        <img src="<?= htmlspecialchars($image_path) ?>" 
                                             alt="<?= htmlspecialchars($ad['title']) ?>"
                                             onerror="this.onerror=null; this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                        <div class="absolute inset-0 flex items-center justify-center bg-gray-100 text-gray-400" style="display: none;">
                                            <i class="fas fa-image text-3xl"></i>
                                        </div>
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center bg-gray-100 text-gray-400">
                                            <i class="fas fa-image text-3xl"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span class="product-status <?= $status_class ?>">
                                        <?= $status_text ?>
                                    </span>
                                </div>
                                
                                <div class="product-content">
                                    <div class="product-price">Rp <?= $price ?></div>
                                    <h3 class="product-title"><?= htmlspecialchars($ad['title'] ?? 'Tidak ada judul') ?></h3>
                                    <div class="product-meta">
                                        <span class="product-category">
                                            <i class="fas fa-tag"></i>
                                            <?= htmlspecialchars($ad['category_name'] ?? 'Umum') ?>
                                        </span>
                                        <span class="product-views">
                                            <i class="fas fa-eye"></i>
                                            <?= number_format($ad['views'] ?? 0) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="product-actions">
                                    <a href="detail.php?id=<?= $ad['id'] ?>" target="_blank" class="btn-action btn-view">
                                        <i class="fas fa-external-link-alt"></i>
                                        Lihat
                                    </a>
                                    <a href="edit_ad.php?id=<?= $ad['id'] ?>" class="btn-action btn-edit">
                                        <i class="fas fa-edit"></i>
                                        Edit
                                    </a>
                                    <a href="delete_ad.php?id=<?= $ad['id'] ?>" 
                                       class="btn-action btn-delete"
                                       onclick="return confirm('Yakin ingin menghapus iklan ini?\nJudul: <?= addslashes($ad['title']) ?>')">
                                        <i class="fas fa-trash"></i>
                                        Hapus
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-black text-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center space-x-2 mb-3">
                        <i class="fas fa-store text-2xl"></i>
                        <span class="text-2xl font-bold">Marketplace</span>
                    </div>
                    <p class="text-white/80">Platform jual beli online terpercaya.</p>
                </div>
                
                <div class="flex flex-wrap justify-center gap-6">
                    <a href="about.php" class="text-white/80 hover:text-white transition-colors">Tentang Kami</a>
                    <a href="privacy.php" class="text-white/80 hover:text-white transition-colors">Kebijakan Privasi</a>
                    <a href="terms.php" class="text-white/80 hover:text-white transition-colors">Syarat & Ketentuan</a>
                    <a href="help.php" class="text-white/80 hover:text-white transition-colors">Bantuan</a>
                </div>
            </div>
            
            <div class="border-t border-white/20 mt-8 pt-8 text-center">
                <p class="text-white/60">&copy; <?= date('Y') ?> Marketplace. Semua hak dilindungi.</p>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Image error handling
            document.querySelectorAll('img').forEach(img => {
                img.addEventListener('error', function() {
                    if (!this.src.includes('placeholder')) {
                        this.style.display = 'none';
                        const fallback = this.nextElementSibling;
                        if (fallback) {
                            fallback.style.display = 'flex';
                        }
                    }
                });
            });
            
            // Confirm delete
            const deleteButtons = document.querySelectorAll('.btn-delete');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    const title = this.closest('.product-card').querySelector('.product-title').textContent;
                    if (!confirm('Yakin ingin menghapus iklan ini?\n\nJudul: ' + title)) {
                        e.preventDefault();
                        return false;
                    }
                });
            });
        });
    </script>
</body>
</html>