<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'admin_only']);
  exit;
}

require_once __DIR__ . '/../db.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$slug = isset($data['slug']) ? trim((string)$data['slug']) : '';
$count = isset($data['count']) ? (int)$data['count'] : 30;
$overwrite = isset($data['overwrite']) ? (int)$data['overwrite'] : 1;

if ($slug === '' || $count <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_input']);
  exit;
}
if ($count > 200) $count = 200;

$stmt = $mysqli->prepare("SELECT id, total_soal FROM published_quizzes WHERE slug=? AND user_id=? LIMIT 1");
$stmt->bind_param('si', $slug, $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($pubId, $totalSoal);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'not_found']);
  exit;
}
$stmt->close();

$total = (int)$totalSoal;
if ($total <= 0) $total = 10;

$mysqli->begin_transaction();
try {
  if ($overwrite) {
    $stmt = $mysqli->prepare("DELETE FROM published_quiz_results WHERE published_id=? AND absen BETWEEN 1 AND ?");
    $stmt->bind_param('ii', $pubId, $count);
    $stmt->execute();
    $stmt->close();
  }

  $ins = $mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, score, total) VALUES (?, ?, ?, ?)");
  $inserted = 0;
  for ($absen = 1; $absen <= $count; $absen++) {
    $pct = random_int(45, 100);
    $score = (int)round(($pct / 100.0) * $total);
    if ($score < 0) $score = 0;
    if ($score > $total) $score = $total;
    $ins->bind_param('iiii', $pubId, $absen, $score, $total);
    if ($ins->execute()) $inserted++;
  }
  $ins->close();

  $mysqli->commit();
  echo json_encode(['ok' => true, 'slug' => $slug, 'published_id' => (int)$pubId, 'total' => $total, 'count' => $count, 'inserted' => $inserted], JSON_UNESCAPED_UNICODE);
  exit;
} catch (Throwable $e) {
  $mysqli->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
  exit;
}
