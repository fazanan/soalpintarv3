<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);

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

if (!table_exists($mysqli, 'scalev_webhook_events') || !table_exists($mysqli, 'users')) {
  echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$hasNama = column_exists($mysqli, 'users', 'nama');
$hasSekolah = column_exists($mysqli, 'users', 'nama_sekolah');
if (!$hasNama || !$hasSekolah) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'missing_user_profile_columns'], JSON_UNESCAPED_UNICODE);
  exit;
}

$sql = "
  SELECT
    TRIM(u.nama) AS nama,
    TRIM(u.nama_sekolah) AS sekolah,
    COALESCE(e.processed_at, e.received_at) AS created_at
  FROM scalev_webhook_events e
  INNER JOIN users u ON u.username = e.email
  WHERE e.email IS NOT NULL AND e.email <> ''
    AND e.payment_status IS NOT NULL AND e.payment_status <> ''
    AND LOWER(e.payment_status) IN ('paid', 'settled')
    AND u.nama IS NOT NULL AND TRIM(u.nama) <> ''
    AND u.nama_sekolah IS NOT NULL AND TRIM(u.nama_sekolah) <> ''
  ORDER BY COALESCE(e.processed_at, e.received_at) DESC, e.id DESC
  LIMIT 10
";

$res = $mysqli->query($sql);
if (!$res) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'db_query_failed'], JSON_UNESCAPED_UNICODE);
  exit;
}

$rows = [];
while ($r = $res->fetch_assoc()) {
  $rows[] = [
    'nama' => (string)($r['nama'] ?? ''),
    'sekolah' => (string)($r['sekolah'] ?? ''),
    'created_at' => (string)($r['created_at'] ?? ''),
  ];
}
$res->close();

echo json_encode(['ok' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
