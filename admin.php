<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole('Admin');

$user = getCurrentUser();

// Handle staff creation and password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_staff') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $full_name = $_POST['full_name'] ?? '';
        $email = $_POST['email'] ?? '';
        $role_name = $_POST['role'] ?? '';

        $valid_roles = ['Doctor', 'Nurse', 'Receptionist'];
        if (!in_array($role_name, $valid_roles)) {
            $error = 'Invalid role';
        } else {
            $role_stmt = $conn->prepare("SELECT id FROM roles WHERE role_name = ?");
            $role_stmt->bind_param("s", $role_name);
            $role_stmt->execute();
            $role_res = $role_stmt->get_result();
            if ($role_row = $role_res->fetch_assoc()) {
                $role_id = $role_row['id'];
            } else {
                $error = 'Role not found';
            }

            if (!isset($error)) {
                $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
                $check->bind_param("ss", $username, $email);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $error = 'Username or email already exists';
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role_id, full_name, email) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssiss", $username, $password_hash, $role_id, $full_name, $email);
                    if ($stmt->execute()) {
                        log_audit('CREATE_STAFF', "Created staff account: $username with role $role_name");
                        $message = "Staff account created successfully.";
                    } else {
                        $error = "Failed to create staff account.";
                    }
                }
            }
        }
    } elseif ($_POST['action'] === 'reset_password') {
        $user_id = $_POST['user_id'] ?? 0;
        $new_password = $_POST['new_password'] ?? '';
        if ($user_id && $new_password) {
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->bind_param("si", $password_hash, $user_id);
            if ($stmt->execute()) {
                log_audit('RESET_PASSWORD', "Reset password for user ID: $user_id");
                $message = "Password reset successful.";
            } else {
                $error = "Failed to reset password.";
            }
        } else {
            $error = "Missing user ID or new password.";
        }
    }
}

// Fetch all users for management
$users = $conn->query("SELECT u.id, u.username, u.full_name, u.email, r.role_name 
                       FROM users u JOIN roles r ON u.role_id = r.id ORDER BY u.id");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        
        /* Additional style for vertical scroll on the user table */
        .manage-users-table {
            max-height: 400px;        /* Adjust as needed */
            overflow-y: auto;
            overflow-x: auto;         /* Horizontal scroll if table is wide */
            margin-top: 15px;
        }
        .manage-users-table table {
            width: 100%;
            min-width: 600px;         /* Ensures table doesn't shrink too much */
        }
        /* Optional: Ensure the card itself doesn't stretch */
        .card {
            display: flex;
            flex-direction: column;
        }
        .card h3 {
            flex-shrink: 0;
        }
        .reset-form {
            display: flex;
            gap: 5px;
            align-items: center;
        }
        .reset-form input[type="password"] {
            width: 120px;
            padding: 5px;
        }
        .reset-form button {
            padding: 5px 10px;
        }
        /* Search bar styling */
        .user-search {
            margin-bottom: 15px;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .user-search input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: 'Poppins', sans-serif;
        }
        .user-search button {
            padding: 8px 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Admin Dashboard</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars($user['full_name']); ?> (Admin)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <?php if (isset($message)): ?><div class="message success"><?php echo $message; ?></div><?php endif; ?>
            <?php if (isset($error)): ?><div class="message error"><?php echo $error; ?></div><?php endif; ?>

            <div class="card-grid">

                <!-- Create Staff Account -->
                <div class="card">
                    <h3>Create Staff Account</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="create_staff">
                        <div class="form-group">
                            <label>Username:</label>
                            <input type="text" name="username" required>
                        </div>
                        <div class="form-group">
                            <label>Password:</label>
                            <input type="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label>Full Name:</label>
                            <input type="text" name="full_name" required>
                        </div>
                        <div class="form-group">
                            <label>Email:</label>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label>Role:</label>
                            <select name="role" required>
                                <option value="">Select Role</option>
                                <option value="Doctor">Doctor</option>
                                <option value="Nurse">Nurse</option>
                                <option value="Receptionist">Receptionist</option>
                            </select>
                        </div>
                        <button type="submit" class="btn">Create Staff</button>
                    </form>
                </div>

                <!-- Manage Users / Reset Passwords -->
                <div class="card">
                    <h3>Manage Users / Reset Passwords</h3>
                    <!-- Search bar -->
                    <div class="user-search">
                        <input type="text" id="searchInput" placeholder="Search by username, name, email, or role...">
                        <button type="button" id="clearSearch" class="btn btn-secondary">Clear</button>
                    </div>
                    <div class="manage-users-table">
                        <table id="userTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Full Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($u = $users->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $u['id']; ?></td>
                                    <td><?php echo htmlspecialchars($u['username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><?php echo $u['role_name']; ?></td>
                                    <td>
                                        <form method="POST" class="reset-form">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                            <input type="password" name="new_password" placeholder="New password" required>
                                            <button type="submit" class="btn">Reset</button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Audit Logs -->
                <div class="card">
                    <h3>Audit Logs</h3>
                    <p><a href="audit-log.php" class="btn">View Full Audit Log</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Live search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const clearButton = document.getElementById('clearSearch');
            const table = document.getElementById('userTable');
            const rows = table.querySelectorAll('tbody tr');

            function filterTable() {
                const searchTerm = searchInput.value.toLowerCase().trim();
                rows.forEach(row => {
                    // Collect text from relevant columns (skip the Action column)
                    const cells = row.querySelectorAll('td');
                    if (cells.length >= 5) {
                        const idText = cells[0].textContent.toLowerCase();
                        const usernameText = cells[1].textContent.toLowerCase();
                        const nameText = cells[2].textContent.toLowerCase();
                        const emailText = cells[3].textContent.toLowerCase();
                        const roleText = cells[4].textContent.toLowerCase();
                        const match = idText.includes(searchTerm) ||
                                     usernameText.includes(searchTerm) ||
                                     nameText.includes(searchTerm) ||
                                     emailText.includes(searchTerm) ||
                                     roleText.includes(searchTerm);
                        row.style.display = match ? '' : 'none';
                    } else {
                        row.style.display = ''; // fallback
                    }
                });
            }

            searchInput.addEventListener('input', filterTable);
            clearButton.addEventListener('click', function() {
                searchInput.value = '';
                filterTable();
            });
        });
    </script>
    <script src="assets/app.js"></script>
</body>
</html>