<?php
declare(strict_types=1);

function gsheet_base64url(string $data): string {
  return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function gsheet_read_json_file(string $path): ?array {
  if (!is_file($path)) return null;
  $raw = @file_get_contents($path);
  if (!is_string($raw) || $raw === '') return null;
  if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
  $raw = trim($raw);
  if ($raw === '') return null;
  $j = json_decode($raw, true);
  return is_array($j) ? $j : null;
}

function gsheet_write_json_file(string $path, array $data): bool {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0777, true) && !is_dir($dir)) return false;
  }
  $json = json_encode($data, JSON_UNESCAPED_UNICODE);
  if (!is_string($json) || $json === '') return false;
  return @file_put_contents($path, $json) !== false;
}

function gsheet_http_json(string $method, string $url, array $headers = [], ?array $body = null): array {
  if (function_exists('set_time_limit')) { @set_time_limit(60); }
  if (function_exists('ini_set')) { @ini_set('max_execution_time', '60'); }
  $ch = curl_init($url);
  $outHeaders = array_values(array_filter($headers, fn($h) => is_string($h) && $h !== ''));
  if ($body !== null) {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) $json = '{}';
    $outHeaders[] = 'Content-Type: application/json; charset=utf-8';
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  }
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $outHeaders);
  curl_setopt($ch, CURLOPT_NOSIGNAL, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $raw = is_string($resp) ? $resp : '';
  $j = null;
  if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $j = $tmp;
  }
  return [
    'ok' => $errno === 0 && $status >= 200 && $status < 300,
    'status' => $status,
    'errno' => $errno,
    'error' => $errno !== 0 ? $err : null,
    'raw' => $raw,
    'json' => $j,
  ];
}

function gsheet_http_form(string $url, array $data): array {
  if (function_exists('set_time_limit')) { @set_time_limit(60); }
  if (function_exists('ini_set')) { @ini_set('max_execution_time', '60'); }
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded; charset=utf-8']);
  curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
  curl_setopt($ch, CURLOPT_NOSIGNAL, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
  curl_setopt($ch, CURLOPT_TIMEOUT, 20);
  $resp = curl_exec($ch);
  $errno = curl_errno($ch);
  $err = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $raw = is_string($resp) ? $resp : '';
  $j = null;
  if ($raw !== '') {
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $j = $tmp;
  }
  return [
    'ok' => $errno === 0 && $status >= 200 && $status < 300,
    'status' => $status,
    'errno' => $errno,
    'error' => $errno !== 0 ? $err : null,
    'raw' => $raw,
    'json' => $j,
  ];
}

function gsheet_find_service_account_paths(): array {
  $cwd = getcwd();
  if (!is_string($cwd) || $cwd === '') $cwd = null;
  $candidates = [
    __DIR__ . '/../storage/gsheet/service_account.json',
    __DIR__ . '/../project/storage/gsheet/service_account.json',
    dirname(__DIR__) . '/storage/gsheet/service_account.json',
    dirname(__DIR__) . '/project/storage/gsheet/service_account.json',
  ];
  if ($cwd) {
    $candidates[] = $cwd . '/storage/gsheet/service_account.json';
    $candidates[] = $cwd . '/project/storage/gsheet/service_account.json';
  }
  foreach ($candidates as $p) {
    if (is_file($p)) {
      $dir = dirname($p);
      return [
        'ok' => true,
        'service_account_path' => $p,
        'token_cache_path' => $dir . '/token_cache.json',
      ];
    }
  }
  return [
    'ok' => false,
    'candidates' => $candidates,
  ];
}

function gsheet_get_access_token(): array {
  $paths = gsheet_find_service_account_paths();
  $saPath = (string)($paths['service_account_path'] ?? (__DIR__ . '/../storage/gsheet/service_account.json'));
  $cachePath = (string)($paths['token_cache_path'] ?? (__DIR__ . '/../storage/gsheet/token_cache.json'));

  $cache = gsheet_read_json_file($cachePath);
  if (is_array($cache)) {
    $token = (string)($cache['access_token'] ?? '');
    $exp = (int)($cache['expires_at'] ?? 0);
    if ($token !== '' && $exp > 0 && time() < ($exp - 120)) {
      return ['ok' => true, 'access_token' => $token, 'expires_at' => $exp, 'cached' => true];
    }
  }

  $sa = gsheet_read_json_file($saPath);
  if (!is_array($sa)) {
    $msg = 'service_account.json tidak ditemukan atau invalid';
    $cands = is_array($paths['candidates'] ?? null) ? $paths['candidates'] : [];
    if ($cands) {
      $shown = array_slice(array_values(array_filter($cands, fn($x) => is_string($x) && $x !== '')), 0, 6);
      if ($shown) $msg .= ' | cek: ' . implode(' , ', $shown);
    }
    return [
      'ok' => false,
      'error' => 'service_account_missing',
      'message' => $msg,
      'path' => $saPath,
      'candidates' => $paths['candidates'] ?? null,
    ];
  }
  $clientEmail = trim((string)($sa['client_email'] ?? ''));
  $privateKey = (string)($sa['private_key'] ?? '');
  $tokenUri = trim((string)($sa['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
  if ($clientEmail === '' || $privateKey === '') {
    return ['ok' => false, 'error' => 'service_account_invalid', 'message' => 'client_email/private_key tidak lengkap'];
  }

  $now = time();
  $header = ['alg' => 'RS256', 'typ' => 'JWT'];
  $claims = [
    'iss' => $clientEmail,
    'scope' => 'https://www.googleapis.com/auth/spreadsheets',
    'aud' => $tokenUri,
    'iat' => $now,
    'exp' => $now + 3600,
  ];
  $h = gsheet_base64url((string)json_encode($header));
  $p = gsheet_base64url((string)json_encode($claims));
  $unsigned = $h . '.' . $p;
  $sig = '';
  $signOk = @openssl_sign($unsigned, $sig, $privateKey, OPENSSL_ALGO_SHA256);
  if (!$signOk) {
    return ['ok' => false, 'error' => 'jwt_sign_failed', 'message' => 'Gagal sign JWT (openssl_sign)'];
  }
  $jwt = $unsigned . '.' . gsheet_base64url($sig);

  $resp = gsheet_http_form($tokenUri, [
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion' => $jwt,
  ]);
  if (!$resp['ok']) {
    $msg = '';
    if (is_array($resp['json'])) {
      $msg = (string)($resp['json']['error_description'] ?? $resp['json']['error'] ?? '');
    }
    if ($msg === '') $msg = $resp['error'] ? (string)$resp['error'] : 'Token request gagal';
    return [
      'ok' => false,
      'error' => 'token_request_failed',
      'status' => (int)$resp['status'],
      'message' => $msg,
    ];
  }
  $j = is_array($resp['json']) ? $resp['json'] : [];
  $token = trim((string)($j['access_token'] ?? ''));
  $expiresIn = (int)($j['expires_in'] ?? 0);
  if ($token === '' || $expiresIn <= 0) {
    return ['ok' => false, 'error' => 'token_invalid_response', 'message' => 'Response token tidak valid'];
  }
  $expiresAt = time() + $expiresIn;
  gsheet_write_json_file($cachePath, ['access_token' => $token, 'expires_at' => $expiresAt, 'created_at' => time()]);
  return ['ok' => true, 'access_token' => $token, 'expires_at' => $expiresAt, 'cached' => false];
}

function gsheet_ensure_sheet(string $spreadsheetId, string $sheetName, $token): array {
  $spreadsheetId = trim($spreadsheetId);
  $sheetName = trim($sheetName);
  $accessToken = is_array($token) ? (string)($token['access_token'] ?? '') : (string)$token;
  if ($spreadsheetId === '' || $sheetName === '' || $accessToken === '') {
    return ['ok' => false, 'error' => 'invalid_input'];
  }

  $metaUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=sheets.properties';
  $meta = gsheet_http_json('GET', $metaUrl, ['Authorization: Bearer ' . $accessToken]);
  if (!$meta['ok']) {
    return ['ok' => false, 'error' => 'metadata_failed', 'status' => (int)$meta['status'], 'message' => 'Gagal membaca metadata spreadsheet'];
  }
  $sheets = is_array($meta['json']['sheets'] ?? null) ? $meta['json']['sheets'] : [];
  foreach ($sheets as $s) {
    $title = (string)($s['properties']['title'] ?? '');
    if ($title === $sheetName) return ['ok' => true, 'exists' => true];
  }

  $batchUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate';
  $res = gsheet_http_json('POST', $batchUrl, ['Authorization: Bearer ' . $accessToken], [
    'requests' => [
      ['addSheet' => ['properties' => ['title' => $sheetName]]],
    ],
  ]);
  if (!$res['ok']) {
    return ['ok' => false, 'error' => 'add_sheet_failed', 'status' => (int)$res['status'], 'message' => 'Gagal membuat sheet baru'];
  }
  return ['ok' => true, 'exists' => false, 'created' => true];
}

function gsheet_write_row(string $spreadsheetId, string $sheetName, int $row, array $values, $token): array {
  $spreadsheetId = trim($spreadsheetId);
  $sheetName = trim($sheetName);
  $accessToken = is_array($token) ? (string)($token['access_token'] ?? '') : (string)$token;
  if ($spreadsheetId === '' || $sheetName === '' || $row <= 0 || $accessToken === '') {
    return ['ok' => false, 'error' => 'invalid_input'];
  }
  $range = $sheetName . '!A' . $row;
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?valueInputOption=RAW';
  $res = gsheet_http_json('PUT', $url, ['Authorization: Bearer ' . $accessToken], [
    'range' => $range,
    'majorDimension' => 'ROWS',
    'values' => [array_values($values)],
  ]);
  if (!$res['ok']) {
    return ['ok' => false, 'error' => 'write_failed', 'status' => (int)$res['status'], 'message' => 'Gagal menulis baris'];
  }
  return ['ok' => true];
}

function gsheet_append_row(string $spreadsheetId, string $sheetName, array $values, $token): array {
  $spreadsheetId = trim($spreadsheetId);
  $sheetName = trim($sheetName);
  $accessToken = is_array($token) ? (string)($token['access_token'] ?? '') : (string)$token;
  if ($spreadsheetId === '' || $sheetName === '' || $accessToken === '') {
    return ['ok' => false, 'error' => 'invalid_input'];
  }
  $range = $sheetName . '!A:A';
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
  $res = gsheet_http_json('POST', $url, ['Authorization: Bearer ' . $accessToken], [
    'majorDimension' => 'ROWS',
    'values' => [array_values($values)],
  ]);
  if (!$res['ok']) {
    return ['ok' => false, 'error' => 'append_failed', 'status' => (int)$res['status'], 'message' => 'Gagal append baris'];
  }
  return ['ok' => true];
}

function gsheet_get_values(string $spreadsheetId, string $range, $token): array {
  $spreadsheetId = trim($spreadsheetId);
  $range = trim($range);
  $accessToken = is_array($token) ? (string)($token['access_token'] ?? '') : (string)$token;
  if ($spreadsheetId === '' || $range === '' || $accessToken === '') {
    return ['ok' => false, 'error' => 'invalid_input'];
  }
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?majorDimension=ROWS';
  $resp = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
  if (!($resp['ok'] ?? false) || !is_array($resp['json'])) {
    return ['ok' => false, 'error' => 'get_failed', 'status' => (int)($resp['status'] ?? 0)];
  }
  $vals = $resp['json']['values'] ?? [];
  return ['ok' => true, 'values' => is_array($vals) ? $vals : []];
}

function gsheet_find_row_in_column(string $spreadsheetId, string $sheetName, string $colLetter, string $needle, $token, int $startRow = 2, int $maxRows = 5000): array {
  $sheetName = trim($sheetName);
  $colLetter = strtoupper(trim($colLetter));
  $needle = trim($needle);
  if ($sheetName === '' || $colLetter === '' || $needle === '' || $startRow <= 0 || $maxRows <= 0) {
    return ['ok' => false, 'error' => 'invalid_input', 'row' => 0];
  }
  $endRow = $startRow + $maxRows - 1;
  $range = $sheetName . '!' . $colLetter . $startRow . ':' . $colLetter . $endRow;
  $res = gsheet_get_values($spreadsheetId, $range, $token);
  if (!($res['ok'] ?? false)) return ['ok' => false, 'error' => 'get_failed', 'row' => 0];
  $vals = is_array($res['values'] ?? null) ? $res['values'] : [];
  for ($i = 0; $i < count($vals); $i++) {
    $cell = is_array($vals[$i]) ? (string)($vals[$i][0] ?? '') : '';
    $cell = trim($cell);
    if ($cell === '') continue;
    if ($cell === $needle) return ['ok' => true, 'row' => $startRow + $i];
    if ((string)((int)$cell) === $needle) return ['ok' => true, 'row' => $startRow + $i];
  }
  return ['ok' => true, 'row' => 0];
}
