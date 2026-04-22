<?php
declare(strict_types=1);

function auth_lock_cookie_name(): string {
  return 'sp_device_id';
}

function auth_lock_is_https(): bool {
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') return true;
  return false;
}

function auth_lock_generate_device_id(): string {
  try {
    return bin2hex(random_bytes(16));
  } catch (Throwable $e) {
    return bin2hex((string)microtime(true) . '|' . (string)mt_rand());
  }
}

function auth_lock_get_device_id(): string {
  $name = auth_lock_cookie_name();
  $raw = isset($_COOKIE[$name]) ? (string)$_COOKIE[$name] : '';
  $raw = trim($raw);
  $ok = $raw !== '' && preg_match('/^[a-f0-9]{16,128}$/i', $raw);
  if ($ok) return strtolower($raw);
  $val = auth_lock_generate_device_id();
  $exp = time() + (365 * 24 * 60 * 60);
  $secure = auth_lock_is_https();
  if (!headers_sent()) {
    if (defined('PHP_VERSION_ID') && PHP_VERSION_ID >= 70300) {
      @setcookie($name, $val, [
        'expires' => $exp,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    } else {
      @setcookie($name, $val, $exp, '/', '', $secure, true);
    }
  }
  $_COOKIE[$name] = $val;
  return $val;
}

function auth_lock_ip_prefix(string $ip): string {
  $ip = trim($ip);
  if ($ip === '') return '';
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $parts = explode('.', $ip);
    if (count($parts) === 4) return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    return $ip;
  }
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    $bin = @inet_pton($ip);
    if ($bin === false) return $ip;
    return bin2hex(substr($bin, 0, 8));
  }
  return $ip;
}

function auth_lock_fingerprint(): string {
  $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
  $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
  $ua = strtolower(trim($ua));
  $ip = auth_lock_ip_prefix($ip);
  if ($ua === '' && $ip === '') return '';
  return hash('sha256', $ua . '|' . $ip);
}

function auth_lock_dir(): string {
  return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'session_locks';
}

function auth_lock_path(int $userId): string {
  return auth_lock_dir() . DIRECTORY_SEPARATOR . 'u' . $userId . '.lock';
}

function auth_lock_ttl_seconds(): int {
  return 15 * 60;
}

function auth_lock_ensure_dir(): void {
  $dir = auth_lock_dir();
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
}

function auth_lock_read_sid(string $path): string {
  $raw = @file_get_contents($path);
  return trim((string)$raw);
}

function auth_lock_read_record(string $path): array {
  $raw = @file_get_contents($path);
  $raw = trim((string)$raw);
  if ($raw === '') return ['session_id' => '', 'device_id' => '', 'fp' => ''];
  if (isset($raw[0]) && $raw[0] === '{') {
    $data = json_decode($raw, true);
    if (is_array($data)) {
      return [
        'session_id' => isset($data['session_id']) ? (string)$data['session_id'] : '',
        'device_id' => isset($data['device_id']) ? (string)$data['device_id'] : '',
        'fp' => isset($data['fp']) ? (string)$data['fp'] : '',
      ];
    }
  }
  if (strpos($raw, '|') !== false) {
    $parts = explode('|', $raw, 3);
    return [
      'session_id' => isset($parts[1]) ? (string)$parts[1] : '',
      'device_id' => (string)$parts[0],
      'fp' => isset($parts[2]) ? (string)$parts[2] : '',
    ];
  }
  return ['session_id' => $raw, 'device_id' => '', 'fp' => ''];
}

function auth_lock_busy(int $userId, string $sessionId, ?string $deviceId = null, ?string $fingerprint = null): bool {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return false;
  $mtime = @filemtime($path);
  if (!$mtime || (time() - (int)$mtime) > auth_lock_ttl_seconds()) {
    @unlink($path);
    return false;
  }
  $rec = auth_lock_read_record($path);
  $sid = (string)($rec['session_id'] ?? '');
  $did = strtolower(trim((string)($rec['device_id'] ?? '')));
  $fp = (string)($rec['fp'] ?? '');
  $deviceId = $deviceId !== null ? strtolower(trim((string)$deviceId)) : null;
  $fingerprint = $fingerprint !== null ? (string)$fingerprint : null;
  $isLegacy = ($did === '' && $fp === '');
  if ($did !== '' && $deviceId !== null && $deviceId !== '' && hash_equals($did, $deviceId)) return false;
  if ($fp !== '' && $fingerprint !== null && $fingerprint !== '' && hash_equals($fp, $fingerprint)) return false;
  if ($sid === '') return false;
  if ($isLegacy && !hash_equals($sid, $sessionId) && (time() - (int)$mtime) > 120) {
    @unlink($path);
    return false;
  }
  return hash_equals($sid, $sessionId) ? false : true;
}

function auth_lock_acquire(int $userId, string $sessionId, ?string $deviceId = null, ?string $fingerprint = null): void {
  auth_lock_ensure_dir();
  $path = auth_lock_path($userId);
  $deviceId = $deviceId !== null ? strtolower(trim((string)$deviceId)) : '';
  $fingerprint = $fingerprint !== null ? (string)$fingerprint : '';
  $payload = json_encode([
    'session_id' => $sessionId,
    'device_id' => $deviceId,
    'fp' => $fingerprint,
  ], JSON_UNESCAPED_SLASHES);
  if ($payload === false) $payload = $sessionId;
  @file_put_contents($path, $payload, LOCK_EX);
  @touch($path);
}

function auth_lock_touch(int $userId, string $sessionId, ?string $deviceId = null, ?string $fingerprint = null): bool {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return false;
  $mtime = @filemtime($path);
  if (!$mtime || (time() - (int)$mtime) > auth_lock_ttl_seconds()) {
    @unlink($path);
    return false;
  }
  $rec = auth_lock_read_record($path);
  $sid = (string)($rec['session_id'] ?? '');
  $did = strtolower(trim((string)($rec['device_id'] ?? '')));
  $fp = (string)($rec['fp'] ?? '');
  $deviceId = $deviceId !== null ? strtolower(trim((string)$deviceId)) : null;
  $fingerprint = $fingerprint !== null ? (string)$fingerprint : null;
  $deviceOk = false;
  if ($did !== '' && $deviceId !== null && $deviceId !== '' && hash_equals($did, $deviceId)) $deviceOk = true;
  if (!$deviceOk && $fp !== '' && $fingerprint !== null && $fingerprint !== '' && hash_equals($fp, $fingerprint)) $deviceOk = true;
  if (!$deviceOk) {
    if ($sid === '' || !hash_equals($sid, $sessionId)) return false;
  }
  if ($deviceOk && ($sid === '' || !hash_equals($sid, $sessionId))) {
    auth_lock_acquire($userId, $sessionId, $deviceId ?? $did, $fingerprint ?? $fp);
    return true;
  }
  @touch($path);
  return true;
}

function auth_lock_release(int $userId, ?string $sessionId = null): void {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return;
  if ($sessionId !== null) {
    $rec = auth_lock_read_record($path);
    $sid = (string)($rec['session_id'] ?? '');
    if ($sid === '' || !hash_equals($sid, $sessionId)) return;
  }
  @unlink($path);
}
