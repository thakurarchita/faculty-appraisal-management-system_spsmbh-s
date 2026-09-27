<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is principal
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Principal') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Evaluation.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Generate PDF Report
    if ($action == 'generate_pdf') {
        $facultyId = $_POST['faculty_id'] ?? null;
        
        if ($facultyId) {
            $evaluationModel = new Evaluation();
            $report = $evaluationModel->getPerformanceReport($facultyId);
            $categoryMarks = $evaluationModel->getCategoryWiseMarks($facultyId);
            
            // Generate PDF logic here using a PDF library
            // For now, return JSON
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => [
                    'report' => $report,
                    'category_marks' => $categoryMarks
                ]
            ]);
            exit();
        }
    }
    
    // Generate Excel Report
    if ($action == 'generate_excel') {
        $evaluationModel = new Evaluation();
        $reports = $evaluationModel->getAllPerformanceReports();
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $reports
        ]);
        exit();
    }
}
?>
