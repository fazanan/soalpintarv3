<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
$__sp_ensure_manual_grade_table = function(mysqli $db): void {
  $sql = "
CREATE TABLE IF NOT EXISTS published_quiz_manual_grades (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  published_id INT UNSIGNED NOT NULL,
  absen INT UNSIGNED NOT NULL,
  grades_json LONGTEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_pub_absen (published_id, absen),
  INDEX idx_pub (published_id),
  CONSTRAINT fk_pqmg_pub FOREIGN KEY (published_id) REFERENCES published_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
";
  @$db->query($sql);
};
$__sp_sheet_name = function(string $name, string $fallback): string {
  $n = trim($name);
  if ($n === '') $n = $fallback;
  $n = preg_replace('/[\[\]\:\?\*\/\\\\]/', ' ', $n);
  $n = preg_replace('/\s+/', ' ', $n);
  $n = trim($n);
  if ($n === '') $n = $fallback;
  if (function_exists('mb_substr')) $n = mb_substr($n, 0, 100, 'UTF-8');
  else $n = substr($n, 0, 100);
  return $n;
};
$role = (string)($_SESSION['role'] ?? 'user');
if ($role !== 'admin') {
  $access = isset($_SESSION['access_quiz']) ? (int)$_SESSION['access_quiz'] : null;
  if ($access === null) {
    $stmtAcc = null;
    try { $stmtAcc = $mysqli->prepare("SELECT access_quiz FROM users WHERE id=? LIMIT 1"); } catch (mysqli_sql_exception $e) { $stmtAcc = null; }
    if ($stmtAcc) {
      $stmtAcc->bind_param('i', $_SESSION['user_id']);
      $stmtAcc->execute();
      $aq = null;
      $stmtAcc->bind_result($aq);
      if ($stmtAcc->fetch()) $access = (int)$aq;
      $stmtAcc->close();
    }
    if ($access === null) $access = 1;
  }
  if ($access !== 1) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'no_access']);
    exit;
  }
}
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : '';
if ($slug === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'missing_slug']);
  exit;
}
$mode = isset($_GET['mode']) ? trim((string)$_GET['mode']) : '';
$absenParam = isset($_GET['absen']) ? (int)$_GET['absen'] : 0;
$stmt = $mysqli->prepare("SELECT id, mapel, kelas FROM published_quizzes WHERE slug=? AND user_id=? LIMIT 1");
$stmt->bind_param('si', $slug, $_SESSION['user_id']);
$stmt->execute();
$pubId = 0;
$mapel = '';
$kelas = '';
$stmt->bind_result($pubId, $mapel, $kelas);
if (!$stmt->fetch()) {
  $stmt->close();
  http_response_code(404);
  echo json_encode(['ok'=>false,'error'=>'not_found']);
  exit;
}
$stmt->close();
$stmt = null;

if ($mode === 'grade') {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method_not_allowed']);
    exit;
  }
  $raw = file_get_contents('php://input');
  $data = json_decode($raw, true);
  if (!is_array($data)) $data = $_POST;
  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_json']);
    exit;
  }
  $absen = isset($data['absen']) ? (int)$data['absen'] : 0;
  $gradesIn = (isset($data['grades']) && is_array($data['grades'])) ? $data['grades'] : [];
  if ($absen <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_absen']);
    exit;
  }

  $uid = (int)$_SESSION['user_id'];
  $stmtQ = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public, answer_key FROM published_quizzes WHERE id=? AND user_id=? LIMIT 1");
  if (!$stmtQ) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'stmt_fail']);
    exit;
  }
  $stmtQ->bind_param('ii', $pubId, $uid);
  $stmtQ->execute();
  $stmtQ->bind_result($qid, $slugDb, $mapelDb, $kelasDb, $payloadJson, $answerKeyJson);
  if (!$stmtQ->fetch()) {
    $stmtQ->close();
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found']);
    exit;
  }
  $stmtQ->close();

  require_once __DIR__ . '/gsheet_service.php';
  $stmtS = $mysqli->prepare("SELECT spreadsheet_id FROM gsheet_settings WHERE user_id=? AND mapel=? AND is_active=1 LIMIT 1");
  if (!$stmtS) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $stmtS->bind_param('is', $uid, $mapelDb);
  $stmtS->execute();
  $stmtS->bind_result($spreadsheetId);
  if (!$stmtS->fetch()) {
    $stmtS->close();
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $stmtS->close();
  $spreadsheetId = trim((string)$spreadsheetId);
  if ($spreadsheetId === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $tok = gsheet_get_access_token();
  if (!($tok['ok'] ?? false)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_token_failed','message'=>(string)($tok['message'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $accessToken = (string)($tok['access_token'] ?? '');
  if ($accessToken === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_token_empty']);
    exit;
  }

  $payload = json_decode((string)$payloadJson, true);
  $quizItems = [];
  if (is_array($payload)) {
    if (isset($payload['items']) && is_array($payload['items'])) $quizItems = $payload['items'];
    else $quizItems = $payload;
  }
  $answerKey = json_decode((string)$answerKeyJson, true);
  if (!is_array($answerKey)) $answerKey = [];

  $pointsByType = [];
  if (is_array($payload) && isset($payload['settings']['points_by_type']) && is_array($payload['settings']['points_by_type'])) {
    $pointsByType = $payload['settings']['points_by_type'];
  }
  $pointsMode = 'per_question';
  if (is_array($payload) && isset($payload['settings']['points_mode'])) {
    $pm = strtolower(trim((string)$payload['settings']['points_mode']));
    if ($pm === 'per_type_total' || $pm === 'per_question') $pointsMode = $pm;
  }
  $getPts = function(string $type) use ($pointsByType): int {
    $t = strtolower(trim($type));
    if ($t === 'isian') $t = 'isian_singkat';
    $v = $pointsByType[$t] ?? 1;
    $n = (int)$v;
    if ($n < 0) $n = 0;
    if ($n > 100) $n = 100;
    return $n;
  };

  $fetchSheetVals = function(string $sheetName) use ($spreadsheetId, $accessToken) {
    $range = $sheetName . '!A1:ZZ5000';
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?majorDimension=ROWS';
    $resp = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
    if (!($resp['ok'] ?? false) || !is_array($resp['json'])) return null;
    $vals = $resp['json']['values'] ?? null;
    if (!is_array($vals) || count($vals) < 2) return null;
    $hdr0 = is_array($vals[0]) ? $vals[0] : [];
    $hdr = array_map(fn($x) => strtolower(trim((string)$x)), $hdr0);
    $hasJawabanJson = false;
    $hasJCols = false;
    foreach ($hdr as $h) {
      if ($h === '') continue;
      if (strpos($h, 'jawaban json') !== false) { $hasJawabanJson = true; break; }
      if (preg_match('/^j\d+$/', $h)) { $hasJCols = true; break; }
    }
    if (!$hasJawabanJson && !$hasJCols) return null;
    return $vals;
  };

  $slugTrim = trim((string)$slugDb);
  $candidates = [
    $__sp_sheet_name('Jawaban ' . $slugTrim, 'Jawaban'),
    $__sp_sheet_name('Jawaban', 'Jawaban'),
    $__sp_sheet_name((string)$kelasDb, 'Kelas'),
  ];
  $candidates = array_values(array_unique(array_filter($candidates, fn($x) => is_string($x) && trim($x) !== '')));

  $sheetName = '';
  $vals = null;
  $tried = [];
  foreach ($candidates as $sn) {
    $tried[] = $sn;
    $vals = $fetchSheetVals($sn);
    if ($vals !== null) { $sheetName = $sn; break; }
  }
  if ($vals === null) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'answers_not_found','message'=>'Sheet jawaban tidak ditemukan/empty', 'tried'=>$tried], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $header = array_map(fn($x) => strtolower(trim((string)$x)), is_array($vals[0]) ? $vals[0] : []);
  $findCol = function(array $hdr, array $needles): int {
    for ($i = 0; $i < count($hdr); $i++) {
      $h = (string)($hdr[$i] ?? '');
      foreach ($needles as $n) {
        if ($n !== '' && strpos($h, $n) !== false) return $i;
      }
    }
    return -1;
  };
  $iTime = $findCol($header, ['waktu']);
  $iAbsen = $findCol($header, ['absen']);
  $iNama = $findCol($header, ['nama']);
  $iAnsJson = $findCol($header, ['jawaban json']);
  if ($iAbsen < 0) {
    $iAbsen = 3;
    if ($iAbsen >= count($header)) $iAbsen = 0;
  }
  $jCols = [];
  for ($ci = 0; $ci < count($header); $ci++) {
    $h = (string)($header[$ci] ?? '');
    if (preg_match('/^j(\d+)$/', $h, $m)) {
      $jCols[] = ['idx' => $ci, 'n' => (int)$m[1]];
    }
  }
  usort($jCols, fn($a, $b) => ($a['n'] ?? 0) <=> ($b['n'] ?? 0));

  $best = null;
  $bestTs = 0;
  for ($r = 1; $r < count($vals); $r++) {
    $row = is_array($vals[$r]) ? $vals[$r] : [];
    $ab = (int)trim((string)($row[$iAbsen] ?? '0'));
    if ($ab !== $absen) continue;
    $tsStr = $iTime >= 0 ? (string)($row[$iTime] ?? '') : '';
    $ts = $tsStr ? strtotime($tsStr) : 0;
    if ($best === null || $ts >= $bestTs) {
      $best = $row;
      $bestTs = $ts;
    }
  }
  if (!$best) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'answers_not_found','message'=>'Jawaban untuk absen tersebut belum ada di sheet','sheet'=>$sheetName], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $jawabanJson = $iAnsJson >= 0 ? (string)($best[$iAnsJson] ?? '') : '';
  $jawabanJson = trim($jawabanJson);
  if ($jawabanJson !== '' && ($jawabanJson[0] === "'" || $jawabanJson[0] === "’")) $jawabanJson = ltrim(substr($jawabanJson, 1));
  $ansArr = null;
  if ($jawabanJson !== '') {
    $tmp = json_decode($jawabanJson, true);
    if (is_array($tmp)) $ansArr = $tmp;
    else if (is_string($tmp) && trim($tmp) !== '') {
      $tmp2 = json_decode($tmp, true);
      if (is_array($tmp2)) $ansArr = $tmp2;
    }
  }
  if (!is_array($ansArr) || count($ansArr) === 0) {
    $tmpJ = [];
    foreach ($jCols as $jc) {
      $ci = (int)($jc['idx'] ?? -1);
      if ($ci < 0) continue;
      $tmpJ[] = (string)($best[$ci] ?? '');
    }
    $tmpJ = array_map(fn($x) => trim((string)$x), $tmpJ);
    $ansArr = array_values(array_map(fn($x) => $x === '-' ? '' : $x, $tmpJ));
  }
  $ansArr = array_values($ansArr);

  $toIdx = function(string $type, $val): int {
    if ($val === null || $val === '') return -1;
    if (is_int($val) || is_float($val)) return (int)$val;
    $v = trim((string)$val);
    if ($v === '') return -1;
    if ($type === 'benar_salah') {
      if (preg_match('/^benar$/i', $v)) return 0;
      if (preg_match('/^salah$/i', $v)) return 1;
    }
    $m = preg_match('/^[A-Z]$/', strtoupper($v)) ? strtoupper($v) : '';
    if ($m !== '') return ord($m) - 65;
    if (preg_match('/^-?\d+$/', $v)) return (int)$v;
    return -1;
  };
  $toIdxList = function(string $type, $val) use ($toIdx): array {
    if ($type !== 'pg_kompleks') {
      $x = $toIdx($type, $val);
      return $x >= 0 ? [$x] : [];
    }
    if (is_array($val)) return array_values(array_filter(array_map(fn($x) => $toIdx('pg', $x), $val), fn($n) => $n >= 0));
    $v = trim((string)$val);
    if ($v === '') return [];
    $parts = preg_split('/[,\s;\/\|]+/', $v, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
      $n = $toIdx('pg', $p);
      if ($n >= 0) $out[] = $n;
    }
    $out = array_values(array_unique($out));
    sort($out);
    return $out;
  };
  $toMatchList = function($val) use ($toIdx): array {
    if (is_array($val)) return array_map(fn($x) => is_numeric($x) ? (int)$x : -1, $val);
    $v = trim((string)$val);
    if ($v === '') return [];
    $parts = preg_split('/[,\s;\/\|]+/', $v, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
      $p = trim((string)$p);
      if ($p === '-' || $p === '') { $out[] = -1; continue; }
      $out[] = $toIdx('pg', $p);
    }
    return $out;
  };
  $normShort = function($s): string {
    $v = (string)($s ?? '');
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = trim($v);
    $v = preg_replace('/\s+/', ' ', $v);
    $v = preg_replace('/^[\s"\'`]+/', '', (string)$v);
    $v = preg_replace('/[\s"\'`]+$/', '', (string)$v);
    $v = preg_replace('/[.,;:!?]+$/', '', (string)$v);
    $v = trim((string)$v);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
  };
  $shortKeyList = function($raw) use ($normShort): array {
    $out = [];
    $push = function($x) use (&$out, $normShort) {
      $t = trim((string)($x ?? ''));
      if ($t === '') return;
      $n = $normShort($t);
      if ($n === '') return;
      $out[$n] = 1;
    };
    if (is_array($raw)) foreach ($raw as $v) $push($v);
    else if (is_string($raw)) {
      if (strpos($raw, '|') !== false) foreach (explode('|', $raw) as $v) $push($v);
      else $push($raw);
    } else $push($raw);
    return array_keys($out);
  };

  $gradesNorm = [];
  $totalPoints = 0;
  $score = 0;
  if ($pointsMode === 'per_type_total') {
    $counts = [];
    $typesByIdx = [];
    $nAll = min(count($quizItems), count($answerKey), count($ansArr));
    for ($i = 0; $i < $nAll; $i++) {
      $q = is_array($quizItems[$i] ?? null) ? $quizItems[$i] : [];
      $t = strtolower(trim((string)($q['type'] ?? '')));
      if ($t === 'isian') $t = 'isian_singkat';
      if ($t === '') $t = is_array($answerKey[$i] ?? null) ? 'pg_kompleks' : 'pg';
      $typesByIdx[$i] = $t;
      if (!isset($counts[$t])) $counts[$t] = 0;
      $counts[$t]++;
    }
    $getWeight = function(string $type) use ($pointsByType, $counts): int {
      $t = strtolower(trim($type));
      if ($t === 'isian') $t = 'isian_singkat';
      if (!array_key_exists($t, $counts)) return 0;
      $v = $pointsByType[$t] ?? null;
      if ($v === null) return (int)($counts[$t] ?? 0);
      $n = (int)$v;
      if ($n < 0) $n = 0;
      if ($n > 100) $n = 100;
      return $n;
    };

    for ($i = 0; $i < $nAll; $i++) {
      $t = (string)($typesByIdx[$i] ?? '');
      if ($t !== 'isian_singkat' && $t !== 'uraian') continue;
      $max = $getWeight($t);
      $rawV = $gradesIn[(string)$i] ?? ($gradesIn[$i] ?? null);
      if ($rawV === null) continue;
      $n = (int)$rawV;
      if ($n < 0) $n = 0;
      if ($n > $max) $n = $max;
      $gradesNorm[(string)$i] = $n;
    }

    $sumByType = [];
    $correctByType = [];
    foreach ($counts as $t => $_c) { $sumByType[$t] = 0; $correctByType[$t] = 0; }
    for ($i = 0; $i < $nAll; $i++) {
      $t = (string)($typesByIdx[$i] ?? '');
      $ans = $ansArr[$i] ?? null;
      $key = $answerKey[$i] ?? null;
      if ($t === 'uraian') {
        if (array_key_exists((string)$i, $gradesNorm)) $sumByType[$t] += (int)$gradesNorm[(string)$i];
        continue;
      }
      if ($t === 'isian_singkat') {
        if (array_key_exists((string)$i, $gradesNorm)) { $sumByType[$t] += (int)$gradesNorm[(string)$i]; continue; }
        $ansNorm = $normShort($ans);
        if ($ansNorm !== '' && in_array($ansNorm, $shortKeyList($key), true)) $correctByType[$t] += 1;
        continue;
      }
      if ($t === 'pg_kompleks') {
        $corr = is_array($key) ? array_values(array_unique(array_map('intval', $key))) : [];
        sort($corr);
        $a = $toIdxList('pg_kompleks', $ans);
        $ok = false;
        if (!empty($a) && !empty($corr)) {
          $set = array_fill_keys($corr, 1);
          $ok = true;
          foreach ($a as $v) {
            if (!isset($set[(int)$v])) { $ok = false; break; }
          }
        }
        if ($ok) $correctByType[$t] += 1;
        continue;
      }
      if ($t === 'menjodohkan') {
        $corr = is_array($key) ? array_map('intval', $key) : [];
        $a = $toMatchList($ans);
        $n = min(count($corr), count($a));
        $ok = $n >= 2 && count($corr) === $n;
        for ($k = 0; $k < $n; $k++) {
          if ((int)($a[$k] ?? -999) !== (int)($corr[$k] ?? -999)) { $ok = false; break; }
        }
        if ($ok) $correctByType[$t] += 1;
        continue;
      }
      $ai = $toIdx($t === 'benar_salah' ? 'benar_salah' : 'pg', $ans);
      $ck = (int)$key;
      if ($ai === $ck) $correctByType[$t] += 1;
    }

    foreach ($counts as $t => $cnt) {
      $w = $getWeight($t);
      $totalPoints += $w;
      if ($w <= 0 || $cnt <= 0) continue;
      if ($t === 'uraian') {
        $score += (int)min($w, max(0, (int)($sumByType[$t] ?? 0)));
        continue;
      }
      if ($t === 'isian_singkat') {
        $anyManual = false;
        for ($i = 0; $i < $nAll; $i++) {
          if ((string)($typesByIdx[$i] ?? '') !== 'isian_singkat') continue;
          if (array_key_exists((string)$i, $gradesNorm)) { $anyManual = true; break; }
        }
        if ($anyManual) {
          $score += (int)min($w, max(0, (int)($sumByType[$t] ?? 0)));
        } else {
          $c = (int)($correctByType[$t] ?? 0);
          $score += (int)round(($c / $cnt) * $w);
        }
        continue;
      }
      $c = (int)($correctByType[$t] ?? 0);
      $score += (int)round(($c / $cnt) * $w);
    }
  } else {
    for ($i = 0; $i < min(count($quizItems), count($answerKey)); $i++) {
      $q = is_array($quizItems[$i] ?? null) ? $quizItems[$i] : [];
      $t = strtolower(trim((string)($q['type'] ?? '')));
      if ($t === 'isian') $t = 'isian_singkat';
      if ($t !== 'isian_singkat' && $t !== 'uraian') continue;
      $pts = $getPts($t);
      $rawV = $gradesIn[(string)$i] ?? ($gradesIn[$i] ?? null);
      if ($rawV === null) continue;
      $n = (int)$rawV;
      if ($n < 0) $n = 0;
      if ($n > $pts) $n = $pts;
      $gradesNorm[(string)$i] = $n;
    }
    for ($i = 0; $i < min(count($quizItems), count($answerKey)); $i++) {
      $q = is_array($quizItems[$i] ?? null) ? $quizItems[$i] : [];
      $t = strtolower(trim((string)($q['type'] ?? '')));
      if ($t === 'isian') $t = 'isian_singkat';
      if ($t === '') $t = is_array($answerKey[$i] ?? null) ? 'pg_kompleks' : 'pg';
      $pts = $getPts($t);
      $totalPoints += $pts;
      $ans = $ansArr[$i] ?? null;
      $key = $answerKey[$i] ?? null;
      if ($t === 'uraian') {
        $score += (int)($gradesNorm[(string)$i] ?? 0);
        continue;
      }
      if ($t === 'isian_singkat') {
        if (array_key_exists((string)$i, $gradesNorm)) { $score += (int)$gradesNorm[(string)$i]; continue; }
        $ansNorm = $normShort($ans);
        if ($ansNorm !== '' && in_array($ansNorm, $shortKeyList($key), true)) $score += $pts;
        continue;
      }
      if ($t === 'pg_kompleks') {
        $corr = is_array($key) ? array_values(array_unique(array_map('intval', $key))) : [];
        sort($corr);
        $a = $toIdxList('pg_kompleks', $ans);
      $ok = false;
      if (!empty($a) && !empty($corr)) {
        $set = array_fill_keys($corr, 1);
        $ok = true;
        foreach ($a as $v) {
          if (!isset($set[(int)$v])) { $ok = false; break; }
        }
      }
      if ($ok) $score += $pts;
        continue;
      }
      if ($t === 'menjodohkan') {
        $corr = is_array($key) ? array_map('intval', $key) : [];
        $a = $toMatchList($ans);
        $n = min(count($corr), count($a));
        $ok = $n >= 2;
        for ($k = 0; $k < $n; $k++) {
          if ((int)($a[$k] ?? -999) !== (int)($corr[$k] ?? -999)) { $ok = false; break; }
        }
        if ($ok && count($corr) === $n) $score += $pts;
        continue;
      }
      $ai = $toIdx($t === 'benar_salah' ? 'benar_salah' : 'pg', $ans);
      $ck = (int)$key;
      if ($ai === $ck) $score += $pts;
    }
  }

  $createdAt = date('Y-m-d H:i:s');
  $pct = $totalPoints > 0 ? round(($score / $totalPoints) * 100, 2) : 0.0;

  try {
    $__sp_ensure_manual_grade_table($mysqli);
    $gj = json_encode($gradesNorm, JSON_UNESCAPED_UNICODE);
    if (!is_string($gj)) $gj = '{}';
    $stmtMG = @$mysqli->prepare("INSERT INTO published_quiz_manual_grades (published_id, absen, grades_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE grades_json=VALUES(grades_json), updated_at=CURRENT_TIMESTAMP");
    if ($stmtMG) {
      $stmtMG->bind_param('iis', $qid, $absen, $gj);
      @$stmtMG->execute();
      $stmtMG->close();
    }
  } catch (Throwable $e) {
  }

  $nm = $iNama >= 0 ? (string)($best[$iNama] ?? '') : '';
  try {
    $stmtR = @$mysqli->prepare("INSERT INTO published_quiz_results (published_id, absen, nama, score, total, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE nama=VALUES(nama), score=VALUES(score), total=VALUES(total), created_at=VALUES(created_at)");
    if ($stmtR) {
      $stmtR->bind_param('iisiss', $qid, $absen, $nm, $score, $totalPoints, $createdAt);
      @$stmtR->execute();
      $stmtR->close();
    }
  } catch (Throwable $e) {
  }

  $updateScoreRow = function(string $sheet) use ($spreadsheetId, $accessToken, $absen, $qid, $slugDb, $nm, $score, $totalPoints, $pct, $createdAt) {
    $sheetName = trim($sheet);
    if ($sheetName === '') return;
    $ens = gsheet_ensure_sheet($spreadsheetId, $sheetName, $accessToken);
    if (!($ens['ok'] ?? false)) return;
    gsheet_write_row($spreadsheetId, $sheetName, 1, ['Waktu Submit','Quiz ID','Slug','No Absen','Nama','Skor','Total','Nilai (%)'], $accessToken);
    $find = gsheet_find_row_in_column($spreadsheetId, $sheetName, 'D', (string)$absen, $accessToken, 2, 5000);
    $rowNum = (int)($find['row'] ?? 0);
    if ($rowNum <= 0) return;
    gsheet_write_row($spreadsheetId, $sheetName, $rowNum, [$createdAt, $qid, (string)$slugDb, $absen, $nm, $score, $totalPoints, $pct], $accessToken);
  };
  $updateScoreRow($__sp_sheet_name((string)$kelasDb, 'Kelas'));
  $updateScoreRow($__sp_sheet_name('Hasil ' . (trim((string)$slugDb) !== '' ? trim((string)$slugDb) : (string)$qid), 'Hasil'));
  $updateScoreRow($sheetName);

  echo json_encode(['ok'=>true,'score'=>$score,'total'=>$totalPoints,'pct'=>$pct,'grades'=>$gradesNorm], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($mode === 'answers') {
  if ($absenParam <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'missing_absen']);
    exit;
  }
  $stmtQ = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public, answer_key FROM published_quizzes WHERE id=? AND user_id=? LIMIT 1");
  if (!$stmtQ) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'stmt_fail']);
    exit;
  }
  $uid = (int)$_SESSION['user_id'];
  $stmtQ->bind_param('ii', $pubId, $uid);
  $stmtQ->execute();
  $stmtQ->bind_result($qid, $slugDb, $mapelDb, $kelasDb, $payloadJson, $answerKeyJson);
  if (!$stmtQ->fetch()) {
    $stmtQ->close();
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'not_found']);
    exit;
  }
  $stmtQ->close();

  require_once __DIR__ . '/gsheet_service.php';
  $stmtS = $mysqli->prepare("SELECT spreadsheet_id FROM gsheet_settings WHERE user_id=? AND mapel=? AND is_active=1 LIMIT 1");
  if (!$stmtS) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $stmtS->bind_param('is', $uid, $mapelDb);
  $stmtS->execute();
  $stmtS->bind_result($spreadsheetId);
  if (!$stmtS->fetch()) {
    $stmtS->close();
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $stmtS->close();
  $spreadsheetId = trim((string)$spreadsheetId);
  if ($spreadsheetId === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_not_configured']);
    exit;
  }
  $tok = gsheet_get_access_token();
  if (!($tok['ok'] ?? false)) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_token_failed','message'=>(string)($tok['message'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $accessToken = (string)($tok['access_token'] ?? '');
  if ($accessToken === '') {
    http_response_code(503);
    echo json_encode(['ok'=>false,'error'=>'gsheet_token_empty']);
    exit;
  }

  $fetchSheetVals = function(string $sheetName) use ($spreadsheetId, $accessToken) {
    $range = $sheetName . '!A1:ZZ5000';
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?majorDimension=ROWS';
    $resp = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
    if (!($resp['ok'] ?? false) || !is_array($resp['json'])) return null;
    $vals = $resp['json']['values'] ?? null;
    if (!is_array($vals) || count($vals) < 2) return null;
    $hdr0 = is_array($vals[0]) ? $vals[0] : [];
    $hdr = array_map(fn($x) => strtolower(trim((string)$x)), $hdr0);
    $hasJawabanJson = false;
    $hasJCols = false;
    foreach ($hdr as $h) {
      if ($h === '') continue;
      if (strpos($h, 'jawaban json') !== false) { $hasJawabanJson = true; break; }
      if (preg_match('/^j\d+$/', $h)) { $hasJCols = true; break; }
    }
    if (!$hasJawabanJson && !$hasJCols) return null;
    return $vals;
  };

  $slugTrim = trim((string)$slugDb);
  $candidates = [
    $__sp_sheet_name('Jawaban ' . $slugTrim, 'Jawaban'),
    $__sp_sheet_name('Jawaban', 'Jawaban'),
    $__sp_sheet_name((string)$kelasDb, 'Kelas'),
  ];
  $candidates = array_values(array_unique(array_filter($candidates, fn($x) => is_string($x) && trim($x) !== '')));

  $sheetName = '';
  $vals = null;
  $tried = [];
  foreach ($candidates as $sn) {
    $tried[] = $sn;
    $vals = $fetchSheetVals($sn);
    if ($vals !== null) { $sheetName = $sn; break; }
  }

  if ($vals === null) {
    $metaUrl = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=sheets.properties.title';
    $meta = gsheet_http_json('GET', $metaUrl, ['Authorization: Bearer ' . $accessToken]);
    $titles = [];
    if (($meta['ok'] ?? false) && is_array($meta['json']) && is_array($meta['json']['sheets'] ?? null)) {
      foreach ($meta['json']['sheets'] as $s) {
        $t = is_array($s) ? (string)($s['properties']['title'] ?? '') : '';
        $t = trim($t);
        if ($t !== '') $titles[] = $t;
      }
    }
    $pref = array_values(array_filter($titles, fn($t) => stripos($t, 'jawaban') !== false));
    $rest = array_values(array_filter($titles, fn($t) => stripos($t, 'jawaban') === false));
    $scan = array_slice(array_merge($pref, $rest), 0, 20);
    foreach ($scan as $sn) {
      if (in_array($sn, $tried, true)) continue;
      $tried[] = $sn;
      $vals = $fetchSheetVals($sn);
      if ($vals !== null) { $sheetName = $sn; break; }
    }
  }

  if ($vals === null) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'answers_not_found','message'=>'Sheet jawaban tidak ditemukan/empty', 'tried'=>$tried], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $header = array_map(fn($x) => strtolower(trim((string)$x)), is_array($vals[0]) ? $vals[0] : []);
  $findCol = function(array $hdr, array $needles): int {
    for ($i = 0; $i < count($hdr); $i++) {
      $h = (string)($hdr[$i] ?? '');
      foreach ($needles as $n) {
        if ($n !== '' && strpos($h, $n) !== false) return $i;
      }
    }
    return -1;
  };
  $iTime = $findCol($header, ['waktu']);
  $iQuizId = $findCol($header, ['quiz id']);
  $iSlug = $findCol($header, ['slug']);
  $iAbsen = $findCol($header, ['absen']);
  $iNama = $findCol($header, ['nama']);
  $iScore = $findCol($header, ['skor', 'score']);
  $iTotal = $findCol($header, ['total']);
  $iPct = $findCol($header, ['nilai', '%']);
  $iAnsJson = $findCol($header, ['jawaban json']);
  if ($iAbsen < 0) {
    $iAbsen = 3;
    if ($iAbsen >= count($header)) $iAbsen = 0;
  }
  $jCols = [];
  for ($ci = 0; $ci < count($header); $ci++) {
    $h = (string)($header[$ci] ?? '');
    if (preg_match('/^j(\d+)$/', $h, $m)) {
      $jCols[] = ['idx' => $ci, 'n' => (int)$m[1]];
    }
  }
  usort($jCols, fn($a, $b) => ($a['n'] ?? 0) <=> ($b['n'] ?? 0));

  $best = null;
  $bestTs = 0;
  for ($r = 1; $r < count($vals); $r++) {
    $row = is_array($vals[$r]) ? $vals[$r] : [];
    $ab = (int)trim((string)($row[$iAbsen] ?? '0'));
    if ($ab !== $absenParam) continue;
    $tsStr = $iTime >= 0 ? (string)($row[$iTime] ?? '') : '';
    $ts = $tsStr ? strtotime($tsStr) : 0;
    if ($best === null || $ts >= $bestTs) {
      $best = $row;
      $bestTs = $ts;
    }
  }
  if (!$best) {
    http_response_code(404);
    echo json_encode([
      'ok'=>false,
      'error'=>'answers_not_found',
      'message'=>'Jawaban untuk absen tersebut belum ada di sheet',
      'sheet'=>$sheetName,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $jawabanJson = $iAnsJson >= 0 ? (string)($best[$iAnsJson] ?? '') : '';
  $jawabanJson = trim($jawabanJson);
  if ($jawabanJson !== '' && ($jawabanJson[0] === "'" || $jawabanJson[0] === "’")) {
    $jawabanJson = ltrim(substr($jawabanJson, 1));
  }
  $ansArr = null;
  if ($jawabanJson !== '') {
    $tmp = json_decode($jawabanJson, true);
    if (is_array($tmp)) {
      $ansArr = $tmp;
    } else if (is_string($tmp) && trim($tmp) !== '') {
      $tmp2 = json_decode($tmp, true);
      if (is_array($tmp2)) $ansArr = $tmp2;
    }
  }
  if (!is_array($ansArr) || count($ansArr) === 0) {
    $tmpJ = [];
    foreach ($jCols as $jc) {
      $ci = (int)($jc['idx'] ?? -1);
      if ($ci < 0) continue;
      $tmpJ[] = (string)($best[$ci] ?? '');
    }
    $tmpJ = array_map(fn($x) => trim((string)$x), $tmpJ);
    $ansArr = array_values(array_map(fn($x) => $x === '-' ? '' : $x, $tmpJ));
  }

  $payload = json_decode((string)$payloadJson, true);
  $items = [];
  if (is_array($payload)) {
    if (isset($payload['items']) && is_array($payload['items'])) $items = $payload['items'];
    else $items = $payload;
  }
  $answerKey = json_decode((string)$answerKeyJson, true);
  if (!is_array($answerKey)) $answerKey = [];

  $nm = $iNama >= 0 ? (string)($best[$iNama] ?? '') : '';
  $sc = $iScore >= 0 ? (int)trim((string)($best[$iScore] ?? '0')) : 0;
  $tt = $iTotal >= 0 ? (int)trim((string)($best[$iTotal] ?? '0')) : 0;
  $tsOut = $iTime >= 0 ? (string)($best[$iTime] ?? '') : '';
  $manualGrades = null;
  try {
    $__sp_ensure_manual_grade_table($mysqli);
    $stmtMG = @$mysqli->prepare("SELECT grades_json FROM published_quiz_manual_grades WHERE published_id=? AND absen=? LIMIT 1");
    if ($stmtMG) {
      $stmtMG->bind_param('ii', $qid, $absenParam);
      $stmtMG->execute();
      $stmtMG->bind_result($gjson);
      if ($stmtMG->fetch()) {
        $tmp = json_decode((string)$gjson, true);
        if (is_array($tmp)) $manualGrades = $tmp;
      }
      $stmtMG->close();
    }
  } catch (Throwable $e) {}

  echo json_encode([
    'ok' => true,
    'quiz' => [
      'id' => (int)$qid,
      'slug' => (string)$slugDb,
      'mapel' => (string)$mapelDb,
      'kelas' => (string)$kelasDb,
    ],
    'student' => [
      'absen' => $absenParam,
      'nama' => $nm,
      'score' => $sc,
      'total' => $tt,
      'created_at' => $tsOut,
    ],
    'items' => $items,
    'answer_key' => $answerKey,
    'answers' => array_values($ansArr),
    'manual_grades' => $manualGrades,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
try {
  $stmt = $mysqli->prepare("SELECT absen, nama, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY score DESC, absen ASC");
} catch (mysqli_sql_exception $e) {
  $stmt = null;
}
if (!$stmt) $stmt = $mysqli->prepare("SELECT absen, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY score DESC, absen ASC");
$stmt->bind_param('i', $pubId);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();
if (!empty($rows)) {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>$rows], JSON_UNESCAPED_UNICODE);
  exit;
}

require_once __DIR__ . '/gsheet_service.php';

$slugTrim = trim((string)$slug);
$mapel = (string)$mapel;
$kelas = (string)$kelas;
$userId = (int)$_SESSION['user_id'];
$stmtS = $mysqli->prepare("SELECT spreadsheet_id FROM gsheet_settings WHERE user_id=? AND mapel=? AND is_active=1 LIMIT 1");
if (!$stmtS) {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}
$stmtS->bind_param('is', $userId, $mapel);
$stmtS->execute();
$stmtS->bind_result($spreadsheetId);
if (!$stmtS->fetch()) {
  $stmtS->close();
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}
$stmtS->close();
$spreadsheetId = trim((string)$spreadsheetId);
if ($spreadsheetId === '') {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$tok = gsheet_get_access_token();
if (!($tok['ok'] ?? false)) {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}
$accessToken = (string)($tok['access_token'] ?? '');
if ($accessToken === '') {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$fetchSheet = function(string $sheetName) use ($spreadsheetId, $accessToken) {
  $range = $sheetName . '!A1:Z5000';
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?majorDimension=ROWS';
  $resp = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
  if (!($resp['ok'] ?? false) || !is_array($resp['json'])) return null;
  $vals = $resp['json']['values'] ?? null;
  if (!is_array($vals) || count($vals) < 2) return null;
  return $vals;
};

$preferred = $__sp_sheet_name('Hasil ' . ($slugTrim !== '' ? $slugTrim : (string)$pubId), 'Hasil');
$sheetName = $preferred;
$vals = $fetchSheet($sheetName);
if ($vals === null) {
  $sheetName = $__sp_sheet_name($kelas, 'Kelas');
  $vals = $fetchSheet($sheetName);
}
if ($vals === null) {
  echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>[]], JSON_UNESCAPED_UNICODE);
  exit;
}

$header = array_map(fn($x) => strtolower(trim((string)$x)), is_array($vals[0]) ? $vals[0] : []);
$findCol = function(array $hdr, array $needles): int {
  for ($i = 0; $i < count($hdr); $i++) {
    $h = (string)($hdr[$i] ?? '');
    foreach ($needles as $n) {
      if ($n !== '' && strpos($h, $n) !== false) return $i;
    }
  }
  return -1;
};
$iAbsen = $findCol($header, ['absen', 'no']);
$iNama = $findCol($header, ['nama']);
$iScore = $findCol($header, ['skor', 'score']);
$iTotal = $findCol($header, ['total']);
$iTime = $findCol($header, ['waktu', 'submit', 'created']);

if ($iAbsen < 0) $iAbsen = 0;
if ($iNama < 0) $iNama = 1;
if ($iScore < 0) $iScore = 2;
if ($iTotal < 0) $iTotal = 3;
if ($iTime < 0) $iTime = 5;

$out = [];
for ($r = 1; $r < count($vals); $r++) {
  $row = is_array($vals[$r]) ? $vals[$r] : [];
  $abs = (int)trim((string)($row[$iAbsen] ?? '0'));
  if ($abs <= 0) continue;
  $nm = (string)($row[$iNama] ?? '');
  $sc = (int)trim((string)($row[$iScore] ?? '0'));
  $tt = (int)trim((string)($row[$iTotal] ?? '0'));
  $ts = (string)($row[$iTime] ?? '');
  $out[] = ['absen' => $abs, 'nama' => $nm, 'score' => $sc, 'total' => $tt, 'created_at' => $ts];
}
if (count($out) > 1) {
  $byAbsen = [];
  foreach ($out as $it) {
    $ab = (int)($it['absen'] ?? 0);
    if ($ab <= 0) continue;
    $ts = (string)($it['created_at'] ?? '');
    $t = $ts !== '' ? @strtotime($ts) : 0;
    if (!isset($byAbsen[$ab])) {
      $byAbsen[$ab] = ['t' => $t, 'row' => $it];
      continue;
    }
    $prev = $byAbsen[$ab];
    $pt = (int)($prev['t'] ?? 0);
    if ($t >= $pt) $byAbsen[$ab] = ['t' => $t, 'row' => $it];
  }
  $out = array_values(array_map(fn($x) => $x['row'], $byAbsen));
}
usort($out, function($a, $b) {
  $sa = (int)($a['score'] ?? 0);
  $sb = (int)($b['score'] ?? 0);
  if ($sb !== $sa) return $sb - $sa;
  return ((int)($a['absen'] ?? 0)) <=> ((int)($b['absen'] ?? 0));
});

echo json_encode(['ok'=>true,'mapel'=>$mapel,'items'=>$out, 'source'=>'gsheet', 'sheet'=>$sheetName], JSON_UNESCAPED_UNICODE);
