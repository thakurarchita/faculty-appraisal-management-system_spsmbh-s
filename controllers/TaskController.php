<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../middleware/role_middleware.php';
require_once __DIR__ . '/../models/Task.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mail.php';

$taskModel = new Task();
$db = getDB();


// ─── Rate Limiting ─────────────────────────────────────────────────────────
if (!rateLimit('api_' . basename(__FILE__, '.php'), 60, 60)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ============== CREATE TEMPLATE ==============
    if ($action == 'create_template') {
        checkAdmin();
        
        $data = [
            'task_name' => sanitizeInput($_POST['task_name']),
            'category_id' => $_POST['category_id'],
            'employee_type' => $_POST['employee_type'],
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by' => $_SESSION['user_id']
        ];
        
        $result = $taskModel->createTemplate($data);
        
        if ($result) {
            $_SESSION['success'] = 'Template created successfully';
        } else {
            $_SESSION['error'] = 'Failed to create template';
        }
        
        header('Location: ' . BASE_URL . '/admin/templates');
        exit();
    }
    
    // ============== GET TEMPLATE (for Edit) ==============
    if ($action == 'get_template') {
        checkAdmin();
        
        $templateId = $_POST['template_id'];
        
        $stmt = $db->prepare("SELECT * FROM task_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch();
        
        if ($template) {
            echo json_encode([
                'success' => true,
                'data' => $template
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Template not found'
            ]);
        }
        exit();
    }
    
    // ============== UPDATE TEMPLATE ==============
    if ($action == 'update_template') {
        checkAdmin();
        
        $templateId = $_POST['template_id'];
        $taskName = sanitizeInput($_POST['task_name']);
        $categoryId = $_POST['category_id'];
        $employeeType = $_POST['employee_type'];
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $db->prepare("
            UPDATE task_templates 
            SET task_name = ?, 
                category_id = ?, 
                employee_type = ?, 
                is_default = ?, 
                is_active = ?
            WHERE id = ?
        ");
        
        $result = $stmt->execute([$taskName, $categoryId, $employeeType, $isDefault, $isActive, $templateId]);
        
        if ($result) {
            $_SESSION['success'] = 'Template updated successfully';
        } else {
            $_SESSION['error'] = 'Failed to update template';
        }
        
        header('Location: ' . BASE_URL . '/admin/templates');
        exit();
    }
    
    // ============== DELETE TEMPLATE ==============
    if ($action == 'delete_template') {
        checkAdmin();
        
        $templateId = $_POST['template_id'];
        
        // Check if template is being used in any task
        $checkStmt = $db->prepare("SELECT COUNT(*) as count FROM tasks WHERE template_id = ?");
        $checkStmt->execute([$templateId]);
        $count = $checkStmt->fetch()['count'];
        
        if ($count > 0) {
            echo json_encode([
                'success' => false,
                'message' => 'This template is being used in tasks and cannot be deleted'
            ]);
            exit();
        }
        
        // Delete the template
        $stmt = $db->prepare("DELETE FROM task_templates WHERE id = ?");
        $result = $stmt->execute([$templateId]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Template deleted successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to delete template'
            ]);
        }
        exit();
    }
    
    // ============== TOGGLE TEMPLATE STATUS ==============
    if ($action == 'toggle_template_status') {
        checkAdmin();
        
        $templateId = $_POST['template_id'];
        $status = $_POST['status'];
        
        $stmt = $db->prepare("UPDATE task_templates SET is_active = ? WHERE id = ?");
        $result = $stmt->execute([$status, $templateId]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to update status'
            ]);
        }
        exit();
    }
}
// ============== SUBMIT TASK ==============
if ($action == 'submit_task') {
    checkFaculty();
    
    $taskId = $_POST['task_id'];
    $submissionText = sanitizeInput($_POST['submission_text']);
    $attachment = $_FILES['proof']['name'] ?? null;
    
    // Handle file upload
    if (!empty($_FILES['proof']['name'])) {
        $upload_dir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = time() . '_' . $_FILES['proof']['name'];
        $target_file = $upload_dir . $file_name;
        if (move_uploaded_file($_FILES['proof']['tmp_name'], $target_file)) {
            $attachment = $file_name;
        }
    }
    
    // Get task details for email
    $taskStmt = $db->prepare("SELECT t.*, u.full_name as user_name, u.email as user_email 
                              FROM tasks t 
                              JOIN users u ON t.user_id = u.id 
                              WHERE t.id = ?");
    $taskStmt->execute([$taskId]);
    $task = $taskStmt->fetch();
    
    // Update task status
    $stmt = $db->prepare("UPDATE tasks SET status = 'Submitted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $result = $stmt->execute([$taskId]);
    
    if ($result) {
        // Add submission record
        $stmt2 = $db->prepare("
            INSERT INTO task_submissions (task_id, submitted_by, submission_text, attachment, submitted_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt2->execute([$taskId, $_SESSION['user_id'], $submissionText, $attachment, date('Y-m-d H:i:s')]);
        
        // Send email notification to Principal
        try {
            $mailer = new Mailer();
            
            // Get principal email
            $principalStmt = $db->query("SELECT email FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Principal') LIMIT 1");
            $principal = $principalStmt->fetch();
            
            if ($principal) {
                $mailer->sendTaskSubmissionNotification(
                    $principal['email'],
                    $task['user_name'],
                    $task['task_title']
                );
                $_SESSION['email_message'] = 'Notification sent to Principal';
            }
        } catch (Exception $e) {
            error_log("Task submission email failed: " . $e->getMessage());
        }
        
        $_SESSION['success'] = 'Task submitted successfully';
    } else {
        $_SESSION['error'] = 'Failed to submit task';
    }
    
    header('Location: ' . BASE_URL . '/faculty/tasks');
    exit();
}
?>
