<?php
session_start();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$sid = session_id();
require_once __DIR__ . '/auth_lock.php';
if ($uid > 0) auth_lock_release($uid, $sid ?: null);
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header('Location: login.php');
exit;
?>
