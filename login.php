<?php
session_start();
include 'config.php';

$email = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Email tidak valid';
    }

    if (empty($password)) {
        $errors['password'] = 'Password harus diisi';
    }

    if (empty($errors)) {
        try {
            // PERBAIKAN: Ganti $conn menjadi $pdo
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                if (isset($user['is_active']) && $user['is_active'] == 0) {
                    $errors['login'] = 'Akun Anda dinonaktifkan. Silakan hubungi administrator.';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_whatsapp'] = $user['whatsapp'];
                    
                    if ($remember) {
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60);
                        setcookie('remember_token', $token, $expires, '/', '', false, true);
                        
                        $stmt = $pdo->prepare("UPDATE users SET remember_token = ?, token_expires = FROM_UNIXTIME(?) WHERE id = ?");
                        $stmt->execute([$token, $expires, $user['id']]);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    header('Location: index.php');
                    exit();
                }
            } else {
                $errors['login'] = 'Email atau password salah';
            }
        } catch (PDOException $e) {
            $errors['general'] = 'Terjadi kesalahan. Silakan coba lagi nanti.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - KF-OLX</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .animate-fade-in-up {
            animation: fadeInUp 0.5s ease-out;
        }
        
        .animate-slide-in {
            animation: slideIn 0.4s ease-out;
        }
        
        .hover-lift:hover {
            transform: translateY(-2px);
            transition: transform 0.2s ease;
        }
        
        .input-focus:focus {
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- Navbar -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
        <div class="container mx-auto px-4">
            <div class="flex items-center justify-between py-4">
                <a href="index.php" class="flex items-center space-x-2 hover-lift">
                    <i class="fas fa-arrow-left text-gray-600"></i>
                    <span class="text-gray-700 font-medium">Kembali ke Beranda</span>
                </a>
                
                <a href="register.php" class="text-gray-700 font-medium hover:text-gray-900 hover-lift">
                    Daftar
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto px-4 py-12">
        <div class="max-w-md mx-auto">
            <!-- Card -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden animate-fade-in-up">
                <!-- Header -->
                <div class="p-8 border-b border-gray-100">
                    <div class="flex items-center justify-center mb-6 animate-slide-in">
                        <div class="w-16 h-16 bg-gray-900 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-sign-in-alt text-white text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Masuk ke Akun</h1>
                            <p class="text-gray-600 mt-1">Selamat datang kembali di KF-OLX</p>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <div class="p-8">
                    <?php if (!empty($errors['general'])): ?>
                        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r animate-slide-in">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                                <span class="text-red-700"><?= htmlspecialchars($errors['general']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($errors['login'])): ?>
                        <div class="mb-6 bg-yellow-50 border-l-4 border-yellow-500 p-4 rounded-r animate-slide-in">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-triangle text-yellow-500 mr-3"></i>
                                <span class="text-yellow-700"><?= htmlspecialchars($errors['login']) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="loginForm" class="space-y-6">
                        <!-- Email -->
                        <div class="animate-slide-in" style="animation-delay: 0.1s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Email
                            </label>
                            <input type="email" 
                                   name="email" 
                                   value="<?= htmlspecialchars($email) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['email']) ? 'border-red-500' : '' ?>"
                                   placeholder="nama@email.com">
                            <?php if (isset($errors['email'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['email']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Password -->
                        <div class="animate-slide-in" style="animation-delay: 0.2s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="password" 
                                       id="password"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['password']) ? 'border-red-500' : '' ?>"
                                       placeholder="Masukkan password">
                                <button type="button" 
                                        onclick="togglePassword('password', this)"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
                                    <i class="far fa-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['password'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['password']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Remember & Forgot -->
                        <div class="flex justify-between items-center animate-slide-in" style="animation-delay: 0.3s">
                            <div class="flex items-center">
                                <input type="checkbox" 
                                       name="remember" 
                                       id="remember"
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                       <?= isset($_POST['remember']) ? 'checked' : '' ?>>
                                <label for="remember" class="ml-2 text-sm text-gray-700">
                                    Ingat saya
                                </label>
                            </div>
                            <a href="lupa-password.php" class="text-sm text-blue-600 hover:text-blue-800 hover-lift">
                                Lupa password?
                            </a>
                        </div>

                        <!-- Submit Button -->
                        <div class="animate-slide-in" style="animation-delay: 0.4s">
                            <button type="submit" 
                                    id="submitBtn"
                                    class="w-full bg-gray-900 text-white font-medium py-3.5 px-4 rounded-lg hover:bg-gray-800 hover-lift transition-all duration-300">
                                <span id="btnText">Masuk</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>

                        <!-- Divider -->
                        <div class="animate-slide-in" style="animation-delay: 0.5s">
                            <div class="relative">
                                <div class="absolute inset-0 flex items-center">
                                    <div class="w-full border-t border-gray-200"></div>
                                </div>
                                <div class="relative flex justify-center text-sm">
                                    <span class="px-4 bg-white text-gray-500">Atau lanjutkan dengan</span>
                                </div>
                            </div>
                        </div>

                        <!-- Social Login -->
                        <div class="grid grid-cols-2 gap-3 animate-slide-in" style="animation-delay: 0.6s">
                            <button type="button" 
                                    class="flex items-center justify-center px-4 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 hover-lift transition-all">
                                <i class="fab fa-google text-gray-600 mr-3"></i>
                                <span class="text-gray-700 font-medium">Google</span>
                            </button>
                            <button type="button" 
                                    class="flex items-center justify-center px-4 py-3 border border-gray-300 rounded-lg hover:bg-gray-50 hover-lift transition-all">
                                <i class="fab fa-facebook text-gray-600 mr-3"></i>
                                <span class="text-gray-700 font-medium">Facebook</span>
                            </button>
                        </div>

                        <!-- Register Link -->
                        <div class="text-center pt-6 border-t border-gray-100 animate-slide-in" style="animation-delay: 0.7s">
                            <p class="text-gray-600">
                                Belum punya akun?
                                <a href="register.php" class="text-blue-600 font-medium hover:text-blue-800 hover-lift ml-1">
                                    Daftar di sini
                                </a>
                            </p>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Footer -->
            <div class="mt-8 text-center text-gray-500 text-sm animate-fade-in-up">
                <p>&copy; <?= date('Y') ?> KF-OLX. Marketplace olahraga terpercaya.</p>
            </div>
        </div>
    </div>

    <script>
        // Password toggle
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
                button.setAttribute('title', 'Sembunyikan password');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
                button.setAttribute('title', 'Tampilkan password');
            }
            
            // Add animation
            button.style.transform = 'scale(1.2)';
            setTimeout(() => {
                button.style.transform = 'scale(1)';
            }, 200);
        }

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.querySelector('input[name="email"]');
            const password = document.getElementById('password');
            
            // Simple validation
            let isValid = true;
            
            if (!email.value || !email.value.includes('@')) {
                email.classList.add('border-red-500', 'animate-pulse');
                isValid = false;
            }
            
            if (!password.value) {
                password.classList.add('border-red-500', 'animate-pulse');
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
                
                setTimeout(() => {
                    email.classList.remove('animate-pulse');
                    password.classList.remove('animate-pulse');
                }, 1000);
                return false;
            }
            
            // Show loading
            const btn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            btn.disabled = true;
            btnText.textContent = 'Memproses...';
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Memproses...';
            
            // Add ripple effect
            btn.classList.add('relative', 'overflow-hidden');
            const ripple = document.createElement('span');
            ripple.className = 'absolute inset-0 bg-white opacity-20 animate-ripple';
            ripple.style.animation = 'ripple 0.6s linear';
            btn.appendChild(ripple);
            
            setTimeout(() => {
                ripple.remove();
            }, 600);
        });

        // Add ripple animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes ripple {
                from {
                    transform: scale(0);
                    opacity: 1;
                }
                to {
                    transform: scale(4);
                    opacity: 0;
                }
            }
            
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.7; }
            }
            
            .animate-pulse {
                animation: pulse 0.5s ease-in-out 2;
            }
            
            .animate-ripple {
                border-radius: 50%;
                transform-origin: center;
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>