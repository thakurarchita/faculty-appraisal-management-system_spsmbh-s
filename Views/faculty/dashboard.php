<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/faculty/dashboard');
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is faculty
if ($_SESSION['role'] !== 'Faculty') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../models/Task.php';

$taskModel = new Task();
$userId = $_SESSION['user_id'];

// Get task statistics
$pendingTasks = $taskModel->getTasksByUser($userId, 'Pending');
$inProgressTasks = $taskModel->getTasksByUser($userId, 'In Progress');
$submittedTasks = $taskModel->getTasksByUser($userId, 'Submitted');
$completedTasks = $taskModel->getTasksByUser($userId, 'Approved');
$rejectedTasks = $taskModel->getTasksByUser($userId, 'Rejected');

// Get all tasks for activity feed
$allTasks = $taskModel->getTasksByUser($userId);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Faculty Workspace</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
    
    <style>

        :root {
            --primary: #12304a;
            --primary-light: #1f5278;
            --primary-dark: #0b2033;
            --secondary: #d6a84f;
            --dark: #0b2033;
            --dark-light: #12304a;
            --bg-body: #f4f7fa;
            --bg-card: #FFFFFF;
            --text-main: #263746;
            --text-muted: #687887;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.03);
            
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        
        body {
            background-color: var(--bg-body);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6, .brand-text {
            font-family: 'Outfit', sans-serif;
        }

        /* Top Navigation */
        .topbar {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            position: sticky;
            top: 0;
            z-index: 1020;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dark);
        }

        .topbar-brand .icon-box {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .topbar-nav {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .topbar-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .topbar-nav a:hover, .topbar-nav a.active {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.08);
        }

        .workspace {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Completion Bar */
        .completion-banner {
            background: linear-gradient(135deg, var(--dark), var(--dark-light));
            border-radius: var(--radius-lg);
            padding: 24px;
            color: white;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            overflow: hidden;
        }
        .completion-banner::after {
            content: ''; position: absolute; right: 0; top: 0;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(214,168,79,0.28) 0%, rgba(0,0,0,0) 70%);
            transform: translate(20%, -30%); pointer-events: none;
        }
        
        .progress-circle {
            position: relative;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: conic-gradient(var(--secondary) <?php echo $profileCompletion; ?>%, rgba(255,255,255,0.1) 0);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }
        .progress-circle::before {
            content: ''; position: absolute; inset: 6px;
            background: var(--dark-light); border-radius: 50%;
        }
        .progress-text {
            position: relative; font-weight: 700; font-size: 1.2rem; font-family: 'Outfit';
        }

        /* Profile Layout */
        .profile-header-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 30px;
            margin-bottom: 24px;
            position: relative;
            border: 1px solid #E2E8F0;
        }
        
        .profile-avatar-wrapper {
            position: relative;
            width: 120px; height: 120px;
            margin-right: 30px;
            flex-shrink: 0;
        }
        
        .profile-avatar {
            width: 100%; height: 100%;
            border-radius: 20px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: var(--shadow-md);
            background: #F1F5F9;
        }
        
        .avatar-edit-btn {
            position: absolute;
            bottom: -10px; right: -10px;
            background: var(--primary);
            color: white;
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 3px solid white;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }
        .avatar-edit-btn:hover { background: var(--primary-dark); transform: scale(1.05); }

        .section-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #E2E8F0;
        }
        
        .section-title {
            font-family: 'Outfit';
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid #F1F5F9;
        }

        /* Documents Grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }
        
        .doc-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
            position: relative;
        }
        .doc-card:hover { border-color: var(--primary-light); box-shadow: var(--shadow-md); transform: translateY(-3px); }
        
        .doc-preview {
            height: 140px;
            background: #E2E8F0;
            position: relative;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .doc-preview img { width: 100%; height: 100%; object-fit: cover; }
        
        .doc-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center; opacity: 0; transition: 0.2s; gap: 10px;
        }
        .doc-card:hover .doc-overlay { opacity: 1; }
        
        .doc-info { padding: 12px 15px; }
        .doc-name { font-weight: 600; font-size: 0.95rem; color: var(--dark); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .add-doc-card {
            background: rgba(79, 70, 229, 0.05);
            border: 2px dashed rgba(79, 70, 229, 0.3);
            border-radius: 12px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            min-height: 200px; cursor: pointer; transition: all 0.2s; color: var(--primary); text-align: center; padding: 20px;
        }
        .add-doc-card:hover { background: rgba(79, 70, 229, 0.1); border-color: var(--primary); }
        
        /* Cropper container */
        .cropper-container-wrapper { width: 100%; max-height: 60vh; background: #000; overflow: hidden; }

        .custom-nav-pills .nav-link {
            border-radius: 8px;
            color: var(--text-muted);
            font-weight: 500;
            padding: 10px 20px;
        }
        .custom-nav-pills .nav-link.active {
            background: var(--dark);
            color: white;
            box-shadow: var(--shadow-sm);
        }
    

        /* Specific overrides for dashboard */
        .workspace {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
    

        /* Workflow Guide */
        .workflow-guide {
            background: var(--dark);
            border-radius: var(--radius-lg);
            padding: 30px;
            color: white;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
        }
        
        .workflow-header {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .workflow-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .step-item {
            display: flex;
            gap: 15px;
            align-items: flex-start;
        }

        .step-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .step-title a {
            color: white;
            text-decoration: none;
            font-weight: 600;
        }

        .step-title a:hover {
            color: var(--primary);
        }

        .step-desc {
            color: rgba(255, 255, 255, 0.7);
        }

        /* Card */
        .card {
            border-radius: 12px;
            border: none;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            border-radius: 12px 12px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
            background: white;
            border-bottom: 2px solid #d6a84f;
        }
        
        .card-header h5 {
            margin: 0;
            font-size: 1.1rem;
            color: #12304a;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Progress Rings/Bars */
        .progress {
            height: 10px;
            border-radius: 5px;
            margin-top: 8px;
            background-color: #ecf0f1;
        }
        
        .progress-bar {
            border-radius: 5px;
        }

        .bg-success { background-color: #27ae60 !important; }
        .bg-info { background-color: #3498db !important; }
        .bg-warning { background-color: #d6a84f !important; }
        .bg-danger { background-color: #e74c3c !important; }
        
        /* Table Styles */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            border-top: none;
            border-bottom-width: 1px;
            color: #7f8c8d;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }
        
        .table td {
            vertical-align: middle;
            color: #12304a;
        }
        
        .badge {
            padding: 6px 10px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        
        /* Alerts */
        .alert {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
        }
        
        .alert-info {
            background-color: #e8f4f8;
            color: #31708f;
        }



        /* Guided Banner */
        .guided-banner {
            border-radius: var(--radius-lg);
            padding: 24px 30px;
            color: white;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }
        
        .guided-steps {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 20px;
            position: relative;
            z-index: 2;
        }
        
        .guided-step-item {
            display: flex;
            align-items: center;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 12px;
            text-decoration: none !important;
            color: white;
            transition: all 0.3s ease;
        }
        
        .guided-step-item:hover {
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
            color: white;
        }
        
        .guided-step-num {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: bold;
            margin-right: 15px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        
        .guided-step-text {
            display: flex;
            flex-direction: column;
        }
        
        .guided-step-text strong {
            font-size: 0.95rem;
            margin-bottom: 2px;
            color: white;
        }
        
        .guided-step-text small {
            font-size: 0.75rem;
            opacity: 0.8;
            color: rgba(255,255,255,0.9);
        }



        .user-profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid #E2E8F0;
            padding: 6px 16px 6px 6px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-profile-btn:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-light);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Institutional theme alignment */
        :root {
            --primary: #12304a;
            --primary-light: #1f5278;
            --primary-dark: #0b2033;
            --secondary: #d6a84f;
            --dark: #0b2033;
            --dark-light: #12304a;
            --bg-body: #f4f7fa;
            --text-main: #263746;
            --radius-md: 8px;
            --radius-lg: 10px;
            --radius-xl: 12px;
        }
        .topbar { background: #fff; border-bottom-color: #e2e8f0; padding: 12px 24px; }
        .topbar-brand { color: #0b2033; }
        .topbar-brand .icon-box { background: linear-gradient(135deg, #12304a, #d6a84f); box-shadow: 0 4px 10px rgba(18, 48, 74, 0.25); }
        .topbar-nav a:hover, .topbar-nav a.active { color: #0b2033; background: rgba(214, 168, 79, 0.16); }
        .btn-smart-primary, .btn-primary { background-color: #12304a; border-color: #12304a; }
        .btn-smart-primary:hover, .btn-primary:hover { background-color: #0b2033; border-color: #0b2033; }
        .text-primary { color: #12304a !important; }
        .bg-primary { background-color: #12304a !important; }
        .border-primary { border-color: #12304a !important; }
        .recent-task-item {
            padding: 16px 20px;
            border-bottom: 1px solid #edf0f3;
            transition: background-color 0.2s ease;
        }
        .recent-task-item:last-child { border-bottom: 0; }
        .recent-task-item:hover { background: #f8fafc; }
        .recent-task-item .task-title {
            color: #0b2033;
            font-size: 0.96rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .recent-task-item .task-meta {
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.7;
        }
        .recent-task-item .task-meta i { color: #12304a; }
        .quick-action-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 10px;
            color: #0b2033;
            text-decoration: none;
            border-bottom: 1px solid #edf0f3;
            transition: background-color 0.2s ease, padding-left 0.2s ease;
        }
        .quick-action-btn:last-child { border-bottom: 0; }
        .quick-action-btn:hover { background: #f8fafc; padding-left: 14px; color: #0b2033; }
        .quick-action-btn > i {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            background: rgba(241, 196, 15, 0.18);
            color: #8a6d00;
            flex-shrink: 0;
        }
        .status-badge { white-space: nowrap; }
        @media (max-width: 575.98px) {
            .recent-task-item > .d-flex { gap: 10px; }
            .recent-task-item .task-meta span { display: block; margin-right: 0 !important; }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar">
        <a href="<?php echo BASE_URL; ?>/faculty/dashboard" class="topbar-brand">
            <div class="icon-box"><i class="bi bi-mortarboard-fill"></i></div>
            <div>
                <h5 class="mb-0 fw-bold" style="font-size: 1.1rem;">Appraisal System</h5>
                <small class="text-muted" style="font-size: 0.75rem;">Faculty Workspace</small>
            </div>
        </a>
        <div class="d-none d-md-flex topbar-nav">
            <a href="<?php echo BASE_URL; ?>/faculty/dashboard" class="active"><i class="bi bi-grid me-1"></i> Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/faculty/tasks"><i class="bi bi-card-checklist me-1"></i> My Tasks</a>
            <a href="<?php echo BASE_URL; ?>/faculty/profile" ><i class="bi bi-person me-1"></i> Profile</a>
        </div>
        
        <div class="d-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="user-profile-btn" data-bs-toggle="dropdown">
                    <div class="user-avatar">
                        <?php 
                        // Try to get user info if not already loaded
                        if (!isset($user) && isset($profileModel)) {
                            $user = $profileModel->getProfile($_SESSION['user_id']);
                        } else if (!isset($user)) {
                            $stmt = getDB()->prepare("SELECT profile_photo, full_name FROM users WHERE id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $user = $stmt->fetch();
                        }
                        
                        if(!empty($user['profile_photo'])): ?>
                            <img src="<?php echo BASE_URL; ?>/assets/uploads/profiles/<?php echo $user['profile_photo']; ?>" class="rounded-circle w-100 h-100" style="object-fit:cover">
                        <?php else: ?>
                            <i class="bi bi-person-fill"></i>
                        <?php endif; ?>
                    </div>
                    <span class="d-none d-md-block fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                    <i class="bi bi-chevron-down text-muted ms-1" style="font-size:0.8rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="border-radius: 12px; min-width: 200px;">
                    <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/faculty/profile"><i class="bi bi-person me-2 text-muted"></i> My Profile</a></li>
                    <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/faculty/tasks"><i class="bi bi-list-task me-2 text-muted"></i> My Tasks</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?php echo BASE_URL; ?>/logout"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
            <a href="<?php echo BASE_URL; ?>/logout" class="btn btn-light rounded-circle text-danger d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border: 1px solid #fee2e2;" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    
    </nav>
    
    <div class="workspace">
        

    <!-- Main content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 pb-3 mb-3 border-bottom">
                <div>
                    <h1 class="h2 mb-0">Faculty Dashboard</h1>
                    <p class="text-muted small mb-0">Welcome back, <?php echo $_SESSION['full_name']; ?></p>
                </div>
                <div class="btn-toolbar mt-2 mt-sm-0">
                    <span class="notification-badge">
                        <i class="bi bi-bell me-1"></i> 
                        <?php echo getUnreadNotificationsCount($_SESSION['user_id']); ?>
                    </span>
                </div>
            </div>

            <!-- Guided Faculty Workflow Banner -->
            <div class="guided-banner fade-in" style="background: linear-gradient(135deg, #0b2033 0%, #12304a 100%);">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h5 class="mb-1 fw-bold text-white"><i class="bi bi-mortarboard-fill me-2 text-warning"></i>Faculty Appraisal Workflow Guide</h5>
                        <p class="mb-0 small text-light opacity-75">Submit task evidence and track your yearly appraisal progress step-by-step.</p>
                    </div>
                    <a href="<?php echo BASE_URL; ?>/faculty/tasks" class="btn btn-warning btn-sm fw-bold">
                        <i class="bi bi-upload me-1"></i> Go to My Tasks
                    </a>
                </div>
                <div class="guided-steps">
                    <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=Pending" class="guided-step-item">
                        <div class="guided-step-num" style="background: #d6a84f; color: #0b2033;">1</div>
                        <div class="guided-step-text">
                            <strong>Review Tasks</strong>
                            <small><?php echo count($pendingTasks); ?> Pending acceptance</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=In Progress" class="guided-step-item">
                        <div class="guided-step-num" style="background: #3498db;">2</div>
                        <div class="guided-step-text">
                            <strong>Submit Evidence</strong>
                            <small><?php echo count($inProgressTasks); ?> In progress</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=Submitted" class="guided-step-item">
                        <div class="guided-step-num" style="background: #e67e22;">3</div>
                        <div class="guided-step-text">
                            <strong>Under Evaluation</strong>
                            <small><?php echo count($submittedTasks); ?> Awaiting review</small>
                        </div>
                    </a>
                    <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=Approved" class="guided-step-item">
                        <div class="guided-step-num" style="background: #27ae60;">4</div>
                        <div class="guided-step-text">
                            <strong>Completed</strong>
                            <small><?php echo count($completedTasks); ?> Approved tasks</small>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div class="row mt-2 fade-in">
                <!-- Left Column - Recent Tasks -->
                <div class="col-12 col-lg-8 mb-4">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="bi bi-clock-history me-2" style="color: #d6a84f;"></i>Recent Tasks</h5>
                            <a href="<?php echo BASE_URL; ?>/faculty/tasks" class="btn btn-sm btn-outline-secondary">View All</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (count($allTasks) > 0): ?>
                                <?php 
                                $recentTasks = array_slice($allTasks, 0, 5);
                                foreach ($recentTasks as $task): 
                                    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $task['status']));
                                ?>
                                    <div class="recent-task-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <div class="task-title"><?php echo htmlspecialchars($task['task_title']); ?></div>
                                                <div class="task-meta">
                                                    <span class="me-2"><i class="bi bi-tag"></i> <?php echo htmlspecialchars($task['category_name']); ?></span>
                                                    <span class="me-2"><i class="bi bi-calendar"></i> <?php echo date('d M Y', strtotime($task['created_at'])); ?></span>
                                                    <?php if (!empty($task['due_date'])): ?>
                                                        <span><i class="bi bi-clock"></i> Due: <?php echo date('d M Y', strtotime($task['due_date'])); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo $task['status']; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                    No tasks assigned yet.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Right Column - Overview & Quick Actions -->
                <div class="col-12 col-lg-4 mb-4">
                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="bi bi-lightbulb me-2" style="color: #d6a84f;"></i>Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=Pending" class="quick-action-btn">
                                <i class="bi bi-eye"></i>
                                <div>
                                    <div class="fw-semibold">Review Pending Tasks</div>
                                    <small class="text-muted"><?php echo count($pendingTasks); ?> tasks waiting</small>
                                </div>
                            </a>
                            <a href="<?php echo BASE_URL; ?>/faculty/tasks?status=In Progress" class="quick-action-btn">
                                <i class="bi bi-upload"></i>
                                <div>
                                    <div class="fw-semibold">Submit Evidence</div>
                                    <small class="text-muted"><?php echo count($inProgressTasks); ?> tasks in progress</small>
                                </div>
                            </a>
                            <a href="<?php echo BASE_URL; ?>/faculty/profile" class="quick-action-btn">
                                <i class="bi bi-person-gear"></i>
                                <div>
                                    <div class="fw-semibold">Update Profile</div>
                                    <small class="text-muted">Edit personal details</small>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        $(document).ready(function() {
            // Sidebar Toggle
            const sidebar = $('#sidebar');
            const overlay = $('#sidebarOverlay');
            const toggleBtn = $('#sidebarToggle');
            
            function toggleSidebar() {
                sidebar.toggleClass('show');
                overlay.toggleClass('active');
                document.body.style.overflow = sidebar.hasClass('show') ? 'hidden' : '';
            }
            
            toggleBtn.on('click', toggleSidebar);
            overlay.on('click', toggleSidebar);
            
            // Close sidebar on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.hasClass('show')) {
                    toggleSidebar();
                }
            });
            
            // Handle window resize - close sidebar on large screens
            $(window).on('resize', function() {
                if ($(window).width() >= 768 && sidebar.hasClass('show')) {
                    sidebar.removeClass('show');
                    overlay.removeClass('active');
                    document.body.style.overflow = '';
                }
            });
            
        });
    </script>
</body>
</html>
