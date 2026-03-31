<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../db.php';
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$slug = isset($data['slug']) ? trim((string)$data['slug']) : '';
$pubIdParam = isset($data['id']) ? (int)$data['id'] : 0;
$absen = isset($data['absen']) ? (int)$data['absen'] : 0;
$nama = isset($data['nama']) ? trim((string)$data['nama']) : (isset($data['name']) ? trim((string)$data['name']) : '');
$answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];
$orderMap = isset($data['order_map']) && is_array($data['order_map']) ? $data['order_map'] : [];
if (($slug === '' && $pubIdParam <= 0) || $absen <= 0 || empty($answers) || empty($orderMap)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_input']);
  exit;
}
$nama = preg_replace('/\s+/', ' ', $nama);
if (strlen($nama) > 120) $nama = substr($nama, 0, 120);
$sql = $pubIdParam > 0
  ? "SELECT id, answer_key, is_active, expire_at, total_soal, payload_public FROM published_quizzes WHERE id=? LIMIT 1"
  : "SELECT id, answer_key, is_active, expire_at, total_soal, payload_public FROM published_quizzes WHERE slug=? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if ($pubIdParam > 0) $stmt->bind_param('i', $pubIdParam);
else $stmt->bind_param('s', $slug);
$stmt->execute();
$stmt->bind_result($pubId, $answerJson, $active, $expireAt, $total, $payloadJson);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'not_found']);
  exit;
}
$stmt->close();
if ((int)$active !== 1) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'inactive']);
  exit;
}
if ($expireAt && strtotime($expireAt) < time()) {
  http_response_code(410);
  echo json_encode(['ok'=>false,'error'=>'expired']);
  exit;
}
$decoded = json_decode($payloadJson, true);
$maxAbsen = 0;
if (is_array($decoded) && isset($decoded['settings']['max_absen'])) $maxAbsen = (int)$decoded['settings']['max_absen'];
if ($maxAbsen > 0 && ($absen < 1 || $absen > $maxAbsen)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'absen_out_of_range']);
  exit;
}
$answerKey = json_decode($answerJson, true);
if (!is_array($answerKey)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'key_corrupt']);
  exit;
}
$rev = array_fill(0, count($orderMap), 0);
for ($i=0;$i<count($orderMap);$i++) {
  $orig = (int)$orderMap[$i];
  $rev[$orig] = $i;
}
$score = 0;
$len = min(count($answerKey), count($answers), count($orderMap));
for ($i=0; $i<$len; $i++) {
  $pos = $rev[$i] ?? null;
  if ($pos === null) continue;
  $ansIdx = isset($answers[$pos]) ? (int)$answers[$pos] : -1;
  $correct = isset($answerKey[$i]) ? (int)$answerKey[$i] : -1;
  if ($ansIdx === $correct) $score++;
}
$totalFinal = $total ?: $len;
$stmt = null;
try {
  $stmt = $mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, nama, score, total) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama=IF(nama IS NULL OR nama='', VALUES(nama), nama)");
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}
if (!$stmt) {
  try { $mysqli->query("ALTER TABLE published_quiz_results ADD COLUMN nama VARCHAR(120) NULL AFTER absen"); } catch (mysqli_sql_exception $e) {}
  try {
    $stmt = $mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, nama, score, total) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nama=IF(nama IS NULL OR nama='', VALUES(nama), nama)");
  } catch (mysqli_sql_exception $e) {
    $stmt = null;
  }
}
if (!$stmt) {
  $stmt2 = $mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, score, total) VALUES (?, ?, ?, ?)");
  if (!$stmt2) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'stmt_fail']);
    exit;
  }
  $stmt2->bind_param('iiii', $pubId, $absen, $score, $totalFinal);
  $ok = $stmt2->execute();
  if (!$ok) {
    $code = (int)($stmt2->errno ?: $mysqli->errno);
    $stmt2->close();
    if ($code === 1062) {
      echo json_encode(['ok'=>true,'status'=>'duplicate','score_saved'=>false,'score'=>$score,'total'=>$totalFinal]);
    } else {
      http_response_code(500);
      echo json_encode(['ok'=>false,'error'=>'insert_fail']);
    }
    exit;
  }
  $stmt2->close();
  echo json_encode(['ok'=>true,'status'=>'saved','score'=>$score,'total'=>$totalFinal]);
  exit;
}
$stmt->bind_param('iisii', $pubId, $absen, $nama, $score, $totalFinal);
$ok = $stmt->execute();
if (!$ok) {
  $code = (int)($stmt->errno ?: $mysqli->errno);
  $stmt->close();
  if ($code === 1062) {
    echo json_encode(['ok'=>true,'status'=>'duplicate','score_saved'=>false,'score'=>$score,'total'=>$totalFinal]);
  } else {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'insert_fail']);
  }
  exit;
}
$stmt->close();
echo json_encode(['ok'=>true,'status'=>'saved','score'=>$score,'total'=>$totalFinal]);
