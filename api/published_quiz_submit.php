<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/../db.php';
function normalize_student_name($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = str_replace("\0", '', $s);
  for ($i = 0; $i < 2; $i++) {
    if (strpos($s, '%') === false && strpos($s, '+') === false) break;
    $dec = urldecode($s);
    if ($dec === $s) break;
    $s = $dec;
  }
  $s = preg_replace('/\s+/', ' ', $s);
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($s, 'UTF-8') > 120) $s = mb_substr($s, 0, 120, 'UTF-8');
  } else {
    if (strlen($s) > 120) $s = substr($s, 0, 120);
  }
  return $s;
}
function __sp_norm_short_text($s): string {
  $v = (string)($s ?? '');
  $v = str_replace(["\r\n", "\r"], "\n", $v);
  $v = trim($v);
  $v = preg_replace('/\s+/', ' ', $v);
  $v = preg_replace('/^[\s"\'`]+/', '', (string)$v);
  $v = preg_replace('/[\s"\'`]+$/', '', (string)$v);
  $v = preg_replace('/[.,;:!?]+$/', '', (string)$v);
  $v = trim((string)$v);
  if (function_exists('mb_strtolower')) return mb_strtolower($v, 'UTF-8');
  return strtolower($v);
}
function __sp_short_key_list($raw): array {
  $out = [];
  $push = function($x) use (&$out) {
    $t = trim((string)($x ?? ''));
    if ($t === '') return;
    $n = __sp_norm_short_text($t);
    if ($n === '') return;
    $out[$n] = 1;
  };
  if (is_array($raw)) {
    foreach ($raw as $v) $push($v);
  } else if (is_string($raw)) {
    if (strpos($raw, '|') !== false) {
      foreach (explode('|', $raw) as $v) $push($v);
    } else {
      $push($raw);
    }
  } else {
    $push($raw);
  }
  return array_keys($out);
}
function __sp_sheet_name($name, $fallback) {
  $n = trim((string)$name);
  if ($n === '') $n = (string)$fallback;
  $n = preg_replace('/[\[\]\:\?\*\/\\\\]/', ' ', $n);
  $n = preg_replace('/\s+/', ' ', $n);
  $n = trim((string)$n);
  if ($n === '') $n = (string)$fallback;
  if (function_exists('mb_substr')) $n = mb_substr($n, 0, 100, 'UTF-8');
  else $n = substr($n, 0, 100);
  return $n;
}
function __sp_try_gsheet_autosync(mysqli $mysqli, int $publishedId, int $absen, string $nama, int $score, int $total, string $createdAt, array $answersOrdered): void {
  try {
    $stmtQ = @$mysqli->prepare("SELECT user_id, mapel, kelas, slug FROM published_quizzes WHERE id=? LIMIT 1");
    if (!$stmtQ) return;
    $stmtQ->bind_param('i', $publishedId);
    $stmtQ->execute();
    $stmtQ->bind_result($ownerId, $mapel, $kelas, $slug);
    if (!$stmtQ->fetch()) { $stmtQ->close(); return; }
    $stmtQ->close();
    $ownerId = (int)$ownerId;
    $mapel = (string)$mapel;
    $kelas = (string)$kelas;
    $slug = (string)$slug;
    if ($ownerId <= 0 || trim($mapel) === '') return;

    $stmtS = @$mysqli->prepare("SELECT spreadsheet_id, is_active, auto_sync, include_detail FROM gsheet_settings WHERE user_id=? AND mapel=? LIMIT 1");
    if (!$stmtS) return;
    $stmtS->bind_param('is', $ownerId, $mapel);
    $stmtS->execute();
    $stmtS->bind_result($spreadsheetId, $isActive, $autoSync, $includeDetail);
    if (!$stmtS->fetch()) { $stmtS->close(); return; }
    $stmtS->close();
    if ((int)$isActive !== 1) return;
    $spreadsheetId = trim((string)$spreadsheetId);
    if ($spreadsheetId === '') return;

    $createdAt = trim((string)$createdAt);
    if ($createdAt === '') $createdAt = date('Y-m-d H:i:s');
    $pct = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;

    require_once __DIR__ . '/gsheet_service.php';
    $tok = gsheet_get_access_token();
    if (!($tok['ok'] ?? false)) return;
    $accessToken = (string)($tok['access_token'] ?? '');
    if ($accessToken === '') return;

    $upsert = function(string $sheetName, string $absenColLetter, array $header, array $rowValues) use ($spreadsheetId, $accessToken, $absen) {
      $ens = gsheet_ensure_sheet($spreadsheetId, $sheetName, $accessToken);
      if (!($ens['ok'] ?? false)) return;
      gsheet_write_row($spreadsheetId, $sheetName, 1, $header, $accessToken);
      $find = gsheet_find_row_in_column($spreadsheetId, $sheetName, $absenColLetter, (string)$absen, $accessToken, 2, 5000);
      $rowNum = (int)($find['row'] ?? 0);
      if ($rowNum > 0) {
        gsheet_write_row($spreadsheetId, $sheetName, $rowNum, $rowValues, $accessToken);
      } else {
        gsheet_append_row($spreadsheetId, $sheetName, $rowValues, $accessToken);
      }
    };

    $sheetName = __sp_sheet_name($kelas, 'Kelas');
    $upsert($sheetName, 'D', ['Waktu Submit', 'Quiz ID', 'Slug', 'No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)'], [$createdAt, $publishedId, trim($slug), $absen, $nama, $score, $total, $pct]);

    $slugTrim = trim($slug);
    $hasilSheet = __sp_sheet_name('Hasil ' . ($slugTrim !== '' ? $slugTrim : (string)$publishedId), 'Hasil');
    $upsert($hasilSheet, 'D', ['Waktu Submit', 'Quiz ID', 'Slug', 'No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)'], [$createdAt, $publishedId, $slugTrim, $absen, $nama, $score, $total, $pct]);

    $detailSheet = __sp_sheet_name('Jawaban ' . ($slugTrim !== '' ? $slugTrim : (string)$publishedId), 'Jawaban');
    $header = ['Waktu Submit', 'Quiz ID', 'Slug', 'No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)', 'Jawaban JSON'];
    $nq = count($answersOrdered);
    if ((int)$includeDetail === 1) {
      for ($i = 1; $i <= $nq; $i++) $header[] = 'J' . $i;
    }
    $ansJson = json_encode(array_values($answersOrdered), JSON_UNESCAPED_UNICODE);
    if (!is_string($ansJson)) $ansJson = '[]';
    $row = [$createdAt, $publishedId, $slugTrim, $absen, $nama, $score, $total, $pct, $ansJson];
    if ((int)$includeDetail === 1) {
      foreach ($answersOrdered as $v) $row[] = (string)$v;
    }
    $upsert($detailSheet, 'D', $header, $row);
  } catch (Throwable $e) {
    return;
  }
}
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
$nama = normalize_student_name($nama);
$sql = $pubIdParam > 0
  ? "SELECT id, user_id, mapel, kelas, slug, answer_key, is_active, expire_at, total_soal, payload_public FROM published_quizzes WHERE id=? LIMIT 1"
  : "SELECT id, user_id, mapel, kelas, slug, answer_key, is_active, expire_at, total_soal, payload_public FROM published_quizzes WHERE slug=? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if ($pubIdParam > 0) $stmt->bind_param('i', $pubIdParam);
else $stmt->bind_param('s', $slug);
$stmt->execute();
$pubId = 0;
$ownerId = 0;
$mapel = '';
$kelas = '';
$slugDb = '';
$answerJson = '[]';
$active = 0;
$expireAt = null;
$total = 0;
$payloadJson = '{}';
$stmt->bind_result($pubId, $ownerId, $mapel, $kelas, $slugDb, $answerJson, $active, $expireAt, $total, $payloadJson);
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
$ownerId = (int)$ownerId;
$mapel = trim((string)$mapel);
if ($ownerId <= 0 || $mapel === '') {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'quiz_corrupt']);
  exit;
}
$stmtG = @$mysqli->prepare("SELECT spreadsheet_id, is_active FROM gsheet_settings WHERE user_id=? AND mapel=? LIMIT 1");
if (!$stmtG) {
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'gsheet_unavailable']);
  exit;
}
$stmtG->bind_param('is', $ownerId, $mapel);
$stmtG->execute();
$stmtG->bind_result($gsheetId, $gsheetActive);
if (!$stmtG->fetch()) {
  $stmtG->close();
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
  exit;
}
$stmtG->close();
if ((int)$gsheetActive !== 1 || trim((string)$gsheetId) === '') {
  http_response_code(503);
  echo json_encode(['ok'=>false,'error'=>'gsheet_not_active']);
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
$items = [];
if (is_array($decoded)) {
  if (isset($decoded['items']) && is_array($decoded['items'])) $items = $decoded['items'];
  else $items = $decoded;
}
$score = 0;
$len = min(count($answerKey), count($answers), count($orderMap));
for ($i=0; $i<$len; $i++) {
  $pos = $rev[$i] ?? null;
  if ($pos === null) continue;
  $ansRaw = $answers[$pos] ?? null;
  $correctRaw = $answerKey[$i] ?? null;
  $q = is_array($items[$i] ?? null) ? $items[$i] : [];
  $type = strtolower(trim((string)($q['type'] ?? '')));
  if ($type === 'isian') $type = 'isian_singkat';
  if ($type === '') $type = is_array($correctRaw) ? 'pg_kompleks' : 'pg';
  if ($type === 'isian_singkat') {
    $ansText = '';
    if (is_array($ansRaw)) $ansText = (string)($ansRaw[0] ?? '');
    else $ansText = (string)($ansRaw ?? '');
    $ansNorm = __sp_norm_short_text($ansText);
    if ($ansNorm !== '') {
      $keys = __sp_short_key_list($correctRaw);
      if (in_array($ansNorm, $keys, true)) $score++;
    }
  } else if ($type === 'pg_kompleks') {
    $corr = [];
    $corrRawArr = is_array($correctRaw) ? $correctRaw : [$correctRaw];
    foreach ($corrRawArr as $v) {
      if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) $corr[] = (int)$v;
    }
    $corr = array_values(array_unique($corr));
    sort($corr);
    $ans = [];
    if (is_array($ansRaw)) {
      foreach ($ansRaw as $v) {
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) $ans[] = (int)$v;
      }
    } else if (is_int($ansRaw) || is_float($ansRaw) || (is_string($ansRaw) && preg_match('/^-?\d+$/', trim($ansRaw)))) {
      $ans[] = (int)$ansRaw;
    }
    $ans = array_values(array_unique($ans));
    sort($ans);
    if ($ans === $corr) $score++;
  } else {
    $ansIdx = -1;
    if (is_array($ansRaw)) {
      if (count($ansRaw) > 0) $ansIdx = (int)($ansRaw[0] ?? -1);
    } else if ($ansRaw !== null) {
      $ansIdx = (int)$ansRaw;
    }
    $correct = -1;
    if (is_int($correctRaw) || is_float($correctRaw) || (is_string($correctRaw) && preg_match('/^-?\d+$/', trim($correctRaw)))) $correct = (int)$correctRaw;
    if ($ansIdx === $correct) $score++;
  }
}
$totalFinal = $total ?: $len;
$answersOrdered = [];
for ($i = 0; $i < $len; $i++) {
  $pos = $rev[$i] ?? null;
  if ($pos === null) { $answersOrdered[] = ''; continue; }
  $ansRaw = $answers[$pos] ?? null;
  $q = is_array($items[$i] ?? null) ? $items[$i] : [];
  $type = strtolower(trim((string)($q['type'] ?? 'pg')));
  if ($type === 'isian') $type = 'isian_singkat';
  if ($type === '') $type = 'pg';
  if ($type === 'isian_singkat') {
    $txt = '';
    if (is_array($ansRaw)) $txt = (string)($ansRaw[0] ?? '');
    else $txt = (string)($ansRaw ?? '');
    $txt = trim($txt);
    $txt = preg_replace('/\s+/', ' ', (string)$txt);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($txt, 'UTF-8') > 250) $txt = mb_substr($txt, 0, 250, 'UTF-8');
    } else {
      if (strlen($txt) > 250) $txt = substr($txt, 0, 250);
    }
    $answersOrdered[] = $txt;
  } else if ($type === 'pg_kompleks') {
    $vals = [];
    if (is_array($ansRaw)) {
      foreach ($ansRaw as $v) {
        if (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) $vals[] = (int)$v;
      }
    } else if ($ansRaw !== null) {
      if (is_int($ansRaw) || is_float($ansRaw) || (is_string($ansRaw) && preg_match('/^-?\d+$/', trim($ansRaw)))) $vals[] = (int)$ansRaw;
    }
    $vals = array_values(array_unique($vals));
    sort($vals);
    $letters = [];
    foreach ($vals as $v) {
      $idx = (int)$v;
      if ($idx >= 0 && $idx < 26) $letters[] = chr(65 + $idx);
    }
    $answersOrdered[] = implode(',', $letters);
  } else if ($type === 'benar_salah') {
    $idx = -1;
    if (is_array($ansRaw)) {
      if (count($ansRaw) > 0) $idx = (int)($ansRaw[0] ?? -1);
    } else if ($ansRaw !== null) {
      $idx = (int)$ansRaw;
    }
    if ($idx === 0) $answersOrdered[] = 'Benar';
    else if ($idx === 1) $answersOrdered[] = 'Salah';
    else $answersOrdered[] = '';
  } else {
    $idx = -1;
    if (is_array($ansRaw)) {
      if (count($ansRaw) > 0) $idx = (int)($ansRaw[0] ?? -1);
    } else if ($ansRaw !== null) {
      $idx = (int)$ansRaw;
    }
    if ($idx >= 0 && $idx < 26) $answersOrdered[] = chr(65 + $idx);
    else $answersOrdered[] = '';
  }
}
$resp = ['ok'=>true,'status'=>'saved','score'=>$score,'total'=>$totalFinal];
$outJson = json_encode($resp, JSON_UNESCAPED_UNICODE);
if (!is_string($outJson)) $outJson = '{"ok":true}';
header('Connection: close');
header('Content-Length: ' . strlen($outJson));
echo $outJson;
if (function_exists('fastcgi_finish_request')) {
  @fastcgi_finish_request();
} else {
  while (ob_get_level() > 0) { @ob_end_flush(); }
  @flush();
}
@ignore_user_abort(true);
@set_time_limit(15);
$createdAt = date('Y-m-d H:i:s');
__sp_try_gsheet_autosync($mysqli, $pubId, $absen, $nama, $score, $totalFinal, $createdAt, $answersOrdered);
