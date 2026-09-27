// Dashboard specific JavaScript

// Chart initialization for dashboard
function initDashboardCharts() {
    // Common chart options
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    color: '#6c757d'
                }
            }
        }
    };
}

// Live notifications polling
function pollNotifications(userId) {
    setInterval(function() {
        $.get('controllers/NotificationController.php?action=get_unread_count&user_id=' + userId, function(data) {
            if (data.count > 0) {
                $('.notification-badge').text(data.count).show();
            } else {
                $('.notification-badge').hide();
            }
        });
    }, 30000); // Poll every 30 seconds
}

// File upload preview
function previewFile(input, previewId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#' + previewId).attr('src', e.target.result).show();
        };
        reader.readAsDataURL(input.files[0]);
    }
}