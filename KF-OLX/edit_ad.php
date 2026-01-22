<?php
session_start();

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require 'config.php';

$user_id = $_SESSION['user_id'];
$id = intval($_GET['id'] ?? 0);

// Validate ad ID
if ($id <= 0) {
    $_SESSION['error'] = 'ID iklan tidak valid.';
    header("Location: my_ads.php");
    exit;
}

// Initialize variables
$ad = null;
$categories = [];
$images = [];
$errors = [];

// FUNGSI PHP UNTUK PATH GAMBAR - TAMBAHKAN DI SINI
function getImageDisplayPath($path) {
    if (!$path) return '';
    
    // Check if path already has uploads/ prefix
    if (strpos($path, 'uploads/') === 0) {
        return $path;
    }
    
    // Check if path exists as is
    if (file_exists($path)) {
        return $path;
    }
    
    // Try with uploads/ prefix
    $new_path = 'uploads/' . basename($path);
    if (file_exists($new_path)) {
        return $new_path;
    }
    
    return $path; // Return as is if not found
}

function imageExists($path) {
    if (!$path) return false;
    
    // Try multiple possible paths
    $possible_paths = [
        $path,
        'uploads/' . basename($path),
        str_replace('uploads/', '', $path),
        'uploads/' . str_replace('uploads/', '', $path)
    ];
    
    foreach ($possible_paths as $test_path) {
        if (file_exists($test_path)) {
            return true;
        }
    }
    
    return false;
}

try {
    // Get ad data with category info (single query with join)
    $stmt = $pdo->prepare("
        SELECT a.*, c.name as category_name, COUNT(ai.id) as total_images
        FROM ads a 
        LEFT JOIN categories c ON a.category_id = c.id 
        LEFT JOIN ad_images ai ON a.id = ai.ad_id
        WHERE a.id = :id AND a.user_id = :user_id
        GROUP BY a.id
    ");
    $stmt->execute([':id' => $id, ':user_id' => $user_id]);
    $ad = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ad) {
        $_SESSION['error'] = 'Iklan tidak ditemukan atau Anda tidak memiliki akses.';
        header("Location: my_ads.php");
        exit;
    }

    // Get all images for this ad
    $img_stmt = $pdo->prepare("SELECT * FROM ad_images WHERE ad_id = :ad_id ORDER BY id");
    $img_stmt->execute([':ad_id' => $id]);
    $images = $img_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all active categories
    $cats_stmt = $pdo->query("SELECT id, name FROM categories WHERE status = 1 ORDER BY name");
    $categories = $cats_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Sanitize input
        $title = trim($_POST['title'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $price = isset($_POST['price']) ? str_replace(['.', ','], '', $_POST['price']) : '0';
        $price = floatval($price);
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'sold', 'inactive']) ? $_POST['status'] : 'active';
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        $delete_image_ids = array_map('intval', $_POST['delete_images'] ?? []);

        // Validation
        $errors = validateAdData($title, $category_id, $price, $location, $description, $phone);
        
        // Count existing images that won't be deleted
        $remaining_images = count($images) - count(array_intersect($delete_image_ids, 
            array_column($images, 'id')));
        
        // Validate uploaded images
        $new_images = [];
        if (!empty($_FILES['new_images']['name'][0])) {
            $total_images = $remaining_images + count($_FILES['new_images']['name']);
            
            if ($total_images > 10) {
                $errors['new_images'] = 'Maksimal 10 gambar per iklan. Anda memiliki ' . $remaining_images . ' gambar yang tersisa.';
            } else {
                foreach ($_FILES['new_images']['tmp_name'] as $index => $tmp_name) {
                    if ($_FILES['new_images']['error'][$index] === UPLOAD_ERR_OK) {
                        $validation = validateUploadedImage($_FILES['new_images'], $index);
                        if (!$validation['success']) {
                            $errors['new_images'] = $validation['error'];
                            break;
                        }
                    }
                }
                
                // If no errors, process uploads
                if (!isset($errors['new_images'])) {
                    foreach ($_FILES['new_images']['tmp_name'] as $index => $tmp_name) {
                        if ($_FILES['new_images']['error'][$index] === UPLOAD_ERR_OK) {
                            // Use uploadImage function from config.php
                            $upload_result = uploadImage($_FILES['new_images'], $index);
                            if (!$upload_result['success']) {
                                $errors['new_images'] = $upload_result['error'];
                                break;
                            }
                            $new_images[] = $upload_result['path'];
                        }
                    }
                }
            }
        }

        // If no errors, proceed with update
        if (empty($errors)) {
            $pdo->beginTransaction();

            try {
                // Update ad data
                $update_stmt = $pdo->prepare("
                    UPDATE ads SET 
                        title = :title, 
                        category_id = :category_id, 
                        price = :price, 
                        location = :location, 
                        description = :description,
                        status = :status,
                        phone = :phone,
                        updated_at = NOW()
                    WHERE id = :id AND user_id = :user_id
                ");
                
                $update_success = $update_stmt->execute([
                    ':title' => $title,
                    ':category_id' => $category_id,
                    ':price' => $price,
                    ':location' => $location,
                    ':description' => $description,
                    ':status' => $status,
                    ':phone' => $phone,
                    ':id' => $id,
                    ':user_id' => $user_id
                ]);

                if (!$update_success) {
                    throw new Exception('Gagal mengupdate data iklan.');
                }

                // Delete selected images
                if (!empty($delete_image_ids)) {
                    // Get image paths to delete files
                    $placeholders = implode(',', array_fill(0, count($delete_image_ids), '?'));
                    $delete_stmt = $pdo->prepare("
                        SELECT image_path FROM ad_images 
                        WHERE id IN ($placeholders) AND ad_id = ?
                    ");
                    $delete_params = array_merge($delete_image_ids, [$id]);
                    $delete_stmt->execute($delete_params);
                    $images_to_delete = $delete_stmt->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Delete image files
                    foreach ($images_to_delete as $image_path) {
                        $full_path = __DIR__ . '/' . $image_path;
                        if (file_exists($full_path) && is_writable($full_path)) {
                            unlink($full_path);
                        }
                    }
                    
                    // Delete from database
                    $delete_db_stmt = $pdo->prepare("
                        DELETE FROM ad_images 
                        WHERE id IN ($placeholders) AND ad_id = ?
                    ");
                    $delete_db_stmt->execute($delete_params);
                }

                // Add new images
                foreach ($new_images as $image_path) {
                    $img_insert_stmt = $pdo->prepare("
                        INSERT INTO ad_images (ad_id, image_path, created_at)
                        VALUES (:ad_id, :image_path, NOW())
                    ");
                    $img_insert_stmt->execute([
                        ':ad_id' => $id,
                        ':image_path' => $image_path
                    ]);
                }

                $pdo->commit();
                
                // Clear form data and set success message
                unset($_POST);
                $_SESSION['success'] = 'Iklan berhasil diperbarui!';
                header("Location: my_ads.php");
                exit;

            } catch(Exception $e) {
                $pdo->rollBack();
                $errors['general'] = 'Gagal mengupdate iklan. ' . $e->getMessage();
                error_log("Update ad error: " . $e->getMessage());
            }
        }
    }

} catch(PDOException $e) {
    $errors['general'] = 'Terjadi kesalahan database. Silakan coba lagi nanti.';
    error_log("Database error in edit_ad.php: " . $e->getMessage());
}

/**
 * Validate ad data
 */
function validateAdData($title, $category_id, $price, $location, $description, $phone) {
    $errors = [];
    
    // Title validation
    if (empty($title)) {
        $errors['title'] = 'Judul iklan harus diisi';
    } elseif (strlen($title) < 5) {
        $errors['title'] = 'Judul minimal 5 karakter';
    } elseif (strlen($title) > 200) {
        $errors['title'] = 'Judul maksimal 200 karakter';
    }
    
    // Category validation
    if ($category_id <= 0) {
        $errors['category_id'] = 'Kategori harus dipilih';
    }
    
    // Price validation
    if ($price <= 0) {
        $errors['price'] = 'Harga harus diisi dengan angka positif';
    } elseif ($price > 999999999999) {
        $errors['price'] = 'Harga terlalu besar';
    }
    
    // Location validation
    if (empty($location)) {
        $errors['location'] = 'Lokasi harus diisi';
    } elseif (strlen($location) > 100) {
        $errors['location'] = 'Lokasi maksimal 100 karakter';
    }
    
    // Description validation
    if (empty($description)) {
        $errors['description'] = 'Deskripsi harus diisi';
    } elseif (strlen($description) < 20) {
        $errors['description'] = 'Deskripsi minimal 20 karakter';
    } elseif (strlen($description) > 5000) {
        $errors['description'] = 'Deskripsi maksimal 5000 karakter';
    }
    
    // Phone validation (optional)
    if (!empty($phone) && (strlen($phone) < 10 || strlen($phone) > 15)) {
        $errors['phone'] = 'Nomor telepon harus 10-15 digit';
    }
    
    return $errors;
}

/**
 * Validate uploaded image
 */
function validateUploadedImage($file, $index) {
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if (!in_array($file['type'][$index], $allowed_types)) {
        return ['success' => false, 'error' => 'Format file tidak didukung. Gunakan JPG, PNG, atau GIF.'];
    }
    
    if ($file['size'][$index] > $max_size) {
        return ['success' => false, 'error' => 'Ukuran file maksimal 5MB.'];
    }
    
    // Check image dimensions
    $image_info = getimagesize($file['tmp_name'][$index]);
    if (!$image_info) {
        return ['success' => false, 'error' => 'File bukan gambar yang valid.'];
    }
    
    // Optional: Limit dimensions
    if ($image_info[0] > 4000 || $image_info[1] > 4000) {
        return ['success' => false, 'error' => 'Dimensi gambar terlalu besar. Maksimal 4000x4000 piksel.'];
    }
    
    return ['success' => true];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Iklan - Marketplace</title>
    
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
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 600;
        }
        
        .status-active {
            background-color: #10b981;
            color: white;
        }
        
        .status-sold {
            background-color: #f59e0b;
            color: white;
        }
        
        .status-inactive {
            background-color: #6b7280;
            color: white;
        }
        
        /* Form Content */
        .form-content {
            padding: 40px;
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
        
        /* Current Images Section */
        .current-images-section {
            background: #fafafa;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
            border: 1px solid #e0e0e0;
        }
        
        .current-images-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .current-images-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .current-image-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            background: white;
            border: 2px solid #e0e0e0;
            height: 120px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .current-image-item:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .current-image-item.selected {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
        }
        
        .current-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-number {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0, 0, 0, 0.75);
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }
        
        .selected-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #dc2626;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .current-image-item.selected .selected-icon {
            opacity: 1;
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
        
        /* Selected Files Preview */
        .selected-files-preview {
            margin-top: 30px;
        }
        
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 15px;
            margin-top: 15px;
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
        .form-actions {
            display: flex;
            justify-content: space-between;
            margin-top: 50px;
            padding-top: 30px;
            border-top: 2px solid #f0f0f0;
            gap: 20px;
        }
        
        .action-btn {
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
            text-decoration: none;
            text-align: center;
        }
        
        .btn-submit {
            background: #000000;
            color: white;
            border: none;
            flex: 2;
            justify-content: center;
        }
        
        .btn-submit:hover {
            background: #333333;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn-cancel {
            background: white;
            color: #000000;
            border: 2px solid #d1d5db;
            flex: 1;
            justify-content: center;
        }
        
        .btn-cancel:hover {
            background: #f5f5f5;
            border-color: #000000;
        }
        
        .btn-reset {
            background: #f5f5f5;
            color: #666666;
            border: 2px solid #e0e0e0;
            flex: 1;
            justify-content: center;
        }
        
        .btn-reset:hover {
            background: #e5e5e5;
            border-color: #999999;
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
            
            .category-grid {
                grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            }
            
            .current-images-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
            
            .current-image-item {
                height: 100px;
            }
            
            .preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
            
            .preview-item {
                height: 100px;
            }
            
            .form-actions {
                flex-direction: column;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .form-header h1 {
                font-size: 20px;
            }
            
            .category-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 12px;
            }
            
            .category-item {
                padding: 15px;
            }
            
            .current-images-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 12px;
            }
            
            .current-image-item {
                height: 80px;
            }
            
            .preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
                gap: 12px;
            }
            
            .preview-item {
                height: 80px;
            }
        }
    </style>
</head>
<body>
    <!-- Main Container -->
    <div class="card-container animate-slide-up">
        <!-- Form Header -->
        <div class="form-header">
            <div class="flex items-start justify-between">
                <div>
                    <h1>Edit Iklan</h1>
                    <p>Perbarui informasi iklan Anda</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="status-badge status-<?= $ad['status'] ?? 'active' ?>">
                        <i class="fas fa-circle text-xs mr-2"></i>
                        <?= $ad['status'] == 'active' ? 'Aktif' : ($ad['status'] == 'sold' ? 'Terjual' : 'Nonaktif') ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Form Content -->
        <div class="form-content">
            <?php if (!empty($errors['general'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg animate-fade-in">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-500 mt-0.5 mr-3"></i>
                        <p class="text-sm text-red-700"><?= htmlspecialchars($errors['general']) ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg animate-fade-in">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle text-red-500 mt-0.5 mr-3"></i>
                        <p class="text-sm text-red-700"><?= htmlspecialchars($_SESSION['error']) ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-r-lg animate-fade-in">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle text-green-500 mt-0.5 mr-3"></i>
                        <p class="text-sm text-green-700"><?= htmlspecialchars($_SESSION['success']) ?></p>
                    </div>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="editForm" novalidate>
                <!-- Title -->
                <div class="form-group">
                    <label for="title" class="form-label">
                        Judul Iklan <span class="required">*</span>
                    </label>
                    <input type="text" 
                           id="title" 
                           name="title" 
                           class="form-input <?= isset($errors['title']) ? 'error' : '' ?>" 
                           value="<?= htmlspecialchars($_POST['title'] ?? $ad['title'] ?? '') ?>" 
                           placeholder="Contoh: iPhone 13 Pro Max 256GB" 
                           maxlength="200"
                           required>
                    <?php if (isset($errors['title'])): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($errors['title']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="text-right text-sm text-gray-600 mt-1">
                        <span id="titleCounter"><?= strlen($_POST['title'] ?? $ad['title'] ?? '') ?></span>/200 karakter
                    </div>
                </div>

                <!-- Category -->
                <div class="form-group">
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
                            <?php foreach($categories as $cat): 
                                $selected = ($_POST['category_id'] ?? $ad['category_id'] ?? 0) == $cat['id'];
                            ?>
                                <div class="category-item <?= $selected ? 'selected' : '' ?>" 
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
                                           <?= $selected ? 'checked' : '' ?>
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

                <!-- Price & Location Grid -->
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
                                   value="<?= !empty($_POST['price']) ? htmlspecialchars($_POST['price']) : (!empty($ad['price']) ? number_format($ad['price'], 0, ',', '.') : '') ?>" 
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
                               value="<?= htmlspecialchars($_POST['location'] ?? $ad['location'] ?? '') ?>" 
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
                </div>

                <!-- Phone & Status Grid -->
                <div class="form-grid">
                    <!-- Phone -->
                    <div class="form-group">
                        <label for="phone" class="form-label">
                            Nomor Telepon (Opsional)
                        </label>
                        <input type="text" 
                               id="phone" 
                               name="phone" 
                               class="form-input <?= isset($errors['phone']) ? 'error' : '' ?>" 
                               value="<?= htmlspecialchars($_POST['phone'] ?? $ad['phone'] ?? '') ?>" 
                               placeholder="Contoh: 6281234567890"
                               maxlength="15">
                        <?php if (isset($errors['phone'])): ?>
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($errors['phone']) ?>
                            </div>
                        <?php endif; ?>
                        <p class="text-xs text-gray-500 mt-1">Format: 62xxxxxxxxxxx</p>
                    </div>

                    <!-- Status -->
                    <div class="form-group">
                        <label for="status" class="form-label">
                            Status Iklan
                        </label>
                        <select id="status" 
                                name="status" 
                                class="form-select">
                            <option value="active" <?= (($_POST['status'] ?? $ad['status'] ?? 'active') == 'active') ? 'selected' : '' ?>>Aktif</option>
                            <option value="sold" <?= (($_POST['status'] ?? $ad['status'] ?? 'active') == 'sold') ? 'selected' : '' ?>>Terjual</option>
                            <option value="inactive" <?= (($_POST['status'] ?? $ad['status'] ?? 'active') == 'inactive') ? 'selected' : '' ?>>Nonaktif</option>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">Ubah status jika barang sudah terjual</p>
                    </div>
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label for="description" class="form-label">
                        Deskripsi <span class="required">*</span>
                    </label>
                    <textarea id="description" 
                              name="description" 
                              class="form-textarea <?= isset($errors['description']) ? 'error' : '' ?>" 
                              placeholder="Jelaskan detail produk Anda (kondisi, spesifikasi, alasan dijual, dll.)" 
                              rows="8"
                              maxlength="5000"
                              required><?= htmlspecialchars($_POST['description'] ?? $ad['description'] ?? '') ?></textarea>
                    <?php if (isset($errors['description'])): ?>
                        <div class="error-message">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($errors['description']) ?>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-between text-sm text-gray-600 mt-1">
                        <span>Minimal 20 karakter</span>
                        <span><span id="descCounter"><?= strlen($_POST['description'] ?? $ad['description'] ?? '') ?></span>/5000 karakter</span>
                    </div>
                </div>

                <!-- Current Images -->
                <?php if (!empty($images)): ?>
                    <div class="current-images-section">
                        <div class="current-images-header">
                            <div>
                                <h3 class="font-bold text-gray-900">Gambar Saat Ini</h3>
                                <p class="text-sm text-gray-600">
                                    <?= count($images) ?> gambar tersedia
                                    <span id="deleteCountText"> | <span id="deleteCount">0</span> dipilih untuk dihapus</span>
                                </p>
                            </div>
                            <div class="text-sm text-gray-600">
                                <i class="fas fa-info-circle mr-2"></i>
                                Klik gambar untuk memilih
                            </div>
                        </div>
                        
                        <div class="current-images-grid">
                            <?php foreach ($images as $index => $image): 
                                // PERBAIKAN DI SINI: Gunakan fungsi PHP yang sudah didefinisikan
                                $image_path = getImageDisplayPath($image['image_path']);
                                $has_image = imageExists($image['image_path']);
                                $is_selected = false;
                            ?>
                                <div class="current-image-item <?= $is_selected ? 'selected' : '' ?>" 
                                     data-id="<?= $image['id'] ?>"
                                     onclick="toggleImageSelection(this, <?= $image['id'] ?>)">
                                    <div class="image-number"><?= $index + 1 ?></div>
                                    
                                    <?php if ($has_image): ?>
                                        <img src="<?= htmlspecialchars($image_path) ?>" 
                                             alt="Gambar <?= $index + 1 ?>" 
                                             class="current-img"
                                             onerror="this.onerror=null; this.src='data:image/svg+xml,%3Csvg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"%3E%3Crect width=\"100\" height=\"100\" fill=\"%23f3f4f6\"/%3E%3Ctext x=\"50%25\" y=\"50%25\" text-anchor=\"middle\" dy=\".3em\" font-family=\"sans-serif\" font-size=\"10\" fill=\"%239ca3af\"%3EGambar%3C/text%3E%3C/svg%3E'">
                                    <?php else: ?>
                                        <div class="w-full h-full flex flex-col items-center justify-center bg-gray-100 text-gray-400">
                                            <i class="fas fa-image text-2xl mb-2"></i>
                                            <span class="text-xs">Gambar tidak ditemukan</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="selected-icon">
                                        <i class="fas fa-times"></i>
                                    </div>
                                    <input type="checkbox" 
                                           name="delete_images[]" 
                                           value="<?= $image['id'] ?>" 
                                           class="hidden"
                                           <?= $is_selected ? 'checked' : '' ?>>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Add New Images -->
                <div class="form-group">
                    <label class="form-label">
                        Tambah Gambar Baru (Opsional)
                    </label>
                    <div class="image-upload-container">
                        <div class="image-upload">
                            <label class="image-upload-label">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <span>Seret & Lepas atau Klik untuk Unggah</span>
                                <small>Format: JPG, PNG, GIF, WebP • Maksimal 5MB per gambar • Minimal 1, maksimal 10 gambar total</small>
                                <input type="file" 
                                       id="new_images" 
                                       name="new_images[]" 
                                       class="file-input" 
                                       accept="image/*"
                                       multiple
                                       onchange="handleFiles(this.files)">
                            </label>
                        </div>
                        <?php if (isset($errors['new_images'])): ?>
                            <div class="error-message mt-3">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($errors['new_images']) ?>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Selected Files Preview -->
                        <div id="selectedFiles" class="selected-files-preview" style="display: none;">
                            <h4 class="font-medium text-gray-900">
                                <i class="fas fa-images mr-2"></i>
                                <span id="selectedCount">0</span> gambar baru dipilih
                            </h4>
                            
                            <div id="filesPreview" class="preview-grid"></div>
                        </div>
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <button type="submit" class="action-btn btn-submit">
                        <i class="fas fa-save"></i>
                        Update Iklan
                    </button>
                    
                    <a href="my_ads.php" class="action-btn btn-cancel">
                        <i class="fas fa-arrow-left"></i>
                        Kembali
                    </a>
                    
                    <button type="button" onclick="resetForm()" class="action-btn btn-reset">
                        <i class="fas fa-redo"></i>
                        Reset
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // HAPUS fungsi JavaScript getValidImagePath() karena sudah ada di PHP
        
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

        // Remove dots before form submit
        document.getElementById('editForm')?.addEventListener('submit', function(e) {
            const priceInput = document.getElementById('price');
            if (priceInput) {
                priceInput.value = priceInput.value.replace(/\./g, '');
            }
            
            // Validate before submit
            if (!validateForm()) {
                e.preventDefault();
                return false;
            }
        });

        // Select category
        function selectCategory(categoryId, element) {
            document.querySelectorAll('.category-item').forEach(item => {
                item.classList.remove('selected');
                item.querySelector('input[type="radio"]').checked = false;
            });
            
            element.classList.add('selected');
            element.querySelector('input[type="radio"]').checked = true;
        }

        // Character counters
        document.addEventListener('DOMContentLoaded', function() {
            // Title counter
            const titleInput = document.getElementById('title');
            const titleCounter = document.getElementById('titleCounter');
            if (titleInput && titleCounter) {
                titleInput.addEventListener('input', function() {
                    titleCounter.textContent = this.value.length;
                });
                titleInput.dispatchEvent(new Event('input'));
            }

            // Description counter
            const descriptionInput = document.getElementById('description');
            const descCounter = document.getElementById('descCounter');
            if (descriptionInput && descCounter) {
                descriptionInput.addEventListener('input', function() {
                    descCounter.textContent = this.value.length;
                });
                descriptionInput.dispatchEvent(new Event('input'));
            }
        });

        // Toggle image selection
        let deleteCount = 0;
        const maxTotalImages = 10;
        
        function toggleImageSelection(element, imageId) {
            const checkbox = element.querySelector('input[type="checkbox"]');
            
            if (element.classList.contains('selected')) {
                element.classList.remove('selected');
                checkbox.checked = false;
                deleteCount--;
            } else {
                element.classList.add('selected');
                checkbox.checked = true;
                deleteCount++;
            }
            
            // Update delete count display
            updateDeleteCountDisplay();
            
            // Update available slots for new images
            updateAvailableSlots();
        }
        
        function updateDeleteCountDisplay() {
            const deleteCountElement = document.getElementById('deleteCount');
            const deleteCountText = document.getElementById('deleteCountText');
            if (deleteCountElement) {
                deleteCountElement.textContent = deleteCount;
            }
            if (deleteCountText) {
                if (deleteCount > 0) {
                    deleteCountText.style.display = 'inline';
                } else {
                    deleteCountText.style.display = 'none';
                }
            }
        }

        // File upload handling
        let selectedFiles = [];
        
        function handleFiles(files) {
            const previewContainer = document.getElementById('filesPreview');
            const previewWrapper = document.getElementById('selectedFiles');
            const selectedCount = document.getElementById('selectedCount');
            
            // Get available slots
            const availableSlots = getAvailableSlots();
            
            if (files.length > availableSlots) {
                alert(`Hanya dapat menambahkan ${availableSlots} gambar lagi.`);
                return;
            }
            
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
                        
                        // Update count
                        updateSelectedCount();
                        
                        // Hide preview if no images
                        if (selectedFiles.length === 0) {
                            previewWrapper.style.display = 'none';
                        }
                    };
                    
                    previewItem.appendChild(img);
                    previewItem.appendChild(removeBtn);
                    previewContainer.appendChild(previewItem);
                };
                
                reader.readAsDataURL(file);
            });
            
            // Show preview wrapper
            if (selectedFiles.length > 0) {
                previewWrapper.style.display = 'block';
                updateSelectedCount();
            }
        }

        function updateSelectedCount() {
            const selectedCount = document.getElementById('selectedCount');
            if (selectedCount) {
                selectedCount.textContent = selectedFiles.length;
            }
        }

        // Calculate available slots for new images
        function getAvailableSlots() {
            const currentImages = document.querySelectorAll('.current-image-item').length || 0;
            const imagesToDelete = deleteCount;
            const remainingImages = currentImages - imagesToDelete;
            return maxTotalImages - remainingImages;
        }

        function updateAvailableSlots() {
            const availableSlots = getAvailableSlots();
            // Update UI jika diperlukan
            const uploadLabel = document.querySelector('.image-upload-label small');
            if (uploadLabel) {
                const currentText = uploadLabel.textContent;
                const baseText = currentText.split('•')[0] + '• Maksimal 5MB per gambar •';
                uploadLabel.textContent = baseText + ' Slot tersedia: ' + availableSlots + ' gambar';
            }
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

        // Form validation
        function validateForm() {
            const form = document.getElementById('editForm');
            let isValid = true;
            
            // Clear previous errors
            form.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
            form.querySelectorAll('.error-message').forEach(el => el.remove());
            
            // Validate required fields
            const requiredFields = form.querySelectorAll('[required]');
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    showError(field, 'Field ini harus diisi');
                    isValid = false;
                }
            });
            
            // Validate description length
            const description = document.getElementById('description');
            if (description && description.value.length < 20) {
                showError(description, 'Deskripsi minimal 20 karakter');
                isValid = false;
            }
            
            // Validate price
            const price = document.getElementById('price');
            if (price && price.value.replace(/\./g, '') <= 0) {
                showError(price, 'Harga harus lebih dari 0');
                isValid = false;
            }
            
            // Validate phone (if provided)
            const phone = document.getElementById('phone');
            if (phone && phone.value.trim() && !/^[0-9]{10,15}$/.test(phone.value)) {
                showError(phone, 'Nomor telepon harus 10-15 digit angka');
                isValid = false;
            }
            
            // Validate total images
            const currentImages = document.querySelectorAll('.current-image-item').length || 0;
            const imagesToDelete = deleteCount;
            const newImagesCount = selectedFiles.length;
            const totalAfterUpdate = (currentImages - imagesToDelete) + newImagesCount;
            
            if (totalAfterUpdate > maxTotalImages) {
                alert(`Maksimal ${maxTotalImages} gambar per iklan. Total gambar setelah update: ${totalAfterUpdate}`);
                isValid = false;
            }
            
            if (totalAfterUpdate === 0) {
                alert('Minimal 1 gambar harus tersedia untuk iklan');
                isValid = false;
            }
            
            if (!isValid) {
                // Scroll to first error
                const firstError = form.querySelector('.error');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
            }
            
            return isValid;
        }

        function showError(field, message) {
            field.classList.add('error');
            
            const errorDiv = document.createElement('div');
            errorDiv.className = 'error-message';
            errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i>${message}`;
            
            field.parentNode.appendChild(errorDiv);
        }

        // Reset form
        function resetForm() {
            if (confirm('Apakah Anda yakin ingin mereset form? Semua perubahan akan hilang.')) {
                document.getElementById('editForm').reset();
                selectedFiles = [];
                document.getElementById('selectedFiles').style.display = 'none';
                document.getElementById('filesPreview').innerHTML = '';
                deleteCount = 0;
                updateDeleteCountDisplay();
                document.querySelectorAll('.current-image-item.selected').forEach(el => {
                    el.classList.remove('selected');
                    el.querySelector('input[type="checkbox"]').checked = false;
                });
                
                // Reset character counters
                document.getElementById('title').dispatchEvent(new Event('input'));
                document.getElementById('description').dispatchEvent(new Event('input'));
                
                // Reset category selection
                document.querySelectorAll('.category-item.selected').forEach(el => {
                    el.classList.remove('selected');
                    el.querySelector('input[type="radio"]').checked = false;
                });
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateAvailableSlots();
            updateDeleteCountDisplay();
        });
    </script>
</body>
</html>