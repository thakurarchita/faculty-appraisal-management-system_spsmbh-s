<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';

// Check if user is logged in and is faculty
if (!isLoggedIn() || !isFaculty()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$db = getDB();


// ─── Rate Limiting ─────────────────────────────────────────────────────────
if (!rateLimit('api_' . basename(__FILE__, '.php'), 60, 60)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ============== GET TASK DETAILS ==============
    if ($action == 'get_task_details') {
        $taskId = $_POST['task_id'];
        $userId = $_SESSION['user_id'];
        
        $stmt = $db->prepare("
            SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name
            FROM tasks t
            JOIN task_categories tc ON t.category_id = tc.id
            JOIN users u ON t.assigned_by = u.id
            WHERE t.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$taskId, $userId]);
        $task = $stmt->fetch();
        
        if ($task) {
            echo json_encode([
                'success' => true,
                'data' => $task
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Task not found'
            ]);
        }
        exit();
    }
    
    // ============== GET EVALUATION ==============
    if ($action == 'get_evaluation') {
        $taskId = $_POST['task_id'];
        $userId = $_SESSION['user_id'];
        
        $stmt = $db->prepare("
            SELECT te.*, u.full_name as evaluated_by_name
            FROM task_evaluations te
            JOIN task_submissions ts ON ts.id = te.submission_id
            JOIN tasks t ON t.id = ts.task_id
            JOIN users u ON u.id = te.evaluated_by
            WHERE t.id = ? AND t.user_id = ?
            ORDER BY te.evaluated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$taskId, $userId]);
        $evaluation = $stmt->fetch();
        
        if ($evaluation) {
            echo json_encode([
                'success' => true,
                'data' => $evaluation
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No evaluation found'
            ]);
        }
        exit();
    }
}
?>
