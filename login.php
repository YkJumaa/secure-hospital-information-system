<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'auth.php';
require_once 'audit.php';

// If already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    $user = getCurrentUser();
    $role = $user['role_name'];
    $dashboard_map = [
        'Admin'        => 'admin.php',
        'Doctor'       => 'doctor.php',
        'Nurse'        => 'nurse.php',
        'Receptionist' => 'receptionist.php',
        'Patient'      => 'patient.php'
    ];
    header('Location: ' . $dashboard_map[$role]);
    exit;
}

$error = '';

// Check for timeout or unauthorized errors in URL
if (isset($_GET['error']) && $_GET['error'] === 'timeout') {
    $error = 'Your session expired due to inactivity. Please login again.';
} elseif (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $error = 'You are not authorized to access that page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        // Fetch user by username
        $stmt = $conn->prepare("SELECT id, username, password_hash, role_id FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password_hash'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role_id'] = $user['role_id'];
                $_SESSION['last_activity'] = time();

                // Update last login
                $update = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $update->bind_param("i", $user['id']);
                $update->execute();

                // Get role name
                $role_stmt = $conn->prepare("SELECT role_name FROM roles WHERE id = ?");
                $role_stmt->bind_param("i", $user['role_id']);
                $role_stmt->execute();
                $role_res = $role_stmt->get_result();
                $role_row = $role_res->fetch_assoc();

                if (!$role_row) {
                    error_log("Missing role for user ID: " . $user['id']);
                    session_destroy();
                    $error = "Authentication error. Please contact support.";
                } else {
                    $role_name = $role_row['role_name'];
                    log_audit('LOGIN', "User $username logged in as $role_name");

                    // Redirect based on role_id
                    $dashboard_map = [
                        1 => 'admin.php',
                        2 => 'doctor.php',
                        3 => 'nurse.php',
                        4 => 'receptionist.php',
                        5 => 'patient.php'
                    ];
                    header('Location: ' . $dashboard_map[$user['role_id']]);
                    exit;
                }
            } else {
                $error = 'Invalid password';
            }
        } else {
            $error = 'User not found';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital Login</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="container" style="max-width: 400px;">
        <div style="padding: 40px;">
            <h1 style="text-align: center; margin-bottom: 30px; color: #2c3e50;">Hospital Management System</h1>
            <?php if ($error): ?>
                <div class="message error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Login</button>
            </form>
            <p style="text-align: center; margin-top: 20px;">
                Registration is handled by the reception desk
            </p>
        </div>
    </div>
    <script src="assets/app.js"></script>
</body>
</html>