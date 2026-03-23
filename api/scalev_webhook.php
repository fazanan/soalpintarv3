<?php
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
header('Content-Type: application/json; charset=utf-8');

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

function log_audit(mysqli $db, string $level, string $category, string $message, ?int $http_status = null, array $context = []): void {
  if (!table_exists($db, 'audit_logs')) return;
  $endpoint = $_SERVER['REQUEST_URI'] ?? '';
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $json = json_encode($context, JSON_UNESCAPED_UNICODE) ?: '';
  $stmt = $db->prepare("INSERT INTO audit_logs (user_id, level, category, message, http_status, endpoint, ip_address, context) VALUES (NULL,?,?,?,?,?,?,?)");
  if ($stmt) {
    $stmt->bind_param('sssisss', $level, $category, $message, $http_status, $endpoint, $ip, $json);
    $stmt->execute();
    $stmt->close();
  }
}

function extract_customer_email(array $data): string {
  $candidates = [];
  if (isset($data['destination_address']) && is_array($data['destination_address'])) {
    $da = $data['destination_address'];
    $candidates[] = (string)($da['email'] ?? '');
    $candidates[] = (string)($da['email_address'] ?? '');
  }
  $candidates[] = (string)($data['customer_email'] ?? '');
  if (isset($data['customer']) && is_array($data['customer'])) {
    $candidates[] = (string)($data['customer']['email'] ?? '');
  }
  $candidates[] = (string)($data['destination_email'] ?? '');
  $candidates[] = (string)($data['email'] ?? '');
  foreach ($candidates as $raw) {
    $email = trim(strtolower((string)$raw));
    if ($email === '') continue;
    if (str_ends_with($email, '@scalev.id')) continue;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
    return $email;
  }
  return '';
}

function get_signing_secret(): string {
  $secret = getenv('SCALEV_SIGNING_SECRET') ?: '';
  if ($secret !== '') return $secret;
  $p = __DIR__ . '/../SCALEV_SIGNING_SECRET.txt';
  if (is_file($p) && is_readable($p)) {
    $c = file_get_contents($p);
    if ($c !== false) return trim($c);
  }
  return '';
}

function respond_ok(array $extra = []): void {
  echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

function respond_err(int $code, string $err, array $extra = []): void {
  http_response_code($code);
  echo json_encode(array_merge(['ok' => false, 'error' => $err], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) $rawBody = '';
$sig = $_SERVER['HTTP_X_SCALEV_HMAC_SHA256'] ?? '';
$secret = get_signing_secret();
if ($secret === '') {
  log_audit($mysqli, 'error', 'scalev_webhook', 'Signing secret belum diset', 500);
  respond_err(500, 'signing_secret_missing');
}
if ($sig === '') {
  log_audit($mysqli, 'warn', 'scalev_webhook', 'Header signature kosong', 403);
  respond_err(403, 'signature_missing');
}
$calc = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
if (!hash_equals($sig, $calc)) {
  log_audit($mysqli, 'warn', 'scalev_webhook', 'Signature invalid', 403, ['sig' => $sig]);
  respond_err(403, 'signature_invalid');
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
  log_audit($mysqli, 'warn', 'scalev_webhook', 'Payload JSON tidak valid', 400);
  respond_err(400, 'invalid_json');
}

$event = isset($payload['event']) ? (string)$payload['event'] : '';
$uniqueId = isset($payload['unique_id']) ? (string)$payload['unique_id'] : '';
$timestamp = isset($payload['timestamp']) ? (string)$payload['timestamp'] : '';
$data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

if ($event === 'business.test_event') {
  respond_ok(['handled' => 'test_event']);
}

$mysqli->query("CREATE TABLE IF NOT EXISTS scalev_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unique_id VARCHAR(64) NOT NULL,
  event VARCHAR(128) NOT NULL,
  order_id VARCHAR(64) NULL,
  email VARCHAR(160) NULL,
  payment_status VARCHAR(32) NULL,
  created_user_id INT UNSIGNED NULL,
  received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_unique_id (unique_id),
  INDEX idx_event_received (event, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($uniqueId !== '') {
  $stmt = $mysqli->prepare("INSERT IGNORE INTO scalev_webhook_events (unique_id, event, order_id, email, payment_status) VALUES (?,?,?,?,?)");
  if ($stmt) {
    $orderIdIns = isset($data['order_id']) ? (string)$data['order_id'] : '';
    $emailIns = extract_customer_email($data);
    $payIns = isset($data['payment_status']) ? (string)$data['payment_status'] : '';
    $stmt->bind_param('sssss', $uniqueId, $event, $orderIdIns, $emailIns, $payIns);
    $stmt->execute();
    $inserted = $stmt->affected_rows;
    $stmt->close();
    if ($inserted === 0) {
      respond_ok(['handled' => 'duplicate']);
    }
  }
}

if ($event !== 'order.payment_status_changed') {
  respond_ok(['handled' => 'ignored', 'event' => $event]);
}

$paymentStatus = isset($data['payment_status']) ? trim((string)$data['payment_status']) : '';
$ps = strtolower($paymentStatus);
if (!in_array($ps, ['paid', 'settled'], true)) respond_ok(['handled' => 'not_paid']);

$email = extract_customer_email($data);
if ($email === '') {
  log_audit($mysqli, 'warn', 'scalev_webhook', 'Email tidak ditemukan / invalid', 200, ['event' => $event]);
  respond_ok(['handled' => 'missing_email']);
}
if (strlen($email) > 100) {
  log_audit($mysqli, 'warn', 'scalev_webhook', 'Email terlalu panjang untuk username', 200, ['email' => $email]);
  respond_ok(['handled' => 'email_too_long']);
}

$exists = false;
$stmt = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
if ($stmt) {
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $stmt->bind_result($uid);
  if ($stmt->fetch()) $exists = true;
  $stmt->close();
}

$createdUserId = null;
if (!$exists) {
  $initLimit = 300;
  if (table_exists($mysqli, 'app_settings')) {
    $stmt = $mysqli->prepare("SELECT v FROM app_settings WHERE k='initial_limit' LIMIT 1");
    if ($stmt) {
      $stmt->execute();
      $stmt->bind_result($v);
      if ($stmt->fetch()) $initLimit = max(0, (int)$v);
      $stmt->close();
    }
  }
  $hash = password_hash('GuruPintar123!', PASSWORD_BCRYPT);
  $role = 'user';
  $limitGambar = 5;
  $stmt = $mysqli->prepare("INSERT INTO users (username, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?)");
  if ($stmt) {
    $stmt->bind_param('sssii', $email, $hash, $role, $initLimit, $limitGambar);
    if ($stmt->execute()) $createdUserId = (int)$stmt->insert_id;
    $stmt->close();
  }
}

if ($uniqueId !== '') {
  $stmt = $mysqli->prepare("UPDATE scalev_webhook_events SET email=?, payment_status=?, created_user_id=?, processed_at=NOW() WHERE unique_id=?");
  if ($stmt) {
    $uidVal = $createdUserId;
    if ($uidVal === null) {
      $stmt2 = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
      if ($stmt2) {
        $stmt2->bind_param('s', $email);
        $stmt2->execute();
        $stmt2->bind_result($uid2);
        if ($stmt2->fetch()) $uidVal = (int)$uid2;
        $stmt2->close();
      }
    }
    $uidInt = $uidVal === null ? null : (int)$uidVal;
    $stmt->bind_param('ssis', $email, $paymentStatus, $uidInt, $uniqueId);
    $stmt->execute();
    $stmt->close();
  }
}

respond_ok(['handled' => 'created_or_exists', 'username' => $email]);
