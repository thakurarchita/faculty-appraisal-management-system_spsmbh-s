<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    header('Location: ' . getDashboardUrl());
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Forgot Password - Appraisal System</title>
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
        
        .btn-send {
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
        
        .btn-send:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(253, 160, 133, 0.4);
            color: white;
        }
        
        .btn-send:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .btn-send i {
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
        
        .alert-info {
            background: #ebf8ff;
            border-left-color: #63b3ed;
            color: #2a69ac;
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
            
            .btn-send {
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
        
        .btn-send.loading {
            opacity: 0.8;
            pointer-events: none;
        }
        
        .btn-send.loading i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <div class="icon-wrapper">
                <i class="bi bi-key"></i>
            </div>
            <h3>Forgot Password</h3>
            <p>Enter your email address and we'll send you a link to reset your password.</p>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo BASE_URL; ?>/api/auth" id="forgotForm">
            <input type="hidden" name="action" value="forgot_password">
            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control" id="email" name="email" required 
                       placeholder="Enter your registered email" autocomplete="email" autofocus>
            </div>
            <button type="submit" class="btn btn-send" id="sendBtn">
                <i class="bi bi-envelope"></i> Send Reset Link
            </button>
        </form>
        
        <div class="text-center mt-3">
            <a href="<?php echo BASE_URL; ?>/login" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>

    <script>
        document.getElementById('forgotForm').addEventListener('submit', function(e) {
            const btn = document.getElementById('sendBtn');
            const icon = btn.querySelector('i');
            
            btn.classList.add('loading');
            icon.className = 'bi bi-arrow-repeat';
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sending...';
        });

        if (window.innerWidth >= 768) {
            document.getElementById('email').focus();
        }

        window.addEventListener('load', function() {
            const btn = document.getElementById('sendBtn');
            btn.classList.remove('loading');
            btn.disabled = false;
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
