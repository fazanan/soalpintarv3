<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['user_id'])) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/gsheet_service.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  if (!empty($_POST)) $data = $_POST;
  else {
    $tmp = [];
    parse_str($raw ?? '', $tmp);
    if (is_array($tmp) && !empty($tmp)) $data = $tmp;
  }
}
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

function gsheet_export_sheet_name(string $name, string $fallback): string {
  $n = trim($name);
  if ($n === '') $n = $fallback;
  $n = preg_replace('/[\[\]\:\?\*\/\\\\]/', ' ', $n);
  $n = preg_replace('/\s+/', ' ', $n);
  $n = trim($n);
  if ($n === '') $n = $fallback;
  if (function_exists('mb_substr')) $n = mb_substr($n, 0, 100, 'UTF-8');
  else $n = substr($n, 0, 100);
  return $n;
}

function gsheet_export_clear(string $spreadsheetId, string $sheetName, string $accessToken): bool {
  $range = $sheetName . '!A:AZ';
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . ':clear';
  $res = gsheet_http_json('POST', $url, ['Authorization: Bearer ' . $accessToken], []);
  return (bool)($res['ok'] ?? false);
}

function gsheet_export_write_table(string $spreadsheetId, string $sheetName, array $rows, string $accessToken): array {
  $range = $sheetName . '!A1';
  $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . rawurlencode($range) . '?valueInputOption=RAW';
  $res = gsheet_http_json('PUT', $url, ['Authorization: Bearer ' . $accessToken], [
    'range' => $range,
    'majorDimension' => 'ROWS',
    'values' => $rows,
  ]);
  return $res;
}

function gsheet_export_parse_payload_items(string $payloadJson): array {
  $decoded = json_decode($payloadJson, true);
  if (!is_array($decoded)) return [];
  if (isset($decoded['items']) && is_array($decoded['items'])) return $decoded['items'];
  return $decoded;
}

function gsheet_export_parse_answer_key(string $answerJson): array {
  $decoded = json_decode($answerJson, true);
  return is_array($decoded) ? $decoded : [];
}

function gsheet_export_letter(int $idx): string {
  if ($idx < 0) return '';
  return chr(65 + $idx);
}

function gsheet_export_clean_text($s): string {
  $t = (string)($s ?? '');
  $t = str_replace(["\r\n", "\r"], "\n", $t);
  $t = preg_replace('/\s+/', ' ', $t);
  return trim((string)$t);
}

$userId = (int)$_SESSION['user_id'];
$publishedId = isset($data['published_quiz_id']) ? (int)$data['published_quiz_id'] : 0;

$quizzes = [];
if ($publishedId > 0) {
  $stmt = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public, answer_key, total_soal, created_at, expire_at FROM published_quizzes WHERE user_id=? AND id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('ii', $userId, $publishedId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $quizzes[] = $r;
    $stmt->close();
  }
} else {
  $stmt = $mysqli->prepare("SELECT id, slug, mapel, kelas, payload_public, answer_key, total_soal, created_at, expire_at FROM published_quizzes WHERE user_id=? ORDER BY id DESC");
  if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $quizzes[] = $r;
    $stmt->close();
  }
}

if (!$quizzes) {
  echo json_encode(['ok' => true, 'items' => [], 'message' => 'Tidak ada quiz'], JSON_UNESCAPED_UNICODE);
  exit;
}

$settingsByMapel = [];
$stmtS = $mysqli->prepare("SELECT id, user_id, mapel, spreadsheet_url, spreadsheet_id, is_active, auto_sync, include_detail, created_at, updated_at FROM gsheet_settings WHERE user_id=? AND is_active=1");
if ($stmtS) {
  $stmtS->bind_param('i', $userId);
  $stmtS->execute();
  $resS = $stmtS->get_result();
  while ($r = $resS->fetch_assoc()) {
    $m = (string)($r['mapel'] ?? '');
    if ($m !== '') $settingsByMapel[$m] = $r;
  }
  $stmtS->close();
}

$tok = gsheet_get_access_token();
if (!($tok['ok'] ?? false)) {
  echo json_encode([
    'ok' => false,
    'error' => (string)($tok['error'] ?? 'token_failed'),
    'message' => (string)($tok['message'] ?? 'Gagal token'),
    'path' => $tok['path'] ?? null,
    'candidates' => $tok['candidates'] ?? null,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
$accessToken = (string)($tok['access_token'] ?? '');
if ($accessToken === '') {
  echo json_encode(['ok' => false, 'error' => 'token_empty'], JSON_UNESCAPED_UNICODE);
  exit;
}

$summaries = [];
foreach ($quizzes as $quiz) {
  $qid = (int)($quiz['id'] ?? 0);
  $slug = (string)($quiz['slug'] ?? '');
  $mapel = (string)($quiz['mapel'] ?? '');
  $kelasRaw = (string)($quiz['kelas'] ?? '');

  $set = $settingsByMapel[$mapel] ?? null;
  if (!is_array($set)) {
    $summaries[] = [
      'published_id' => $qid,
      'slug' => $slug,
      'mapel' => $mapel,
      'kelas' => $kelasRaw,
      'status' => 'skipped_no_settings',
    ];
    continue;
  }
  $spreadsheetId = (string)($set['spreadsheet_id'] ?? '');
  if ($spreadsheetId === '') {
    $summaries[] = [
      'published_id' => $qid,
      'slug' => $slug,
      'mapel' => $mapel,
      'kelas' => $kelasRaw,
      'status' => 'skipped_invalid_spreadsheet',
    ];
    continue;
  }

  $kelasSheet = gsheet_export_sheet_name($kelasRaw, 'Kelas');

  $items = gsheet_export_parse_payload_items((string)($quiz['payload_public'] ?? ''));
  $answerKey = gsheet_export_parse_answer_key((string)($quiz['answer_key'] ?? ''));

  $soalRows = [];
  $soalRows[] = ['Quiz ID', 'Slug', 'Kelas', 'No', 'Tipe', 'Pertanyaan', 'A', 'B', 'C', 'D', 'E', 'Kunci'];
  for ($i = 0; $i < count($items); $i++) {
    $q = is_array($items[$i] ?? null) ? $items[$i] : [];
    $type = trim((string)($q['type'] ?? 'pg'));
    if ($type === '') $type = 'pg';
    $qText = gsheet_export_clean_text($q['question'] ?? '');
    $opts = [];
    if ($type === 'benar_salah') $opts = ['Benar', 'Salah'];
    else $opts = is_array($q['options'] ?? null) ? $q['options'] : [];
    $oa = gsheet_export_clean_text($opts[0] ?? '');
    $ob = gsheet_export_clean_text($opts[1] ?? '');
    $oc = gsheet_export_clean_text($opts[2] ?? '');
    $od = gsheet_export_clean_text($opts[3] ?? '');
    $oe = gsheet_export_clean_text($opts[4] ?? '');

    $k = $answerKey[$i] ?? null;
    $kunci = '';
    if (is_array($k)) {
      $letters = [];
      foreach ($k as $vv) {
        $idx = is_int($vv) || is_float($vv) ? (int)$vv : (is_string($vv) && preg_match('/^-?\d+$/', trim($vv)) ? (int)$vv : -1);
        $letters[] = gsheet_export_letter($idx);
      }
      $letters = array_values(array_filter($letters, fn($x) => $x !== ''));
      $kunci = implode(',', $letters);
    } else {
      $idx = is_int($k) || is_float($k) ? (int)$k : (is_string($k) && preg_match('/^-?\d+$/', trim($k)) ? (int)$k : -1);
      $kunci = gsheet_export_letter($idx);
      if ($type === 'benar_salah' && $kunci === '') {
        if ($idx === 0) $kunci = 'Benar';
        else if ($idx === 1) $kunci = 'Salah';
      }
    }

    $soalRows[] = [
      $qid,
      $slug,
      $kelasRaw,
      $i + 1,
      $type,
      $qText,
      $oa, $ob, $oc, $od, $oe,
      $kunci,
    ];
  }

  $resRows = [];
  $resRows[] = ['No Absen', 'Nama', 'Skor', 'Total', 'Nilai (%)', 'Waktu Submit'];
  $stmtR = $mysqli->prepare("SELECT absen, nama, score, total, created_at FROM published_quiz_results WHERE published_id=? ORDER BY absen ASC");
  if ($stmtR) {
    $stmtR->bind_param('i', $qid);
    $stmtR->execute();
    $resR = $stmtR->get_result();
    while ($r = $resR->fetch_assoc()) {
      $a = (int)($r['absen'] ?? 0);
      $nm = (string)($r['nama'] ?? '');
      $sc = (int)($r['score'] ?? 0);
      $tt = (int)($r['total'] ?? 0);
      $pct = $tt > 0 ? round(($sc / $tt) * 100, 2) : 0.0;
      $ts = (string)($r['created_at'] ?? '');
      $resRows[] = [$a, $nm, $sc, $tt, $pct, $ts];
    }
    $stmtR->close();
  }

  $quizSummary = [
    'published_id' => $qid,
    'slug' => $slug,
    'mapel' => $mapel,
    'kelas' => $kelasRaw,
    'spreadsheet_id' => $spreadsheetId,
    'status' => 'pending',
    'rows_soal' => max(0, count($soalRows) - 1),
    'rows_kelas' => max(0, count($resRows) - 1),
  ];

  $okSoal = false;
  $okKelas = false;
  $msg = '';
  try {
    $ensSoal = gsheet_ensure_sheet($spreadsheetId, 'Soal', $accessToken);
    if (!($ensSoal['ok'] ?? false)) throw new RuntimeException('ensure_sheet_soal_failed');
    gsheet_export_clear($spreadsheetId, 'Soal', $accessToken);
    $wr = gsheet_export_write_table($spreadsheetId, 'Soal', $soalRows, $accessToken);
    $okSoal = (bool)($wr['ok'] ?? false);
    if (!$okSoal) throw new RuntimeException('write_soal_failed');

    $ensK = gsheet_ensure_sheet($spreadsheetId, $kelasSheet, $accessToken);
    if (!($ensK['ok'] ?? false)) throw new RuntimeException('ensure_sheet_kelas_failed');
    gsheet_export_clear($spreadsheetId, $kelasSheet, $accessToken);
    $wr2 = gsheet_export_write_table($spreadsheetId, $kelasSheet, $resRows, $accessToken);
    $okKelas = (bool)($wr2['ok'] ?? false);
    if (!$okKelas) throw new RuntimeException('write_kelas_failed');
  } catch (Throwable $e) {
    $msg = (string)$e->getMessage();
  }

  if ($okSoal && $okKelas) {
    $quizSummary['status'] = 'ok';
    $quizSummary['sheet_kelas'] = $kelasSheet;
  } else {
    $quizSummary['status'] = 'failed';
    $quizSummary['sheet_kelas'] = $kelasSheet;
    $quizSummary['message'] = $msg ?: 'Gagal export';
  }
  $summaries[] = $quizSummary;
}

echo json_encode(['ok' => true, 'items' => $summaries], JSON_UNESCAPED_UNICODE);
?>
