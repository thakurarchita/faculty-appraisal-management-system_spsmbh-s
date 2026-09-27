<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl());
    exit();
}

// Get token from URL
$token = isset($_GET['token']) ? $_GET['token'] : '';
$email = isset($_GET['email']) ? $_GET['email'] : '';

if (empty($token) || empty($email)) {
    $_SESSION['error'] = 'Invalid password reset link. Please request a new one.';
    header('Location: ' . BASE_URL . '/forgot-password');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reset Password - Appraisal System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #8f2633 0%, #c94d57 70%, #e58b91 100%);
            min-height: 100vh;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 40px 35px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            animation: fadeInUp 0.6s ease-out;
            position: relative;
            overflow: hidden;
            border: none;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #12304a 0%, #d6a84f 100%);
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
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header .icon-wrapper {
            display: inline-block;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg, #12304a 0%, #d6a84f 100%);
            padding: 8px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .header .icon-wrapper i {
            font-size: 40px;
            color: white;
        }
        
        .header h3 {
            color: #2d3748;
            margin-top: 8px;
            font-weight: 700;
            font-size: 24px;
        }
        
        .header p {
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
        
        .form-control {
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #d6a84f;
            box-shadow: 0 0 0 3px rgba(214, 168, 79, 0.25);
        }
        
        .input-group {
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #e2e8f0;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: white;
        }
        
        .input-group:focus-within {
            border-color: #d6a84f;
            box-shadow: 0 0 0 3px rgba(214, 168, 79, 0.25);
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
            padding: 12px 14px;
            font-size: 14px;
            background: white;
            color: #2d3748;
        }
        
        .input-group .form-control:focus {
            box-shadow: none;
            outline: none;
        }
        
        .btn-reset {
            background: linear-gradient(135deg, #12304a 0%, #d6a84f 100%);
            border: none;
            color: white;
            padding: 14px;
            font-weight: 600;
            width: 100%;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(253, 160, 133, 0.4);
            color: white;
        }
        
        .btn-reset:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .btn-reset i {
            margin-right: 8px;
        }
        
        .back-link {
            color: #12304a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s;
        }
        
        .back-link:hover {
            color: #d6a84f;
            text-decoration: underline;
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
        
        .alert-success {
            background: #f0fff4;
            border-left-color: #48bb78;
            color: #22543d;
        }
        
        .password-requirements {
            font-size: 12px;
            color: #718096;
            margin-top: 5px;
        }
        
        .password-requirements ul {
            list-style: none;
            padding-left: 0;
            margin: 5px 0 0;
        }
        
        .password-requirements ul li {
            padding: 2px 0;
        }
        
        .password-requirements ul li i {
            margin-right: 5px;
        }
        
        .password-requirements ul li.valid {
            color: #48bb78;
        }
        
        .password-requirements ul li.invalid {
            color: #fc8181;
        }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
                padding-top: 40px;
            }
            
            .card {
                padding: 30px 20px;
                border-radius: 16px;
            }
            
            .header .icon-wrapper {
                width: 60px;
                height: 60px;
            }
            
            .header .icon-wrapper i {
                font-size: 30px;
            }
            
            .header h3 {
                font-size: 20px;
            }
            
            .header p {
                font-size: 13px;
            }
            
            .form-control {
                padding: 10px 12px;
                font-size: 16px;
            }
            
            .btn-reset {
                padding: 16px;
                font-size: 16px;
            }
        }
        
        @media (max-width: 400px) {
            body {
                padding: 10px;
                padding-top: 30px;
            }
            
            .card {
                padding: 24px 16px;
                border-radius: 14px;
            }
            
            .header h3 {
                font-size: 18px;
            }
        }
        
        .btn-reset.loading {
            opacity: 0.8;
            pointer-events: none;
        }
        
        .btn-reset.loading i {
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
            color: #d6a84f;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon-wrapper">
                <i class="bi bi-shield-lock"></i>
            </div>
            <h3>Reset Password</h3>
            <p>Enter your new password below.</p>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo BASE_URL; ?>/api/auth" id="resetForm">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" required 
                           placeholder="Enter new password" minlength="8">
                    <span class="input-group-text" id="togglePassword" onclick="togglePasswordVisibility()">
                        <i class="bi bi-eye" id="togglePasswordIcon"></i>
                    </span>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-shield-check"></i></span>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                           placeholder="Confirm new password" minlength="8">
                </div>
            </div>
            
            <div class="password-requirements mb-3">
                <small>Password must contain:</small>
                <ul>
                    <li id="req-length"><i class="bi bi-circle"></i> At least 8 characters</li>
                    <li id="req-upper"><i class="bi bi-circle"></i> At least 1 uppercase letter</li>
                    <li id="req-lower"><i class="bi bi-circle"></i> At least 1 lowercase letter</li>
                    <li id="req-number"><i class="bi bi-circle"></i> At least 1 number</li>
                    <li id="req-special"><i class="bi bi-circle"></i> At least 1 special character (!@#$%)</li>
                </ul>
            </div>
            
            <button type="submit" class="btn btn-reset" id="resetBtn">
                <i class="bi bi-check-circle"></i> Reset Password
            </button>
        </form>
        
        <div class="text-center mt-3">
            <a href="<?php echo BASE_URL; ?>/login" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
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

        // Password validation
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        
        function validatePassword() {
            const val = password.value;
            
            const requirements = [
                { id: 'req-length', regex: /.{8,}/, msg: 'At least 8 characters' },
                { id: 'req-upper', regex: /[A-Z]/, msg: 'At least 1 uppercase letter' },
                { id: 'req-lower', regex: /[a-z]/, msg: 'At least 1 lowercase letter' },
                { id: 'req-number', regex: /[0-9]/, msg: 'At least 1 number' },
                { id: 'req-special', regex: /[!@#$%]/, msg: 'At least 1 special character' }
            ];
            
            let allValid = true;
            
            requirements.forEach(req => {
                const element = document.getElementById(req.id);
                const isValid = req.regex.test(val);
                if (isValid) {
                    element.className = 'valid';
                    element.innerHTML = '<i class="bi bi-check-circle-fill"></i> ' + req.msg;
                } else {
                    element.className = 'invalid';
                    element.innerHTML = '<i class="bi bi-circle"></i> ' + req.msg;
                    allValid = false;
                }
            });
            
            return allValid && val.length >= 8;
        }

        password.addEventListener('input', validatePassword);

        document.getElementById('resetForm').addEventListener('submit', function(e) {
            const passwordVal = password.value;
            const confirmVal = confirmPassword.value;
            
            if (!validatePassword()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Password Requirements',
                    text: 'Please make sure your password meets all requirements.',
                    confirmButtonColor: '#f6d365'
                });
                return;
            }
            
            if (passwordVal !== confirmVal) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Passwords Do Not Match',
                    text: 'Please make sure both passwords are the same.',
                    confirmButtonColor: '#dc3545'
                });
                return;
            }
            
            const btn = document.getElementById('resetBtn');
            btn.classList.add('loading');
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Resetting...';
        });

        if (window.innerWidth >= 768) {
            document.getElementById('password').focus();
        }

        window.addEventListener('load', function() {
            const btn = document.getElementById('resetBtn');
            btn.classList.remove('loading');
            btn.disabled = false;
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</body>
</html>
