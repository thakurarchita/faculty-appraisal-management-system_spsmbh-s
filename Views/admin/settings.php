<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

// Check if user is admin
if ($_SESSION['role'] !== 'Admin') {
    header('Location: ' . BASE_URL . '/index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/functions.php';

$db = getDB();

// Fetch current theme settings
$stmt = $db->query("SELECT setting_key, setting_value FROM settings");
$settings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$theme_settings = [
    'theme_primary'   => '#12304a',
    'theme_secondary' => '#0b2033',
    'theme_accent'    => '#d6a84f',
    'quick_test_login' => '1'          // default: enabled
];

foreach ($settings_rows as $row) {
    if (array_key_exists($row['setting_key'], $theme_settings)) {
        $theme_settings[$row['setting_key']] = $row['setting_value'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Settings - Faculty Appraisal System</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    
    <style>
        /* Global Styles */
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f6fa; overflow-x: hidden; }
        
        /* Sidebar Styles */
        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0; width: 280px; z-index: 1050;
            background: linear-gradient(180deg, #1a2332 0%, #2c3e50 100%) !important;
            transition: transform 0.3s ease-in-out; transform: translateX(-100%);
            overflow-y: auto; box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        @media (min-width: 992px) {
            .sidebar { transform: translateX(0); }
            .main-content { margin-left: 280px !important; }
            .navbar-toggle { display: none !important; }
        }
        .sidebar.show { transform: translateX(0); }
        .sidebar .nav-link {
            padding: 12px 20px; color: rgba(255,255,255,0.7) !important;
            border-radius: 8px; margin: 2px 10px; transition: all 0.3s ease; font-size: 0.95rem;
        }
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.05); color: #f1c40f !important; }
        .sidebar .nav-link.active { color: #f1c40f !important; background: rgba(241, 196, 15, 0.1); }
        .sidebar .nav-link i { margin-right: 12px; font-size: 1.2rem; width: 24px; text-align: center; }
        
        /* Main Content */
        .main-content { margin-left: 0; padding: 20px 15px; transition: margin-left 0.3s ease; width: 100%; min-height: 100vh; }
        
        /* Mobile Toggle Button */
        .navbar-toggle {
            display: block; position: fixed; top: 10px; left: 10px; z-index: 1060;
            background: #1a2332; border: none; color: #f1c40f; padding: 10px 14px;
            border-radius: 8px; font-size: 1.5rem; box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: all 0.3s ease;
        }
        .navbar-toggle:hover { background: #2c3e50; transform: scale(1.05); }
        .navbar-toggle:focus { outline: none; box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.3); }
        
        /* Overlay */
        .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1040; }
        .sidebar-overlay.active { display: block; }
        
        /* Card Styles */
        .card { border-radius: 12px; border: none; box-shadow: 0 2px 15px rgba(0,0,0,0.08); margin-bottom: 20px; overflow: hidden; }
        .card-header { border-radius: 12px 12px 0 0 !important; padding: 15px 20px; font-weight: 600; }
        .card-body { padding: 20px; }
        
        /* Color pickers styling */
        .color-input-wrapper { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .color-picker { width: 50px; height: 50px; padding: 0; border: none; border-radius: 8px; cursor: pointer; }
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
            <div class="text-center mb-4 px-3" style="color: #f1c40f;">
                <i class="bi bi-mortarboard-fill" style="font-size: 2.5rem;"></i>
                <h6 class="mt-2" style="color: white;">Appraisal System</h6>
                <small style="color: rgba(255,255,255,0.7);">Admin Panel</small>
            </div>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/users"><i class="bi bi-people"></i> Users</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/assign-task"><i class="bi bi-plus-circle"></i> Assign Task</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/my-tasks"><i class="bi bi-list-task"></i> My Tasks</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/task-status"><i class="bi bi-list-check"></i> Task Status</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/admin/templates"><i class="bi bi-file-earmark-text"></i> Task Templates</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="<?php echo BASE_URL; ?>/admin/settings"><i class="bi bi-gear"></i> Settings</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/principal/settings">
                        <i class="bi bi-sliders"></i> Perf. Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo BASE_URL; ?>/logout"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- Main content -->
    <main class="main-content" id="mainContent">
        <div class="container-fluid px-0">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center pt-2 pb-3 mb-3 border-bottom">
                <h1 class="h2 mb-0">System Settings</h1>
            </div>

            <!-- Settings Form -->
            <div class="row">
                <div class="col-md-8 col-lg-6">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <i class="bi bi-palette me-2"></i> Faculty Public Profile & Login Theme
                        </div>
                        <div class="card-body">
                            <form id="themeSettingsForm">
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Primary Color</label>
                                    <div class="color-input-wrapper">
                                        <input type="color" class="color-picker" id="theme_primary" name="theme_primary" value="<?php echo htmlspecialchars($theme_settings['theme_primary']); ?>">
                                        <input type="text" class="form-control text-uppercase" id="theme_primary_hex" value="<?php echo htmlspecialchars($theme_settings['theme_primary']); ?>" pattern="^#+([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$" placeholder="#0d6efd">
                                    </div>
                                    <div class="form-text">Used for primary buttons, headers, and active links.</div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Secondary Color</label>
                                    <div class="color-input-wrapper">
                                        <input type="color" class="color-picker" id="theme_secondary" name="theme_secondary" value="<?php echo htmlspecialchars($theme_settings['theme_secondary']); ?>">
                                        <input type="text" class="form-control text-uppercase" id="theme_secondary_hex" value="<?php echo htmlspecialchars($theme_settings['theme_secondary']); ?>" pattern="^#+([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$" placeholder="#6c757d">
                                    </div>
                                    <div class="form-text">Used for secondary text, backgrounds, or subtle borders.</div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Accent Color</label>
                                    <div class="color-input-wrapper">
                                        <input type="color" class="color-picker" id="theme_accent" name="theme_accent" value="<?php echo htmlspecialchars($theme_settings['theme_accent']); ?>">
                                        <input type="text" class="form-control text-uppercase" id="theme_accent_hex" value="<?php echo htmlspecialchars($theme_settings['theme_accent']); ?>" pattern="^#+([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$" placeholder="#f59f00">
                                    </div>
                                    <div class="form-text">Used for highlights, warnings, and special badges.</div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary" id="saveThemeBtn">
                                    <i class="bi bi-save me-1"></i> Save Theme Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div><!-- /col theme -->
            </div><!-- /row -->

            <!-- Developer / Login Settings -->
            <div class="row mt-4">
                <div class="col-md-8 col-lg-6">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header text-white" style="background: #212529;">
                            <i class="bi bi-lightning-charge-fill me-2"></i> Developer / Login Settings
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center py-2">
                                <div>
                                    <div class="fw-bold">Quick Test Login (1-Click Fill)</div>
                                    <div class="text-muted" style="font-size: 0.85rem;">
                                        Show one-click role fill buttons on the login page.<br>
                                        <span class="text-danger fw-semibold">Disable this in production.</span>
                                    </div>
                                </div>
                                <div class="form-check form-switch ms-4 flex-shrink-0">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           id="quick_test_login" name="quick_test_login"
                                           style="width: 3em; height: 1.5em; cursor: pointer;"
                                           <?php echo ($theme_settings['quick_test_login'] === '1') ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="mt-3">
                                <button type="button" class="btn btn-dark px-4" id="saveLoginSettingBtn">
                                    <i class="bi bi-save me-1"></i> Save Setting
                                </button>
                                <span id="loginSettingStatus" class="ms-3 small text-muted"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

    </main>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            // Sidebar Toggle Logic
            const sidebar = document.getElementById('sidebar');
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebarOverlay = document.getElementById('sidebarOverlay');
            
            function toggleSidebar() {
                sidebar.classList.toggle('show');
                sidebarOverlay.classList.toggle('active');
            }
            
            sidebarToggle.addEventListener('click', toggleSidebar);
            sidebarOverlay.addEventListener('click', toggleSidebar);
            
            // Sync color picker and hex text input
            function syncColors(pickerId, hexId) {
                $(pickerId).on('input', function() {
                    $(hexId).val($(this).val());
                });
                $(hexId).on('input', function() {
                    let val = $(this).val();
                    if(!val.startsWith('#')) val = '#' + val;
                    if(/^#[0-9A-F]{6}$/i.test(val)) {
                        $(pickerId).val(val);
                    }
                });
            }
            
            syncColors('#theme_primary', '#theme_primary_hex');
            syncColors('#theme_secondary', '#theme_secondary_hex');
            syncColors('#theme_accent', '#theme_accent_hex');

            // Save Quick Test Login setting
            $('#saveLoginSettingBtn').on('click', function() {
                const btn = $(this);
                const originalText = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status"></span> Saving...');

                $.ajax({
                    url: '<?php echo BASE_URL; ?>/api/settings',
                    type: 'POST',
                    data: {
                        quick_test_login: $('#quick_test_login').is(':checked') ? '1' : '0'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Login setting updated.',
                                timer: 1800,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'Failed to update' });
                        }
                    },
                    error: function() {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Server error occurred.' });
                    },
                    complete: function() { btn.prop('disabled', false).html(originalText); }
                });
            });

            // Save theme settings via AJAX
            $('#themeSettingsForm').on('submit', function(e) {
                e.preventDefault();
                
                const data = {
                    theme_primary: $('#theme_primary').val(),
                    theme_secondary: $('#theme_secondary').val(),
                    theme_accent: $('#theme_accent').val(),
                };
                
                const btn = $('#saveThemeBtn');
                const originalText = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Saving...');
                
                $.ajax({
                    url: '<?php echo BASE_URL; ?>/api/settings',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: 'Theme settings updated successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'Failed to update settings'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'A server error occurred. Please try again.'
                        });
                    },
                    complete: function() {
                        btn.prop('disabled', false).html(originalText);
                    }
                });
            });
        });
    </script>
</body>
</html>
