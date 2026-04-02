<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user_id'])) {
  http_response_code(401);
  header('Content-Type: application/json');
  echo json_encode(['error' => 'Unauthorized']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';

function read_json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

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

function get_limits(mysqli $db, int $user_id): array {
  $hasPkg = has_column($db, 'users', 'limitpaket');
  $hasImg = has_column($db, 'users', 'limitgambar');
  $defaults = ['limitpaket' => 300, 'limitgambar' => 5];
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
  $sql = "SELECT provider, modality, model, endpoint_url, token_input_price, token_output_price, currency, currency_rate_to_idr, unit, supports_json_mode
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

function proxy_chat(mysqli $db, array $payload, int $user_id) {
  $prompt = trim((string)($payload['prompt'] ?? ''));
  $model  = (string)($payload['model'] ?? 'gpt-4o-mini');
  if ($prompt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Prompt kosong']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'Prompt kosong', 400, []);
    return;
  }
  $cfg = get_model_config($db, $model, 'chat');
  $provider = $cfg['provider'] ?? 'openai';
  $api_key = get_api_key($db, $provider, $user_id);
  if (!$api_key) {
    http_response_code(500);
    echo json_encode(['error' => 'API key tidak tersedia. Tambahkan ke tabel api_keys.']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'API key tidak tersedia', 500, ['provider'=>$provider]);
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
    return;
  }
  $decoded = json_decode($result, true);
  $content = $decoded['choices'][0]['message']['content'] ?? '{}';
  $parsed = json_decode($content, true);
  if (!is_array($parsed)) {
    http_response_code(500);
    echo json_encode(['error' => 'Respons tidak valid dari OpenAI']);
    log_audit($db, $user_id, 'error', 'openai_chat', 'Respons tidak valid dari OpenAI', 500, ['decoded'=>$decoded]);
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
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($type === 'chat') {
  proxy_chat($mysqli, $data, $user_id);
  exit;
}
if ($type === 'modul_ajar') {
  // Full messages array, returns plain text (markdown), no JSON mode
  $messages = $data['messages'] ?? [];
  $model    = (string)($data['model'] ?? 'gpt-4o-mini');
  if (empty($messages)) {
    http_response_code(400); echo json_encode(['error'=>'Messages kosong']); exit;
  }
  $cfg     = get_model_config($mysqli, $model, 'chat');
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
    'max_tokens'  => 4000,
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
    'max_tokens'  => 4000,
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
