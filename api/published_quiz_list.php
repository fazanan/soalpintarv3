<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? 'user') !== 'admin') {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
$stmt = $mysqli->prepare("SELECT id, slug, mapel, kelas, total_soal, is_active, created_at, expire_at FROM published_quizzes WHERE user_id=? ORDER BY id DESC");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
echo json_encode(['ok'=>true,'items'=>$rows], JSON_UNESCAPED_UNICODE);
