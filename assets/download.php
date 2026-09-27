<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

// Get file name and action
$file = $_GET['file'] ?? '';
$action = $_GET['action'] ?? 'view';

if (empty($file)) {
    die('File not specified');
}

// Security: Prevent directory traversal
$file = basename($file);

// Get the project root path
$project_root = dirname(__DIR__);

// Keep compatibility with both submission upload locations used by the app.
$upload_paths = [
    $project_root . '/assets/uploads/tasks/',
    $project_root . '/assets/uploads/submissions/',
    $project_root . '/assets/uploads/',
    $project_root . '/uploads/submissions/',
    $project_root . '/uploads/',
];
$upload_dir = $upload_paths[0];
$file_path = '';
foreach ($upload_paths as $path) {
    if (file_exists($path . $file)) {
        $file_path = $path . $file;
        break;
    }
}

// Check if file exists
if (!file_exists($file_path)) {
    // Show error with debug info
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>File Not Found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { padding: 50px; background: #f8f9fc; }
            .error-container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .error-icon { font-size: 4rem; color: #dc3545; text-align: center; display: block; }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <span class='error-icon'>📁</span>
            <h3 class='text-center text-danger'>File Not Found</h3>
            <p class='text-center'>The file <strong><?php echo htmlspecialchars($file); ?></strong> could not be found.</p>
            <div class='alert alert-info'>
                <strong>Expected location:</strong><br>
                <code><?php echo htmlspecialchars($upload_dir . $file); ?></code>
            </div>
            <div class='text-center mt-3'>
                <a href='javascript:history.back()' class='btn btn-primary'>Go Back</a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Get file extension
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// Set appropriate headers
if ($action === 'download') {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
} else {
    // View mode - set content type based on file extension
    $content_types = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'csv' => 'text/csv',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
    ];
    
    $content_type = $content_types[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: inline; filename="' . $file . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
}

// Read and output the file
readfile($file_path);
exit();
?>
