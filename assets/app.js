// Frontend helper functions – logout and confirmation
document.addEventListener('DOMContentLoaded', function() {
    // Logout button handler
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            window.location.href = 'logout.php';
        });
    }

    // Optional: confirm before deleting
    const deleteButtons = document.querySelectorAll('.delete-btn');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (!confirm('Are you sure?')) {
                e.preventDefault();
            }
        });
    });
});