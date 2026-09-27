<?php
// Fix session warning
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    header("HTTP/1.0 404 Not Found");
    echo "Profile not found.";
    exit();
}

require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../models/FacultyProfile.php';
require_once __DIR__ . '/../../includes/functions.php';

$db = getDB();
$profileModel = new FacultyProfile();
$userId = $profileModel->getUserIdBySlug($slug);

if (!$userId) {
    header("HTTP/1.0 404 Not Found");
    ?><!DOCTYPE html>
    <html><head><title>Profile Not Found</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#F8FAFC;margin:0;}
    .not-found{text-align:center;padding:40px;}.not-found h1{font-size:3rem;color:#0F172A;margin-bottom:12px;}
    .not-found p{color:#64748B;margin-bottom:24px;}.not-found a{background:#4F46E5;color:white;padding:12px 24px;border-radius:8px;text-decoration:none;}</style>
    </head><body><div class="not-found"><h1>404</h1><p>Faculty profile not found.</p><a href="<?php echo BASE_URL; ?>/">Go Back</a></div></body></html>
    <?php
    exit();
}

$userModel = new User();
$user = $userModel->getUserById($userId);
$facultyProfile = $profileModel->getProfile($userId);
$educationList = $profileModel->getEducation($userId);
$awardsList = $profileModel->getAwards($userId);
$publicationsList = $profileModel->getPublications($userId);
$fdpList = $profileModel->getFDPs($userId);
$galleryList = $profileModel->getGallery($userId);
$galleryCount = $profileModel->getGalleryCount($userId);

$isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['Admin', 'Principal']);
$documents = $profileModel->getDocuments($userId);
$publicDocs = array_filter($documents, fn($d) => $d['visibility'] === 'public');
$privateDocs = array_filter($documents, fn($d) => $d['visibility'] === 'private');

// Fetch Theme Settings
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$theme = [
    'theme_primary'   => '#4F46E5',
    'theme_secondary' => '#4338CA',
    'theme_accent'    => '#10B981'
];
foreach ($settings_rows as $row) {
    if (array_key_exists($row['setting_key'], $theme)) {
        $theme[$row['setting_key']] = $row['setting_value'];
    }
}

$fullName    = htmlspecialchars($user['full_name']);
$designation = htmlspecialchars($user['designation'] ?? 'Faculty');
$employeeId  = htmlspecialchars($user['employee_id'] ?? '');
$email       = htmlspecialchars($user['email'] ?? '');
$mobile      = htmlspecialchars($user['mobile'] ?? '');
$photoPath   = !empty($user['profile_photo'])
    ? BASE_URL . '/assets/uploads/profiles/' . $user['profile_photo']
    : 'https://ui-avatars.com/api/?name=' . urlencode($user['full_name']) . '&size=400&background=4F46E5&color=fff&bold=true&font-size=0.4';

$bio = !empty($facultyProfile['bio'])
    ? htmlspecialchars($facultyProfile['bio'])
    : "A dedicated and passionate academic professional committed to excellence in education, research, and knowledge dissemination.";

// Count stats
$statsEdu   = count($educationList);
$statsPub   = count($publicationsList);
$statsAwards = count($awardsList);
$statsFdps  = count($fdpList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $fullName; ?> — <?php echo $designation; ?> | Faculty Profile</title>
    <meta name="description" content="Academic profile of <?php echo $fullName; ?>, <?php echo $designation; ?>. Explore education, research, publications, and professional background.">
    <meta property="og:title" content="<?php echo $fullName; ?> | Faculty Profile">
    <meta property="og:description" content="<?php echo $bio; ?>">
    <meta property="og:image" content="<?php echo $photoPath; ?>">

    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary:        <?php echo htmlspecialchars($theme['theme_primary']); ?>;
            --primary-dark:   <?php echo htmlspecialchars($theme['theme_secondary']); ?>;
            --accent:         <?php echo htmlspecialchars($theme['theme_accent']); ?>;
            --dark:           #0F172A;
            --dark-light:     #1E293B;
            --text-main:      #1E293B;
            --text-muted:     #64748B;
            --bg:             #F8FAFC;
            --card-bg:        #FFFFFF;
            --border:         #E2E8F0;
            --shadow-sm:      0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md:      0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg:      0 15px 40px rgba(0,0,0,0.10);
            --radius:         16px;
            --radius-sm:      10px;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg);
            color: var(--text-main);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }

        h1,h2,h3,h4,h5,h6 { font-family: 'Outfit', sans-serif; }

        /* ─── STICKY NAV ─────────────────────────────────── */
        .site-nav {
            position: sticky;
            top: 0;
            z-index: 1000;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            padding: 12px 0;
        }
        .site-nav .nav-inner {
            max-width: 1100px;
            margin: 0 auto;
            padding: 0 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .site-nav .brand {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .site-nav .brand .icon {
            background: var(--primary);
            color: white;
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        .site-nav .nav-links {
            display: flex;
            gap: 4px;
        }
        .site-nav .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            padding: 6px 14px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .site-nav .nav-links a:hover {
            background: rgba(79,70,229,0.08);
            color: var(--primary);
        }

        /* ─── HERO ───────────────────────────────────────── */
        .hero {
            background: linear-gradient(135deg, var(--dark) 0%, var(--dark-light) 60%, #1a1560 100%);
            padding: 70px 20px 80px;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute; inset: 0;
            background: radial-gradient(ellipse at 80% 50%, rgba(79,70,229,0.25) 0%, transparent 60%);
            pointer-events: none;
        }
        .hero::after {
            content: '';
            position: absolute; bottom: -2px; left: 0; right: 0;
            height: 60px;
            background: var(--bg);
            clip-path: ellipse(55% 100% at 50% 100%);
        }
        .hero-inner {
            max-width: 1100px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 50px;
            position: relative;
            z-index: 2;
        }
        .hero-photo-wrap {
            flex-shrink: 0;
            position: relative;
        }
        .hero-photo-ring {
            width: 200px; height: 200px;
            border-radius: 50%;
            padding: 4px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            box-shadow: 0 0 0 8px rgba(79,70,229,0.15), 0 20px 50px rgba(0,0,0,0.3);
        }
        .hero-photo {
            width: 100%; height: 100%;
            border-radius: 50%;
            object-fit: cover;
            background: #1e293b;
        }
        .hero-badge {
            position: absolute;
            bottom: 6px; right: 6px;
            background: var(--accent);
            color: white;
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(16,185,129,0.4);
        }
        .hero-content { flex: 1; color: white; }
        .hero-content .emp-badge {
            display: inline-block;
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 4px 14px;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.7);
            margin-bottom: 16px;
            letter-spacing: 0.5px;
        }
        .hero-content h1 {
            font-size: 2.6rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 8px;
            color: white;
        }
        .hero-content .designation {
            font-size: 1.1rem;
            color: rgba(255,255,255,0.7);
            margin-bottom: 20px;
        }
        .hero-content .bio-text {
            font-size: 0.975rem;
            line-height: 1.75;
            color: rgba(255,255,255,0.65);
            max-width: 600px;
            margin-bottom: 24px;
        }
        .hero-contact-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .hero-contact-row .contact-chip {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.7);
            font-size: 0.85rem;
        }
        .hero-contact-row .contact-chip i {
            color: var(--accent);
            font-size: 1rem;
        }
        .social-row { display: flex; gap: 10px; flex-wrap: wrap; }
        .social-btn {
            width: 42px; height: 42px;
            border-radius: 10px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: rgba(255,255,255,0.8);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            text-decoration: none;
            transition: all 0.25s;
        }
        .social-btn:hover { background: var(--primary); border-color: var(--primary); color: white; transform: translateY(-3px); }
        .social-btn.whatsapp:hover { background: #25D366; border-color: #25D366; }
        .social-btn.facebook:hover { background: #1877F2; border-color: #1877F2; }
        .social-btn.youtube:hover  { background: #FF0000; border-color: #FF0000; }
        .social-btn.scholar:hover  { background: #4285F4; border-color: #4285F4; }

        /* ─── STATS ROW ──────────────────────────────────── */
        .stats-row {
            max-width: 1100px;
            margin: -30px auto 0;
            padding: 0 20px;
            position: relative;
            z-index: 10;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .stat-card {
            background: white;
            border-radius: var(--radius);
            padding: 20px 24px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            text-align: center;
            transition: all 0.25s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 50px rgba(0,0,0,0.12); }
        .stat-number {
            font-family: 'Outfit', sans-serif;
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1;
            margin-bottom: 4px;
        }
        .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            margin: 0 auto 12px;
        }

        /* ─── MAIN CONTENT ───────────────────────────────── */
        .main-content {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px 60px;
        }

        /* ─── SECTION CARDS ──────────────────────────────── */
        .section-card {
            background: var(--card-bg);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            margin-bottom: 28px;
            overflow: hidden;
            transition: box-shadow 0.3s;
        }
        .section-card:hover { box-shadow: var(--shadow-md); }
        .section-header {
            padding: 22px 28px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .section-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .section-header h2 {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--dark);
            margin: 0;
        }
        .section-header p {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin: 2px 0 0;
        }
        .section-body { padding: 24px 28px; }

        /* ─── TIMELINE ───────────────────────────────────── */
        .timeline { position: relative; padding-left: 32px; }
        .timeline::before {
            content: '';
            position: absolute; left: 10px; top: 8px; bottom: 8px;
            width: 2px;
            background: linear-gradient(to bottom, var(--primary), transparent);
            border-radius: 2px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 28px;
        }
        .timeline-item:last-child { margin-bottom: 0; }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -26px; top: 6px;
            width: 12px; height: 12px;
            border-radius: 50%;
            background: var(--primary);
            box-shadow: 0 0 0 4px rgba(79,70,229,0.15);
        }
        .timeline-title {
            font-weight: 700;
            font-size: 1rem;
            color: var(--dark);
            margin-bottom: 4px;
        }
        .timeline-sub {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 6px;
        }
        .tag-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 12px;
            border-radius: 20px;
            background: rgba(79,70,229,0.08);
            color: var(--primary);
            font-size: 0.78rem;
            font-weight: 600;
            border: 1px solid rgba(79,70,229,0.15);
        }

        /* ─── EMPTY STATE ────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        .empty-state i {
            font-size: 2.5rem;
            opacity: 0.3;
            display: block;
            margin-bottom: 10px;
        }
        .empty-state p {
            font-size: 0.95rem;
            margin: 0;
        }

        /* ─── DOCUMENTS GRID ─────────────────────────────── */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 16px;
        }
        .doc-card {
            border-radius: var(--radius-sm);
            overflow: hidden;
            border: 1px solid var(--border);
            background: #F8FAFC;
            transition: all 0.25s;
            cursor: pointer;
        }
        .doc-card:hover {
            border-color: var(--primary);
            box-shadow: 0 8px 24px rgba(79,70,229,0.12);
            transform: translateY(-3px);
        }
        .doc-thumb {
            height: 120px;
            overflow: hidden;
            background: #E2E8F0;
            display: flex; align-items: center; justify-content: center;
            position: relative;
        }
        .doc-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .doc-thumb .doc-hover {
            position: absolute; inset: 0;
            background: rgba(79,70,229,0.7);
            display: flex; align-items: center; justify-content: center;
            opacity: 0;
            transition: 0.25s;
            color: white;
            font-size: 1.5rem;
        }
        .doc-card:hover .doc-hover { opacity: 1; }
        .doc-name {
            padding: 10px 12px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--dark);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* ─── GALLERY GRID ───────────────────────────────── */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        .gallery-item {
            border-radius: var(--radius-sm);
            overflow: hidden;
            cursor: pointer;
            position: relative;
            aspect-ratio: 1;
        }
        .gallery-item img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.4s;
        }
        .gallery-item:hover img { transform: scale(1.08); }
        .gallery-caption {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: linear-gradient(transparent, rgba(0,0,0,0.7));
            color: white;
            font-size: 0.78rem;
            padding: 24px 10px 10px;
            transform: translateY(100%);
            transition: 0.3s;
        }
        .gallery-item:hover .gallery-caption { transform: translateY(0); }

        /* ─── ADMIN BADGE ────────────────────────────────── */
        .admin-section-header {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 14px 20px;
            background: #FEF2F2;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            border: 1px solid #FECACA;
        }
        .admin-section-header span { color: #991B1B; font-weight: 600; font-size: 0.9rem; }

        /* ─── LIGHTBOX ───────────────────────────────────── */
        .lightbox-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.92);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .lightbox-overlay.active { display: flex; }
        .lightbox-close {
            position: absolute;
            top: 20px; right: 24px;
            color: white; font-size: 2rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
            background: none; border: none;
        }
        .lightbox-close:hover { opacity: 1; }
        .lightbox-img {
            max-width: 90vw;
            max-height: 90vh;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 30px 80px rgba(0,0,0,0.5);
        }
        .lightbox-cap {
            position: absolute;
            bottom: 24px; left: 50%;
            transform: translateX(-50%);
            color: rgba(255,255,255,0.85);
            font-size: 0.95rem;
            background: rgba(0,0,0,0.4);
            padding: 8px 20px;
            border-radius: 20px;
        }

        /* ─── FOOTER ─────────────────────────────────────── */
        .site-footer {
            background: var(--dark);
            color: rgba(255,255,255,0.5);
            text-align: center;
            padding: 24px;
            font-size: 0.85rem;
        }

        /* ─── RESPONSIVE ─────────────────────────────────── */
        @media(max-width: 768px) {
            .hero-inner { flex-direction: column; text-align: center; gap: 30px; }
            .hero-content h1 { font-size: 1.9rem; }
            .hero-content .bio-text { display: none; }
            .hero-contact-row { justify-content: center; }
            .social-row { justify-content: center; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media(max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
            .hero-photo-ring { width: 150px; height: 150px; }
        }

        /* ─── ANIMATIONS ─────────────────────────────────── */
        .fade-up {
            opacity: 0;
            transform: translateY(30px);
            animation: fadeUp 0.6s ease forwards;
        }
        @keyframes fadeUp {
            to { opacity: 1; transform: translateY(0); }
        }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }
        .delay-4 { animation-delay: 0.4s; }
    </style>
</head>
<body>

    <!-- ─── STICKY NAV ──────────────────────────────────── -->
    <nav class="site-nav">
        <div class="nav-inner">
            <a href="<?php echo BASE_URL; ?>/" class="brand">
                <div class="icon"><i class="bi bi-mortarboard-fill" style="font-size:1rem;"></i></div>
                Appraisal System
            </a>
            <div class="nav-links d-none d-md-flex">
                <a href="#education"><i class="bi bi-mortarboard me-1"></i>Education</a>
                <a href="#publications"><i class="bi bi-journal-bookmark me-1"></i>Research</a>
                <a href="#awards"><i class="bi bi-trophy me-1"></i>Awards</a>
                <a href="#fdp"><i class="bi bi-easel me-1"></i>FDP</a>
                <?php if ($galleryCount >= 4): ?>
                <a href="#gallery"><i class="bi bi-images me-1"></i>Gallery</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- ─── HERO ────────────────────────────────────────── -->
    <section class="hero">
        <div class="hero-inner">
            <div class="hero-photo-wrap fade-up">
                <div class="hero-photo-ring">
                    <img src="<?php echo $photoPath; ?>" alt="<?php echo $fullName; ?>" class="hero-photo">
                </div>
                <span class="hero-badge"><i class="bi bi-patch-check-fill me-1"></i>Faculty</span>
            </div>
            <div class="hero-content fade-up delay-1">
                <?php if (!empty($employeeId)): ?>
                <div class="emp-badge"><i class="bi bi-person-badge me-1"></i><?php echo $employeeId; ?></div>
                <?php endif; ?>
                <h1><?php echo $fullName; ?></h1>
                <div class="designation"><i class="bi bi-briefcase me-2" style="color: var(--accent);"></i><?php echo $designation; ?></div>
                <p class="bio-text"><?php echo $bio; ?></p>
                <div class="hero-contact-row">
                    <?php if(!empty($email)): ?>
                    <div class="contact-chip"><i class="bi bi-envelope-fill"></i><?php echo $email; ?></div>
                    <?php endif; ?>
                    <?php if(!empty($mobile)): ?>
                    <div class="contact-chip"><i class="bi bi-telephone-fill"></i><?php echo $mobile; ?></div>
                    <?php endif; ?>
                </div>
                <!-- Social Links -->
                <div class="social-row">
                    <?php if(!empty($facultyProfile['whatsapp_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['whatsapp_link']); ?>" target="_blank" class="social-btn whatsapp" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['facebook_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['facebook_link']); ?>" target="_blank" class="social-btn facebook" title="Facebook"><i class="bi bi-facebook"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['youtube_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['youtube_link']); ?>" target="_blank" class="social-btn youtube" title="YouTube"><i class="bi bi-youtube"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['google_scholar_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['google_scholar_link']); ?>" target="_blank" class="social-btn scholar" title="Google Scholar"><i class="bi bi-google"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['scopus_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['scopus_link']); ?>" target="_blank" class="social-btn" title="Scopus"><i class="bi bi-book-half"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['publon_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['publon_link']); ?>" target="_blank" class="social-btn" title="Publon"><i class="bi bi-award"></i></a>
                    <?php endif; ?>
                    <?php if(!empty($facultyProfile['other_link'])): ?>
                    <a href="<?php echo htmlspecialchars($facultyProfile['other_link']); ?>" target="_blank" class="social-btn" title="Research Profile"><i class="bi bi-link-45deg"></i></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── STATS ROW ─────────────────────────────────── -->
    <div class="stats-row fade-up delay-2">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#EEF2FF; color: var(--primary);"><i class="bi bi-mortarboard-fill"></i></div>
                <div class="stat-number"><?php echo $statsEdu; ?></div>
                <div class="stat-label">Qualifications</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ECFDF5; color: #059669;"><i class="bi bi-journal-bookmark-fill"></i></div>
                <div class="stat-number"><?php echo $statsPub; ?></div>
                <div class="stat-label">Publications</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#FFF7ED; color: #D97706;"><i class="bi bi-trophy-fill"></i></div>
                <div class="stat-number"><?php echo $statsAwards; ?></div>
                <div class="stat-label">Awards</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#F0F9FF; color: #0284C7;"><i class="bi bi-easel-fill"></i></div>
                <div class="stat-number"><?php echo $statsFdps; ?></div>
                <div class="stat-label">FDPs</div>
            </div>
        </div>
    </div>

    <!-- ─── MAIN CONTENT ─────────────────────────────── -->
    <div class="main-content">

        <!-- Public Documents -->
        <?php if (!empty($publicDocs) || $isAdmin): ?>
        <section id="docs" class="section-card fade-up delay-3">
            <div class="section-header">
                <div class="section-icon" style="background:#EFF6FF; color: #2563EB;">
                    <i class="bi bi-file-earmark-check-fill"></i>
                </div>
                <div>
                    <h2>Public Documents</h2>
                    <p>Certificates, FDPs, research documents and more</p>
                </div>
            </div>
            <div class="section-body">
                <?php if (!empty($publicDocs)): ?>
                <div class="doc-grid">
                    <?php foreach ($publicDocs as $doc): ?>
                    <div class="doc-card" onclick="openLightbox('<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>', '<?php echo htmlspecialchars(addslashes($doc['document_name'])); ?>')">
                        <div class="doc-thumb">
                            <img src="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" alt="<?php echo htmlspecialchars($doc['document_name']); ?>">
                            <div class="doc-hover"><i class="bi bi-zoom-in"></i></div>
                        </div>
                        <div class="doc-name"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-file-earmark-x"></i><p>No public documents uploaded yet.</p></div>
                <?php endif; ?>

                <?php if ($isAdmin && !empty($privateDocs)): ?>
                <div class="mt-4">
                    <div class="admin-section-header">
                        <i class="bi bi-shield-lock-fill text-danger"></i>
                        <span>Private Documents — Admin View Only</span>
                    </div>
                    <div class="doc-grid">
                        <?php foreach ($privateDocs as $doc): ?>
                        <div class="doc-card" onclick="openLightbox('<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>', '<?php echo htmlspecialchars(addslashes($doc['document_name'])); ?>')">
                            <div class="doc-thumb" style="background:#FEF2F2;">
                                <img src="<?php echo BASE_URL; ?>/assets/uploads/documents/<?php echo htmlspecialchars($doc['filename']); ?>" alt="<?php echo htmlspecialchars($doc['document_name']); ?>">
                                <div class="doc-hover" style="background:rgba(239,68,68,0.7);"><i class="bi bi-zoom-in"></i></div>
                            </div>
                            <div class="doc-name"><?php echo htmlspecialchars($doc['document_name']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Education -->
        <section id="education" class="section-card fade-up delay-3">
            <div class="section-header">
                <div class="section-icon" style="background:#EEF2FF; color: var(--primary);">
                    <i class="bi bi-mortarboard-fill"></i>
                </div>
                <div>
                    <h2>Academic Background</h2>
                    <p>Educational qualifications and degrees</p>
                </div>
            </div>
            <div class="section-body">
                <?php if (!empty($educationList)): ?>
                <div class="timeline">
                    <?php foreach($educationList as $edu): ?>
                    <div class="timeline-item">
                        <div class="timeline-title"><?php echo htmlspecialchars($edu['degree_name']); ?></div>
                        <div class="timeline-sub"><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($edu['institution']); ?></div>
                        <span class="tag-pill"><i class="bi bi-calendar3"></i>Class of <?php echo htmlspecialchars($edu['passing_year']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-mortarboard"></i><p>No education details provided yet.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Publications -->
        <section id="publications" class="section-card fade-up delay-3">
            <div class="section-header">
                <div class="section-icon" style="background:#ECFDF5; color: #059669;">
                    <i class="bi bi-journal-bookmark-fill"></i>
                </div>
                <div>
                    <h2>Research &amp; Publications</h2>
                    <p>Research papers, journals, and scholarly articles</p>
                </div>
            </div>
            <div class="section-body">
                <?php if (!empty($publicationsList)): ?>
                <div class="timeline">
                    <?php foreach($publicationsList as $pub): ?>
                    <div class="timeline-item">
                        <div class="timeline-title"><?php echo htmlspecialchars($pub['name']); ?></div>
                        <div class="timeline-sub"><i class="bi bi-journal-text me-1"></i>Published in: <?php echo htmlspecialchars($pub['published_in']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-journal-x"></i><p>No publications listed yet.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Awards -->
        <section id="awards" class="section-card fade-up delay-3">
            <div class="section-header">
                <div class="section-icon" style="background:#FFF7ED; color: #D97706;">
                    <i class="bi bi-trophy-fill"></i>
                </div>
                <div>
                    <h2>Honors &amp; Awards</h2>
                    <p>Recognitions and achievements</p>
                </div>
            </div>
            <div class="section-body">
                <?php if (!empty($awardsList)): ?>
                <div class="timeline">
                    <?php foreach($awardsList as $award): ?>
                    <div class="timeline-item">
                        <div class="timeline-title"><?php echo htmlspecialchars($award['title']); ?></div>
                        <div class="timeline-sub"><i class="bi bi-building me-1"></i>Honored by: <?php echo htmlspecialchars($award['honored_by']); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-trophy"></i><p>No awards listed yet.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- FDP & Conferences -->
        <section id="fdp" class="section-card fade-up delay-3">
            <div class="section-header">
                <div class="section-icon" style="background:#F0F9FF; color: #0284C7;">
                    <i class="bi bi-easel-fill"></i>
                </div>
                <div>
                    <h2>FDPs &amp; Conferences</h2>
                    <p>Faculty Development Programs and professional events</p>
                </div>
            </div>
            <div class="section-body">
                <?php if (!empty($fdpList)): ?>
                <div class="timeline">
                    <?php foreach($fdpList as $fdp): ?>
                    <div class="timeline-item">
                        <div class="timeline-title"><?php echo htmlspecialchars($fdp['title']); ?></div>
                        <div class="timeline-sub"><i class="bi bi-geo-alt me-1"></i>Venue: <?php echo htmlspecialchars($fdp['venue']); ?></div>
                        <div class="mt-2 d-flex gap-2 flex-wrap">
                            <span class="tag-pill"><i class="bi bi-clock"></i><?php echo htmlspecialchars($fdp['week_duration']); ?></span>
                            <span class="tag-pill" style="background:rgba(16,185,129,0.08);color:#059669;border-color:rgba(16,185,129,0.2);"><i class="bi bi-check-circle"></i>Approved by: <?php echo htmlspecialchars($fdp['approved_by']); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="empty-state"><i class="bi bi-easel"></i><p>No FDPs or Conferences listed yet.</p></div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Gallery -->
        <?php if ($galleryCount >= 4): ?>
        <section id="gallery" class="section-card fade-up delay-4">
            <div class="section-header">
                <div class="section-icon" style="background:#FDF4FF; color: #7C3AED;">
                    <i class="bi bi-images"></i>
                </div>
                <div>
                    <h2>Photo Gallery</h2>
                    <p>Academic and professional moments</p>
                </div>
            </div>
            <div class="section-body">
                <div class="gallery-grid">
                    <?php foreach($galleryList as $photo): ?>
                    <div class="gallery-item" onclick="openLightbox('<?php echo BASE_URL; ?>/assets/uploads/gallery/<?php echo htmlspecialchars($photo['filename']); ?>', '<?php echo htmlspecialchars(addslashes($photo['caption'] ?? '')); ?>')">
                        <img src="<?php echo BASE_URL; ?>/assets/uploads/gallery/<?php echo htmlspecialchars($photo['filename']); ?>" alt="<?php echo htmlspecialchars($photo['caption'] ?? ''); ?>">
                        <?php if (!empty($photo['caption'])): ?>
                        <div class="gallery-caption"><?php echo htmlspecialchars($photo['caption']); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php endif; ?>

    </div><!-- /main-content -->

    <!-- ─── FOOTER ─────────────────────────────────────── -->
    <footer class="site-footer">
        <p class="mb-0">Profile of <strong style="color:rgba(255,255,255,0.8);"><?php echo $fullName; ?></strong> · Appraisal System &copy; <?php echo date('Y'); ?></p>
    </footer>

    <!-- ─── LIGHTBOX ──────────────────────────────────── -->
    <div class="lightbox-overlay" id="lightbox" onclick="closeLightbox(event)">
        <button class="lightbox-close" onclick="closeLb()">×</button>
        <img id="lightboxImg" class="lightbox-img" src="" alt="">
        <div id="lightboxCap" class="lightbox-cap" style="display:none;"></div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openLightbox(src, caption) {
            document.getElementById('lightboxImg').src = src;
            const cap = document.getElementById('lightboxCap');
            if (caption) { cap.textContent = caption; cap.style.display = 'block'; }
            else { cap.style.display = 'none'; }
            document.getElementById('lightbox').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeLb() {
            document.getElementById('lightbox').classList.remove('active');
            document.body.style.overflow = '';
        }
        function closeLightbox(e) {
            if (e.target === document.getElementById('lightbox')) closeLb();
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLb(); });

        // Smooth scroll for nav links
        document.querySelectorAll('.nav-links a[href^="#"]').forEach(a => {
            a.addEventListener('click', e => {
                e.preventDefault();
                const el = document.querySelector(a.getAttribute('href'));
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        // Active nav on scroll
        const navLinks = document.querySelectorAll('.nav-links a');
        const sections = document.querySelectorAll('section[id]');
        window.addEventListener('scroll', () => {
            const scrollY = window.scrollY + 80;
            sections.forEach(sec => {
                if (scrollY >= sec.offsetTop && scrollY < sec.offsetTop + sec.offsetHeight) {
                    navLinks.forEach(l => l.style.background = '');
                    const link = document.querySelector(`.nav-links a[href="#${sec.id}"]`);
                    if (link) link.style.background = 'rgba(79,70,229,0.1)';
                }
            });
        }, { passive: true });
    </script>
</body>
</html>
