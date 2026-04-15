<?php
session_start();
require_once __DIR__ . '/auth_lock.php';
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
  if (substr($digits, 0, 1) === '0') {
    $candPhones[] = $digits;
    $candPhones[] = '62' . substr($digits, 1);
  } elseif (substr($digits, 0, 2) === '62') {
    $candPhones[] = $digits;
    $candPhones[] = '0' . substr($digits, 2);
  } elseif (substr($digits, 0, 1) === '8') {
    $candPhones[] = '0' . $digits;
    $candPhones[] = '62' . $digits;
    $candPhones[] = $digits;
  } else {
    $candPhones[] = $digits;
  }
  $candPhones = array_values(array_unique(array_filter($candPhones, function ($x) { return $x !== ''; })));
  $candPhones = array_slice($candPhones, 0, 4);
}

$hasNoHpCol = false;
$hasAccessQuizCol = false;
$hasAccessRekapCol = false;
$hasAccessBuatSoalCol = false;
$hasAccessModulAjarCol = false;
$hasAccessRppCol = false;
try {
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) {
    $hasNoHpCol = $rs->num_rows > 0;
    $rs->close();
  }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_quiz'")) { $hasAccessQuizCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_rekap_nilai'")) { $hasAccessRekapCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_buat_soal'")) { $hasAccessBuatSoalCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_modul_ajar'")) { $hasAccessModulAjarCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_rpp'")) { $hasAccessRppCol = $rs->num_rows > 0; $rs->close(); }
} catch (mysqli_sql_exception $e) {
  $hasNoHpCol = false;
  $hasAccessQuizCol = false;
  $hasAccessRekapCol = false;
  $hasAccessBuatSoalCol = false;
  $hasAccessModulAjarCol = false;
  $hasAccessRppCol = false;
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
$exprAccessQuiz = $hasAccessQuizCol ? 'access_quiz' : '1';
$exprAccessRekap = $hasAccessRekapCol ? 'access_rekap_nilai' : '1';
$exprAccessBuatSoal = $hasAccessBuatSoalCol ? 'access_buat_soal' : '1';
$exprAccessModulAjar = $hasAccessModulAjarCol ? 'access_modul_ajar' : '1';
$exprAccessRpp = $hasAccessRppCol ? 'access_rpp' : '1';
$stmt = null;
try {
  if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
    $ph = implode(',', array_fill(0, count($candPhones), '?'));
    $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccessQuiz) AS access_quiz, ($exprAccessRekap) AS access_rekap_nilai, ($exprAccessBuatSoal) AS access_buat_soal, ($exprAccessModulAjar) AS access_modul_ajar, ($exprAccessRpp) AS access_rpp FROM users WHERE username = ? OR ($phoneExpr IN ($ph)) LIMIT 1");
  } else {
    $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccessQuiz) AS access_quiz, ($exprAccessRekap) AS access_rekap_nilai, ($exprAccessBuatSoal) AS access_buat_soal, ($exprAccessModulAjar) AS access_modul_ajar, ($exprAccessRpp) AS access_rpp FROM users WHERE username = ? LIMIT 1");
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
  $stmt->bind_result($id, $dbUsername, $hash, $role, $accessQuiz, $accessRekap, $accessBuatSoal, $accessModulAjar, $accessRpp);
  if ($stmt->fetch() && password_verify($p, $hash)) {
    session_regenerate_id(true);
    $sid = session_id();
    $isAdminLogin = (string)$role === 'admin';
    if ($isAdminLogin) {
      auth_lock_release((int)$id, null);
    } else {
      if (auth_lock_busy((int)$id, $sid)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?e=busy');
        exit;
      }
      auth_lock_acquire((int)$id, $sid);
    }
    $_SESSION['user_id'] = $id;
    $_SESSION['username'] = $dbUsername ?: ($isEmail ? $uEmail : $u);
    $_SESSION['role'] = $role ?: 'user';
    $_SESSION['access_quiz'] = $isAdminLogin ? 1 : (int)$accessQuiz;
    $_SESSION['access_rekap_nilai'] = $isAdminLogin ? 1 : (int)$accessRekap;
    $_SESSION['access_buat_soal'] = $isAdminLogin ? 1 : (int)$accessBuatSoal;
    $_SESSION['access_modul_ajar'] = $isAdminLogin ? 1 : (int)$accessModulAjar;
    $_SESSION['access_rpp'] = $isAdminLogin ? 1 : (int)$accessRpp;
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
    session_regenerate_id(true);
    $sid = session_id();
    $isAdminLogin2 = (string)$role2 === 'admin';
    if ($isAdminLogin2) {
      auth_lock_release((int)$id2, null);
    } else {
      if (auth_lock_busy((int)$id2, $sid)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?e=busy');
        exit;
      }
      auth_lock_acquire((int)$id2, $sid);
    }
    $_SESSION['user_id'] = $id2;
    $_SESSION['username'] = $dbUsername2 ?: ($isEmail ? $uEmail : $u);
    $_SESSION['role'] = $role2 ?: 'user';
    $_SESSION['access_quiz'] = 1;
    $_SESSION['access_rekap_nilai'] = 1;
    $_SESSION['access_buat_soal'] = 1;
    $_SESSION['access_modul_ajar'] = 1;
    $_SESSION['access_rpp'] = 1;
    header('Location: index.php');
    exit;
  }
  $stmt2->close();
}
header('Location: login.php?e=1');
exit;
