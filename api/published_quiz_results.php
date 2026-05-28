<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
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
