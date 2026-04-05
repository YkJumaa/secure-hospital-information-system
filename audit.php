<?php
require_once 'db.php';

/**
 * Log an action to audit_logs
 */
function log_audit($action, $details = '') {
    global $conn;
    
    $user_id = $_SESSION['user_id'] ?? null;
    $role_id = $_SESSION['role_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $stmt = $conn->prepare("INSERT INTO audit_logs (user_id, role_id, action, details, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $user_id, $role_id, $action, $details, $ip);
    $stmt->execute();
}
?>