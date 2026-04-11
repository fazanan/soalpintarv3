<?php
header('Content-Type: application/json; charset=utf-8');

function json_out($status, $payload) {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === '' && php_sapi_name() === 'cli') $raw = file_get_contents('php://stdin');
$req = $raw ? json_decode($raw, true) : null;
if (!is_array($req)) $req = $_GET;
if (!is_array($req)) json_out(400, ['ok' => false, 'error' => 'bad_request']);

$jenjangIn = trim((string)($req['jenjang'] ?? ''));
$faseIn = trim((string)($req['fase'] ?? ''));
$q = trim((string)($req['q'] ?? ''));
$limit = (int)($req['limit'] ?? 12);
if ($limit < 1) $limit = 12;
if ($limit > 30) $limit = 30;

if ($jenjangIn === '' || $faseIn === '') json_out(200, ['ok' => false, 'error' => 'missing_required']);

$faseKey = '';
if (preg_match('/Fondasi/i', $faseIn)) $faseKey = 'Fondasi';
else if (preg_match('/Fase\s+([A-F])/i', $faseIn, $m)) $faseKey = strtoupper((string)$m[1]);
if ($faseKey === '') json_out(200, ['ok' => false, 'error' => 'fase_unrecognized']);

$mapJenjang = [
  'Paket A' => 'SD/MI',
  'Paket B' => 'SMP/MTs',
  'Paket C' => 'SMA/MA',
  'SMK/MAK' => 'SMA/MA',
  'SMK' => 'SMA/MA',
  'PAUD' => 'PAUD',
  'TK' => 'TK',
];
$jenjangKey = $mapJenjang[$jenjangIn] ?? $jenjangIn;

$root = realpath(__DIR__ . '/..');
if (!$root) json_out(500, ['ok' => false, 'error' => 'root_not_found']);
$indexPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cp046' . DIRECTORY_SEPARATOR . 'index' . DIRECTORY_SEPARATOR . 'mapel_index.json';
if (!is_file($indexPath)) json_out(200, ['ok' => false, 'error' => 'mapel_index_not_found']);
$rawIndex = file_get_contents($indexPath);
$index = $rawIndex ? json_decode($rawIndex, true) : null;
if (!is_array($index) || !is_array($index['jenjang'] ?? null)) json_out(200, ['ok' => false, 'error' => 'mapel_index_bad']);

$candidates = $index['jenjang'][$jenjangKey]['fase'][$faseKey] ?? null;
if (!is_array($candidates)) json_out(200, ['ok' => false, 'error' => 'no_mapel_for_fase']);

$u_lower = function($s) {
  $s = (string)$s;
  if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
  return strtolower($s);
};

$norm = function($s) use ($u_lower) {
  $s = $u_lower((string)$s);
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
  $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
  $s = preg_replace('/\s{2,}/', ' ', $s);
  return trim($s);
};

$toLabel = function($s) {
  $src = trim((string)$s);
  if ($src === '') return '';
  $parts = preg_split('/\s+/', $src);
  $out = [];
  foreach ($parts as $p) {
    $tok = (string)$p;
    $core = preg_replace('/[^A-Za-z0-9]/', '', $tok);
    if ($core !== '' && strtoupper($core) === $core && strlen($core) <= 5) {
      $out[] = $tok;
      continue;
    }
    $low = strtolower($tok);
    $out[] = preg_replace_callback('/(^|[\(\[\{\"\'])([a-z])/', function($m) {
      return $m[1] . strtoupper($m[2]);
    }, $low);
  }
  $txt = implode(' ', $out);
  $txt = preg_replace('/\bDan\b/u', 'dan', $txt);
  $txt = preg_replace('/\bDAN\b/u', 'dan', $txt);
  return $txt;
};

$qNorm = $norm($q);
$res = [];
foreach ($candidates as $c) {
  $slug = (string)($c['slug'] ?? '');
  $mapel = (string)($c['mapel'] ?? '');
  if ($slug === '' || $mapel === '') continue;
  if ($qNorm !== '') {
    $mNorm = $norm($mapel);
    if ($mNorm === '' || strpos($mNorm, $qNorm) === false) continue;
  }
  $res[] = [
    'slug' => $slug,
    'label' => $toLabel($mapel),
    'raw' => $mapel,
  ];
  if (count($res) >= $limit) break;
}

json_out(200, [
  'ok' => true,
  'jenjang' => $jenjangKey,
  'fase' => $faseKey,
  'q' => $q,
  'results' => $res,
]);

