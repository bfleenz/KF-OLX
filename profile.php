<?php
// profile.php - Profile Page with Modern Black & White Design

// Start session with proper handling
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    // Save the intended URL for redirect after login
    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
    header('Location: login.php');
    exit();
}

require 'config.php';

// Initialize variables
$user = [];
$stats = [];
$error = '';
$success = '';

// Helper function for time elapsed
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'tahun',
        'm' => 'bulan',
        'w' => 'minggu',
        'd' => 'hari',
        'h' => 'jam',
        'i' => 'menit',
        's' => 'detik',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? '' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' yang lalu' : 'baru saja';
}

try {
    $user_id = $_SESSION['user_id'];
    
    // SESUAIKAN QUERY DENGAN STRUKTUR DATABASE ANDA
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            name,
            email, 
            whatsapp,
            phone,
            address,
            created_at,
            last_login,
            is_active
        FROM users 
        WHERE id = ?
    ");
    
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // User not found in database - destroy session
        session_destroy();
        header('Location: login.php');
        exit();
    }
    
    // Format user data untuk tampilan
    $user['display_name'] = !empty($user['name']) ? $user['name'] : 'Pengguna';
    
    // Prioritas nomor: whatsapp > phone
    $user['phone_number'] = '';
    if (!empty($user['whatsapp'])) {
        $user['phone_number'] = $user['whatsapp'];
    } elseif (!empty($user['phone'])) {
        $user['phone_number'] = $user['phone'];
    }
    
    // Format join date
    if (!empty($user['created_at'])) {
        $join_date = new DateTime($user['created_at']);
        $user['join_date_formatted'] = $join_date->format('l, d F Y');
    } else {
        $user['join_date_formatted'] = 'Tidak diketahui';
    }
    
    // Format last login
    if (!empty($user['last_login'])) {
        $last_login = new DateTime($user['last_login']);
        $user['last_login_formatted'] = $last_login->format('l, d F Y H:i');
    } else {
        $user['last_login_formatted'] = 'Belum pernah login';
    }
    
    // Status aktif
    $user['status_text'] = ($user['is_active'] == 1) ? 'Aktif' : 'Nonaktif';
    $user['status_class'] = ($user['is_active'] == 1) ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
    
    // Get user statistics
    try {
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_ads,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_ads,
                SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_ads,
                COALESCE(SUM(views), 0) as total_views
            FROM ads 
            WHERE user_id = ?
        ");
        
        $stats_stmt->execute([$user_id]);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        // Jika tabel ads tidak ada atau error, set default stats
        error_log("Stats error: " . $e->getMessage());
        $stats = [
            'total_ads' => 0,
            'active_ads' => 0,
            'sold_ads' => 0,
            'total_views' => 0
        ];
    }
    
    // Format stats if empty
    if (!$stats) {
        $stats = [
            'total_ads' => 0,
            'active_ads' => 0,
            'sold_ads' => 0,
            'total_views' => 0
        ];
    }
    
} catch (PDOException $e) {
    error_log("Database error in profile.php: " . $e->getMessage());
    $error = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
} catch (Exception $e) {
    error_log("General error in profile.php: " . $e->getMessage());
    $error = "Terjadi kesalahan sistem. Silakan coba lagi nanti.";
}

// Handle profile picture upload
// TAMBAHKAN KOLOM profile_picture DI DATABASE JIKA INGIN FITUR INI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_picture']) && isset($_FILES['profile_picture']['tmp_name']) && !empty($_FILES['profile_picture']['tmp_name'])) {
    try {
        $upload_dir = 'uploads/profile_pictures/';
        
        // Create directory if not exists
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['profile_picture'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($file_ext, $allowed_ext)) {
            $error = 'Format file tidak didukung. Gunakan JPG, PNG, atau GIF.';
        } elseif ($file['size'] > 2 * 1024 * 1024) { // 2MB max
            $error = 'Ukuran file terlalu besar. Maksimal 2MB.';
        } else {
            $new_filename = 'profile_' . $user_id . '_' . time() . '.' . $file_ext;
            $file_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($file['tmp_name'], $file_path)) {
                // Periksa apakah kolom profile_picture ada di tabel users
                // Jika tidak ada, buat kolomnya dulu atau comment kode ini
                try {
                    $update_stmt = $pdo->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                    $update_stmt->execute([$file_path, $user_id]);
                    $success = 'Foto profil berhasil diperbarui!';
                    
                    // Refresh user data
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Kolom profile_picture mungkin belum ada di database
                    $error = 'Fitur foto profil belum tersedia. Silakan hubungi administrator.';
                    error_log("Profile picture column missing: " . $e->getMessage());
                }
            } else {
                $error = 'Gagal mengupload file.';
            }
        }
    } catch (Exception $e) {
        error_log("Upload error: " . $e->getMessage());
        $error = 'Terjadi kesalahan saat mengupload file.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profil Saya - KF-OLX Marketplace</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #fafafa;
            min-height: 100vh;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-20px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .animate-slide-in {
            animation: slideIn 0.5s ease-out;
        }
        
        /* Profile Picture */
        .profile-picture {
            position: relative;
            display: inline-block;
        }
        
        .profile-picture:hover .profile-overlay {
            opacity: 1;
        }
        
        .profile-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s;
            cursor: pointer;
        }
        
        /* Stats Cards */
        .stats-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-color: #333;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.25rem;
            background-color: #f5f5f5;
            color: #333;
        }
        
        /* Tabs */
        .profile-tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 2rem;
        }
        
        .tab-button {
            padding: 0.75rem 1.5rem;
            background: none;
            border: none;
            color: #666;
            font-weight: 500;
            cursor: pointer;
            position: relative;
            transition: all 0.3s;
        }
        
        .tab-button:hover {
            color: #000;
        }
        
        .tab-button.active {
            color: #000;
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #000;
            border-radius: 1px;
        }
        
        /* Tab Content */
        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease-out;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Form Elements */
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.3s;
            background: white;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #000;
            box-shadow: 0 0 0 2px rgba(0,0,0,0.1);
        }
        
        /* Button Styles */
        .btn-primary {
            background: #000;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-primary:hover {
            background: #333;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .btn-secondary {
            background: white;
            color: #000;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-secondary:hover {
            background: #f5f5f5;
            border-color: #000;
        }
        
        /* Alert Messages */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            animation: fadeIn 0.5s ease-out;
        }
        
        .alert-success {
            background: #f5f5f5;
            border: 1px solid #d4d4d4;
            color: #000;
        }
        
        .alert-error {
            background: #f5f5f5;
            border: 1px solid #d4d4d4;
            color: #d32f2f;
        }
        
        /* Verification Badge */
        .verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: #f5f5f5;
            color: #000;
            padding: 0.25rem 0.75rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
            border: 1px solid #ddd;
        }
        
        /* Activity Items */
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s;
        }
        
        .activity-item:hover {
            background-color: #fafafa;
        }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ddd;
            transition: .4s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #000;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
        }
        
        /* Loading Animation */
        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .profile-tabs {
                flex-direction: column;
            }
            
            .tab-button {
                width: 100%;
                text-align: left;
                border-bottom: 1px solid #eee;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200 sticky top-0 z-50">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="index.php" class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-black rounded-lg flex items-center justify-center">
                        <i class="fas fa-store text-white text-sm"></i>
                    </div>
                    <span class="text-xl font-bold text-black">KF-OLX</span>
                </a>

                <!-- User Menu -->
                <div class="flex items-center space-x-4">
                    <a href="index.php" class="text-gray-700 hover:text-black px-3 py-2 rounded-lg transition-colors">
                        <i class="fas fa-home mr-2"></i>Beranda
                    </a>
                    <a href="my_ads.php" class="text-gray-700 hover:text-black px-3 py-2 rounded-lg transition-colors">
                        <i class="fas fa-th-list mr-2"></i>Iklan Saya
                    </a>
                    <a href="post-ad.php" class="bg-black text-white px-4 py-2 rounded-lg font-semibold hover:bg-gray-800 transition-colors">
                        <i class="fas fa-plus mr-2"></i>Jual
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="container mx-auto px-4 py-8">
        <!-- Alert Messages -->
        <?php if ($success): ?>
            <div class="alert alert-success animate-fade-in">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error animate-fade-in">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="max-w-6xl mx-auto animate-slide-in">
            <!-- Profile Header -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-8">
                <div class="flex flex-col md:flex-row items-center md:items-start gap-6">
                    <!-- Profile Picture -->
                    <div class="profile-picture">
                        <div class="w-32 h-32 rounded-full overflow-hidden border-4 border-white shadow-lg">
                            <?php if (!empty($user['profile_picture']) && file_exists($user['profile_picture'])): ?>
                                <img src="<?= htmlspecialchars($user['profile_picture']) ?>" 
                                     alt="Profile Picture" 
                                     class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                                    <span class="text-gray-700 text-3xl font-bold">
                                        <?= !empty($user['display_name']) ? strtoupper(substr($user['display_name'], 0, 1)) : '?' ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Upload Overlay -->
                        <div class="profile-overlay">
                            <form method="POST" enctype="multipart/form-data" id="uploadForm">
                                <label for="profile_picture" class="cursor-pointer text-white">
                                    <i class="fas fa-camera text-xl"></i>
                                    <input type="file" 
                                           id="profile_picture" 
                                           name="profile_picture" 
                                           accept="image/*" 
                                           class="hidden"
                                           onchange="document.getElementById('uploadForm').submit()">
                                </label>
                            </form>
                        </div>
                    </div>
                    
                    <!-- User Info -->
                    <div class="flex-1 text-center md:text-left">
                        <div class="flex flex-col md:flex-row md:items-center justify-between mb-4">
                            <div>
                                <h1 class="text-2xl font-bold text-gray-900 mb-2">
                                    <?= htmlspecialchars($user['display_name']) ?>
                                    <span class="verified-badge">
                                        <i class="fas fa-check"></i>
                                        Terverifikasi
                                    </span>
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $user['status_class'] ?> ml-2">
                                        <?= $user['status_text'] ?>
                                    </span>
                                </h1>
                                <p class="text-gray-600 mb-2">
                                    <i class="fas fa-user-plus mr-2"></i>
                                    Member sejak <?= htmlspecialchars($user['join_date_formatted']) ?>
                                </p>
                                <p class="text-gray-600 text-sm">
                                    <i class="fas fa-sign-in-alt mr-2"></i>
                                    Login terakhir: <?= htmlspecialchars($user['last_login_formatted']) ?>
                                </p>
                            </div>
                            <div class="mt-4 md:mt-0">
                                <a href="edit_profile.php" class="btn-primary">
                                    <i class="fas fa-edit"></i>
                                    Edit Profil
                                </a>
                            </div>
                        </div>
                        
                        <!-- Contact Info -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mt-6">
                            <?php if (!empty($user['email'])): ?>
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-envelope text-gray-700"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Email</p>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($user['email']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['phone_number'])): ?>
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <i class="fab fa-whatsapp text-gray-700"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">WhatsApp</p>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($user['phone_number']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($user['address'])): ?>
                            <div class="flex items-center space-x-3">
                                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-map-marker-alt text-gray-700"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500">Alamat</p>
                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($user['address']) ?></p>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs Navigation -->
            <div class="profile-tabs">
                <button class="tab-button active" data-tab="overview">
                    <i class="fas fa-chart-line mr-2"></i>Ringkasan
                </button>
                <button class="tab-button" data-tab="activity">
                    <i class="fas fa-history mr-2"></i>Aktivitas
                </button>
                <button class="tab-button" data-tab="settings">
                    <i class="fas fa-cog mr-2"></i>Pengaturan
                </button>
            </div>

            <!-- Tab Contents -->
            <div class="tab-content active" id="overviewTab">
                <!-- Statistics Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="stats-card">
                        <div class="stat-icon">
                            <i class="fas fa-cube"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">
                            <?= number_format($stats['total_ads'] ?? 0) ?>
                        </div>
                        <div class="text-gray-600">Total Iklan</div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">
                            <?= number_format($stats['active_ads'] ?? 0) ?>
                        </div>
                        <div class="text-gray-600">Aktif</div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="stat-icon">
                            <i class="fas fa-tag"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">
                            <?= number_format($stats['sold_ads'] ?? 0) ?>
                        </div>
                        <div class="text-gray-600">Terjual</div>
                    </div>
                    
                    <div class="stats-card">
                        <div class="stat-icon">
                            <i class="fas fa-eye"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900 mb-1">
                            <?= number_format($stats['total_views'] ?? 0) ?>
                        </div>
                        <div class="text-gray-600">Total Dilihat</div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Aktivitas Terbaru</h2>
                    <div class="space-y-4">
                        <?php
                        // Get recent activities
                        try {
                            $activity_stmt = $pdo->prepare("
                                SELECT id, title, status, created_at 
                                FROM ads 
                                WHERE user_id = ? 
                                ORDER BY created_at DESC 
                                LIMIT 5
                            ");
                            $activity_stmt->execute([$user_id]);
                            $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($activities)): ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-inbox text-3xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500">Belum ada aktivitas</p>
                                    <a href="post-ad.php" class="btn-primary mt-4 inline-block">
                                        <i class="fas fa-plus mr-2"></i>
                                        Pasang Iklan Pertama
                                    </a>
                                </div>
                            <?php else: 
                                foreach ($activities as $activity): 
                                    $time_ago = time_elapsed_string($activity['created_at']);
                                    $status_class = $activity['status'] === 'active' ? 'bg-gray-100 text-gray-800' : 
                                                   ($activity['status'] === 'sold' ? 'bg-gray-800 text-white' : 'bg-gray-200 text-gray-600');
                                    $status_text = $activity['status'] === 'active' ? 'Aktif' : 
                                                  ($activity['status'] === 'sold' ? 'Terjual' : 'Nonaktif');
                            ?>
                                <div class="activity-item">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                                <?php if ($activity['status'] === 'active'): ?>
                                                    <i class="fas fa-plus text-gray-700"></i>
                                                <?php elseif ($activity['status'] === 'sold'): ?>
                                                    <i class="fas fa-check text-gray-700"></i>
                                                <?php else: ?>
                                                    <i class="fas fa-pause text-gray-700"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900"><?= htmlspecialchars($activity['title'] ?? 'Tanpa Judul') ?></p>
                                                <p class="text-sm text-gray-600"><?= $time_ago ?></p>
                                            </div>
                                        </div>
                                        <span class="px-3 py-1 rounded-full text-sm font-medium <?= $status_class ?>">
                                            <?= $status_text ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; 
                            endif;
                        } catch (PDOException $e) {
                            echo '<p class="text-gray-500 text-center py-8">Tidak dapat memuat aktivitas</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Activity Tab -->
            <div class="tab-content" id="activityTab">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Riwayat Aktivitas Lengkap</h2>
                    <div class="space-y-4">
                        <?php
                        try {
                            $full_activity_stmt = $pdo->prepare("
                                SELECT id, title, status, price, created_at 
                                FROM ads 
                                WHERE user_id = ? 
                                ORDER BY created_at DESC
                            ");
                            $full_activity_stmt->execute([$user_id]);
                            $all_activities = $full_activity_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($all_activities)): ?>
                                <div class="text-center py-12">
                                    <i class="fas fa-history text-4xl text-gray-300 mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">Belum ada aktivitas</h3>
                                    <p class="text-gray-500">Mulai pasang iklan untuk melihat riwayat aktivitas</p>
                                    <a href="post-ad.php" class="btn-primary mt-4 inline-block">
                                        <i class="fas fa-plus mr-2"></i>
                                        Pasang Iklan Pertama
                                    </a>
                                </div>
                            <?php else: 
                                foreach ($all_activities as $activity): 
                                    $time_ago = time_elapsed_string($activity['created_at']);
                                    $price = !empty($activity['price']) ? 'Rp ' . number_format($activity['price'], 0, ',', '.') : '';
                            ?>
                                <div class="activity-item">
                                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                                        <div class="flex-1 mb-3 md:mb-0">
                                            <div class="flex items-start space-x-3">
                                                <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                    <?php if ($activity['status'] === 'active'): ?>
                                                        <i class="fas fa-bullhorn text-gray-700"></i>
                                                    <?php elseif ($activity['status'] === 'sold'): ?>
                                                        <i class="fas fa-check-circle text-gray-700"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-ban text-gray-700"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="font-medium text-gray-900"><?= htmlspecialchars($activity['title'] ?? 'Tanpa Judul') ?></p>
                                                    <p class="text-sm text-gray-600"><?= $time_ago ?></p>
                                                    <?php if ($price): ?>
                                                        <p class="text-sm font-medium text-gray-900 mt-1"><?= $price ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center space-x-3">
                                            <span class="px-3 py-1 bg-gray-100 text-gray-800 rounded-full text-sm font-medium">
                                                <?= $activity['status'] === 'active' ? 'Aktif' : 
                                                   ($activity['status'] === 'sold' ? 'Terjual' : 'Nonaktif') ?>
                                            </span>
                                            <a href="edit_ad.php?id=<?= $activity['id'] ?>" class="text-gray-600 hover:text-gray-900">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; 
                            endif;
                        } catch (PDOException $e) {
                            echo '<div class="text-center py-12">
                                    <i class="fas fa-exclamation-triangle text-3xl text-gray-300 mb-3"></i>
                                    <p class="text-gray-500">Gagal memuat riwayat aktivitas</p>
                                  </div>';
                        }
                        ?>
                    </div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div class="tab-content" id="settingsTab">
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Pengaturan Akun</h2>
                    
                    <div class="space-y-8">
                        <!-- Account Settings -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Akun</h3>
                            <div class="space-y-3">
                                <a href="edit_profile.php" class="flex items-center justify-between p-4 hover:bg-gray-50 rounded-lg transition-colors border border-gray-200">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-user-edit text-gray-700"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900">Edit Profil</p>
                                            <p class="text-sm text-gray-600">Ubah informasi pribadi</p>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right text-gray-400"></i>
                                </a>
                                
                                <a href="change_password.php" class="flex items-center justify-between p-4 hover:bg-gray-50 rounded-lg transition-colors border border-gray-200">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-lock text-gray-700"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900">Ubah Password</p>
                                            <p class="text-sm text-gray-600">Ganti password akun</p>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right text-gray-400"></i>
                                </a>
                            </div>
                        </div>
                        
                        <!-- Notification Settings -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Notifikasi</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                    <div>
                                        <p class="font-medium text-gray-900">Email Notifikasi</p>
                                        <p class="text-sm text-gray-600">Kirim notifikasi ke email</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                                
                                <div class="flex items-center justify-between p-4 border border-gray-200 rounded-lg">
                                    <div>
                                        <p class="font-medium text-gray-900">WhatsApp Notifikasi</p>
                                        <p class="text-sm text-gray-600">Kirim notifikasi ke WhatsApp</p>
                                    </div>
                                    <label class="toggle-switch">
                                        <input type="checkbox" checked>
                                        <span class="toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Danger Zone -->
                        <div class="border-t border-gray-200 pt-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4">Zona Berbahaya</h3>
                            <div class="space-y-3">
                                <button onclick="showDeleteModal()" class="w-full flex items-center justify-between p-4 hover:bg-gray-50 rounded-lg transition-colors border border-gray-300">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-trash-alt text-gray-700"></i>
                                        </div>
                                        <div class="text-left">
                                            <p class="font-medium text-gray-900">Hapus Akun</p>
                                            <p class="text-sm text-gray-600">Akun dan semua data akan dihapus permanen</p>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right text-gray-400"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-black text-white mt-12">
        <div class="container mx-auto px-4 py-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <div class="mb-6 md:mb-0">
                    <div class="flex items-center space-x-2 mb-3">
                        <i class="fas fa-store text-2xl"></i>
                        <span class="text-2xl font-bold">KF-OLX</span>
                    </div>
                    <p class="text-gray-400">Platform jual beli online terpercaya.</p>
                </div>
                
                <div class="flex flex-wrap justify-center gap-6">
                    <a href="about.php" class="text-gray-400 hover:text-white transition-colors">Tentang Kami</a>
                    <a href="privacy.php" class="text-gray-400 hover:text-white transition-colors">Kebijakan Privasi</a>
                    <a href="terms.php" class="text-gray-400 hover:text-white transition-colors">Syarat & Ketentuan</a>
                    <a href="help.php" class="text-gray-400 hover:text-white transition-colors">Bantuan</a>
                </div>
            </div>
            
            <div class="border-t border-gray-800 mt-8 pt-8 text-center">
                <p class="text-gray-500">&copy; <?= date('Y') ?> KF-OLX Marketplace. Semua hak dilindungi.</p>
            </div>
        </div>
    </footer>

    <!-- Delete Account Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4 animate-fade-in">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-exclamation-triangle text-gray-700 text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 mb-2">Hapus Akun?</h3>
                <p class="text-gray-600">Apakah Anda yakin ingin menghapus akun? Tindakan ini tidak dapat dibatalkan dan semua data akan hilang permanen.</p>
            </div>
            
            <div class="space-y-3">
                <input type="password" 
                       id="confirmPassword" 
                       placeholder="Masukkan password untuk konfirmasi" 
                       class="form-input">
                
                <div class="flex gap-3">
                    <button onclick="hideDeleteModal()" class="flex-1 btn-secondary">
                        Batal
                    </button>
                    <button onclick="deleteAccount()" class="flex-1 btn-primary bg-gray-900 hover:bg-black">
                        <span id="deleteText">Hapus Akun</span>
                        <div class="loading" id="deleteLoading" style="display: none;"></div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab functionality
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                
                // Update active tab button
                document.querySelectorAll('.tab-button').forEach(btn => {
                    btn.classList.remove('active');
                });
                this.classList.add('active');
                
                // Show active tab content
                document.querySelectorAll('.tab-content').forEach(content => {
                    content.classList.remove('active');
                });
                document.getElementById(tabId + 'Tab').classList.add('active');
            });
        });
        
        // Delete account modal
        function showDeleteModal() {
            document.getElementById('deleteModal').classList.remove('hidden');
        }
        
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
            document.getElementById('confirmPassword').value = '';
        }
        
        function deleteAccount() {
            const password = document.getElementById('confirmPassword').value;
            const deleteText = document.getElementById('deleteText');
            const deleteLoading = document.getElementById('deleteLoading');
            
            if (!password) {
                alert('Silakan masukkan password untuk konfirmasi.');
                return;
            }
            
            // Show loading
            deleteText.style.display = 'none';
            deleteLoading.style.display = 'block';
            
            // Simulate API call
            setTimeout(() => {
                alert('Fitur penghapusan akun akan segera tersedia.');
                hideDeleteModal();
                deleteText.style.display = 'inline';
                deleteLoading.style.display = 'none';
            }, 1500);
        }
        
        // Profile picture preview
        document.getElementById('profile_picture').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = document.querySelector('.profile-picture img');
                    if (img) {
                        img.src = e.target.result;
                    }
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
        
        // Close modal on outside click
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideDeleteModal();
            }
        });
        
        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('deleteModal').classList.contains('hidden')) {
                hideDeleteModal();
            }
        });
        
        // Initialize tabs
        document.addEventListener('DOMContentLoaded', function() {
            // Set first tab as active by default
            document.querySelector('.tab-button.active').click();
        });
    </script>
</body>
</html>