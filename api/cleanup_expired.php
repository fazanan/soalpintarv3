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

$now = time();
$threshold = date('Y-m-d H:i:s', $now - 86400);

// pilih publikasi milik user yang expire_at < now atau dibuat >24 jam lalu (jika kolom tersedia)
$stmt = $mysqli->prepare("SELECT id, payload_public, expire_at FROM published_quizzes WHERE user_id=? AND ((expire_at IS NOT NULL AND expire_at < NOW()) OR (expire_at IS NULL AND created_at < ?))");
if ($stmt) {
  $stmt->bind_param('is', $_SESSION['user_id'], $threshold);
} else {
  // fallback tanpa created_at
  $stmt = $mysqli->prepare("SELECT id, payload_public, expire_at FROM published_quizzes WHERE user_id=? AND (expire_at IS NOT NULL AND expire_at < NOW())");
  $stmt->bind_param('i', $_SESSION['user_id']);
}
$stmt->execute();
$res = $stmt->get_result();
$toDelete = [];
while ($r = $res->fetch_assoc()) $toDelete[] = $r;
$stmt->close();

$deleted = ['publications'=>0, 'results'=>0, 'images'=>0];
foreach ($toDelete as $pub) {
  $pubId = (int)$pub['id'];
  // hapus hasil
  $stmt2 = $mysqli->prepare("DELETE FROM published_quiz_results WHERE published_id=?");
  if ($stmt2) {
    $stmt2->bind_param('i', $pubId);
    $stmt2->execute();
    $deleted['results'] += $stmt2->affected_rows > 0 ? $stmt2->affected_rows : 0;
    $stmt2->close();
  }
  // hapus gambar lokal yang direferensikan
  $payload = json_decode((string)$pub['payload_public'], true);
  if (is_array($payload) && isset($payload['items']) && is_array($payload['items'])) {
    foreach ($payload['items'] as $it) {
      $img = isset($it['image']) ? (string)$it['image'] : '';
      if ($img && preg_match('#^/uploads/quiz_images/#', $img)) {
        $full = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $img), DIRECTORY_SEPARATOR);
        if (is_file($full)) {
          @unlink($full);
          $deleted['images']++;
        }
      }
    }
  }
  // hapus publikasi
  $stmt3 = $mysqli->prepare("DELETE FROM published_quizzes WHERE id=? LIMIT 1");
  if ($stmt3) {
    $stmt3->bind_param('i', $pubId);
    $stmt3->execute();
    if ($stmt3->affected_rows > 0) $deleted['publications']++;
    $stmt3->close();
  }
}

echo json_encode(['ok'=>true,'deleted'=>$deleted,'checked'=>count($toDelete)]);
exit;

