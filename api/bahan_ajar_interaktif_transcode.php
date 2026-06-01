<?php
session_start();
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);

if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? 'user'), ['admin','user'], true)) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
  exit;
}

@set_time_limit(0);

if (!function_exists('proc_open') || !is_callable('proc_open')) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'proc_open_disabled', 'hint' => 'Fitur proc_open dinonaktifkan di PHP (disable_functions). Aktifkan proc_open untuk menjalankan ffmpeg.']);
  exit;
}

if (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'missing_video']);
  exit;
}

$f = $_FILES['video'];
$err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'upload_error', 'code' => $err]);
  exit;
}

$size = (int)($f['size'] ?? 0);
$maxBytes = 120 * 1024 * 1024;
if ($size <= 0 || $size > $maxBytes) {
  http_response_code($size > $maxBytes ? 413 : 400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'file_too_large', 'max_bytes' => $maxBytes]);
  exit;
}

$tmpUpload = (string)($f['tmp_name'] ?? '');
if ($tmpUpload === '' || !is_uploaded_file($tmpUpload)) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'invalid_upload']);
  exit;
}

$tmpIn = tempnam(sys_get_temp_dir(), 'sp_vid_in_');
if ($tmpIn === false) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'temp_in_fail']);
  exit;
}
$tmpOutBase = tempnam(sys_get_temp_dir(), 'sp_vid_out_');
if ($tmpOutBase === false) {
  @unlink($tmpIn);
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'temp_out_fail']);
  exit;
}
$tmpOut = $tmpOutBase . '.mp4';
@unlink($tmpOutBase);

if (!@move_uploaded_file($tmpUpload, $tmpIn)) {
  @unlink($tmpIn);
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'move_fail']);
  exit;
}

$ffmpegCandidates = [];
$envFfmpeg = trim((string)(getenv('FFMPEG_PATH') ?: ''));
if ($envFfmpeg !== '') $ffmpegCandidates[] = $envFfmpeg;
if (PHP_OS_FAMILY === 'Windows') {
  $root = realpath(__DIR__ . '/..');
  if (is_string($root) && $root !== '') {
    $ffmpegCandidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'ffmpeg.exe';
    $ffmpegCandidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe';
    $ffmpegCandidates[] = $root . DIRECTORY_SEPARATOR . 'ffmpeg' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ffmpeg.exe';
  }
  $ffmpegCandidates[] = 'C:\\ffmpeg\\bin\\ffmpeg.exe';
  $ffmpegCandidates[] = 'C:\\ffmpeg\\ffmpeg.exe';
  $ffmpegCandidates[] = 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe';
}
$ffmpegCandidates[] = 'ffmpeg';

$ffmpeg = '';
foreach ($ffmpegCandidates as $cand) {
  $cand = trim((string)$cand);
  if ($cand === '') continue;
  if ($cand !== 'ffmpeg' && is_file($cand)) { $ffmpeg = $cand; break; }
}
if ($ffmpeg === '') $ffmpeg = $envFfmpeg;
if ($ffmpeg === '') {
  if (PHP_OS_FAMILY === 'Windows') {
    @unlink($tmpIn);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => false,
      'error' => 'ffmpeg_not_found',
      'hint' => 'Letakkan ffmpeg.exe di folder project: storage/ffmpeg/bin/ffmpeg.exe (beserta semua DLL di folder bin). Atau set environment variable FFMPEG_PATH ke full path ffmpeg.exe.',
      'ffmpeg_candidates' => $ffmpegCandidates,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  $ffmpeg = 'ffmpeg';
}

$filter = 'scale=720:1280:force_original_aspect_ratio=decrease,pad=720:1280:(ow-iw)/2:(oh-ih)/2:black,fps=30';
$cmd = [
  $ffmpeg,
  '-nostdin',
  '-y',
  '-hide_banner',
  '-loglevel',
  'error',
  '-i',
  $tmpIn,
  '-vf',
  $filter,
  '-c:v',
  'libx264',
  '-preset',
  'veryfast',
  '-crf',
  '23',
  '-pix_fmt',
  'yuv420p',
  '-c:a',
  'aac',
  '-b:a',
  '128k',
  '-ar',
  '44100',
  '-movflags',
  '+faststart',
  $tmpOut,
];

$descriptors = [
  0 => ['pipe', 'r'],
  1 => ['pipe', 'w'],
  2 => ['pipe', 'w'],
];
$proc = @proc_open($cmd, $descriptors, $pipes, null, null);
if (!is_resource($proc)) {
  $last = error_get_last();
  @unlink($tmpIn);
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  $disable = (string)(ini_get('disable_functions') ?: '');
  $openBasedir = (string)(ini_get('open_basedir') ?: '');
  echo json_encode([
    'ok' => false,
    'error' => 'proc_open_failed',
    'ffmpeg_used' => $ffmpeg,
    'ffmpeg_candidates' => $ffmpegCandidates,
    'disable_functions' => $disable,
    'open_basedir' => $openBasedir,
    'last_error' => is_array($last) ? ($last['message'] ?? '') : '',
    'hint' => 'Jika ini Windows, pastikan ffmpeg.exe dipanggil via full path dan folder bin berisi semua DLL. Jika pakai hosting/shared hosting, kemungkinan proc_open diblok.',
  ]);
  exit;
}

try { @fclose($pipes[0]); } catch (Throwable $e) {}
$stdout = '';
$stderr = '';
try { $stdout = stream_get_contents($pipes[1]); } catch (Throwable $e) {}
try { $stderr = stream_get_contents($pipes[2]); } catch (Throwable $e) {}
try { @fclose($pipes[1]); } catch (Throwable $e) {}
try { @fclose($pipes[2]); } catch (Throwable $e) {}
$exitCode = proc_close($proc);

if ($exitCode !== 0 || !is_file($tmpOut) || filesize($tmpOut) < 1024) {
  @unlink($tmpIn);
  @unlink($tmpOut);
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  $pathEnv = (string)(getenv('PATH') ?: '');
  echo json_encode([
    'ok' => false,
    'error' => 'ffmpeg_failed',
    'exit_code' => $exitCode,
    'stderr' => mb_substr((string)$stderr, 0, 2000),
    'stdout' => mb_substr((string)$stdout, 0, 2000),
    'hint' => 'Pastikan ffmpeg terpasang dan ada di PATH server.',
    'ffmpeg_used' => $ffmpeg,
    'ffmpeg_candidates' => $ffmpegCandidates,
    'path_head' => mb_substr($pathEnv, 0, 3000),
  ]);
  exit;
}

$pad2 = fn($n) => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
$dt = new DateTime('now');
$yyyy = $dt->format('Y');
$mm = $dt->format('m');
$dd = $dt->format('d');
$hh = $dt->format('H');
$mi = $dt->format('i');
$ss = $dt->format('s');
$filename = "WhatsApp Video {$yyyy}-{$mm}-{$dd} at {$hh}.{$mi}.{$ss}.mp4";

header('Content-Type: video/mp4');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpOut));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

@readfile($tmpOut);
@unlink($tmpIn);
@unlink($tmpOut);
exit;
