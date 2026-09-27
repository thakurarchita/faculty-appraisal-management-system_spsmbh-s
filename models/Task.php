<?php
require_once __DIR__ . '/../config/database.php';

class Task {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function createTask($data) {
        $stmt = $this->db->prepare("
            INSERT INTO tasks (user_id, assigned_by, task_title, task_description, 
                             category_id, due_date, priority, attachment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $data['user_id'],
            $data['assigned_by'],
            $data['task_title'],
            $data['task_description'],
            $data['category_id'],
            $data['due_date'],
            $data['priority'],
            $data['attachment'] ?? null
        ]);
    }
    
    public function createTemplate($data) {
        $stmt = $this->db->prepare("
            INSERT INTO task_templates (task_name, category_id, employee_type, is_default, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $data['task_name'],
            $data['category_id'],
            $data['employee_type'],
            $data['is_default'] ?? 0,
            $data['is_active'] ?? 1,
            $data['created_by']
        ]);
    }
    
    public function getTasksByUser($userId, $status = null) {
        $sql = "SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name 
                FROM tasks t 
                JOIN task_categories tc ON t.category_id = tc.id 
                JOIN users u ON t.assigned_by = u.id 
                WHERE t.user_id = ?";
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId, $status]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
        }
        
        return $stmt->fetchAll();
    }
    
    public function getTaskById($id) {
        $stmt = $this->db->prepare("
            SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name,
                   u2.full_name as user_name
            FROM tasks t 
            JOIN task_categories tc ON t.category_id = tc.id 
            JOIN users u ON t.assigned_by = u.id 
            JOIN users u2 ON t.user_id = u2.id 
            WHERE t.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function submitTask($taskId, $userId, $data) {
        $stmt = $this->db->prepare("
            INSERT INTO task_submissions (task_id, submitted_by, submission_text, attachment, submitted_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $result = $stmt->execute([$taskId, $userId, $data['submission_text'], $data['attachment'] ?? null, date('Y-m-d H:i:s')]);
        
        if ($result) {
            // Update task status
            $stmt2 = $this->db->prepare("UPDATE tasks SET status = 'Submitted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt2->execute([$taskId]);
            
            return true;
        }
        return false;
    }
    
 
    
   
    
    public function getPendingSubmissions() {
        $stmt = $this->db->query("
            SELECT 
                ts.*, 
                t.task_title, 
                t.task_description,
                t.category_id,
                u.full_name as employee_name, 
                u.employee_id,
                tc.name as category_name,
                t.id as task_id,
                t.user_id,
                t.due_date
            FROM task_submissions ts
            JOIN tasks t ON ts.task_id = t.id
            JOIN users u ON t.user_id = u.id
            JOIN task_categories tc ON t.category_id = tc.id
            WHERE ts.status = 'Pending'
            ORDER BY ts.submitted_at ASC
        ");
        return $stmt->fetchAll();
    }
    
   
    
    public function getSubmissionById($id) {
        $stmt = $this->db->prepare("
            SELECT ts.*, t.task_title, t.task_description, u.full_name as employee_name,
                   u.employee_id, tc.name as category_name, tc.id as category_id,
                   t.user_id, t.due_date
            FROM task_submissions ts
            JOIN tasks t ON ts.task_id = t.id
            JOIN users u ON t.user_id = u.id
            JOIN task_categories tc ON t.category_id = tc.id
            WHERE ts.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function evaluateSubmission($submissionId, $data) {
        $this->db->beginTransaction();
        
        try {
            // Get submission and task details
            $submission = $this->getSubmissionById($submissionId);
            if (!$submission) {
                throw new Exception("Submission not found");
            }
            
            // Check marks don't exceed category max
            $categoryMax = getCategoryMaxMarks($submission['category_id']);
            if ($data['marks_obtained'] > $categoryMax) {
                throw new Exception("Marks cannot exceed category maximum of {$categoryMax}");
            }
            
            // Insert evaluation
            $stmt = $this->db->prepare("
                INSERT INTO task_evaluations (task_id, submission_id, evaluated_by, 
                                            marks_obtained, remarks, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $submission['task_id'],
                $submissionId,
                $data['evaluated_by'],
                $data['marks_obtained'],
                $data['remarks'],
                $data['status']
            ]);
            
            // Update submission status
            $stmt2 = $this->db->prepare("
                UPDATE task_submissions SET status = ? WHERE id = ?
            ");
            $stmt2->execute([$data['status'], $submissionId]);
            
            // Update task status
            $stmt3 = $this->db->prepare("
                UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
            ");
            $stmt3->execute([$data['status'], $submission['task_id']]);
            
            // Add notification
            $notificationTitle = "Task {$data['status']} - {$submission['task_title']}";
            $notificationMessage = "Your task has been {$data['status']} with marks: {$data['marks_obtained']}";
            addNotification($submission['user_id'], $notificationTitle, $notificationMessage);
            
            // Send email
            $mailer = new Mailer();
            $user = (new User())->getUserById($submission['user_id']);
            if ($user) {
                $mailer->sendTaskEvaluatedEmail(
                    $user['email'],
                    $submission['task_title'],
                    $data['status'],
                    $data['marks_obtained']
                );
            }
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Evaluation Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updateTask($id, $data) {
        $stmt = $this->db->prepare("
            UPDATE tasks 
            SET task_title = ?, task_description = ?, category_id = ?, due_date = ?, priority = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        return $stmt->execute([
            $data['task_title'],
            $data['task_description'],
            $data['category_id'],
            $data['due_date'],
            $data['priority'] ?? 'Medium',
            $id
        ]);
    }
    
    public function deleteTask($id) {
        $this->db->beginTransaction();
        
        try {
            // First, delete evaluations for submissions of this task
            $stmt1 = $this->db->prepare("
                DELETE FROM task_evaluations 
                WHERE task_id = ?
            ");
            $stmt1->execute([$id]);
            
            // Second, delete submissions for this task
            $stmt2 = $this->db->prepare("
                DELETE FROM task_submissions 
                WHERE task_id = ?
            ");
            $stmt2->execute([$id]);
            
            // Finally, delete the task itself
            $stmt3 = $this->db->prepare("
                DELETE FROM tasks 
                WHERE id = ?
            ");
            $stmt3->execute([$id]);
            
            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Task Delete Error: " . $e->getMessage());
            return false;
        }
    }
}
?>
