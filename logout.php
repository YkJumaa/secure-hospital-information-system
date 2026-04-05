<?php
require_once 'auth.php';
require_once 'audit.php';

log_audit('LOGOUT', 'User logged out');
session_destroy();
header('Location: ./index.html');
exit;
?>