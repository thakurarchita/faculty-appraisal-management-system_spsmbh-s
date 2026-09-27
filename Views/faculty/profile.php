<?php
require_once __DIR__ . '/../../includes/functions.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? (BASE_URL . '/faculty/profile');
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is faculty
if ($_SESSION['role'] !== 'Faculty') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/FacultyProfile.php';

$userId = $_SESSION['user_id'];
$db = getDB();
$userModel = new User();

$profileModel = new FacultyProfile();
$facultyProfile = $profileModel->getProfile($userId);
$educationList = $profileModel->getEducation($userId);
$awardsList = $profileModel->getAwards($userId);
$publicationsList = $profileModel->getPublications($userId);
$fdpList = $profileModel->getFDPs($userId);
$galleryList = $profileModel->getGallery($userId);
$galleryCount = $profileModel->getGalleryCount($userId);

// Fetch Theme Settings
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$theme = [
    'theme_primary' => '#7c3aed', // Default primary 
    'theme_secondary' => '#6d2edb', // Default secondary
    'theme_accent' => '#a78bfa'  // Default accent
];

foreach ($settings_rows as $row) {
    if (array_key_exists($row['setting_key'], $theme)) {
        $theme[$row['setting_key']] = $row['setting_value'];
    }
}

// Check and add profile_photo column if not exists
try {
    $check = $db->query("PRAGMA table_info(users)");
    $columns = [];
    while ($row = $check->fetch()) {
        $columns[] = $row['name'];
    }
    if (!in_array('profile_photo', $columns)) {
        $db->exec("ALTER TABLE users ADD COLUMN profile_photo TEXT");
    }
} catch(PDOException $e) {
    // Column might already exist
}

// Get user details
$user = $userModel->getUserById($userId);

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    // CSRF check — skip for AJAX file uploads; enforce for standard forms
    if (!in_array($action, ['upload_photo', 'upload_document']) && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Security check failed. Please refresh the page and try again.';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }

    if ($action == 'update_profile') {
        $full_name = sanitizeInput($_POST['full_name']);
        $mobile = sanitizeInput($_POST['mobile']);
        $designation = sanitizeInput($_POST['designation']);
        
        $stmt = $db->prepare("
            UPDATE users 
            SET full_name = ?, mobile = ?, designation = ?, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $result = $stmt->execute([$full_name, $mobile, $designation, $userId]);
        
        if ($result) {
            $_SESSION['full_name'] = $full_name;
            $_SESSION['success'] = 'Profile updated successfully!';
        } else {
            $_SESSION['error'] = 'Failed to update profile.';
        }
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'change_password') {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (!verifyPassword($current_password, $user['password'])) {
            $_SESSION['error'] = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 8) {
            $_SESSION['error'] = 'New password must be at least 8 characters long.';
        } elseif ($new_password !== $confirm_password) {
            $_SESSION['error'] = 'Passwords do not match.';
        } else {
            $hashedPassword = hashPassword($new_password);
            $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if ($stmt->execute([$hashedPassword, $userId])) {
                $_SESSION['success'] = 'Password changed successfully!';
            } else {
                $_SESSION['error'] = 'Failed to change password.';
            }
        }
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'upload_photo') {
        if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_photo']['name'];
            $filetype = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $filesize = $_FILES['profile_photo']['size'];
            
            if ($filesize > 2 * 1024 * 1024) {
                $_SESSION['error'] = 'File size must be less than 2MB.';
            } elseif (!in_array($filetype, $allowed)) {
                $_SESSION['error'] = 'Only JPG, JPEG, PNG, and GIF files are allowed.';
            } else {
                $upload_dir = __DIR__ . '/../../assets/uploads/profiles/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $new_filename = 'profile_' . $userId . '_' . time() . '.' . $filetype;
                $upload_path = $upload_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_path)) {
                    if (!empty($user['profile_photo']) && file_exists($upload_dir . $user['profile_photo'])) {
                        unlink($upload_dir . $user['profile_photo']);
                    }
                    
                    $stmt = $db->prepare("UPDATE users SET profile_photo = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    if ($stmt->execute([$new_filename, $userId])) {
                        $_SESSION['success'] = 'Profile photo updated successfully!';
                    } else {
                        $_SESSION['error'] = 'Failed to update profile photo in database.';
                    }
                } else {
                    $_SESSION['error'] = 'Failed to upload photo.';
                }
            }
        } else {
            $_SESSION['error'] = 'Please select a photo to upload.';
        }
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'upload_cropped_photo') {
        $base64Image = $_POST['cropped_image'] ?? '';
        if ($base64Image) {
            $image_parts = explode(";base64,", $base64Image);
            $image_type_aux = explode("image/", $image_parts[0]);
            $image_type = $image_type_aux[1];
            $image_base64 = base64_decode($image_parts[1]);
            
            $upload_dir = __DIR__ . '/../../assets/uploads/profiles/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $new_filename = 'profile_' . $userId . '_' . time() . '.png';
            $upload_path = $upload_dir . $new_filename;
            
            if (file_put_contents($upload_path, $image_base64)) {
                if (!empty($user['profile_photo']) && file_exists($upload_dir . $user['profile_photo'])) {
                    unlink($upload_dir . $user['profile_photo']);
                }
                $stmt = $db->prepare("UPDATE users SET profile_photo = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$new_filename, $userId]);
                echo json_encode(['success' => true]);
                exit();
            }
        }
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit();
    }
    
    if ($action == 'update_profile_links') {
        $data = [
            'bio' => $_POST['bio'] ?? '',
            'publon_link' => $_POST['publon_link'] ?? '',
            'scopus_link' => $_POST['scopus_link'] ?? '',
            'google_scholar_link' => $_POST['google_scholar_link'] ?? '',
            'other_link' => $_POST['other_link'] ?? '',
            'whatsapp_link' => $_POST['whatsapp_link'] ?? '',
            'facebook_link' => $_POST['facebook_link'] ?? '',
            'youtube_link' => $_POST['youtube_link'] ?? ''
        ];
        $profileModel->saveProfile($userId, $data);
        $_SESSION['success'] = 'Profile details updated!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'add_education') {
        $profileModel->addEducation($userId, $_POST);
        $_SESSION['success'] = 'Education added!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    if ($action == 'delete_education') {
        $profileModel->deleteEducation($userId, $_POST['id']);
        $_SESSION['success'] = 'Education deleted!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'add_award') {
        $profileModel->addAward($userId, $_POST);
        $_SESSION['success'] = 'Award added!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    if ($action == 'delete_award') {
        $profileModel->deleteAward($userId, $_POST['id']);
        $_SESSION['success'] = 'Award deleted!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'add_publication') {
        $profileModel->addPublication($userId, $_POST);
        $_SESSION['success'] = 'Publication added!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    if ($action == 'delete_publication') {
        $profileModel->deletePublication($userId, $_POST['id']);
        $_SESSION['success'] = 'Publication deleted!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'add_fdp') {
        $profileModel->addFDP($userId, $_POST);
        $_SESSION['success'] = 'FDP added!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    if ($action == 'delete_fdp') {
        $profileModel->deleteFDP($userId, $_POST['id']);
        $_SESSION['success'] = 'FDP deleted!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
    
    if ($action == 'upload_gallery_photo') {
        $galleryCount = $profileModel->getGalleryCount($userId);
        if ($galleryCount >= 12) {
            echo json_encode(['success' => false, 'error' => 'Maximum 12 photos allowed.']);
            exit();
        }
        $base64Image = $_POST['gallery_image'] ?? '';
        $caption = $_POST['gallery_caption'] ?? '';
        if ($base64Image) {
            $image_parts = explode(";base64,", $base64Image);
            $image_base64 = base64_decode($image_parts[1]);
            
            // Compress to under 100kb
            $img = imagecreatefromstring($image_base64);
            if ($img) {
                $upload_dir = __DIR__ . '/../../assets/uploads/gallery/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
                $new_filename = 'gallery_' . $userId . '_' . time() . '_' . rand(100,999) . '.jpg';
                $upload_path = $upload_dir . $new_filename;
                
                // Iteratively lower quality until <100kb
                $quality = 90;
                ob_start();
                imagejpeg($img, null, $quality);
                $data = ob_get_clean();
                while (strlen($data) > 102400 && $quality > 10) {
                    $quality -= 10;
                    ob_start();
                    imagejpeg($img, null, $quality);
                    $data = ob_get_clean();
                }
                // If still too large, resize
                if (strlen($data) > 102400) {
                    $w = imagesx($img);
                    $h = imagesy($img);
                    $ratio = sqrt(102400 / strlen($data));
                    $newW = (int)($w * $ratio);
                    $newH = (int)($h * $ratio);
                    $resized = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    imagedestroy($img);
                    $img = $resized;
                    ob_start();
                    imagejpeg($img, null, 80);
                    $data = ob_get_clean();
                }
                imagedestroy($img);
                
                if (file_put_contents($upload_path, $data)) {
                    $profileModel->addGalleryPhoto($userId, $new_filename, $caption);
                    echo json_encode(['success' => true, 'count' => $galleryCount + 1]);
                    exit();
                }
            }
        }
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit();
    }
    
    if ($action == 'delete_gallery_photo') {
        $profileModel->deleteGalleryPhoto($userId, $_POST['id']);
        $_SESSION['success'] = 'Gallery photo deleted!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }
}

    if (($_POST['action'] ?? '') == 'upload_document') {
        $base64Image = $_POST['document_image'] ?? '';
        $document_name = sanitizeInput($_POST['document_name'] ?? '');
        $visibility = sanitizeInput($_POST['visibility'] ?? 'private');
        
        if ($base64Image && $document_name) {
            $image_parts = explode(";base64,", $base64Image);
            $image_base64 = base64_decode($image_parts[1]);
            
            $upload_dir = __DIR__ . '/../../assets/uploads/documents/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            
            $new_filename = 'doc_' . $userId . '_' . time() . '_' . rand(100,999) . '.jpg';
            $upload_path = $upload_dir . $new_filename;
            
            // Convert PNG base64 to JPG to save space
            $img = @imagecreatefromstring($image_base64);
            if ($img) {
                // Resize if too large
                $w = imagesx($img);
                $h = imagesy($img);
                if ($w > 1500) {
                    $newW = 1500;
                    $newH = (int)($h * (1500 / $w));
                    $resized = imagecreatetruecolor($newW, $newH);
                    imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    imagedestroy($img);
                    $img = $resized;
                }
                
                if (imagejpeg($img, $upload_path, 75)) {
                    $profileModel->addDocument($userId, [
                        'document_name' => $document_name,
                        'filename' => $new_filename,
                        'visibility' => $visibility
                    ]);
                    imagedestroy($img);
                    echo json_encode(['success' => true]);
                    exit();
                }
                imagedestroy($img);
            }
        }
        echo json_encode(['success' => false, 'error' => 'Upload failed']);
        exit();
    }
    
    if (($_POST['action'] ?? '') == 'delete_document') {
        $docId = $_POST['id'] ?? 0;
        $profileModel->deleteDocument($userId, $docId);
        $_SESSION['success'] = 'Document deleted successfully!';
        header('Location: ' . BASE_URL . '/faculty/profile');
        exit();
    }



$profileCompletion = $profileModel->calculateProfileCompletion($userId);
$documents = $profileModel->getDocuments($userId);
$publicDocs = array_filter($documents, fn($d) => $d['visibility'] === 'public');
$privateDocs = array_filter($documents, fn($d) => $d['visibility'] === 'private');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Profile - Faculty Workspace</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Cropper.js CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" />
    
    <style>
        :root {
            --primary: #4F46E5;
            --primary-light: #6366F1;
            --primary-dark: #4338CA;
            --secondary: #10B981;
            --dark: #0F172A;
            --dark-light: #1E293B;
            --bg-body: #F8FAFC;
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
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
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
        .btn-primary { background-color: #2c3e50; border-color: #2c3e50; }
        .btn-primary:hover { background-color: #1a2332; border-color: #1a2332; }
        .text-primary { color: #2c3e50 !important; }
        .bg-primary { background-color: #2c3e50 !important; }
        .border-primary { border-color: #2c3e50 !important; }
        .completion-banner {
            border-radius: 10px;
            padding: 22px 24px;
            margin-bottom: 24px;
            box-shadow: 0 6px 18px rgba(26, 35, 50, 0.12);
        }
        .profile-header-card, .section-card {
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(26, 35, 50, 0.06);
            border-color: #e2e8f0;
        }
        .profile-header-card { padding: 24px; }
        .section-card { padding: 20px; margin-bottom: 18px; }
        .section-title {
            font-size: 1.08rem;
            margin-bottom: 16px;
            padding-bottom: 10px;
        }
        .profile-avatar { border-radius: 10px; }
        .form-control, .form-select {
            border-color: #d9e0e7;
            border-radius: 6px;
            min-height: 40px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #2c3e50;
            box-shadow: 0 0 0 3px rgba(44, 62, 80, 0.12);
        }
        .btn-primary { border-radius: 6px; }
        .doc-card, .add-doc-card { border-radius: 8px; }
        @media (max-width: 767.98px) {
            .workspace { padding: 22px 14px; }
            .completion-banner { padding: 18px; gap: 16px; }
            .progress-circle { width: 64px; height: 64px; }
            .profile-header-card, .section-card { padding: 16px; }
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
            <a href="<?php echo BASE_URL; ?>/faculty/tasks"><i class="bi bi-card-checklist me-1"></i> My Tasks</a>
            <a href="<?php echo BASE_URL; ?>/faculty/profile" class="active"><i class="bi bi-person me-1"></i> Profile</a>
        </div>
        <div class="d-flex align-items-center">
            <a href="<?php echo BASE_URL; ?>/logout" class="btn btn-light rounded-pill btn-sm text-danger fw-bold px-3 border"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
        </div>
    </nav>

    <div class="workspace">
        
        <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px; border: none; background: #ECFDF5; color: #065F46;">
            <i class="bi bi-check-circle-fill me-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert" style="border-radius: 12px; border: none; background: #FEF2F2; color: #991B1B;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Profile Completion -->
        <div class="completion-banner">
            <div class="progress-circle">
                <span class="progress-text"><?php echo $profileCompletion; ?>%</span>
            </div>
            <div style="z-index: 1;">
                <h3 class="fw-bold mb-1">Profile Completion</h3>
                <p class="mb-0 opacity-75">Upload your documents, education, and bio to complete your profile.</p>
            </div>
        </div>

        <div class="row">
            <!-- Left Sidebar -->
            <div class="col-lg-4">
                <div class="profile-header-card">
                    <div class="d-flex align-items-center flex-column text-center">
                        <div class="profile-avatar-wrapper mb-3 mx-auto">
                            <?php if (!empty($user['profile_photo'])): ?>
                                <img src="<?php echo BASE_URL; ?>/assets/uploads/profiles/<?php echo htmlspecialchars($user['profile_photo']); ?>" class="profile-avatar" alt="Profile">
                            <?php else: ?>
                                <div class="profile-avatar d-flex align-items-center justify-content-center bg-light text-secondary" style="font-size: 3rem;">
                                    <?php echo substr($user['full_name'], 0, 1); ?>
                                </div>
                            <?php endif; ?>
                            <div class="avatar-edit-btn" data-bs-toggle="modal" data-bs-target="#photoModal" title="Update Photo">
                                <i class="bi bi-camera-fill"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold text-dark mb-1" style="font-family:'Outfit';"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <p class="text-primary fw-bold mb-1"><?php echo htmlspecialchars($user['designation'] ?: 'Designation Not Set'); ?></p>
                        <p class="text-muted small mb-3"><i class="bi bi-person-badge me-1"></i> <?php echo htmlspecialchars($user['employee_id']); ?></p>
                        
                        <div class="w-100 text-start bg-light rounded p-3 mt-2">
                            <div class="mb-2"><i class="bi bi-envelope text-muted me-2"></i> <?php echo htmlspecialchars($user['email']); ?></div>
                            <div><i class="bi bi-telephone text-muted me-2"></i> <?php echo htmlspecialchars($user['mobile'] ?: 'Not added'); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="section-card">
                    <h5 class="section-title"><i class="bi bi-person-lines-fill text-primary"></i> Basic Info</h5>
                    <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST">
                        <?php echo csrfField(); ?>
                        
                        <input type="hidden" name="action" value="update_profile">
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Full Name</label>
                            <input type="text" name="full_name" class="form-control bg-light" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Designation</label>
                            <input type="text" name="designation" class="form-control" value="<?php echo htmlspecialchars($user['designation'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small fw-bold">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?php echo htmlspecialchars($user['mobile'] ?? ''); ?>">
                        </div>
                        <button type="submit" class="btn btn-primary w-100 rounded-3 shadow-sm">Save Changes</button>
                    </form>
                </div>
            </div>
            
            <!-- Right Content -->
            <div class="col-lg-8">
                
                <?php
                    $slugStmt = $db->prepare("SELECT profile_slug FROM users WHERE id = ?");
                    $slugStmt->execute([$userId]);
                    $mySlug = $slugStmt->fetchColumn();
                    if (!$mySlug) {
                        $mySlug = strtolower(str_replace(' ', '-', $user['full_name'])) . '-' . $user['id'];
                    }
                ?>
                <!-- Navigation Tabs -->

                <ul class="nav nav-pills custom-nav-pills mb-4" id="profileTabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tab-education"><i class="bi bi-mortarboard me-2"></i>Education</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tab-bio"><i class="bi bi-card-text me-2"></i>Bio & Links</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tab-docs"><i class="bi bi-folder-check me-2"></i>Documents</a></li>
                    
                    <li class="nav-item ms-auto">
                        <a class="nav-link text-white shadow-sm" style="background: var(--primary); font-weight: 600;" href="<?php echo BASE_URL; ?>/faculty/<?php echo urlencode($mySlug); ?>" target="_blank">
                            <i class="bi bi-box-arrow-up-right me-2"></i>View Public Profile
                        </a>
                    </li>
                </ul>

                <div class="tab-content">
                    
                    <!-- Documents Tab -->
                    <div class="tab-pane fade" id="tab-docs">
                        
                        <!-- Private Docs -->
                        <div class="section-card">
                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                <h5 class="section-title border-0 mb-0"><i class="bi bi-shield-lock-fill text-danger"></i> Private Documents</h5>
                                <button class="btn btn-sm btn-outline-danger" onclick="openDocModal('private')"><i class="bi bi-plus"></i> Add Private</button>
                            </div>
                            <p class="text-muted small mb-3">Upload Aadhaar, PAN, Bank Passbook, Degree Marksheets, Licenses, Insurance. (Only visible to you and Admin).</p>
                            
                            <div class="doc-grid">
                                <?php foreach($privateDocs as $doc): ?>
                                <div class="doc-card">
                                    <div class="doc-preview">
                                        <img src="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" alt="Document">
                                        <div class="doc-overlay">
                                            <a href="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" target="_blank" class="btn btn-light btn-sm rounded-circle"><i class="bi bi-eye"></i></a>
                                            <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST" class="m-0" onsubmit="return confirm('Delete this document?');">
                                                <input type="hidden" name="action" value="delete_document">
                                                <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm rounded-circle"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="doc-info">
                                        <div class="doc-name" title="<?php echo htmlspecialchars($doc['document_name']); ?>"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?php echo date('d M Y', strtotime($doc['created_at'])); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div class="add-doc-card" onclick="openDocModal('private')">
                                    <i class="bi bi-cloud-arrow-up fs-2 mb-2"></i>
                                    <span class="fw-bold">Upload Private Document</span>
                                </div>
                            </div>
                        </div>

                        <!-- Public Docs -->
                        <div class="section-card">
                            <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-2">
                                <h5 class="section-title border-0 mb-0"><i class="bi bi-globe text-primary"></i> Public Documents</h5>
                                <button class="btn btn-sm btn-outline-primary" onclick="openDocModal('public')"><i class="bi bi-plus"></i> Add Public</button>
                            </div>
                            <p class="text-muted small mb-3">Upload FDP certificates, Research certificates, and Awards. (Visible on your public profile).</p>
                            
                            <div class="doc-grid">
                                <?php foreach($publicDocs as $doc): ?>
                                <div class="doc-card">
                                    <div class="doc-preview">
                                        <img src="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" alt="Document">
                                        <div class="doc-overlay">
                                            <a href="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" target="_blank" class="btn btn-light btn-sm rounded-circle"><i class="bi bi-eye"></i></a>
                                            <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST" class="m-0" onsubmit="return confirm('Delete this document?');">
                                                <input type="hidden" name="action" value="delete_document">
                                                <input type="hidden" name="id" value="<?php echo $doc['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm rounded-circle"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="doc-info">
                                        <div class="doc-name" title="<?php echo htmlspecialchars($doc['document_name']); ?>"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                                        <div class="text-muted" style="font-size: 0.75rem;"><?php echo date('d M Y', strtotime($doc['created_at'])); ?></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div class="add-doc-card" onclick="openDocModal('public')" style="background: rgba(16,185,129,0.05); border-color: rgba(16,185,129,0.3); color: var(--secondary);">
                                    <i class="bi bi-cloud-arrow-up fs-2 mb-2"></i>
                                    <span class="fw-bold">Upload Public Document</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Education Tab -->
                    <div class="tab-pane fade show active" id="tab-education">
                        <div class="section-card">
                            <h5 class="section-title"><i class="bi bi-mortarboard text-warning"></i> Educational Qualifications</h5>
                            
                            <div class="table-responsive mb-4">
                                <table class="table table-hover border align-middle">
                                    <thead class="table-light text-muted small">
                                        <tr>
                                            <th>Degree / Exam</th>
                                            <th>Institution / Board</th>
                                            <th>Year</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(empty($educationList)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-3">No education details added yet.</td></tr>
                                        <?php else: foreach($educationList as $edu): ?>
                                        <tr>
                                            <td class="fw-bold text-dark"><?php echo htmlspecialchars($edu['degree_name']); ?></td>
                                            <td><?php echo htmlspecialchars($edu['institution']); ?></td>
                                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($edu['passing_year']); ?></span></td>
                                            <td class="text-end">
                                                <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST" class="d-inline" onsubmit="return confirm('Delete this record?');">
                                                    <input type="hidden" name="action" value="delete_education">
                                                    <input type="hidden" name="id" value="<?php echo $edu['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </td>
                                        </tr>
                                        <?php endforeach; endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST" class="bg-light p-4 rounded-3 border">
                                <h6 class="fw-bold mb-3">Add Qualification</h6>
                                <input type="hidden" name="action" value="add_education">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <input type="text" name="degree_name" class="form-control" placeholder="Degree (e.g., Ph.D, M.Tech)" required>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" name="institution" class="form-control" placeholder="University / Institution" required>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="text" name="passing_year" class="form-control" placeholder="Passing Year" required>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-dark w-100 rounded-3">Add Record</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Bio Tab -->
                    <div class="tab-pane fade" id="tab-bio">
                        <div class="section-card">
                            <h5 class="section-title"><i class="bi bi-card-text text-info"></i> Biography & Links</h5>
                            <form action="<?php echo BASE_URL; ?>/faculty/profile" method="POST">
                        <?php echo csrfField(); ?>
                        
                                <input type="hidden" name="action" value="update_profile_links">
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-muted small">Biography / About Me</label>
                                    <textarea name="bio" class="form-control" rows="4" placeholder="Write a short professional bio..."><?php echo htmlspecialchars($facultyProfile['bio'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small"><i class="bi bi-google me-1"></i> Google Scholar</label>
                                        <input type="url" name="google_scholar_link" class="form-control" value="<?php echo htmlspecialchars($facultyProfile['google_scholar_link'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small"><i class="bi bi-book me-1"></i> Scopus Link</label>
                                        <input type="url" name="scopus_link" class="form-control" value="<?php echo htmlspecialchars($facultyProfile['scopus_link'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small"><i class="bi bi-globe me-1"></i> Publon / Other Academic</label>
                                        <input type="url" name="publon_link" class="form-control" value="<?php echo htmlspecialchars($facultyProfile['publon_link'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold text-muted small"><i class="bi bi-whatsapp text-success me-1"></i> WhatsApp Link</label>
                                        <input type="url" name="whatsapp_link" class="form-control" value="<?php echo htmlspecialchars($facultyProfile['whatsapp_link'] ?? ''); ?>">
                                    </div>
                                </div>
                                
                                <hr class="my-4">
                                <button type="submit" class="btn btn-primary px-5 rounded-3 shadow-sm">Save Profile Data</button>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Document Upload Cropper Modal -->
    <div class="modal fade" id="documentUploadModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header border-bottom-0 pb-0">
                    <h5 class="modal-title fw-bold" id="docModalTitle">Upload Document</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Document Name</label>
                        <input type="text" id="docInputName" class="form-control" placeholder="E.g., Aadhaar Card, B.Tech Marksheet" required>
                    </div>
                    <div class="mb-3">
                        <input type="file" class="form-control" id="docImageInput" accept="image/png, image/jpeg, image/gif">
                        <div class="form-text">Select an image. You can crop it below to frame the document perfectly.</div>
                    </div>
                    
                    <div class="cropper-container-wrapper rounded-3 shadow-sm d-none" id="docCropperWrapper">
                        <img id="docCropperImage" src="" style="max-width: 100%;">
                    </div>
                </div>
                <div class="modal-footer border-top-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary px-4" id="btnSaveDocument" disabled>Crop & Upload</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Profile Photo Avatar Modal -->
    <div class="modal fade" id="photoModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold">Update Profile Photo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="file" class="form-control mb-3" id="avatarImageInput" accept="image/png, image/jpeg">
                    <div class="cropper-container-wrapper rounded-3 shadow-sm d-none" id="avatarCropperWrapper" style="max-height: 400px;">
                        <img id="avatarCropperImage" src="" style="max-width: 100%;">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary w-100" id="btnSaveAvatar" disabled>Save Photo</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    
    <script>
        // Document Upload Logic
        let docCropper = null;
        let currentVisibility = 'private';
        
        function openDocModal(visibility) {
            currentVisibility = visibility;
            document.getElementById('docModalTitle').innerText = visibility === 'private' ? 'Upload Private Document' : 'Upload Public Document';
            document.getElementById('docInputName').value = '';
            document.getElementById('docImageInput').value = '';
            document.getElementById('docCropperWrapper').classList.add('d-none');
            document.getElementById('btnSaveDocument').disabled = true;
            if(docCropper) { docCropper.destroy(); docCropper = null; }
            
            new bootstrap.Modal(document.getElementById('documentUploadModal')).show();
        }

        document.getElementById('docImageInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = document.getElementById('docCropperImage');
                    img.src = event.target.result;
                    document.getElementById('docCropperWrapper').classList.remove('d-none');
                    document.getElementById('btnSaveDocument').disabled = false;
                    
                    if(docCropper) docCropper.destroy();
                    docCropper = new Cropper(img, {
                        viewMode: 2,
                        autoCropArea: 1,
                        background: false
                    });
                };
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('btnSaveDocument').addEventListener('click', function() {
            const name = document.getElementById('docInputName').value.trim();
            if(!name) { alert('Please enter a document name'); return; }
            if(!docCropper) return;
            
            this.disabled = true;
            this.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Uploading...';
            
            const canvas = docCropper.getCroppedCanvas();
            const base64Image = canvas.toDataURL('image/png');
            
            $.ajax({
                url: '<?php echo BASE_URL; ?>/faculty/profile',
                type: 'POST',
                data: {
                    action: 'upload_document',
                    document_name: name,
                    visibility: currentVisibility,
                    document_image: base64Image
                },
                success: function(response) {
                    try {
                        const res = JSON.parse(response);
                        if(res.success) {
                            window.location.reload();
                        } else {
                            alert(res.error || 'Upload failed');
                            document.getElementById('btnSaveDocument').disabled = false;
                            document.getElementById('btnSaveDocument').innerText = 'Crop & Upload';
                        }
                    } catch(e) {
                        window.location.reload(); // Fallback
                    }
                },
                error: function() {
                    alert('Server error occurred.');
                    document.getElementById('btnSaveDocument').disabled = false;
                    document.getElementById('btnSaveDocument').innerText = 'Crop & Upload';
                }
            });
        });

        // Avatar Upload Logic
        let avatarCropper = null;
        document.getElementById('avatarImageInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const img = document.getElementById('avatarCropperImage');
                    img.src = event.target.result;
                    document.getElementById('avatarCropperWrapper').classList.remove('d-none');
                    document.getElementById('btnSaveAvatar').disabled = false;
                    
                    if(avatarCropper) avatarCropper.destroy();
                    avatarCropper = new Cropper(img, {
                        aspectRatio: 1,
                        viewMode: 1
                    });
                };
                reader.readAsDataURL(file);
            }
        });

        document.getElementById('btnSaveAvatar').addEventListener('click', function() {
            if(!avatarCropper) return;
            this.disabled = true;
            this.innerHTML = 'Saving...';
            const canvas = avatarCropper.getCroppedCanvas({ width: 400, height: 400 });
            
            $.ajax({
                url: '<?php echo BASE_URL; ?>/faculty/profile',
                type: 'POST',
                data: {
                    action: 'upload_cropped_photo',
                    cropped_image: canvas.toDataURL('image/png')
                },
                success: function(response) {
                    window.location.reload();
                }
            });
        });
    </script>
</body>
</html>
