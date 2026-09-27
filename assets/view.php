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

// Get file name
$file = $_GET['file'] ?? '';

if (empty($file)) {
    die('File not specified');
}

// Security: Prevent directory traversal
$file = basename($file);

// Get the project root path
$project_root = dirname(__DIR__);

// Keep compatibility with both submission upload locations used by the app.
$upload_paths = [
    $project_root . '/assets/uploads/submissions/',
    $project_root . '/assets/uploads/',
    $project_root . '/uploads/submissions/',
    $project_root . '/uploads/',
];
$file_path = '';
foreach ($upload_paths as $path) {
    if (file_exists($path . $file)) {
        $file_path = $path . $file;
        break;
    }
}

if (!file_exists($file_path)) {
    die('File not found');
}

// Get file extension
$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

// For images, display in a nice HTML page
$image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
if (in_array($ext, $image_types)) {
    $base64 = base64_encode(file_get_contents($file_path));
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>View Image - <?php echo htmlspecialchars($file); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css" rel="stylesheet">
        <style>
            body { margin: 0; padding: 20px; background: #f5f5f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; flex-direction: column; font-family: Arial, sans-serif; }
            .container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 95%; }
            .image-wrapper { text-align: center; }
            img { max-width: 100%; max-height: 80vh; display: block; margin: 0 auto; border-radius: 4px; }
            .info { margin-top: 15px; text-align: center; color: #666; font-size: 14px; }
            .btn { margin: 5px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="image-wrapper">
                <img src="data:image/<?php echo $ext; ?>;base64,<?php echo $base64; ?>" alt="<?php echo htmlspecialchars($file); ?>">
            </div>
            <div class="info">
                <p><strong><?php echo htmlspecialchars($file); ?></strong></p>
                <p class="text-muted"><?php echo round(filesize($file_path) / 1024, 2); ?> KB</p>
                <div>
                    <a href="download.php?file=<?php echo urlencode($file); ?>&action=download" class="btn btn-success">
                        <i class="bi bi-download"></i> Download
                    </a>
                    <a href="javascript:window.close()" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Close
                    </a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    // For PDFs and other files, redirect to download
    header('Location: download.php?file=' . urlencode($file) . '&action=view');
}
exit();
?>
