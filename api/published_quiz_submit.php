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
function __sp_try_gsheet_autosync(mysqli $mysqli, int $publishedId, int $absen, string $nama, int $score, int $total, string $createdAt, array $answersOrdered): array {
  try {
    $stmtQ = @$mysqli->prepare("SELECT user_id, mapel, kelas, slug FROM published_quizzes WHERE id=? LIMIT 1");
    if (!$stmtQ) return ['ok'=>false,'error'=>'stmt_quiz_fail'];
    $stmtQ->bind_param('i', $publishedId);
    $stmtQ->execute();
    $stmtQ->bind_result($ownerId, $mapel, $kelas, $slug);
    if (!$stmtQ->fetch()) { $stmtQ->close(); return ['ok'=>false,'error'=>'quiz_not_found']; }
    $stmtQ->close();
    $ownerId = (int)$ownerId;
    $mapel = (string)$mapel;
    $kelas = (string)$kelas;
    $slug = (string)$slug;
    if ($ownerId <= 0 || trim($mapel) === '') return ['ok'=>false,'error'=>'quiz_corrupt'];

    $stmtS = @$mysqli->prepare("SELECT spreadsheet_id, is_active, auto_sync, include_detail FROM gsheet_settings WHERE user_id=? AND mapel=? LIMIT 1");
    if (!$stmtS) return ['ok'=>false,'error'=>'stmt_gsheet_fail'];
    $stmtS->bind_param('is', $ownerId, $mapel);
    $stmtS->execute();
    $stmtS->bind_result($spreadsheetId, $isActive, $autoSync, $includeDetail);
    if (!$stmtS->fetch()) { $stmtS->close(); return ['ok'=>false,'error'=>'gsheet_not_configured']; }
    $stmtS->close();
    if ((int)$isActive !== 1) return ['ok'=>false,'error'=>'gsheet_inactive'];
    $spreadsheetId = trim((string)$spreadsheetId);
    if ($spreadsheetId === '') return ['ok'=>false,'error'=>'gsheet_id_empty'];

    $createdAt = trim((string)$createdAt);
    if ($createdAt === '') $createdAt = date('Y-m-d H:i:s');
    $pct = $total > 0 ? round(($score / $total) * 100, 2) : 0.0;

    require_once __DIR__ . '/gsheet_service.php';
    $tok = gsheet_get_access_token();
    if (!($tok['ok'] ?? false)) return ['ok'=>false,'error'=>'gsheet_token_failed','message'=>(string)($tok['message'] ?? '')];
    $accessToken = (string)($tok['access_token'] ?? '');
    if ($accessToken === '') return ['ok'=>false,'error'=>'gsheet_token_empty'];

    $upsert = function(string $sheetName, string $absenColLetter, array $header, array $rowValues) use ($spreadsheetId, $accessToken, $absen) {
      $ens = gsheet_ensure_sheet($spreadsheetId, $sheetName, $accessToken);
      if (!($ens['ok'] ?? false)) return ['ok'=>false,'error'=>'ensure_sheet_fail','sheet'=>$sheetName,'message'=>(string)($ens['message'] ?? '')];
      gsheet_write_row($spreadsheetId, $sheetName, 1, $header, $accessToken);
      $find = gsheet_find_row_in_column($spreadsheetId, $sheetName, $absenColLetter, (string)$absen, $accessToken, 2, 5000);
      $rowNum = (int)($find['row'] ?? 0);
      if ($rowNum > 0) {
        gsheet_write_row($spreadsheetId, $sheetName, $rowNum, $rowValues, $accessToken);
      } else {
        gsheet_append_row($spreadsheetId, $sheetName, $rowValues, $accessToken);
      }
      return ['ok'=>true];
    };

    $sheetName = __sp_sheet_name($kelas, 'Kelas');
    $r = $upsert($sheetName, 'D', ['Waktu Submit', 'Quiz ID', 'Slug', 'No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)'], [$createdAt, $publishedId, trim($slug), $absen, $nama, $score, $total, $pct]);
    if (!($r['ok'] ?? false)) return $r + ['step'=>'kelas_sheet'];

    $slugTrim = trim($slug);
    $hasilSheet = __sp_sheet_name('Hasil ' . ($slugTrim !== '' ? $slugTrim : (string)$publishedId), 'Hasil');
    $r = $upsert($hasilSheet, 'D', ['Waktu Submit', 'Quiz ID', 'Slug', 'No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)'], [$createdAt, $publishedId, $slugTrim, $absen, $nama, $score, $total, $pct]);
    if (!($r['ok'] ?? false)) return $r + ['step'=>'hasil_sheet'];

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
    $r = $upsert($detailSheet, 'D', $header, $row);
    if (!($r['ok'] ?? false)) return $r + ['step'=>'jawaban_sheet'];
    return ['ok'=>true];
  } catch (Throwable $e) {
    return ['ok'=>false,'error'=>'exception','message'=>$e->getMessage()];
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
$requireGSheet = false;
$nScan = min(count($items), count($answerKey));
for ($i = 0; $i < $nScan; $i++) {
  $q = is_array($items[$i] ?? null) ? $items[$i] : [];
  $t = strtolower(trim((string)($q['type'] ?? '')));
  if ($t === 'isian') $t = 'isian_singkat';
  if ($t === 'isian_singkat' || $t === 'uraian') { $requireGSheet = true; break; }
}
$gsheetOk = false;
if ($requireGSheet) {
  $stmtG = @$mysqli->prepare("SELECT spreadsheet_id, is_active FROM gsheet_settings WHERE user_id=? AND mapel=? LIMIT 1");
  if (!$stmtG) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_unavailable']);
    exit;
  }
  $stmtG->bind_param('is', $ownerId, $mapel);
  $stmtG->execute();
  $stmtG->bind_result($gsheetId, $gsheetActive);
  $has = $stmtG->fetch();
  $stmtG->close();
  $gsheetOk = $has && (int)$gsheetActive === 1 && trim((string)$gsheetId) !== '';
  if (!$gsheetOk) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_required','message'=>'Isian singkat & uraian wajib terhubung ke Google Sheets.']);
    exit;
  }
} else {
  try {
    $stmtG = @$mysqli->prepare("SELECT spreadsheet_id, is_active FROM gsheet_settings WHERE user_id=? AND mapel=? LIMIT 1");
    if ($stmtG) {
      $stmtG->bind_param('is', $ownerId, $mapel);
      $stmtG->execute();
      $stmtG->bind_result($gsheetId, $gsheetActive);
      $has = $stmtG->fetch();
      $stmtG->close();
      $gsheetOk = $has && (int)$gsheetActive === 1 && trim((string)$gsheetId) !== '';
    }
  } catch (Throwable $e) {
    $gsheetOk = false;
  }
}
$pointsByType = [];
if (is_array($decoded) && isset($decoded['settings']['points_by_type']) && is_array($decoded['settings']['points_by_type'])) {
  $pointsByType = $decoded['settings']['points_by_type'];
}
$pointsMode = '';
if (is_array($decoded) && isset($decoded['settings']['points_mode'])) $pointsMode = strtolower(trim((string)$decoded['settings']['points_mode']));
if ($pointsMode !== 'per_type_total' && $pointsMode !== 'per_question') $pointsMode = 'per_question';
$getPts = function(string $type) use ($pointsByType): int {
  $t = strtolower(trim($type));
  if ($t === 'isian') $t = 'isian_singkat';
  $v = $pointsByType[$t] ?? 1;
  $n = (int)$v;
  if ($n < 0) $n = 0;
  if ($n > 100) $n = 100;
  return $n <= 0 ? 0 : $n;
};
$score = 0;
$len = min(count($answerKey), count($answers), count($orderMap));
$totalFinal = 0;
if ($pointsMode === 'per_type_total') {
  $counts = [];
  $corrects = [];
  $typesByIdx = [];
  for ($i = 0; $i < min(count($items), count($answerKey)); $i++) {
    $q = is_array($items[$i] ?? null) ? $items[$i] : [];
    $t = strtolower(trim((string)($q['type'] ?? '')));
    if ($t === 'isian') $t = 'isian_singkat';
    if ($t === '') $t = is_array($answerKey[$i] ?? null) ? 'pg_kompleks' : 'pg';
    $typesByIdx[$i] = $t;
    if (!isset($counts[$t])) $counts[$t] = 0;
    if (!isset($corrects[$t])) $corrects[$t] = 0;
    $counts[$t]++;
  }
  $getWeight = function(string $type) use ($pointsByType, $counts): int {
    $t = strtolower(trim($type));
    if ($t === 'isian') $t = 'isian_singkat';
    $v = $pointsByType[$t] ?? null;
    if ($v === null) return (int)($counts[$t] ?? 0);
    $n = (int)$v;
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n;
  };
  for ($i = 0; $i < $len; $i++) {
    $pos = $rev[$i] ?? null;
    if ($pos === null) continue;
    $ansRaw = $answers[$pos] ?? null;
    $correctRaw = $answerKey[$i] ?? null;
    $q = is_array($items[$i] ?? null) ? $items[$i] : [];
    $type = (string)($typesByIdx[$i] ?? '');
    if ($type === '') $type = is_array($correctRaw) ? 'pg_kompleks' : 'pg';
    if ($type === 'uraian') continue;
    $ok = false;
    if ($type === 'isian_singkat') {
      $ansText = '';
      if (is_array($ansRaw)) $ansText = (string)($ansRaw[0] ?? '');
      else $ansText = (string)($ansRaw ?? '');
      $ansNorm = __sp_norm_short_text($ansText);
      if ($ansNorm !== '') {
        $keys = __sp_short_key_list($correctRaw);
        $ok = in_array($ansNorm, $keys, true);
      }
    } else if ($type === 'menjodohkan') {
      $corr = [];
      if (is_array($correctRaw)) {
        foreach ($correctRaw as $v) $corr[] = (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) ? (int)$v : -1;
      }
      $ans = [];
      if (is_array($ansRaw)) {
        foreach ($ansRaw as $v) $ans[] = (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) ? (int)$v : -1;
      } else if ($ansRaw !== null) {
        $ans[] = (is_int($ansRaw) || is_float($ansRaw) || (is_string($ansRaw) && preg_match('/^-?\d+$/', trim($ansRaw)))) ? (int)$ansRaw : -1;
      }
      $right = [];
      if (is_array($q)) {
        if (isset($q['right_options']) && is_array($q['right_options'])) $right = $q['right_options'];
        else if (isset($q['rightOptions']) && is_array($q['rightOptions'])) $right = $q['rightOptions'];
        else if (isset($q['answer']) && is_array($q['answer'])) $right = $q['answer'];
      }
      $n = min(count(is_array($q['options'] ?? null) ? $q['options'] : []), count($right));
      $corr = array_slice($corr, 0, $n);
      $ans = array_slice($ans, 0, $n);
      $ok = $n >= 2;
      for ($k = 0; $k < $n; $k++) {
        $a = $ans[$k] ?? -999;
        $c = $corr[$k] ?? -999;
        if ((int)$a !== (int)$c) { $ok = false; break; }
      }
      if (count($corr) !== $n) $ok = false;
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
      $ok = false;
      if (!empty($ans) && !empty($corr)) {
        $set = array_fill_keys($corr, 1);
        $ok = true;
        foreach ($ans as $v) {
          if (!isset($set[(int)$v])) { $ok = false; break; }
        }
      }
    } else {
      $ansIdx = -1;
      if (is_array($ansRaw)) {
        if (count($ansRaw) > 0) $ansIdx = (int)($ansRaw[0] ?? -1);
      } else if ($ansRaw !== null) {
        $ansIdx = (int)$ansRaw;
      }
      $correct = -1;
      if (is_int($correctRaw) || is_float($correctRaw) || (is_string($correctRaw) && preg_match('/^-?\d+$/', trim($correctRaw)))) $correct = (int)$correctRaw;
      $ok = ($ansIdx === $correct);
    }
    if ($ok) $corrects[$type] = (int)($corrects[$type] ?? 0) + 1;
  }
  $typesPresent = array_keys($counts);
  foreach ($typesPresent as $t) {
    $w = (int)$getWeight($t);
    $totalFinal += $w;
    if ($t === 'uraian') continue;
    $cnt = (int)($counts[$t] ?? 0);
    if ($cnt <= 0 || $w <= 0) continue;
    $c = (int)($corrects[$t] ?? 0);
    $score += (int)round(($c / $cnt) * $w);
  }
} else {
  for ($i=0; $i<$len; $i++) {
    $pos = $rev[$i] ?? null;
    if ($pos === null) continue;
    $ansRaw = $answers[$pos] ?? null;
    $correctRaw = $answerKey[$i] ?? null;
    $q = is_array($items[$i] ?? null) ? $items[$i] : [];
    $type = strtolower(trim((string)($q['type'] ?? '')));
    if ($type === 'isian') $type = 'isian_singkat';
    if ($type === '') $type = is_array($correctRaw) ? 'pg_kompleks' : 'pg';
    $pts = $getPts($type);
    if ($type === 'uraian') {
      continue;
    }
    if ($type === 'isian_singkat') {
      $ansText = '';
      if (is_array($ansRaw)) $ansText = (string)($ansRaw[0] ?? '');
      else $ansText = (string)($ansRaw ?? '');
      $ansNorm = __sp_norm_short_text($ansText);
      if ($ansNorm !== '') {
        $keys = __sp_short_key_list($correctRaw);
        if (in_array($ansNorm, $keys, true)) $score += $pts;
      }
    } else if ($type === 'menjodohkan') {
      $corr = [];
      if (is_array($correctRaw)) {
        foreach ($correctRaw as $v) $corr[] = (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) ? (int)$v : -1;
      }
      $ans = [];
      if (is_array($ansRaw)) {
        foreach ($ansRaw as $v) $ans[] = (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) ? (int)$v : -1;
      } else if ($ansRaw !== null) {
        $ans[] = (is_int($ansRaw) || is_float($ansRaw) || (is_string($ansRaw) && preg_match('/^-?\d+$/', trim($ansRaw)))) ? (int)$ansRaw : -1;
      }
      $right = [];
      if (is_array($q)) {
        if (isset($q['right_options']) && is_array($q['right_options'])) $right = $q['right_options'];
        else if (isset($q['rightOptions']) && is_array($q['rightOptions'])) $right = $q['rightOptions'];
        else if (isset($q['answer']) && is_array($q['answer'])) $right = $q['answer'];
      }
      $n = min(count(is_array($q['options'] ?? null) ? $q['options'] : []), count($right));
      $corr = array_slice($corr, 0, $n);
      $ans = array_slice($ans, 0, $n);
      $ok = true;
      for ($k = 0; $k < $n; $k++) {
        $a = $ans[$k] ?? -999;
        $c = $corr[$k] ?? -999;
        if ((int)$a !== (int)$c) { $ok = false; break; }
      }
      if ($n >= 2 && $ok) $score += $pts;
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
    $ok = false;
    if (!empty($ans) && !empty($corr)) {
      $set = array_fill_keys($corr, 1);
      $ok = true;
      foreach ($ans as $v) {
        if (!isset($set[(int)$v])) { $ok = false; break; }
      }
    }
    if ($ok) $score += $pts;
    } else {
      $ansIdx = -1;
      if (is_array($ansRaw)) {
        if (count($ansRaw) > 0) $ansIdx = (int)($ansRaw[0] ?? -1);
      } else if ($ansRaw !== null) {
        $ansIdx = (int)$ansRaw;
      }
      $correct = -1;
      if (is_int($correctRaw) || is_float($correctRaw) || (is_string($correctRaw) && preg_match('/^-?\d+$/', trim($correctRaw)))) $correct = (int)$correctRaw;
      if ($ansIdx === $correct) $score += $pts;
    }
  }
  $totalPoints = 0;
  for ($i = 0; $i < min(count($items), count($answerKey)); $i++) {
    $q = is_array($items[$i] ?? null) ? $items[$i] : [];
    $t = strtolower(trim((string)($q['type'] ?? '')));
    if ($t === 'isian') $t = 'isian_singkat';
    if ($t === '') $t = is_array($answerKey[$i] ?? null) ? 'pg_kompleks' : 'pg';
    $totalPoints += $getPts($t);
  }
  $totalFinal = $totalPoints;
}
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
  } else if ($type === 'uraian') {
    $txt = '';
    if (is_array($ansRaw)) $txt = (string)($ansRaw[0] ?? '');
    else $txt = (string)($ansRaw ?? '');
    $txt = trim($txt);
    $txt = preg_replace('/\s+/', ' ', (string)$txt);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($txt, 'UTF-8') > 800) $txt = mb_substr($txt, 0, 800, 'UTF-8');
    } else {
      if (strlen($txt) > 800) $txt = substr($txt, 0, 800);
    }
    $answersOrdered[] = $txt;
  } else if ($type === 'menjodohkan') {
    $arr = is_array($ansRaw) ? $ansRaw : [$ansRaw];
    $letters = [];
    foreach ($arr as $v) {
      $idx = (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', trim($v)))) ? (int)$v : -1;
      if ($idx >= 0 && $idx < 26) $letters[] = chr(65 + $idx);
      else $letters[] = '';
    }
    $answersOrdered[] = implode(',', array_map(fn($x) => $x === '' ? '-' : $x, $letters));
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
$createdAt = date('Y-m-d H:i:s');
try {
  $stmtR = @$mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, nama, score, total, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama), score=VALUES(score), total=VALUES(total), created_at=VALUES(created_at)");
  if ($stmtR) {
    $stmtR->bind_param('iisiss', $pubId, $absen, $nama, $score, $totalFinal, $createdAt);
    @$stmtR->execute();
    $stmtR->close();
  }
} catch (Throwable $e) {
}
if ($gsheetOk) {
  if (function_exists('set_time_limit')) { @set_time_limit(60); }
  if (function_exists('ini_set')) { @ini_set('max_execution_time', '60'); }
  $sync = __sp_try_gsheet_autosync($mysqli, $pubId, $absen, $nama, $score, $totalFinal, $createdAt, $answersOrdered);
  if (!($sync['ok'] ?? false)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_sync_failed','details'=>$sync], JSON_UNESCAPED_UNICODE);
    exit;
  }
}
echo json_encode(['ok'=>true,'status'=>'saved','score'=>$score,'total'=>$totalFinal], JSON_UNESCAPED_UNICODE);
