<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
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
  http_response_code(400);
  echo json_encode([
    'ok'=>false,
    'error'=>'invalid_json',
    'content_type'=> (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    'length'=> strlen((string)$raw)
  ]);
  exit;
}
$slug = isset($data['slug']) ? trim((string)$data['slug']) : '';
$mapel = isset($data['mapel']) ? trim((string)$data['mapel']) : '';
$kelas = isset($data['kelas']) ? trim((string)$data['kelas']) : '';
$sekolah = isset($data['sekolah']) ? trim((string)$data['sekolah']) : '';
$guru = isset($data['guru']) ? trim((string)$data['guru']) : '';
$payload = $data['payload_public'] ?? null;
$answerKey = $data['answer_key'] ?? null;
$expire = isset($data['expire_at']) ? trim((string)$data['expire_at']) : '';
$maxAbsen = isset($data['max_absen']) ? (int)$data['max_absen'] : 0;
$showSolution = isset($data['show_solution']) ? (int)$data['show_solution'] : 0;
if (is_string($payload)) {
  $tmp = json_decode($payload, true);
  if (is_array($tmp)) $payload = $tmp;
}
if (is_string($answerKey)) {
  $tmp = json_decode($answerKey, true);
  if (is_array($tmp)) $answerKey = $tmp;
}
$missing = [];
if ($slug === '') $missing[] = 'slug';
if ($mapel === '') $missing[] = 'mapel';
if (!is_array($payload) || count($payload) === 0) $missing[] = 'payload_public';
if (!is_array($answerKey) || count($answerKey) === 0) $missing[] = 'answer_key';
if ($missing) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_input','missing'=>$missing], JSON_UNESCAPED_UNICODE);
  exit;
}
$slug = strtolower(preg_replace('/[^a-z0-9\-]+/','-', $slug));
$payloadObject = [];
if (isset($payload['items']) && is_array($payload['items'])) {
  $payloadObject = $payload;
} else {
  $payloadObject = ['items'=>$payload];
}
if (!isset($payloadObject['settings']) || !is_array($payloadObject['settings'])) $payloadObject['settings'] = [];
$payloadObject['settings']['max_absen'] = max(0, (int)$maxAbsen);
$payloadObject['settings']['meta'] = [
  'sekolah' => $sekolah,
  'guru' => $guru,
  'mapel' => $mapel,
  'kelas' => $kelas
];
$payloadObject['settings']['show_solution'] = $showSolution ? 1 : 0;
$payloadObject['settings']['answer_key'] = is_array($answerKey) ? array_map('intval', $answerKey) : [];
$payloadJson = json_encode($payloadObject, JSON_UNESCAPED_UNICODE);
$answerJson = is_string($answerKey) ? $answerKey : json_encode($answerKey, JSON_UNESCAPED_UNICODE);
$arr = json_decode($payloadJson, true);
$total = is_array($arr['items'] ?? null) ? count($arr['items']) : 0;
$sql = "INSERT INTO published_quizzes (user_id, slug, mapel, kelas, total_soal, payload_public, answer_key, is_active, expire_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ".($expire ? '?' : 'NULL').")";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'stmt_fail','errno'=>(int)$mysqli->errno]);
  exit;
}
if ($expire !== '') {
  $stmt->bind_param('isssisss', $_SESSION['user_id'], $slug, $mapel, $kelas, $total, $payloadJson, $answerJson, $expire);
} else {
  $stmt->close();
  $stmt = $mysqli->prepare("INSERT INTO published_quizzes (user_id, slug, mapel, kelas, total_soal, payload_public, answer_key, is_active, expire_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NULL)");
  if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'stmt_fail','errno'=>(int)$mysqli->errno]);
    exit;
  }
  $stmt->bind_param('isssiss', $_SESSION['user_id'], $slug, $mapel, $kelas, $total, $payloadJson, $answerJson);
}
$ok = $stmt->execute();
if (!$ok) {
  $code = (int)($stmt->errno ?: $mysqli->errno);
  $stmt->close();
  if ($code === 1062) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'slug_exists']);
  } else {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'insert_fail','errno'=>$code]);
  }
  exit;
}
$id = $stmt->insert_id;
$stmt->close();
echo json_encode([
  'ok'=>true,
  'id'=>$id,
  'slug'=>$slug,
  'mapel'=>$mapel,
  'kelas'=>$kelas,
  'total'=>$total,
  'public_link'=>"https://".$_SERVER['HTTP_HOST']."/{$slug}/{no_absen}"
], JSON_UNESCAPED_UNICODE);
exit;
