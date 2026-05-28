<?php
declare(strict_types=1);
ob_start();
session_start();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);

function json_exit(array $payload, int $status = 200): void {
  if (ob_get_level() > 0) @ob_clean();
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['user_id'])) {
  json_exit(['ok' => false, 'error' => 'forbidden'], 403);
}
require_once __DIR__ . '/../db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_exit(['ok' => false, 'error' => 'method_not_allowed'], 405);
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
  json_exit(['ok' => false, 'error' => 'invalid_json'], 400);
}

function parse_spreadsheet_id(string $url): string {
  $u = trim($url);
  if ($u === '') return '';
  if (preg_match('~\/spreadsheets\/d\/([a-zA-Z0-9\-_]+)~', $u, $m)) return (string)$m[1];
  if (preg_match('~\/d\/([a-zA-Z0-9\-_]+)~', $u, $m)) return (string)$m[1];
  return '';
}

function table_exists(mysqli $db, string $table): bool {
  $table = $db->real_escape_string($table);
  $sql = "SHOW TABLES LIKE '$table'";
  $res = $db->query($sql);
  if (!$res) return false;
  $ok = $res->num_rows > 0;
  $res->close();
  return $ok;
}

function ensure_gsheet_settings_table(mysqli $db): void {
  if (table_exists($db, 'gsheet_settings')) return;
  $sql = "
CREATE TABLE IF NOT EXISTS gsheet_settings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  mapel VARCHAR(128) NOT NULL,
  spreadsheet_url TEXT NOT NULL,
  spreadsheet_id VARCHAR(128) NOT NULL,
  sheet_title VARCHAR(255) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  auto_sync TINYINT(1) NOT NULL DEFAULT 1,
  include_detail TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_gsheet_user_mapel (user_id, mapel),
  INDEX idx_gsheet_user (user_id),
  CONSTRAINT fk_gsheet_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
";
  if (!$db->query($sql)) {
    throw new RuntimeException('create_table_failed errno=' . (int)$db->errno . ' err=' . (string)$db->error);
  }
}

function column_exists(mysqli $db, string $table, string $col): bool {
  $t = $db->real_escape_string($table);
  $c = $db->real_escape_string($col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $db->query($sql);
  if (!$res) return false;
  $ok = $res->num_rows > 0;
  $res->close();
  return $ok;
}

function ensure_sheet_title_column(mysqli $db): void {
  if (table_exists($db, 'gsheet_settings') && !column_exists($db, 'gsheet_settings', 'sheet_title')) {
    if (!$db->query("ALTER TABLE gsheet_settings ADD COLUMN sheet_title VARCHAR(255) DEFAULT NULL AFTER spreadsheet_id")) {
      throw new RuntimeException('alter_table_failed errno=' . (int)$db->errno . ' err=' . (string)$db->error);
    }
  }
}

$userId = (int)$_SESSION['user_id'];
$mapel = isset($data['mapel']) ? trim((string)$data['mapel']) : '';
$spreadsheetUrl = isset($data['spreadsheet_url']) ? trim((string)$data['spreadsheet_url']) : '';
$spreadsheetId = parse_spreadsheet_id($spreadsheetUrl);
$sheetTitle = isset($data['sheet_title']) ? trim((string)$data['sheet_title']) : '';
$isActive = isset($data['is_active']) ? (((int)$data['is_active']) === 1 ? 1 : 0) : 1;
$autoSync = isset($data['auto_sync']) ? (((int)$data['auto_sync']) === 1 ? 1 : 0) : 1;
$includeDetail = isset($data['include_detail']) ? (((int)$data['include_detail']) === 1 ? 1 : 0) : 0;

$missing = [];
if ($mapel === '') $missing[] = 'mapel';
if ($spreadsheetUrl === '') $missing[] = 'spreadsheet_url';
if ($spreadsheetUrl !== '' && $spreadsheetId === '') $missing[] = 'spreadsheet_id';
if ($missing) {
  json_exit(['ok' => false, 'error' => 'invalid_input', 'missing' => $missing], 400);
}
if (strlen($spreadsheetId) > 128) {
  json_exit(['ok' => false, 'error' => 'invalid_spreadsheet_id'], 400);
}

try {
  ensure_gsheet_settings_table($mysqli);
  ensure_sheet_title_column($mysqli);
} catch (Throwable $e) {
  json_exit([
    'ok' => false,
    'error' => 'ensure_table_failed',
    'message' => $e->getMessage(),
    'errno' => (int)$mysqli->errno,
  ], 500);
}

$sheetTitle = preg_replace('/\s+/', ' ', $sheetTitle);
$sheetTitle = trim((string)$sheetTitle);
if ($sheetTitle === '') {
  try {
    require_once __DIR__ . '/gsheet_service.php';
    $tok = gsheet_get_access_token();
    if (($tok['ok'] ?? false) && !empty($tok['access_token'])) {
      $accessToken = (string)$tok['access_token'];
      $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=properties.title';
      $res = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
      if (($res['ok'] ?? false) && is_array($res['json'])) {
        $t = (string)($res['json']['properties']['title'] ?? '');
        $t = preg_replace('/\s+/', ' ', trim($t));
        if ($t !== '') $sheetTitle = $t;
      }
    }
  } catch (Throwable $e) {}
}

$sql = "
  INSERT INTO gsheet_settings
    (user_id, mapel, spreadsheet_url, spreadsheet_id, sheet_title, is_active, auto_sync, include_detail)
  VALUES
    (?, ?, ?, ?, ?, ?, ?, ?)
  ON DUPLICATE KEY UPDATE
    spreadsheet_url=VALUES(spreadsheet_url),
    spreadsheet_id=VALUES(spreadsheet_id),
    sheet_title=VALUES(sheet_title),
    is_active=VALUES(is_active),
    auto_sync=VALUES(auto_sync),
    include_detail=VALUES(include_detail),
    updated_at=CURRENT_TIMESTAMP
";

$stmt = null;
try {
  $stmt = $mysqli->prepare($sql);
} catch (Throwable $e) {
  json_exit([
    'ok' => false,
    'error' => 'stmt_exception',
    'message' => $e->getMessage(),
    'errno' => (int)$mysqli->errno,
  ], 500);
}
if (!$stmt) {
  json_exit([
    'ok' => false,
    'error' => 'stmt_fail',
    'message' => 'Gagal menyiapkan query database',
    'errno' => (int)$mysqli->errno,
  ], 500);
}

$stmt->bind_param('issssiii', $userId, $mapel, $spreadsheetUrl, $spreadsheetId, $sheetTitle, $isActive, $autoSync, $includeDetail);
$ok = false;
try {
  $ok = $stmt->execute();
} catch (Throwable $e) {
  $stmt->close();
  json_exit([
    'ok' => false,
    'error' => 'db_exception',
    'message' => $e->getMessage(),
    'errno' => (int)$mysqli->errno,
  ], 500);
}
$stmt->close();
if (!$ok) {
  json_exit([
    'ok' => false,
    'error' => 'db_error',
    'message' => 'Query database gagal dieksekusi',
    'errno' => (int)$mysqli->errno,
  ], 500);
}

json_exit(['ok' => true, 'spreadsheet_id' => $spreadsheetId, 'sheet_title' => $sheetTitle, 'mapel' => $mapel], 200);
