<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../middleware/role_middleware.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../includes/functions.php';

checkAdmin();

$userModel = new User();
$db = getDB();


// ─── Rate Limiting ─────────────────────────────────────────────────────────
if (!rateLimit('api_' . basename(__FILE__, '.php'), 60, 60)) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please slow down.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // ============== ADD USER ==============
    if ($action == 'add_user') {
        $data = [
            'employee_id' => sanitizeInput($_POST['employee_id']),
            'full_name' => sanitizeInput($_POST['full_name']),
            'email' => sanitizeInput($_POST['email']),
            'mobile' => sanitizeInput($_POST['mobile']),
            'designation' => sanitizeInput($_POST['designation']),
            'employee_type' => $_POST['employee_type'],
            'role_id' => $_POST['role_id']
        ];
        
        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = 'Invalid email address format.';
            header('Location: ' . BASE_URL . '/admin/users');
            exit();
        }
        
        // Check if email already exists
        if ($userModel->emailExists($data['email'])) {
            $_SESSION['error'] = 'Email "' . $data['email'] . '" already exists! Please use a different email address.';
            header('Location: ' . BASE_URL . '/admin/users');
            exit();
        }
        
        // Check if employee ID already exists
        if ($userModel->employeeIdExists($data['employee_id'])) {
            $_SESSION['error'] = 'Employee ID "' . $data['employee_id'] . '" already exists! Please contact administrator.';
            header('Location: ' . BASE_URL . '/admin/users');
            exit();
        }
        
        // Create the user
        $result = $userModel->createUser($data);
        
        if ($result) {
            // Check if email was sent
            if (isset($_SESSION['email_sent']) && $_SESSION['email_sent']) {
                $_SESSION['success'] = '✅ User created successfully! Welcome email sent to the user.';
            } else {
                $_SESSION['success'] = '✅ User created successfully! Please provide credentials manually (check below).';
            }
        } else {
            // If createUser returns false but no error was set
            if (!isset($_SESSION['error'])) {
                $_SESSION['error'] = 'Failed to create user. Please try again.';
            }
        }
        
        header('Location: ' . BASE_URL . '/admin/users');
        exit();
    }
    
    // ============== AJAX ADD USER ==============
    if ($action == 'ajax_add_user') {
        header('Content-Type: application/json');
        
        $data = [
            'employee_id' => sanitizeInput($_POST['employee_id']),
            'full_name' => sanitizeInput($_POST['full_name']),
            'email' => sanitizeInput($_POST['email']),
            'mobile' => sanitizeInput($_POST['mobile']),
            'designation' => sanitizeInput($_POST['designation']),
            'employee_type' => $_POST['employee_type'],
            'role_id' => $_POST['role_id']
        ];
        
        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'Invalid email address format.']);
            exit();
        }
        
        // Check if email already exists
        if ($userModel->emailExists($data['email'])) {
            echo json_encode(['success' => false, 'message' => 'Email already exists!']);
            exit();
        }
        
        // Check if employee ID already exists
        if ($userModel->employeeIdExists($data['employee_id'])) {
            echo json_encode(['success' => false, 'message' => 'Employee ID already exists!']);
            exit();
        }
        
        // Set maximum sequence + 1 for new user so it appears at the bottom
        $seqStmt = $db->query("SELECT MAX(sequence) as max_seq FROM users");
        $maxSeq = $seqStmt->fetchColumn();
        $nextSeq = $maxSeq !== null ? $maxSeq + 1 : 0;
        
        // Custom creation since User::createUser() sets flash messages and generates a random password.
        // We will generate the password manually (or accept one if the user wants it, but they didn't provide one in POST).
        // Wait, the prompt says "Role and Password". So they can pass a custom password!
        $password = !empty($_POST['password']) ? $_POST['password'] : '123456';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("
            INSERT INTO users (employee_id, full_name, email, mobile, 
                             designation, employee_type, role_id, password, sequence)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['employee_id'],
            $data['full_name'],
            $data['email'],
            $data['mobile'],
            $data['designation'],
            $data['employee_type'],
            $data['role_id'],
            $hashedPassword,
            $nextSeq
        ]);
        
        if ($result) {
            $userId = $db->lastInsertId();
            $newUser = $userModel->getUserById($userId);

            try {
                $mailer = new Mailer();
                $emailSent = $mailer->sendWelcomeEmail(
                    $data['email'],
                    $data['email'],
                    $password
                );
            } catch (Exception $e) {
                error_log('Fast Add User welcome email failed: ' . $e->getMessage());
                $emailSent = false;
            }
            
            // Generate next employee ID for the UI
            $nextEmpStmt = $db->query("SELECT employee_id FROM users WHERE employee_id LIKE 'EMP%' ORDER BY CAST(SUBSTR(employee_id, 4) AS INTEGER) DESC LIMIT 1");
            $lastIdRow = $nextEmpStmt->fetch();
            $nextEmpId = 'EMP001';
            if ($lastIdRow) {
                $num = intval(substr($lastIdRow['employee_id'], 3));
                $nextEmpId = 'EMP' . str_pad($num + 1, 3, '0', STR_PAD_LEFT);
            }
            
            echo json_encode([
                'success' => true, 
                'user' => $newUser,
                'next_employee_id' => $nextEmpId,
                'email_sent' => $emailSent,
                'message' => $emailSent
                    ? 'User added successfully! Welcome email sent.'
                    : 'User added successfully, but the welcome email could not be sent. Please share the password manually.'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create user.']);
        }
        exit();
    }
    
    // ============== UPDATE USER SEQUENCE ==============
    if ($action == 'update_sequence') {
        header('Content-Type: application/json');
        $sequences = $_POST['sequences'] ?? [];
        
        if (!empty($sequences) && is_array($sequences)) {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("UPDATE users SET sequence = ? WHERE id = ?");
                foreach ($sequences as $item) {
                    $stmt->execute([$item['sequence'], $item['id']]);
                }
                $db->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'No sequence data provided.']);
        }
        exit();
    }
    
    // ============== GET USER (for View/Edit) ==============
    if ($action == 'get_user') {
        $userId = $_POST['user_id'];
        $user = $userModel->getUserById($userId);
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'data' => $user
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'User not found'
            ]);
        }
        exit();
    }
    
    // ============== UPDATE USER ==============
    if ($action == 'update_user') {
        $userId = $_POST['user_id'];
        $email = sanitizeInput($_POST['email']);
        
        // Check if email already exists for another user
        $existingUser = $userModel->getUserById($userId);
        if ($existingUser && $existingUser['email'] != $email) {
            if ($userModel->emailExists($email)) {
                $_SESSION['error'] = 'Email "' . $email . '" already exists! Please use a different email address.';
                header('Location: ' . BASE_URL . '/admin/users');
                exit();
            }
        }
        
        $data = [
            'full_name' => sanitizeInput($_POST['full_name']),
            'email' => $email,
            'mobile' => sanitizeInput($_POST['mobile']),
            'designation' => sanitizeInput($_POST['designation']),
            'employee_type' => $_POST['employee_type'],
            'role_id' => $_POST['role_id']
        ];
        
        $password = $_POST['password'] ?? '';
        
        if (!empty(trim($password))) {
            $hashedPassword = hashPassword($password);
            $stmt = $db->prepare("
                UPDATE users 
                SET full_name = ?,
                    email = ?,
                    mobile = ?,
                    designation = ?,
                    employee_type = ?,
                    role_id = ?,
                    password = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $data['full_name'],
                $data['email'],
                $data['mobile'],
                $data['designation'],
                $data['employee_type'],
                $data['role_id'],
                $hashedPassword,
                $userId
            ]);
        } else {
            $stmt = $db->prepare("
                UPDATE users 
                SET full_name = ?,
                    email = ?,
                    mobile = ?,
                    designation = ?,
                    employee_type = ?,
                    role_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $data['full_name'],
                $data['email'],
                $data['mobile'],
                $data['designation'],
                $data['employee_type'],
                $data['role_id'],
                $userId
            ]);
        }
        
        if ($result) {
            $_SESSION['success'] = '✅ User updated successfully';
        } else {
            $_SESSION['error'] = 'Failed to update user';
        }
        
        header('Location: ' . BASE_URL . '/admin/users');
        exit();
    }
    
  // ============== DELETE USER ==============
if ($action == 'delete_user') {
    $userId = $_POST['user_id'];
    
    // Check if user is the last admin
    $checkStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') 
        AND is_active = 1
    ");
    $checkStmt->execute();
    $adminCount = $checkStmt->fetch()['count'];
    
    if ($adminCount <= 1) {
        $user = $userModel->getUserById($userId);
        if ($user && $user['role_name'] == 'Admin') {
            echo json_encode([
                'success' => false,
                'message' => 'Cannot delete the last active admin user'
            ]);
            exit();
        }
    }
    
    try {
        $db->beginTransaction();
        
        // 1. Delete task evaluations and submissions
        $stmt = $db->prepare("DELETE FROM task_evaluations WHERE task_id IN (SELECT id FROM tasks WHERE user_id = ?)");
        $stmt->execute([$userId]);
        
        $stmt = $db->prepare("DELETE FROM task_submissions WHERE task_id IN (SELECT id FROM tasks WHERE user_id = ?)");
        $stmt->execute([$userId]);
        
        // 2. Delete tasks
        $stmt = $db->prepare("DELETE FROM tasks WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 3. Delete notifications, password resets, and activity logs
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $stmt = $db->prepare("DELETE FROM password_resets WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        $stmt = $db->prepare("DELETE FROM activity_logs WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 4. Delete profile data
        $profileTables = [
            'faculty_profiles', 'faculty_education', 'faculty_awards', 
            'faculty_publications', 'faculty_fdps', 'faculty_gallery'
        ];
        foreach ($profileTables as $table) {
            $stmt = $db->prepare("DELETE FROM $table WHERE user_id = ?");
            $stmt->execute([$userId]);
        }
        
        // 5. Delete the user
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'User and all related data (tasks, profile, activity) successfully deleted.'
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Error deleting user: ' . $e->getMessage()
        ]);
    }
    exit();
}

// ============== CHECK USER TASKS ==============
if ($action == 'check_user_tasks') {
    $userId = $_POST['user_id'];
    
    $taskStmt = $db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(CASE WHEN status IN ('Pending', 'Initiated', 'In Progress', 'Completed', 'Submitted') THEN 1 ELSE 0 END) as active_tasks,
            SUM(CASE WHEN status IN ('Approved', 'Rejected') THEN 1 ELSE 0 END) as completed_tasks
        FROM tasks 
        WHERE user_id = ?
    ");
    $taskStmt->execute([$userId]);
    $taskStats = $taskStmt->fetch();
    
    $totalTasks = $taskStats['total_tasks'] ?? 0;
    $activeTasks = $taskStats['active_tasks'] ?? 0;
    $completedTasks = $taskStats['completed_tasks'] ?? 0;
    
    echo json_encode([
        'has_active_tasks' => $activeTasks > 0,
        'can_delete' => $activeTasks == 0,
        'total_tasks' => $totalTasks,
        'active_tasks' => $activeTasks,
        'completed_tasks' => $completedTasks
    ]);
    exit();
}
    // ============== TOGGLE USER STATUS ==============
    if ($action == 'toggle_user') {
        $userId = $_POST['user_id'];
        $status = $_POST['status'];
        
        // Check if trying to deactivate last admin
        if ($status == 0) {
            $checkStmt = $db->prepare("
                SELECT COUNT(*) as count 
                FROM users 
                WHERE role_id = (SELECT id FROM roles WHERE name = 'Admin') 
                AND is_active = 1
            ");
            $checkStmt->execute();
            $adminCount = $checkStmt->fetch()['count'];
            
            if ($adminCount <= 1) {
                $user = $userModel->getUserById($userId);
                if ($user && $user['role_name'] == 'Admin') {
                    echo json_encode([
                        'success' => false,
                        'message' => 'Cannot deactivate the last active admin user'
                    ]);
                    exit();
                }
            }
        }
        
        $stmt = $db->prepare("UPDATE users SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $result = $stmt->execute([$status, $userId]);
        
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
    
    // ============== CLEAR SESSION ==============
    if ($action == 'clear_session') {
        unset($_SESSION['created_user']);
        unset($_SESSION['email_sent']);
        echo json_encode(['success' => true]);
        exit();
    }
}
?>
