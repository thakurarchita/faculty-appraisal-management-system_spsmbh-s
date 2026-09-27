<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is principal
if ($_SESSION['role'] !== 'Principal') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/Task.php';

// Check if TCPDF is installed
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

$userModel = new User();
$taskModel = new Task();
$db = getDB();

// Get all faculty users
$allUsers = $userModel->getAllUsers();
$facultyUsers = array_filter($allUsers, function($u) {
    return $u['role_name'] == 'Faculty';
});

// Get categories
$categories = getTaskCategories();
$categoryMaxMarks = getCategoriesWithMaxMarks();

// Academic Years & Month Options
$academicYears = getAllAcademicYears();
$activeYearObj = getActiveAcademicYear();
$defaultYearName = $activeYearObj ? $activeYearObj['year_name'] : '2025-2026';

// Request Parameters
$reportMode = $_GET['report_mode'] ?? 'yearly'; // 'yearly' or 'monthly'
$selectedYear = $_GET['year'] ?? $defaultYearName;
$selectedMonth = !empty($_GET['month']) ? $_GET['month'] : ($reportMode === 'monthly' ? date('n') : 'all');
$selectedUserId = $_GET['user_id'] ?? null;
$reportType = $_GET['type'] ?? 'individual'; // 'individual' or 'summary'
$action = $_GET['action'] ?? '';

// Build Period Text Label
$monthsList = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

if ($reportMode === 'monthly' && !empty($selectedMonth) && $selectedMonth !== 'all') {
    $monthName = $monthsList[(int)$selectedMonth] ?? 'Month ' . $selectedMonth;
    $reportPeriodText = "Monthly Appraisal: {$monthName} (Year: {$selectedYear})";
} else {
    $reportPeriodText = "Yearly Appraisal: Academic Year {$selectedYear}";
}

// Build date filtering clause for custom queries
$tasksDateFilter = "";
$range = parseYearDateRange($selectedYear);
if ($range) {
    if ($reportMode === 'monthly' && !empty($selectedMonth) && $selectedMonth !== 'all') {
        $monthNum = str_pad((int)$selectedMonth, 2, '0', STR_PAD_LEFT);
        if ($range['type'] === 'single') {
            $yearNum = substr($range['start'], 0, 4);
            $tasksDateFilter .= " AND strftime('%Y-%m', te.evaluated_at) = '{$yearNum}-{$monthNum}'";
        } else {
            $startYear = substr($range['start'], 0, 4);
            $endYear = substr($range['end'], 0, 4);
            $targetYear = ((int)$selectedMonth >= 6) ? $startYear : $endYear;
            $tasksDateFilter .= " AND strftime('%Y-%m', te.evaluated_at) = '{$targetYear}-{$monthNum}'";
        }
    } else {
        $tasksDateFilter .= " AND te.evaluated_at >= '{$range['start']}' AND te.evaluated_at <= '{$range['end']}'";
    }
}

// Initialize variables
$selectedUser = null;
$userTasks = [];
$userPerformance = null;
$categoryWiseMarks = [];

// If user selected, get their data
if ($selectedUserId) {
    $selectedUser = getUserById($selectedUserId);
    if ($selectedUser) {
        // Get ONLY APPROVED user's tasks with submissions and evaluations matching the period
        $stmt = $db->prepare("
            SELECT 
                t.*,
                tc.name as category_name,
                tc.id as category_id,
                ts.submission_text,
                ts.submitted_at,
                ts.attachment as submission_attachment,
                te.marks_obtained,
                te.remarks as evaluation_remarks,
                te.status as evaluation_status,
                te.evaluated_at,
                u.full_name as assigned_by_name
            FROM tasks t
            JOIN task_categories tc ON t.category_id = tc.id
            JOIN users u ON t.assigned_by = u.id
            LEFT JOIN task_submissions ts ON ts.task_id = t.id
            LEFT JOIN task_evaluations te ON te.submission_id = ts.id
            WHERE t.user_id = ? 
            AND te.status = 'Approved'
            {$tasksDateFilter}
            ORDER BY tc.name, t.created_at DESC
        ");
        $stmt->execute([$selectedUserId]);
        $userTasks = $stmt->fetchAll();
        
        // Also get ALL tasks matching user for general view
        $stmtAll = $db->prepare("
            SELECT 
                t.*,
                tc.name as category_name,
                tc.id as category_id,
                ts.submission_text,
                ts.submitted_at,
                ts.attachment as submission_attachment,
                te.marks_obtained,
                te.remarks as evaluation_remarks,
                te.status as evaluation_status,
                te.evaluated_at,
                u.full_name as assigned_by_name
            FROM tasks t
            JOIN task_categories tc ON t.category_id = tc.id
            JOIN users u ON t.assigned_by = u.id
            LEFT JOIN task_submissions ts ON ts.task_id = t.id
            LEFT JOIN task_evaluations te ON te.submission_id = ts.id
            WHERE t.user_id = ?
            ORDER BY tc.name, t.created_at DESC
        ");
        $stmtAll->execute([$selectedUserId]);
        $allUserTasks = $stmtAll->fetchAll();
        
        // Calculate performance with selected year and month
        $evalMonth = ($reportMode === 'monthly' && $selectedMonth !== 'all') ? $selectedMonth : null;
        $userPerformance = calculatePerformance($selectedUserId, $selectedYear, $evalMonth);
        
        // Get category-wise marks
        $catStmt = $db->prepare("
            SELECT 
                tc.id as category_id,
                tc.name as category_name,
                tc.max_marks as category_max_marks,
                COUNT(DISTINCT t.id) as task_count,
                SUM(te.marks_obtained) as total_marks,
                AVG(te.marks_obtained) as average_marks,
                MAX(te.marks_obtained) as max_marks,
                MIN(te.marks_obtained) as min_marks
            FROM tasks t
            JOIN task_categories tc ON t.category_id = tc.id
            LEFT JOIN task_submissions ts ON ts.task_id = t.id
            LEFT JOIN task_evaluations te ON te.submission_id = ts.id
            WHERE t.user_id = ? AND te.status = 'Approved'
            {$tasksDateFilter}
            GROUP BY tc.id
            ORDER BY tc.name
        ");
        $catStmt->execute([$selectedUserId]);
        $categoryWiseMarks = $catStmt->fetchAll();
    }
}

// Get all faculty performance for summary report with period filter
$allFacultyPerformance = [];
$evalMonthForSummary = ($reportMode === 'monthly' && $selectedMonth !== 'all') ? $selectedMonth : null;
foreach ($facultyUsers as $faculty) {
    $perf = calculatePerformance($faculty['id'], $selectedYear, $evalMonthForSummary);
    if (!isset($perf['total'])) {
        $perf['total'] = 0;
    }
    if (!isset($perf['scores'])) {
        $perf['scores'] = [];
    }
    $allFacultyPerformance[] = [
        'user' => $faculty,
        'performance' => $perf
    ];
}

// Sort by total score descending
if (!empty($allFacultyPerformance)) {
    usort($allFacultyPerformance, function($a, $b) {
        return ($b['performance']['total'] ?? 0) - ($a['performance']['total'] ?? 0);
    });
}

// Calculate summary statistics - FIXED
$totalFaculty = count($facultyUsers);
$topScore = 0;
$avgScore = 0;
$above70 = 0;
$allScores = [];

if (!empty($allFacultyPerformance)) {
    foreach ($allFacultyPerformance as $faculty) {
        $score = isset($faculty['performance']['total']) ? (float)$faculty['performance']['total'] : 0;
        $allScores[] = $score;
        if ($score > $topScore) {
            $topScore = $score;
        }
        if ($score >= 70) {
            $above70++;
        }
    }
    $avgScore = !empty($allScores) ? array_sum($allScores) / count($allScores) : 0;
}

// Handle Summary Report PDF Generation
if ($action == 'summary_pdf') {
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        die('TCPDF library not installed. Please run: composer require tecnickcom/tcpdf');
    }
    
    // Clear output buffers to prevent corruption
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 10);
    
    // Include the summary marksheet template
    ob_start();
    include __DIR__ . '/pdf_summary_marksheet.php';
    $html = ob_get_clean();
    
    // Write HTML to PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Output PDF
    $pdf->Output('faculty_performance_summary_' . date('Y-m-d') . '.pdf', 'D');
    exit();
}

// Handle Individual PDF generation
if ($action == 'pdf' && $selectedUserId && $selectedUser) {
    // Check if TCPDF is available
    if (!class_exists('TCPDF')) {
        die('TCPDF library not installed. Please run: composer require tecnickcom/tcpdf');
    }
    
    // Clear output buffers to prevent corruption
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Create new PDF document
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Set margins - smaller margins to fit everything on one page
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 8);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 9);
    
    // Use the clean marksheet format - only approved tasks
    $user = $selectedUser;
    $tasks = $userTasks; // Only approved tasks
    $performance = $userPerformance;
    $categoriesList = $categories;
    $maxMarks = $categoryMaxMarks;
    $principalName = $_SESSION['full_name'] ?? 'Principal';
    $collegeName = "College of Architecture, Kolhapur";
    $collegeAddress = "Mangalwar Peth, Kolhapur";
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Performance Report</title>
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            body { font-family: "Times New Roman", serif; background: #fff; padding: 15px; font-size: 10px; }
            .container { max-width: 780px; margin: auto; border: 2px solid #222; padding: 15px; }
            .header { text-align: center; border-bottom: 2px solid #222; padding-bottom: 8px; margin-bottom: 10px; }
            .header h1 { font-size: 20px; }
            .header h2 { font-size: 12px; font-weight: normal; margin-top: 2px; }
            .header p { font-size: 11px; font-style: italic; }
            .title { margin-top: 8px; font-size: 18px; font-weight: bold; letter-spacing: 2px; }
            .info table, .summary table, .marks { width: 100%; border-collapse: collapse; }
            .info td { padding: 4px 5px; vertical-align: top; font-size: 10px; }
            .label { font-weight: bold; width: 120px; }
            .summary { margin: 10px 0; }
            .summary th, .summary td { border: 1px solid #444; padding: 5px 6px; text-align: center; font-size: 10px; }
            .summary th { background: #e9e9e9; }
            .marks { margin-top: 8px; }
            .marks th, .marks td { border: 1px solid #666; padding: 3px 4px; text-align: center; font-size: 9px; }
            .marks th { background: #1a2332; color: #fff; }
            .marks tr:nth-child(even) { background: #f7f7f7; }
            .marks .task-row td { text-align: left; padding-left: 20px; font-size: 9px; }
            .marks .task-row td:first-child { padding-left: 25px; }
            .footer { margin-top: 12px; }
            .sign { display: flex; justify-content: space-between; margin-top: 20px; }
            .sign div { text-align: center; width: 180px; font-size: 10px; }
            .line { border-top: 1px solid #000; margin-bottom: 3px; width: 160px; margin-left: auto; margin-right: auto; }
            .note { font-size: 8px; color: #666; font-style: italic; margin-top: 5px; text-align: center; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>' . $collegeName . '</h1>
                <h2>' . $collegeAddress . '</h2>
                <p>(An Autonomous Institution)</p>
                <div class="title">PERFORMANCE REPORT</div>
            </div>

            <div class="info">
                <table>
                    <tr>
                        <td class="label">Faculty Name</td>
                        <td>' . htmlspecialchars($user['full_name'] ?? '') . '</td>
                        <td class="label">Employee ID</td>
                        <td>' . htmlspecialchars($user['employee_id'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Designation</td>
                        <td>' . htmlspecialchars($user['designation'] ?? '') . '</td>
                        <td class="label">Employee Type</td>
                        <td>' . htmlspecialchars($user['employee_type'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Email</td>
                        <td colspan="3">' . htmlspecialchars($user['email'] ?? '') . '</td>
                    </tr>
                    <tr>
                        <td class="label">Report Date</td>
                        <td colspan="3">' . date('d F Y') . '</td>
                    </tr>
                </table>
            </div>

            <div class="summary">
                <table>
                    <tr>
                        <th>Overall</th>';
    
    // Add category headers
    $categoryOrder = array('Teaching', 'Administrative', 'FDP', 'Research', 'Self Initiative');
    $scores = $performance['scores'] ?? array();
    foreach ($categoryOrder as $cat):
        $html .= '<th>' . htmlspecialchars($cat) . '</th>';
    endforeach;
    
    $html .= '</tr>
                    <tr>
                        <td><b>' . $performance['total'] . '/100</b></td>';
    
    foreach ($categoryOrder as $cat):
        $score = isset($scores[$cat]) ? round($scores[$cat], 1) : 0;
        $html .= '<td>' . $score . '</td>';
    endforeach;
    
    $html .= '</tr>
                </table>
            </div>

            <table class="marks">
                <tr>
                    <th>Category</th>
                    <th>Task Count</th>
                    <th>Total</th>
                    <th>Average</th>
                    <th>Max Marks</th>
                </tr>';
    
    $gt = 0;
    $gc = 0;
    foreach ($categoriesList as $cat):
        $catTasks = array_filter($tasks, function($t) use ($cat) {
            return ($t['category_id'] ?? null) == ($cat['id'] ?? null) && isset($t['marks_obtained']);
        });
        $total = array_sum(array_column($catTasks, 'marks_obtained'));
        $count = count($catTasks);
        $avg = $count ? round($total / $count, 1) : 0;
        $mx = $maxMarks[$cat['id']]['max_marks'] ?? 0;
        $gt += $total;
        $gc += $count;
        
        $html .= '<tr>
                    <td><b>' . $cat['name'] . '</b></td>
                    <td>' . $count . '</td>
                    <td>' . round($total, 1) . '</td>
                    <td>' . $avg . '</td>
                    <td>' . $mx . '</td>
                </tr>';
        
        foreach ($catTasks as $t):
            $html .= '<tr class="task-row">
                        <td style="padding-left:25px;">• ' . htmlspecialchars($t['task_title']) . '</td>
                        <td></td>
                        <td>' . $t['marks_obtained'] . '</td>
                        <td></td>
                        <td></td>
                    </tr>';
        endforeach;
    endforeach;
    
    $html .= '<tr style="background: #fef9e7; font-weight: bold;">
                    <td><b>GRAND TOTAL</b></td>
                    <td><b>' . $gc . '</b></td>
                    <td><b>' . round($gt, 1) . '</b></td>
                    <td><b>' . ($gc ? round($gt / $gc, 1) : 0) . '</b></td>
                    <td><b>' . $performance['total'] . '/100</b></td>
                </tr>
            </table>

            <div class="footer">
                <div style="margin-top: 6px;">Date: ' . date('d F Y') . '</div>
                <div class="note">* Report includes only APPROVED tasks</div>
                <div class="sign">
                    <div>
                        <div class="line"></div>
                        Faculty Signature
                    </div>
                    <div>
                        <div class="line"></div>
                        ' . htmlspecialchars($principalName) . '<br>Principal
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    // Write HTML to PDF
    $pdf->writeHTML($html, true, false, true, false, '');
    
    // Output PDF
    $pdf->Output('performance_report_' . $selectedUser['employee_id'] . '_' . date('Y-m-d') . '.pdf', 'D');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reports - Principal Panel</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    
    <style>
        /* Global Styles */
        * {
            box-sizing: border-box;
        }
        
        body {
            background: #f5f6fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        /* Sidebar Styles - Mobile First */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: 280px;
            z-index: 1050;
            background: linear-gradient(180deg, #1a2332 0%, #2c3e50 100%) !important;
            transition: transform 0.3s ease-in-out;
            transform: translateX(-100%);
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar.show {
            transform: translateX(0);
        }
        
        .sidebar .nav-link {
            padding: 12px 20px;
            color: #ecf0f1 !important;
            border-radius: 8px;
            margin: 2px 10px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }
        
        .sidebar .nav-link:hover {
            background: rgba(255,255,255,0.05);
            color: #f1c40f !important;
        }
        
        .sidebar .nav-link.active {
            color: #f1c40f !important;
            background: rgba(241, 196, 15, 0.15);
        }
        
        .sidebar .nav-link i {
            margin-right: 12px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 0;
            padding: 20px 15px;
            transition: margin-left 0.3s ease;
            width: 100%;
            min-height: 100vh;
        }
        
        /* Mobile Toggle Button */
        .navbar-toggle {
            display: block;
            position: fixed;
            top: 10px;
            left: 10px;
            z-index: 1060;
            background: #1a2332;
            border: none;
            color: #f1c40f;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        
        .navbar-toggle:hover {
            background: #2c3e50;
            transform: scale(1.05);
        }
        
        .navbar-toggle:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(241, 196, 15, 0.3);
        }
        
        /* Overlay */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Page Header */
        .page-title {
            color: #1a2332;
            font-weight: 700;
        }
        .page-subtitle {
            color: #6c757d;
            font-size: 14px;
        }
        
        /* Cards */
        .card-custom {
            border-radius: 14px;
            border: none;
            box-shadow: 0 2px 15px rgba(0,0,0,0.06);
            overflow: hidden;
            margin-bottom: 20px;
        }
        .card-custom .card-header {
            background: linear-gradient(135deg, #1a2332 0%, #2c3e50 100%);
            color: #f1c40f;
            padding: 14px 22px;
            font-weight: 600;
            border-bottom: none;
        }
        .card-custom .card-body {
            padding: 20px;
            background: white;
        }
        
        /* Stat Cards */
        .stat-card {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            transition: all 0.3s;
            height: 100%;
        }
        .stat-card .number {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        .stat-card .label {
            font-size: 12px;
            color: #6c757d;
            margin: 0;
        }
        .stat-card.primary { background: #e9ecef; border-left: 4px solid #1a2332; }
        .stat-card.success { background: #d1e7dd; border-left: 4px solid #198754; }
        .stat-card.warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        .stat-card.info { background: #cff4fc; border-left: 4px solid #0dcaf0; }
        .stat-card.danger { background: #f8d7da; border-left: 4px solid #dc3545; }
        .stat-card.gold { background: #fef9e7; border-left: 4px solid #f1c40f; }
        
        /* Tables */
        .table-custom { font-size: 13px; }
        .table-custom th { background: #f8f9fa; color: #1a2332; font-weight: 600; white-space: nowrap; }
        .table-custom td { vertical-align: middle; padding: 8px 6px; }
        
        /* Status Badges */
        .status-badge { padding: 3px 12px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .status-Approved { background: #d1e7dd; color: #0f5132; }
        .status-Rejected { background: #f8d7da; color: #842029; }
        .status-Pending { background: #fff3cd; color: #856404; }
        .status-Submitted { background: #cfe2ff; color: #084298; }
        .status-InProgress { background: #cce5ff; color: #004085; }
        .status-Not Submitted { background: #f8f9fa; color: #6c757d; }
        
        /* Buttons */
        .btn-pdf {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            font-size: 13px;
        }
        .btn-pdf:hover {
            background: #c82333;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(220, 53, 69, 0.3);
        }
        .btn-pdf-summary {
            background: #1a2332;
            color: #f1c40f !important;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            font-size: 13px;
        }
        .btn-pdf-summary:hover {
            background: #2c3e50;
            color: #f1c40f !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(26, 35, 50, 0.3);
        }
        .btn-primary-custom {
            background: linear-gradient(135deg, #1a2332 0%, #2c3e50 100%);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            width: 100%;
        }
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(26, 35, 50, 0.3);
            color: #f1c40f;
        }
        
        /* Summary Card */
        .summary-card {
            border-radius: 12px;
            padding: 12px 15px;
            background: #f8f9fa;
            border-left: 4px solid #f1c40f;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        .summary-card:hover { transform: translateX(5px); background: #fef9e7; }
        .summary-card .rank {
            background: #1a2332;
            color: #f1c40f;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 12px;
            flex-shrink: 0;
        }
        .summary-card .score { font-size: 18px; font-weight: 700; color: #1a2332; }
        .summary-card .progress { height: 5px; border-radius: 10px; background: #e9ecef; }
        .summary-card .progress-bar { border-radius: 10px; background: linear-gradient(90deg, #f1c40f, #f39c12); }
        
        .rank-gold { background: #f1c40f; color: #1a2332; }
        .rank-silver { background: #bdc3c7; color: #1a2332; }
        .rank-bronze { background: #e67e22; color: white; }
        
        /* Score Display */
        .score-display {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-around;
            padding: 12px 15px;
            background: #fef9e7;
            border-radius: 10px;
            border: 1px solid #f1c40f;
            margin: 15px 0;
            gap: 10px;
        }
        .score-display .item {
            text-align: center;
            flex: 1;
            min-width: 50px;
        }
        .score-display .item .number {
            font-size: 18px;
            font-weight: 700;
            color: #1a2332;
        }
        .score-display .item .label {
            font-size: 10px;
            color: #6c757d;
        }
        .score-display .overall {
            border-left: 2px solid #f1c40f;
            padding-left: 15px;
        }
        .score-display .overall .number {
            color: #f1c40f;
            font-size: 22px;
        }
        
        /* Nav Tabs */
        .nav-tabs-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .nav-tabs {
            border-bottom: 2px solid #e9ecef;
            flex-wrap: nowrap;
            min-width: max-content;
        }
        .nav-tabs .nav-link {
            color: #1a2332;
            border: none;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            font-size: 0.9rem;
            white-space: nowrap;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        .nav-tabs .nav-link.active {
            color: #f1c40f;
            border-bottom: 3px solid #f1c40f;
            background: transparent;
        }
        .nav-tabs .nav-link:hover {
            border-color: transparent;
            background: #f8f9fc;
        }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 20px 0; }
        .empty-state i { font-size: 3rem; color: #d0c8e0; }
        .empty-state h5 { color: #1a2332; margin-top: 10px; }
        .empty-state p { color: #6c757d; font-size: 14px; }
        
        /* Principal Badge */
        .principal-badge {
            background: #f1c40f;
            color: #1a2332;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }
        
        /* Select2 Responsive */
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 8px;
            border-color: #dce1e8;
            min-height: 44px;
        }
        .select2-container--bootstrap-5 .select2-selection--single .select2-selection__rendered {
            padding: 8px 14px;
            color: #1a2332;
        }
        
        /* DataTables mobile fixes */
        .dataTables_wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .dataTables_wrapper .dataTables_filter {
            float: none !important;
            text-align: left !important;
            margin-bottom: 10px;
        }
        .dataTables_wrapper .dataTables_filter input {
            width: 100% !important;
            max-width: 300px;
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 6px 12px;
            margin-left: 0 !important;
        }
        .dataTables_wrapper .dataTables_length {
            float: none !important;
            text-align: left !important;
            margin-bottom: 10px;
        }
        .dataTables_wrapper .dataTables_length select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 4px 8px;
        }
        .dataTables_wrapper .dataTables_info {
            float: none !important;
            text-align: left !important;
            padding-top: 10px;
        }
        .dataTables_wrapper .dataTables_paginate {
            float: none !important;
            text-align: center !important;
            padding-top: 10px;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 4px 10px !important;
            margin: 0 2px !important;
        }
        
        /* Responsive */
        @media (min-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: 0;
                bottom: 0;
                width: 240px;
                transform: translateX(0);
                z-index: 1000;
            }
            
            .navbar-toggle {
                display: none;
            }
            
            .sidebar-overlay {
                display: none !important;
            }
            
            .main-content {
                margin-left: 240px;
                padding: 25px 30px;
                width: calc(100% - 240px);
            }
            
            .stat-card .number {
                font-size: 28px;
            }
            
            .dataTables_wrapper .dataTables_filter {
                float: right !important;
                text-align: right !important;
            }
            .dataTables_wrapper .dataTables_filter input {
                width: auto !important;
                margin-left: 6px !important;
            }
            .dataTables_wrapper .dataTables_length {
                float: left !important;
                text-align: left !important;
            }
            .dataTables_wrapper .dataTables_info {
                float: left !important;
                text-align: left !important;
            }
            .dataTables_wrapper .dataTables_paginate {
                float: right !important;
                text-align: right !important;
            }
        }
        
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
            
            .main-content {
                margin-left: 260px;
                width: calc(100% - 260px);
                padding: 30px 40px;
            }
            
            .card-custom .card-body {
                padding: 25px 30px;
            }
        }
        
        @media (max-width: 576px) {
            .main-content {
                padding: 15px 10px;
            }
            
            .card-custom .card-header {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
            
            .card-custom .card-body {
                padding: 15px;
            }
            
            .navbar-toggle {
                padding: 8px 12px;
                font-size: 1.2rem;
                top: 8px;
                left: 8px;
            }
            
            .page-title {
                font-size: 1.2rem;
            }
            
            .stat-card {
                padding: 10px 15px;
            }
            .stat-card .number {
                font-size: 20px;
            }
            .stat-card .label {
                font-size: 11px;
            }
            
            .table-custom {
                font-size: 11px;
            }
            .table-custom td, .table-custom th {
                padding: 5px 3px;
            }
            
            .score-display .item .number {
                font-size: 15px;
            }
            .score-display .overall .number {
                font-size: 18px;
            }
            
            .btn-pdf, .btn-pdf-summary {
                font-size: 11px;
                padding: 6px 12px;
            }
            
            .nav-tabs .nav-link {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .summary-card {
                padding: 10px 12px;
            }
            .summary-card .score {
                font-size: 16px;
            }
            .summary-card .rank {
                width: 24px;
                height: 24px;
                font-size: 11px;
            }
            
            .select2-container--bootstrap-5 .select2-selection {
                min-height: 38px;
            }
            
            .principal-badge {
                font-size: 11px;
                padding: 4px 12px;
            }
            
            .dataTables_wrapper .dataTables_filter input {
                max-width: 100%;
            }
            .dataTables_wrapper .dataTables_paginate .paginate_button {
                padding: 2px 6px !important;
                font-size: 11px;
            }
        }
        
        @media (max-width: 400px) {
            .main-content {
                padding: 10px 5px;
            }
            
            .card-custom .card-body {
                padding: 12px;
            }
            
            .stat-card .number {
                font-size: 17px;
            }
            
            .table-custom {
                font-size: 10px;
            }
            .table-custom td, .table-custom th {
                padding: 4px 2px;
            }
            
            .score-display {
                padding: 8px 10px;
                gap: 5px;
            }
            .score-display .item .number {
                font-size: 13px;
            }
            .score-display .overall .number {
                font-size: 15px;
            }
        }
        
        /* Animation */
        .fade-in {
            animation: fadeIn 0.4s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Scrollbar */
        .sidebar::-webkit-scrollbar {
            width: 5px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: transparent;
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.2);
            border-radius: 3px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* Print - Hide everything on print */
        @media print {
            body * {
                visibility: hidden;
            }
            .no-print, .no-print * {
                visibility: visible;
            }
            .no-print {
                display: none !important;
            }
        }
        
        /* Note for tasks table */
        .task-status-approved {
            border-left: 3px solid #198754;
        }
        .task-status-rejected {
            border-left: 3px solid #dc3545;
            opacity: 0.6;
        }
        .task-status-pending {
            border-left: 3px solid #ffc107;
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="navbar-toggle" id="sidebarToggle" aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>
    
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <nav class="sidebar" id="sidebar">
        <div class="position-sticky pt-3">
            <div class="text-center mb-4" style="color: #f1c40f; padding: 10px 0;">
                <i class="bi bi-mortarboard-fill" style="font-size: 2.5rem;"></i>
                <h6 style="color: #ecf0f1; margin-top: 5px;">Appraisal System</h6>
                <small style="color: #f1c40f;">Principal Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task"><i class="bi bi-plus-circle"></i> Assign Task</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/task-status"><i class="bi bi-list-task"></i> Task Status</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/evaluations"><i class="bi bi-check2-square"></i> Evaluations</a></li>
                <li class="nav-item"><a class="nav-link active" href="<?php echo BASE_URL; ?>/principal/reports"><i class="bi bi-file-earmark-pdf"></i> Reports</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/principal/settings"><i class="bi bi-sliders"></i> Performance Settings</a></li>
                <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-4 border-bottom">
                <div>
                    <h2 class="page-title"><i class="bi bi-file-earmark-text me-2" style="color: #f1c40f;"></i>Reports</h2>
                    <p class="page-subtitle">Generate individual and summary performance reports</p>
                </div>
                <span class="principal-badge mt-2 mt-sm-0">
                    <i class="bi bi-person-badge me-1"></i> Principal
                </span>
            </div>

            <!-- Report Type Tabs -->
            <div class="nav-tabs-wrapper">
                <ul class="nav nav-tabs" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $reportType == 'individual' ? 'active' : ''; ?>" 
                                id="individual-tab" data-bs-toggle="tab" data-bs-target="#individual" 
                                type="button" role="tab">
                            <i class="bi bi-person"></i> Individual Report
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $reportType == 'summary' ? 'active' : ''; ?>" 
                                id="summary-tab" data-bs-toggle="tab" data-bs-target="#summary" 
                                type="button" role="tab">
                            <i class="bi bi-people"></i> Summary Report
                        </button>
                    </li>
                </ul>
            </div>

            <div class="tab-content" id="reportTabsContent">
                <!-- INDIVIDUAL REPORT TAB -->
                <div class="tab-pane fade <?php echo $reportType == 'individual' ? 'show active' : ''; ?>" 
                     id="individual" role="tabpanel">
                    
                    <!-- Select User & Appraisal Period Filters -->
                    <div class="card-custom mt-3 fade-in">
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3 align-items-end">
                                <input type="hidden" name="type" value="individual">
                                
                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-bold"><i class="bi bi-person me-1"></i> Faculty Member <span class="text-danger">*</span></label>
                                    <select name="user_id" id="userSelect" class="form-control" required>
                                        <option value="">Search and select faculty...</option>
                                        <?php foreach ($facultyUsers as $user): ?>
                                        <option value="<?php echo $user['id']; ?>" 
                                            <?php echo $selectedUserId == $user['id'] ? 'selected' : ''; ?>>
                                            <?php echo $user['employee_id']; ?> - <?php echo $user['full_name']; ?> 
                                            (<?php echo $user['employee_type']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar3 me-1"></i> Appraisal Year</label>
                                    <select name="year" class="form-select">
                                        <?php foreach ($academicYears as $yr): ?>
                                        <option value="<?php echo htmlspecialchars($yr['year_name']); ?>"
                                            <?php echo $selectedYear == $yr['year_name'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($yr['year_name']); ?> <?php echo !empty($yr['is_default']) ? '(Default)' : ''; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-6 col-md-2">
                                    <label class="form-label fw-bold"><i class="bi bi-clock-history me-1"></i> Cycle</label>
                                    <select name="report_mode" id="individualReportMode" class="form-select" onchange="toggleMonthSelect(this.value, 'individualMonthCol')">
                                        <option value="yearly" <?php echo $reportMode === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                        <option value="monthly" <?php echo $reportMode === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>

                                <div class="col-6 col-md-2" id="individualMonthCol" style="<?php echo $reportMode === 'monthly' ? '' : 'display:none;'; ?>">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar-month me-1"></i> Month</label>
                                    <select name="month" class="form-select">
                                        <?php foreach ($monthsList as $mNum => $mName): ?>
                                        <option value="<?php echo $mNum; ?>" <?php echo $selectedMonth == $mNum ? 'selected' : ''; ?>>
                                            <?php echo $mName; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-<?php echo $reportMode === 'monthly' ? '1' : '3'; ?>">
                                    <button type="submit" class="btn btn-primary-custom w-100">
                                        <i class="bi bi-search me-1"></i> View
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Individual Report Content -->
                    <?php if ($selectedUser && $selectedUserId): ?>
                    <div class="report-section fade-in">
                        <!-- Report Header -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <div>
                                <h4 class="mb-0" style="color: #1a2332; font-size: 1.1rem;">
                                    <i class="bi bi-file-earmark-person me-2" style="color: #f1c40f;"></i>Individual Performance Report
                                </h4>
                                <span class="badge bg-primary text-white mt-1 me-1"><?php echo htmlspecialchars($reportPeriodText); ?></span>
                                <small class="text-muted d-block d-sm-inline mt-1">Generated on <?php echo date('d M Y, h:i A'); ?></small>
                            </div>
                            <div class="no-print d-flex gap-2">
                                <a href="<?php echo BASE_URL; ?>/principal/pdf-marksheet?user_id=<?php echo $selectedUserId; ?>&year=<?php echo urlencode($selectedYear); ?>&report_mode=<?php echo urlencode($reportMode); ?>&month=<?php echo urlencode($selectedMonth); ?>&print=1" 
                                   target="_blank" class="btn btn-warning btn-sm">
                                    <i class="bi bi-printer me-1"></i> Print A4 Portrait
                                </a>
                                <a href="<?php echo BASE_URL; ?>/principal/reports?action=pdf&user_id=<?php echo $selectedUserId; ?>&year=<?php echo urlencode($selectedYear); ?>&report_mode=<?php echo urlencode($reportMode); ?>&month=<?php echo urlencode($selectedMonth); ?>&type=individual" 
                                   class="btn btn-pdf btn-sm">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                                </a>
                            </div>
                        </div>

                        <!-- Employee Info Card -->
                        <div class="card-custom">
                            <div class="card-body">
                                <div class="row align-items-center g-3">
                                    <div class="col-12 col-md-7">
                                        <h4 style="color: #1a2332; margin-bottom: 5px; font-size: 1.1rem;"><?php echo htmlspecialchars($selectedUser['full_name']); ?></h4>
                                        <div class="row g-2 mt-2">
                                            <div class="col-6 col-md-4">
                                                <small class="text-muted">Employee ID</small>
                                                <p class="fw-bold mb-0"><?php echo htmlspecialchars($selectedUser['employee_id']); ?></p>
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <small class="text-muted">Designation</small>
                                                <p class="fw-bold mb-0"><?php echo htmlspecialchars($selectedUser['designation'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <small class="text-muted">Employee Type</small>
                                                <p class="fw-bold mb-0">
                                                    <span class="badge" style="background: <?php echo $selectedUser['employee_type'] == 'Teaching' ? '#d1e7dd' : '#cfe2ff'; ?>; color: <?php echo $selectedUser['employee_type'] == 'Teaching' ? '#0f5132' : '#084298'; ?>;">
                                                        <?php echo htmlspecialchars($selectedUser['employee_type'] ?? 'N/A'); ?>
                                                    </span>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-5 text-md-end">
                                        <div class="stat-card gold" style="display: inline-block; padding: 10px 20px; width: 100%; max-width: 280px;">
                                            <p class="number" style="color: #1a2332; font-size: 28px;"><?php echo $userPerformance['total']; ?>/100</p>
                                            <p class="label">Overall Performance Score</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Score Summary -->
                        <div class="score-display">
                            <?php 
                            $categoryOrder = ['Teaching', 'Administrative', 'FDP', 'Research', 'Self Initiative'];
                            $scores = $userPerformance['scores'] ?? [];
                            foreach ($categoryOrder as $cat):
                                $score = isset($scores[$cat]) ? round($scores[$cat], 1) : 0;
                            ?>
                            <div class="item">
                                <div class="number"><?php echo $score; ?></div>
                                <div class="label"><?php echo $cat; ?></div>
                            </div>
                            <?php endforeach; ?>
                            <div class="item overall">
                                <div class="number"><?php echo $userPerformance['total']; ?></div>
                                <div class="label">Overall</div>
                            </div>
                        </div>

                        <!-- Category-wise Marks -->
                        <div class="card-custom mt-3">
                            <div class="card-header">
                                <i class="bi bi-table me-2"></i> Category-wise Performance Details
                            </div>
                            <div class="card-body">
                                <?php if (count($categoryWiseMarks) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-custom">
                                        <thead>
                                            <tr>
                                                <th>Category</th>
                                                <th>Tasks</th>
                                                <th>Total Marks</th>
                                                <th>Average</th>
                                                <th>Max</th>
                                                <th>Min</th>
                                                <th>Max Possible</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($categoryWiseMarks as $cat): 
                                                $maxPossible = $categoryMaxMarks[$cat['category_id']]['max_marks'] ?? 0;
                                            ?>
                                            <tr>
                                                <td><strong><?php echo $cat['category_name']; ?></strong></td>
                                                <td><?php echo $cat['task_count']; ?></td>
                                                <td><?php echo round($cat['total_marks'] ?? 0, 1); ?></td>
                                                <td><?php echo round($cat['average_marks'] ?? 0, 1); ?></td>
                                                <td><?php echo round($cat['max_marks'] ?? 0, 1); ?></td>
                                                <td><?php echo round($cat['min_marks'] ?? 0, 1); ?></td>
                                                <td><?php echo $maxPossible; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background: #fef9e7;">
                                                <td colspan="6" class="text-end"><strong>Total Performance Score</strong></td>
                                                <td><strong style="color: #1a2332; font-size: 18px;"><?php echo $userPerformance['total']; ?>/100</strong></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="empty-state">
                                    <i class="bi bi-clipboard-x"></i>
                                    <h5>No Evaluated Tasks</h5>
                                    <p>No approved tasks found for this faculty member.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- All Tasks Details -->
                        <div class="card-custom mt-3">
                            <div class="card-header">
                                <i class="bi bi-list-ul me-2"></i> All Tasks Details
                                <span class="badge bg-success ms-2">Approved: <?php echo count(array_filter($userTasks, function($t) { return isset($t['evaluation_status']) && $t['evaluation_status'] == 'Approved'; })); ?></span>
                                <span class="badge bg-danger ms-1">Rejected: <?php echo count(array_filter($allUserTasks ?? [], function($t) { return isset($t['evaluation_status']) && $t['evaluation_status'] == 'Rejected'; })); ?></span>
                                <span class="badge bg-warning ms-1">Pending: <?php echo count(array_filter($allUserTasks ?? [], function($t) { return !isset($t['evaluation_status']) || $t['evaluation_status'] == 'Pending' || $t['evaluation_status'] == 'Submitted'; })); ?></span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-custom" id="tasksTable">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Task</th>
                                                <th>Category</th>
                                                <th>Assigned By</th>
                                                <th>Status</th>
                                                <th>Marks</th>
                                                <th>Submitted</th>
                                                <th>Evaluated</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $taskDisplay = $allUserTasks ?? $userTasks;
                                            $i = 1; 
                                            foreach ($taskDisplay as $task): 
                                                $isApproved = isset($task['evaluation_status']) && $task['evaluation_status'] == 'Approved';
                                                $isRejected = isset($task['evaluation_status']) && $task['evaluation_status'] == 'Rejected';
                                                $rowClass = $isApproved ? 'task-status-approved' : ($isRejected ? 'task-status-rejected' : 'task-status-pending');
                                            ?>
                                            <tr class="<?php echo $rowClass; ?>">
                                                <td><?php echo $i++; ?></td>
                                                <td><?php echo htmlspecialchars($task['task_title']); ?></td>
                                                <td><span class="badge" style="background: #e9ecef; color: #1a2332;"><?php echo $task['category_name']; ?></span></td>
                                                <td><?php echo $task['assigned_by_name']; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo $task['evaluation_status'] ?? 'Not Submitted'; ?>">
                                                        <?php echo $task['evaluation_status'] ?? 'Not Submitted'; ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($isApproved && $task['marks_obtained'] !== null): ?>
                                                        <span class="fw-bold text-success"><?php echo $task['marks_obtained']; ?></span>
                                                        <small class="text-muted">/ <?php echo $categoryMaxMarks[$task['category_id']]['max_marks'] ?? 0; ?></small>
                                                    <?php elseif ($isRejected): ?>
                                                        <span class="text-danger">Rejected</span>
                                                    <?php elseif ($task['marks_obtained'] !== null): ?>
                                                        <span class="fw-bold"><?php echo $task['marks_obtained']; ?></span>
                                                        <small class="text-muted">/ <?php echo $categoryMaxMarks[$task['category_id']]['max_marks'] ?? 0; ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo $task['submitted_at'] ? date('d M Y', strtotime($task['submitted_at'])) : '—'; ?></td>
                                                <td><?php echo $task['evaluated_at'] ? date('d M Y', strtotime($task['evaluated_at'])) : '—'; ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php if (count($taskDisplay) > 0): ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <span class="text-success">■</span> Approved tasks included in report | 
                                        <span class="text-danger">■</span> Rejected tasks excluded from report |
                                        <span class="text-warning">■</span> Pending tasks excluded from report
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($selectedUserId && !$selectedUser): ?>
                    <div class="alert alert-warning mt-3">
                        <i class="bi bi-exclamation-triangle me-2"></i> User not found. Please select a valid faculty member.
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 mt-3">
                        <i class="bi bi-file-earmark-text" style="font-size: 4rem; color: #d0c8e0;"></i>
                        <h4 class="mt-3" style="color: #1a2332;">Select a Faculty Member</h4>
                        <p class="text-muted">Choose a faculty member from the dropdown above to view their performance report.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- SUMMARY REPORT TAB -->
                <div class="tab-pane fade <?php echo $reportType == 'summary' ? 'show active' : ''; ?>" 
                     id="summary" role="tabpanel">
                    
                    <!-- Summary Report Filters Form -->
                    <div class="card-custom mt-3 fade-in">
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3 align-items-end">
                                <input type="hidden" name="type" value="summary">

                                <div class="col-12 col-md-4">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar3 me-1"></i> Appraisal Year</label>
                                    <select name="year" class="form-select">
                                        <?php foreach ($academicYears as $yr): ?>
                                        <option value="<?php echo htmlspecialchars($yr['year_name']); ?>"
                                            <?php echo $selectedYear == $yr['year_name'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($yr['year_name']); ?> <?php echo !empty($yr['is_default']) ? '(Default)' : ''; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-6 col-md-3">
                                    <label class="form-label fw-bold"><i class="bi bi-clock-history me-1"></i> Cycle</label>
                                    <select name="report_mode" id="summaryReportMode" class="form-select" onchange="toggleMonthSelect(this.value, 'summaryMonthCol')">
                                        <option value="yearly" <?php echo $reportMode === 'yearly' ? 'selected' : ''; ?>>Yearly</option>
                                        <option value="monthly" <?php echo $reportMode === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    </select>
                                </div>

                                <div class="col-6 col-md-3" id="summaryMonthCol" style="<?php echo $reportMode === 'monthly' ? '' : 'display:none;'; ?>">
                                    <label class="form-label fw-bold"><i class="bi bi-calendar-month me-1"></i> Month</label>
                                    <select name="month" class="form-select">
                                        <?php foreach ($monthsList as $mNum => $mName): ?>
                                        <option value="<?php echo $mNum; ?>" <?php echo $selectedMonth == $mNum ? 'selected' : ''; ?>>
                                            <?php echo $mName; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-2">
                                    <button type="submit" class="btn btn-primary-custom w-100">
                                        <i class="bi bi-filter me-1"></i> Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Summary Report Header with Action Buttons -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 mb-3 gap-2">
                        <div>
                            <h4 class="mb-0" style="color: #1a2332; font-size: 1.1rem;">
                                <i class="bi bi-people me-2" style="color: #f1c40f;"></i>Faculty Performance Summary
                            </h4>
                            <span class="badge bg-primary text-white mt-1 me-1"><?php echo htmlspecialchars($reportPeriodText); ?></span>
                            <small class="text-muted d-block d-sm-inline mt-1">Cumulative ranking of all faculty members</small>
                        </div>
                        <div class="no-print d-flex gap-2">
                            <a href="<?php echo BASE_URL; ?>/principal/pdf-summary-marksheet?year=<?php echo urlencode($selectedYear); ?>&report_mode=<?php echo urlencode($reportMode); ?>&month=<?php echo urlencode($selectedMonth); ?>&print=1" 
                               target="_blank" class="btn btn-warning btn-sm">
                                <i class="bi bi-printer me-1"></i> Print A4 Portrait
                            </a>
                            <a href="<?php echo BASE_URL; ?>/principal/reports?action=summary_pdf&year=<?php echo urlencode($selectedYear); ?>&report_mode=<?php echo urlencode($reportMode); ?>&month=<?php echo urlencode($selectedMonth); ?>" 
                               class="btn btn-pdf-summary btn-sm">
                                <i class="bi bi-file-earmark-pdf me-1"></i> Download PDF
                            </a>
                        </div>
                    </div>

                    <!-- Summary Statistics - FIXED with proper calculations -->
                    <div class="row g-2 g-md-3 mb-4">
                        <div class="col-6 col-md-3">
                            <div class="stat-card primary">
                                <p class="number"><?php echo $totalFaculty; ?></p>
                                <p class="label"><i class="bi bi-people me-1"></i>Total Faculty</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card gold">
                                <p class="number"><?php echo round($topScore, 1); ?></p>
                                <p class="label"><i class="bi bi-trophy me-1"></i>Highest Score</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card warning">
                                <p class="number"><?php echo round($avgScore, 1); ?></p>
                                <p class="label"><i class="bi bi-bar-chart me-1"></i>Average Score</p>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="stat-card success">
                                <p class="number"><?php echo $above70; ?></p>
                                <p class="label"><i class="bi bi-check-circle me-1"></i>Above 70%</p>
                            </div>
                        </div>
                    </div>

                    <!-- Summary Table -->
                    <div class="card-custom">
                        <div class="card-header">
                            <i class="bi bi-table me-2"></i> Faculty Performance Summary
                        </div>
                        <div class="card-body">
                            <?php if (count($allFacultyPerformance) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-custom" id="summaryTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Employee</th>
                                            <th>Name</th>
                                            <th>Type</th>
                                            <?php foreach ($categories as $cat): ?>
                                            <th><?php echo $cat['name']; ?></th>
                                            <?php endforeach; ?>
                                            <th>Total</th>
                                            <th>Rank</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $rank = 1; foreach ($allFacultyPerformance as $faculty): 
                                            $user = $faculty['user'];
                                            $perf = $faculty['performance'];
                                            $totalScore = $perf['total'] ?? 0;
                                        ?>
                                        <tr>
                                            <td><?php echo $rank; ?></td>
                                            <td><?php echo $user['employee_id']; ?></td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>/principal/reports?type=individual&user_id=<?php echo $user['id']; ?>" 
                                                   style="color: #1a2332; text-decoration: none; font-weight: 500;">
                                                    <?php echo htmlspecialchars($user['full_name']); ?>
                                                </a>
                                            </td>
                                            <td><span class="badge" style="background: #e9ecef; color: #1a2332;"><?php echo $user['employee_type'] ?? 'N/A'; ?></span></td>
                                            <?php foreach ($categories as $cat): ?>
                                            <td>
                                                <?php 
                                                $score = $perf['scores'][$cat['name']] ?? 0;
                                                $maxMarks = $categoryMaxMarks[$cat['id']]['max_marks'] ?? 0;
                                                ?>
                                                <span class="fw-bold"><?php echo round($score, 1); ?></span>
                                                <small class="text-muted">/ <?php echo $maxMarks; ?></small>
                                            </td>
                                            <?php endforeach; ?>
                                            <td>
                                                <strong style="color: #1a2332; font-size: 16px;"><?php echo round($totalScore, 1); ?></strong>
                                                <small class="text-muted">/ 100</small>
                                            </td>
                                            <td>
                                                <?php if ($rank == 1): ?>
                                                <span class="badge rank-gold" style="font-size: 12px; padding: 4px 10px;">#1</span>
                                                <?php elseif ($rank == 2): ?>
                                                <span class="badge rank-silver" style="font-size: 12px; padding: 4px 10px;">#2</span>
                                                <?php elseif ($rank == 3): ?>
                                                <span class="badge rank-bronze" style="font-size: 12px; padding: 4px 10px;">#3</span>
                                                <?php else: ?>
                                                <span class="text-muted">#<?php echo $rank; ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php $rank++; endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="empty-state">
                                <i class="bi bi-people"></i>
                                <h5>No Faculty Members Found</h5>
                                <p>There are no faculty members in the system to generate summary report.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Performers -->
                    <?php if (count($allFacultyPerformance) > 0): ?>
                    <div class="row g-2 g-md-3 mt-3">
                        <div class="col-12">
                            <div class="card-custom">
                                <div class="card-header">
                                    <i class="bi bi-bar-chart me-2"></i> Top Performers
                                </div>
                                <div class="card-body">
                                    <?php 
                                    $topPerformers = array_slice($allFacultyPerformance, 0, 5);
                                    ?>
                                    <?php foreach ($topPerformers as $index => $faculty): 
                                        $score = $faculty['performance']['total'] ?? 0;
                                        $percentage = ($score / 100) * 100;
                                    ?>
                                    <div class="summary-card">
                                        <div class="d-flex align-items-center gap-2 gap-md-3">
                                            <div class="rank <?php echo $index == 0 ? 'rank-gold' : ($index == 1 ? 'rank-silver' : ($index == 2 ? 'rank-bronze' : '')); ?>" 
                                                 style="width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 12px; background: <?php echo $index == 0 ? '#f1c40f' : ($index == 1 ? '#bdc3c7' : ($index == 2 ? '#e67e22' : '#e9ecef')); ?>; color: <?php echo $index < 3 ? '#1a2332' : '#6c757d'; ?>; flex-shrink: 0;">
                                                <?php echo $index + 1; ?>
                                            </div>
                                            <div style="flex: 1; min-width: 0;">
                                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-1">
                                                    <span><strong><?php echo htmlspecialchars($faculty['user']['full_name']); ?></strong></span>
                                                    <span class="score"><?php echo round($score, 1); ?>/100</span>
                                                </div>
                                                <div class="progress">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: <?php echo $percentage; ?>%;"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        function toggleMonthSelect(mode, colId) {
            const col = document.getElementById(colId);
            if (col) {
                col.style.display = (mode === 'monthly') ? 'block' : 'none';
            }
        }

        $(document).ready(function() {
            // Sidebar Toggle
            const sidebar = $('#sidebar');
            const overlay = $('#sidebarOverlay');
            const toggleBtn = $('#sidebarToggle');
            
            function toggleSidebar() {
                sidebar.toggleClass('show');
                overlay.toggleClass('active');
                document.body.style.overflow = sidebar.hasClass('show') ? 'hidden' : '';
            }
            
            toggleBtn.on('click', toggleSidebar);
            overlay.on('click', toggleSidebar);
            
            // Close sidebar on escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && sidebar.hasClass('show')) {
                    toggleSidebar();
                }
            });
            
            // Handle window resize - close sidebar on large screens
            $(window).on('resize', function() {
                if ($(window).width() >= 768 && sidebar.hasClass('show')) {
                    sidebar.removeClass('show');
                    overlay.removeClass('active');
                    document.body.style.overflow = '';
                }
            });

            // Initialize Select2
            $('#userSelect').select2({
                theme: 'bootstrap-5',
                placeholder: 'Search and select faculty...',
                allowClear: true,
                width: '100%',
                dropdownParent: $('#userSelect').parent()
            });

            // Initialize DataTables with responsive
            <?php if (count($allUserTasks ?? []) > 0 || count($userTasks) > 0): ?>
            $('#tasksTable').DataTable({
                pageLength: 10,
                order: [[0, 'asc']],
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                return 'Task Details';
                            }
                        }),
                        renderer: $.fn.dataTable.Responsive.renderer.tableAll({
                            tableClass: 'table table-bordered mb-0'
                        })
                    }
                },
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching tasks found"
                },
                columnDefs: [
                    { responsivePriority: 1, targets: [0, 1, 2, 4, 5] },
                    { responsivePriority: 2, targets: [3, 6, 7] }
                ]
            });
            <?php endif; ?>

            <?php if (count($allFacultyPerformance) > 0): ?>
            // Get the total number of columns in the summary table
            var summaryCols = $('#summaryTable thead th').length;
            
            $('#summaryTable').DataTable({
                pageLength: 25,
                order: [[summaryCols - 2, 'desc']],
                responsive: {
                    details: {
                        display: $.fn.dataTable.Responsive.display.modal({
                            header: function(row) {
                                return 'Faculty Performance Details';
                            }
                        }),
                        renderer: $.fn.dataTable.Responsive.renderer.tableAll({
                            tableClass: 'table table-bordered mb-0'
                        })
                    }
                },
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    infoEmpty: "Showing 0 to 0 of 0 entries",
                    infoFiltered: "(filtered from _MAX_ total entries)",
                    zeroRecords: "No matching records found"
                },
                columnDefs: [
                    { responsivePriority: 1, targets: [0, 1, 2, summaryCols - 2, summaryCols - 1] },
                    { responsivePriority: 2, targets: [3, 4] }
                ]
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>
