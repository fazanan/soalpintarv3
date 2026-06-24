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

function column_exists(mysqli $db, string $table, string $column): bool {
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

function extract_customer_phone(array $data): string {
  $candidates = [];
  if (isset($data['destination_address']) && is_array($data['destination_address'])) {
    $da = $data['destination_address'];
    $candidates[] = (string)($da['phone'] ?? '');
    $candidates[] = (string)($da['phone_number'] ?? '');
    $candidates[] = (string)($da['mobile'] ?? '');
  }
  if (isset($data['customer']) && is_array($data['customer'])) {
    $candidates[] = (string)($data['customer']['phone'] ?? '');
    $candidates[] = (string)($data['customer']['phone_number'] ?? '');
    $candidates[] = (string)($data['customer']['mobile'] ?? '');
  }
  $candidates[] = (string)($data['customer_phone'] ?? '');
  $candidates[] = (string)($data['phone'] ?? '');
  $candidates[] = (string)($data['phone_number'] ?? '');
  $candidates[] = (string)($data['mobile'] ?? '');
  $candidates[] = (string)($data['destination_phone'] ?? '');
  $candidates[] = (string)($data['destination_phone_number'] ?? '');
  foreach ($candidates as $raw) {
    $s = trim((string)$raw);
    if ($s === '') continue;
    $s = (string)preg_replace('/[^0-9+()\\-\\s]/', '', $s);
    $s = trim(preg_replace('/\\s+/', ' ', $s) ?? '');
    if ($s === '') continue;
    if (strlen($s) > 32) $s = substr($s, 0, 32);
    return $s;
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

function normalize_phone_id(string $raw): string {
  $s = trim($raw);
  if ($s === '') return '';
  $s = preg_replace('/[^0-9+]/', '', $s) ?? '';
  $s = trim($s);
  if ($s === '') return '';
  if (str_starts_with($s, '+')) $s = substr($s, 1);
  if (str_starts_with($s, '0')) $s = '62' . substr($s, 1);
  else if (str_starts_with($s, '8')) $s = '62' . $s;
  if (strlen($s) > 32) $s = substr($s, 0, 32);
  return $s;
}

function get_whapify_settings(mysqli $db): ?array {
  if (!table_exists($db, 'whapify_settings')) return null;
  $stmt = $db->prepare("SELECT endpoint_url, secret, account FROM whapify_settings WHERE is_active=1 ORDER BY id DESC LIMIT 1");
  if (!$stmt) return null;
  $stmt->execute();
  $stmt->bind_result($endpointUrl, $secret, $account);
  $row = null;
  if ($stmt->fetch()) {
    $row = [
      'endpoint_url' => (string)$endpointUrl,
      'secret' => (string)$secret,
      'account' => (string)$account,
    ];
  }
  $stmt->close();
  if (!$row) return null;
  $row['endpoint_url'] = trim($row['endpoint_url']);
  $row['secret'] = trim($row['secret']);
  $row['account'] = trim($row['account']);
  if ($row['endpoint_url'] === '' || $row['secret'] === '' || $row['account'] === '') return null;
  return $row;
}

function send_whapify_text(string $endpointUrl, string $secret, string $account, string $recipient, string $message): array {
  if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'curl_missing'];
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $endpointUrl);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
  curl_setopt($ch, CURLOPT_TIMEOUT, 8);
  $fields = [
    'secret' => $secret,
    'account' => $account,
    'recipient' => $recipient,
    'type' => 'text',
    'message' => $message,
  ];
  curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
  $body = curl_exec($ch);
  $err = curl_error($ch);
  $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  $bodyStr = is_string($body) ? $body : '';
  $ok = $http >= 200 && $http < 300;
  if ($ok) {
    $decoded = json_decode($bodyStr, true);
    if (is_array($decoded) && isset($decoded['status'])) {
      $statusVal = $decoded['status'];
      if (is_int($statusVal) || ctype_digit((string)$statusVal)) {
        $ok = ((int)$statusVal) >= 200 && ((int)$statusVal) < 300;
      }
    }
  }
  return ['ok' => $ok, 'http' => $http, 'body' => $bodyStr, 'error' => $err ?: ''];
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
  products_text TEXT NULL,
  UNIQUE KEY uniq_unique_id (unique_id),
  INDEX idx_event_received (event, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
try { $mysqli->query("ALTER TABLE scalev_webhook_events ADD COLUMN products_text TEXT NULL"); } catch (Exception $e) {}

$mysqli->query("CREATE TABLE IF NOT EXISTS whapify_notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id VARCHAR(64) NOT NULL,
  username VARCHAR(160) NULL,
  user_id INT UNSIGNED NULL,
  recipient VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  http_status INT NULL,
  response_text TEXT NULL,
  error_text TEXT NULL,
  sent_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_order_id (order_id),
  INDEX idx_status_created (status, created_at),
  INDEX idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$productsText = '';
if (isset($data['orderlines']) && is_array($data['orderlines'])) {
  foreach ($data['orderlines'] as $line) {
    if (isset($line['product_name'])) $productsText .= ' ' . $line['product_name'];
  }
}
if (isset($data['lines']) && is_array($data['lines'])) {
  foreach ($data['lines'] as $line) {
    if (isset($line['product']['name'])) $productsText .= ' ' . $line['product']['name'];
    elseif (isset($line['name'])) $productsText .= ' ' . $line['name'];
  }
}
if (isset($data['items']) && is_array($data['items'])) {
  foreach ($data['items'] as $item) {
    if (isset($item['product']['name'])) $productsText .= ' ' . $item['product']['name'];
    elseif (isset($item['name'])) $productsText .= ' ' . $item['name'];
    elseif (isset($item['product_name'])) $productsText .= ' ' . $item['product_name'];
  }
}
if (isset($data['products']) && is_array($data['products'])) {
  foreach ($data['products'] as $p) {
    if (isset($p['name'])) $productsText .= ' ' . $p['name'];
    elseif (is_string($p)) $productsText .= ' ' . $p;
  }
}
if (isset($data['product']['name'])) {
  $productsText .= ' ' . $data['product']['name'];
}
if (isset($data['message_variables']['items_name'])) {
  $productsText .= ' ' . $data['message_variables']['items_name'];
}
$productsText = strtolower(trim($productsText));

if ($uniqueId !== '') {
  $stmt = $mysqli->prepare("INSERT IGNORE INTO scalev_webhook_events (unique_id, event, order_id, email, payment_status, products_text) VALUES (?,?,?,?,?,?)");
  if ($stmt) {
    $orderIdIns = isset($data['order_id']) ? (string)$data['order_id'] : '';
    $emailIns = extract_customer_email($data);
    $payIns = isset($data['payment_status']) ? (string)$data['payment_status'] : '';
    $stmt->bind_param('ssssss', $uniqueId, $event, $orderIdIns, $emailIns, $payIns, $productsText);
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
  $hash = password_hash('GuruPintar', PASSWORD_BCRYPT);
  $role = 'user';
  $limitGambar = 0;
  $noHp = extract_customer_phone($data);
  
  if ($productsText === '') {
    $orderIdIns = isset($data['order_id']) ? (string)$data['order_id'] : '';
    if ($orderIdIns !== '') {
      $stmtQuery = $mysqli->prepare("SELECT products_text FROM scalev_webhook_events WHERE order_id=? AND event='order.created' AND products_text IS NOT NULL LIMIT 1");
      if ($stmtQuery) {
        $stmtQuery->bind_param('s', $orderIdIns);
        $stmtQuery->execute();
        $stmtQuery->bind_result($pt);
        if ($stmtQuery->fetch() && $pt !== null) {
          $productsText = (string)$pt;
        }
        $stmtQuery->close();
      }
    }
  }
  
  // Debug log to a local file so we can inspect the payload if it fails again
  @file_put_contents(__DIR__ . '/scalev_last_payload.json', json_encode(['products_text' => $productsText, 'data' => $data], JSON_PRETTY_PRINT));
  
  $cleanProductsText = preg_replace('/[^a-z0-9]/', '', strtolower($productsText));
  $isEducomicBasic = strpos($cleanProductsText, 'komikedukasibasic') !== false;
  $isEducomicPro = strpos($cleanProductsText, 'komikedukasipro') !== false;
  $isGuruPintarBasic = strpos($cleanProductsText, 'gurupintarbasic') !== false;
  $isGuruPintarPro = strpos($cleanProductsText, 'gurupintarpro') !== false;
  
  $cols = ['username', 'password', 'role', 'limitpaket', 'limitgambar'];
  $types = 'sssii';
  $vals = [$email, $hash, $role, $initLimit, $limitGambar];
  
  $hasNoHp = column_exists($mysqli, 'users', 'no_hp');
  $stmt = $hasNoHp
    ? $mysqli->prepare("INSERT INTO users (username, no_hp, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?, ?)")
    : $mysqli->prepare("INSERT INTO users (username, password, role, limitpaket, limitgambar) VALUES (?, ?, ?, ?, ?)");
  if ($stmt) {
    if ($hasNoHp) $stmt->bind_param('ssssii', $email, $noHp, $hash, $role, $initLimit, $limitGambar);
    else $stmt->bind_param('sssii', $email, $hash, $role, $initLimit, $limitGambar);
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

$uidFinal = null;
$stmt = $mysqli->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
if ($stmt) {
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $stmt->bind_result($uidTmp);
  if ($stmt->fetch()) $uidFinal = (int)$uidTmp;
  $stmt->close();
}

if ($uidFinal !== null && ($isEducomicBasic || $isEducomicPro || $isGuruPintarBasic || $isGuruPintarPro)) {
  $isNewUser = ($createdUserId !== null);
  $accessUpdates = [];
  
  // EDUCOMIC
  if ($isEducomicBasic || $isEducomicPro) {
    if (column_exists($mysqli, 'users', 'access_bahan_ajar_komik')) $accessUpdates['access_bahan_ajar_komik'] = 1;
    if ($isEducomicPro) {
      if (column_exists($mysqli, 'users', 'access_bahan_ajar_slide')) $accessUpdates['access_bahan_ajar_slide'] = 1;
      if (column_exists($mysqli, 'users', 'access_lkpd_interaktif')) $accessUpdates['access_lkpd_interaktif'] = 1;
    }
  }
  
  // GURU PINTAR
  if ($isGuruPintarBasic || $isGuruPintarPro) {
    if (column_exists($mysqli, 'users', 'access_buat_soal')) $accessUpdates['access_buat_soal'] = 1;
    if ($isGuruPintarPro) {
      if (column_exists($mysqli, 'users', 'access_modul_ajar')) $accessUpdates['access_modul_ajar'] = 1;
      if (column_exists($mysqli, 'users', 'access_rpp')) $accessUpdates['access_rpp'] = 1;
      if (column_exists($mysqli, 'users', 'access_quiz')) $accessUpdates['access_quiz'] = 1;
      if (column_exists($mysqli, 'users', 'access_rekap_nilai')) $accessUpdates['access_rekap_nilai'] = 1;
    }
  }
  
  // NEW USER DEFAULTS
  if ($isNewUser) {
    $defaultsToZero = [];
    if ($isEducomicBasic || $isEducomicPro) {
      $defaultsToZero = array_merge($defaultsToZero, ['access_buat_soal', 'access_modul_ajar', 'access_rpp', 'access_quiz', 'access_rekap_nilai']);
    }
    if ($isGuruPintarBasic || $isGuruPintarPro) {
      $defaultsToZero = array_merge($defaultsToZero, ['access_bahan_ajar_komik', 'access_bahan_ajar_slide', 'access_lkpd_interaktif']);
      if ($isGuruPintarBasic && !$isGuruPintarPro) {
        $defaultsToZero = array_merge($defaultsToZero, ['access_modul_ajar', 'access_rpp', 'access_quiz', 'access_rekap_nilai']);
      }
    }
    foreach ($defaultsToZero as $col) {
      if (!isset($accessUpdates[$col]) && column_exists($mysqli, 'users', $col)) {
        $accessUpdates[$col] = 0;
      }
    }
  }
  
  if (!empty($accessUpdates)) {
    $upCols = [];
    $upTypes = '';
    $upVals = [];
    foreach ($accessUpdates as $col => $val) {
      $upCols[] = "$col=?";
      $upTypes .= 'i';
      $upVals[] = $val;
    }
    $upTypes .= 'i';
    $upVals[] = $uidFinal;
    $sql = "UPDATE users SET " . implode(', ', $upCols) . " WHERE id=?";
    $stmt = $mysqli->prepare($sql);
    if ($stmt) {
      $bind = [];
      $bind[] = $upTypes;
      foreach ($upVals as $i => $v) $bind[] = &$upVals[$i];
      call_user_func_array([$stmt, 'bind_param'], $bind);
      $stmt->execute();
      $stmt->close();
    }
  }
}

$orderId = isset($data['order_id']) ? (string)$data['order_id'] : '';
if ($orderId === '') $orderId = $uniqueId !== '' ? $uniqueId : '';

$waStatus = 'skipped';
$waHttp = null;
if ($orderId !== '' && table_exists($mysqli, 'whapify_notifications')) {
  $hasNoHp = column_exists($mysqli, 'users', 'no_hp');
  $userPhone = '';
  if ($hasNoHp) {
    $stmt = $mysqli->prepare("SELECT no_hp FROM users WHERE username=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $email);
      $stmt->execute();
      $stmt->bind_result($hp);
      if ($stmt->fetch()) $userPhone = (string)$hp;
      $stmt->close();
    }
  }
  $recipient = normalize_phone_id($userPhone !== '' ? $userPhone : extract_customer_phone($data));

  if ($recipient === '') {
    $waStatus = 'no_phone';
  } else {
    $stmt = $mysqli->prepare("INSERT IGNORE INTO whapify_notifications (order_id, username, user_id, recipient, status) VALUES (?,?,?,?, 'pending')");
    if ($stmt) {
      $uidBind = $uidFinal === null ? null : (int)$uidFinal;
      $stmt->bind_param('ssis', $orderId, $email, $uidBind, $recipient);
      $stmt->execute();
      $inserted = $stmt->affected_rows;
      $stmt->close();
      if ($inserted === 0) {
        $waStatus = 'duplicate';
      } else {
        $cfg = get_whapify_settings($mysqli);
        if (!$cfg) {
          $waStatus = 'no_config';
          $stmt = $mysqli->prepare("UPDATE whapify_notifications SET status='failed', error_text=?, updated_at=NOW() WHERE order_id=?");
          if ($stmt) {
            $err = 'whapify_config_missing';
            $stmt->bind_param('ss', $err, $orderId);
            $stmt->execute();
            $stmt->close();
          }
        } else {
          $loginLink = 'https://pinterin.my.id/login.php';
          $msg = "Halo Bapak/Ibu Guru,\n\n✅ Pembayaran Anda telah berhasil kami terima.\n\n🔗 Silakan login melalui tautan berikut: {$loginLink}\n👤 Username: {$email}\n🔐 Password: GuruPintar";
          if ($recipient !== '') $msg .= "\n\n📱 Apabila diperlukan, Anda juga dapat login menggunakan nomor HP: {$recipient}.";
          $msg .= "\n\n🛡️ Demi keamanan akun, kami menyarankan Anda segera mengganti password setelah login dan menggunakan password yang mudah diingat namun tetap aman.\n\n*Terimakasih*\n*Admin GuruPintar*";

          $res = send_whapify_text($cfg['endpoint_url'], $cfg['secret'], $cfg['account'], $recipient, $msg);
          $waHttp = (int)($res['http'] ?? 0);
          if (!empty($res['ok'])) {
            $waStatus = 'sent';
            $stmt = $mysqli->prepare("UPDATE whapify_notifications SET status='sent', http_status=?, response_text=?, sent_at=NOW(), updated_at=NOW() WHERE order_id=?");
            if ($stmt) {
              $body = (string)($res['body'] ?? '');
              $stmt->bind_param('iss', $waHttp, $body, $orderId);
              $stmt->execute();
              $stmt->close();
            }
          } else {
            $waStatus = 'failed';
            $stmt = $mysqli->prepare("UPDATE whapify_notifications SET status='failed', http_status=?, response_text=?, error_text=?, updated_at=NOW() WHERE order_id=?");
            if ($stmt) {
              $body = (string)($res['body'] ?? '');
              $err = (string)($res['error'] ?? '');
              if ($err === '' && $waHttp >= 400) $err = 'http_' . $waHttp;
              $stmt->bind_param('isss', $waHttp, $body, $err, $orderId);
              $stmt->execute();
              $stmt->close();
            }
          }
        }
      }
    }
  }
}

respond_ok(['handled' => 'created_or_exists', 'username' => $email, 'wa' => $waStatus, 'wa_http' => $waHttp]);
