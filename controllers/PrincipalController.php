<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define SITE_URL if not defined
if (!defined('SITE_URL')) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    define('SITE_URL', $protocol . $host);
}

// Check if user is logged in and is principal or admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'Principal' && $_SESSION['role'] !== 'Admin')) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// ... rest of the code ...

// Include required files
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';

$db = getDB();

// Handle POST requests

// ─── Rate Limiting ─────────────────────────────────────────────────────────
if (!rateLimit('api_' . basename(__FILE__, '.php'), 60, 60)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Update Category Settings
    if ($action == 'update_settings') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        $maxMarks = $_POST['max_marks'] ?? [];
        $errors = [];
        $successCount = 0;

        if (empty($maxMarks)) {
            $_SESSION['error'] = 'Please provide at least one category mark.';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }

        $totalMarks = 0;
        foreach ($maxMarks as $marks) {
            if (filter_var($marks, FILTER_VALIDATE_INT) === false) {
                $errors[] = 'Category marks must be whole numbers.';
                continue;
            }
            $totalMarks += (int)$marks;
        }

        if (!empty($errors) || $totalMarks !== 100) {
            if ($totalMarks !== 100) {
                $errors[] = "Category marks must total exactly 100. Current total: {$totalMarks}.";
            }
            $_SESSION['error'] = implode(' ', $errors);
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            foreach ($maxMarks as $categoryId => $marks) {
                $marks = intval($marks);
                
                // Validate marks
                if ($marks < 0 || $marks > 100) {
                    $errors[] = "Category ID {$categoryId} has invalid marks: {$marks}";
                    continue;
                }
                
                // Check if category exists
                $checkStmt = $db->prepare("SELECT id FROM task_categories WHERE id = ?");
                $checkStmt->execute([$categoryId]);
                if (!$checkStmt->fetch()) {
                    $errors[] = "Category ID {$categoryId} does not exist";
                    continue;
                }
                
                // Check if setting exists
                $checkStmt = $db->prepare("SELECT id FROM performance_settings WHERE category_id = ?");
                $checkStmt->execute([$categoryId]);
                $existing = $checkStmt->fetch();
                
                if ($existing) {
                    // Update existing setting
                    $stmt = $db->prepare("
                        UPDATE performance_settings 
                        SET max_marks = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP 
                        WHERE category_id = ?
                    ");
                    $result = $stmt->execute([$marks, $_SESSION['user_id'], $categoryId]);
                } else {
                    // Insert new setting
                    $stmt = $db->prepare("
                        INSERT INTO performance_settings (category_id, max_marks, updated_by, updated_at) 
                        VALUES (?, ?, ?, CURRENT_TIMESTAMP)
                    ");
                    $result = $stmt->execute([$categoryId, $marks, $_SESSION['user_id']]);
                }
                
                if ($result) {
                    $successCount++;
                    
                    // Log activity
                    logActivity(
                        $_SESSION['user_id'],
                        'Updated Performance Setting',
                        "Category ID: {$categoryId}, Max Marks: {$marks}"
                    );
                } else {
                    $errors[] = "Failed to update category ID {$categoryId}";
                }
            }
            
            $db->commit();
            
            // Add notification for all faculty about settings update
            $facultyUsers = getUsersByRole('Faculty');
            foreach ($facultyUsers as $faculty) {
                addNotification(
                    $faculty['id'],
                    'Performance Settings Updated',
                    'The performance evaluation settings have been updated by the Principal.',
                    '/faculty/dashboard'
                );
            }
            
            if ($successCount > 0) {
                $_SESSION['success'] = "Successfully updated {$successCount} category setting(s).";
                if (!empty($errors)) {
                    $_SESSION['error'] = implode(', ', $errors);
                }
            } else {
                $_SESSION['error'] = "No settings were updated. " . implode(', ', $errors);
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Update Settings Error: " . $e->getMessage());
            $_SESSION['error'] = "An error occurred while updating settings: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/settings');
        exit();
    }
    
    // Update Duration
    if ($action == 'update_duration') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        $duration = $_POST['duration'] ?? '';
        $validDurations = ['Monthly', 'Semester', 'Yearly'];
        
        if (!in_array($duration, $validDurations)) {
            $_SESSION['error'] = 'Invalid duration selected';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Check if any settings exist
            $checkStmt = $db->query("SELECT COUNT(*) FROM performance_settings");
            $count = $checkStmt->fetchColumn();
            
            if ($count == 0) {
                // If no settings exist, insert default ones
                $insertStmt = $db->prepare("
                    INSERT INTO performance_settings (category_id, max_marks, duration, updated_by, updated_at)
                    SELECT id, max_marks, ?, ?, CURRENT_TIMESTAMP
                    FROM task_categories
                ");
                $result = $insertStmt->execute([$duration, $_SESSION['user_id']]);
            } else {
                // Update existing settings with the new duration
                $stmt = $db->prepare("
                    UPDATE performance_settings 
                    SET duration = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                ");
                $result = $stmt->execute([$duration, $_SESSION['user_id']]);
            }
            
            if ($result !== false) {
                
                $db->commit();
                
                logActivity(
                    $_SESSION['user_id'],
                    'Updated Duration Setting',
                    "Duration set to: {$duration}"
                );
                
                $_SESSION['success'] = "Duration updated to: {$duration}";
            } else {
                throw new Exception("Failed to update duration");
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Update Duration Error: " . $e->getMessage());
            $_SESSION['error'] = "An error occurred while updating duration: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/settings');
        exit();
    }
        
    // Add Academic Year
    if ($action == 'add_academic_year') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        $yearName = trim($_POST['year_name'] ?? '');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        
        if (empty($yearName)) {
            $_SESSION['error'] = 'Please enter an appraisal year format (e.g., 2025, 2025-2026, 2028-2027).';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        // Validate format: 4 digits OR 4 digits-4 digits
        if (!preg_match('/^(\d{4})(\s*-\s*\d{4})?$/', $yearName)) {
            $_SESSION['error'] = 'Invalid year format. Examples: 2025, 2025-2026, 2028-2027.';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        // Standardize format (remove extra spaces around hyphen)
        $yearName = preg_replace('/\s*-\s*/', '-', $yearName);
        
        try {
            // Check if year already exists
            $checkStmt = $db->prepare("SELECT id FROM academic_years WHERE year_name = ?");
            $checkStmt->execute([$yearName]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = "Appraisal Year '{$yearName}' already exists.";
                header('Location: ' . BASE_URL . '/principal/settings');
                exit();
            }
            
            $db->beginTransaction();
            
            if ($isDefault) {
                $db->exec("UPDATE academic_years SET is_default = 0");
            }
            
            $stmt = $db->prepare("INSERT INTO academic_years (year_name, is_active, is_default) VALUES (?, 1, ?)");
            $stmt->execute([$yearName, $isDefault]);
            
            $db->commit();
            
            logActivity(
                $_SESSION['user_id'],
                'Added Appraisal Year',
                "Added year: {$yearName} (Default: " . ($isDefault ? 'Yes' : 'No') . ")"
            );
            
            $_SESSION['success'] = "Appraisal Year '{$yearName}' added successfully.";
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Add Academic Year Error: " . $e->getMessage());
            $_SESSION['error'] = "Failed to add appraisal year: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/settings');
        exit();
    }
    
    // Set Default Academic Year
    if ($action == 'set_default_academic_year') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        $yearId = intval($_POST['year_id'] ?? 0);
        
        try {
            $checkStmt = $db->prepare("SELECT year_name FROM academic_years WHERE id = ?");
            $checkStmt->execute([$yearId]);
            $year = $checkStmt->fetch();
            
            if (!$year) {
                $_SESSION['error'] = 'Selected appraisal year not found.';
                header('Location: ' . BASE_URL . '/principal/settings');
                exit();
            }
            
            $db->beginTransaction();
            $db->exec("UPDATE academic_years SET is_default = 0");
            
            $updateStmt = $db->prepare("UPDATE academic_years SET is_default = 1, is_active = 1 WHERE id = ?");
            $updateStmt->execute([$yearId]);
            $db->commit();
            
            logActivity(
                $_SESSION['user_id'],
                'Set Default Appraisal Year',
                "Default appraisal year set to: {$year['year_name']}"
            );
            
            $_SESSION['success'] = "Default Appraisal Year set to: '{$year['year_name']}'.";
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Set Default Year Error: " . $e->getMessage());
            $_SESSION['error'] = "Failed to set default year: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/settings');
        exit();
    }
    
    // Delete Academic Year
    if ($action == 'delete_academic_year') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . BASE_URL . '/principal/settings');
            exit();
        }
        
        $yearId = intval($_POST['year_id'] ?? 0);
        
        try {
            $checkStmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
            $checkStmt->execute([$yearId]);
            $year = $checkStmt->fetch();
            
            if (!$year) {
                $_SESSION['error'] = 'Appraisal year not found.';
                header('Location: ' . BASE_URL . '/principal/settings');
                exit();
            }
            
            if ($year['is_default']) {
                $_SESSION['error'] = 'Cannot delete the default active appraisal year. Please set another year as default first.';
                header('Location: ' . BASE_URL . '/principal/settings');
                exit();
            }
            
            $deleteStmt = $db->prepare("DELETE FROM academic_years WHERE id = ?");
            $deleteStmt->execute([$yearId]);
            
            logActivity(
                $_SESSION['user_id'],
                'Deleted Appraisal Year',
                "Deleted year: {$year['year_name']}"
            );
            
            $_SESSION['success'] = "Appraisal Year '{$year['year_name']}' deleted.";
        } catch (Exception $e) {
            error_log("Delete Academic Year Error: " . $e->getMessage());
            $_SESSION['error'] = "Failed to delete appraisal year: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/settings');
        exit();
    }
    
    // Evaluate Task - UPDATED with reject functionality
    if ($action == 'evaluate_task') {
        // Validate CSRF token
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ../views/principal/evaluations.php');
            exit();
        }
        
        $submissionId = $_POST['submission_id'] ?? null;
        $taskId = $_POST['task_id'] ?? null;
        $userId = $_POST['user_id'] ?? null;
        $marksObtained = filter_var($_POST['marks_obtained'] ?? null, FILTER_VALIDATE_FLOAT);
        $status = $_POST['status'] ?? null;
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        $newDueDate = !empty($_POST['new_due_date']) ? $_POST['new_due_date'] : null;
        
        // Validate inputs
        if (!$submissionId || !$taskId || !$userId || $marksObtained === false || !$status) {
            $_SESSION['error'] = 'Missing required fields';
            header('Location: ' . BASE_URL . '/principal/evaluations');
            exit();
        }
        
        // Get category max marks
        $catStmt = $db->prepare("
            SELECT t.category_id, ps.max_marks 
            FROM tasks t 
            LEFT JOIN performance_settings ps ON ps.category_id = t.category_id 
            WHERE t.id = ?
        ");
        $catStmt->execute([$taskId]);
        $categoryInfo = $catStmt->fetch();
        
        if (!$categoryInfo) {
            $_SESSION['error'] = 'Invalid task or category';
            header('Location: ' . BASE_URL . '/principal/evaluations');
            exit();
        }
        
        $maxMarks = $categoryInfo['max_marks'] ?? 0;
        
        // Validate marks
        if ($marksObtained < 0 || $marksObtained > $maxMarks) {
            $_SESSION['error'] = "Marks must be between 0 and {$maxMarks}";
            header('Location: ' . BASE_URL . '/principal/evaluations');
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Check if evaluation already exists
            $checkStmt = $db->prepare("SELECT id FROM task_evaluations WHERE submission_id = ?");
            $checkStmt->execute([$submissionId]);
            if ($checkStmt->fetch()) {
                $_SESSION['error'] = 'This submission has already been evaluated';
                header('Location: ' . BASE_URL . '/principal/evaluations');
                exit();
            }
            
            // Insert evaluation
            $stmt = $db->prepare("
                INSERT INTO task_evaluations (task_id, submission_id, evaluated_by, marks_obtained, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $taskId,
                $submissionId,
                $_SESSION['user_id'],
                $marksObtained,
                $remarks,
                $status
            ]);
            
            if ($result) {
                // Get task details first
                $taskStmt = $db->prepare("SELECT * FROM tasks WHERE id = ?");
                $taskStmt->execute([$taskId]);
                $task = $taskStmt->fetch();
                
                if ($status == 'Approved') {
                    // If Approved, update task status to Approved (completed)
                    $updateTask = $db->prepare("UPDATE tasks SET status = 'Approved', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $updateTask->execute([$taskId]);
                    
                    // Update submission status
                    $subStmt = $db->prepare("UPDATE task_submissions SET status = 'Approved' WHERE id = ?");
                    $subStmt->execute([$submissionId]);
                    
                } elseif ($status == 'Rejected') {
                    // If Rejected, reset task status to 'Pending' so faculty can work on it again
                    if ($newDueDate) {
                        $updateTask = $db->prepare("
                            UPDATE tasks 
                            SET status = 'Pending', 
                                due_date = ?,
                                updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $updateTask->execute([$newDueDate, $taskId]);
                    } else {
                        $updateTask = $db->prepare("
                            UPDATE tasks 
                            SET status = 'Pending', 
                                updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $updateTask->execute([$taskId]);
                    }
                    
                    // Update submission status
                    $subStmt = $db->prepare("UPDATE task_submissions SET status = 'Rejected' WHERE id = ?");
                    $subStmt->execute([$submissionId]);
                }
                
                // Get user details
                $userModel = new User();
                $user = $userModel->getUserById($userId);
                
                if ($user) {
                    // Add notification
                    if ($status == 'Rejected') {
                        $notificationMsg = "Your task '{$task['task_title']}' has been REJECTED. Please revise and submit again.";
                        if ($newDueDate) {
                            $notificationMsg .= " New due date: " . date('d M Y', strtotime($newDueDate));
                        }
                        addNotification(
                            $userId,
                            'Task Rejected - Needs Revision',
                            $notificationMsg,
                            '/faculty/tasks'
                        );
                    } else {
                        addNotification(
                            $userId,
                            "Task Approved",
                            "Your task '{$task['task_title']}' has been APPROVED with {$marksObtained} marks.",
                            '/faculty/tasks'
                        );
                    }
                    
                   // Send email notification
try {
    require_once __DIR__ . '/../config/mail.php';
    $mailer = new Mailer();
    
    $mailer->sendTaskEvaluatedEmail(
        $user['email'],
        $task['task_title'],
        $status,
        $remarks
    );
} catch (Exception $e) {
    error_log("Email send failed: " . $e->getMessage());
}
                    // Log activity
                    logActivity(
                        $_SESSION['user_id'],
                        'Evaluated Task',
                        "Evaluated task '{$task['task_title']}' for user {$user['full_name']} with status: {$status}, marks: {$marksObtained}"
                    );
                }
                
                $db->commit();
                
                if ($status == 'Rejected') {
                    $_SESSION['success'] = "Task rejected. User has been notified and task has been reopened for revision.";
                } else {
                    $_SESSION['success'] = "Task approved successfully!";
                }
            } else {
                throw new Exception("Failed to save evaluation");
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Evaluation Error: " . $e->getMessage());
            $_SESSION['error'] = "An error occurred while evaluating: " . $e->getMessage();
        }
        
        header('Location: ' . BASE_URL . '/principal/evaluations');
        exit();
    }
    
    // Delete Task
    if ($action == 'delete_task') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit();
        }
        
        $taskId = $_POST['task_id'] ?? 0;
        
        require_once __DIR__ . '/../models/Task.php';
        $taskModel = new Task();
        
        if ($taskModel->deleteTask($taskId)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Task successfully deleted']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to delete task']);
        }
        exit();
    }
    
    // Edit Task
    if ($action == 'edit_task') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['error'] = 'Invalid security token';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/principal/task-status'));
            exit();
        }
        
        $taskId = $_POST['task_id'] ?? 0;
        $data = [
            'task_title' => sanitizeInput($_POST['task_title'] ?? ''),
            'task_description' => sanitizeInput($_POST['task_description'] ?? ''),
            'category_id' => $_POST['category_id'] ?? 0,
            'due_date' => !empty($_POST['due_date']) ? $_POST['due_date'] : null,
            'priority' => $_POST['priority'] ?? 'Medium'
        ];
        
        require_once __DIR__ . '/../models/Task.php';
        $taskModel = new Task();
        
        if (empty($data['task_title']) || empty($data['category_id'])) {
            $_SESSION['error'] = 'Task title and category are required';
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/principal/task-status'));
            exit();
        }
        
        if ($taskModel->updateTask($taskId, $data)) {
            $_SESSION['success'] = 'Task successfully updated';
        } else {
            $_SESSION['error'] = 'Failed to update task';
        }
        
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/principal/task-status'));
        exit();
    }
}

// Handle AJAX GET requests
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    
    // Get settings
    if ($action == 'get_settings') {
        $stmt = $db->query("
            SELECT 
                tc.id as category_id,
                tc.name as category_name,
                tc.max_marks as default_max_marks,
                ps.max_marks as current_max_marks,
                ps.duration,
                ps.updated_at,
                u.full_name as updated_by_name
            FROM task_categories tc
            LEFT JOIN performance_settings ps ON ps.category_id = tc.id
            LEFT JOIN users u ON u.id = ps.updated_by
            ORDER BY tc.id
        ");
        $settings = $stmt->fetchAll();
        
        header('Content-Type: application/json');
        echo json_encode($settings);
        exit();
    }
    
    // Get submission details
    if ($action == 'get_submission_details') {
        $submissionId = $_GET['id'] ?? null;
        
        if (!$submissionId) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Submission ID required']);
            exit();
        }
        
        try {
            $stmt = $db->prepare("
                SELECT 
                    ts.*,
                    t.task_title,
                    t.task_description,
                    t.due_date,
                    t.priority,
                    u.full_name as employee_name,
                    u.employee_id,
                    u.email as employee_email,
                    u.mobile as employee_mobile,
                    tc.name as category_name,
                    tc.id as category_id,
                    ps.max_marks as category_max_marks,
                    te.id as evaluation_id,
                    te.marks_obtained,
                    te.remarks as evaluation_remarks,
                    te.status as evaluation_status,
                    te.evaluated_at,
                    eval_by.full_name as evaluated_by_name
                FROM task_submissions ts
                JOIN tasks t ON ts.task_id = t.id
                JOIN users u ON t.user_id = u.id
                JOIN task_categories tc ON t.category_id = tc.id
                LEFT JOIN performance_settings ps ON ps.category_id = tc.id
                LEFT JOIN task_evaluations te ON te.submission_id = ts.id
                LEFT JOIN users eval_by ON eval_by.id = te.evaluated_by
                WHERE ts.id = ?
            ");
            $stmt->execute([$submissionId]);
            $submission = $stmt->fetch();
            
            if ($submission) {
                // Format dates
                $submission['submitted_at'] = formatDatabaseDate($submission['submitted_at']);
                if (!empty($submission['due_date'])) {
                    $submission['due_date'] = date('d M Y', strtotime($submission['due_date']));
                }
                if (!empty($submission['evaluated_at'])) {
                    $submission['evaluated_at'] = formatDatabaseDate($submission['evaluated_at']);
                }
                
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'data' => $submission
                ]);
            } else {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Submission not found'
                ]);
            }
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
    }
}

// If no action matched, redirect to dashboard
header('Location: ' . BASE_URL . '/principal/dashboard');
exit();
?>
