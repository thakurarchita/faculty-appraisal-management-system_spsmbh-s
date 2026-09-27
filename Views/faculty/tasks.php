<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/faculty/tasks');
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is faculty
if ($_SESSION['role'] !== 'Faculty') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../models/Task.php';

$taskModel = new Task();
$db = getDB();
$userId = $_SESSION['user_id'];

// Get task categories for self-task modal
$categories = $db->query("SELECT * FROM task_categories ORDER BY name")->fetchAll();

// Get filter from URL
$statusFilter = $_GET['status'] ?? 'all';

// Get all tasks assigned to this user (INCLUDE Rejected tasks)
$tasksStmt = $db->prepare("
    SELECT t.*, tc.name as category_name, u.full_name as assigned_by_name,
           u.employee_id as assigned_by_emp_id,
           ts.submission_text, ts.submitted_at, ts.attachment as submission_attachment,
           te.remarks as evaluation_remarks, te.status as evaluation_status,
           te.marks_obtained
    FROM tasks t
    JOIN task_categories tc ON t.category_id = tc.id
    JOIN users u ON t.assigned_by = u.id
    LEFT JOIN task_submissions ts ON ts.task_id = t.id
    LEFT JOIN task_evaluations te ON te.submission_id = ts.id
    WHERE t.user_id = ?
    AND t.status NOT IN ('Approved')  -- Only exclude Approved, include Rejected
    ORDER BY 
        CASE t.status 
            WHEN 'Rejected' THEN 0  -- Show Rejected first
            WHEN 'Pending' THEN 1
            WHEN 'Initiated' THEN 1
            WHEN 'In Progress' THEN 2
            WHEN 'Completed' THEN 3
            WHEN 'Submitted' THEN 4
        END,
        t.created_at DESC
");
$tasksStmt->execute([$userId]);
$allTasks = $tasksStmt->fetchAll();
// Filter tasks based on status
$filteredTasks = $allTasks;
if ($statusFilter != 'all') {
    if ($statusFilter == 'pending_initiated') {
        $filteredTasks = array_filter($allTasks, function($task) {
            return in_array($task['status'], ['Pending', 'Initiated']);
        });
    } else {
        $filteredTasks = array_filter($allTasks, function($task) use ($statusFilter) {
            return $task['status'] == $statusFilter;
        });
    }
}
// Get task counts by status (include Rejected)
$statusCounts = [];
$countStmt = $db->prepare("
    SELECT status, COUNT(*) as count 
    FROM tasks 
    WHERE user_id = ? AND status NOT IN ('Approved')
    GROUP BY status
");
$countStmt->execute([$userId]);
while ($row = $countStmt->fetch()) {
    $statusCounts[$row['status']] = $row['count'];
}

$totalPending = ($statusCounts['Pending'] ?? 0) + ($statusCounts['Initiated'] ?? 0) + ($statusCounts['Rejected'] ?? 0);

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'add_self_task') {
        $taskTitle = sanitizeInput($_POST['task_title']);
        $taskDesc = sanitizeInput($_POST['task_description']);
        $categoryId = $_POST['category_id'];
        $dueDate = $_POST['due_date'];
        
        $data = [
            'user_id' => $userId,
            'assigned_by' => $userId,
            'task_title' => $taskTitle,
            'task_description' => $taskDesc,
            'category_id' => $categoryId,
            'due_date' => $dueDate,
            'priority' => 'Medium', // Default priority for self tasks
            'attachment' => null
        ];
        
        if ($taskModel->createTask($data)) {
            // Update the status to 'In Progress' for self tasks automatically
            $lastInsertId = $db->lastInsertId();
            $stmt = $db->prepare("UPDATE tasks SET status = 'In Progress' WHERE id = ?");
            $stmt->execute([$lastInsertId]);
            
            $_SESSION['success'] = 'Self task added successfully!';
        } else {
            $_SESSION['error'] = 'Failed to add self task.';
        }
        header('Location: ' . BASE_URL . '/faculty/tasks?status=all');
        exit();
    }
    
    if ($action == 'update_status') {
        $taskId = $_POST['task_id'];
        $status = $_POST['status'];
        $remarks = sanitizeInput($_POST['remarks'] ?? '');
        
        $stmt = $db->prepare("UPDATE tasks SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $result = $stmt->execute([$status, $taskId, $userId]);
        
        if ($result) {
            logActivity($userId, 'Task Status Updated', "Task ID: $taskId, New Status: $status");
            $_SESSION['success'] = 'Task status updated successfully!';
        } else {
            $_SESSION['error'] = 'Failed to update task status.';
        }
        header('Location: ' . BASE_URL . '/faculty/tasks?status=' . $statusFilter);
        exit();
    }
    
    if ($action == 'submit_task') {
        $taskId = $_POST['task_id'];
        $submission_text = sanitizeInput($_POST['submission_text']);
        $attachment = null;
        
        if (isset($_FILES['proof']) && $_FILES['proof']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];
            $filename = $_FILES['proof']['name'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $filesize = $_FILES['proof']['size'];
            
            if ($filesize > 5 * 1024 * 1024) {
                $_SESSION['error'] = 'File size must be less than 5MB.';
                header('Location: ' . BASE_URL . '/faculty/tasks?status=' . $statusFilter);
                exit();
            }
            
            if (!in_array($filetype, $allowed)) {
                $_SESSION['error'] = 'Only JPG, PNG, PDF, DOC files are allowed.';
                header('Location: ' . BASE_URL . '/faculty/tasks?status=' . $statusFilter);
                exit();
            }
            
            $upload_dir = __DIR__ . '/../../assets/uploads/submissions/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $new_filename = 'submission_' . $taskId . '_' . time() . '.' . $filetype;
            if (move_uploaded_file($_FILES['proof']['tmp_name'], $upload_dir . $new_filename)) {
                $attachment = $new_filename;
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO task_submissions (task_id, submitted_by, submission_text, attachment, status, submitted_at)
            VALUES (?, ?, ?, ?, 'Pending', ?)
        ");
        $result = $stmt->execute([$taskId, $userId, $submission_text, $attachment, date('Y-m-d H:i:s')]);
        
        if ($result) {
            $stmt2 = $db->prepare("UPDATE tasks SET status = 'Submitted', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt2->execute([$taskId]);
            
            $task = $taskModel->getTaskById($taskId);
            if ($task) {
                addNotification(
                    $task['assigned_by'],
                    'Task Submitted',
                    "Task '{$task['task_title']}' has been submitted by {$_SESSION['full_name']}",
                    '/principal/evaluations'
                );
            }
            
            $_SESSION['success'] = 'Task submitted successfully!';
        } else {
            $_SESSION['error'] = 'Failed to submit task.';
        }
        header('Location: ' . BASE_URL . '/faculty/tasks?status=' . $statusFilter);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Faculty Workspace</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
    
    <style>

        :root {
            --primary: #C94D57;
            --primary-light: #E58B91;
            --primary-dark: #8F2633;
            --secondary: #E58B91;
            --dark: #8F2633;
            --dark-light: #C94D57;
            --bg-body: #FAF7F7;
            --bg-card: #FFFFFF;
            --text-main: #334155;
            --text-muted: #64748B;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.03);
            
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        
        body {
            background-color: var(--bg-body);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6, .brand-text {
            font-family: 'Outfit', sans-serif;
        }

        /* Top Navigation */
        .topbar {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            position: sticky;
            top: 0;
            z-index: 1020;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dark);
        }

        .topbar-brand .icon-box {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .topbar-nav {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .topbar-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .topbar-nav a:hover, .topbar-nav a.active {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.08);
        }

        .workspace {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        /* Completion Bar */
        .completion-banner {
            background: linear-gradient(135deg, var(--dark), var(--dark-light));
            border-radius: var(--radius-lg);
            padding: 24px;
            color: white;
            margin-bottom: 30px;
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            overflow: hidden;
        }
        .completion-banner::after {
            content: ''; position: absolute; right: 0; top: 0;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(79,70,229,0.4) 0%, rgba(0,0,0,0) 70%);
            transform: translate(20%, -30%); pointer-events: none;
        }
        
        .progress-circle {
            position: relative;
            width: 80px; height: 80px;
            border-radius: 50%;
            background: conic-gradient(var(--secondary) <?php echo $profileCompletion; ?>%, rgba(255,255,255,0.1) 0);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.3);
        }
        .progress-circle::before {
            content: ''; position: absolute; inset: 6px;
            background: var(--dark-light); border-radius: 50%;
        }
        .progress-text {
            position: relative; font-weight: 700; font-size: 1.2rem; font-family: 'Outfit';
        }

        /* Profile Layout */
        .profile-header-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 30px;
            margin-bottom: 24px;
            position: relative;
            border: 1px solid #E2E8F0;
        }
        
        .profile-avatar-wrapper {
            position: relative;
            width: 120px; height: 120px;
            margin-right: 30px;
            flex-shrink: 0;
        }
        
        .profile-avatar {
            width: 100%; height: 100%;
            border-radius: 20px;
            object-fit: cover;
            border: 4px solid white;
            box-shadow: var(--shadow-md);
            background: #F1F5F9;
        }
        
        .avatar-edit-btn {
            position: absolute;
            bottom: -10px; right: -10px;
            background: var(--primary);
            color: white;
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            border: 3px solid white;
            cursor: pointer;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s;
        }
        .avatar-edit-btn:hover { background: var(--primary-dark); transform: scale(1.05); }

        .section-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid #E2E8F0;
        }
        
        .section-title {
            font-family: 'Outfit';
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 20px;
            display: flex; align-items: center; gap: 10px;
            padding-bottom: 12px;
            border-bottom: 1px solid #F1F5F9;
        }

        /* Documents Grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
        }
        
        .doc-card {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
            position: relative;
        }
        .doc-card:hover { border-color: var(--primary-light); box-shadow: var(--shadow-md); transform: translateY(-3px); }
        
        .doc-preview {
            height: 140px;
            background: #E2E8F0;
            position: relative;
            display: flex; align-items: center; justify-content: center;
            overflow: hidden;
        }
        .doc-preview img { width: 100%; height: 100%; object-fit: cover; }
        
        .doc-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,0.5);
            display: flex; align-items: center; justify-content: center; opacity: 0; transition: 0.2s; gap: 10px;
        }
        .doc-card:hover .doc-overlay { opacity: 1; }
        
        .doc-info { padding: 12px 15px; }
        .doc-name { font-weight: 600; font-size: 0.95rem; color: var(--dark); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .add-doc-card {
            background: rgba(79, 70, 229, 0.05);
            border: 2px dashed rgba(79, 70, 229, 0.3);
            border-radius: 12px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            min-height: 200px; cursor: pointer; transition: all 0.2s; color: var(--primary); text-align: center; padding: 20px;
        }
        .add-doc-card:hover { background: rgba(79, 70, 229, 0.1); border-color: var(--primary); }
        
        /* Cropper container */
        .cropper-container-wrapper { width: 100%; max-height: 60vh; background: #000; overflow: hidden; }

        .custom-nav-pills .nav-link {
            border-radius: 8px;
            color: var(--text-muted);
            font-weight: 500;
            padding: 10px 20px;
        }
        .custom-nav-pills .nav-link.active {
            background: var(--dark);
            color: white;
            box-shadow: var(--shadow-sm);
        }
    

        /* Specific overrides for tasks */
        .workspace {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px 20px;
        }
    
/* NEW STYLE BLOCK */

        :root {
            --primary: #12304A;
            --primary-light: #315A78;
            --primary-dark: #0B2033;
            --secondary: #D6A84F;
            --dark: #0B2033;
            --dark-light: #12304A;
            --bg-body: #F5F7F8;
            --bg-card: #FFFFFF;
            --text-main: #334155;
            --text-muted: #64748B;
            --danger: #EF4444;
            --warning: #F59E0B;
            
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.03);
            
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }
        
        body {
            background-color: var(--bg-body);
            font-family: 'Inter', sans-serif;
            color: var(--text-main);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1, h2, h3, h4, h5, h6, .brand-text {
            font-family: 'Outfit', sans-serif;
        }

        /* Top Navigation */
        .topbar {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
            position: sticky;
            top: 0;
            z-index: 1020;
            padding: 12px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--dark);
        }

        .topbar-brand .icon-box {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        }

        .topbar-nav {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .topbar-nav a {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.2s;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .topbar-nav a:hover, .topbar-nav a.active {
            color: var(--primary);
            background: rgba(79, 70, 229, 0.08);
        }

        .user-profile-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid #E2E8F0;
            padding: 6px 16px 6px 6px;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .user-profile-btn:hover {
            border-color: var(--primary-light);
            box-shadow: var(--shadow-sm);
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--primary-light);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Main Workspace */
        .workspace {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
        }

        /* Guided Workflow Header */
        .workflow-guide {
            background: linear-gradient(135deg, var(--dark) 0%, var(--dark-light) 100%);
            border-radius: var(--radius-xl);
            padding: 32px;
            color: white;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }
        
        .workflow-guide::after {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 300px; height: 300px;
            background: radial-gradient(circle, rgba(99,102,241,0.2) 0%, rgba(0,0,0,0) 70%);
            border-radius: 50%;
            transform: translate(30%, -30%);
            pointer-events: none;
        }

        .workflow-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .workflow-subtitle {
            color: #CBD5E1;
            font-size: 1rem;
            max-width: 600px;
            margin-bottom: 30px;
        }

        /* Interactive Steps */
        .steps-container {
            display: flex;
            gap: 15px;
            position: relative;
            z-index: 1;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: none;
        }
        .steps-container::-webkit-scrollbar { display: none; }

        .step-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--radius-lg);
            padding: 20px;
            min-width: 200px;
            flex: 1;
            transition: all 0.3s;
            text-decoration: none;
            color: white;
            display: flex;
            flex-direction: column;
        }

        .step-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-5px);
            color: white;
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }
        
        .step-card.active {
            background: white;
            color: var(--dark);
        }
        .step-card.active .step-icon {
            background: var(--primary);
            color: white;
        }
        .step-card.active .step-count {
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary);
        }

        .step-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }

        .step-icon {
            width: 36px; height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .step-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .step-title {
            font-weight: 600;
            font-size: 1.05rem;
            margin-bottom: 4px;
        }

        .step-desc {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .filter-tabs {
            display: flex;
            background: white;
            padding: 6px;
            border-radius: 12px;
            box-shadow: var(--shadow-sm);
            gap: 5px;
            overflow-x: auto;
        }

        .filter-tab {
            padding: 8px 16px;
            border-radius: 8px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .filter-tab:hover {
            background: #F1F5F9;
            color: var(--dark);
        }

        .filter-tab.active {
            background: var(--dark);
            color: white;
        }

        /* Task Cards */
        .task-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .task-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #E2E8F0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .task-card:hover {
            box-shadow: var(--shadow-lg);
            border-color: #CBD5E1;
            transform: translateY(-2px);
        }

        .task-status-bar {
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 6px;
        }
        
        .status-Pending .task-status-bar, .status-Initiated .task-status-bar { background: var(--warning); }
        .status-InProgress .task-status-bar { background: var(--primary); }
        .status-Completed .task-status-bar { background: var(--secondary); }
        .status-Submitted .task-status-bar { background: #8B5CF6; }
        .status-Rejected .task-status-bar { background: var(--danger); }

        .task-icon-wrapper {
            flex-shrink: 0;
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .status-Pending .task-icon-wrapper { background: #FEF3C7; color: #D97706; }
        .status-InProgress .task-icon-wrapper { background: #EEF2FF; color: var(--primary); }
        .status-Completed .task-icon-wrapper { background: #ECFDF5; color: var(--secondary); }
        .status-Submitted .task-icon-wrapper { background: #F5F3FF; color: #8B5CF6; }
        .status-Rejected .task-icon-wrapper { background: #FEF2F2; color: var(--danger); }

        .task-content {
            flex-grow: 1;
            min-width: 0;
        }

        .task-meta-top {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 6px;
            font-size: 0.85rem;
        }

        .task-category {
            background: #F1F5F9;
            color: #475569;
            padding: 4px 10px;
            border-radius: 20px;
            font-weight: 600;
        }

        .task-deadline {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .task-deadline.overdue {
            color: var(--danger);
            font-weight: 600;
        }

        .task-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .task-desc {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .task-actions {
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: flex-end;
        }

        /* Smart Action Button */
        .btn-smart {
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            transition: all 0.2s;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-smart-primary {
            background: var(--primary);
            color: white;
            box-shadow: 0 4px 6px rgba(79, 70, 229, 0.2);
        }
        .btn-smart-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(79, 70, 229, 0.3);
            color: white;
        }
        
        .btn-smart-success {
            background: var(--secondary);
            color: white;
            box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2);
        }
        .btn-smart-success:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(16, 185, 129, 0.3);
            color: white;
        }

        .btn-smart-outline {
            background: white;
            color: var(--text-main);
            border: 1px solid #E2E8F0;
        }
        .btn-smart-outline:hover {
            background: #F8FAFC;
            border-color: #CBD5E1;
            color: var(--dark);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            gap: 6px;
        }
        
        .status-badge.Pending, .status-badge.Initiated { background: #FEF3C7; color: #D97706; }
        .status-badge.InProgress { background: #EEF2FF; color: var(--primary); }
        .status-badge.Completed { background: #ECFDF5; color: var(--secondary); }
        .status-badge.Submitted { background: #F5F3FF; color: #8B5CF6; }
        .status-badge.Rejected { background: #FEF2F2; color: var(--danger); }

        /* Modals */
        .modal-content {
            border-radius: var(--radius-xl);
            border: none;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            overflow: hidden;
        }
        
        .modal-header {
            background: #F8FAFC;
            border-bottom: 1px solid #E2E8F0;
            padding: 24px;
        }
        
        .modal-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: var(--dark);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            border-top: 1px solid #E2E8F0;
            padding: 20px 24px;
            background: #F8FAFC;
        }
        
        .form-control, .form-select {
            border-radius: 10px;
            padding: 12px 16px;
            border: 1px solid #E2E8F0;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-main);
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: var(--radius-lg);
            border: 1px dashed #CBD5E1;
        }
        
        .empty-icon {
            width: 80px; height: 80px;
            background: #F1F5F9;
            color: #94A3B8;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 20px;
        }

        @media (max-width: 768px) {
            .task-card {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .task-actions {
                width: 100%;
                flex-direction: row;
                justify-content: flex-start;
                flex-wrap: wrap;
            }
            .btn-smart {
                flex: 1;
                justify-content: center;
            }
            .workflow-title { font-size: 1.5rem; }
            .step-card { min-width: 150px; }
        }
    

        /* Institutional theme alignment */
        :root {
            --primary: #2c3e50;
            --primary-light: #3f5870;
            --primary-dark: #1a2332;
            --secondary: #f1c40f;
            --dark: #1a2332;
            --dark-light: #2c3e50;
            --bg-body: #f5f6fa;
            --text-main: #2c3e50;
            --radius-md: 8px;
            --radius-lg: 10px;
            --radius-xl: 12px;
        }
        .topbar { background: #fff; border-bottom-color: #e2e8f0; padding: 12px 24px; }
        .topbar-brand { color: #1a2332; }
        .topbar-brand .icon-box { background: #1a2332; box-shadow: 0 4px 10px rgba(26, 35, 50, 0.25); }
        .topbar-nav a:hover, .topbar-nav a.active { color: #1a2332; background: rgba(241, 196, 15, 0.16); }
        .btn-smart-primary, .btn-primary { background-color: #2c3e50; border-color: #2c3e50; }
        .btn-smart-primary:hover, .btn-primary:hover { background-color: #1a2332; border-color: #1a2332; }
        .text-primary { color: #2c3e50 !important; }
        .bg-primary { background-color: #2c3e50 !important; }
        .border-primary { border-color: #2c3e50 !important; }
        .task-view-header {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            gap: 18px;
            margin-bottom: 22px;
            padding: 20px 22px;
            background: linear-gradient(135deg, #ffffff 0%, #f5f8fb 72%, #fff9df 100%);
            border: 1px solid #e2e8f0;
            border-left: 4px solid #f1c40f;
            border-radius: 10px;
            box-shadow: 0 4px 14px rgba(26, 35, 50, 0.06);
        }
        .page-title {
            color: #1a2332;
            font-size: 1.55rem;
            font-weight: 700;
        }
        .page-subtitle { color: #64748b; font-size: 0.88rem; }
        .status-filter-control {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            color: #2c3e50;
            background: #f8fafc;
            border: 1px solid #dce3e9;
            border-radius: 8px;
            padding: 4px;
            margin: 0;
            box-shadow: 0 2px 5px rgba(26, 35, 50, 0.04);
        }
        .task-filter-heading {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            width: 100%;
            padding: 4px 8px 2px;
            color: #1a2332;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }
        .task-filter-heading i { color: #b28a00; font-size: 0.9rem; }
        .status-filter-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #64748b;
            text-decoration: none;
            border-radius: 6px;
            flex: 1 1 auto;
            justify-content: center;
            padding: 9px 12px;
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .status-filter-pill:hover { color: #1a2332; background: #f3f6f8; }
        .status-filter-pill.active { color: #fff; background: #2c3e50; }
        .status-filter-pill.status-all.active {
            color: #1a2332;
            background: #f1c40f;
            box-shadow: 0 2px 6px rgba(241, 196, 15, 0.35);
        }
        .status-filter-pill.status-new:hover { color: #92400e; background: #fff7df; }
        .status-filter-pill.status-new.active { background: #d18b00; }
        .status-filter-pill.status-progress:hover { color: #075985; background: #e8f5fb; }
        .status-filter-pill.status-progress.active { background: #287a9f; }
        .status-filter-pill.status-complete:hover { color: #166534; background: #eaf8ef; }
        .status-filter-pill.status-complete.active { background: #2f855a; }
        .status-filter-pill.status-review:hover { color: #6b21a8; background: #f7effd; }
        .status-filter-pill.status-review.active { background: #8051a8; }
        .status-filter-pill span {
            min-width: 18px;
            padding: 1px 5px;
            border-radius: 10px;
            background: #edf1f4;
            color: #64748b;
            font-size: 0.7rem;
            text-align: center;
        }
        .status-filter-pill.active span { background: rgba(255,255,255,0.2); color: #fff; }
        .status-filter-pill.needs-attention { color: #b45309; }
        .status-filter-pill.needs-attention.active { background: #b45309; color: #fff; }
        .status-filter-pill.needs-attention span { color: #b45309; }
        .status-filter-pill.needs-attention.active span { color: #fff; }
        .status-filter-control select { display: none; }
        .status-filter-control i { display: none; }
        .status-filter-control[aria-label] { font-size: inherit; }
        .status-filter-control:focus-within { border-color: #2c3e50; }
        .status-filter-control a:focus-visible {
            outline: 2px solid #f1c40f;
            outline-offset: 1px;
        }
        .task-count-label { color: #64748b; font-size: 0.8rem; }
        .task-next-step {
            color: #64748b;
            font-size: 0.74rem;
            margin: -2px 0 3px;
        }
        .task-next-step strong { color: #2c3e50; }
        @media (max-width: 767.98px) {
            .task-view-header { gap: 12px; }
            .status-filter-control { width: 100%; justify-content: flex-start; }
            .status-filter-pill { flex: 1 1 auto; justify-content: center; }
        }
        .workflow-guide {
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 6px 18px rgba(26, 35, 50, 0.12);
        }
        .workflow-title { font-size: 1.55rem; }
        .workflow-subtitle { font-size: 0.9rem; margin-bottom: 20px; }
        .steps-container { gap: 10px; }
        .step-card {
            min-width: 175px;
            padding: 14px;
            border-radius: 8px;
        }
        .step-card:hover { transform: translateY(-2px); }
        .step-title { font-size: 0.95rem; }
        .step-desc { font-size: 0.76rem; }
        .action-bar { margin-bottom: 16px; }
        .action-bar h3 { font-size: 1.1rem; }
        .filter-tabs { border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: none; }
        .filter-tab { padding: 7px 12px; font-size: 0.82rem; }
        .task-list { gap: 12px; }
        .task-card {
            border-radius: 8px;
            padding: 18px 20px;
            gap: 16px;
            background: linear-gradient(100deg, #ffffff 0%, #fbfcfd 100%);
            box-shadow: 0 3px 12px rgba(26, 35, 50, 0.07);
        }
        .task-card:hover { transform: translateY(-1px); box-shadow: 0 5px 14px rgba(26, 35, 50, 0.1); }
        .task-status-bar { width: 4px; }
        .task-title { font-size: 1.05rem; margin-bottom: 5px; }
        .task-desc { font-size: 0.88rem; }
        .task-actions {
            gap: 7px;
            min-width: 155px;
            padding-left: 16px;
            border-left: 1px solid #e8edf1;
        }
        .task-actions .status-badge {
            align-self: flex-end;
            padding: 5px 9px;
            border-radius: 5px;
            font-size: 0.74rem;
            font-weight: 700;
        }
        .task-category { border: 1px solid #e2e8f0; }
        .task-deadline { font-size: 0.78rem; }
        .task-list .task-card { animation: taskReveal 0.35s ease both; }
        .task-list .task-card:nth-child(2) { animation-delay: 0.04s; }
        .task-list .task-card:nth-child(3) { animation-delay: 0.08s; }
        .task-list .task-card:nth-child(4) { animation-delay: 0.12s; }
        @keyframes taskReveal {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .btn-smart { padding: 8px 14px; border-radius: 7px; font-size: 0.84rem; }
        @media (max-width: 767.98px) {
            .workspace { padding: 22px 14px; }
            .task-view-header { padding: 18px 16px; }
            .workflow-guide { padding: 20px 16px; }
            .task-card { align-items: flex-start; padding: 16px; }
            .task-meta-top { flex-wrap: wrap; gap: 7px; }
            .task-actions { width: 100%; min-width: 0; align-items: stretch; padding-left: 0; padding-top: 12px; border-left: 0; border-top: 1px solid #e8edf1; }
            .task-actions .status-badge { align-self: flex-start; }
            .task-actions .btn-smart { justify-content: center; }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="topbar">
        <a href="<?php echo BASE_URL; ?>/faculty/dashboard" class="topbar-brand">
            <div class="icon-box"><i class="bi bi-mortarboard-fill"></i></div>
            <div>
                <h5 class="mb-0 fw-bold" style="font-size: 1.1rem;">Appraisal System</h5>
                <small class="text-muted" style="font-size: 0.75rem;">Faculty Workspace</small>
            </div>
        </a>
        <div class="d-none d-md-flex topbar-nav">
            <a href="<?php echo BASE_URL; ?>/faculty/dashboard"><i class="bi bi-grid me-1"></i> Dashboard</a>
            <a href="<?php echo BASE_URL; ?>/faculty/tasks" class="active"><i class="bi bi-card-checklist me-1"></i> My Tasks</a>
            <a href="<?php echo BASE_URL; ?>/faculty/profile" ><i class="bi bi-person me-1"></i> Profile</a>
        </div>
        
        <div class="d-flex align-items-center gap-3">
            <div class="dropdown">
                <button class="user-profile-btn" data-bs-toggle="dropdown">
                    <div class="user-avatar">
                        <?php 
                        // Try to get user info if not already loaded
                        if (!isset($user) && isset($profileModel)) {
                            $user = $profileModel->getProfile($_SESSION['user_id']);
                        } else if (!isset($user)) {
                            $stmt = getDB()->prepare("SELECT profile_photo, full_name FROM users WHERE id = ?");
                            $stmt->execute([$_SESSION['user_id']]);
                            $user = $stmt->fetch();
                        }
                        
                        if(!empty($user['profile_photo'])): ?>
                            <img src="<?php echo BASE_URL; ?>/assets/uploads/profiles/<?php echo $user['profile_photo']; ?>" class="rounded-circle w-100 h-100" style="object-fit:cover">
                        <?php else: ?>
                            <i class="bi bi-person-fill"></i>
                        <?php endif; ?>
                    </div>
                    <span class="d-none d-md-block fw-bold text-dark small"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></span>
                    <i class="bi bi-chevron-down text-muted ms-1" style="font-size:0.8rem"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" style="border-radius: 12px; min-width: 200px;">
                    <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/faculty/profile"><i class="bi bi-person me-2 text-muted"></i> My Profile</a></li>
                    <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/faculty/dashboard"><i class="bi bi-grid me-2 text-muted"></i> Dashboard</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="<?php echo BASE_URL; ?>/logout"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                </ul>
            </div>
            <a href="<?php echo BASE_URL; ?>/logout" class="btn btn-light rounded-circle text-danger d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border: 1px solid #fee2e2;" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    
    </nav>
    
    <div class="workspace">
        
        <!-- Alerts -->
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px; border: none; background: #ECFDF5; color: #065F46;">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill fs-5 me-3 text-success"></i>
                <div><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px; border: none; background: #FEF2F2; color: #991B1B;">
            <div class="d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill fs-5 me-3 text-danger"></i>
                <div><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Task view controls -->
        <div class="task-view-header">
            <div>
                <h1 class="page-title mb-1">My Tasks</h1>
                <p class="page-subtitle mb-0">Start a task, mark it complete, then submit your evidence.</p>
            </div>
            <nav class="status-filter-control" aria-label="Filter tasks by status">
                <div class="task-filter-heading"><i class="bi bi-funnel-fill"></i> View tasks by status</div>
                <a href="?status=all" class="status-filter-pill status-all <?php echo $statusFilter === 'all' ? 'active' : ''; ?>">All tasks</a>
                <a href="?status=pending_initiated" class="status-filter-pill status-new <?php echo $statusFilter === 'pending_initiated' ? 'active' : ''; ?>">New <span><?php echo $totalPending; ?></span></a>
                <a href="?status=In Progress" class="status-filter-pill status-progress <?php echo $statusFilter === 'In Progress' ? 'active' : ''; ?>">In progress <span><?php echo $statusCounts['In Progress'] ?? 0; ?></span></a>
                <a href="?status=Completed" class="status-filter-pill status-complete <?php echo $statusFilter === 'Completed' ? 'active' : ''; ?>">Completed <span><?php echo $statusCounts['Completed'] ?? 0; ?></span></a>
                <a href="?status=Submitted" class="status-filter-pill status-review <?php echo $statusFilter === 'Submitted' ? 'active' : ''; ?>">Review <span><?php echo $statusCounts['Submitted'] ?? 0; ?></span></a>
                <?php if (($statusCounts['Rejected'] ?? 0) > 0): ?>
                <a href="?status=Rejected" class="status-filter-pill needs-attention <?php echo $statusFilter === 'Rejected' ? 'active' : ''; ?>">Needs changes <span><?php echo $statusCounts['Rejected']; ?></span></a>
                <?php endif; ?>
            </nav>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <h3 class="mb-0 fw-bold d-flex align-items-center gap-2 text-dark">
                <i class="bi bi-list-check text-primary"></i> 
                <?php 
                    if($statusFilter == 'all') echo "All Active Tasks";
                    else if($statusFilter == 'pending_initiated') echo "New & Pending Tasks";
                    else echo htmlspecialchars($statusFilter) . " Tasks";
                ?>
                <span class="badge bg-light text-secondary border rounded-pill px-3 fs-6"><?php echo count($filteredTasks); ?></span>
            </h3>
            
            <span class="task-count-label"><i class="bi bi-list-check me-1"></i><?php echo count($filteredTasks); ?> shown</span>
        </div>

        <!-- Task List -->
        <?php if (count($filteredTasks) > 0): ?>
        <div class="task-list">
            <?php foreach ($filteredTasks as $task): 
                $statusClass = str_replace(' ', '', $task['status']);
                $iconClass = "bi-file-text";
                if(in_array($task['status'], ['Pending', 'Initiated'])) $iconClass = "bi-inbox";
                if($task['status'] == 'In Progress') $iconClass = "bi-arrow-repeat";
                if($task['status'] == 'Completed') $iconClass = "bi-check2-all";
                if($task['status'] == 'Submitted') $iconClass = "bi-send-check";
                if($task['status'] == 'Rejected') $iconClass = "bi-x-octagon";
                
                $dueDateStr = '';
                $dueClass = '';
                if ($task['due_date']) {
                    $dueDate = strtotime($task['due_date']);
                    $today = strtotime(date('Y-m-d'));
                    $diff = ($dueDate - $today) / (60 * 60 * 24);
                    $dueDateStr = date('d M, Y', $dueDate);
                    if ($diff < 0) {
                        $dueDateStr .= ' (Overdue)';
                        $dueClass = 'overdue';
                    } else if ($diff < 7) {
                        $dueClass = 'text-warning fw-bold';
                    }
                }
            ?>
            <div class="task-card status-<?php echo $statusClass; ?>">
                <div class="task-status-bar"></div>
                
                <div class="task-icon-wrapper d-none d-sm-flex">
                    <i class="bi <?php echo $iconClass; ?>"></i>
                </div>
                
                <div class="task-content">
                    <div class="task-meta-top">
                        <span class="task-category"><i class="bi bi-tag-fill me-1 opacity-50"></i><?php echo htmlspecialchars($task['category_name']); ?></span>
                        <?php if($task['assigned_by'] != $userId): ?>
                            <span class="text-muted"><i class="bi bi-person-badge me-1"></i>By <?php echo htmlspecialchars($task['assigned_by_name']); ?></span>
                        <?php else: ?>
                            <span class="text-muted"><i class="bi bi-person-badge me-1"></i>Self Assigned</span>
                        <?php endif; ?>
                        
                        <?php if($dueDateStr): ?>
                        <span class="task-deadline <?php echo $dueClass; ?>"><i class="bi bi-calendar-event"></i> Due: <?php echo $dueDateStr; ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <h4 class="task-title"><?php echo htmlspecialchars($task['task_title']); ?></h4>
                    <p class="task-desc"><?php echo htmlspecialchars($task['task_description'] ?: 'No additional instructions provided.'); ?></p>
                    
                    <?php if($task['status'] == 'Rejected' && !empty($task['evaluation_remarks'])): ?>
                    <div class="mt-3 p-3 bg-danger bg-opacity-10 rounded text-danger" style="font-size: 0.9rem;">
                        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Rejection Reason:</strong> <?php echo htmlspecialchars($task['evaluation_remarks']); ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="task-actions">
                    <div class="status-badge <?php echo $statusClass; ?> mb-2">
                        <i class="bi <?php echo $iconClass; ?>"></i> <?php echo htmlspecialchars($task['status']); ?>
                    </div>
                    
                    <!-- Smart Workflow Buttons -->
                    <?php if(in_array($task['status'], ['Pending', 'Initiated', 'Rejected'])): ?>
                        <div class="task-next-step">Next: <strong>start working</strong></div>
                        <form action="<?php echo BASE_URL; ?>/faculty/tasks" method="POST" class="m-0">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                            <input type="hidden" name="status" value="In Progress">
                            <button type="submit" class="btn-smart btn-smart-primary">
                                Start working <i class="bi bi-arrow-right"></i>
                            </button>
                        </form>
                    <?php elseif($task['status'] == 'In Progress'): ?>
                        <div class="task-next-step">Next: <strong>mark when finished</strong></div>
                        <form action="<?php echo BASE_URL; ?>/faculty/tasks" method="POST" class="m-0">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                            <input type="hidden" name="status" value="Completed">
                            <button type="submit" class="btn-smart btn-smart-success">
                                Mark as complete <i class="bi bi-check-lg"></i>
                            </button>
                        </form>
                    <?php elseif($task['status'] == 'Completed'): ?>
                        <div class="task-next-step">Next: <strong>submit your evidence</strong></div>
                        <button class="btn-smart btn-smart-primary" onclick="openSubmitModal(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars(addslashes($task['task_title'])); ?>')">
                            Submit evidence <i class="bi bi-upload"></i>
                        </button>
                    <?php endif; ?>
                    
                    <button class="btn-smart btn-smart-outline mt-1" onclick="viewTask(<?php echo htmlspecialchars(json_encode($task)); ?>)">
                        View Details
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="bi bi-stars"></i>
            </div>
            <h3 class="fw-bold">You're all caught up!</h3>
            <p class="text-muted" style="max-width: 400px; margin: 0 auto 20px;">
                <?php if ($statusFilter == 'all'): ?>
                    There are no active tasks assigned to you right now. 
                <?php else: ?>
                    There are no tasks matching the "<?php echo htmlspecialchars($statusFilter); ?>" status.
                <?php endif; ?>
            </p>
            <?php if ($statusFilter != 'all'): ?>
            <a href="?status=all" class="btn btn-primary px-4 py-2 rounded-pill shadow-sm">View All Tasks</a>
            <?php else: ?>
            <button class="btn btn-primary px-4 py-2 rounded-pill shadow-sm" data-bs-toggle="modal" data-bs-target="#addSelfTaskModal">Add a Self Task</button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <!-- Modals (Task Edit, Document Submission) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <div class="modal fade" id="viewTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-1 rounded-pill mb-2" id="viewCategory">Category</span>
                        <h4 class="modal-title mb-1" id="viewTitle">Task Title</h4>
                        <div class="text-muted" style="font-size: 0.9rem;">
                            Assigned by <strong id="viewAssignedBy" class="text-dark">Admin</strong> 
                            • Due <strong id="viewDueDate" class="text-dark">Date</strong>
                        </div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="p-4 bg-light rounded-3 mb-4">
                        <h6 class="text-muted fw-bold mb-2">Instructions / Description</h6>
                        <p id="viewDesc" class="mb-0" style="white-space: pre-wrap; font-size: 1.05rem;"></p>
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100">
                                <h6 class="text-muted fw-bold mb-3">Status Tracking</h6>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Current Status</span>
                                    <strong id="viewStatus" class="text-primary">Status</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Priority</span>
                                    <strong id="viewPriority">Medium</strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Duration</span>
                                    <strong id="viewDuration">Semester</strong>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="border rounded-3 p-3 h-100" id="viewAttachmentArea">
                                <h6 class="text-muted fw-bold mb-3">Task Attachment</h6>
                                <div id="viewAttachmentContent"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Submit Task Modal -->
    <div class="modal fade" id="submitTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Submit Evidence</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="<?php echo BASE_URL; ?>/faculty/tasks" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="submit_task">
                        <input type="hidden" name="task_id" id="submitTaskId">
                        
                        <div class="alert alert-info" style="border-radius: 10px; font-size: 0.9rem;">
                            <i class="bi bi-info-circle me-1"></i> Submitting evidence for: <br>
                            <strong id="submitTaskTitle" class="d-block mt-1"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Submission Remarks / Notes <span class="text-danger">*</span></label>
                            <textarea name="submission_text" class="form-control" rows="3" required placeholder="Describe what you accomplished..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Evidence Upload <span class="text-muted">(Optional)</span></label>
                            <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                            <div class="form-text mt-2"><i class="bi bi-paperclip"></i> Max size 5MB. PDF or Image recommended.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 shadow-sm">Submit to Principal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Self Task Modal -->
    <div class="modal fade" id="addSelfTaskModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Self-Assigned Task</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="<?php echo BASE_URL; ?>/faculty/tasks" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_self_task">
                        <div class="mb-3">
                            <label class="form-label">Task Title <span class="text-danger">*</span></label>
                            <input type="text" name="task_title" class="form-control" required placeholder="E.g., Organized Guest Lecture">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category_id" class="form-select" required>
                                <option value="">Select Category...</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="task_description" class="form-control" rows="2" placeholder="Brief details about the task"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Completion / Due Date</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary px-4 shadow-sm">Add Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function viewTask(task) {
            document.getElementById('viewTitle').textContent = task.task_title;
            document.getElementById('viewCategory').textContent = task.category_name;
            document.getElementById('viewDesc').textContent = task.task_description || 'No description provided.';
            document.getElementById('viewAssignedBy').textContent = task.assigned_by_name;
            document.getElementById('viewPriority').textContent = task.priority;
            document.getElementById('viewDuration').textContent = task.duration;
            document.getElementById('viewStatus').textContent = task.status;
            
            let dueDateText = 'Not specified';
            if (task.due_date) {
                const options = { year: 'numeric', month: 'long', day: 'numeric' };
                dueDateText = new Date(task.due_date).toLocaleDateString(undefined, options);
            }
            document.getElementById('viewDueDate').textContent = dueDateText;
            
            const attachArea = document.getElementById('viewAttachmentContent');
            if (task.attachment) {
                const isUrl = task.attachment.startsWith('http://') || task.attachment.startsWith('https://');
                if (isUrl) {
                    attachArea.innerHTML = `<a href="${task.attachment}" target="_blank" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-box-arrow-up-right me-2"></i>Open Web Link</a>`;
                } else {
                    attachArea.innerHTML = `
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-file-earmark-text fs-4 text-primary"></i>
                            <span class="text-truncate" style="max-width:200px;">${task.attachment}</span>
                        </div>
                        <a href="<?php echo BASE_URL; ?>/assets/download.php?file=${encodeURIComponent(task.attachment)}&action=download" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-download me-2"></i>Download File</a>
                    `;
                }
            } else {
                attachArea.innerHTML = '<div class="text-muted text-center py-3"><i class="bi bi-slash-circle me-2"></i>No attachment provided</div>';
            }
            
            new bootstrap.Modal(document.getElementById('viewTaskModal')).show();
        }

        function openSubmitModal(id, title) {
            document.getElementById('submitTaskId').value = id;
            document.getElementById('submitTaskTitle').textContent = title;
            new bootstrap.Modal(document.getElementById('submitTaskModal')).show();
        }
    </script>
</body>
</html>
