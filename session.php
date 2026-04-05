<?php
/**
 * Session Timeout Handler
 * 
 * Include this file at the top of every protected page.
 * Define SESSION_TIMEOUT in seconds (default 900 = 15 minutes).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SESSION_TIMEOUT', 900); // 15 minutes

if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > SESSION_TIMEOUT) {
            // Log timeout if audit function exists
            if (function_exists('log_audit')) {
                log_audit('SESSION_TIMEOUT', 'Session expired due to inactivity');
            }
            $_SESSION = array();
            session_destroy();
            // Redirect to login with timeout message – adjust folder name if needed
            header('Location: login.php?error=timeout');
            exit;
        }
    }
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
}
?>