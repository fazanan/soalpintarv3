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
$isEmail = filter_var($u, FILTER_VALIDATE_EMAIL) !== false;
$uEmail = $isEmail ? trim(strtolower($u)) : '';
$digits = preg_replace('/\D+/', '', $u);
$candPhones = [];
if (!$isEmail && $digits !== '') {
  if (str_starts_with($digits, '0')) {
    $candPhones[] = $digits;
    $candPhones[] = '62' . substr($digits, 1);
  } elseif (str_starts_with($digits, '62')) {
    $candPhones[] = $digits;
    $candPhones[] = '0' . substr($digits, 2);
  } elseif (str_starts_with($digits, '8')) {
    $candPhones[] = '0' . $digits;
    $candPhones[] = '62' . $digits;
    $candPhones[] = $digits;
  } else {
    $candPhones[] = $digits;
  }
  $candPhones = array_values(array_unique(array_filter($candPhones, fn($x) => $x !== '')));
  $candPhones = array_slice($candPhones, 0, 4);
}

$hasNoHpCol = false;
try {
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) {
    $hasNoHpCol = $rs->num_rows > 0;
    $rs->close();
  }
} catch (mysqli_sql_exception $e) {
  $hasNoHpCol = false;
}

function stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): void {
  $bind = [];
  $bind[] = $types;
  foreach ($values as $i => $v) {
    $bind[] = &$values[$i];
  }
  call_user_func_array([$stmt, 'bind_param'], $bind);
}

$phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(no_hp,' ',''),'-',''),'(',''),')',''),'+','')";
$stmt = null;
try {
  if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
    $ph = implode(',', array_fill(0, count($candPhones), '?'));
    $stmt = $mysqli->prepare("SELECT id, username, password, role, access_quiz, access_rekap_nilai FROM users WHERE username = ? OR ($phoneExpr IN ($ph)) LIMIT 1");
  } else {
    $stmt = $mysqli->prepare('SELECT id, username, password, role, access_quiz, access_rekap_nilai FROM users WHERE username = ? LIMIT 1');
  }
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}
if ($stmt) {
  if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
    $types = 's' . str_repeat('s', count($candPhones));
    $values = array_merge([$u], $candPhones);
    stmt_bind_params($stmt, $types, $values);
  } else {
    $uLookup = $isEmail ? $uEmail : $u;
    $stmt->bind_param('s', $uLookup);
  }
  $stmt->execute();
  $stmt->bind_result($id, $dbUsername, $hash, $role, $accessQuiz, $accessRekap);
  if ($stmt->fetch() && password_verify($p, $hash)) {
    $_SESSION['user_id'] = $id;
    $_SESSION['username'] = $dbUsername ?: ($isEmail ? $uEmail : $u);
    $_SESSION['role'] = $role ?: 'user';
    $_SESSION['access_quiz'] = (int)$accessQuiz;
    $_SESSION['access_rekap_nilai'] = (int)$accessRekap;
    header('Location: index.php');
    exit;
  }
  $stmt->close();
} else {
  $stmt2 = $mysqli->prepare('SELECT id, username, password, role FROM users WHERE username = ? LIMIT 1');
  $uLookup2 = $isEmail ? $uEmail : $u;
  $stmt2->bind_param('s', $uLookup2);
  $stmt2->execute();
  $stmt2->bind_result($id2, $dbUsername2, $hash2, $role2);
  if ($stmt2->fetch() && password_verify($p, $hash2)) {
    $_SESSION['user_id'] = $id2;
    $_SESSION['username'] = $dbUsername2 ?: ($isEmail ? $uEmail : $u);
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
