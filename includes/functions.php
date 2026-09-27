<?php
require_once __DIR__ . '/../config/database.php';

function getDB() {
    return Database::getInstance()->getConnection();
}

// ─── Subfolder Routing Helpers ───────────────────────────────────────────────

// Determine base URL dynamically (e.g. '/appraisal' or '')
$appDir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
$baseUrl = '';

if ($appDir && $docRoot && strpos($appDir, $docRoot) === 0) {
    $baseUrl = substr($appDir, strlen($docRoot));
}
// Fallback if realpath fails or DOCUMENT_ROOT is unreliable
if ($baseUrl === '' && isset($_SERVER['SCRIPT_NAME'])) {
    // Check if we are running from a known script like index.php
    $scriptName = $_SERVER['SCRIPT_NAME'];
    if (strpos($scriptName, '/index.php') !== false) {
        $baseUrl = substr($scriptName, 0, strpos($scriptName, '/index.php'));
    }
}
define('BASE_URL', rtrim($baseUrl, '/'));

/**
 * Generate an absolute URL that includes the base subfolder.
 */
function url($path) {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Format a database timestamp using the application's existing date behavior.
 */
function formatDatabaseDate($value, $format = 'd M Y, h:i A') {
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date($format, $timestamp);
}

// ─── Output / Input Sanitization ─────────────────────────────────────────────

function sanitizeInput($input) {
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

/**
 * Clean an integer from user input. Returns null if not a valid integer.
 */
function cleanInt($val, $min = null, $max = null) {
    $v = filter_var($val, FILTER_VALIDATE_INT);
    if ($v === false) return null;
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return (int)$v;
}

/**
 * Clean a float from user input.
 */
function cleanFloat($val, $min = null, $max = null) {
    $v = filter_var($val, FILTER_VALIDATE_FLOAT);
    if ($v === false) return null;
    if ($min !== null && $v < $min) return null;
    if ($max !== null && $v > $max) return null;
    return (float)$v;
}

/**
 * Sanitize and truncate a string field.
 */
function cleanStr($val, $maxLen = 255) {
    $v = trim((string)$val);
    if (strlen($v) > $maxLen) {
        $v = mb_substr($v, 0, $maxLen, 'UTF-8');
    }
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ─── CSRF Helpers ─────────────────────────────────────────────────────────────

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Render a hidden CSRF input field — call inside every form.
 */
function csrfField() {
    $token = generateCSRFToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Enforce CSRF on POST handlers. Exits with 403 if token missing/invalid.
 */
function enforceCSRF() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!validateCSRFToken($token)) {
            http_response_code(403);
            die('Security check failed. Please go back and try again.');
        }
    }
}

// ─── Secure File Upload ───────────────────────────────────────────────────────

/**
 * Validate and save an uploaded image file.
 *
 * @param  array  $file       Entry from $_FILES
 * @param  string $uploadDir  Absolute path to upload directory
 * @param  int    $maxBytes   Max file size in bytes (default 2 MB)
 * @return string             Generated filename on success
 * @throws RuntimeException   On any validation failure
 */
function saveUploadedImage(array $file, string $uploadDir, int $maxBytes = 2097152): string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $file['error']);
    }

    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File too large. Maximum allowed size is ' . round($maxBytes / 1048576, 1) . ' MB.');
    }

    // Real MIME check via finfo (not trusting client-supplied type)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Invalid file type. Only JPEG, PNG, GIF and WebP images are allowed.');
    }

    // Verify it is truly an image (catches disguised files)
    if (!getimagesize($file['tmp_name'])) {
        throw new RuntimeException('Uploaded file is not a valid image.');
    }

    // Map MIME → extension
    $extMap = [
        'image/jpeg' => '.jpg',
        'image/png'  => '.png',
        'image/gif'  => '.gif',
        'image/webp' => '.webp',
    ];
    $ext = $extMap[$mimeType];

    // Random filename — prevents path traversal and enumeration
    $filename = bin2hex(random_bytes(16)) . $ext;
    $dest     = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $filename;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }

    return $filename;
}

// ─── Simple In-Request Rate Limiter ──────────────────────────────────────────

/**
 * Basic session-based rate limiter.
 * Returns false (and sets HTTP 429) if caller exceeds $maxCalls per $windowSec.
 */
function rateLimit(string $key, int $maxCalls = 30, int $windowSec = 60): bool {
    $sessionKey = 'rl_' . $key;
    $now        = time();

    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = ['count' => 0, 'window_start' => $now];
    }

    $data = &$_SESSION[$sessionKey];

    if ($now - $data['window_start'] > $windowSec) {
        // Reset window
        $data = ['count' => 1, 'window_start' => $now];
        return true;
    }

    $data['count']++;

    if ($data['count'] > $maxCalls) {
        http_response_code(429);
        return false;
    }

    return true;
}


function getNotifications($userId, $limit = 10) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$userId, $limit]);
    return $stmt->fetchAll();
}

function getUnreadNotificationsCount($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result ? $result['count'] : 0;
}

function markNotificationAsRead($notificationId, $userId) {
    $db = getDB();
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    return $stmt->execute([$notificationId, $userId]);
}

function addNotification($userId, $title, $message, $link = null) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, link) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$userId, $title, $message, $link]);
}

function logActivity($userId, $action, $details = null) {
    $db = getDB();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = $db->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$userId, $action, $details, $ip]);
}

function getTaskCategories() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM task_categories ORDER BY name");
    return $stmt->fetchAll();
}

function getCategoryMaxMarks($categoryId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT max_marks FROM performance_settings WHERE category_id = ? ORDER BY updated_at DESC LIMIT 1");
    $stmt->execute([$categoryId]);
    $result = $stmt->fetch();
    return $result ? $result['max_marks'] : 0;
}

// ============== ACADEMIC YEAR FUNCTIONS ==============

function getActiveAcademicYear() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM academic_years WHERE is_default = 1 LIMIT 1");
    $year = $stmt->fetch();
    if (!$year) {
        $stmt = $db->query("SELECT * FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $year = $stmt->fetch();
    }
    return $year;
}

function getAllAcademicYears() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM academic_years ORDER BY is_default DESC, id DESC");
    return $stmt->fetchAll();
}

function getAcademicYearById($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM academic_years WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function getAcademicYearByName($name) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM academic_years WHERE year_name = ?");
    $stmt->execute([$name]);
    return $stmt->fetch();
}

function parseYearDateRange($yearString) {
    $yearString = trim($yearString);
    if (preg_match('/^(\d{4})\s*-\s*(\d{4})$/', $yearString, $matches)) {
        // Range format like 2025-2026: from June 1st of start year to May 31st of end year (Academic cycle)
        return [
            'start' => $matches[1] . '-06-01 00:00:00',
            'end'   => $matches[2] . '-05-31 23:59:59',
            'type'  => 'range'
        ];
    } elseif (preg_match('/^(\d{4})$/', $yearString, $matches)) {
        // Single year format like 2025: full calendar year
        return [
            'start' => $matches[1] . '-01-01 00:00:00',
            'end'   => $matches[1] . '-12-31 23:59:59',
            'type'  => 'single'
        ];
    }
    return null;
}

function calculatePerformance($userId, $year = null, $month = null) {
    $db = getDB();
    
    // Determine date filtering
    $dateFilterSql = "";
    $params = [$userId];
    
    if ($year !== null && $year !== '' && $year !== 'all') {
        $range = parseYearDateRange($year);
        if ($range) {
            if ($month !== null && $month !== '' && $month !== 'all') {
                $monthNum = str_pad((int)$month, 2, '0', STR_PAD_LEFT);
                // If single year (e.g. 2025)
                if ($range['type'] === 'single') {
                    $yearNum = substr($range['start'], 0, 4);
                    $dateFilterSql .= " AND strftime('%Y-%m', te.evaluated_at) = '{$yearNum}-{$monthNum}'";
                } else {
                    // For range 2025-2026, month 6-12 is 2025, month 1-5 is 2026
                    $startYear = substr($range['start'], 0, 4);
                    $endYear = substr($range['end'], 0, 4);
                    $targetYear = ((int)$month >= 6) ? $startYear : $endYear;
                    $dateFilterSql .= " AND strftime('%Y-%m', te.evaluated_at) = '{$targetYear}-{$monthNum}'";
                }
            } else {
                $dateFilterSql .= " AND te.evaluated_at >= '{$range['start']}' AND te.evaluated_at <= '{$range['end']}'";
            }
        }
    } elseif ($month !== null && $month !== '' && $month !== 'all') {
        $monthNum = str_pad((int)$month, 2, '0', STR_PAD_LEFT);
        $dateFilterSql .= " AND strftime('%m', te.evaluated_at) = '{$monthNum}'";
    }
    
    // Get all categories
    $categories = getTaskCategories();
    $scores = [];
    $totalScore = 0;
    
    foreach ($categories as $category) {
        // Get all approved tasks for this category
        $sql = "
            SELECT te.marks_obtained 
            FROM tasks t
            JOIN task_submissions ts ON ts.task_id = t.id
            JOIN task_evaluations te ON te.submission_id = ts.id
            WHERE t.user_id = ? 
            AND t.category_id = ?
            AND te.status = 'Approved'
            {$dateFilterSql}
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $category['id']]);
        $tasks = $stmt->fetchAll();
        
        if (count($tasks) > 0) {
            $totalMarks = array_sum(array_column($tasks, 'marks_obtained'));
            $average = $totalMarks / count($tasks);
            $scores[$category['name']] = round($average, 2);
            $totalScore += $average;
        } else {
            $scores[$category['name']] = 0;
        }
    }
    
    return [
        'scores' => $scores,
        'total' => round($totalScore, 2)
    ];
}

// Get category name by ID
function getCategoryName($categoryId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM task_categories WHERE id = ?");
    $stmt->execute([$categoryId]);
    $result = $stmt->fetch();
    return $result ? $result['name'] : 'Unknown';
}

// Get all users with role filter
function getUsersByRole($roleName = null) {
    $db = getDB();
    $sql = "SELECT u.*, r.name as role_name, d.name as department_name 
            FROM users u 
            LEFT JOIN roles r ON u.role_id = r.id 
            LEFT JOIN departments d ON u.department_id = d.id";
    
    if ($roleName) {
        $sql .= " WHERE r.name = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$roleName]);
    } else {
        $stmt = $db->query($sql);
    }
    
    return $stmt->fetchAll();
}

// Get task status counts for dashboard
function getTaskStatusCounts() {
    $db = getDB();
    $statusCounts = [];
    $statusQuery = $db->query("
        SELECT status, COUNT(*) as count 
        FROM tasks 
        GROUP BY status
    ");
    while ($row = $statusQuery->fetch()) {
        $statusCounts[$row['status']] = $row['count'];
    }
    
    // Default statuses
    $defaultStatuses = ['Pending' => 0, 'In Progress' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
    return array_merge($defaultStatuses, $statusCounts);
}

// Get template by ID
function getTemplateById($templateId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.*, tc.name as category_name, ps.max_marks as category_max_marks
        FROM task_templates t
        JOIN task_categories tc ON t.category_id = tc.id
        LEFT JOIN performance_settings ps ON ps.category_id = tc.id
        WHERE t.id = ?
    ");
    $stmt->execute([$templateId]);
    return $stmt->fetch();
}

// Get all templates with category info
function getAllTemplates($activeOnly = true) {
    $db = getDB();
    $sql = "
        SELECT t.*, tc.name as category_name, ps.max_marks as category_max_marks
        FROM task_templates t
        JOIN task_categories tc ON t.category_id = tc.id
        LEFT JOIN performance_settings ps ON ps.category_id = tc.id
    ";
    if ($activeOnly) {
        $sql .= " WHERE t.is_active = 1";
    }
    $sql .= " ORDER BY tc.name, t.task_name";
    
    $stmt = $db->query($sql);
    return $stmt->fetchAll();
}

// Get default templates by employee type
function getDefaultTemplates($employeeType = null) {
    $db = getDB();
    $sql = "
        SELECT t.*, tc.name as category_name, ps.max_marks as category_max_marks
        FROM task_templates t
        JOIN task_categories tc ON t.category_id = tc.id
        LEFT JOIN performance_settings ps ON ps.category_id = tc.id
        WHERE t.is_default = 1 AND t.is_active = 1
    ";
    if ($employeeType) {
        $sql .= " AND (t.employee_type = ? OR t.employee_type = 'Both')";
        $stmt = $db->prepare($sql);
        $stmt->execute([$employeeType]);
    } else {
        $stmt = $db->query($sql);
    }
    return $stmt->fetchAll();
}

// Get categories with max marks
function getCategoriesWithMaxMarks() {
    $db = getDB();
    $catQuery = $db->query("
        SELECT tc.id, tc.name, ps.max_marks 
        FROM task_categories tc
        LEFT JOIN performance_settings ps ON ps.category_id = tc.id
        ORDER BY tc.name
    ");
    $categoryMaxMarks = [];
    while ($row = $catQuery->fetch()) {
        $categoryMaxMarks[$row['id']] = [
            'name' => $row['name'],
            'max_marks' => $row['max_marks'] ?? 0
        ];
    }
    return $categoryMaxMarks;
}

// Get tasks by status for a user
function getTasksByStatus($userId, $status = null) {
    $db = getDB();
    $sql = "SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name 
            FROM tasks t 
            JOIN task_categories tc ON t.category_id = tc.id 
            JOIN users u ON t.assigned_by = u.id 
            WHERE t.user_id = ?";
    
    $params = [$userId];
    if ($status) {
        $sql .= " AND t.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY t.created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Get all pending submissions for evaluation
function getPendingSubmissions() {
    $db = getDB();
    $stmt = $db->query("
        SELECT ts.*, t.task_title, u.full_name as employee_name, u.employee_id,
               tc.name as category_name, t.id as task_id
        FROM task_submissions ts
        JOIN tasks t ON ts.task_id = t.id
        JOIN users u ON t.user_id = u.id
        JOIN task_categories tc ON t.category_id = tc.id
        WHERE ts.status = 'Pending'
        ORDER BY ts.submitted_at ASC
    ");
    return $stmt->fetchAll();
}

// ============== USER FUNCTIONS ==============

// Get user by ID
function getUserById($userId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT u.*, r.name as role_name, d.name as department_name 
                          FROM users u 
                          LEFT JOIN roles r ON u.role_id = r.id 
                          LEFT JOIN departments d ON u.department_id = d.id 
                          WHERE u.id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

// Get user by email
function getUserByEmail($email) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch();
}

// Get users by role
function getUsersByRoleId($roleId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE role_id = ? AND is_active = 1 ORDER BY sequence ASC, full_name ASC");
    $stmt->execute([$roleId]);
    return $stmt->fetchAll();
}

// Get all users
function getAllUsers() {
    $db = getDB();
    $stmt = $db->query("
        SELECT u.*, r.name as role_name, d.name as department_name 
        FROM users u 
        LEFT JOIN roles r ON u.role_id = r.id 
        LEFT JOIN departments d ON u.department_id = d.id 
        ORDER BY u.sequence ASC, u.created_at DESC
    ");
    return $stmt->fetchAll();
}

// Get department name by ID
function getDepartmentName($departmentId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM departments WHERE id = ?");
    $stmt->execute([$departmentId]);
    $result = $stmt->fetch();
    return $result ? $result['name'] : 'N/A';
}

// Get role name by ID
function getRoleName($roleId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT name FROM roles WHERE id = ?");
    $stmt->execute([$roleId]);
    $result = $stmt->fetch();
    return $result ? $result['name'] : 'Unknown';
}

// Get all roles
function getAllRoles() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM roles ORDER BY name");
    return $stmt->fetchAll();
}

// Get all departments
function getAllDepartments() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM departments ORDER BY name");
    return $stmt->fetchAll();
}

// Get task by ID with all details
function getTaskById($taskId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name,
               u2.full_name as user_name, u2.employee_id
        FROM tasks t
        JOIN task_categories tc ON t.category_id = tc.id
        JOIN users u ON t.assigned_by = u.id
        JOIN users u2 ON t.user_id = u2.id
        WHERE t.id = ?
    ");
    $stmt->execute([$taskId]);
    return $stmt->fetch();
}

// Get submission by task ID
function getSubmissionByTaskId($taskId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM task_submissions WHERE task_id = ? ORDER BY submitted_at DESC LIMIT 1
    ");
    $stmt->execute([$taskId]);
    return $stmt->fetch();
}

// Get evaluation by submission ID
function getEvaluationBySubmissionId($submissionId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM task_evaluations WHERE submission_id = ? ORDER BY evaluated_at DESC LIMIT 1
    ");
    $stmt->execute([$submissionId]);
    return $stmt->fetch();
}

// ============== NOTE: generateRandomPassword(), hashPassword(), verifyPassword() are already defined in auth.php ==============
// Do NOT redeclare them here to avoid "Cannot redeclare" errors

// Get pending submissions count
function getPendingSubmissionsCount() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as count FROM task_submissions WHERE status = 'Pending'");
    $result = $stmt->fetch();
    return $result ? $result['count'] : 0;
}

// Get total tasks count
function getTotalTasksCount() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as count FROM tasks");
    $result = $stmt->fetch();
    return $result ? $result['count'] : 0;
}

// Get approved tasks count
function getApprovedTasksCount() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as count FROM tasks WHERE status = 'Approved'");
    $result = $stmt->fetch();
    return $result ? $result['count'] : 0;
}

// Get faculty count
function getFacultyCount() {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) as count FROM users WHERE role_id = (SELECT id FROM roles WHERE name = 'Faculty')");
    $result = $stmt->fetch();
    return $result ? $result['count'] : 0;
}

// Get performance summary for all faculty
function getPerformanceSummary() {
    $db = getDB();
    $faculty = getUsersByRole('Faculty');
    $summary = [];
    
    foreach ($faculty as $user) {
        $perf = calculatePerformance($user['id']);
        $summary[] = [
            'user' => $user,
            'performance' => $perf
        ];
    }
    
    // Sort by total score descending
    usort($summary, function($a, $b) {
        return $b['performance']['total'] - $a['performance']['total'];
    });
    
    return $summary;
}

// Get category wise performance for a user
function getCategoryWisePerformance($userId) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            tc.id as category_id,
            tc.name as category_name,
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
        GROUP BY tc.id
        ORDER BY tc.name
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Get user tasks with details
function getUserTasksWithDetails($userId) {
    $db = getDB();
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
        ORDER BY tc.name, t.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
