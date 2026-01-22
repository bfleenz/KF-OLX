<?php
session_start();
require 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Initialize form data and errors
$formData = [
    'title' => '',
    'category_id' => '',
    'price' => '',
    'location' => '',
    'description' => ''
];

$errors = [];

// Get categories from database
try {
    $stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Form submission handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize inputs
    $formData = [
        'title' => htmlspecialchars(trim($_POST['title'] ?? '')),
        'category_id' => (int)($_POST['category_id'] ?? 0),
        'price' => preg_replace('/[^0-9]/', '', $_POST['price'] ?? '0'),
        'location' => htmlspecialchars(trim($_POST['location'] ?? '')),
        'description' => htmlspecialchars(trim($_POST['description'] ?? ''))
    ];

    // Validation
    if (empty($formData['title'])) {
        $errors['title'] = 'Judul iklan harus diisi';
    } elseif (strlen($formData['title']) < 5) {
        $errors['title'] = 'Judul minimal 5 karakter';
    } elseif (strlen($formData['title']) > 150) {
        $errors['title'] = 'Judul maksimal 150 karakter';
    }

    // Validate category exists
    $validCategory = false;
    foreach ($categories as $category) {
        if ($category['id'] == $formData['category_id']) {
            $validCategory = true;
            break;
        }
    }
    
    if (!$validCategory) {
        $errors['category_id'] = 'Pilih kategori yang valid';
    }

    if (!is_numeric($formData['price']) || $formData['price'] <= 0) {
        $errors['price'] = 'Harga harus lebih dari 0';
    } elseif ($formData['price'] > 999999999999) {
        $errors['price'] = 'Harga terlalu besar';
    }

    if (empty($formData['location'])) {
        $errors['location'] = 'Lokasi harus diisi';
    } elseif (strlen($formData['location']) > 100) {
        $errors['location'] = 'Lokasi maksimal 100 karakter';
    }

    if (empty($formData['description'])) {
        $errors['description'] = 'Deskripsi harus diisi';
    } elseif (strlen($formData['description']) < 20) {
        $errors['description'] = 'Deskripsi minimal 20 karakter';
    } elseif (strlen($formData['description']) > 5000) {
        $errors['description'] = 'Deskripsi maksimal 5000 karakter';
    }

    // Handle image uploads
    $uploadedImages = [];
    $images = $_FILES['images'] ?? [];
    
    // Check if any file is uploaded
    $hasFiles = false;
    if (!empty($images['name'])) {
        foreach ($images['name'] as $index => $name) {
            if (!empty($name)) {
                $hasFiles = true;
                break;
            }
        }
    }
    
    if (!$hasFiles) {
        $errors['images'] = 'Minimal unggah 1 gambar';
    } else {
        $totalImages = count($images['name']);
        
        // Count actual uploaded files
        $actualUploaded = 0;
        foreach ($images['name'] as $name) {
            if (!empty($name)) $actualUploaded++;
        }
        
        // Limit maximum images
        if ($actualUploaded > 10) {
            $errors['images'] = 'Maksimal 10 foto yang dapat diunggah';
        }
        
        for ($i = 0; $i < $totalImages; $i++) {
            if ($images['error'][$i] === UPLOAD_ERR_OK && !empty($images['name'][$i])) {
                $fileTmpPath = $images['tmp_name'][$i];
                $fileName = $images['name'][$i];
                $fileSize = $images['size'][$i];
                
                // Validate file type using both extension and MIME type
                $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                if (!in_array($fileExtension, $allowedExtensions)) {
                    $errors['images'] = 'Hanya file JPG, PNG, GIF, dan WebP yang diperbolehkan';
                    break;
                }
                
                // Additional MIME type validation
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $fileTmpPath);
                finfo_close($finfo);
                
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!in_array($mimeType, $allowedMimeTypes)) {
                    $errors['images'] = 'File harus berupa gambar yang valid';
                    break;
                }
                
                // Validate file size (max 5MB)
                if ($fileSize > 5 * 1024 * 1024) {
                    $errors['images'] = 'Ukuran file maksimal 5MB';
                    break;
                }
                
                // Generate unique filename
                $newFileName = uniqid('img_', true) . '.' . $fileExtension;
                $uploadDir = 'uploads/';
                
                // Create directory if not exists
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $destPath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $uploadedImages[] = $destPath;
                } else {
                    $errors['images'] = 'Gagal mengupload gambar';
                    error_log("Upload error for file: " . $fileName);
                }
            } elseif ($images['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                // Handle upload errors
                switch ($images['error'][$i]) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $errors['images'] = 'Ukuran file terlalu besar';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $errors['images'] = 'File hanya terupload sebagian';
                        break;
                    default:
                        $errors['images'] = 'Terjadi kesalahan saat upload';
                }
                break;
            }
        }
        
        // Check if at least one image was uploaded
        if (empty($uploadedImages) && empty($errors['images'])) {
            $errors['images'] = 'Minimal unggah 1 gambar';
        }
    }

    // If no errors, save to database
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Insert ad
            $stmt = $pdo->prepare("
                INSERT INTO ads (user_id, category_id, title, description, price, location, created_at, status)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 'active')
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $formData['category_id'],
                $formData['title'],
                $formData['description'],
                $formData['price'],
                $formData['location']
            ]);
            
            $adId = $pdo->lastInsertId();
            
            // Insert images
            if (!empty($uploadedImages)) {
                $stmt = $pdo->prepare("
                    INSERT INTO ad_images (ad_id, image_path, is_primary)
                    VALUES (?, ?, ?)
                ");
                
                $isFirst = true;
                foreach ($uploadedImages as $imagePath) {
                    $stmt->execute([$adId, $imagePath, $isFirst ? 1 : 0]);
                    $isFirst = false;
                }
            }
            
            $pdo->commit();
            
            // Set success message and redirect
            $_SESSION['success'] = 'Iklan berhasil dipasang!';
            header('Location: detail.php?id=' . $adId);
            exit();
            
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors['general'] = 'Terjadi kesalahan. Silakan coba lagi.';
            error_log("Post ad error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pasang Iklan - Marketplace</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f5f5;
            min-height: 100vh;
            padding: 20px;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .animate-slide-up {
            animation: slideUp 0.6s ease-out;
        }
        
        /* Card Container */
        .card-container {
            max-width: 900px;
            margin: 40px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #e0e0e0;
        }
        
        /* Form Header */
        .form-header {
            background: #000000;
            color: white;
            padding: 30px 40px;
            position: relative;
        }
        
        .form-header h1 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 10px;
        }
        
        .form-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            font-weight: 400;
        }
        
        /* Form Content */
        .form-content {
            padding: 40px;
        }
        
        /* Progress Steps */
        .progress-steps {
            display: flex;
            justify-content: space-between;
            margin-bottom: 40px;
            position: relative;
        }
        
        .progress-steps::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e0e0e0;
            z-index: 1;
        }
        
        .progress-bar {
            position: absolute;
            top: 15px;
            left: 0;
            height: 2px;
            background: #000000;
            transition: width 0.3s ease;
            z-index: 2;
        }
        
        .step {
            position: relative;
            z-index: 3;
            text-align: center;
            width: 100px;
        }
        
        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: white;
            border: 2px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 10px;
            font-weight: 600;
            color: #666666;
            transition: all 0.3s ease;
        }
        
        .step.active .step-circle {
            background: #000000;
            border-color: #000000;
            color: white;
            transform: scale(1.1);
        }
        
        .step-label {
            font-size: 12px;
            font-weight: 500;
            color: #666666;
            transition: color 0.3s ease;
        }
        
        .step.active .step-label {
            color: #000000;
            font-weight: 600;
        }
        
        /* Form Groups */
        .form-section {
            margin-bottom: 50px;
            opacity: 0.5;
            transition: opacity 0.3s ease;
        }
        
        .form-section.active {
            opacity: 1;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #000000;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: #333333;
        }
        
        /* Form Elements */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            font-weight: 600;
            color: #333333;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .form-label .required {
            color: #dc2626;
            margin-left: 4px;
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            background: white;
            color: #333333;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #000000;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        
        .form-input.error, .form-select.error, .form-textarea.error {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .error-message {
            color: #dc2626;
            font-size: 13px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
            animation: fadeIn 0.3s ease;
        }
        
        /* Category Selection */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 15px;
            margin-top: 10px;
        }
        
        .category-item {
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
        }
        
        .category-item:hover {
            border-color: #000000;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .category-item.selected {
            background: #000000;
            border-color: #000000;
            color: white;
        }
        
        .category-item.selected::before {
            content: '✓';
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255, 255, 255, 0.2);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
        }
        
        .category-icon {
            font-size: 28px;
            margin-bottom: 12px;
            color: #666666;
            transition: color 0.3s ease;
        }
        
        .category-item.selected .category-icon {
            color: white;
        }
        
        .category-name {
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Image Upload */
        .image-upload-container {
            margin-top: 10px;
        }
        
        .image-upload {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .image-upload-label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 40px;
            background: #fafafa;
            border: 3px dashed #cccccc;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            position: relative;
        }
        
        .image-upload-label:hover {
            border-color: #000000;
            background: #f5f5f5;
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        }
        
        .image-upload-label i {
            font-size: 48px;
            color: #666666;
            margin-bottom: 20px;
        }
        
        .image-upload-label span {
            font-weight: 700;
            color: #000000;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .image-upload-label small {
            color: #666666;
            font-size: 14px;
            max-width: 400px;
            line-height: 1.5;
        }
        
        /* Image Preview */
        .image-preview {
            margin-top: 30px;
            animation: slideUp 0.4s ease;
        }
        
        .preview-title {
            font-weight: 600;
            color: #000000;
            margin-bottom: 15px;
            font-size: 16px;
        }
        
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .preview-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            border: 2px solid #e0e0e0;
            height: 120px;
            transition: all 0.3s ease;
        }
        
        .preview-item:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .remove-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            background: rgba(220, 38, 38, 0.95);
            color: white;
            border: none;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            transition: all 0.3s ease;
            opacity: 0;
        }
        
        .preview-item:hover .remove-btn {
            opacity: 1;
            transform: scale(1.1);
        }
        
        .remove-btn:hover {
            background: #b91c1c;
            transform: scale(1.2);
        }
        
        /* Price Input */
        .price-container {
            position: relative;
        }
        
        .price-prefix {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #666666;
            font-weight: 600;
            font-size: 15px;
        }
        
        .price-input {
            padding-left: 50px !important;
        }
        
        /* Navigation Buttons */
        .form-navigation {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
        }
        
        .nav-btn {
            padding: 14px 32px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            border: none;
        }
        
        .btn-prev {
            background: white;
            color: #666666;
            border: 2px solid #d1d5db;
        }
        
        .btn-prev:hover {
            background: #f5f5f5;
            border-color: #999999;
            color: #000000;
        }
        
        .btn-next {
            background: #000000;
            color: white;
            border: none;
        }
        
        .btn-next:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn-submit {
            background: #000000;
            color: white;
            border: none;
        }
        
        .btn-submit:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .card-container {
                margin: 20px auto;
                border-radius: 10px;
            }
            
            .form-header {
                padding: 25px 20px;
            }
            
            .form-header h1 {
                font-size: 24px;
            }
            
            .form-content {
                padding: 25px 20px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .progress-steps {
                margin-bottom: 30px;
            }
            
            .step {
                width: 70px;
            }
            
            .category-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
            
            .preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
            
            .preview-item {
                height: 100px;
            }
            
            .nav-btn {
                padding: 12px 24px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .form-header h1 {
                font-size: 20px;
            }
            
            .section-title {
                font-size: 18px;
            }
            
            .category-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 12px;
            }
            
            .category-item {
                padding: 15px;
            }
            
            .preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 12px;
            }
            
            .preview-item {
                height: 80px;
            }
            
            .form-navigation {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- Main Container -->
    <div class="card-container animate-slide-up">
        <!-- Form Header -->
        <div class="form-header">
            <h1>Pasang Iklan Baru</h1>
            <p>Jangkau lebih banyak pembeli dengan iklan yang menarik</p>
        </div>

        <!-- Form Content -->
        <div class="form-content">
            <!-- Progress Steps -->
            <div class="progress-steps">
                <div class="progress-bar" id="progressBar" style="width: 0%"></div>
                <div class="step active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Informasi</div>
                </div>
                <div class="step" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Detail</div>
                </div>
                <div class="step" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Gambar</div>
                </div>
            </div>

            <!-- Form Sections -->
            <form method="POST" enctype="multipart/form-data" id="postForm" novalidate>
                <!-- Section 1: Basic Information -->
                <div class="form-section active" id="section1">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i>
                        Informasi Dasar
                    </div>
                    
                    <div class="form-grid">
                        <!-- Title -->
                        <div class="form-group full-width">
                            <label for="title" class="form-label">
                                Judul Iklan <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="title" 
                                   name="title" 
                                   class="form-input <?= isset($errors['title']) ? 'error' : '' ?>" 
                                   value="<?= htmlspecialchars($formData['title']) ?>" 
                                   placeholder="Contoh: iPhone 13 Pro Max 256GB - Kondisi Baru" 
                                   maxlength="150"
                                   required>
                            <?php if (isset($errors['title'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($errors['title']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-right text-sm text-gray-600 mt-1">
                                <span id="titleCounter"><?= strlen($formData['title']) ?></span>/150 karakter
                            </div>
                        </div>

                        <!-- Category -->
                        <div class="form-group full-width">
                            <label class="form-label">
                                Kategori <span class="required">*</span>
                            </label>
                            <?php if (empty($categories)): ?>
                                <div class="text-center py-8 border-2 border-dashed border-gray-300 rounded-lg">
                                    <i class="fas fa-exclamation-triangle text-gray-500 text-2xl mb-2"></i>
                                    <p class="text-gray-600">Belum ada kategori yang tersedia</p>
                                </div>
                            <?php else: ?>
                                <div class="category-grid">
                                    <?php foreach($categories as $cat): ?>
                                        <div class="category-item <?= ($formData['category_id'] == $cat['id']) ? 'selected' : '' ?>" 
                                             onclick="selectCategory(<?= $cat['id'] ?>, this)">
                                            <div class="category-icon">
                                                <?php 
                                                $icon = 'fas fa-tag';
                                                $catName = strtolower($cat['name']);
                                                if (strpos($catName, 'elektronik') !== false) $icon = 'fas fa-laptop';
                                                elseif (strpos($catName, 'mobil') !== false) $icon = 'fas fa-car';
                                                elseif (strpos($catName, 'motor') !== false) $icon = 'fas fa-motorcycle';
                                                elseif (strpos($catName, 'rumah') !== false) $icon = 'fas fa-home';
                                                elseif (strpos($catName, 'fashion') !== false) $icon = 'fas fa-tshirt';
                                                elseif (strpos($catName, 'hobi') !== false) $icon = 'fas fa-gamepad';
                                                ?>
                                                <i class="<?= $icon ?>"></i>
                                            </div>
                                            <div class="category-name"><?= htmlspecialchars($cat['name']) ?></div>
                                            <input type="radio" 
                                                   name="category_id" 
                                                   value="<?= $cat['id'] ?>" 
                                                   <?= ($formData['category_id'] == $cat['id']) ? 'checked' : '' ?>
                                                   style="display: none;"
                                                   required>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (isset($errors['category_id'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($errors['category_id']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Details -->
                <div class="form-section" id="section2" style="display: none;">
                    <div class="section-title">
                        <i class="fas fa-file-alt"></i>
                        Detail Iklan
                    </div>
                    
                    <div class="form-grid">
                        <!-- Price -->
                        <div class="form-group">
                            <label for="price" class="form-label">
                                Harga (Rp) <span class="required">*</span>
                            </label>
                            <div class="price-container">
                                <span class="price-prefix">Rp</span>
                                <input type="text" 
                                       id="price" 
                                       name="price" 
                                       class="form-input price-input <?= isset($errors['price']) ? 'error' : '' ?>" 
                                       value="<?= !empty($formData['price']) && is_numeric($formData['price']) ? number_format((float)$formData['price'], 0, ',', '.') : '' ?>" 
                                       placeholder="15.000.000" 
                                       oninput="formatCurrency(this)"
                                       required>
                            </div>
                            <?php if (isset($errors['price'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($errors['price']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Location -->
                        <div class="form-group">
                            <label for="location" class="form-label">
                                Lokasi <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   id="location" 
                                   name="location" 
                                   class="form-input <?= isset($errors['location']) ? 'error' : '' ?>" 
                                   value="<?= htmlspecialchars($formData['location']) ?>" 
                                   placeholder="Contoh: Jakarta Selatan" 
                                   maxlength="100"
                                   required>
                            <?php if (isset($errors['location'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($errors['location']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Description -->
                        <div class="form-group full-width">
                            <label for="description" class="form-label">
                                Deskripsi <span class="required">*</span>
                            </label>
                            <textarea id="description" 
                                      name="description" 
                                      class="form-textarea <?= isset($errors['description']) ? 'error' : '' ?>" 
                                      placeholder="Jelaskan detail produk Anda secara lengkap. Sertakan kondisi, spesifikasi, dan kelebihan produk..." 
                                      rows="8"
                                      maxlength="5000"
                                      required><?= htmlspecialchars($formData['description']) ?></textarea>
                            <?php if (isset($errors['description'])): ?>
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <?= htmlspecialchars($errors['description']) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex justify-between text-sm text-gray-600 mt-1">
                                <span>Minimal 20 karakter</span>
                                <span><span id="descCounter"><?= strlen($formData['description']) ?></span>/5000 karakter</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Images -->
                <div class="form-section" id="section3" style="display: none;">
                    <div class="section-title">
                        <i class="fas fa-images"></i>
                        Unggah Gambar
                    </div>
                    
                    <div class="form-grid">
                        <!-- Image Upload -->
                        <div class="form-group full-width">
                            <label class="form-label">
                                Gambar Produk <span class="required">*</span>
                            </label>
                            <div class="image-upload-container">
                                <div class="image-upload">
                                    <label class="image-upload-label">
                                        <i class="fas fa-cloud-upload-alt"></i>
                                        <span>Seret & Lepas atau Klik untuk Unggah</span>
                                        <small>Format: JPG, PNG, GIF, WebP • Maksimal 5MB per gambar • Minimal 1, maksimal 10 gambar</small>
                                        <input type="file" 
                                               name="images[]" 
                                               class="file-input" 
                                               accept="image/*"
                                               multiple
                                               onchange="handleFiles(this.files)">
                                    </label>
                                </div>
                                <?php if (isset($errors['images'])): ?>
                                    <div class="error-message mt-3">
                                        <i class="fas fa-exclamation-circle"></i>
                                        <?= htmlspecialchars($errors['images']) ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Image Preview -->
                                <div id="imagePreview" class="image-preview" style="display: none;">
                                    <div class="preview-title">
                                        <i class="fas fa-eye mr-2"></i>
                                        Pratinjau Gambar
                                        <span id="imageCount" class="text-sm font-normal text-gray-600 ml-2"></span>
                                    </div>
                                    <div id="previewContainer" class="preview-grid"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="form-navigation">
                    <a href="index.php" class="flex items-center space-x-2 hover-lift">
                        <i class="fas fa-arrow-left text-gray-600"></i>
                        <span class="text-gray-700 font-medium">Kembali ke Beranda</span>
                    </a>
                    <button type="button" class="nav-btn btn-prev" id="prevBtn" style="display: none;">
                        <i class="fas fa-arrow-left"></i>
                        Kembali
                    </button>
                    <button type="button" class="nav-btn btn-next" id="nextBtn">
                        Lanjut
                        <i class="fas fa-arrow-right"></i>
                    </button>
                    <button type="submit" class="nav-btn btn-submit" id="submitBtn" style="display: none;">
                        <i class="fas fa-paper-plane"></i>
                        Pasang Iklan
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Multi-step form functionality
        let currentStep = 1;
        const totalSteps = 3;
        let selectedFiles = [];
        const maxFiles = 10;

        // Update progress bar
        function updateProgress() {
            const progressBar = document.getElementById('progressBar');
            const progress = ((currentStep - 1) / (totalSteps - 1)) * 100;
            progressBar.style.width = `${progress}%`;
            
            // Update steps
            document.querySelectorAll('.step').forEach(step => {
                const stepNum = parseInt(step.dataset.step);
                if (stepNum <= currentStep) {
                    step.classList.add('active');
                } else {
                    step.classList.remove('active');
                }
            });
            
            // Show/hide sections
            document.querySelectorAll('.form-section').forEach(section => {
                section.style.display = 'none';
                section.classList.remove('active');
            });
            
            const currentSection = document.getElementById(`section${currentStep}`);
            currentSection.style.display = 'block';
            setTimeout(() => currentSection.classList.add('active'), 10);
            
            // Update buttons
            document.getElementById('prevBtn').style.display = currentStep > 1 ? 'flex' : 'none';
            document.getElementById('nextBtn').style.display = currentStep < totalSteps ? 'flex' : 'none';
            document.getElementById('submitBtn').style.display = currentStep === totalSteps ? 'flex' : 'none';
        }

        // Navigation
        document.getElementById('nextBtn').addEventListener('click', nextStep);
        document.getElementById('prevBtn').addEventListener('click', prevStep);

        function nextStep() {
            if (validateStep(currentStep)) {
                currentStep++;
                updateProgress();
                scrollToTop();
            }
        }

        function prevStep() {
            currentStep--;
            updateProgress();
            scrollToTop();
        }

        function scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Step validation
        function validateStep(step) {
            let isValid = true;
            
            switch(step) {
                case 1:
                    const title = document.getElementById('title');
                    const category = document.querySelector('input[name="category_id"]:checked');
                    
                    if (!title.value.trim() || title.value.trim().length < 5) {
                        showError(title, 'Judul minimal 5 karakter');
                        isValid = false;
                    }
                    
                    if (!category) {
                        const categoryLabel = document.querySelector('.form-label[for="category_id"]');
                        if (!document.querySelector('.category-grid .error-message')) {
                            const errorDiv = document.createElement('div');
                            errorDiv.className = 'error-message';
                            errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i>Pilih kategori yang sesuai';
                            document.querySelector('.category-grid').appendChild(errorDiv);
                        }
                        isValid = false;
                    }
                    break;
                    
                case 2:
                    const price = document.getElementById('price');
                    const location = document.getElementById('location');
                    const description = document.getElementById('description');
                    
                    const priceValue = price.value.replace(/\./g, '');
                    if (!priceValue || !/^\d+$/.test(priceValue) || parseInt(priceValue) <= 0) {
                        showError(price, 'Harga harus lebih dari 0');
                        isValid = false;
                    }
                    
                    if (!location.value.trim()) {
                        showError(location, 'Lokasi harus diisi');
                        isValid = false;
                    }
                    
                    if (description.value.trim().length < 20) {
                        showError(description, 'Deskripsi minimal 20 karakter');
                        isValid = false;
                    }
                    break;
            }
            
            return isValid;
        }

        // Format currency
        function formatCurrency(input) {
            let value = input.value.replace(/\D/g, '');
            
            if (value) {
                value = parseInt(value).toLocaleString('id-ID');
                input.value = value;
            } else {
                input.value = '';
            }
        }

        // Select category
        function selectCategory(categoryId, element) {
            // Remove selected class from all categories
            document.querySelectorAll('.category-item').forEach(item => {
                item.classList.remove('selected');
                item.querySelector('input[type="radio"]').checked = false;
            });
            
            // Add selected class to clicked category
            element.classList.add('selected');
            element.querySelector('input[type="radio"]').checked = true;
            
            // Remove category error if exists
            const errorMsg = document.querySelector('.category-grid .error-message');
            if (errorMsg) {
                errorMsg.remove();
            }
        }

        // Character counters
        document.getElementById('title').addEventListener('input', function() {
            const counter = document.getElementById('titleCounter');
            counter.textContent = this.value.length;
        });

        document.getElementById('description').addEventListener('input', function() {
            const counter = document.getElementById('descCounter');
            counter.textContent = this.value.length;
        });

        // Image preview functionality
        function handleFiles(files) {
            const previewContainer = document.getElementById('previewContainer');
            const previewWrapper = document.getElementById('imagePreview');
            const imageCount = document.getElementById('imageCount');
            
            // Clear preview if no files selected
            if (!files || files.length === 0) {
                if (selectedFiles.length === 0) {
                    previewWrapper.style.display = 'none';
                }
                return;
            }
            
            // Check file limit
            if (selectedFiles.length + files.length > maxFiles) {
                alert(`Maksimal ${maxFiles} gambar yang dapat diunggah`);
                return;
            }
            
            // Process each file
            Array.from(files).forEach(file => {
                if (!file.type.startsWith('image/')) {
                    alert('Hanya file gambar yang diperbolehkan: ' + file.name);
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) {
                    alert('Ukuran file maksimal 5MB: ' + file.name);
                    return;
                }
                
                // Add to selected files
                selectedFiles.push(file);
                
                // Create preview
                const reader = new FileReader();
                const previewItem = document.createElement('div');
                previewItem.className = 'preview-item';
                previewItem.dataset.filename = file.name;
                
                reader.onload = function(e) {
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'preview-img';
                    img.alt = 'Preview';
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'remove-btn';
                    removeBtn.innerHTML = '×';
                    removeBtn.onclick = function(e) {
                        e.stopPropagation();
                        // Remove from selectedFiles
                        const filename = previewItem.dataset.filename;
                        selectedFiles = selectedFiles.filter(f => f.name !== filename);
                        previewItem.remove();
                        
                        // Update image count
                        updateImageCount();
                        
                        // Hide preview if no images
                        if (selectedFiles.length === 0) {
                            previewWrapper.style.display = 'none';
                        }
                    };
                    
                    previewItem.appendChild(img);
                    previewItem.appendChild(removeBtn);
                    previewContainer.appendChild(previewItem);
                    
                    // Show preview wrapper
                    previewWrapper.style.display = 'block';
                    
                    // Update image count
                    updateImageCount();
                };
                
                reader.readAsDataURL(file);
            });
        }

        function updateImageCount() {
            const imageCount = document.getElementById('imageCount');
            imageCount.textContent = `(${selectedFiles.length} gambar)`;
        }

        // Drag and drop functionality
        const dropArea = document.querySelector('.image-upload-label');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        function highlight() {
            dropArea.style.borderColor = '#000000';
            dropArea.style.transform = 'translateY(-2px)';
            dropArea.style.boxShadow = '0 4px 20px rgba(0, 0, 0, 0.08)';
        }

        function unhighlight() {
            dropArea.style.borderColor = '#cccccc';
            dropArea.style.transform = 'translateY(0)';
            dropArea.style.boxShadow = 'none';
        }

        dropArea.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }

        // Form submission
        const form = document.getElementById('postForm');
        const submitBtn = document.getElementById('submitBtn');

        form.addEventListener('submit', function(e) {
            let isValid = true;
            
            // Remove previous errors
            document.querySelectorAll('.error-message').forEach(error => error.remove());
            document.querySelectorAll('.form-input, .form-textarea').forEach(field => {
                field.classList.remove('error');
            });
            
            // Validate all steps
            for (let i = 1; i <= totalSteps; i++) {
                if (!validateStep(i)) {
                    isValid = false;
                    break;
                }
            }
            
            // Validate images on final step
            if (selectedFiles.length === 0) {
                const imageUpload = document.querySelector('.image-upload');
                const errorDiv = document.createElement('div');
                errorDiv.className = 'error-message mt-3';
                errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i>Minimal unggah 1 gambar';
                imageUpload.parentNode.appendChild(errorDiv);
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                // Go to first step with error
                if (selectedFiles.length === 0) currentStep = 3;
                updateProgress();
                
                // Scroll to first error
                const firstError = document.querySelector('.error-message');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                // Show loading
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Memproses...';
                submitBtn.disabled = true;
                
                // Add hidden inputs for files (for form submission simulation)
                const hiddenInputsContainer = document.createElement('div');
                hiddenInputsContainer.id = 'hiddenInputs';
                hiddenInputsContainer.style.display = 'none';
                form.appendChild(hiddenInputsContainer);
                
                // Create DataTransfer for FormData
                const dt = new DataTransfer();
                selectedFiles.forEach(file => dt.items.add(file));
                
                // Update file input
                const fileInput = document.querySelector('input[name="images[]"]');
                fileInput.files = dt.files;
            }
        });

        // Helper function to show error
        function showError(field, message) {
            field.classList.add('error');
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i>${message}`;
            field.parentNode.appendChild(errorDiv);
        }

        // Initialize counters
        document.getElementById('title').dispatchEvent(new Event('input'));
        document.getElementById('description').dispatchEvent(new Event('input'));

        // Add touch event listeners for mobile
        document.querySelectorAll('.category-item').forEach(item => {
            item.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.95)';
            });
            
            item.addEventListener('touchend', function() {
                this.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>