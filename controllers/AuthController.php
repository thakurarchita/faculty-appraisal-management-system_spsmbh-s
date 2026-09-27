<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mail.php';

$userModel = new User();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ============================================
    // LOGIN — with brute-force protection
    // ============================================
    if ($action == 'login') {
        $rawEmail = trim($_POST['email'] ?? '');
        // Check if email was obfuscated/encoded from frontend (to prevent plain-text email in network tab)
        if (strpos($rawEmail, 'enc:') === 0) {
            $decoded = base64_decode(substr($rawEmail, 4));
            if ($decoded) {
                $rawEmail = $decoded;
            }
        } elseif (base64_decode($rawEmail, true) && filter_var(base64_decode($rawEmail), FILTER_VALIDATE_EMAIL)) {
            $rawEmail = base64_decode($rawEmail);
        }
        $email    = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$email || empty($password)) {
            $_SESSION['error'] = 'Please provide a valid email and password.';
            header('Location: ' . BASE_URL . '/login');
            exit();
        }

        $db = getDB();

        // --- Ensure login_attempts table exists ---
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            email TEXT NOT NULL,
            attempt_count INTEGER DEFAULT 1,
            last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_login_ip_email ON login_attempts(ip, email)");

        // --- Check lockout ---
        $lockoutMinutes = 15;
        $maxAttempts    = 5;
        $stmt = $db->prepare(
            "SELECT attempt_count, last_attempt FROM login_attempts
             WHERE ip = ? AND email = ?
               AND last_attempt > datetime('now', '-{$lockoutMinutes} minutes')"
        );
        $stmt->execute([$ip, $email]);
        $attemptRow = $stmt->fetch();

        if ($attemptRow && $attemptRow['attempt_count'] >= $maxAttempts) {
            $_SESSION['error'] = "Too many failed login attempts. Please wait {$lockoutMinutes} minutes and try again.";
            header('Location: ' . BASE_URL . '/login');
            exit();
        }

        // --- Attempt authentication ---
        if ($userModel->authenticate($email, $password)) {
            // Clear failed attempts on success
            $db->prepare("DELETE FROM login_attempts WHERE ip = ? AND email = ?")->execute([$ip, $email]);

            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Check if there was an intended redirect URL (e.g. from an email link)
            if (!empty($_SESSION['redirect_after_login'])) {
                $redirectUrl = $_SESSION['redirect_after_login'];
                unset($_SESSION['redirect_after_login']);
                if (strpos($redirectUrl, '/') === 0 || strpos($redirectUrl, BASE_URL) === 0) {
                    header('Location: ' . $redirectUrl);
                    exit();
                }
            }

            $role = $_SESSION['role'] ?? '';
            switch ($role) {
                case 'Admin':     header('Location: ' . BASE_URL . '/admin/dashboard');     break;
                case 'Principal': header('Location: ' . BASE_URL . '/principal/dashboard'); break;
                case 'Faculty':   header('Location: ' . BASE_URL . '/faculty/dashboard');   break;
                default:          header('Location: ' . BASE_URL . '/login');
            }
            exit();
        } else {
            // Record failed attempt (upsert)
            $db->prepare(
                "INSERT INTO login_attempts (ip, email, attempt_count, last_attempt)
                 VALUES (?, ?, 1, CURRENT_TIMESTAMP)
                 ON CONFLICT(ip, email) DO UPDATE SET
                     attempt_count = CASE
                         WHEN last_attempt < datetime('now', '-{$lockoutMinutes} minutes')
                         THEN 1
                         ELSE attempt_count + 1
                     END,
                     last_attempt = CURRENT_TIMESTAMP"
            )->execute([$ip, $email]);

            $remaining = $maxAttempts - (($attemptRow['attempt_count'] ?? 0) + 1);
            $remaining = max(0, $remaining);
            $_SESSION['error'] = 'Invalid email or password.' . ($remaining > 0 ? " ({$remaining} attempts remaining)" : " Account temporarily locked.");
            header('Location: ' . BASE_URL . '/login');
            exit();
        }
    }

    
    // ============================================
    // CHANGE PASSWORD (Existing - Unchanged)
    // ============================================
    if ($action == 'change_password') {
        requireLogin();
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        $user = $userModel->getUserById($_SESSION['user_id']);
        
        if (!verifyPassword($currentPassword, $user['password'])) {
            $_SESSION['error'] = 'Current password is incorrect';
        } elseif ($newPassword !== $confirmPassword) {
            $_SESSION['error'] = 'Passwords do not match';
        } elseif (strlen($newPassword) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters';
        } else {
            if ($userModel->changePassword($_SESSION['user_id'], $newPassword)) {
                $_SESSION['is_first_login'] = 0;
                $_SESSION['success'] = 'Password changed successfully';
            } else {
                $_SESSION['error'] = 'Failed to change password';
            }
        }
        
        header('Location: ' . $_SERVER['HTTP_REFERER']);
        exit();
    }
    
    // ============================================
    // FORGOT PASSWORD (NEW)
    // ============================================
    if ($action == 'forgot_password') {
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        
        if (!$email) {
            $_SESSION['error'] = 'Please enter your email address.';
            header('Location: ' . BASE_URL . '/forgot-password');
            exit();
        }
        
        // Check if email exists
        $db = Database::getInstance()->getConnection();
        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            is_used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        $stmt = $db->prepare("SELECT id, full_name FROM users WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $_SESSION['error'] = 'No account found with this email address.';
            header('Location: ' . BASE_URL . '/forgot-password');
            exit();
        }
        
        // Generate reset token
        $token = bin2hex(random_bytes(32));
        // SQLite datetime('now') uses UTC, so store the expiry in the same timezone.
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);
        
        // Delete any existing tokens for this user
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        
        // Insert new token
        $stmt = $db->prepare("
            INSERT INTO password_resets (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user['id'], $token, $expiresAt]);
        
        // Send reset email
        try {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $resetLink = $protocol . $host . rtrim(BASE_URL, '/') . "/reset-password?token=" . rawurlencode($token) . '&email=' . rawurlencode($email);
            
            $mailer = new Mailer();
            $emailSent = $mailer->sendPasswordResetEmail($email, $user['full_name'], $resetLink);
            
            if ($emailSent) {
                $_SESSION['success'] = 'Password reset link has been sent to your email. Please check your inbox.';
            } else {
                $_SESSION['error'] = 'Failed to send reset email. Please try again later.';
            }
        } catch (Exception $e) {
            error_log("Password reset error: " . $e->getMessage());
            $_SESSION['error'] = 'Failed to send reset email. Please try again later.';
        }
        
        header('Location: ' . BASE_URL . '/forgot-password');
        exit();
    }
    
    // ============================================
    // RESET PASSWORD (NEW)
    // ============================================
    if ($action == 'reset_password') {
        $token = $_POST['token'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($token) || empty($email) || empty($password) || empty($confirmPassword)) {
            $_SESSION['error'] = 'All fields are required.';
            header("Location: " . BASE_URL . "/reset-password?token={$token}&email=" . urlencode($email));
            exit();
        }
        
        if ($password !== $confirmPassword) {
            $_SESSION['error'] = 'Passwords do not match.';
            header("Location: " . BASE_URL . "/reset-password?token={$token}&email=" . urlencode($email));
            exit();
        }
        
        // Validate password strength
        if (strlen($password) < 8) {
            $_SESSION['error'] = 'Password must be at least 8 characters long.';
            header("Location: " . BASE_URL . "/reset-password?token={$token}&email=" . urlencode($email));
            exit();
        }
        
        $db = Database::getInstance()->getConnection();

        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token TEXT NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            is_used INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // Verify token
        $stmt = $db->prepare("
            SELECT * FROM password_resets 
            WHERE token = ? 
            AND expires_at > datetime('now')
            AND is_used = 0
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reset) {
            $_SESSION['error'] = 'Invalid or expired password reset link. Please request a new one.';
            header('Location: ' . BASE_URL . '/forgot-password');
            exit();
        }
        
        // Verify email matches
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND email = ?");
        $stmt->execute([$reset['user_id'], $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $_SESSION['error'] = 'Invalid password reset request.';
            header('Location: ' . BASE_URL . '/forgot-password');
            exit();
        }
        
        // Update password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("
            UPDATE users 
            SET password = ?, is_first_login = 0, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$hashedPassword, $user['id']]);
        
        // Mark token as used
        $stmt = $db->prepare("UPDATE password_resets SET is_used = 1 WHERE id = ?");
        $stmt->execute([$reset['id']]);
        
        // Log activity
        logActivity($user['id'], 'Password Reset', 'User reset their password successfully.');
        
        $_SESSION['success'] = 'Your password has been reset successfully. Please login with your new password.';
        header('Location: ' . BASE_URL . '/login');
        exit();
    }
}
?>
