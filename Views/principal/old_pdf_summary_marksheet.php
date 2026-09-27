<?php
// Summary Performance Report Template
if (!isset($allFacultyPerformance) || empty($allFacultyPerformance)) {
    die('Error: No faculty performance data available');
}

$principalName = $_SESSION['full_name'] ?? 'Principal';
$collegeName = "College of Architecture, Kolhapur";
$collegeAddress = "Mangalwar Peth, Kolhapur";
$generatedDate = date('d F Y');

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
        'score' => $faculty['performance']['total'] ?? 0
    ];
}

$itemsPerPage = 20;
$totalPages = ceil(count($facultyWithScores) / $itemsPerPage);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Faculty Performance Summary Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Times New Roman", serif; background: #fff; padding: 20px; font-size: 12px; }
        .container { max-width: 1100px; margin: auto; border: 2px solid #222; padding: 25px; background: #fff; }
        .page-break { page-break-after: always; border-bottom: 2px dashed #ccc; margin-bottom: 20px; padding-bottom: 20px; }
        @media print { .page-break { page-break-after: always; border-bottom: none; margin-bottom: 0; padding-bottom: 0; } body { padding: 10px; } .container { border: 1px solid #000; } }

        .header { display: flex; align-items: center; border-bottom: 2px solid #222; padding-bottom: 15px; margin-bottom: 20px; }
        .header-logo { flex: 0 0 80px; margin-right: 20px; }
        .header-logo img { max-width: 80px; height: auto; display: block; }
        .header-content { flex: 1; text-align: center; }
        .header-content h1 { font-size: 26px; letter-spacing: 1px; margin: 0; }
        .header-content h2 { font-size: 15px; font-weight: normal; margin-top: 4px; }
        .header-content p { font-size: 13px; font-style: italic; margin: 2px 0; }
        .title { margin-top: 10px; font-size: 22px; font-weight: bold; letter-spacing: 2px; }

        .info-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td { padding: 6px 12px; vertical-align: top; font-size: 12px; }
        .info-table .label { font-weight: bold; width: 150px; }

        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin: 20px 0; }
        .stats-grid .stat-box { border: 1px solid #444; padding: 12px; text-align: center; border-radius: 4px; }
        .stats-grid .stat-box .number { font-size: 24px; font-weight: bold; color: #1a2332; }
        .stats-grid .stat-box .label { font-size: 12px; color: #555; margin-top: 4px; }
        .stats-grid .stat-box.highlight { background: #fef9e7; border-color: #f1c40f; }

        .summary-table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 12px; }
        .summary-table th { background: #1a2332; color: #f1c40f; padding: 10px 12px; text-align: center; border: 1px solid #1a2332; }
        .summary-table td { border: 1px solid #666; padding: 10px 12px; text-align: center; vertical-align: middle; }
        .summary-table tr:nth-child(even) { background: #f7f7f7; }
        .summary-table .rank-cell { font-weight: bold; font-size: 14px; }
        .summary-table .rank-1 { color: #f1c40f; }
        .summary-table .rank-2 { color: #bdc3c7; }
        .summary-table .rank-3 { color: #e67e22; }
        .summary-table .score-cell { font-weight: bold; font-size: 16px; color: #1a2332; }
        .summary-table .score-cell.excellent { color: #27ae60; }
        .summary-table .score-cell.good { color: #2ecc71; }
        .summary-table .score-cell.average { color: #f39c12; }
        .summary-table .score-cell.poor { color: #e74c3c; }
        .summary-table .designation-cell { font-size: 11px; color: #555; }
        .summary-table .type-badge { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 10px; font-weight: 600; background: #e9ecef; color: #1a2332; }

        .footer { margin-top: 30px; padding-top: 15px; border-top: 2px solid #222; display: flex; justify-content: space-between; align-items: flex-end; }
        .footer-left { text-align: left; }
        .footer-left p { font-size: 10px; color: #666; margin: 2px 0; }
        .footer-right { text-align: right; }
        .footer-right p { font-size: 10px; color: #666; margin: 2px 0; }

        .signature { display: flex; justify-content: space-between; margin-top: 30px; padding-top: 15px; }
        .signature div { text-align: center; width: 220px; }
        .signature .line { border-top: 1px solid #000; margin-bottom: 4px; padding-top: 20px; }
        .signature .sig-label { font-size: 10px; color: #555; }

        .section-title { color: #1a2332; margin-bottom: 15px; border-bottom: 2px solid #f1c40f; padding-bottom: 8px; font-size: 16px; font-weight: bold; }
        .clearfix::after { content: ""; clear: both; display: table; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .summary-table { font-size: 10px; }
            .summary-table th, .summary-table td { padding: 4px 6px; }
            .header-logo { flex: 0 0 60px; margin-right: 10px; }
            .header-logo img { max-width: 60px; }
            .header-content h1 { font-size: 20px; }
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
            <div class="header-logo">
                <!-- Use relative path for TCPDF -->
                <img src="../../assets/CAK_logo.jpg" 
                     alt="College Logo" 
                     style="max-width: 80px; height: auto;">
            </div>
            <div class="header-content">
                <h1><?php echo $collegeName; ?></h1>
                <h2><?php echo $collegeAddress; ?></h2>
                <p>(An Autonomous Institution)</p>
                <div class="title">FACULTY PERFORMANCE SUMMARY REPORT</div>
            </div>
        </div>

        <!-- Report Info (only on first page) -->
        <?php if ($page == 0): ?>
        <table class="info-table">
            <tr>
                <td class="label">Report Type</td>
                <td>Summary Performance Report</td>
                <td class="label">Generated On</td>
                <td><?php echo $generatedDate; ?></td>
            </tr>
            <tr>
                <td class="label">Total Faculty</td>
                <td><?php echo $totalFaculty; ?></td>
                <td class="label">Report Period</td>
                <td>Academic Year <?php echo date('Y') . '-' . (date('Y') + 1); ?></td>
            </tr>
        </table>

        <!-- Statistics (only on first page) -->
        <div class="stats-grid">
            <div class="stat-box highlight">
                <div class="number"><?php echo $totalFaculty; ?></div>
                <div class="label">Total Faculty</div>
            </div>
            <div class="stat-box highlight">
                <div class="number"><?php echo round($highestScore, 1); ?></div>
                <div class="label">Highest Score</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo round($averageScore, 1); ?></div>
                <div class="label">Average Score</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $above80; ?></div>
                <div class="label">Faculty Above 80%</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Faculty List -->
        <h3 class="section-title">
            Faculty Performance List <?php echo ($totalPages > 1) ? '- Page ' . ($page + 1) . ' of ' . $totalPages : ''; ?>
        </h3>

        <table class="summary-table">
            <thead>
                <tr>
                    <th style="width: 16.66%">Rank</th>
                    <th style="width: 16.66%;">Emp ID</th>
                    <th style="text-align: left;width: 16.66%">Faculty Name</th>
                    <th style="width: 16.66%;">Designation</th>
                    <th style="width: 16.66%;">Employee Type</th>
                    <th style="width: 16.66%;">Total Score</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $rank = $start + 1;
                foreach ($pageFaculty as $faculty): 
                    $totalScore = $faculty['score'];
                    
                    $scoreClass = '';
                    if ($totalScore >= 80) $scoreClass = 'excellent';
                    elseif ($totalScore >= 65) $scoreClass = 'good';
                    elseif ($totalScore >= 45) $scoreClass = 'average';
                    elseif ($totalScore > 0) $scoreClass = 'poor';
                    
                    $rankClass = '';
                    if ($rank == 1) $rankClass = 'rank-1';
                    elseif ($rank == 2) $rankClass = 'rank-2';
                    elseif ($rank == 3) $rankClass = 'rank-3';
                ?>
                <tr>
                    <td class="rank-cell <?php echo $rankClass; ?>">
                        <?php 
                        if ($rank == 1) echo '1';
                        elseif ($rank == 2) echo '2';
                        elseif ($rank == 3) echo '3';
                        else echo '#' . $rank;
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars($faculty['employee_id']); ?></td>
                    <td style="text-align: left; font-weight: 500;">
                        <?php echo htmlspecialchars($faculty['name']); ?>
                    </td>
                    <td class="designation-cell"><?php echo htmlspecialchars($faculty['designation']); ?></td>
                    <td>
                        <span class="type-badge"><?php echo htmlspecialchars($faculty['employee_type']); ?></span>
                    </td>
                    <td>
                        <span class="score-cell <?php echo $scoreClass; ?>">
                            <?php echo round($totalScore, 1); ?>
                        </span>
                        <small style="color: #999;">/100</small>
                    </td>
                </tr>
                <?php 
                $rank++;
                endforeach; 
                ?>
            </tbody>
            <?php if ($isLastPage): ?>
            <tfoot>
                <tr style="background: #fef9e7; font-weight: bold;">
                    <td colspan="4" style="text-align: right;">Total Faculty: <?php echo $totalFaculty; ?></td>
                    <td colspan="2" style="text-align: center;">
                        Average Score: <?php echo round($averageScore, 1); ?>/100
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <!-- Footer and Signatures (only on last page) -->
        <?php if ($isLastPage): ?>
        <div class="footer">
            <div class="footer-left">
                <p>Generated on: <?php echo $generatedDate; ?></p>
                <p>This is a system-generated report.</p>
            </div>
            <div class="footer-right">
                <p>Page <?php echo ($page + 1); ?> of <?php echo $totalPages; ?></p>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature">
            <div>
                <div class="line"></div>
                <span style="font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($principalName); ?></span><br>
                <span class="sig-label">Principal</span>
            </div>
            <div>
                <div class="line"></div>
                <span style="font-weight: 600; font-size: 12px;"><?php echo $collegeName; ?></span><br>
                <span class="sig-label">College Seal</span>
            </div>
        </div>
        <?php else: ?>
        <!-- Footer for non-last pages -->
        <div class="footer">
            <div class="footer-left">
                <p>Generated on: <?php echo $generatedDate; ?></p>
            </div>
            <div class="footer-right">
                <p>Page <?php echo ($page + 1); ?> of <?php echo $totalPages; ?></p>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endfor; ?>
</body>
</html>
