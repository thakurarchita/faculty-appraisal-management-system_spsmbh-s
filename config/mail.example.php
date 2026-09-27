<?php
/**
 * Mailer Class - Professional Email Templates
 */

// Try to load PHPMailer
$phpmailer_path = __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';

if (file_exists($phpmailer_path)) {
    require_once $phpmailer_path;
    require_once __DIR__ . '/../vendor/PHPMailer/SMTP.php';
    require_once __DIR__ . '/../vendor/PHPMailer/Exception.php';
    define('USE_PHPMAILER', true);
} else {
    define('USE_PHPMAILER', false);
}

class Mailer {
    private $from_email = 'your-email@gmail.com';
    private $from_name = 'College Appraisal System';
    private $mail;
    private $use_phpmailer;
    public $config;
    
    public function __construct() {
        $this->use_phpmailer = USE_PHPMAILER && class_exists('PHPMailer\PHPMailer\PHPMailer');
        
        // ================================================================
        // GMAIL SMTP CONFIGURATION - UPDATE THESE VALUES
        // ================================================================
        
        $this->config = [
            'host' => 'smtp.gmail.com',
            'username' => 'your-email@gmail.com',
            'password' => 'your-app-password',
            'port' => 587,
            'encryption' => 'tls'
        ];
        
        $this->from_email = $this->config['username'];
        
        if ($this->use_phpmailer) {
            $this->initPHPMailer();
        }
    }
    
    public function getBaseUrl() {
        if (defined('BASE_URL') && preg_match('#^https?://#i', BASE_URL)) {
            return rtrim(BASE_URL, '/');
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $host = trim($_SERVER['SERVER_NAME'] ?? '');
        }
        if ($host === '') {
            $host = 'localhost';
        }

        $basePath = defined('BASE_URL') ? trim(BASE_URL, '/') : 'appraisal';
        return $protocol . '://' . $host . ($basePath !== '' ? '/' . $basePath : '');
    }
    
    private function initPHPMailer() {
        try {
            $this->mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            $this->mail->isSMTP();
            $this->mail->Host       = $this->config['host'];
            $this->mail->SMTPAuth   = true;
            $this->mail->Username   = $this->config['username'];
            $this->mail->Password   = $this->config['password'];
            $this->mail->Port       = $this->config['port'];
            $this->mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            
            $this->mail->setFrom($this->from_email, $this->from_name);
            $this->mail->isHTML(true);
            $this->mail->CharSet = 'UTF-8';
            
            $this->mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            error_log("PHPMailer initialized with Gmail SMTP");
            
        } catch (Exception $e) {
            $this->use_phpmailer = false;
            error_log("PHPMailer initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail($email, $name, $resetLink) {
        $subject = "Password Reset - College Appraisal System";
        
        $content = "
            <p class='greeting'>Dear {$name},</p>
            <p>You have requested to reset your password for the College Faculty Performance Appraisal System.</p>
            
            <div class='warning-box'>
                <strong>Security Notice:</strong> This link will expire in 1 hour.
            </div>
            
            <div class='text-center'>
                <a href='{$resetLink}' class='btn-primary'>Reset Password</a>
            </div>
            
            <p class='text-small mt-16'>If you did not request this, please ignore this email. Your password will remain unchanged.</p>
            
            <hr class='divider-line'>
            
            <p class='text-small'>
                Best regards,<br>
                <strong>College Appraisal System Team</strong>
            </p>
        ";
        
        $body = $this->getEmailWrapper($content);
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Send email using Gmail SMTP
     */
    public function sendEmail($to, $subject, $body) {
        if ($this->use_phpmailer) {
            try {
                $this->mail->clearAddresses();
                $this->mail->addAddress($to);
                $this->mail->Subject = $subject;
                $this->mail->Body = $body;
                $this->mail->AltBody = strip_tags($body);
                
                $result = $this->mail->send();
                error_log("Email sent via Gmail to: $to - Subject: $subject");
                return true;
            } catch (Exception $e) {
                error_log("Gmail Error: " . $this->mail->ErrorInfo);
                error_log("Exception: " . $e->getMessage());
            }
        }
        
        error_log("Email would be sent to: $to - Subject: $subject");
        return false;
    }
    
    // ===================== CLEAN EMAIL TEMPLATE =====================
    
    /**
     * Get clean email wrapper - matching the WhatsApp image theme
     */
    private function getEmailWrapper($content, $title = '') {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { 
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                    line-height: 1.6; 
                    color: #1a2332; 
                    margin: 0; 
                    padding: 0;
                    background: #f0f2f5;
                }
                .email-container { 
                    max-width: 580px; 
                    margin: 0 auto; 
                    padding: 20px; 
                }
                .email-wrapper {
                    background: #ffffff;
                    border-radius: 12px;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
                    overflow: hidden;
                }
                .email-header { 
                    background: #1a2634; 
                    padding: 18px 24px; 
                    text-align: center;
                }
                .email-header .logo-text { 
                    font-size: 16px; 
                    font-weight: 600; 
                    color: #ffffff; 
                    letter-spacing: -0.2px;
                }
                .email-header .logo-sub {
                    font-size: 11px;
                    color: #a0afbe;
                    display: block;
                    margin-top: 2px;
                    font-weight: 400;
                }
                .email-body { 
                    padding: 24px 28px; 
                }
                .email-footer { 
                    text-align: center; 
                    padding: 14px 24px; 
                    border-top: 1px solid #e9edf2; 
                    font-size: 11px; 
                    color: #6b7a8f; 
                    background: #ffffff;
                }
                .email-footer p {
                    margin: 3px 0;
                }
                .greeting {
                    font-size: 15px;
                    font-weight: 500;
                    color: #1a2634;
                    margin-bottom: 10px;
                }
                .text-muted {
                    color: #6b7a8f;
                    font-size: 14px;
                }
                .text-small {
                    font-size: 12px;
                    color: #6b7a8f;
                }
                .btn-primary {
                    display: inline-block;
                    padding: 8px 24px;
                    background: #1a2634;
                    color: #ffffff !important;
                    text-decoration: none;
                    border-radius: 6px;
                    font-size: 13px;
                    font-weight: 500;
                    transition: background 0.2s;
                }
                .btn-primary:hover {
                    background: #2d3748;
                }
                .task-box {
                    background: #f8f9fa;
                    padding: 14px 18px;
                    border-radius: 8px;
                    margin: 14px 0;
                }
                .task-box .task-title {
                    font-size: 15px;
                    font-weight: 600;
                    color: #1a2634;
                }
                .task-box .task-due {
                    font-size: 13px;
                    color: #6b7a8f;
                    margin-top: 4px;
                }
                .task-box .task-due strong {
                    color: #1a2634;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    padding: 4px 0;
                    font-size: 14px;
                }
                .info-row .label {
                    color: #4a5568;
                }
                .info-row .value {
                    color: #1a2634;
                    font-weight: 500;
                }
                .warning-box {
                    background: #fef9e7;
                    padding: 10px 14px;
                    border-radius: 6px;
                    margin: 14px 0;
                    border-left: 3px solid #b45309;
                    font-size: 13px;
                    color: #78350f;
                }
                .divider-line {
                    border: none;
                    border-top: 1px solid #e9edf2;
                    margin: 16px 0;
                }
                .text-center { text-align: center; }
                .mt-8 { margin-top: 8px; }
                .mt-16 { margin-top: 16px; }
                .mt-24 { margin-top: 24px; }
                .mb-8 { margin-bottom: 8px; }
                .mb-16 { margin-bottom: 16px; }
                .status-badge {
                    display: inline-block;
                    padding: 3px 14px;
                    border-radius: 4px;
                    font-weight: 500;
                    font-size: 13px;
                    color: #ffffff;
                }
                .status-approved { background: #0f7b3a; }
                .status-rejected { background: #b91c1c; }
                .status-pending { background: #b45309; }
                .feedback-box {
                    background: #f8f9fa;
                    padding: 10px 14px;
                    border-radius: 6px;
                    margin: 10px 0;
                    border-left: 3px solid #1a2634;
                }
                .feedback-box .feedback-label {
                    font-size: 12px;
                    color: #6b7a8f;
                    font-weight: 500;
                }
                .feedback-box .feedback-text {
                    font-size: 14px;
                    color: #1a2634;
                    margin-top: 4px;
                }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='email-wrapper'>
                    <div class='email-header'>
                        <span class='logo-text'>College Appraisal System</span>
                        <span class='logo-sub'>Faculty Performance Management</span>
                    </div>
                    <div class='email-body'>
                        {$content}
                    </div>
                    <div class='email-footer'>
                        <p>This is an automated notification. Please do not reply to this email.</p>
                        <p>&copy; 2026 College Appraisal System. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }
    
    /**
     * Welcome email for new users - Clean Theme
     */
    public function sendWelcomeEmail($email, $username, $password) {
        $subject = "Account Created - College Appraisal System";
        
        $content = "
            <p class='greeting'>Dear User,</p>
            <p>Your account has been created in the College Faculty Performance Appraisal System.</p>
            
            <div style='background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin: 14px 0;'>
                <div class='info-row'>
                    <span class='label'>Email Address</span>
                    <span class='value'>{$username}</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Temporary Password</span>
                    <span class='value'>{$password}</span>
                </div>
            </div>
            
            <div class='warning-box'>
                <strong>Important:</strong> Please change your password after your first login.
            </div>
            
            <div class='text-center mt-16'>
                <a href='{$this->getBaseUrl()}/login' class='btn-primary'>Access System</a>
            </div>
            
            <p class='text-small mt-16'>
                Best regards,<br>
                <strong>College Appraisal System Team</strong>
            </p>
        ";
        
        $body = $this->getEmailWrapper($content);
        $result = $this->sendEmail($email, $subject, $body);
        
        $_SESSION['temp_password'] = $password;
        $_SESSION['temp_username'] = $username;
        $_SESSION['temp_email'] = $email;
        
        return $result;
    }
    
    /**
     * Task assigned email with multiple tasks - Clean Theme
     */
    public function sendTaskAssignedEmail($email, $userName, $tasks, $assignedBy, $duration, $priority, $dueDate, $note = '') {
        $subject = "New Tasks Assigned - College Appraisal System";
        
        $taskListHtml = "";
        foreach ($tasks as $task) {
            $taskListHtml .= "
                <div style='background: #f8f9fa; padding: 8px 14px; margin: 6px 0; border-radius: 6px; border-left: 3px solid #1a2634;'>
                    <strong style='font-size: 14px; color: #1a2634;'>{$task['task_name']}</strong><br>
                    <span style='font-size: 12px; color: #6b7a8f;'>Due: " . ($task['due_date'] ?? 'Not specified') . "</span>
                </div>
            ";
        }
        
        $content = "
            <p class='greeting'>Dear {$userName},</p>
            <p>New tasks have been assigned to you by <strong>{$assignedBy}</strong>.</p>
            
            <div style='background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin: 14px 0;'>
                <div class='info-row'>
                    <span class='label'>Total Tasks</span>
                    <span class='value'>" . count($tasks) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Duration</span>
                    <span class='value'>{$duration}</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Priority</span>
                    <span class='value'>{$priority}</span>
                </div>
            </div>
            
            <p style='font-weight: 500; font-size: 14px; margin: 14px 0 8px;'>Task Details:</p>
            {$taskListHtml}
            
            " . ($note ? "
            <div class='warning-box'>
                <strong>Note:</strong><br>
                {$note}
            </div>
            " : "") . "
            
            <div class='text-center mt-16'>
                <a href='{$this->getBaseUrl()}/faculty/tasks' class='btn-primary'>View Tasks</a>
            </div>
            
            <p class='text-small mt-8'>Please login to view and complete your tasks.</p>
        ";
        
        $body = $this->getEmailWrapper($content);
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Single Task Assigned Email - Clean Theme (Matches WhatsApp image)
     */
    public function sendSingleTaskAssignedEmail($email, $taskTitle, $dueDate, $assignedBy) {
        $subject = "Task Assigned - {$taskTitle}";
        
        $content = "
            <p class='greeting'>Dear Faculty Member,</p>
            <p>A new task has been assigned to you by <strong>{$assignedBy}</strong>.</p>
            
            <div class='task-box'>
                <div class='task-title'>{$taskTitle}</div>
                <div class='task-due'><strong>Due Date:</strong> {$dueDate}</div>
            </div>
            
            <div class='text-center mt-16'>
                <a href='{$this->getBaseUrl()}/faculty/tasks' class='btn-primary'>View Task</a>
            </div>
            
            <p class='text-small mt-8'>Please login to view and complete the task.</p>
        ";
        
        $body = $this->getEmailWrapper($content);
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Task Evaluated Email (Approved/Rejected) - Clean Theme, NO MARKS
     */
    public function sendTaskEvaluatedEmail($email, $taskTitle, $status, $remarks = null) {
        $statusColor = $status == 'Approved' ? '#0f7b3a' : '#b91c1c';
        $statusText = $status == 'Approved' ? 'Approved' : 'Rejected';
        $subject = "Task {$statusText} - {$taskTitle}";
        
        $remarksHtml = $remarks ? "
            <div class='feedback-box'>
                <div class='feedback-label'>Feedback from Principal</div>
                <div class='feedback-text'>" . nl2br(htmlspecialchars($remarks)) . "</div>
            </div>
        " : "";
        
        $content = "
            <p class='greeting'>Dear Faculty Member,</p>
            <p>Your task has been reviewed by the Principal.</p>
            
            <div style='background: #f8f9fa; padding: 16px 20px; border-radius: 8px; margin: 14px 0;'>
                <div style='font-size: 15px; font-weight: 600; color: #1a2634; margin-bottom: 10px;'>{$taskTitle}</div>
                <div class='status-badge status-" . strtolower($status) . "'>Status: {$statusText}</div>
                {$remarksHtml}
            </div>
            
            <div style='background: " . ($status == 'Approved' ? '#e8f5e9' : '#ffebee') . "; padding: 10px 14px; border-radius: 6px; margin: 14px 0; border-left: 3px solid " . ($status == 'Approved' ? '#2e7d32' : '#c62828') . ";'>
                <strong>Next Steps:</strong>
                " . ($status == 'Approved' ? 
                    '✅ Your task has been approved. You can view the feedback in the system.' : 
                    '❌ Your task needs revision. Please check the feedback and resubmit.') . "
            </div>
            
            <div class='text-center mt-16'>
                <a href='{$this->getBaseUrl()}/faculty/tasks' class='btn-primary'>View Details</a>
            </div>
            
            <p class='text-small mt-8'>Please login to view the complete evaluation and feedback.</p>
        ";
        
        $body = $this->getEmailWrapper($content);
        return $this->sendEmail($email, $subject, $body);
    }
    
    /**
     * Task Submission Notification to Principal - Clean Theme
     */
    public function sendTaskSubmissionNotification($principalEmail, $facultyName, $taskTitle) {
        $subject = "Task Submitted for Review - {$taskTitle}";
        
        $content = "
            <p class='greeting'>Dear Principal,</p>
            <p>A task has been submitted for your review.</p>
            
            <div style='background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin: 14px 0;'>
                <div class='info-row'>
                    <span class='label'>Faculty</span>
                    <span class='value'>{$facultyName}</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Task</span>
                    <span class='value'>{$taskTitle}</span>
                </div>
            </div>
            
            <div class='text-center mt-16'>
                <a href='{$this->getBaseUrl()}/principal/evaluations' class='btn-primary'>Review Task</a>
            </div>
            
            <p class='text-small mt-8'>Please login to review and evaluate the submission.</p>
        ";
        
        $body = $this->getEmailWrapper($content);
        return $this->sendEmail($principalEmail, $subject, $body);
    }
}
?>
