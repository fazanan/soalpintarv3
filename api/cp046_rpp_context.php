<?php
header('Content-Type: application/json; charset=utf-8');

function json_out($status, $payload) {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$u_lower = function($s) {
  $s = (string)$s;
  if (function_exists('mb_strtolower')) return mb_strtolower($s, 'UTF-8');
  return strtolower($s);
};
$u_len = function($s) {
  $s = (string)$s;
  if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
  return strlen($s);
};
$u_sub = function($s, $start, $len) {
  $s = (string)$s;
  if (function_exists('mb_substr')) return mb_substr($s, (int)$start, (int)$len, 'UTF-8');
  return substr($s, (int)$start, (int)$len);
};

$raw = file_get_contents('php://input');
if ($raw === '' && php_sapi_name() === 'cli') {
  $raw = file_get_contents('php://stdin');
}
$req = $raw ? json_decode($raw, true) : null;
if (!is_array($req)) json_out(400, ['ok' => false, 'error' => 'bad_request']);

$jenjangIn = trim((string)($req['jenjang'] ?? ''));
$faseIn = trim((string)($req['fase'] ?? ''));
$mapelIn = trim((string)($req['mapel'] ?? ''));
$mapelSlugIn = trim((string)($req['mapel_slug'] ?? ''));
$materiIn = trim((string)($req['materi'] ?? ''));
$kurikulumIn = trim((string)($req['kurikulum'] ?? 'Kurikulum Merdeka'));
$docType = trim((string)($req['docType'] ?? 'rpp'));
$docType = strtolower($docType);

if ($jenjangIn === '' || $faseIn === '' || $mapelIn === '') {
  json_out(200, ['ok' => false, 'error' => 'missing_required']);
}

if (preg_match('/Fondasi/i', $faseIn)) $faseKey = 'Fondasi';
else if (preg_match('/Fase\s+([A-F])/i', $faseIn, $m)) $faseKey = strtoupper((string)$m[1]);
else $faseKey = '';
if ($faseKey === '') json_out(200, ['ok' => false, 'error' => 'fase_unrecognized']);

$mapJenjang = [
  'Paket A' => 'SD/MI',
  'Paket B' => 'SMP/MTs',
  'Paket C' => 'SMA/MA',
  'SMK/MAK' => 'SMA/MA',
  'PAUD' => 'PAUD',
  'TK' => 'TK',
];
$jenjangKey = $mapJenjang[$jenjangIn] ?? $jenjangIn;
if ($jenjangKey !== 'SD/MI' && $jenjangKey !== 'SMP/MTs' && $jenjangKey !== 'SMA/MA' && $jenjangKey !== 'PAUD' && $jenjangKey !== 'TK') {
  json_out(200, ['ok' => false, 'error' => 'jenjang_unsupported']);
}
if (($jenjangKey === 'PAUD' || $jenjangKey === 'TK') && $faseKey !== 'Fondasi') {
  json_out(200, ['ok' => false, 'error' => 'fase_not_supported_for_jenjang']);
}

function slugify($s) {
  $s = (string)$s;
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
  $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  if ($s === false) $s = '';
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '-', $s);
  $s = trim($s, '-');
  return $s === '' ? 'x' : $s;
}

function norm($s) {
  global $u_lower;
  $s = $u_lower((string)$s);
  $s = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $s);
  $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
  $s = preg_replace('/\s{2,}/', ' ', $s);
  return trim($s);
}

function tokens($s) {
  global $u_len;
  $s = norm($s);
  if ($s === '') return [];
  $parts = preg_split('/\s+/', $s);
  $out = [];
  foreach ($parts as $p) {
    $p = trim((string)$p);
    if ($p === '' || $u_len($p) < 3) continue;
    $out[] = $p;
  }
  return array_values(array_unique($out));
}

function mapel_variants($mapelIn) {
  $m = norm($mapelIn);
  $variants = [];
  if ($m !== '') $variants[] = $m;

  $noPar = preg_replace('/\([^)]*\)/', ' ', (string)$m);
  $noPar = preg_replace('/\s{2,}/', ' ', $noPar);
  $noPar = trim($noPar);
  if ($noPar !== '' && $noPar !== $m) $variants[] = $noPar;

  $syn = [
    'pai' => 'pendidikan agama islam dan budi pekerti',
    'ppkn' => 'pendidikan pancasila',
    'pjok' => 'pendidikan jasmani olahraga dan kesehatan',
    'ipa' => 'ilmu pengetahuan alam',
    'ips' => 'ilmu pengetahuan sosial',
    'ipas' => 'ilmu pengetahuan alam dan sosial',
    'b indo' => 'bahasa indonesia',
    'b.indo' => 'bahasa indonesia',
    'b inggris' => 'bahasa inggris',
    'b.inggris' => 'bahasa inggris',
    'tik' => 'informatika',
  ];
  if (isset($syn[$m])) $variants[] = $syn[$m];
  if (isset($syn[$noPar])) $variants[] = $syn[$noPar];

  $variants[] = trim(preg_replace('/\s{2,}/', ' ', str_replace(['&', '/'], ' dan ', $m)));

  $out = [];
  foreach ($variants as $v) {
    $v = norm($v);
    if ($v === '') continue;
    $out[$v] = true;
  }
  return array_keys($out);
}

function load_mapel_aliases($root) {
  static $cache = null;
  if (is_array($cache)) return $cache;
  $path = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cp046' . DIRECTORY_SEPARATOR . 'mapel_alias.json';
  if (!is_file($path)) { $cache = []; return $cache; }
  $raw = @file_get_contents($path);
  $json = $raw ? json_decode($raw, true) : null;
  if (!is_array($json)) { $cache = []; return $cache; }
  $cache = $json;
  return $cache;
}

function lookup_mapel_alias_slug($root, $jenjangKey, $faseKey, $mapelIn) {
  $aliases = load_mapel_aliases($root);
  if (!is_array($aliases)) return '';
  $global = is_array($aliases['global'] ?? null) ? $aliases['global'] : [];
  $by = is_array($aliases['by_jenjang_fase'] ?? null) ? $aliases['by_jenjang_fase'] : [];

  $j = slugify($jenjangKey);
  $f = slugify($faseKey);
  $bucket = is_array($by[$j][$f] ?? null) ? $by[$j][$f] : [];

  foreach (mapel_variants($mapelIn) as $v) {
    if (isset($bucket[$v]) && is_string($bucket[$v]) && trim($bucket[$v]) !== '') return trim((string)$bucket[$v]);
    if (isset($global[$v]) && is_string($global[$v]) && trim($global[$v]) !== '') return trim((string)$global[$v]);
  }
  return '';
}

function best_mapel_slug($candidates, $mapelIn) {
  $vars = mapel_variants($mapelIn);
  $mTok = [];
  foreach ($vars as $v) {
    foreach (tokens($v) as $t) $mTok[$t] = true;
  }
  $mTok = array_keys($mTok);
  $best = null;
  $bestScore = -1;
  foreach ($candidates as $c) {
    $name = (string)($c['mapel'] ?? '');
    $slug = (string)($c['slug'] ?? '');
    if ($name === '' || $slug === '') continue;
    $nNorm = norm($name);
    $score = 0;
    foreach ($vars as $v) {
      if ($nNorm === $v) return ['slug' => $slug, 'mapel' => $name, 'score' => 100000];
      if ($v !== '' && strpos($nNorm, $v) !== false) $score += 120;
      if ($nNorm !== '' && strpos($v, $nNorm) !== false) $score += 40;
    }
    $nTok = tokens($nNorm);
    $hits = 0;
    foreach ($mTok as $t) {
      if (in_array($t, $nTok, true)) $hits++;
    }
    $score += $hits * 20;
    $score -= max(0, count($nTok) - $hits);
    if ($score > $bestScore) { $bestScore = $score; $best = ['slug' => $slug, 'mapel' => $name, 'score' => $score]; }
  }
  return $best;
}

$root = realpath(__DIR__ . '/..');
if (!$root) json_out(500, ['ok' => false, 'error' => 'root_not_found']);
$indexDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cp046' . DIRECTORY_SEPARATOR . 'index';
$mapelIndexPath = $indexDir . DIRECTORY_SEPARATOR . 'mapel_index.json';
if (!is_file($mapelIndexPath)) json_out(200, ['ok' => false, 'error' => 'mapel_index_not_found']);
$mapelIndexRaw = file_get_contents($mapelIndexPath);
$mapelIndex = $mapelIndexRaw ? json_decode($mapelIndexRaw, true) : null;
if (!is_array($mapelIndex)) json_out(200, ['ok' => false, 'error' => 'mapel_index_bad']);

$candidates = $mapelIndex['jenjang'][$jenjangKey]['fase'][$faseKey] ?? null;
if (!is_array($candidates) || !count($candidates)) json_out(200, ['ok' => false, 'error' => 'no_mapel_for_fase']);
$best = null;
if ($mapelSlugIn !== '') {
  foreach ($candidates as $c) {
    if (isset($c['slug']) && (string)$c['slug'] === $mapelSlugIn) {
      $best = ['slug' => (string)$c['slug'], 'mapel' => (string)($c['mapel'] ?? ''), 'score' => 100000];
      break;
    }
  }
}
if (!$best) {
  $aliasSlug = lookup_mapel_alias_slug($root, $jenjangKey, $faseKey, $mapelIn);
  if ($aliasSlug !== '') {
    foreach ($candidates as $c) {
      if (isset($c['slug']) && (string)$c['slug'] === $aliasSlug) {
        $best = ['slug' => (string)$c['slug'], 'mapel' => (string)($c['mapel'] ?? ''), 'score' => 90000];
        break;
      }
    }
  }
}
if (!$best) $best = best_mapel_slug($candidates, $mapelIn);
if (!$best || ($best['score'] ?? -1) < 1) {
  json_out(200, ['ok' => false, 'error' => 'mapel_not_matched', 'candidates' => array_slice($candidates, 0, 15)]);
}

$kSlug = slugify($kurikulumIn);
$jSlug = slugify($jenjangKey);
$fSlug = slugify($faseKey);
$cpPath = $indexDir . DIRECTORY_SEPARATOR . 'cp' . DIRECTORY_SEPARATOR . $kSlug . DIRECTORY_SEPARATOR . $jSlug . DIRECTORY_SEPARATOR . $fSlug . DIRECTORY_SEPARATOR . $best['slug'] . '.json';
if (!is_file($cpPath)) json_out(200, ['ok' => false, 'error' => 'cp_file_not_found', 'path' => str_replace($root . DIRECTORY_SEPARATOR, '', $cpPath)]);

$bundleRaw = file_get_contents($cpPath);
$bundleRaw = str_replace('\\"chunks\\"', '"chunks"', (string)$bundleRaw);
$bundle = $bundleRaw ? json_decode($bundleRaw, true) : null;
if (!is_array($bundle) || !is_array($bundle['chunks'] ?? null)) json_out(200, ['ok' => false, 'error' => 'cp_file_bad']);

$stop = array_flip([
  'dan','yang','dari','di','ke','untuk','pada','dengan','atau','sebagai','dalam','adalah','yaitu','yakni',
  'ini','itu','tersebut','oleh','para','agar','bagi','tentang','serta','karena','maka','jika','sehingga',
  'dapat','peserta','didik','murid','siswa','pembelajaran','kegiatan','materi','kompetensi','tujuan',
]);
$kw = [];
foreach (tokens($materiIn) as $t) {
  if (!isset($stop[$t]) && $u_len($t) >= 4) $kw[] = $t;
}
$kw = array_values(array_unique($kw));

function clean_snip($t, $max) {
  global $u_len, $u_sub;
  $t = str_replace(["\r\n", "\r"], "\n", (string)$t);
  $t = preg_replace('/[ \t]{2,}/', ' ', $t);
  $t = preg_replace('/\n{3,}/', "\n\n", $t);
  $t = trim($t);
  if ($u_len($t) > $max) $t = $u_sub($t, 0, $max) . '…';
  return $t;
}

$scored = [];
foreach ($bundle['chunks'] as $ch) {
  $page = (int)($ch['page'] ?? 0);
  $txt = (string)($ch['text'] ?? '');
  if ($page <= 0 || $txt === '') continue;
  $score = 0;
  if (count($kw)) {
    $t = $u_lower($txt);
    foreach ($kw as $w) {
      $score += substr_count($t, $w) * 3;
      if (strpos($t, $w) !== false) $score += 2;
    }
  }
  $scored[] = ['page' => $page, 'text' => $txt, 'score' => $score];
}

usort($scored, function($a, $b) {
  if ($a['score'] === $b['score']) return $a['page'] <=> $b['page'];
  return $b['score'] <=> $a['score'];
});

$take = [];
$maxChars = 7000;
$used = 0;
$maxItems = 8;
$minItems = 3;
$i = 0;
while ($i < count($scored) && count($take) < $maxItems) {
  $it = $scored[$i];
  if (count($kw) && $it['score'] <= 0 && count($take) >= $minItems) break;
  $snip = clean_snip($it['text'], 950);
  $line = "[Hal. {$it['page']}] " . $snip;
  $len = $u_len($line);
  if ($used + $len > $maxChars && count($take) >= $minItems) break;
  $take[] = ['page' => $it['page'], 'snippet' => $snip];
  $used += $len;
  $i++;
}

if (!count($take)) json_out(200, ['ok' => false, 'error' => 'no_snippet']);

$lines = [];
$lines[] = "SUMBER RESMI CP046 (WAJIB DIJADIKAN DASAR):";
$lines[] = "- Dokumen: CP046 (Capaian Pembelajaran Kemendikbudristek)";
$faseLabel = $faseKey === 'Fondasi' ? 'Fondasi' : $faseKey;
$lines[] = "- Rujukan: " . $jenjangKey . " · Fase " . $faseLabel . " · " . $best['mapel'];
$lines[] = "- Cuplikan CP relevan (sertakan nomor halaman):";
foreach ($take as $t) {
  $lines[] = "  - [Hal. {$t['page']}] {$t['snippet']}";
}
$lines[] = "";
$lines[] = "ATURAN KEPATUHAN (WAJIB):";
$docLabel = $docType === 'modul_ajar' ? 'Modul Ajar' : ($docType === 'soal' ? 'Soal' : 'RPP');
$lines[] = "- {$docLabel} WAJIB selaras dengan cuplikan CP046 di atas.";
$lines[] = $docType === 'soal'
  ? "- Materi, indikator, dan tingkat kognitif soal harus traceable ke CP (hindari generik)."
  : "- Tujuan Pembelajaran, materi, kegiatan, dan asesmen harus traceable ke CP (hindari generik).";
$lines[] = "- Jika materi/topik input terlalu sempit/luas, sesuaikan ruang lingkup agar tetap sesuai CP.";

$pages = array_values(array_unique(array_map(fn($x) => (int)$x['page'], $take)));
sort($pages);
$pagesText = count($pages) ? implode(', ', $pages) : 'X';
$lines[] = "- Halaman rujukan yang dipakai: {$pagesText}.";
$lines[] = $docType === 'modul_ajar'
  ? "- WAJIB: di bagian '### 7. Daftar Pustaka' tulis minimal 1 entri yang memuat teks persis: CP046 hal. {$pagesText}."
  : ($docType === 'soal'
    ? "- WAJIB: pada setiap butir soal, field 'indikator' memuat teks: (CP046 hal. {$pagesText})."
    : "- WAJIB: di akhir RPP buat subbagian 'Rujukan' dan tulis minimal 1 baris yang memuat teks persis: CP046 hal. {$pagesText}.");

json_out(200, [
  'ok' => true,
  'jenjang' => $jenjangKey,
  'fase' => $faseKey,
  'mapel_input' => $mapelIn,
  'mapel_match' => $best['mapel'],
  'mapel_slug' => $best['slug'],
  'cp_file' => str_replace($root . DIRECTORY_SEPARATOR, '', $cpPath),
  'pages' => $pages,
  'block' => implode("\n", $lines),
]);
