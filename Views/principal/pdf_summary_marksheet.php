<?php
// Summary Performance Report Template - A4 Portrait Optimized
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

if (!isset($allFacultyPerformance) || empty($allFacultyPerformance)) {
    $userModel = new User();
    $allUsers = $userModel->getAllUsers();
    $facultyUsers = array_filter($allUsers, function($u) {
        return $u['role_name'] == 'Faculty';
    });
    
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
    
    $evalMonthForSummary = ($reportMode === 'monthly' && $selectedMonth !== 'all') ? $selectedMonth : null;
    $allFacultyPerformance = [];
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
}

$principalName = $_SESSION['full_name'] ?? 'Principal';
$collegeName = "College of Architecture, Kolhapur";
$collegeAddress = "Mangalwar Peth, Kolhapur - 416012";
$generatedDate = date('d F Y');
$appraisalPeriod = $reportPeriodText ?? ("Appraisal Year: " . ($selectedYear ?? date('Y')));

// Sort by total score descending
usort($allFacultyPerformance, function($a, $b) {
    return ($b['performance']['total'] ?? 0) - ($a['performance']['total'] ?? 0);
});

// Calculate statistics
$totalFaculty = count($allFacultyPerformance);
$scores = array_column(array_column($allFacultyPerformance, 'performance'), 'total');
$highestScore = !empty($scores) ? max($scores) : 0;
$averageScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;
$above80 = count(array_filter($scores, function($s) { return $s >= 80; }));

// Get faculty with scores for ranking
$facultyWithScores = [];
foreach ($allFacultyPerformance as $faculty) {
    $facultyWithScores[] = [
        'name' => $faculty['user']['full_name'] ?? 'Unknown',
        'employee_id' => $faculty['user']['employee_id'] ?? 'N/A',
        'designation' => $faculty['user']['designation'] ?? 'N/A',
        'employee_type' => $faculty['user']['employee_type'] ?? 'N/A',
        'scores' => $faculty['performance']['scores'] ?? [],
        'score' => $faculty['performance']['total'] ?? 0
    ];
}

$itemsPerPage = 18;
$totalPages = max(1, ceil(count($facultyWithScores) / $itemsPerPage));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Faculty Appraisal Summary Report - <?php echo htmlspecialchars($appraisalPeriod); ?></title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 10mm 10mm 10mm;
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
            font-size: 10.5px; 
            line-height: 1.3;
        }
        .container { 
            width: 100%;
            max-width: 190mm; 
            margin: auto; 
            border: 1.5px solid #2d3748; 
            padding: 12px 15px; 
            border-radius: 4px;
        }
        .page-break { 
            page-break-after: always; 
            margin-bottom: 20px; 
        }
        @media print { 
            .page-break { 
                page-break-after: always; 
                margin-bottom: 0; 
                border-bottom: none; 
            } 
            body { padding: 0; } 
            .container { border: 1.5px solid #000; } 
            .no-print { display: none !important; }
        }

        /* Header */
        .header { 
            text-align: center; 
            border-bottom: 2px solid #2d3748; 
            padding-bottom: 6px; 
            margin-bottom: 8px; 
        }
        .header h1 { 
            font-size: 18px; 
            font-weight: 700;
            color: #1a365d;
            text-transform: uppercase;
        }
        .header h2 { 
            font-size: 10.5px; 
            font-weight: 600; 
            color: #4a5568; 
            margin-top: 1px; 
        }
        .header p { 
            font-size: 9.5px; 
            font-style: italic; 
            color: #718096;
        }
        .title-badge { 
            display: inline-block;
            margin-top: 4px; 
            padding: 2px 12px;
            background: #2b6cb0;
            color: #fff;
            font-size: 11px; 
            font-weight: 700; 
            letter-spacing: 0.5px; 
            text-transform: uppercase;
            border-radius: 3px;
        }

        /* Info Table */
        .info-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 8px; 
        }
        .info-table td { 
            padding: 3px 5px; 
            border: 1px solid #e2e8f0;
            font-size: 10px; 
        }
        .info-table .label { 
            font-weight: 700; 
            background: #f7fafc;
            color: #2d3748;
            width: 18%; 
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin-bottom: 8px;
        }
        .stat-card-box {
            background: #f7fafc;
            border: 1px solid #cbd5e0;
            padding: 5px;
            text-align: center;
            border-radius: 4px;
        }
        .stat-card-box.highlight {
            background: #ebf8ff;
            border-color: #bee3f8;
        }
        .stat-card-box .num {
            font-size: 14px;
            font-weight: 800;
            color: #2b6cb0;
        }
        .stat-card-box .lbl {
            font-size: 9px;
            color: #4a5568;
            font-weight: 600;
        }

        /* Summary Table */
        .summary-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 8px; 
            font-size: 9.5px; 
        }
        .summary-table th { 
            background: #1a365d; 
            color: #ffffff; 
            padding: 5px 4px; 
            text-align: center; 
            border: 1px solid #1a365d; 
            font-size: 9.5px;
            font-weight: 700;
        }
        .summary-table td { 
            border: 1px solid #cbd5e0; 
            padding: 4px; 
            text-align: center; 
            vertical-align: middle; 
        }
        .summary-table tr:nth-child(even) { 
            background: #f8fafc; 
        }
        .summary-table .rank-1 { background: #fefcbf !important; font-weight: 800; color: #744210; }
        .summary-table .rank-2 { background: #edf2f7 !important; font-weight: 800; color: #2d3748; }
        .summary-table .rank-3 { background: #feebc8 !important; font-weight: 800; color: #7b341e; }
        .summary-table .score-cell { 
            font-weight: 800; 
            font-size: 11px; 
            color: #2b6cb0; 
        }

        /* Signatures */
        .signatures-area {
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            padding: 0 10px;
        }
        .sig-box {
            text-align: center;
            width: 150px;
        }
        .sig-box .line {
            border-top: 1.2px solid #2d3748;
            margin-bottom: 3px;
        }
        .sig-box .name {
            font-size: 9.5px;
            font-weight: 700;
            color: #2d3748;
        }
        .sig-box .role {
            font-size: 8.5px;
            color: #718096;
        }

        .bottom-bar {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 8.5px;
            color: #718096;
            border-top: 1px dashed #cbd5e0;
            padding-top: 4px;
        }
    </style>
</head>
<body>
    <?php for ($page = 0; $page < $totalPages; $page++): 
        $start = $page * $itemsPerPage;
        $pageFaculty = array_slice($facultyWithScores, $start, $itemsPerPage);
        $isLastPage = ($page == $totalPages - 1);
    ?>
    <div class="container <?php echo ($page < $totalPages - 1) ? 'page-break' : ''; ?>">
        <!-- Header -->
        <div class="header">
            <h1><?php echo htmlspecialchars($collegeName); ?></h1>
            <h2><?php echo htmlspecialchars($collegeAddress); ?></h2>
            <p>(Approved by COA, New Delhi & Affiliated to Shivaji University, Kolhapur)</p>
            <div class="title-badge">Faculty Performance Summary Marksheet</div>
        </div>

        <?php if ($page == 0): ?>
        <!-- Report Info -->
        <table class="info-table">
            <tr>
                <td class="label">Appraisal Period</td>
                <td><strong><?php echo htmlspecialchars($appraisalPeriod); ?></strong></td>
                <td class="label">Generated Date</td>
                <td><?php echo htmlspecialchars($generatedDate); ?></td>
            </tr>
            <tr>
                <td class="label">Total Evaluated</td>
                <td><?php echo $totalFaculty; ?> Faculty Members</td>
                <td class="label">Institution</td>
                <td>Autonomous Architecture Institute</td>
            </tr>
        </table>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card-box highlight">
                <div class="num"><?php echo $totalFaculty; ?></div>
                <div class="lbl">Total Faculty</div>
            </div>
            <div class="stat-card-box highlight">
                <div class="num"><?php echo round($highestScore, 1); ?></div>
                <div class="lbl">Highest Score / 100</div>
            </div>
            <div class="stat-card-box">
                <div class="num"><?php echo round($averageScore, 1); ?></div>
                <div class="lbl">Institutional Average</div>
            </div>
            <div class="stat-card-box">
                <div class="num"><?php echo $above80; ?></div>
                <div class="lbl">Faculty Above 80%</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Summary Table -->
        <table class="summary-table">
            <thead>
                <tr>
                    <th style="width: 7%;">Rank</th>
                    <th style="width: 13%;">Emp ID</th>
                    <th style="text-align: left; width: 34%; padding-left: 6px;">Faculty Name</th>
                    <th style="width: 18%;">Designation</th>
                    <th style="width: 13%;">Type</th>
                    <th style="width: 15%;">Total Score</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = $start + 1;
                foreach ($pageFaculty as $fac): 
                    $totalScore = $fac['score'];
                    $rankClass = ($rank == 1) ? 'rank-1' : (($rank == 2) ? 'rank-2' : (($rank == 3) ? 'rank-3' : ''));
                ?>
                <tr>
                    <td class="<?php echo $rankClass; ?>">
                        <strong>#<?php echo $rank; ?></strong>
                    </td>
                    <td><?php echo htmlspecialchars($fac['employee_id']); ?></td>
                    <td style="text-align: left; font-weight: 600; padding-left: 6px; color: #1a202c;">
                        <?php echo htmlspecialchars($fac['name']); ?>
                    </td>
                    <td style="color: #4a5568;"><?php echo htmlspecialchars($fac['designation']); ?></td>
                    <td><span style="font-size: 8.5px; background: #edf2f7; padding: 1px 5px; border-radius: 3px;"><?php echo htmlspecialchars($fac['employee_type']); ?></span></td>
                    <td>
                        <span class="score-cell"><?php echo round($totalScore, 1); ?></span>
                        <small style="color: #718096;">/100</small>
                    </td>
                </tr>
                <?php 
                $rank++;
                endforeach; 
                ?>
            </tbody>
            <?php if ($isLastPage): ?>
            <tfoot>
                <tr style="background: #edf2f7; font-weight: 800;">
                    <td colspan="4" style="text-align: right; padding-right: 10px;">Institutional Cumulative:</td>
                    <td colspan="2" style="color: #2b6cb0; font-size: 10.5px;">Avg: <?php echo round($averageScore, 1); ?> / 100</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <!-- Signatures on Last Page -->
        <?php if ($isLastPage): ?>
        <div class="signatures-area">
            <div class="sig-box">
                <div class="line"></div>
                <div class="name">Appraisal Coordinator</div>
                <div class="role">Internal Quality Assurance Cell</div>
            </div>
            <div class="sig-box">
                <div class="line"></div>
                <div class="name"><?php echo htmlspecialchars($principalName); ?></div>
                <div class="role">Principal / Head of Institution</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="bottom-bar">
            <span>* Performance computed from approved task submissions.</span>
            <span>Page <?php echo ($page + 1); ?> of <?php echo $totalPages; ?> | Printed On: <?php echo date('d-m-Y H:i'); ?></span>
        </div>
    </div>
    <?php endfor; ?>

<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
<script>
    window.onload = function() {
        window.print();
    };
</script>
<?php endif; ?>
</body>
</html>
