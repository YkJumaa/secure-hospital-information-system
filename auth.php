<?php
require_once 'db.php';

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get current user data
 */
function getCurrentUser() {
    global $conn;
    if (!isLoggedIn()) return null;
    
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT u.*, r.role_name FROM users u 
                            JOIN roles r ON u.role_id = r.id 
                            WHERE u.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Check if current user has one of the allowed roles
 */
function hasRole($allowed_roles) {
    $user = getCurrentUser();
    if (!$user) return false;
    return in_array($user['role_name'], (array)$allowed_roles);
}

/**
 * Require that current user has one of the allowed roles, otherwise redirect to login
 */
function requireRole($allowed_roles) {
    if (!hasRole($allowed_roles)) {
        // Use absolute path with folder name – adjust if your folder name differs
        header('Location: login.php?error=unauthorized');
        exit;
    }
}

/**
 * Get patient_id from user_id (for patient role)
 */
function getPatientIdFromUserId($user_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM patients WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['id'];
    }
    return null;
}
?>