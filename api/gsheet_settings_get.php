<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';

$userId = (int)$_SESSION['user_id'];

$__sp_column_exists = function(mysqli $db, string $table, string $col): bool {
  $t = $db->real_escape_string($table);
  $c = $db->real_escape_string($col);
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
  $res = $db->query($sql);
  if (!$res) return false;
  $ok = $res->num_rows > 0;
  $res->close();
  return $ok;
};
$__sp_ensure_sheet_title = function(mysqli $db) use ($__sp_column_exists): void {
  if (!$__sp_column_exists($db, 'gsheet_settings', 'sheet_title')) {
    @$db->query("ALTER TABLE gsheet_settings ADD COLUMN sheet_title VARCHAR(255) DEFAULT NULL AFTER spreadsheet_id");
  }
};
try { $__sp_ensure_sheet_title($mysqli); } catch (Throwable $e) {}

$mapels = [];
$stmtM = $mysqli->prepare("SELECT mapel, COUNT(*) AS quiz_count FROM published_quizzes WHERE user_id=? GROUP BY mapel ORDER BY mapel ASC");
if ($stmtM) {
  $stmtM->bind_param('i', $userId);
  $stmtM->execute();
  $resM = $stmtM->get_result();
  while ($r = $resM->fetch_assoc()) $mapels[] = $r;
  $stmtM->close();
}

$items = [];
$stmt = $mysqli->prepare("
  SELECT
    s.id, s.user_id, s.mapel, s.spreadsheet_url, s.spreadsheet_id, s.sheet_title,
    s.is_active, s.auto_sync, s.include_detail, s.created_at, s.updated_at,
    COALESCE(q.quiz_count, 0) AS quiz_count
  FROM gsheet_settings s
  LEFT JOIN (
    SELECT mapel, COUNT(*) AS quiz_count
    FROM published_quizzes
    WHERE user_id=?
    GROUP BY mapel
  ) q ON q.mapel = s.mapel
  WHERE s.user_id=?
  ORDER BY s.updated_at DESC, s.id DESC
");
if ($stmt) {
  $stmt->bind_param('ii', $userId, $userId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $items[] = $r;
  $stmt->close();
}

echo json_encode(['ok' => true, 'items' => $items, 'mapels_with_quiz' => $mapels], JSON_UNESCAPED_UNICODE);
?>
