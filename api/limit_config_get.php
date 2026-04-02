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

// create tables if not exists
$mysqli->query("CREATE TABLE IF NOT EXISTS app_settings (
  `k` VARCHAR(64) PRIMARY KEY,
  `v` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS feature_costs (
  `feature` VARCHAR(64) PRIMARY KEY,
  `cost` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ensure defaults
function ensure_cost($db,$feat,$cost){
  $stmt=$db->prepare("INSERT INTO feature_costs (feature,cost) VALUES (?,?) ON DUPLICATE KEY UPDATE cost=cost");
  $stmt->bind_param('si',$feat,$cost);
  $stmt->execute(); $stmt->close();
}
ensure_cost($mysqli,'publish_quiz',3);
ensure_cost($mysqli,'modul_ajar',3);
ensure_cost($mysqli,'rpp',2);
ensure_cost($mysqli,'buat_soal',2);
ensure_cost($mysqli,'rekap_nilai',0);
$mysqli->query("INSERT IGNORE INTO app_settings (k,v) VALUES ('initial_limit','300')");

// read values
$costs = [];
$res = $mysqli->query("SELECT feature,cost FROM feature_costs");
while ($row = $res->fetch_assoc()) $costs[$row['feature']] = (int)$row['cost'];
$res->close();
$init = 300;
$res = $mysqli->query("SELECT v FROM app_settings WHERE k='initial_limit' LIMIT 1");
if ($row=$res->fetch_assoc()) $init = (int)$row['v'];
$res->close();

echo json_encode(['ok'=>true,'costs'=>$costs,'initial_limit'=>$init]); exit;
