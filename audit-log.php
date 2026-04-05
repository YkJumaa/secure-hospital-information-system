<?php
require_once 'session.php';
require_once 'auth.php';
require_once 'audit.php';
requireRole('Admin');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$logs = $conn->prepare("SELECT al.*, u.username, r.role_name 
                        FROM audit_logs al 
                        LEFT JOIN users u ON al.user_id = u.id 
                        LEFT JOIN roles r ON al.role_id = r.id 
                        ORDER BY al.logged_at DESC 
                        LIMIT ? OFFSET ?");
$logs->bind_param("ii", $limit, $offset);
$logs->execute();
$logs_result = $logs->get_result();

$total = $conn->query("SELECT COUNT(*) as count FROM audit_logs")->fetch_assoc()['count'];
$total_pages = ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log Viewer</title>
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
    <div class="container">
        <div class="dashboard-header">
            <h1>Audit Log</h1>
            <div class="user-info">
                <span><?php echo htmlspecialchars(getCurrentUser()['full_name']); ?> (Admin)</span>
                <button class="logout-btn" id="logout-btn">Logout</button>
            </div>
        </div>
        <div class="dashboard-content">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $logs_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $row['logged_at']; ?></td>
                            <td><?php echo htmlspecialchars($row['username'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($row['role_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo htmlspecialchars($row['action']); ?></td>
                            <td><?php echo htmlspecialchars($row['details']); ?></td>
                            <td><?php echo $row['ip_address']; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?>" <?php if ($i == $page) echo 'class="active"'; ?>><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <script src="../assets/app.js"></script>
</body>
</html>