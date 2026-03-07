<?php
session_start();
require __DIR__ . '/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}
$u = isset($_POST['username']) ? trim($_POST['username']) : '';
$p = isset($_POST['password']) ? $_POST['password'] : '';
if ($u === '' || $p === '') {
  header('Location: login.php?e=1');
  exit;
}
$stmt = $mysqli->prepare('SELECT id, password, role FROM users WHERE username = ? LIMIT 1');
$stmt->bind_param('s', $u);
$stmt->execute();
$stmt->bind_result($id, $hash, $role);
if ($stmt->fetch() && password_verify($p, $hash)) {
  $_SESSION['user_id'] = $id;
  $_SESSION['username'] = $u;
  $_SESSION['role'] = $role ?: 'user';
  header('Location: index.php');
  exit;
}
header('Location: login.php?e=1');
exit;
