<?php
// Individual Performance Report Template - A4 Portrait Optimized
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/login');
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../models/User.php';

$db = getDB();

// If accessed directly via URL with GET parameters
if (!isset($selectedUser) || empty($selectedUser)) {
    $selectedUserId = $_GET['user_id'] ?? null;
    if (!$selectedUserId) {
        die('Error: No user selected for appraisal report.');
    }
    
    $selectedUser = getUserById($selectedUserId);
    if (!$selectedUser) {
        die('Error: User not found.');
    }
    
    $activeYearObj = getActiveAcademicYear();
    $defaultYearName = $activeYearObj ? $activeYearObj['year_name'] : '2025-2026';
    
    $reportMode = $_GET['report_mode'] ?? 'yearly';
    $selectedYear = $_GET['year'] ?? $defaultYearName;
    $selectedMonth = !empty($_GET['month']) ? $_GET['month'] : ($reportMode === 'monthly' ? date('n') : 'all');
    
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
    
    $evalMonth = ($reportMode === 'monthly' && $selectedMonth !== 'all') ? $selectedMonth : null;
    $userPerformance = calculatePerformance($selectedUserId, $selectedYear, $evalMonth);
    $categories = getTaskCategories();
    $categoryMaxMarks = getCategoriesWithMaxMarks();
}

$user = $selectedUser;
$tasks = $userTasks ?? [];
$performance = $userPerformance ?? ['total' => 0, 'scores' => []];
$categoriesList = $categories ?? [];
$maxMarks = $categoryMaxMarks ?? [];
$principalName = $_SESSION['full_name'] ?? 'Principal';
$collegeName = "College of Architecture, Kolhapur";
$collegeAddress = "Mangalwar Peth, Kolhapur - 416012";

// Period Info
$appraisalPeriod = $reportPeriodText ?? ("Appraisal Year: " . ($selectedYear ?? date('Y')));
$reportGenerationDate = date('d F Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty Appraisal Report - <?php echo htmlspecialchars($user['full_name']); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 12mm 12mm 12mm;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
            background: #fff;
            color: #1a202c;
            font-size: 11.5px;
            line-height: 1.35;
        }
        .container {
            width: 100%;
            max-width: 190mm;
            margin: 0 auto;
            border: 1.5px solid #2d3748;
            padding: 15px 18px;
            border-radius: 4px;
        }
        
        /* Header */
        .header {
            text-align: center;
            border-bottom: 2px solid #2d3748;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }
        .header h1 {
            font-size: 20px;
            font-weight: 700;
            color: #1a365d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .header h2 {
            font-size: 11px;
            font-weight: 600;
            color: #4a5568;
            margin-top: 1px;
        }
        .header p.institution-meta {
            font-size: 10px;
            font-style: italic;
            color: #718096;
            margin-top: 1px;
        }
        .report-title-badge {
            display: inline-block;
            margin-top: 6px;
            padding: 3px 14px;
            background: #2b6cb0;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            border-radius: 3px;
        }
        
        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .info-table td {
            padding: 4px 6px;
            border: 1px solid #e2e8f0;
            font-size: 10.5px;
        }
        .info-table td.label {
            font-weight: 700;
            background: #f7fafc;
            color: #2d3748;
            width: 18%;
        }
        .info-table td.value {
            color: #1a202c;
            width: 32%;
        }
        
        /* Summary Score Card */
        .score-banner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ebf8ff;
            border: 1.5px solid #bee3f8;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 10px;
        }
        .score-banner .title {
            font-weight: 700;
            color: #2b6cb0;
            font-size: 11.5px;
            text-transform: uppercase;
        }
        .score-banner .total-score {
            font-size: 16px;
            font-weight: 800;
            color: #2c5282;
        }
        
        /* Summary Category Table */
        .summary-scores th, .summary-scores td {
            border: 1px solid #cbd5e0;
            padding: 5px 6px;
            text-align: center;
            font-size: 10px;
        }
        .summary-scores th {
            background: #edf2f7;
            font-weight: 700;
            color: #2d3748;
        }
        .summary-scores td.highlight {
            font-weight: 700;
            background: #feebc8;
            color: #7b341e;
        }
        
        /* Marks Detail Table */
        .marks-table th, .marks-table td {
            border: 1px solid #cbd5e0;
            padding: 5px 6px;
            font-size: 10px;
        }
        .marks-table th {
            background: #1a365d;
            color: #ffffff;
            font-weight: 600;
            text-align: center;
        }
        .marks-table tr.category-row {
            background: #f7fafc;
            font-weight: 700;
        }
        .marks-table tr.task-row td {
            font-size: 9.5px;
            color: #4a5568;
            padding-top: 3px;
            padding-bottom: 3px;
        }
        .marks-table tr.total-row {
            background: #edf2f7;
            font-weight: 800;
            font-size: 10.5px;
        }
        
        /* Footer & Signatures */
        .footer-section {
            margin-top: 15px;
            padding-top: 8px;
        }
        .signatures {
            display: flex;
            justify-content: space-between;
            margin-top: 30px;
            padding: 0 10px;
        }
        .sig-block {
            text-align: center;
            width: 140px;
        }
        .sig-line {
            border-top: 1.2px solid #2d3748;
            margin-bottom: 4px;
        }
        .sig-label {
            font-size: 10px;
            font-weight: 700;
            color: #2d3748;
        }
        .sig-sub {
            font-size: 9px;
            color: #718096;
        }
        .meta-bottom {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
            font-size: 9px;
            color: #718096;
            border-top: 1px dashed #e2e8f0;
            padding-top: 5px;
        }
        
        @media print {
            body { padding: 0; }
            .container { border: 1.5px solid #000; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <h1><?php echo htmlspecialchars($collegeName); ?></h1>
        <h2><?php echo htmlspecialchars($collegeAddress); ?></h2>
        <p class="institution-meta">(Approved by COA, New Delhi & Affiliated to Shivaji University, Kolhapur)</p>
        <div class="report-title-badge">Faculty Performance Appraisal Report</div>
    </div>

    <!-- Employee Information -->
    <table class="info-table">
        <tr>
            <td class="label">Faculty Name</td>
            <td class="value"><strong><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></strong></td>
            <td class="label">Employee ID</td>
            <td class="value"><strong><?php echo htmlspecialchars($user['employee_id'] ?? 'N/A'); ?></strong></td>
        </tr>
        <tr>
            <td class="label">Designation</td>
            <td class="value"><?php echo htmlspecialchars($user['designation'] ?? 'Faculty'); ?></td>
            <td class="label">Employee Type</td>
            <td class="value"><?php echo htmlspecialchars($user['employee_type'] ?? 'Teaching'); ?></td>
        </tr>
        <tr>
            <td class="label">Email Address</td>
            <td class="value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
            <td class="label">Department</td>
            <td class="value"><?php echo htmlspecialchars($user['department_name'] ?? 'Architecture'); ?></td>
        </tr>
        <tr>
            <td class="label">Appraisal Period</td>
            <td class="value"><strong><?php echo htmlspecialchars($appraisalPeriod); ?></strong></td>
            <td class="label">Report Date</td>
            <td class="value"><?php echo htmlspecialchars($reportGenerationDate); ?></td>
        </tr>
    </table>

    <!-- Category Summary Scores -->
    <table class="summary-scores">
        <thead>
            <tr>
                <th style="width: 18%;">Overall Score</th>
                <?php foreach (($performance['scores'] ?? []) as $catName => $score): ?>
                <th><?php echo htmlspecialchars($catName); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="highlight" style="font-size: 13px; font-weight: 800;">
                    <?php echo $performance['total']; ?> / 100
                </td>
                <?php foreach (($performance['scores'] ?? []) as $score): ?>
                <td style="font-size: 11px; font-weight: 600;">
                    <?php echo round($score, 1); ?>
                </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>

    <!-- Task Breakdown -->
    <table class="marks-table">
        <thead>
            <tr>
                <th style="text-align: left; width: 44%;">Category / Approved Task</th>
                <th style="width: 14%;">Task Count</th>
                <th style="width: 14%;">Total Marks</th>
                <th style="width: 14%;">Average</th>
                <th style="width: 14%;">Max Marks</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $grandTotalMarks = 0;
            $grandTaskCount = 0;
            foreach ($categoriesList as $cat) {
                $catTasks = array_filter($tasks, function($t) use ($cat) {
                    return ($t['category_id'] ?? null) == ($cat['id'] ?? null) && isset($t['marks_obtained']);
                });
                $total = array_sum(array_column($catTasks, 'marks_obtained'));
                $count = count($catTasks);
                $avg = $count ? round($total / $count, 1) : 0;
                $mx = $maxMarks[$cat['id']]['max_marks'] ?? 0;
                $grandTotalMarks += $total;
                $grandTaskCount += $count;
            ?>
            <tr class="category-row">
                <td style="color: #1a365d;"><?php echo htmlspecialchars($cat['name']); ?></td>
                <td style="text-align: center;"><?php echo $count; ?></td>
                <td style="text-align: center;"><?php echo round($total, 1); ?></td>
                <td style="text-align: center; font-weight: 700;"><?php echo $avg; ?></td>
                <td style="text-align: center; color: #718096;"><?php echo $mx; ?></td>
            </tr>
            <?php foreach ($catTasks as $t): ?>
            <tr class="task-row">
                <td style="padding-left: 18px;">• <?php echo htmlspecialchars($t['task_title']); ?></td>
                <td style="text-align: center; color: #a0aec0;">-</td>
                <td style="text-align: center;"><?php echo $t['marks_obtained']; ?></td>
                <td style="text-align: center; color: #a0aec0;">-</td>
                <td style="text-align: center; color: #a0aec0;">-</td>
            </tr>
            <?php endforeach; ?>
            <?php } ?>
            <tr class="total-row">
                <td style="text-transform: uppercase;">Final Cumulative Assessment</td>
                <td style="text-align: center;"><?php echo $grandTaskCount; ?></td>
                <td style="text-align: center;"><?php echo round($grandTotalMarks, 1); ?></td>
                <td style="text-align: center; color: #2b6cb0; font-size: 11.5px;"><?php echo $grandTaskCount ? round($grandTotalMarks / $grandTaskCount, 1) : 0; ?></td>
                <td style="text-align: center; color: #2b6cb0; font-size: 11.5px;"><?php echo $performance['total']; ?> / 100</td>
            </tr>
        </tbody>
    </table>

    <!-- Footer & Signatures -->
    <div class="footer-section">
        <div class="signatures">
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Faculty Signature</div>
                <div class="sig-sub"><?php echo htmlspecialchars($user['full_name']); ?></div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">HOD / Committee</div>
                <div class="sig-sub">Department Reviewer</div>
            </div>
            <div class="sig-block">
                <div class="sig-line"></div>
                <div class="sig-label">Principal Signature</div>
                <div class="sig-sub"><?php echo htmlspecialchars($principalName); ?></div>
            </div>
        </div>

        <div class="meta-bottom">
            <span>* System-generated appraisal report containing approved task records.</span>
            <span>Printed On: <?php echo date('d-m-Y H:i'); ?> | A4 Portrait</span>
        </div>
    </div>
</div>

<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
<script>
    window.onload = function() {
        window.print();
    };
</script>
<?php endif; ?>
</body>
</html>
