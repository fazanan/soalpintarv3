<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_slug']);
  exit;
}
$stmt = $mysqli->prepare("SELECT id, mapel FROM published_quizzes WHERE slug=? AND user_id=? LIMIT 1");
$stmt->bind_param('si', $slug, $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($pubId, $mapel);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'not_found']);
  exit;
}
$stmt->close();
$stmt = $mysqli->prepare("SELECT absen, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY score DESC, absen ASC");
$stmt->bind_param('i', $pubId);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>$rows], JSON_UNESCAPED_UNICODE);
