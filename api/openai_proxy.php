<?php
declare(strict_types=1);
session_start();
// #region debug-point S:dbg-send
$__DBG_URL = 'http://127.0.0.1:7777/event';
$__DBG_LOG_FILE = __DIR__ . '/../.dbg/trae-debug-log-regen-question-fails.ndjson';
function dbg_send(array $payload): void {
  try {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!$json) return;
    $ch = curl_init('http://127.0.0.1:7777/event');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
    @curl_exec($ch);
    @curl_close($ch);
  } catch (Throwable $e) {}
  try {
    $dir = dirname(__DIR__ . '/../.dbg/.keep');
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents(__DIR__ . '/../.dbg/trae-debug-log-regen-question-fails.ndjson', json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
  } catch (Throwable $e) {}
}
// #endregion
if (!isset($_SESSION['user_id'])) {
  // #region debug-point S:openai-proxy-unauth
  try {
    dbg_send([
      'sessionId' => 'regen-question-fails',
      'runId' => 'pre-fix',
      'hypothesisId' => 'S',
      'location' => 'api/openai_proxy.php',
      'msg' => '[DEBUG] openai_proxy unauthorized (no session user_id)',
      'data' => ['status' => 401, 'has_session_user_id' => false],
      'traceId' => 'proxy-unauth-' . (string)round(microtime(true) * 1000),
      'ts' => (int)round(microtime(true) * 1000),
    ]);
  } catch (Throwable $e) {}
  // #endregion
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}
$user_id = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$access_buat_soal = (int)($_SESSION['access_buat_soal'] ?? 1);
$access_modul_ajar = (int)($_SESSION['access_modul_ajar'] ?? 1);
$access_rpp = (int)($_SESSION['access_rpp'] ?? 1);
$isLockExempt = (int)($_SESSION['session_lock_exempt'] ?? 0) === 1;
require_once __DIR__ . '/../auth_lock.php';
$sid = session_id();
if ($user_id > 0 && $sid && $role !== 'admin' && !$isLockExempt) {
  $did = auth_lock_get_device_id();
  $fp = auth_lock_fingerprint();
  if (!auth_lock_touch($user_id, $sid, $did, $fp)) {
    // #region debug-point S:openai-proxy-device-lock
    try {
      dbg_send([
        'sessionId' => 'regen-question-fails',
        'runId' => 'pre-fix',
        'hypothesisId' => 'S',
        'location' => 'api/openai_proxy.php',
        'msg' => '[DEBUG] openai_proxy unauthorized (auth_lock_touch failed)',
        'data' => ['status' => 401, 'user_id' => $user_id, 'role' => $role, 'lock_exempt' => $isLockExempt ? 1 : 0],
        'traceId' => 'proxy-lock-' . (string)round(microtime(true) * 1000),
        'ts' => (int)round(microtime(true) * 1000),
      ]);
    } catch (Throwable $e) {}
    // #endregion
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
  }
}
session_write_close();

if (isset($_GET['ping'])) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

// #region debug-point S:openai-proxy-instrument
function dbg_event(string $hypothesisId, string $msg, array $data = [], ?string $traceId = null): void {
  try {
    $payload = [
      'sessionId' => 'regen-question-fails',
      'runId' => 'pre-fix',
      'hypothesisId' => $hypothesisId,
      'location' => 'api/openai_proxy.php',
      'msg' => '[DEBUG] ' . $msg,
      'data' => $data,
      'traceId' => $traceId ?: ('proxy-' . (string)round(microtime(true) * 1000)),
      'ts' => (int)round(microtime(true) * 1000),
    ];
    dbg_send($payload);
  } catch (Throwable $e) {}
}
// #endregion

function read_json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function has_column(mysqli $db, string $table, string $column): bool {
  static $cache = [];
  $table = $db->real_escape_string($table);
  $column = $db->real_escape_string($column);
  $k = $table . ':' . $column;
  if (array_key_exists($k, $cache)) return (bool)$cache[$k];
  $sql = "SHOW COLUMNS FROM `$table` LIKE '$column'";
  if ($res = $db->query($sql)) {
    $ok = $res->num_rows > 0;
    $res->close();
    $cache[$k] = $ok;
    return $ok;
  }
  $cache[$k] = false;
  return false;
}

function table_exists(mysqli $db, string $table): bool {
  static $cache = [];
  $table = $db->real_escape_string($table);
  if (array_key_exists($table, $cache)) return (bool)$cache[$table];
  $sql = "SHOW TABLES LIKE '$table'";
  if ($res = $db->query($sql)) {
    $ok = $res->num_rows > 0;
    $res->close();
    $cache[$table] = $ok;
    return $ok;
  }
  $cache[$table] = false;
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

function get_limits(mysqli $db, int $user_id): array {
  $hasPkg = has_column($db, 'users', 'limitpaket');
  $hasImg = has_column($db, 'users', 'limitgambar');
  $defaults = ['limitpaket' => 300, 'limitgambar' => 0];
  if (!$hasPkg && !$hasImg) return $defaults;
  $fields = [];
  if ($hasPkg) $fields[] = 'limitpaket';
  if ($hasImg) $fields[] = 'limitgambar';
  $sql = "SELECT " . implode(',', $fields) . " FROM users WHERE id=? LIMIT 1";
  $stmt = $db->prepare($sql);
  if (!$stmt) return $defaults;
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();
  return [
    'limitpaket' => isset($row['limitpaket']) ? (int)$row['limitpaket'] : $defaults['limitpaket'],
    'limitgambar' => isset($row['limitgambar']) ? (int)$row['limitgambar'] : $defaults['limitgambar'],
    'initial_limitpaket' => 300,
  ];
}

function decrement_package(mysqli $db, int $user_id): int {
  if (!has_column($db, 'users', 'limitpaket')) return 999999; // effectively unlimited if column missing
  $stmt = $db->prepare("UPDATE users SET limitpaket = IF(limitpaket > 0, limitpaket - 1, 0) WHERE id=?");
  $stmt->bind_param('i', $user_id);
  $stmt->execute();
  $stmt->close();
  $limits = get_limits($db, $user_id);
  return (int)$limits['limitpaket'];
}

function get_api_key(mysqli $db, string $provider, int $user_id): ?string {
  $sql = "SELECT api_key
            FROM api_keys
           WHERE provider = ?
             AND is_active = 1
             AND (created_by = ? OR created_by IS NULL)
        ORDER BY (created_by IS NOT NULL) DESC, updated_at DESC
           LIMIT 1";
  $stmt = $db->prepare($sql);
  if (!$stmt) return null;
  $stmt->bind_param('si', $provider, $user_id);
  $stmt->execute();
  $stmt->bind_result($key);
  if ($stmt->fetch()) {
    $stmt->close();
    return $key;
  }
  $stmt->close();
  return null;
}

function get_model_config(mysqli $db, string $model, string $modality): ?array {
  if (!table_exists($db, 'api_models')) return null;
  $sql = "SELECT provider, modality, model, endpoint_url, token_input_price, token_output_price, currency, currency_rate_to_idr, unit, supports_json_mode, max_input_tokens, max_output_tokens
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

function approx_token_to_char_limit(int $tokens): int {
  if ($tokens <= 0) return 0;
  $n = (int)floor($tokens * 4.0);
  if ($n < 0) $n = 0;
  return $n;
}

function clip_text_by_chars(string $text, int $maxChars): string {
  $s = trim($text);
  if ($maxChars <= 0) return $s;
  if (mb_strlen($s, 'UTF-8') <= $maxChars) return $s;
  $head = (int)min(2000, (int)floor($maxChars * 0.25));
  $tail = $maxChars - $head;
  if ($tail < 200) {
    $tail = (int)min($maxChars, 2000);
    $head = $maxChars - $tail;
  }
  $a = $head > 0 ? mb_substr($s, 0, $head, 'UTF-8') : '';
  $b = $tail > 0 ? mb_substr($s, -$tail, null, 'UTF-8') : '';
  return trim($a . "\n" . $b);
}

function clip_messages_to_char_limit(array $messages, int $maxChars): array {
  if ($maxChars <= 0) return $messages;
  $total = 0;
  $lens = [];
  foreach ($messages as $i => $m) {
    $c = is_array($m) ? (string)($m['content'] ?? '') : '';
    $l = mb_strlen($c, 'UTF-8');
    $lens[$i] = $l;
    $total += $l;
  }
  if ($total <= $maxChars) return $messages;
  $need = $total - $maxChars;
  for ($i = count($messages) - 1; $i >= 0 && $need > 0; $i--) {
    if (!isset($messages[$i]) || !is_array($messages[$i])) continue;
    $role = (string)($messages[$i]['role'] ?? '');
    if ($role !== 'user') continue;
    $c = (string)($messages[$i]['content'] ?? '');
    $l = mb_strlen($c, 'UTF-8');
    if ($l <= 0) continue;
    $drop = min($need, (int)floor($l * 0.6));
    if ($drop < 200) $drop = min($need, min(2000, $l));
    $keep = max(0, $l - $drop);
    $messages[$i]['content'] = $keep > 0 ? mb_substr($c, -$keep, null, 'UTF-8') : '';
    $need -= $drop;
  }
  return $messages;
}

function proxy_chat(mysqli $db, array $payload, int $user_id) {
  $traceId = 'chat-' . (string)round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3));
  $t0 = microtime(true);
  $prompt = trim((string)($payload['prompt'] ?? ''));
  $model  = (string)($payload['model'] ?? 'gpt-4o-mini');
  dbg_event('S', 'proxy_chat entry', [
    'user_id' => $user_id,
    'model' => $model,
    'prompt_len' => strlen($prompt),
    'has_prompt' => $prompt !== '' ? 1 : 0,
  ], $traceId);
  if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt kosong']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'Prompt kosong', 400, []);
    dbg_event('S', 'proxy_chat reject: empty prompt', ['status' => 400], $traceId);
    return;
  }
  $cfg = get_model_config($db, $model, 'chat');
  $maxInCfg = (int)($cfg['max_input_tokens'] ?? 0);
  $maxOutCfg = (int)($cfg['max_output_tokens'] ?? 0);
  if ($maxInCfg > 0) {
    $prompt = clip_text_by_chars($prompt, approx_token_to_char_limit($maxInCfg));
  }
  $maxTokens = (int)($payload['max_tokens'] ?? 0);
  if ($maxTokens <= 0) $maxTokens = $maxOutCfg > 0 ? $maxOutCfg : 12000;
  if ($maxOutCfg > 0 && $maxTokens > $maxOutCfg) $maxTokens = $maxOutCfg;
  if ($maxTokens < 64) $maxTokens = 64;
  $provider = $cfg['provider'] ?? 'openai';
  $api_key = get_api_key($db, $provider, $user_id);
  if (!$api_key) {
    http_response_code(500);
    echo json_encode(['error' => 'API key tidak tersedia. Tambahkan ke tabel api_keys.']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'API key tidak tersedia', 500, ['provider'=>$provider]);
    dbg_event('S', 'proxy_chat error: api_key missing', ['status' => 500, 'provider' => $provider], $traceId);
    return;
  }

  $url = $cfg['endpoint_url'] ?? 'https://api.openai.com/v1/chat/completions';
  $postData = json_encode([
    'model' => $model,
    'messages' => [
      ['role' => 'system', 'content' => 'You are a helpful assistant designed to output JSON.'],
      ['role' => 'user', 'content' => $prompt],
    ],
    'response_format' => ['type' => 'json_object'],
    'temperature' => 0.7,
    'max_tokens' => $maxTokens,
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key,
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  curl_setopt($ch, CURLOPT_TIMEOUT, 60);
  $result = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($result === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenAI error', 'status' => $status, 'detail' => $err ?: $result]);
    log_audit($db, $user_id, 'error', 'openai_chat', 'HTTP error dari OpenAI', $status ?: 502, ['error'=>$err, 'result'=>$result]);
    dbg_event('S', 'proxy_chat upstream error', [
      'status' => 502,
      'upstream_status' => $status,
      'curl_error' => $err ? 1 : 0,
      'ms' => (int)round((microtime(true) - $t0) * 1000),
    ], $traceId);
    return;
  }
  $decoded = json_decode($result, true);
  $content = $decoded['choices'][0]['message']['content'] ?? '{}';
  $parsed = json_decode($content, true);
  if (!is_array($parsed)) {
    http_response_code(500);
    echo json_encode(['error' => 'Respons tidak valid dari OpenAI']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'Respons tidak valid dari OpenAI', 500, ['decoded'=>$decoded]);
    dbg_event('S', 'proxy_chat parse error (content not json)', [
      'status' => 500,
      'ms' => (int)round((microtime(true) - $t0) * 1000),
      'content_head' => substr((string)$content, 0, 180),
    ], $traceId);
    return;
  }
  $usage = $decoded['usage'] ?? [];
  $promptTokens = (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
  $completionTokens = (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
  $parsed['_usage'] = ['in' => $promptTokens, 'out' => $completionTokens];
  if ($cfg) {
    $inPrice = (float)($cfg['token_input_price'] ?? 0);
    $outPrice = (float)($cfg['token_output_price'] ?? 0);
    $currency = (string)($cfg['currency'] ?? 'USD');
    $fxToIdr = (float)($cfg['currency_rate_to_idr'] ?? 1.0);
    $unit = (string)($cfg['unit'] ?? 'per_1k_tokens');
    $inputCost = ($promptTokens / 1000.0) * $inPrice;
    $outputCost = ($completionTokens / 1000.0) * $outPrice;
    $parsed['_pricing'] = [
      'currency' => $currency,
      'currency_rate_to_idr' => $fxToIdr,
      'unit' => $unit,
      'input_price_per_unit' => $inPrice,
      'output_price_per_unit' => $outPrice,
      'input_cost' => $inputCost,
      'output_cost' => $outputCost,
      'total_cost' => $inputCost + $outputCost,
    ];
  }
  echo json_encode($parsed, JSON_UNESCAPED_UNICODE);
  dbg_event('S', 'proxy_chat ok', [
    'status' => 200,
    'ms' => (int)round((microtime(true) - $t0) * 1000),
    'keys' => array_slice(array_keys($parsed), 0, 12),
    'items_len' => isset($parsed['items']) && is_array($parsed['items']) ? count($parsed['items']) : 0,
  ], $traceId);
}

function proxy_image(mysqli $db, array $payload, int $user_id) {
  $prompt = trim((string)($payload['prompt'] ?? ''));
  $model  = (string)($payload['model'] ?? 'gpt-image-1');
  $size   = (string)($payload['size'] ?? '512x512');
  if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt kosong']);
    log_audit($db, $user_id, 'error', 'openai_image', 'Prompt kosong', 400, []);
    return;
  }
  $limits = get_limits($db, $user_id);
  if ($limits['limitgambar'] <= 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Batas pembuatan gambar habis']);
    log_audit($db, $user_id, 'warn', 'openai_image', 'Batas pembuatan gambar habis', 403, []);
    return;
  }
  $cfg = get_model_config($db, $model, 'image');
  $provider = $cfg['provider'] ?? 'openai';
  $api_key = get_api_key($db, $provider, $user_id);
  if (!$api_key) {
    http_response_code(500);
    echo json_encode(['error' => 'API key tidak tersedia. Tambahkan ke tabel api_keys.']);
    log_audit($db, $user_id, 'error', 'openai_image', 'API key tidak tersedia', 500, ['provider'=>$provider]);
    return;
  }

  $url = $cfg['endpoint_url'] ?? 'https://api.openai.com/v1/images/generations';
  $postData = json_encode([
    'model' => $model,
    'prompt' => $prompt,
    'n' => 1,
    'size' => $size,
    'response_format' => 'b64_json',
    'quality' => 'standard',
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $api_key,
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  curl_setopt($ch, CURLOPT_TIMEOUT, 90);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
  $result = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($result === false) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL error', 'detail' => $err ?: 'unknown']);
    log_audit($db, $user_id, 'error', 'openai_image', 'cURL error', 502, ['error'=>$err]);
    return;
  }
  if ($status < 200 || $status >= 300) {
    http_response_code($status);
    echo $result;
    log_audit($db, $user_id, 'error', 'openai_image', 'HTTP error dari OpenAI Images', $status, ['result'=>$result]);
    return;
  }
  // Decrement limit only on success
  if (has_column($db, 'users', 'limitgambar')) {
    $stmt = $db->prepare("UPDATE users SET limitgambar = IF(limitgambar > 0, limitgambar - 1, 0) WHERE id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
  }
  echo $result;
}

$data = read_json_input();
$type = (string)($data['type'] ?? 'chat');
$traceIdMain = 'req-' . (string)round(microtime(true) * 1000) . '-' . bin2hex(random_bytes(3));
dbg_event('S', 'request entry', [
  'user_id' => $user_id,
  'role' => $role,
  'type' => $type,
  'has_prompt' => isset($data['prompt']) ? 1 : 0,
  'prompt_len' => isset($data['prompt']) ? strlen((string)$data['prompt']) : 0,
  'model' => isset($data['model']) ? (string)$data['model'] : '',
], $traceIdMain);

if ($role !== 'admin') {
  if ($type === 'chat' && $access_buat_soal === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    dbg_event('S', 'forbidden: access_buat_soal=0', ['status' => 403, 'type' => $type, 'user_id' => $user_id], $traceIdMain);
    exit;
  }
  if ($type === 'modul_ajar' && $access_modul_ajar === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    dbg_event('S', 'forbidden: access_modul_ajar=0', ['status' => 403, 'type' => $type, 'user_id' => $user_id], $traceIdMain);
    exit;
  }
  if ($type === 'rpp' && $access_rpp === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    dbg_event('S', 'forbidden: access_rpp=0', ['status' => 403, 'type' => $type, 'user_id' => $user_id], $traceIdMain);
    exit;
  }
}

if ($type === 'chat') {
  proxy_chat($mysqli, $data, $user_id);
  exit;
}
if ($type === 'modul_ajar') {
  // Full messages array, returns plain text (markdown), no JSON mode
  @set_time_limit(0);
  $messages = $data['messages'] ?? [];
  $model    = (string)($data['model'] ?? 'gpt-4o-mini');
  $maxTokens = (int)($data['max_tokens'] ?? 12000);
  if ($maxTokens < 1200) $maxTokens = 1200;
  if ($maxTokens > 12000) $maxTokens = 12000;
  if (empty($messages)) {
    http_response_code(400); echo json_encode(['error'=>'Messages kosong']); exit;
  }
  $cfg     = get_model_config($mysqli, $model, 'chat');
  $maxInCfg = (int)($cfg['max_input_tokens'] ?? 0);
  $maxOutCfg = (int)($cfg['max_output_tokens'] ?? 0);
  if ($maxOutCfg > 0 && $maxTokens > $maxOutCfg) $maxTokens = $maxOutCfg;
  if ($maxInCfg > 0) {
    $messages = clip_messages_to_char_limit($messages, approx_token_to_char_limit($maxInCfg));
  }
  $provider= $cfg['provider'] ?? 'openai';
  $api_key = get_api_key($mysqli, $provider, $user_id);
  if (!$api_key) {
    http_response_code(500); echo json_encode(['error'=>'API key tidak tersedia']); exit;
  }
  $url      = $cfg['endpoint_url'] ?? 'https://api.openai.com/v1/chat/completions';
  $postData = json_encode([
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => 0.7,
    'max_tokens'  => $maxTokens,
  ], JSON_UNESCAPED_UNICODE);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer '.$api_key,
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
  curl_setopt($ch, CURLOPT_TIMEOUT, 600);
  $result = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err    = curl_error($ch);
  curl_close($ch);
  if ($result===false || $status<200 || $status>=300) {
    http_response_code(502);
    echo json_encode(['error'=>'OpenAI error','status'=>$status,'detail'=>$err?:$result]);
    log_audit($mysqli,$user_id,'error','modul_ajar','HTTP error dari OpenAI',$status?:502,['err'=>$err]);
    exit;
  }
  $decoded = json_decode($result, true);
  $content = $decoded['choices'][0]['message']['content'] ?? '';
  $usage   = $decoded['usage'] ?? [];
  log_audit($mysqli,$user_id,'info','modul_ajar','Modul Ajar generated',200,['model'=>$model,'tokens'=>$usage]);
  echo json_encode(['content'=>$content,'choices'=>[['message'=>['content'=>$content]]],'usage'=>$usage], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($type === 'rpp') {
  $messages = $data['messages'] ?? [];
  $model    = (string)($data['model'] ?? 'gpt-4o-mini');
  if (empty($messages)) {
    http_response_code(400); echo json_encode(['error'=>'Messages kosong']); exit;
  }
  $cfg     = get_model_config($mysqli, $model, 'chat');
  $maxInCfg = (int)($cfg['max_input_tokens'] ?? 0);
  $maxOutCfg = (int)($cfg['max_output_tokens'] ?? 0);
  if ($maxInCfg > 0) {
    $messages = clip_messages_to_char_limit($messages, approx_token_to_char_limit($maxInCfg));
  }
  $provider= $cfg['provider'] ?? 'openai';
  $api_key = get_api_key($mysqli, $provider, $user_id);
  if (!$api_key) {
    http_response_code(500); echo json_encode(['error'=>'API key tidak tersedia']); exit;
  }
  $url      = $cfg['endpoint_url'] ?? 'https://api.openai.com/v1/chat/completions';
  $maxTokens = $maxOutCfg > 0 ? min(4000, $maxOutCfg) : 4000;
  $postData = json_encode([
    'model'       => $model,
    'messages'    => $messages,
    'temperature' => 0.7,
    'max_tokens'  => $maxTokens,
  ], JSON_UNESCAPED_UNICODE);
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer '.$api_key,
  ]);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);
  $result = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err    = curl_error($ch);
  curl_close($ch);
  if ($result===false || $status<200 || $status>=300) {
    http_response_code(502);
    echo json_encode(['error'=>'OpenAI error','status'=>$status,'detail'=>$err?:$result]);
    log_audit($mysqli,$user_id,'error','rpp','HTTP error dari OpenAI',$status?:502,['err'=>$err]);
    exit;
  }
  $decoded = json_decode($result, true);
  $content = $decoded['choices'][0]['message']['content'] ?? '';
  $usage   = $decoded['usage'] ?? [];
  log_audit($mysqli,$user_id,'info','rpp','RPP generated',200,['model'=>$model,'tokens'=>$usage]);
  echo json_encode(['content'=>$content,'choices'=>[['message'=>['content'=>$content]]],'usage'=>$usage], JSON_UNESCAPED_UNICODE);
  exit;
}
if ($type === 'image') {
  proxy_image($mysqli, $data, $user_id);
  exit;
}
if ($type === 'get_limits') {
  echo json_encode(get_limits($mysqli, $user_id));
  exit;
}
if ($type === 'decrement_package') {
  $remain = decrement_package($mysqli, $user_id);
  echo json_encode(['limitpaket' => $remain]);
  exit;
}
if ($type === 'add_tokens') {
  $in = (int)($data['input_tokens'] ?? 0);
  $out = (int)($data['output_tokens'] ?? 0);
  $ok = true;
  if (has_column($mysqli, 'users', 'token_input') && has_column($mysqli, 'users', 'token_output')) {
    $stmt = $mysqli->prepare("UPDATE users SET token_input = token_input + ?, token_output = token_output + ? WHERE id=?");
    if ($stmt) {
      $stmt->bind_param('iii', $in, $out, $user_id);
      $ok = $stmt->execute();
      $stmt->close();
    } else {
      $ok = false;
    }
  } else {
    $ok = false;
  }
  echo json_encode(['ok' => $ok, 'input_tokens' => $in, 'output_tokens' => $out]);
  exit;
}

http_response_code(400);
echo json_encode(['error' => 'Tipe permintaan tidak dikenali']);
