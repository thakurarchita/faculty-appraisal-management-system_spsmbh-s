<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Check if user is admin
if ($_SESSION['role'] !== 'Admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();

    // Handle quick_test_login toggle (standalone save, no colors required)
    if (isset($_POST['quick_test_login']) && count($_POST) === 1) {
        $val = $_POST['quick_test_login'] === '1' ? '1' : '0';
        try {
            $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('quick_test_login', ?)");
            $stmt->execute([$val]);
            echo json_encode(['success' => true, 'message' => 'Setting saved']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
        }
        exit();
    }

    // Get color parameters
    $theme_primary   = $_POST['theme_primary']   ?? '';
    $theme_secondary = $_POST['theme_secondary'] ?? '';
    $theme_accent    = $_POST['theme_accent']    ?? '';
    
    // Basic validation for hex color codes
    function isValidHex($color) {
        return preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color);
    }
    
    if (!isValidHex($theme_primary) || !isValidHex($theme_secondary) || !isValidHex($theme_accent)) {
        echo json_encode(['success' => false, 'message' => 'Invalid color code format']);
        exit();
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        
        $stmt->execute(['theme_primary',   $theme_primary]);
        $stmt->execute(['theme_secondary', $theme_secondary]);
        $stmt->execute(['theme_accent',    $theme_accent]);
        
        $db->commit();
        
        echo json_encode(['success' => true, 'message' => 'Settings updated successfully']);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
