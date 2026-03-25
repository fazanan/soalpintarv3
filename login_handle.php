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
$stmt = $mysqli->prepare('SELECT id, password, role, access_quiz, access_rekap_nilai FROM users WHERE username = ? LIMIT 1');
if ($stmt) {
  $stmt->bind_param('s', $u);
  $stmt->execute();
  $stmt->bind_result($id, $hash, $role, $accessQuiz, $accessRekap);
  if ($stmt->fetch() && password_verify($p, $hash)) {
    $_SESSION['user_id'] = $id;
    $_SESSION['username'] = $u;
    $_SESSION['role'] = $role ?: 'user';
    $_SESSION['access_quiz'] = (int)$accessQuiz;
    $_SESSION['access_rekap_nilai'] = (int)$accessRekap;
    header('Location: index.php');
    exit;
  }
  $stmt->close();
} else {
  $stmt2 = $mysqli->prepare('SELECT id, password, role FROM users WHERE username = ? LIMIT 1');
  $stmt2->bind_param('s', $u);
  $stmt2->execute();
  $stmt2->bind_result($id2, $hash2, $role2);
  if ($stmt2->fetch() && password_verify($p, $hash2)) {
    $_SESSION['user_id'] = $id2;
    $_SESSION['username'] = $u;
    $_SESSION['role'] = $role2 ?: 'user';
    $_SESSION['access_quiz'] = 1;
    $_SESSION['access_rekap_nilai'] = 1;
    header('Location: index.php');
    exit;
  }
  $stmt2->close();
}
header('Location: login.php?e=1');
exit;
