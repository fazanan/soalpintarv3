<?php
session_start();
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  echo 'Unauthorized';
  exit;
}
require_once __DIR__ . '/../db.php';
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true) ?: [];
$type = $data['type'] ?? '';
$uid = (int)($_SESSION['user_id'] ?? 0);

function has_column(mysqli $db, string $table, string $column): bool {
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

function get_model_config(mysqli $db, string $model, string $modality): ?array {
  if (!table_exists($db, 'api_models')) return null;
  $sql = "SELECT provider, modality, model, token_input_price, token_output_price, currency, currency_rate_to_idr
            FROM api_models
           WHERE is_active = 1 AND model = ? AND modality = ?
        ORDER BY updated_at DESC
           LIMIT 1";
  $stmt = $db->prepare($sql);
  if (!$stmt) return null;
  $stmt->bind_param('ss', $model, $modality);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  return $row ?: null;
}

if ($type === 'save') {
  $state = $data['state'] ?? null;
  if (!$state || !is_array($state)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid state']);
    log_audit($mysqli, $uid, 'error', 'soal_save', 'State invalid saat simpan', 400, []);
    exit;
  }
  $title = trim((string)($data['title'] ?? ''));
  $tokenIn = (int)($data['token_input'] ?? 0);
  $tokenOut = (int)($data['token_output'] ?? 0);
  $model = (string)($data['model'] ?? '');
  $jenjang = (string)($state['identity']['jenjang'] ?? '');
  $kelas = (string)($state['identity']['kelas'] ?? '');
  $mapel = (string)($state['identity']['mataPelajaran'] ?? '');
  $qcount = is_array($state['questions'] ?? null) ? count($state['questions']) : 0;
  if ($title === '') {
    $title = trim(($mapel ?: 'Soal') . ' ' . ($kelas ?: ''));
  }
  $snap = json_encode($state, JSON_UNESCAPED_UNICODE);
  $hasTokIn = has_column($mysqli, 'soal_user', 'token_input');
  $hasTokOut = has_column($mysqli, 'soal_user', 'token_output');
  $hasModel = has_column($mysqli, 'soal_user', 'model');
  $hasPriceIn = has_column($mysqli, 'soal_user', 'token_input_price');
  $hasPriceOut = has_column($mysqli, 'soal_user', 'token_output_price');
  $hasCurrency = has_column($mysqli, 'soal_user', 'currency');
  $hasFx = has_column($mysqli, 'soal_user', 'currency_rate_to_idr');

  $cfg = $model !== '' ? get_model_config($mysqli, $model, 'chat') : null;
  $priceIn = (float)($cfg['token_input_price'] ?? 0);
  $priceOut = (float)($cfg['token_output_price'] ?? 0);
  $currency = (string)($cfg['currency'] ?? 'USD');
  $fxToIdr = (float)($cfg['currency_rate_to_idr'] ?? 1.0);

  if ($hasTokIn && $hasTokOut && $hasModel && $hasPriceIn && $hasPriceOut && $hasCurrency && $hasFx) {
    $stmt = $mysqli->prepare("INSERT INTO soal_user (user_id, title, jenjang, kelas, mata_pelajaran, question_count, token_input, token_output, model, token_input_price, token_output_price, currency, currency_rate_to_idr, snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  } else if ($hasTokIn && $hasTokOut) {
    $stmt = $mysqli->prepare("INSERT INTO soal_user (user_id, title, jenjang, kelas, mata_pelajaran, question_count, token_input, token_output, snapshot) VALUES (?,?,?,?,?,?,?,?,?)");
  } else {
    $stmt = $mysqli->prepare("INSERT INTO soal_user (user_id, title, jenjang, kelas, mata_pelajaran, question_count, snapshot) VALUES (?,?,?,?,?,?,?)");
  }
  if (!$stmt) {
    echo json_encode(['ok' => false, 'message' => 'DB error']);
    log_audit($mysqli, $uid, 'error', 'soal_save', 'Gagal prepare insert soal_user', 500, ['mysql_error'=>$mysqli->error]);
    exit;
  }
  if ($hasTokIn && $hasTokOut && $hasModel && $hasPriceIn && $hasPriceOut && $hasCurrency && $hasFx) {
    $stmt->bind_param('issssiissssdss', $uid, $title, $jenjang, $kelas, $mapel, $qcount, $tokenIn, $tokenOut, $model, $priceIn, $priceOut, $currency, $fxToIdr, $snap);
  } else if ($hasTokIn && $hasTokOut) {
    $stmt->bind_param('issssiiss', $uid, $title, $jenjang, $kelas, $mapel, $qcount, $tokenIn, $tokenOut, $snap);
  } else {
    $stmt->bind_param('issssis', $uid, $title, $jenjang, $kelas, $mapel, $qcount, $snap);
  }
  $ok = $stmt->execute();
  $newId = $stmt->insert_id;
  $stmt->close();
  if (!$ok) {
    log_audit($mysqli, $uid, 'error', 'soal_save', 'Gagal eksekusi insert soal_user', 500, []);
  }
  echo json_encode(['ok' => $ok, 'id' => (int)$newId]);
  exit;
}

if ($type === 'list') {
  $limit = 50;
  $stmt = $mysqli->prepare("SELECT id, title, question_count, created_at FROM soal_user WHERE user_id=? ORDER BY id DESC LIMIT ?");
  $stmt->bind_param('ii', $uid, $limit);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id' => (int)$r['id'],
      'title' => (string)$r['title'],
      'question_count' => (int)$r['question_count'],
      'created_at' => (string)$r['created_at'],
    ];
  }
  $stmt->close();
  echo json_encode(['ok' => true, 'items' => $rows]);
  exit;
}

if ($type === 'admin_list') {
  $role = (string)($_SESSION['role'] ?? 'user');
  if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden']);
    exit;
  }
  $limit = 100;
  $offset = max(0, (int)($data['offset'] ?? 0));
  $stmt = $mysqli->prepare("
    SELECT su.id, su.title, su.question_count, su.token_input, su.token_output, su.created_at, u.username
    FROM soal_user su
    JOIN users u ON u.id = su.user_id
    ORDER BY su.id DESC
    LIMIT ? OFFSET ?
  ");
  $stmt->bind_param('ii', $limit, $offset);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($r = $res->fetch_assoc()) {
    $rows[] = [
      'id' => (int)$r['id'],
      'title' => (string)$r['title'],
      'question_count' => (int)$r['question_count'],
      'token_input' => (int)$r['token_input'],
      'token_output' => (int)$r['token_output'],
      'created_at' => (string)$r['created_at'],
      'username' => (string)$r['username'],
    ];
  }
  $stmt->close();
  echo json_encode(['ok' => true, 'items' => $rows]);
  exit;
}

if ($type === 'get') {
  $id = (int)($data['id'] ?? 0);
  $stmt = $mysqli->prepare("SELECT snapshot FROM soal_user WHERE id=? AND user_id=? LIMIT 1");
  $stmt->bind_param('ii', $id, $uid);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  $stmt->close();
  if (!$row) {
    echo json_encode(['ok' => false, 'message' => 'Not found']);
    exit;
  }
  $state = json_decode($row['snapshot'] ?? '', true);
  echo json_encode(['ok' => true, 'state' => $state]);
  exit;
}

if ($type === 'delete') {
  $id = (int)($data['id'] ?? 0);
  $stmt = $mysqli->prepare("DELETE FROM soal_user WHERE id=? AND user_id=?");
  $stmt->bind_param('ii', $id, $uid);
  $ok = $stmt->execute();
  $stmt->close();
  echo json_encode(['ok' => $ok]);
  exit;
}

echo json_encode(['ok' => false, 'message' => 'Unknown type']);
?>
