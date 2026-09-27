<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/mail.php';

class User {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function authenticate($email, $password) {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            WHERE u.email = ? AND u.is_active = 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user && verifyPassword($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['employee_id'] = $user['employee_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role_name'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['employee_type'] = $user['employee_type'];
            $_SESSION['is_first_login'] = $user['is_first_login'];
            
            logActivity($user['id'], 'Login', 'User logged in successfully');
            return true;
        }
        return false;
    }
    
    /**
     * Check if email already exists
     */
    public function emailExists($email) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    /**
     * Check if employee ID already exists
     */
    public function employeeIdExists($employeeId) {
        $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM users WHERE employee_id = ?");
        $stmt->execute([$employeeId]);
        $result = $stmt->fetch();
        return $result['count'] > 0;
    }
    
    public function createUser($data) {
        // Check if email already exists
        if ($this->emailExists($data['email'])) {
            $_SESSION['error'] = 'Email already exists! Please use a different email address.';
            return false;
        }
        
        // Check if employee ID already exists
        if ($this->employeeIdExists($data['employee_id'])) {
            $_SESSION['error'] = 'Employee ID already exists! Please contact administrator.';
            return false;
        }
        
        $temporaryPassword = generateRandomPassword();
        $hashedPassword = hashPassword($temporaryPassword);
        
        $stmt = $this->db->prepare("
            INSERT INTO users (employee_id, full_name, email, mobile, 
                             designation, employee_type, role_id, password)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([
            $data['employee_id'],
            $data['full_name'],
            $data['email'],
            $data['mobile'],
            $data['designation'],
            $data['employee_type'],
            $data['role_id'],
            $hashedPassword
        ]);
        
        if ($result) {
            $userId = $this->db->lastInsertId();
            
            // ✅ SEND WELCOME EMAIL
            try {
                $mailer = new Mailer();
                $emailSent = $mailer->sendWelcomeEmail(
                    $data['email'],
                    $data['email'],
                    $temporaryPassword
                );
                
                $_SESSION['email_sent'] = $emailSent;
                $_SESSION['created_user'] = [
                    'name' => $data['full_name'],
                    'username' => $data['email'],
                    'password' => $temporaryPassword,
                    'email' => $data['email'],
                    'email_sent' => $emailSent
                ];
                
            } catch (Exception $e) {
                $_SESSION['email_sent'] = false;
                $_SESSION['created_user'] = [
                    'name' => $data['full_name'],
                    'username' => $data['email'],
                    'password' => $temporaryPassword,
                    'email' => $data['email'],
                    'email_sent' => false
                ];
            }
            
            // Assign default tasks if faculty
            if ($data['role_id'] == 3) { // Faculty role
                $this->assignDefaultTasks($userId, $data['employee_type']);
            }
            
            logActivity($userId, 'User Created', "User {$data['full_name']} created with ID: {$data['employee_id']}");
            return $userId;
        }
        return false;
    }
    
    public function assignDefaultTasks($userId, $employeeType) {
        // Log start
        error_log("=== assignDefaultTasks called ===");
        error_log("User ID: $userId, Employee Type: $employeeType");
        
        // Get principal ID
        $stmt = $this->db->prepare("SELECT id FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Principal') LIMIT 1");
        $stmt->execute();
        $principal = $stmt->fetch();
        $principalId = $principal ? $principal['id'] : 1;
        error_log("Principal ID: $principalId");
        
        // Define categories based on employee type
        if ($employeeType == 'Teaching') {
            // Teaching gets: Research, FDP, Self Initiative ONLY
            $categories = ['Research', 'FDP', 'Self Initiative'];
            error_log("Teaching user - assigning: Research, FDP, Self Initiative");
        } else {
            // Non-Teaching gets: FDP, Self Initiative ONLY
            $categories = ['FDP', 'Self Initiative'];
            error_log("Non-Teaching user - assigning: FDP, Self Initiative");
        }
        
        // Build the query with category names
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $stmt = $this->db->prepare("
            SELECT id, task_name, category_id 
            FROM task_templates 
            WHERE is_default = 1 
            AND is_active = 1 
            AND category_id IN (SELECT id FROM task_categories WHERE name IN ($placeholders))
            AND (employee_type = ? OR employee_type = 'Both')
        ");
        
        // Merge categories with employee type for binding
        $params = array_merge($categories, [$employeeType]);
        $stmt->execute($params);
        
        $templates = $stmt->fetchAll();
        error_log("Found " . count($templates) . " templates for $employeeType");
        
        // Log the templates found
        foreach ($templates as $t) {
            error_log("Template: {$t['task_name']} (ID: {$t['id']}, Category: {$t['category_id']})");
        }
        
        $assignedCount = 0;
        
        foreach ($templates as $template) {
            // Check if task already assigned to this user
            $checkStmt = $this->db->prepare("
                SELECT COUNT(*) as count FROM tasks 
                WHERE user_id = ? 
                AND template_id = ? 
                AND status != 'Rejected'
            ");
            $checkStmt->execute([$userId, $template['id']]);
            $exists = $checkStmt->fetch();
            
            if ($exists['count'] == 0) {
                // Assign default task
                $insertStmt = $this->db->prepare("
                    INSERT INTO tasks (
                        user_id, 
                        assigned_by, 
                        task_title, 
                        task_description, 
                        category_id, 
                        is_template, 
                        template_id, 
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $result = $insertStmt->execute([
                    $userId,
                    $principalId,
                    $template['task_name'],
                    'Default assigned task',
                    $template['category_id'],
                    1,
                    $template['id'],
                    'Pending'
                ]);
                
                if ($result) {
                    $assignedCount++;
                    error_log("Assigned: {$template['task_name']} to user $userId");
                    
                    // Add notification
                    addNotification(
                        $userId,
                        'Default Task Assigned',
                        "Task '{$template['task_name']}' has been assigned to you automatically.",
                        '/faculty/tasks'
                    );
                }
            } else {
                error_log("Already assigned: {$template['task_name']} to user $userId");
            }
        }
        
        error_log("Assigned $assignedCount new tasks to user $userId");
        error_log("=== assignDefaultTasks complete ===");
        
        return $assignedCount;
    }
    
    public function changePassword($userId, $newPassword) {
        $hashedPassword = hashPassword($newPassword);
        $stmt = $this->db->prepare("
            UPDATE users 
            SET password = ?, is_first_login = 0, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        return $stmt->execute([$hashedPassword, $userId]);
    }
    
    public function getUserById($id) {
        $stmt = $this->db->prepare("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getAllUsers() {
        $stmt = $this->db->query("
            SELECT u.*, r.name as role_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            ORDER BY u.sequence ASC, u.created_at DESC
        ");
        return $stmt->fetchAll();
    }
    /**
 * Check if user has active tasks (not Approved or Rejected)
 */
public function hasActiveTasks($userId) {
    $stmt = $this->db->prepare("
        SELECT COUNT(*) as count 
        FROM tasks 
        WHERE user_id = ? 
        AND status NOT IN ('Approved', 'Rejected')
    ");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result['count'] > 0;
}

/**
 * Get user task statistics
 */
public function getUserTaskStats($userId) {
    $stmt = $this->db->prepare("
        SELECT 
            COUNT(*) as total_tasks,
            SUM(CASE WHEN status IN ('Pending', 'Initiated', 'In Progress', 'Completed', 'Submitted') THEN 1 ELSE 0 END) as active_tasks,
            SUM(CASE WHEN status IN ('Approved', 'Rejected') THEN 1 ELSE 0 END) as completed_tasks
        FROM tasks 
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

/**
 * Delete user and all associated data
 */
public function deleteUserWithTasks($userId) {
    try {
        $this->db->beginTransaction();
        
        // 1. Delete task evaluations
        $stmt = $this->db->prepare("
            DELETE FROM task_evaluations 
            WHERE task_id IN (SELECT id FROM tasks WHERE user_id = ?)
        ");
        $stmt->execute([$userId]);
        
        // 2. Delete task submissions
        $stmt = $this->db->prepare("
            DELETE FROM task_submissions 
            WHERE task_id IN (SELECT id FROM tasks WHERE user_id = ?)
        ");
        $stmt->execute([$userId]);
        
        // 3. Delete notifications
        $stmt = $this->db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 4. Delete all tasks
        $stmt = $this->db->prepare("DELETE FROM tasks WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // 5. Delete the user
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $this->db->commit();
        return true;
        
    } catch (Exception $e) {
        $this->db->rollBack();
        error_log("Delete user error: " . $e->getMessage());
        return false;
    }
}
    public function getAcademicYears() {
        $stmt = $this->db->query("SELECT * FROM academic_years ORDER BY id DESC");
        return $stmt->fetchAll();
    }
    
    public function createAcademicYear($yearData) {
        // Deactivate all other years
        $stmt = $this->db->prepare("UPDATE academic_years SET is_active = 0");
        $stmt->execute();
        
        // Insert new academic year
        $stmt = $this->db->prepare("
            INSERT INTO academic_years (year_name, start_date, end_date, is_active)
            VALUES (?, ?, ?, 1)
        ");
        return $stmt->execute([$yearData['year_name'], $yearData['start_date'], $yearData['end_date']]);
    }
}
?>
