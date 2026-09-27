<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// ─── Public Faculty Profile Route (Apache subfolder dispatch) ─────────────────
// .htaccess rewrites /faculty/slug → index.php?_route=faculty_profile&slug=...
if (($_GET['_route'] ?? '') === 'faculty_profile' && !empty($_GET['slug'])) {
    require __DIR__ . '/Views/faculty/public_profile.php';
    exit();
}

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl());
    exit();
}

$db = getDB();
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$theme = [
    'theme_primary'    => '#c94d57',
    'theme_secondary'  => '#8f2633',
    'theme_accent'     => '#e58b91',
    'quick_test_login' => '1'         // default: show
];

foreach ($settings_rows as $row) {
    if (array_key_exists($row['setting_key'], $theme)) {
        $theme[$row['setting_key']] = $row['setting_value'];
    }
}
$showQuickLogin = ($theme['quick_test_login'] === '1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Login - Appraisal System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --theme-primary: <?php echo htmlspecialchars($theme['theme_primary']); ?>;
            --theme-secondary: <?php echo htmlspecialchars($theme['theme_secondary']); ?>;
            --theme-accent: <?php echo htmlspecialchars($theme['theme_accent']); ?>;
        }
        
        /* Global Reset */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-secondary) 100%);
            min-height: 100vh;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            padding: 40px 35px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            animation: fadeInUp 0.6s ease-out;
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--theme-primary) 0%, var(--theme-secondary) 100%);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header .logo-wrapper {
            display: inline-block;
            width: 90px;
            height: 90px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-secondary) 100%);
            padding: 8px;
            margin-bottom: 12px;
            box-shadow: 0 8px 25px rgba(253, 160, 133, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-header .logo-wrapper img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            background: white;
        }
        
        .login-header .logo-wrapper .logo-fallback {
            display: none;
            font-size: 40px;
            color: white;
        }
        
        .login-header .logo-wrapper img:not([src]) + .logo-fallback,
        .login-header .logo-wrapper img[src=""] + .logo-fallback {
            display: block;
        }
        
        .login-header h3 {
            color: #2d3748;
            margin-top: 8px;
            font-weight: 700;
            font-size: 24px;
        }
        
        .login-header h3 span {
            background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-header p {
            color: #718096;
            font-size: 14px;
            margin-bottom: 0;
        }
        
        .form-label {
            font-weight: 600;
            color: #2d3748;
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: white;
        }
        
        .input-group:focus-within {
            border-color: var(--theme-primary);
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
        }
        
        .input-group-text {
            background: white;
            border: none;
            padding: 10px 14px;
            color: #a0aec0;
            font-size: 16px;
        }
        
        .input-group .form-control {
            border: none;
            padding: 10px 14px;
            font-size: 14px;
            background: white;
            color: #2d3748;
        }
        
        .input-group .form-control:focus {
            box-shadow: none;
            outline: none;
        }
        
        .input-group .form-control::placeholder {
            color: #a0aec0;
        }
        
        .btn-login {
            background: linear-gradient(135deg, var(--theme-primary) 0%, var(--theme-secondary) 100%);
            border: none;
            color: white;
            padding: 14px;
            font-weight: 600;
            width: 100%;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(253, 160, 133, 0.4);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(0px);
        }
        
        .btn-login i {
            margin-right: 8px;
        }
        
        .forgot-link {
            color: var(--theme-primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .forgot-link:hover {
            color: var(--theme-accent);
            text-decoration: underline;
        }
        
        .credentials-box {
            background: #fffaf5;
            border-radius: 10px;
            padding: 14px 16px;
            margin-top: 20px;
            border: 1px solid #fde8d0;
        }
        
        .credentials-box small {
            color: #4a5568;
            font-size: 12px;
            line-height: 1.6;
        }
        
        .credentials-box small i {
            color: var(--theme-primary);
            margin-right: 4px;
        }
        
        .credentials-box .credential-row {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 12px;
        }
        
        .credentials-box .role {
            font-weight: 600;
            color: #2d3748;
        }
        
        .credentials-box .details {
            color: #718096;
        }
        
        .alert {
            border-radius: 10px;
            border: none;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        
        .alert-danger {
            background: #fff5f5;
            border-left-color: #fc8181;
            color: #c53030;
        }
        
        .alert-dismissible .btn-close {
            padding: 10px;
        }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
                align-items: flex-start;
                padding-top: 40px;
            }
            
            .login-card {
                padding: 30px 20px;
                border-radius: 16px;
                max-width: 100%;
            }
            
            .login-header .logo-wrapper {
                width: 75px;
                height: 75px;
                padding: 6px;
            }
            
            .login-header h3 {
                font-size: 20px;
            }
            
            .login-header p {
                font-size: 13px;
            }
            
            .input-group .form-control {
                padding: 12px 12px;
                font-size: 16px;
            }
            
            .input-group-text {
                padding: 8px 12px;
                font-size: 14px;
            }
            
            .btn-login {
                padding: 16px;
                font-size: 16px;
            }
            
            .credentials-box {
                padding: 12px 14px;
            }
            
            .credentials-box small {
                font-size: 11px;
            }
            
            .credentials-box .credential-row {
                flex-direction: column;
                padding: 3px 0;
            }
            
            .forgot-link {
                font-size: 12px;
            }
        }
        
        @media (max-width: 400px) {
            body {
                padding: 10px;
                padding-top: 30px;
            }
            
            .login-card {
                padding: 24px 16px;
                border-radius: 14px;
            }
            
            .login-header .logo-wrapper {
                width: 60px;
                height: 60px;
                padding: 5px;
            }
            
            .login-header h3 {
                font-size: 18px;
            }
            
            .login-header p {
                font-size: 12px;
            }
            
            .input-group .form-control {
                padding: 10px 10px;
                font-size: 15px;
            }
            
            .btn-login {
                padding: 14px;
                font-size: 15px;
            }
            
            .credentials-box {
                padding: 10px 12px;
            }
            
            .credentials-box small {
                font-size: 10px;
            }
        }
        
        @media (min-width: 768px) and (max-height: 600px) {
            body {
                align-items: flex-start;
                padding-top: 30px;
            }
            .login-card {
                padding: 30px 35px;
            }
        }
        
        input, button, .btn {
            touch-action: manipulation;
        }
        
        .form-control, .input-group {
            transition: all 0.25s ease;
        }
        
        .btn-login.loading {
            opacity: 0.8;
            pointer-events: none;
        }
        
        .btn-login.loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .input-group .input-group-text#togglePassword {
            cursor: pointer;
            background: white;
            border: none;
            color: #a0aec0;
            padding: 10px 14px;
            transition: color 0.3s;
        }
        
        .input-group .input-group-text#togglePassword:hover {
            color: #f6d365;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="logo-wrapper">
                <img src="assets\CAK.jpg" alt="College Logo" 
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                <span class="logo-fallback"><i class="bi bi-mortarboard-fill"></i></span>
            </div>
            <h3><span>Appraisal</span> System</h3>
            <p>Faculty Performance &amp; Task Management</p>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo BASE_URL; ?>/api/auth" id="loginForm">
            <input type="hidden" name="action" value="login">
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" required 
                           placeholder="Enter your email" autocomplete="email" autofocus>
                </div>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required 
                           placeholder="Enter your password" autocomplete="current-password">
                    <span class="input-group-text" id="togglePassword" onclick="togglePasswordVisibility()">
                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                    </span>
                </div>
            </div>
            
            <!-- Forgot Password Link -->
            <div class="d-flex justify-content-end mb-3">
                <a href="<?php echo BASE_URL; ?>/forgot-password" class="forgot-link">
                    <i class="bi bi-key me-1"></i> Forgot Password?
                </a>
            </div>
            
            <button type="submit" class="btn btn-login" id="loginBtn">
                <i class="bi bi-box-arrow-in-right"></i> Login
            </button>
        </form>
        
        <?php if ($showQuickLogin): ?>
        <div class="credentials-box">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <small>
                    <i class="bi bi-stars text-warning me-1"></i> 
                    <strong>Quick Test Login (1-Click Fill):</strong>
                </small>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-2">
                <button type="button" class="btn btn-sm btn-outline-primary" style="font-size: 11px; border-radius: 6px;" onclick="fillLogin('admin@college.edu', 'Admin@123')">
                    <i class="bi bi-shield-lock me-1"></i> Admin
                </button>
                <button type="button" class="btn btn-sm btn-outline-warning text-dark" style="font-size: 11px; border-radius: 6px; background: #fef9e7;" onclick="fillLogin('principal@college.edu', 'Principal@123')">
                    <i class="bi bi-person-badge me-1"></i> Principal
                </button>
                <button type="button" class="btn btn-sm btn-outline-purple" style="font-size: 11px; border-radius: 6px; color: #6f42c1; border-color: #6f42c1; background: #f8f5fc;" onclick="fillLogin('faculty@college.edu', 'Faculty@123')">
                    <i class="bi bi-mortarboard me-1"></i> Faculty
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }

        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePasswordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordInput.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('loginBtn');
            const icon = btn.querySelector('i');
            
            btn.classList.add('loading');
            icon.className = 'bi bi-arrow-repeat';
            btn.disabled = true;

            // Obfuscate / encode email so plain text is not exposed in Network tab payload
            const emailInput = document.getElementById('email');
            if (emailInput && emailInput.value) {
                try {
                    const rawVal = emailInput.value.trim();
                    emailInput.value = 'enc:' + btoa(unescape(encodeURIComponent(rawVal)));
                } catch(err) {}
            }
        });

        if (window.innerWidth >= 768) {
            document.getElementById('email').focus();
        }

        window.addEventListener('load', function() {
            const btn = document.getElementById('loginBtn');
            btn.classList.remove('loading');
            btn.disabled = false;
            const icon = btn.querySelector('i');
            icon.className = 'bi bi-box-arrow-in-right';
        });

        document.querySelector('.logo-wrapper img')?.addEventListener('error', function() {
            this.style.display = 'none';
            this.nextElementSibling.style.display = 'block';
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
