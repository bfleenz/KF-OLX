<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'kf_olx');
define('DB_USER', 'root');
define('DB_PASS', '');

// Error reporting (production mode)
error_reporting(0);
ini_set('display_errors', 0);

// Development mode - uncomment for debugging
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Set default timezone
date_default_timezone_set('Asia/Jakarta');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        PDO::MYSQL_ATTR_FOUND_ROWS   => true
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Log error but don't show details to users
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}

/**
 * Sanitize user input
 * @param string $data
 * @return string
 */
function sanitize($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Redirect to a specific URL
 * @param string $url
 * @return void
 */
function redirect($url) {
    if (!headers_sent()) {
        header("Location: " . $url);
        exit();
    } else {
        echo '<script>window.location.href="' . $url . '";</script>';
        exit();
    }
}

/**
 * Hash password using PHP's password_hash()
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password against a hash
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Upload image and return the file path
 * @param array $file
 * @return array ['success' => bool, 'path' => string, 'error' => string]
 */
function uploadImage($file) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size = 5 * 1024 * 1024; // 5MB
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Error saat upload file'];
    }
    
    if (!in_array($file['type'], $allowed_types)) {
        return ['success' => false, 'error' => 'Format file tidak didukung. Gunakan JPG, PNG, atau GIF'];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Ukuran file maksimal 5MB'];
    }
    
    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('img_', true) . '.' . $ext;
    $upload_dir = 'uploads/';
    
    // Create upload directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target_path = $upload_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_path)) {
        return ['success' => true, 'path' => $target_path];
    }
    
    return ['success' => false, 'error' => 'Gagal menyimpan file'];
}

/**
 * Get all categories with ad count
 * @param bool $onlyActive
 * @return array
 */
function getCategories($onlyActive = true) {
    global $pdo;
    try {
        $sql = "SELECT c.*, COUNT(a.id) as ad_count 
                FROM categories c 
                LEFT JOIN ads a ON c.id = a.category_id AND a.status = 'active'";
        
        if ($onlyActive) {
            $sql .= " WHERE c.status = 1";
        }
        
        $sql .= " GROUP BY c.id ORDER BY c.name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting categories: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user by email
 * @param string $email
 * @return array|bool
 */
function getUserByEmail($email) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting user by email: " . $e->getMessage());
        return false;
    }
}

/**
 * Get user by ID
 * @param int $id
 * @return array|bool
 */
function getUserById($id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting user by ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Get ad by ID
 * @param int $id
 * @return array|bool
 */
function getAdById($id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   u.name as user_name, 
                   u.email as user_email,
                   u.whatsapp as user_whatsapp,
                   c.name as category_name
            FROM ads a 
            JOIN users u ON a.user_id = u.id 
            JOIN categories c ON a.category_id = c.id 
            WHERE a.id = ? AND a.status = 'active'
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error getting ad by ID: " . $e->getMessage());
        return false;
    }
}

/**
 * Get ads by user ID
 * @param int $userId
 * @param string $status
 * @return array
 */
function getAdsByUserId($userId, $status = null) {
    global $pdo;
    try {
        $sql = "SELECT a.*, c.name as category_name 
                FROM ads a 
                JOIN categories c ON a.category_id = c.id 
                WHERE a.user_id = ?";
        
        $params = [$userId];
        
        if ($status !== null) {
            $sql .= " AND a.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY a.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting ads by user ID: " . $e->getMessage());
        return [];
    }
}

/**
 * Get recent ads
 * @param int $limit
 * @param int $offset
 * @return array
 */
function getRecentAds($limit = 12, $offset = 0) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   c.name as category_name,
                   (SELECT image_path FROM ad_images WHERE ad_id = a.id LIMIT 1) as image_path
            FROM ads a 
            JOIN categories c ON a.category_id = c.id 
            WHERE a.status = 'active'
            ORDER BY a.created_at DESC 
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting recent ads: " . $e->getMessage());
        return [];
    }
}

/**
 * Get ad images
 * @param int $adId
 * @return array
 */
function getAdImages($adId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM ad_images WHERE ad_id = ? ORDER BY id ASC");
        $stmt->execute([$adId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error getting ad images: " . $e->getMessage());
        return [];
    }
}

/**
 * Search ads with filters
 * @param array $filters
 * @param int $limit
 * @param int $offset
 * @return array
 */
function searchAds($filters = [], $limit = 24, $offset = 0) {
    global $pdo;
    try {
        $sql = "
            SELECT a.*, 
                   c.name as category_name,
                   (SELECT image_path FROM ad_images WHERE ad_id = a.id LIMIT 1) as image_path
            FROM ads a 
            JOIN categories c ON a.category_id = c.id 
            WHERE a.status = 'active'
        ";
        
        $params = [];
        $conditions = [];
        
        // Title search
        if (!empty($filters['title'])) {
            $conditions[] = "a.title LIKE ?";
            $params[] = "%" . $filters['title'] . "%";
        }
        
        // Category filter
        if (!empty($filters['category_id'])) {
            $conditions[] = "a.category_id = ?";
            $params[] = $filters['category_id'];
        }
        
        // Location filter
        if (!empty($filters['location'])) {
            $conditions[] = "a.location LIKE ?";
            $params[] = "%" . $filters['location'] . "%";
        }
        
        // Price range filter
        if (!empty($filters['min_price'])) {
            $conditions[] = "a.price >= ?";
            $params[] = $filters['min_price'];
        }
        
        if (!empty($filters['max_price'])) {
            $conditions[] = "a.price <= ?";
            $params[] = $filters['max_price'];
        }
        
        // Combine conditions
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        // Order by
        $orderBy = 'a.created_at DESC';
        if (!empty($filters['sort'])) {
            switch ($filters['sort']) {
                case 'price_asc':
                    $orderBy = 'a.price ASC';
                    break;
                case 'price_desc':
                    $orderBy = 'a.price DESC';
                    break;
                case 'newest':
                    $orderBy = 'a.created_at DESC';
                    break;
                case 'oldest':
                    $orderBy = 'a.created_at ASC';
                    break;
            }
        }
        
        $sql .= " ORDER BY " . $orderBy;
        $sql .= " LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        
        // Bind parameters with correct types
        foreach ($params as $index => $param) {
            if (is_int($param)) {
                $stmt->bindValue($index + 1, $param, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($index + 1, $param, PDO::PARAM_STR);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Error searching ads: " . $e->getMessage());
        return [];
    }
}

/**
 * Get all unique locations from ads
 * @return array
 */
function getLocations() {
    global $pdo;
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT location 
            FROM ads 
            WHERE location IS NOT NULL 
            AND location != '' 
            AND status = 'active'
            ORDER BY location ASC
            LIMIT 50
        ");
        return $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    } catch (PDOException $e) {
        error_log("Error getting locations: " . $e->getMessage());
        return [];
    }
}

/**
 * Format price to Indonesian Rupiah
 * @param float $price
 * @return string
 */
function formatPrice($price) {
    if ($price >= 1000000) {
        $formatted = number_format($price / 1000000, 1, ',', '.');
        // Remove .0 if it's a whole number
        if (strpos($formatted, '.0') !== false) {
            $formatted = str_replace('.0', '', $formatted);
        }
        return 'Rp ' . $formatted . ' jt';
    } elseif ($price >= 1000) {
        $formatted = number_format($price / 1000, 1, ',', '.');
        // Remove .0 if it's a whole number
        if (strpos($formatted, '.0') !== false) {
            $formatted = str_replace('.0', '', $formatted);
        }
        return 'Rp ' . $formatted . ' rb';
    } else {
        return 'Rp ' . number_format($price, 0, ',', '.');
    }
}

/**
 * Format date to time ago
 * @param string $datetime
 * @param bool $full
 * @return string
 */
function timeAgo($datetime, $full = false) {
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

/**
 * Validate email format
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Set flash message
 * @param string $key
 * @param string $message
 * @return void
 */
function setFlash($key, $message) {
    $_SESSION['flash_' . $key] = $message;
}

/**
 * Get flash message
 * @param string $key
 * @return string|null
 */
function getFlash($key) {
    if (isset($_SESSION['flash_' . $key])) {
        $message = $_SESSION['flash_' . $key];
        unset($_SESSION['flash_' . $key]);
        return $message;
    }
    return null;
}

/**
 * Check if user can edit/delete ad
 * @param array $ad
 * @param int $userId
 * @return bool
 */
function canEditAd($ad, $userId) {
    return $ad['user_id'] == $userId;
}

/**
 * Get category icon based on category name
 * @param string $category_name
 * @return string
 */
function getCategoryIcon($category_name) {
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

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Generate CSRF token field
 * @return string
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . $_SESSION['csrf_token'] . '">';
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Clean old files from uploads directory
 * @param string $directory
 * @param int $daysOld
 * @return int Number of files deleted
 */
function cleanOldFiles($directory = 'uploads/', $daysOld = 30) {
    $directory = rtrim($directory, '/') . '/';
    $deletedCount = 0;
    $time = time() - ($daysOld * 24 * 60 * 60);
    
    if (is_dir($directory)) {
        $files = scandir($directory);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && $file != '.gitkeep') {
                $filePath = $directory . $file;
                if (is_file($filePath) && filemtime($filePath) < $time) {
                    if (unlink($filePath)) {
                        $deletedCount++;
                    }
                }
            }
        }
    }
    
    return $deletedCount;
}

// Include this file in your other PHP files using: require 'config.php';
?>