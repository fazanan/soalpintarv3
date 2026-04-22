<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';

function table_exists(mysqli $db, string $table): bool {
  $table = $db->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  if ($res = $db->query($sql)) {
    $ok = $res->num_rows > 0;
    $res->close();
    return $ok;
  }
  return false;
}

function log_audit(mysqli $db, int $user_id, string $level, string $category, string $message, ?int $http_status = null, array $context = []): void {
  if (!table_exists($db, 'audit_logs')) return;
  $endpoint = $_SERVER['REQUEST_URI'] ?? '';
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $json = json_encode($context, JSON_UNESCAPED_UNICODE) ?: '';
  $stmt = $db->prepare("INSERT INTO audit_logs (user_id, level, category, message, http_status, endpoint, ip_address, context) VALUES (?,?,?,?,?,?,?,?)");
  if ($stmt) {
    $stmt->bind_param('isssisss', $user_id, $level, $category, $message, $http_status, $endpoint, $ip, $json);
    $stmt->execute();
    $stmt->close();
  }
}

$role = (string)($_SESSION['role'] ?? 'user');
if ($role !== 'admin') {
  $access = isset($_SESSION['access_quiz']) ? (int)$_SESSION['access_quiz'] : null;
  if ($access === null) {
    $stmtAcc = null;
    try { $stmtAcc = $mysqli->prepare("SELECT access_quiz FROM users WHERE id=? LIMIT 1"); } catch (mysqli_sql_exception $e) { $stmtAcc = null; }
    if ($stmtAcc) {
      $stmtAcc->bind_param('i', $_SESSION['user_id']);
      $stmtAcc->execute();
      $stmtAcc->bind_result($aq);
      if ($stmtAcc->fetch()) $access = (int)$aq;
      $stmtAcc->close();
    }
    if ($access === null) $access = 1;
  }
  if ($access !== 1) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'no_access']);
    log_audit($mysqli, (int)$_SESSION['user_id'], 'warn', 'quiz_publish', 'No access to publish quiz', 403, []);
    exit;
  }
}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  if (!empty($_POST)) {
    $data = $_POST;
  } else {
    $tmp = [];
    parse_str($raw ?? '', $tmp);
    if (is_array($tmp) && !empty($tmp)) $data = $tmp;
  }
}
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode([
    'ok'=>false,
    'error'=>'invalid_json',
    'content_type'=> (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    'length'=> strlen((string)$raw)
  ]);
  log_audit($mysqli, (int)$_SESSION['user_id'], 'warn', 'quiz_publish', 'Invalid JSON request', 400, [
    'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    'length' => strlen((string)$raw),
  ]);
  exit;
}
$slug = isset($data['slug']) ? trim((string)$data['slug']) : '';
$mapel = isset($data['mapel']) ? trim((string)$data['mapel']) : '';
$kelas = isset($data['kelas']) ? trim((string)$data['kelas']) : '';
$sekolah = isset($data['sekolah']) ? trim((string)$data['sekolah']) : '';
$guru = isset($data['guru']) ? trim((string)$data['guru']) : '';
$payload = $data['payload_public'] ?? null;
$answerKey = $data['answer_key'] ?? null;
$expire = isset($data['expire_at']) ? trim((string)$data['expire_at']) : '';
// Normalisasi format expire ke 'YYYY-MM-DD HH:MM:SS'
if ($expire !== '') {
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) {
    $expire .= ' 23:59:59';
  } else if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $expire)) {
    $expire .= ':00';
  } else {
    $ts = strtotime($expire);
    if ($ts !== false) $expire = date('Y-m-d H:i:s', $ts);
    else $expire = '';
  }
}
$maxAbsen = isset($data['max_absen']) ? (int)$data['max_absen'] : 0;
$showSolution = isset($data['show_solution']) ? (int)$data['show_solution'] : 0;
if (is_string($payload)) {
  $tmp = json_decode($payload, true);
  if (is_array($tmp)) $payload = $tmp;
}
if (is_string($answerKey)) {
  $tmp = json_decode($answerKey, true);
  if (is_array($tmp)) $answerKey = $tmp;
}
$missing = [];
if ($slug === '') $missing[] = 'slug';
if ($mapel === '') $missing[] = 'mapel';
if (!is_array($payload) || count($payload) === 0) $missing[] = 'payload_public';
if (!is_array($answerKey) || count($answerKey) === 0) $missing[] = 'answer_key';
if ($missing) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_input','missing'=>$missing], JSON_UNESCAPED_UNICODE);
  log_audit($mysqli, (int)$_SESSION['user_id'], 'warn', 'quiz_publish', 'Invalid input', 400, [
    'missing' => $missing,
    'slug' => $slug,
    'mapel' => $mapel,
    'kelas' => $kelas,
  ]);
  exit;
}
function normalize_slug(string $s): string {
  $s = trim(strtolower($s));
  $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  return $s;
}
$baseSlug = normalize_slug($slug);
if ($baseSlug === '') $baseSlug = 'kelas';
$payloadObject = [];
if (isset($payload['items']) && is_array($payload['items'])) {
  $payloadObject = $payload;
} else {
  $payloadObject = ['items'=>$payload];
}
if (!isset($payloadObject['settings']) || !is_array($payloadObject['settings'])) $payloadObject['settings'] = [];
$payloadObject['settings']['max_absen'] = max(0, (int)$maxAbsen);
$payloadObject['settings']['meta'] = [
  'sekolah' => $sekolah,
  'guru' => $guru,
  'mapel' => $mapel,
  'kelas' => $kelas
];
$payloadObject['settings']['show_solution'] = $showSolution ? 1 : 0;
$payloadObject['settings']['answer_key'] = is_array($answerKey) ? array_map('intval', $answerKey) : [];
$jsonFlags = JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
$payloadJson = json_encode($payloadObject, $jsonFlags);
$answerJson = is_string($answerKey) ? $answerKey : json_encode($answerKey, $jsonFlags);
$total = is_array($payloadObject['items'] ?? null) ? count($payloadObject['items']) : 0;
if (!is_string($payloadJson) || $payloadJson === '' || !is_string($answerJson) || $answerJson === '') {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'encode_fail'], JSON_UNESCAPED_UNICODE);
  log_audit($mysqli, (int)$_SESSION['user_id'], 'error', 'quiz_publish', 'Encode payload failed', 500, [
    'slug' => $slug,
    'mapel' => $mapel,
    'kelas' => $kelas,
  ]);
  exit;
}
if ($expire === '') {
  $expire = date('Y-m-d H:i:s', time() + (14 * 86400));
}
$sql = "INSERT INTO published_quizzes (user_id, slug, mapel, kelas, total_soal, payload_public, answer_key, is_active, expire_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'stmt_fail','errno'=>(int)$mysqli->errno]);
  log_audit($mysqli, (int)$_SESSION['user_id'], 'error', 'quiz_publish', 'Prepare insert failed', 500, [
    'errno' => (int)$mysqli->errno,
    'slug' => $slug,
    'mapel' => $mapel,
    'kelas' => $kelas,
  ]);
  exit;
}
$candidateSlug = $baseSlug;
$userId = (int)$_SESSION['user_id'];
$stmt->bind_param('isssisss', $userId, $candidateSlug, $mapel, $kelas, $total, $payloadJson, $answerJson, $expire);
$ok = false;
$slugAdjusted = false;
$attempts = 0;
while ($attempts < 8) {
  $attempts++;
  try {
    $ok = $stmt->execute();
  } catch (mysqli_sql_exception $e) {
    $ok = false;
  }
  if ($ok) break;
  $code = (int)($stmt->errno ?: $mysqli->errno);
  if ($code !== 1062) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'insert_fail','errno'=>$code]);
    log_audit($mysqli, (int)$_SESSION['user_id'], 'error', 'quiz_publish', 'Insert failed', 500, [
      'errno' => $code,
      'slug_original' => $baseSlug,
      'slug_candidate' => $candidateSlug,
      'mapel' => $mapel,
      'kelas' => $kelas,
      'total' => $total,
      'attempts' => $attempts,
    ]);
    exit;
  }
  $slugAdjusted = true;
  try {
    $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
  } catch (Exception $e) {
    $suffix = substr(md5(uniqid('', true)), 0, 6);
  }
  $maxLen = 128;
  $baseMax = $maxLen - 1 - strlen($suffix);
  $basePart = $baseSlug;
  if (strlen($basePart) > $baseMax) $basePart = substr($basePart, 0, $baseMax);
  $candidateSlug = rtrim($basePart, '-') . '-' . $suffix;
}
if (!$ok) {
  $stmt->close();
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>'slug_exists']);
  log_audit($mysqli, (int)$_SESSION['user_id'], 'warn', 'quiz_publish', 'Slug exists after retries', 409, [
    'slug_original' => $baseSlug,
    'mapel' => $mapel,
    'kelas' => $kelas,
    'total' => $total,
    'attempts' => $attempts,
  ]);
  exit;
}
$id = (int)$stmt->insert_id;
$stmt->close();
log_audit($mysqli, (int)$_SESSION['user_id'], 'info', 'quiz_publish', 'Quiz link published', 200, [
  'published_id' => $id,
  'slug' => $candidateSlug,
  'slug_original' => $baseSlug,
  'slug_adjusted' => $slugAdjusted ? 1 : 0,
  'mapel' => $mapel,
  'kelas' => $kelas,
  'total' => $total,
  'expire_at' => $expire,
  'max_absen' => max(0, (int)$maxAbsen),
  'show_solution' => $showSolution ? 1 : 0,
]);
echo json_encode([
  'ok'=>true,
  'id'=>$id,
  'slug'=>$candidateSlug,
  'slug_original'=>$baseSlug,
  'slug_adjusted'=>$slugAdjusted ? 1 : 0,
  'mapel'=>$mapel,
  'kelas'=>$kelas,
  'total'=>$total,
  'public_link'=>"https://".$_SERVER['HTTP_HOST']."/{$candidateSlug}/{no_absen}"
], JSON_UNESCAPED_UNICODE);
exit;
