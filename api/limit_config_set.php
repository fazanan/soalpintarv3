<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors','0');
@error_reporting(E_ERROR|E_PARSE);
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? 'user') !== 'admin')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
}
require_once __DIR__ . '/../db.php';
$raw = file_get_contents('php://input');
$j = json_decode($raw, true);
if (!is_array($j)) { echo json_encode(['ok'=>false,'error'=>'bad_json']); exit; }

$mysqli->query("CREATE TABLE IF NOT EXISTS app_settings (`k` VARCHAR(64) PRIMARY KEY, `v` VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS feature_costs (`feature` VARCHAR(64) PRIMARY KEY, `cost` INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$costs = isset($j['costs']) && is_array($j['costs']) ? $j['costs'] : [];
foreach (['publish_quiz','modul_ajar','buat_soal','rekap_nilai'] as $feat) {
  $c = isset($costs[$feat]) ? (int)$costs[$feat] : null;
  if ($c !== null && $c >= 0 && $c <= 1000) {
    $stmt = $mysqli->prepare("INSERT INTO feature_costs (feature,cost) VALUES (?,?) ON DUPLICATE KEY UPDATE cost=VALUES(cost)");
    $stmt->bind_param('si', $feat, $c);
    $stmt->execute(); $stmt->close();
  }
}
if (isset($j['initial_limit'])) {
  $init = max(0, (int)$j['initial_limit']);
  $stmt = $mysqli->prepare("INSERT INTO app_settings (k,v) VALUES ('initial_limit', ?) ON DUPLICATE KEY UPDATE v=VALUES(v)");
  $sv = (string)$init;
  $stmt->bind_param('s', $sv);
  $stmt->execute(); $stmt->close();
}
echo json_encode(['ok'=>true]); exit;

