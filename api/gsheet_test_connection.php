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
}
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_json']);
  exit;
}

function gsheet_parse_spreadsheet_id_from_url(string $url): string {
  $u = trim($url);
  if ($u === '') return '';
  if (preg_match('~\/spreadsheets\/d\/([a-zA-Z0-9\-_]+)~', $u, $m)) return (string)$m[1];
  if (preg_match('~\/d\/([a-zA-Z0-9\-_]+)~', $u, $m)) return (string)$m[1];
  return '';
}

$spreadsheetUrl = isset($data['spreadsheet_url']) ? trim((string)$data['spreadsheet_url']) : '';
if ($spreadsheetUrl === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_input', 'missing' => ['spreadsheet_url']], JSON_UNESCAPED_UNICODE);
  exit;
}
$spreadsheetId = gsheet_parse_spreadsheet_id_from_url($spreadsheetUrl);
if ($spreadsheetId === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'invalid_spreadsheet_url', 'message' => 'Tidak bisa parse spreadsheet_id dari URL'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tok = gsheet_get_access_token();
if (!($tok['ok'] ?? false)) {
  $msg = (string)($tok['message'] ?? 'Gagal mendapatkan access token');
  $path = (string)($tok['path'] ?? '');
  $cands = is_array($tok['candidates'] ?? null) ? $tok['candidates'] : [];
  if ($path !== '') $msg .= ' | path: ' . $path;
  if ($cands) {
    $shown = array_slice(array_values(array_filter($cands, fn($x) => is_string($x) && $x !== '')), 0, 4);
    if ($shown) $msg .= ' | cek: ' . implode(' , ', $shown);
  }
  echo json_encode([
    'ok' => false,
    'error' => (string)($tok['error'] ?? 'token_failed'),
    'message' => $msg,
    'path' => $path !== '' ? $path : null,
    'candidates' => $cands ?: null,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}
$accessToken = (string)($tok['access_token'] ?? '');
if ($accessToken === '') {
  echo json_encode(['ok' => false, 'error' => 'token_empty', 'message' => 'Access token kosong'], JSON_UNESCAPED_UNICODE);
  exit;
}

$url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=properties.title';
$res = gsheet_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken]);
if (!($res['ok'] ?? false)) {
  $msg = '';
  if (is_array($res['json'])) {
    $msg = (string)($res['json']['error']['message'] ?? $res['json']['error']['status'] ?? '');
  }
  if ($msg === '') $msg = 'Gagal mengakses spreadsheet';
  echo json_encode([
    'ok' => false,
    'spreadsheet_id' => $spreadsheetId,
    'error' => 'sheets_api_error',
    'status' => (int)($res['status'] ?? 0),
    'message' => $msg,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$title = '';
if (is_array($res['json'])) $title = (string)($res['json']['properties']['title'] ?? '');
echo json_encode([
  'ok' => true,
  'spreadsheet_id' => $spreadsheetId,
  'title' => $title,
], JSON_UNESCAPED_UNICODE);
?>
