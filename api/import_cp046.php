<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? 'user') !== 'admin')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'admin_only']);
  exit;
}

$enabled = getenv('ENABLE_CP_IMPORT');
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
$isLocal = $remote === '127.0.0.1' || $remote === '::1';
$isEnabled = is_string($enabled) && in_array(strtolower(trim($enabled)), ['1', 'true', 'yes', 'on'], true);
if (!$isLocal && !$isEnabled) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'import_disabled']);
  exit;
}

set_time_limit(0);

$root = realpath(__DIR__ . '/..');
if (!$root) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'root_not_found']);
  exit;
}

$pdfPath = $root . DIRECTORY_SEPARATOR . 'CP046.pdf';
if (!is_file($pdfPath)) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'pdf_not_found', 'path' => $pdfPath]);
  exit;
}

$force = isset($_GET['force']) ? (int)$_GET['force'] : 0;
$outDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cp046';
if (!is_dir($outDir)) {
  if (!@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'mkdir_failed', 'dir' => $outDir]);
    exit;
  }
}

$rawTxt = $outDir . DIRECTORY_SEPARATOR . 'cp046.txt';
$manifestPath = $outDir . DIRECTORY_SEPARATOR . 'manifest.json';
$pagesJsonl = $outDir . DIRECTORY_SEPARATOR . 'cp046.pages.jsonl';
$pagesMetaPath = $outDir . DIRECTORY_SEPARATOR . 'cp046.pages_meta.json';

$needExtract = $force || !is_file($rawTxt) || filesize($rawTxt) < 1024;
if ($needExtract) {
  $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($pdfPath) . ' ' . escapeshellarg($rawTxt);
  $descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];
  $proc = @proc_open($cmd, $descriptors, $pipes, $root);
  if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'proc_open_failed']);
    exit;
  }
  try { fclose($pipes[0]); } catch (Throwable $e) {}
  $stdout = '';
  $stderr = '';
  try { $stdout = stream_get_contents($pipes[1]); } catch (Throwable $e) {}
  try { $stderr = stream_get_contents($pipes[2]); } catch (Throwable $e) {}
  try { fclose($pipes[1]); } catch (Throwable $e) {}
  try { fclose($pipes[2]); } catch (Throwable $e) {}
  $code = proc_close($proc);

  if ($code !== 0 || !is_file($rawTxt) || filesize($rawTxt) < 1024) {
    http_response_code(500);
    echo json_encode([
      'ok' => false,
      'error' => 'extract_failed',
      'exit_code' => $code,
      'stdout' => $stdout,
      'stderr' => $stderr,
      'hint' => 'Pastikan Poppler pdftotext terpasang dan ada di PATH server.',
    ]);
    exit;
  }
}

$wantPages = isset($_GET['pages']) ? (int)$_GET['pages'] : 0;
$needPages = $wantPages && ($force || !is_file($pagesJsonl) || filesize($pagesJsonl) < 1024);
if ($needPages) {
  $in = @fopen($rawTxt, 'rb');
  if (!$in) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'open_raw_failed']);
    exit;
  }
  $out = @fopen($pagesJsonl, 'wb');
  if (!$out) {
    try { fclose($in); } catch (Throwable $e) {}
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'open_pages_failed']);
    exit;
  }
  $page = 1;
  $buf = '';
  while (!feof($in)) {
    $line = fgets($in);
    if ($line === false) break;
    if (strpos($line, "\f") !== false) {
      $parts = explode("\f", $line);
      $buf .= $parts[0];
      $rec = ['page' => $page, 'text' => rtrim($buf, "\r\n")];
      fwrite($out, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
      $page++;
      $buf = isset($parts[1]) ? $parts[1] : '';
      if (count($parts) > 2) {
        for ($i = 2; $i < count($parts); $i++) {
          $rec2 = ['page' => $page, 'text' => rtrim($buf, "\r\n")];
          fwrite($out, json_encode($rec2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
          $page++;
          $buf = $parts[$i];
        }
      }
      continue;
    }
    $buf .= $line;
  }
  if ($buf !== '') {
    $rec = ['page' => $page, 'text' => rtrim($buf, "\r\n")];
    fwrite($out, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  } else {
    $page--;
  }
  try { fclose($in); } catch (Throwable $e) {}
  try { fclose($out); } catch (Throwable $e) {}
  $pagesMeta = [
    'ok' => true,
    'pages' => max(0, (int)$page),
    'jsonl' => str_replace($root . DIRECTORY_SEPARATOR, '', $pagesJsonl),
    'bytes' => @filesize($pagesJsonl) ?: null,
    'sha256' => @hash_file('sha256', $pagesJsonl) ?: null,
    'generated_at' => gmdate('c'),
  ];
  @file_put_contents($pagesMetaPath, json_encode($pagesMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$pdfHash = @hash_file('sha256', $pdfPath);
$txtHash = is_file($rawTxt) ? @hash_file('sha256', $rawTxt) : null;
$pdfSize = @filesize($pdfPath);
$txtSize = is_file($rawTxt) ? @filesize($rawTxt) : null;
if (is_file($pagesJsonl)) {
  $pagesBytes = @filesize($pagesJsonl);
  $pagesCount = null;
  if (is_file($pagesMetaPath)) {
    $metaRaw = @file_get_contents($pagesMetaPath);
    $metaJson = $metaRaw ? json_decode($metaRaw, true) : null;
    if (is_array($metaJson) && isset($metaJson['pages'])) $pagesCount = (int)$metaJson['pages'];
  }
}

$manifest = [
  'ok' => true,
  'source' => [
    'file' => 'CP046.pdf',
    'sha256' => $pdfHash ?: null,
    'bytes' => is_int($pdfSize) ? $pdfSize : null,
  ],
  'output' => [
    'text_file' => str_replace($root . DIRECTORY_SEPARATOR, '', $rawTxt),
    'sha256' => $txtHash ?: null,
    'bytes' => is_int($txtSize) ? $txtSize : null,
    'pages_jsonl' => is_file($pagesJsonl) ? str_replace($root . DIRECTORY_SEPARATOR, '', $pagesJsonl) : null,
    'pages_meta' => is_file($pagesMetaPath) ? str_replace($root . DIRECTORY_SEPARATOR, '', $pagesMetaPath) : null,
  ],
  'extracted_at' => gmdate('c'),
  'tool' => [
    'name' => 'pdftotext',
    'args' => ['-layout', '-enc', 'UTF-8'],
  ],
];

@file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

echo json_encode([
  'ok' => true,
  'manifest' => str_replace($root . DIRECTORY_SEPARATOR, '', $manifestPath),
  'source_pdf' => str_replace($root . DIRECTORY_SEPARATOR, '', $pdfPath),
  'text_output' => str_replace($root . DIRECTORY_SEPARATOR, '', $rawTxt),
  'pages_output' => is_file($pagesJsonl) ? str_replace($root . DIRECTORY_SEPARATOR, '', $pagesJsonl) : null,
  'pages_meta' => is_file($pagesMetaPath) ? str_replace($root . DIRECTORY_SEPARATOR, '', $pagesMetaPath) : null,
  'bytes_pdf' => is_int($pdfSize) ? $pdfSize : null,
  'bytes_text' => is_int($txtSize) ? $txtSize : null,
]);
