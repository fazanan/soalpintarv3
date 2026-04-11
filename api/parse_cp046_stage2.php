<?php
$isCli = php_sapi_name() === 'cli';
if (!$isCli) session_start();
else {
  if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = [];
  $_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;
  $_SESSION['role'] = $_SESSION['role'] ?? 'admin';
}
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? 'user') !== 'admin')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'admin_only']);
  exit;
}

$enabled = getenv('ENABLE_CP_IMPORT');
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$isLocal = $remote === '127.0.0.1' || $remote === '::1' || php_sapi_name() === 'cli';
$isEnabled = is_string($enabled) && in_array(strtolower(trim($enabled)), ['1', 'true', 'yes', 'on'], true);
if (!$isLocal && !$isEnabled) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'parser_disabled']);
  exit;
}

set_time_limit(0);

$root = realpath(__DIR__ . '/..');
if (!$root) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'root_not_found']);
  exit;
}

$srcDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cp046';
$srcJsonl = $srcDir . DIRECTORY_SEPARATOR . 'cp046.pages.jsonl';
if (!is_file($srcJsonl)) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'pages_jsonl_not_found', 'path' => $srcJsonl]);
  exit;
}

$kurikulum = isset($_GET['kurikulum']) ? trim((string)$_GET['kurikulum']) : 'Kurikulum Merdeka';
$force = isset($_GET['force']) ? (int)$_GET['force'] : 0;
$maxPages = isset($_GET['max_pages']) ? max(0, (int)$_GET['max_pages']) : 0;

$outBase = $srcDir . DIRECTORY_SEPARATOR . 'index';
$outCpDir = $outBase . DIRECTORY_SEPARATOR . 'cp';
if (!is_dir($outCpDir)) {
  if (!@mkdir($outCpDir, 0775, true) && !is_dir($outCpDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mkdir_failed', 'dir' => $outCpDir]);
    exit;
  }
}

$rrmdir = function($dir) use (&$rrmdir) {
  if (!is_dir($dir)) return;
  $items = @scandir($dir);
  if (!is_array($items)) return;
  foreach ($items as $it) {
    if ($it === '.' || $it === '..') continue;
    $p = $dir . DIRECTORY_SEPARATOR . $it;
    if (is_dir($p)) $rrmdir($p);
    else @unlink($p);
  }
  @rmdir($dir);
};
if ($force && is_dir($outCpDir)) {
  foreach (glob($outCpDir . DIRECTORY_SEPARATOR . '*') as $p) {
    if (is_dir($p)) $rrmdir($p);
    else @unlink($p);
  }
}

$statePath = $outBase . DIRECTORY_SEPARATOR . 'state.json';
if (!$force && is_file($statePath)) {
  $prev = @file_get_contents($statePath);
  if ($prev) {
    $prevJson = json_decode($prev, true);
    if (is_array($prevJson) && ($prevJson['ok'] ?? false) === true) {
      echo json_encode(['ok' => true, 'already_built' => true, 'state' => str_replace($root . DIRECTORY_SEPARATOR, '', $statePath)]);
      exit;
    }
  }
}

function cp046_slugify($s) {
  $s = (string)$s;
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  if ($s === false) $s = '';
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  if ($s === '') $s = 'mapel';
  return $s;
}

function cp046_norm_title($s) {
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', (string)$s);
  $s = str_replace(["\r", "\t"], ["\n", " "], $s);
  $s = preg_replace('/[ ]{2,}/', ' ', $s);
  $s = preg_replace('/\n{2,}/', "\n", $s);
  $s = trim($s);
  return $s;
}

function cp046_detect_jenjang_from_fase($fase) {
  $f = strtoupper((string)$fase);
  if ($f === 'FONDASI') return 'PAUD';
  if ($f === 'A' || $f === 'B' || $f === 'C') return 'SD/MI';
  if ($f === 'D') return 'SMP/MTs';
  if ($f === 'E' || $f === 'F') return 'SMA/MA';
  return 'Unknown';
}

function cp046_extract_mapel_from_page($text) {
  $t = (string)$text;
  $t = str_replace("\r\n", "\n", $t);
  $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t);
  $hasAnak = preg_match('/\bANAK\s+USIA\s+DINI\b/ui', $t) === 1;
  $hasFondasiAnak = (preg_match('/\bCAPAIAN\s+PEMBELAJARAN\s+FASE\s+FONDASI\b/ui', $t) === 1) && (preg_match('/\bANAK\s+USIA\s+DINI\b/ui', $t) === 1);
  $lines = explode("\n", $t);
  $lineCount = count($lines);
  for ($i = 0; $i < $lineCount; $i++) {
    $line = trim($lines[$i]);
    if ($line === '') continue;
    if (($hasAnak || $hasFondasiAnak) && preg_match('/\bCAPAIAN\s+PEMBELAJARAN\s+PAUD\b/ui', $line)) return 'PAUD (Fase Fondasi)';
  }
  for ($i = 0; $i < $lineCount; $i++) {
    $line = trim($lines[$i]);
    if ($line === '') continue;
    if (preg_match('/^\s*[IVX]+\s*[\.\)]?\s*CAPAIAN\s+PEMBELAJARAN\s+(.+)\s*$/ui', $line, $m)) {
      $name = trim((string)$m[1]);
      $name = preg_replace('/\s{2,}/', ' ', $name);
      return $name;
    }
    if (preg_match('/^\s*[IVX]+\s*\.\s*\d+\s*\.?\s*CAPAIAN\s+PEMBELAJARAN\s+(.+)\s*$/ui', $line, $m)) {
      $name = trim((string)$m[1]);
      $j = $i + 1;
      $extra = [];
      $emptySkips = 0;
      while ($j < $lineCount && count($extra) < 3) {
        $n = trim($lines[$j]);
        if ($n === '') {
          $emptySkips++;
          if ($emptySkips >= 3) break;
          $j++;
          continue;
        }
        if (preg_match('/^[A-C]\.\s+/u', $n)) break;
        if (preg_match('/^B\.\s+/u', $n)) break;
        if (preg_match('/^Tujuan\b/ui', $n)) break;
        if (!preg_match('/^[A-Z0-9][A-Z0-9 ,&\/\-\(\)]+$/u', $n)) break;
        $extra[] = $n;
        $j++;
      }
      if (!empty($extra)) $name = trim($name . ' ' . implode(' ', $extra));
      $name = preg_replace('/\s{2,}/', ' ', $name);
      return $name;
    }
  }
  return null;
}

function cp046_extract_fase_from_page($text) {
  $t = (string)$text;
  $t = str_replace("\r\n", "\n", $t);
  $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $t);
  $hasAnak = preg_match('/\bANAK\s+USIA\s+DINI\b/ui', $t) === 1;
  $lines = explode("\n", $t);
  foreach ($lines as $line) {
    $l = trim((string)$line);
    if ($l === '') continue;
    if ($hasAnak && preg_match('/\bFASE\s+FONDASI\b/ui', $l)) return 'Fondasi';
    if (preg_match('/^\s*(?:CAPAIAN\s+PEMBELAJARAN\s+)?FASE\s+([A-F])\b/ui', $l, $m)) return strtoupper((string)$m[1]);
    if (preg_match('/^\s*(?:\d+\s*[\.\)]\s*)?Fase\s+([A-F])\b/u', $l, $m)) return strtoupper((string)$m[1]);
    if (preg_match('/^\s*(?:\d+(?:\.\d+)?\s*[\.\)]\s*)Fase\s+([A-F])\b/u', $l, $m)) return strtoupper((string)$m[1]);
    if (preg_match('/\bFase\s+([A-F])\b/u', $l, $m)) return strtoupper((string)$m[1]);
  }
  return null;
}

$in = @fopen($srcJsonl, 'rb');
if (!$in) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'open_pages_failed']);
  exit;
}

$slugCounts = [];
$spoolDir = $outBase . DIRECTORY_SEPARATOR . 'spool';
$keepSpool = isset($_GET['keep_spool']) ? (int)$_GET['keep_spool'] : 0;
if (!is_dir($spoolDir)) {
  if (!@mkdir($spoolDir, 0775, true) && !is_dir($spoolDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mkdir_failed', 'dir' => $spoolDir]);
    exit;
  }
}
if ($force) {
  foreach (glob($spoolDir . DIRECTORY_SEPARATOR . '*.jsonl') as $f) {
    @unlink($f);
  }
}

$spools = [];
$indexTmp = [];

$curMapel = null;
$curMapelSlug = null;
$curFase = null;
$curJenjang = null;

$pagesSeen = 0;
while (!feof($in)) {
  $line = fgets($in);
  if ($line === false) break;
  $line = trim($line);
  if ($line === '') continue;
  $obj = json_decode($line, true);
  if (!is_array($obj) || !isset($obj['page']) || !isset($obj['text'])) continue;

  $pageNo = (int)$obj['page'];
  $textRaw = (string)$obj['text'];
  $text = cp046_norm_title($textRaw);

  $newMapel = cp046_extract_mapel_from_page($textRaw);
  if (is_string($newMapel) && $newMapel !== '') {
    $curMapel = cp046_norm_title($newMapel);
    $baseSlug = cp046_slugify($curMapel);
    $n = ($slugCounts[$baseSlug] ?? 0) + 1;
    $slugCounts[$baseSlug] = $n;
    $curMapelSlug = $n === 1 ? $baseSlug : ($baseSlug . '-' . $n);
    $curFase = null;
    $curJenjang = null;
  }

  $newFase = cp046_extract_fase_from_page($textRaw);
  if (is_string($newFase) && $newFase !== '') {
    if ($curMapel === 'PAUD (Fase Fondasi)') {
      if (strtoupper($newFase) === 'FONDASI') $curFase = 'Fondasi';
      if ($curFase === null) $curFase = 'Fondasi';
      $curJenjang = 'PAUD';
    } else {
      if (strtoupper($newFase) !== 'FONDASI') {
        $curFase = $newFase;
        $curJenjang = cp046_detect_jenjang_from_fase($curFase);
      }
    }
  }

  if ($curMapelSlug && $curFase) {
    $key = $curMapelSlug . '__' . $curFase;
    if (!isset($spools[$key])) {
      $spoolPath = $spoolDir . DIRECTORY_SEPARATOR . $key . '.jsonl';
      $spools[$key] = [
        'kurikulum' => $kurikulum,
        'mapel' => $curMapel,
        'mapel_slug' => $curMapelSlug,
        'fase' => $curFase,
        'jenjang' => $curJenjang,
        'spool' => $spoolPath,
      ];
      if (!isset($indexTmp[$curJenjang])) $indexTmp[$curJenjang] = [];
      if (!isset($indexTmp[$curJenjang][$curFase])) $indexTmp[$curJenjang][$curFase] = [];
      $indexTmp[$curJenjang][$curFase][$curMapelSlug] = $curMapel;
    }
    $chunkLine = json_encode(['page' => $pageNo, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents($spools[$key]['spool'], $chunkLine . "\n", FILE_APPEND);
  }

  $pagesSeen++;
  if ($maxPages && $pagesSeen >= $maxPages) break;
}
try { fclose($in); } catch (Throwable $e) {}

function cp046_write_json($path, $data) {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  $tmp = $path . '.tmp';
  @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
  @rename($tmp, $path);
}

$metaPath = $outBase . DIRECTORY_SEPARATOR . 'meta.json';
$manifestPath = $srcDir . DIRECTORY_SEPARATOR . 'manifest.json';
$manifest = null;
if (is_file($manifestPath)) {
  $mr = @file_get_contents($manifestPath);
  if ($mr) {
    $mj = json_decode($mr, true);
    if (is_array($mj)) $manifest = $mj;
  }
}

$meta = [
  'ok' => true,
  'source' => [
    'pdf' => 'CP046.pdf',
    'manifest' => is_file($manifestPath) ? str_replace($root . DIRECTORY_SEPARATOR, '', $manifestPath) : null,
    'pdf_sha256' => $manifest['source']['sha256'] ?? null,
  ],
  'kurikulum' => $kurikulum,
  'generated_at' => gmdate('c'),
  'input' => [
    'pages_jsonl' => str_replace($root . DIRECTORY_SEPARATOR, '', $srcJsonl),
    'max_pages' => $maxPages ?: null,
  ],
];
cp046_write_json($metaPath, $meta);

$mapelIndex = [
  'ok' => true,
  'kurikulum' => $kurikulum,
  'jenjang' => [],
];

if (isset($indexTmp['PAUD']['Fondasi']) && is_array($indexTmp['PAUD']['Fondasi']) && !isset($indexTmp['TK']['Fondasi'])) {
  $indexTmp['TK']['Fondasi'] = $indexTmp['PAUD']['Fondasi'];
}

foreach ($indexTmp as $jenjang => $byFase) {
  if (!isset($mapelIndex['jenjang'][$jenjang])) $mapelIndex['jenjang'][$jenjang] = ['fase' => []];
  foreach ($byFase as $fase => $byMapel) {
    if (!isset($mapelIndex['jenjang'][$jenjang]['fase'][$fase])) $mapelIndex['jenjang'][$jenjang]['fase'][$fase] = [];
    foreach ($byMapel as $slug => $name) {
      $mapelIndex['jenjang'][$jenjang]['fase'][$fase][] = ['slug' => $slug, 'mapel' => $name];
    }
  }
}

foreach ($mapelIndex['jenjang'] as $jenjang => $v) {
  foreach ($v['fase'] as $fase => $arr) {
    usort($arr, function($a, $b) { return strcmp($a['mapel'], $b['mapel']); });
    $mapelIndex['jenjang'][$jenjang]['fase'][$fase] = $arr;
  }
}

$mapelIndexPath = $outBase . DIRECTORY_SEPARATOR . 'mapel_index.json';
cp046_write_json($mapelIndexPath, $mapelIndex);

$written = [];
foreach ($spools as $k => $metaBundle) {
  $jenjang = (string)($metaBundle['jenjang'] ?? 'Unknown');
  $fase = (string)($metaBundle['fase'] ?? 'Unknown');
  $mapelSlug = (string)($metaBundle['mapel_slug'] ?? 'mapel');
  $kSlug = cp046_slugify($kurikulum);
  $jSlug = cp046_slugify($jenjang);
  $fSlug = cp046_slugify($fase);
  $outPath = $outCpDir . DIRECTORY_SEPARATOR . $kSlug . DIRECTORY_SEPARATOR . $jSlug . DIRECTORY_SEPARATOR . $fSlug . DIRECTORY_SEPARATOR . $mapelSlug . '.json';

  $dir = dirname($outPath);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $tmp = $outPath . '.tmp';
  $out = @fopen($tmp, 'wb');
  if ($out) {
    $header = [
      'ok' => true,
      'kurikulum' => $metaBundle['kurikulum'],
      'mapel' => $metaBundle['mapel'],
      'mapel_slug' => $metaBundle['mapel_slug'],
      'fase' => $metaBundle['fase'],
      'jenjang' => $metaBundle['jenjang'],
    ];
    fwrite($out, '{');
    $first = true;
    foreach ($header as $hk => $hv) {
      if (!$first) fwrite($out, ',');
      fwrite($out, json_encode((string)$hk) . ':' . json_encode($hv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      $first = false;
    }
    fwrite($out, ',"chunks":[');

    $inSpool = @fopen($metaBundle['spool'], 'rb');
    $firstChunk = true;
    if ($inSpool) {
      while (!feof($inSpool)) {
        $ln = fgets($inSpool);
        if ($ln === false) break;
        $ln = trim($ln);
        if ($ln === '') continue;
        if (!$firstChunk) fwrite($out, ',');
        fwrite($out, $ln);
        $firstChunk = false;
      }
      try { fclose($inSpool); } catch (Throwable $e) {}
    }
    fwrite($out, ']}');
    try { fclose($out); } catch (Throwable $e) {}
    @rename($tmp, $outPath);
    $written[] = str_replace($root . DIRECTORY_SEPARATOR, '', $outPath);
  } else {
    @unlink($tmp);
  }

  if ($jenjang === 'PAUD' && strtoupper($fase) === 'FONDASI') {
    $tkOutPath = $outCpDir . DIRECTORY_SEPARATOR . $kSlug . DIRECTORY_SEPARATOR . cp046_slugify('TK') . DIRECTORY_SEPARATOR . $fSlug . DIRECTORY_SEPARATOR . $mapelSlug . '.json';
    $dir2 = dirname($tkOutPath);
    if (!is_dir($dir2)) @mkdir($dir2, 0775, true);
    $tmp2 = $tkOutPath . '.tmp';
    $out2 = @fopen($tmp2, 'wb');
    if ($out2) {
      $header2 = [
        'ok' => true,
        'kurikulum' => $metaBundle['kurikulum'],
        'mapel' => $metaBundle['mapel'],
        'mapel_slug' => $metaBundle['mapel_slug'],
        'fase' => $metaBundle['fase'],
        'jenjang' => 'TK',
      ];
      fwrite($out2, '{');
      $first2 = true;
      foreach ($header2 as $hk => $hv) {
        if (!$first2) fwrite($out2, ',');
        fwrite($out2, json_encode((string)$hk) . ':' . json_encode($hv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $first2 = false;
      }
      fwrite($out2, ',"chunks":[');
      $inSpool2 = @fopen($metaBundle['spool'], 'rb');
      $firstChunk2 = true;
      if ($inSpool2) {
        while (!feof($inSpool2)) {
          $ln = fgets($inSpool2);
          if ($ln === false) break;
          $ln = trim($ln);
          if ($ln === '') continue;
          if (!$firstChunk2) fwrite($out2, ',');
          fwrite($out2, $ln);
          $firstChunk2 = false;
        }
        try { fclose($inSpool2); } catch (Throwable $e) {}
      }
      fwrite($out2, ']}');
      try { fclose($out2); } catch (Throwable $e) {}
      @rename($tmp2, $tkOutPath);
      $written[] = str_replace($root . DIRECTORY_SEPARATOR, '', $tkOutPath);
    } else {
      @unlink($tmp2);
    }
  }
}

if (!$keepSpool) {
  foreach ($spools as $metaBundle) {
    if (isset($metaBundle['spool']) && is_file($metaBundle['spool'])) @unlink($metaBundle['spool']);
  }
}

$state = [
  'ok' => true,
  'meta' => str_replace($root . DIRECTORY_SEPARATOR, '', $metaPath),
  'mapel_index' => str_replace($root . DIRECTORY_SEPARATOR, '', $mapelIndexPath),
  'written_files' => $written,
  'generated_at' => gmdate('c'),
];
cp046_write_json($statePath, $state);

echo json_encode([
  'ok' => true,
  'meta' => str_replace($root . DIRECTORY_SEPARATOR, '', $metaPath),
  'mapel_index' => str_replace($root . DIRECTORY_SEPARATOR, '', $mapelIndexPath),
  'state' => str_replace($root . DIRECTORY_SEPARATOR, '', $statePath),
  'written_count' => count($written),
]);
