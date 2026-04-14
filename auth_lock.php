<?php
declare(strict_types=1);

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

function auth_lock_busy(int $userId, string $sessionId): bool {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return false;
  $mtime = @filemtime($path);
  if (!$mtime || (time() - (int)$mtime) > auth_lock_ttl_seconds()) {
    @unlink($path);
    return false;
  }
  $sid = auth_lock_read_sid($path);
  if ($sid === '') return false;
  return hash_equals($sid, $sessionId) ? false : true;
}

function auth_lock_acquire(int $userId, string $sessionId): void {
  auth_lock_ensure_dir();
  $path = auth_lock_path($userId);
  @file_put_contents($path, $sessionId, LOCK_EX);
  @touch($path);
}

function auth_lock_touch(int $userId, string $sessionId): bool {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return false;
  $sid = auth_lock_read_sid($path);
  if ($sid === '') return false;
  if (!hash_equals($sid, $sessionId)) return false;
  @touch($path);
  return true;
}

function auth_lock_release(int $userId, ?string $sessionId = null): void {
  $path = auth_lock_path($userId);
  if (!is_file($path)) return;
  if ($sessionId !== null) {
    $sid = auth_lock_read_sid($path);
    if ($sid === '' || !hash_equals($sid, $sessionId)) return;
  }
  @unlink($path);
}
