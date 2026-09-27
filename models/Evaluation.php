<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

class Evaluation {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function getPerformanceReport($userId) {
        $performance = calculatePerformance($userId);
        $user = getUserById($userId);
        
        return [
            'user' => $user,
            'performance' => $performance
        ];
    }
    
    public function getAllPerformanceReports() {
        $users = getUsersByRole('Faculty');
        $reports = [];
        
        foreach ($users as $user) {
            $performance = calculatePerformance($user['id']);
            $reports[] = [
                'user' => $user,
                'performance' => $performance
            ];
        }
        
        return $reports;
    }
    
    public function getCategoryWiseMarks($userId) {
        $stmt = $this->db->prepare("
            SELECT tc.name as category_name, 
                   COUNT(DISTINCT t.id) as task_count,
                   AVG(te.marks_obtained) as average_marks,
                   MAX(te.marks_obtained) as max_marks,
                   MIN(te.marks_obtained) as min_marks,
                   SUM(te.marks_obtained) as total_marks
            FROM tasks t
            JOIN task_submissions ts ON ts.task_id = t.id
            JOIN task_evaluations te ON te.submission_id = ts.id
            JOIN task_categories tc ON t.category_id = tc.id
            WHERE t.user_id = ? AND te.status = 'Approved'
            GROUP BY tc.id
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
    
    public function getEvaluationsByTask($taskId) {
        $stmt = $this->db->prepare("
            SELECT te.*, u.full_name as evaluated_by_name
            FROM task_evaluations te
            JOIN users u ON te.evaluated_by = u.id
            WHERE te.task_id = ?
            ORDER BY te.evaluated_at DESC
        ");
        $stmt->execute([$taskId]);
        return $stmt->fetchAll();
    }
    
    public function getEvaluationBySubmissionId($submissionId) {
        $stmt = $this->db->prepare("
            SELECT te.*, u.full_name as evaluated_by_name
            FROM task_evaluations te
            JOIN users u ON te.evaluated_by = u.id
            WHERE te.submission_id = ?
            ORDER BY te.evaluated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$submissionId]);
        return $stmt->fetch();
    }
    
    public function getSubmissionStats() {
        $stats = [];
        
        // Total submissions
        $stmt = $this->db->query("SELECT COUNT(*) as total FROM task_submissions");
        $stats['total'] = $stmt->fetch()['total'];
        
        // Pending submissions
        $stmt = $this->db->query("SELECT COUNT(*) as pending FROM task_submissions WHERE status = 'Pending'");
        $stats['pending'] = $stmt->fetch()['pending'];
        
        // Approved submissions
        $stmt = $this->db->query("SELECT COUNT(*) as approved FROM task_submissions WHERE status = 'Approved'");
        $stats['approved'] = $stmt->fetch()['approved'];
        
        // Rejected submissions
        $stmt = $this->db->query("SELECT COUNT(*) as rejected FROM task_submissions WHERE status = 'Rejected'");
        $stats['rejected'] = $stmt->fetch()['rejected'];
        
        return $stats;
    }
}
?>
