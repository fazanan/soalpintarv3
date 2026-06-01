<?php
session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$code = isset($data['code']) ? trim((string)$data['code']) : (isset($data['custom_code']) ? trim((string)$data['custom_code']) : '');
if ($code !== '6a13d61020b08191910e219eb19937f9') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

function has_column_api_check_lkpd(mysqli $db, string $table, string $column): bool {
  $table = $db->real_escape_string($table);
  $column = $db->real_escape_string($column);
  $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
  if ($res = $db->query($sql)) {
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
  }
  return false;
}

function stmt_bind_params_api_check_lkpd(mysqli_stmt $stmt, string $types, array $values): void {
  $bind = [];
  $bind[] = $types;
  foreach ($values as $i => $v) $bind[] = &$values[$i];
  call_user_func_array([$stmt, 'bind_param'], $bind);
}

$hasNoHpCol = false;
$hasAccessLkpdInteraktifCol = false;
try {
  $hasNoHpCol = has_column_api_check_lkpd($mysqli, 'users', 'no_hp');
  $hasAccessLkpdInteraktifCol = has_column_api_check_lkpd($mysqli, 'users', 'access_lkpd_interaktif');
} catch (mysqli_sql_exception $e) {
  $hasNoHpCol = false;
  $hasAccessLkpdInteraktifCol = false;
}

$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$row = null;

if ($uid > 0) {
  $exprAccess = $hasAccessLkpdInteraktifCol ? 'access_lkpd_interaktif' : '1';
  $stmt = $mysqli->prepare("SELECT id, username, role, ($exprAccess) AS access_lkpd_interaktif FROM users WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
} else {
  $u = isset($data['username']) ? trim((string)$data['username']) : '';
  $p = isset($data['password']) ? (string)$data['password'] : '';
  if ($u === '' || $p === '') {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized', 'message' => 'login required'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $isEmail = filter_var($u, FILTER_VALIDATE_EMAIL) !== false;
  $uEmail = $isEmail ? trim(strtolower($u)) : '';
  $digits = preg_replace('/\D+/', '', $u);
  $candPhones = [];
  if (!$isEmail && $digits !== '' && $hasNoHpCol) {
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

  $exprAccess = $hasAccessLkpdInteraktifCol ? 'access_lkpd_interaktif' : '1';
  $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(no_hp,' ',''),'-',''),'(',''),')',''),'+','')";
  $stmt = null;
  try {
    if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
      $ph = implode(',', array_fill(0, count($candPhones), '?'));
      $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccess) AS access_lkpd_interaktif FROM users WHERE username = ? OR ($phoneExpr IN ($ph)) LIMIT 1");
    } else {
      $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccess) AS access_lkpd_interaktif FROM users WHERE username = ? LIMIT 1");
    }
  } catch (mysqli_sql_exception $e) {
    $stmt = null;
  }
  if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db_error'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
    $types = 's' . str_repeat('s', count($candPhones));
    $values = array_merge([$u], $candPhones);
    stmt_bind_params_api_check_lkpd($stmt, $types, $values);
  } else {
    $uLookup = $isEmail ? $uEmail : $u;
    $stmt->bind_param('s', $uLookup);
  }
  $stmt->execute();
  $res = $stmt->get_result();
  $r = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$r || !isset($r['password']) || !password_verify($p, (string)$r['password'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid_credentials'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $row = $r;
}

if (!$row || !isset($row['id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
  exit;
}

$role = (string)($row['role'] ?? 'user');
$access = (int)($row['access_lkpd_interaktif'] ?? 1);
$valid = ($role === 'admin') || ($access === 1);

echo json_encode([
  'ok' => true,
  'valid' => $valid,
  'reason' => $valid ? 'ok' : 'no_access',
  'user' => [
    'id' => (int)$row['id'],
    'username' => (string)($row['username'] ?? ''),
    'role' => $role,
    'access_lkpd_interaktif' => $access,
  ],
], JSON_UNESCAPED_UNICODE);

