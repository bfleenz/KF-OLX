<?php
session_start();
include 'config.php';

$name = $email = $whatsapp = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms = isset($_POST['terms']);

    if (empty($name)) $errors['name'] = 'Nama lengkap harus diisi';
    elseif (strlen($name) < 3) $errors['name'] = 'Nama minimal 3 karakter';

    if (empty($email)) $errors['email'] = 'Email harus diisi';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Format email tidak valid';

    if (empty($whatsapp)) $errors['whatsapp'] = 'Nomor WhatsApp harus diisi';
    elseif (!preg_match('/^[0-9]{10,15}$/', $whatsapp)) $errors['whatsapp'] = 'Format nomor WhatsApp tidak valid (10-15 digit)';

    if (empty($password)) $errors['password'] = 'Password harus diisi';
    elseif (strlen($password) < 6) $errors['password'] = 'Password minimal 6 karakter';

    if (empty($confirm_password)) $errors['confirm_password'] = 'Konfirmasi password harus diisi';
    elseif ($password !== $confirm_password) $errors['confirm_password'] = 'Password tidak cocok';

    if (!$terms) $errors['terms'] = 'Anda harus menyetujui syarat dan ketentuan';

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            
            if ($stmt->rowCount() > 0) {
                $errors['email'] = 'Email sudah terdaftar';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, whatsapp, password) VALUES (?, ?, ?, ?)");
                
                if ($stmt->execute([$name, $email, $whatsapp, $hashed_password])) {
                    $user_id = $pdo->lastInsertId();
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['user_whatsapp'] = $whatsapp;
                    
                    header('Location: index.php');
                    exit();
                } else {
                    $errors['general'] = 'Terjadi kesalahan saat mendaftar. Silakan coba lagi.';
                }
            }
        } catch (PDOException $e) {
            $errors['general'] = 'Terjadi kesalahan sistem. Silakan coba lagi nanti.';
            error_log("Registration error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar - KF-OLX</title>
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
                
                <a href="login.php" class="text-gray-700 font-medium hover:text-gray-900 hover-lift">
                    Masuk
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
                            <i class="fas fa-user-plus text-white text-2xl"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900">Daftar Akun</h1>
                            <p class="text-gray-600 mt-1">Buat akun baru untuk mulai berjualan</p>
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

                    <form method="POST" id="registerForm" class="space-y-6">
                        <!-- Name -->
                        <div class="animate-slide-in" style="animation-delay: 0.1s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Nama Lengkap
                            </label>
                            <input type="text" 
                                   name="name" 
                                   value="<?= htmlspecialchars($name) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['name']) ? 'border-red-500' : '' ?>"
                                   placeholder="Masukkan nama lengkap">
                            <?php if (isset($errors['name'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['name']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Email -->
                        <div class="animate-slide-in" style="animation-delay: 0.2s">
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

                        <!-- WhatsApp -->
                        <div class="animate-slide-in" style="animation-delay: 0.3s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Nomor WhatsApp
                            </label>
                            <input type="tel" 
                                   name="whatsapp" 
                                   value="<?= htmlspecialchars($whatsapp) ?>"
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['whatsapp']) ? 'border-red-500' : '' ?>"
                                   placeholder="6281234567890">
                            <?php if (isset($errors['whatsapp'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['whatsapp']) ?></p>
                            <?php endif; ?>
                            <p class="mt-2 text-sm text-gray-500">Contoh: 6281234567890 (10-15 digit)</p>
                        </div>

                        <!-- Password -->
                        <div class="animate-slide-in" style="animation-delay: 0.4s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="password" 
                                       id="password"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['password']) ? 'border-red-500' : '' ?>"
                                       placeholder="Minimal 6 karakter">
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

                        <!-- Confirm Password -->
                        <div class="animate-slide-in" style="animation-delay: 0.5s">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Konfirmasi Password
                            </label>
                            <div class="relative">
                                <input type="password" 
                                       name="confirm_password" 
                                       id="confirm_password"
                                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 focus:outline-none transition input-focus <?= isset($errors['confirm_password']) ? 'border-red-500' : '' ?>"
                                       placeholder="Ulangi password">
                                <button type="button" 
                                        onclick="togglePassword('confirm_password', this)"
                                        class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors">
                                    <i class="far fa-eye"></i>
                                </button>
                            </div>
                            <?php if (isset($errors['confirm_password'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['confirm_password']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Terms -->
                        <div class="animate-slide-in" style="animation-delay: 0.6s">
                            <div class="flex items-start space-x-3 bg-gray-50 p-4 rounded-lg">
                                <input type="checkbox" 
                                       name="terms" 
                                       id="terms"
                                       class="mt-1 h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                       <?= isset($_POST['terms']) ? 'checked' : '' ?>>
                                <label for="terms" class="text-sm text-gray-700">
                                    Saya setuju dengan 
                                    <a href="#" class="text-blue-600 font-medium hover:text-blue-800 hover-lift">Syarat & Ketentuan</a> 
                                    dan 
                                    <a href="#" class="text-blue-600 font-medium hover:text-blue-800 hover-lift">Kebijakan Privasi</a>
                                </label>
                            </div>
                            <?php if (isset($errors['terms'])): ?>
                                <p class="mt-2 text-sm text-red-600 animate-slide-in"><?= htmlspecialchars($errors['terms']) ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Submit Button -->
                        <div class="animate-slide-in" style="animation-delay: 0.7s">
                            <button type="submit" 
                                    id="submitBtn"
                                    class="w-full bg-gray-900 text-white font-medium py-3.5 px-4 rounded-lg hover:bg-gray-800 hover-lift transition-all duration-300">
                                <span id="btnText">Daftar Sekarang</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </button>
                        </div>

                        <!-- Divider -->
                        <div class="animate-slide-in" style="animation-delay: 0.8s">
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
                        <div class="grid grid-cols-2 gap-3 animate-slide-in" style="animation-delay: 0.9s">
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

                        <!-- Login Link -->
                        <div class="text-center pt-6 border-t border-gray-100 animate-slide-in" style="animation-delay: 1s">
                            <p class="text-gray-600">
                                Sudah punya akun?
                                <a href="login.php" class="text-blue-600 font-medium hover:text-blue-800 hover-lift ml-1">
                                    Masuk di sini
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
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirm = document.getElementById('confirm_password').value;
            const terms = document.getElementById('terms');
            
            // Check password match
            if (password !== confirm) {
                e.preventDefault();
                const confirmInput = document.getElementById('confirm_password');
                confirmInput.classList.add('border-red-500', 'animate-pulse');
                confirmInput.focus();
                
                setTimeout(() => {
                    confirmInput.classList.remove('animate-pulse');
                }, 1000);
                return false;
            }
            
            // Check terms
            if (!terms.checked) {
                e.preventDefault();
                terms.closest('.bg-gray-50').classList.add('border-red-300', 'animate-pulse');
                terms.scrollIntoView({ behavior: 'smooth', block: 'center' });
                
                setTimeout(() => {
                    terms.closest('.bg-gray-50').classList.remove('animate-pulse');
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