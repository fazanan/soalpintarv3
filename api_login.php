<?php
session_start();
require_once __DIR__ . '/auth_lock.php';
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

$u = isset($data['username']) ? trim((string)$data['username']) : '';
$p = isset($data['password']) ? (string)$data['password'] : '';
if ($u === '' || $p === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'missing_fields', 'message' => 'username dan password wajib diisi'], JSON_UNESCAPED_UNICODE);
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
$hasAccessBahanAjarCol = false;
$hasAccessLkpdInteraktifCol = false;
$hasAccessRppCol = false;
$hasNamaCol = false;
$hasJenjangCol = false;
$hasNamaSekolahCol = false;
try {
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'no_hp'")) { $hasNoHpCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_quiz'")) { $hasAccessQuizCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_rekap_nilai'")) { $hasAccessRekapCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_buat_soal'")) { $hasAccessBuatSoalCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_modul_ajar'")) { $hasAccessModulAjarCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_bahan_ajar'")) { $hasAccessBahanAjarCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_lkpd_interaktif'")) { $hasAccessLkpdInteraktifCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'access_rpp'")) { $hasAccessRppCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama'")) { $hasNamaCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'jenjang'")) { $hasJenjangCol = $rs->num_rows > 0; $rs->close(); }
  if ($rs = $mysqli->query("SHOW COLUMNS FROM users LIKE 'nama_sekolah'")) { $hasNamaSekolahCol = $rs->num_rows > 0; $rs->close(); }
} catch (mysqli_sql_exception $e) {
  $hasNoHpCol = false;
  $hasAccessQuizCol = false;
  $hasAccessRekapCol = false;
  $hasAccessBuatSoalCol = false;
  $hasAccessModulAjarCol = false;
  $hasAccessBahanAjarCol = false;
  $hasAccessLkpdInteraktifCol = false;
  $hasAccessRppCol = false;
  $hasNamaCol = false;
  $hasJenjangCol = false;
  $hasNamaSekolahCol = false;
}

function stmt_bind_params_api(mysqli_stmt $stmt, string $types, array $values): void {
  $bind = [];
  $bind[] = $types;
  foreach ($values as $i => $v) $bind[] = &$values[$i];
  call_user_func_array([$stmt, 'bind_param'], $bind);
}

$phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(no_hp,' ',''),'-',''),'(',''),')',''),'+','')";
$exprAccessQuiz = $hasAccessQuizCol ? 'access_quiz' : '1';
$exprAccessRekap = $hasAccessRekapCol ? 'access_rekap_nilai' : '1';
$exprAccessBuatSoal = $hasAccessBuatSoalCol ? 'access_buat_soal' : '1';
$exprAccessModulAjar = $hasAccessModulAjarCol ? 'access_modul_ajar' : '1';
$exprAccessBahanAjar = $hasAccessBahanAjarCol ? 'access_bahan_ajar' : '1';
$exprAccessLkpdInteraktif = $hasAccessLkpdInteraktifCol ? 'access_lkpd_interaktif' : '1';
$exprAccessRpp = $hasAccessRppCol ? 'access_rpp' : '1';
$exprNama = $hasNamaCol ? 'nama' : "''";
$exprJenjang = $hasJenjangCol ? 'jenjang' : "''";
$exprNamaSekolah = $hasNamaSekolahCol ? 'nama_sekolah' : "''";

$stmt = null;
try {
  if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
    $ph = implode(',', array_fill(0, count($candPhones), '?'));
    $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccessQuiz) AS access_quiz, ($exprAccessRekap) AS access_rekap_nilai, ($exprAccessBuatSoal) AS access_buat_soal, ($exprAccessModulAjar) AS access_modul_ajar, ($exprAccessBahanAjar) AS access_bahan_ajar, ($exprAccessLkpdInteraktif) AS access_lkpd_interaktif, ($exprAccessRpp) AS access_rpp, ($exprNama) AS nama, ($exprJenjang) AS jenjang, ($exprNamaSekolah) AS nama_sekolah FROM users WHERE username = ? OR ($phoneExpr IN ($ph)) LIMIT 1");
  } else {
    $stmt = $mysqli->prepare("SELECT id, username, password, role, ($exprAccessQuiz) AS access_quiz, ($exprAccessRekap) AS access_rekap_nilai, ($exprAccessBuatSoal) AS access_buat_soal, ($exprAccessModulAjar) AS access_modul_ajar, ($exprAccessBahanAjar) AS access_bahan_ajar, ($exprAccessLkpdInteraktif) AS access_lkpd_interaktif, ($exprAccessRpp) AS access_rpp, ($exprNama) AS nama, ($exprJenjang) AS jenjang, ($exprNamaSekolah) AS nama_sekolah FROM users WHERE username = ? LIMIT 1");
  }
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}

if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_error', 'message' => 'Gagal memproses login'], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($hasNoHpCol && !$isEmail && count($candPhones) > 0) {
  $types = 's' . str_repeat('s', count($candPhones));
  $values = array_merge([$u], $candPhones);
  stmt_bind_params_api($stmt, $types, $values);
} else {
  $uLookup = $isEmail ? $uEmail : $u;
  $stmt->bind_param('s', $uLookup);
}

$stmt->execute();
$id = 0;
$dbUsername = '';
$hash = '';
$role = 'user';
$accessQuiz = 1;
$accessRekap = 1;
$accessBuatSoal = 1;
$accessModulAjar = 1;
$accessBahanAjar = 1;
$accessLkpdInteraktif = 1;
$accessRpp = 1;
$nama = '';
$jenjang = '';
$namaSekolah = '';
$stmt->bind_result($id, $dbUsername, $hash, $role, $accessQuiz, $accessRekap, $accessBuatSoal, $accessModulAjar, $accessBahanAjar, $accessLkpdInteraktif, $accessRpp, $nama, $jenjang, $namaSekolah);

$ok = $stmt->fetch() && password_verify($p, $hash);
$stmt->close();

if (!$ok) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'invalid_credentials', 'message' => 'Login gagal'], JSON_UNESCAPED_UNICODE);
  exit;
}

session_regenerate_id(true);
$sid = session_id();
$deviceId = auth_lock_get_device_id();
$fp = auth_lock_fingerprint();
$isAdminLogin = (string)$role === 'admin';
$uname = trim(strtolower((string)($dbUsername ?: ($isEmail ? $uEmail : $u))));
$isDemoMulti = $uname === 'coba@gmail.com';

if ($isAdminLogin) {
  auth_lock_release((int)$id, null);
} else {
  if (!$isDemoMulti && auth_lock_busy((int)$id, $sid, $deviceId, $fp)) {
    $_SESSION = [];
    session_destroy();
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'busy', 'message' => 'Akun sedang digunakan di perangkat lain'], JSON_UNESCAPED_UNICODE);
    exit;
  }
  if ($isDemoMulti) auth_lock_release((int)$id, null);
  else auth_lock_acquire((int)$id, $sid, $deviceId, $fp);
}

$_SESSION['user_id'] = (int)$id;
$_SESSION['username'] = $dbUsername ?: ($isEmail ? $uEmail : $u);
$_SESSION['role'] = $role ?: 'user';
$_SESSION['access_quiz'] = $isAdminLogin ? 1 : (int)$accessQuiz;
$_SESSION['access_rekap_nilai'] = $isAdminLogin ? 1 : (int)$accessRekap;
$_SESSION['access_buat_soal'] = $isAdminLogin ? 1 : (int)$accessBuatSoal;
$_SESSION['access_modul_ajar'] = $isAdminLogin ? 1 : (int)$accessModulAjar;
$_SESSION['access_bahan_ajar'] = $isAdminLogin ? 1 : (int)$accessBahanAjar;
$_SESSION['access_lkpd_interaktif'] = $isAdminLogin ? 1 : (int)$accessLkpdInteraktif;
$_SESSION['access_rpp'] = $isAdminLogin ? 1 : (int)$accessRpp;
$_SESSION['nama'] = (string)$nama;
$_SESSION['jenjang'] = (string)$jenjang;
$_SESSION['nama_sekolah'] = (string)$namaSekolah;
$_SESSION['session_lock_exempt'] = $isDemoMulti ? 1 : 0;

echo json_encode([
  'ok' => true,
  'session_id' => $sid,
  'user' => [
    'id' => (int)$id,
    'username' => (string)($_SESSION['username'] ?? ''),
    'role' => (string)($_SESSION['role'] ?? 'user'),
    'nama' => (string)($_SESSION['nama'] ?? ''),
    'jenjang' => (string)($_SESSION['jenjang'] ?? ''),
    'nama_sekolah' => (string)($_SESSION['nama_sekolah'] ?? ''),
    'access' => [
      'quiz' => (int)($_SESSION['access_quiz'] ?? 1),
      'rekap_nilai' => (int)($_SESSION['access_rekap_nilai'] ?? 1),
      'buat_soal' => (int)($_SESSION['access_buat_soal'] ?? 1),
      'modul_ajar' => (int)($_SESSION['access_modul_ajar'] ?? 1),
      'bahan_ajar' => (int)($_SESSION['access_bahan_ajar'] ?? 1),
      'lkpd_interaktif' => (int)($_SESSION['access_lkpd_interaktif'] ?? 1),
      'rpp' => (int)($_SESSION['access_rpp'] ?? 1),
    ],
    'session_lock_exempt' => (int)($_SESSION['session_lock_exempt'] ?? 0),
  ],
], JSON_UNESCAPED_UNICODE);
