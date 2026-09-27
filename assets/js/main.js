// Main JavaScript file

// Initialize DataTables
$(document).ready(function() {
    if ($.fn.dataTable) {
        $('.datatable').DataTable({
            responsive: true,
            pageLength: 25,
            language: {
                search: "_INPUT_",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries"
            }
        });
    }
});

// CSRF Protection
function getCsrfToken() {
    return $('meta[name="csrf-token"]').attr('content');
}

// AJAX Setup
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': getCsrfToken()
    }
});

// Notification System
function markNotificationRead(notificationId) {
    $.post('controllers/NotificationController.php', {
        action: 'mark_read',
        notification_id: notificationId
    }, function(response) {
        if (response.success) {
            location.reload();
        }
    });
}

// Toast notifications
function showToast(message, type = 'success') {
    const colors = {
        success: '#28a745',
        error: '#dc3545',
        warning: '#ffc107',
        info: '#17a2b8'
    };
    
    Toastify({
        text: message,
        duration: 3000,
        close: true,
        style: {
            background: colors[type] || '#333'
        }
    }).showToast();
}