<?php
// Router for PHP built-in web server (php -S localhost:8000 router.php)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve existing files and directories directly (e.g. assets, css, js, images)
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // Let PHP built-in server serve the static file directly
}

// Clean route dispatch table
$routes = [
    '/'                              => __DIR__ . '/index.php',
    '/login'                         => __DIR__ . '/index.php',
    '/logout'                        => __DIR__ . '/logout.php',
    '/forgot-password'               => __DIR__ . '/Views/auth/forgot-password.php',
    '/reset-password'                => __DIR__ . '/Views/auth/reset-password.php',
    
    // Admin Routes
    '/admin'                         => __DIR__ . '/Views/admin/dashboard.php',
    '/admin/dashboard'               => __DIR__ . '/Views/admin/dashboard.php',
    '/admin/users'                   => __DIR__ . '/Views/admin/users.php',
    '/admin/my-tasks'                => __DIR__ . '/Views/admin/my_tasks.php',
    '/admin/task-status'             => __DIR__ . '/Views/admin/task_status.php',
    '/admin/templates'               => __DIR__ . '/Views/admin/templates.php',
    '/admin/settings'                => __DIR__ . '/Views/admin/settings.php',
    '/admin/assign-task'             => __DIR__ . '/Views/principal/assign_task.php',
    
    // Faculty Routes
    '/faculty'                       => __DIR__ . '/Views/faculty/dashboard.php',
    '/faculty/dashboard'             => __DIR__ . '/Views/faculty/dashboard.php',
    '/faculty/tasks'                 => __DIR__ . '/Views/faculty/tasks.php',
    '/faculty/profile'               => __DIR__ . '/Views/faculty/profile.php',
    
    // Principal Routes
    '/principal'                     => __DIR__ . '/Views/principal/dashboard.php',
    '/principal/dashboard'           => __DIR__ . '/Views/principal/dashboard.php',
    '/principal/evaluations'         => __DIR__ . '/Views/principal/evaluations.php',
    '/principal/assign-task'         => __DIR__ . '/Views/principal/assign_task.php',
    '/principal/settings'            => __DIR__ . '/Views/principal/settings.php',
    '/principal/reports'             => __DIR__ . '/Views/principal/reports.php',
    '/principal/pdf-marksheet'       => __DIR__ . '/Views/principal/pdf_marksheet.php',
    '/principal/pdf-summary-marksheet' => __DIR__ . '/Views/principal/pdf_summary_marksheet.php',
    '/principal/task-status'         => __DIR__ . '/Views/admin/task_status.php',
    
    // API / Controller Routes
    '/api/auth'                      => __DIR__ . '/controllers/AuthController.php',
    '/api/users'                     => __DIR__ . '/controllers/UserController.php',
    '/api/tasks'                     => __DIR__ . '/controllers/TaskController.php',
    '/api/admin-tasks'               => __DIR__ . '/controllers/AdminTaskController.php',
    '/api/faculty-tasks'             => __DIR__ . '/controllers/FacultyTaskController.php',
    '/api/principal'                 => __DIR__ . '/controllers/PrincipalController.php',
    '/api/reports'                   => __DIR__ . '/controllers/ReportController.php',
    '/api/settings'                  => __DIR__ . '/controllers/SettingsController.php'
];

$trimmedUri = rtrim($uri, '/');
if ($trimmedUri === '') {
    $trimmedUri = '/';
}

if (isset($routes[$trimmedUri])) {
    require $routes[$trimmedUri];
    exit();
}

// Check for dynamic public profile route: /faculty/slug
// Excludes static routes already handled above
if (preg_match('/^\/faculty\/([a-zA-Z0-9-]+)$/', $trimmedUri, $matches)) {
    $_GET['slug'] = $matches[1];
    require __DIR__ . '/Views/faculty/public_profile.php';
    exit();
}

// Fallback to index.php
require __DIR__ . '/index.php';
