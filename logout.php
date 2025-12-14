<?php
session_start();

if (file_exists('debug')) {
    function debug_log($message) {
        file_put_contents('debug.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
} else {
    function debug_log($message) { }
}

debug_log('logout.php: Session before unset: user_email=' . ($_SESSION['user_email'] ?? 'not set') . ', auth=' . ($_SESSION['auth'] ?? 'not set'));
session_unset();
debug_log('Session unset');
session_destroy();
debug_log('Session destroyed, redirecting to login.php');
header('Location: login.php');
exit;

