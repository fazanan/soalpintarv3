<?php
session_start();
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
$role = (string)($_SESSION['role'] ?? 'user');
if ($role !== 'admin') {
  $access = isset($_SESSION['access_quiz']) ? (int)$_SESSION['access_quiz'] : null;
  if ($access === null) {
    $stmtAcc = null;
    try { $stmtAcc = $mysqli->prepare("SELECT access_quiz FROM users WHERE id=? LIMIT 1"); } catch (mysqli_sql_exception $e) { $stmtAcc = null; }
    if ($stmtAcc) {
      $stmtAcc->bind_param('i', $_SESSION['user_id']);
      $stmtAcc->execute();
      $stmtAcc->bind_result($aq);
      if ($stmtAcc->fetch()) $access = (int)$aq;
      $stmtAcc->close();
    }
    if ($access === null) $access = 1;
  }
  if ($access !== 1) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'no_access']);
    exit;
  }
}
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($slug === '' && $id <= 0) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'missing_id_or_slug']);
  exit;
}
if ($id > 0) {
  $stmt = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public FROM published_quizzes WHERE id=? AND user_id=? LIMIT 1");
  $stmt->bind_param('ii', $id, $_SESSION['user_id']);
} else {
  $stmt = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public FROM published_quizzes WHERE slug=? AND user_id=? LIMIT 1");
  $stmt->bind_param('si', $slug, $_SESSION['user_id']);
}
$stmt->execute();
$stmt->bind_result($pid, $pslug, $pmapel, $pkelas, $ppayload);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'not_found']);
  exit;
}
$stmt->close();

$stmt = null;
try {
  $stmt = $mysqli->prepare("SELECT absen, nama, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY created_at ASC");
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}
if (!$stmt) $stmt = $mysqli->prepare("SELECT absen, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY created_at ASC");
$stmt->bind_param('i', $pid);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

$obj = [
  'ok' => true,
  'id' => $pid,
  'slug' => $pslug,
  'mapel' => $pmapel,
  'kelas' => $pkelas,
  'payload_public' => json_decode($ppayload, true),
  'results' => $rows,
];
$fn = "quiz_export_{$pslug}_{$pid}.json";
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fn.'"');
echo json_encode($obj, JSON_UNESCAPED_UNICODE);
exit;
