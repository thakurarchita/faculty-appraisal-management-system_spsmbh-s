<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../middleware/role_middleware.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mail.php';

// Check if user is admin
checkAdmin();

$db = getDB();


// ─── Rate Limiting ─────────────────────────────────────────────────────────
if (!rateLimit('api_' . basename(__FILE__, '.php'), 60, 60)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ============== ASSIGN DEFAULT TASKS ==============
    if ($action == 'assign_default_tasks') {
        $userId = $_POST['user_id'];
        $templateIds = $_POST['template_ids'] ?? [];
        $assignedBy = $_SESSION['user_id'];
        $duration = $_POST['duration'] ?? 'Semester';
        $priority = $_POST['priority'] ?? 'Medium';
        $dueDate = $_POST['due_date'] ?? null;
        $note = sanitizeInput($_POST['task_description'] ?? '');
        
        if (empty($templateIds)) {
            $_SESSION['error'] = 'Please select at least one task';
            header('Location: ' . BASE_URL . '/admin/assign-task');
            exit();
        }
        
        $successCount = 0;
        $errorCount = 0;
        $alreadyAssignedCount = 0;
        $assignedTasks = [];
        $userEmail = null;
        $userName = null;
        $errors = [];
        
        // Get user details for email
        $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $user = $userStmt->fetch();
        if ($user) {
            $userEmail = $user['email'];
            $userName = $user['full_name'];
        }
        
        // Check if user exists
        if (!$user) {
            $_SESSION['error'] = 'Selected user does not exist';
            header('Location: ' . BASE_URL . '/admin/assign-task');
            exit();
        }
        
        // Get category name for the selected category
        $categoryId = $_POST['category_id'] ?? 0;
        $categoryStmt = $db->prepare("SELECT name FROM task_categories WHERE id = ?");
        $categoryStmt->execute([$categoryId]);
        $category = $categoryStmt->fetch();
        $categoryName = $category ? $category['name'] : 'Unknown';
        
        foreach ($templateIds as $templateId) {
            // Get template details
            $stmt = $db->prepare("SELECT task_name, category_id FROM task_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch();
            
            if (!$template) {
                $errorCount++;
                $errors[] = "Template ID $templateId not found";
                continue;
            }
            
            // Check if task already assigned to this user
            $checkStmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM tasks 
                WHERE user_id = ? AND template_id = ? AND is_template = 1
            ");
            $checkStmt->execute([$userId, $templateId]);
            $exists = $checkStmt->fetch()['count'];
            
            if ($exists > 0) {
                $alreadyAssignedCount++;
                $errors[] = "Task '{$template['task_name']}' already assigned to this user";
                continue;
            }
            
            // Assign task with duration, priority, due date and note
            $taskDescription = $note ? "Note from Admin: " . $note : '';
            
            $stmt2 = $db->prepare("
                INSERT INTO tasks (user_id, assigned_by, task_title, task_description,
                                 category_id, is_template, template_id, status, 
                                 duration, priority, due_date)
                VALUES (?, ?, ?, ?, ?, 1, ?, 'Pending', ?, ?, ?)
            ");
            $result = $stmt2->execute([
                $userId,
                $assignedBy,
                $template['task_name'],
                $taskDescription,
                $template['category_id'],
                $templateId,
                $duration,
                $priority,
                $dueDate
            ]);
            
            if ($result) {
                $successCount++;
                $assignedTasks[] = [
                    'task_name' => $template['task_name'],
                    'due_date' => $dueDate
                ];
                
                // Add notification
                addNotification(
                    $userId,
                    '📋 New Task Assigned',
                    "Task '{$template['task_name']}' has been assigned to you by Admin." . 
                    ($note ? " Note: " . $note : ""),
                    '/faculty/tasks'
                );
            } else {
                $errorCount++;
                $errors[] = "Failed to assign '{$template['task_name']}'";
            }
        }
        
        // ✅ SEND EMAIL NOTIFICATION TO USER
        if ($successCount > 0 && $userEmail) {
            try {
                $mailer = new Mailer();
                
                $emailSent = $mailer->sendTaskAssignedEmail(
                    $userEmail,                    // Recipient email
                    $userName,                     // User name
                    $assignedTasks,                // Array of tasks
                    $_SESSION['full_name'] ?? 'Admin', // Assigned by
                    $duration,                     // Duration
                    $priority,                     // Priority
                    $dueDate,                      // Due date
                    $note                          // Additional note
                );
                
                if ($emailSent) {
                    $_SESSION['email_sent'] = true;
                    $_SESSION['email_message'] = "📧 Email notification sent to {$userEmail}";
                } else {
                    $_SESSION['email_sent'] = false;
                    $_SESSION['email_message'] = "⚠️ Tasks assigned but email could not be sent to {$userEmail}";
                }
            } catch (Exception $e) {
                $_SESSION['email_sent'] = false;
                $_SESSION['email_message'] = "⚠️ Email error: " . $e->getMessage();
                error_log("Task assignment email failed: " . $e->getMessage());
            }
        }
        
        // Build response message
        $msg = "";
        if ($successCount > 0) {
            $msg .= "✅ $successCount task(s) assigned successfully! ";
        }
        if ($alreadyAssignedCount > 0) {
            $msg .= "⚠️ $alreadyAssignedCount task(s) were already assigned. ";
        }
        if ($errorCount > 0 && $successCount == 0) {
            $msg = "❌ Failed to assign tasks. ";
            if (!empty($errors)) {
                $msg .= "Errors: " . implode(", ", array_slice($errors, 0, 3));
                if (count($errors) > 3) {
                    $msg .= " and " . (count($errors) - 3) . " more...";
                }
            }
        }
        
        if ($successCount > 0) {
            $_SESSION['success'] = $msg;
        } else {
            $_SESSION['error'] = $msg ?: 'Failed to assign tasks. Please try again.';
        }
        
        header('Location: ' . BASE_URL . '/admin/assign-task');
        exit();
    }
    
    // ============== UPDATE TASK STATUS ==============
    if ($action == 'update_task_status') {
        $taskId = $_POST['task_id'];
        $status = $_POST['status'];
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        
        $stmt = $db->prepare("UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$status, $taskId, $_SESSION['user_id']]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'Task Status Updated', "Task ID: $taskId, New Status: $status");
            $_SESSION['success'] = 'Task status updated successfully';
        } else {
            $_SESSION['error'] = 'Failed to update task status';
        }
        
        header('Location: ' . BASE_URL . '/admin/my-tasks');
        exit();
    }
    
    // ============== GET TASK DETAILS ==============
    if ($action == 'get_task_details') {
        $taskId = $_POST['task_id'];
        
        $stmt = $db->prepare("
            SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name
            FROM tasks t
            JOIN task_categories tc ON t.category_id = tc.id
            JOIN users u ON t.assigned_by = u.id
            WHERE t.id = ? AND t.user_id = ?
        ");
        $stmt->execute([$taskId, $_SESSION['user_id']]);
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
}
?>
