<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
@ini_set('display_errors', '0');
@error_reporting(E_ERROR | E_PARSE);
if (!isset($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? 'user'), ['admin','user'], true)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_json']);
  exit;
}

$dataUrl = isset($data['dataUrl']) ? (string)$data['dataUrl'] : '';
$httpUrl = isset($data['url']) ? (string)$data['url'] : '';
if ($httpUrl !== '') {
  if (!preg_match('#^https?://#i', $httpUrl) && !preg_match('#^/+#', $httpUrl)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'invalid_url']);
    exit;
  }
  echo json_encode(['ok'=>true,'url'=>$httpUrl]);
  exit;
}

if ($dataUrl === '' || !preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl, $m)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid_image_data']);
  exit;
}
$ext = strtolower($m[1]);
$base64 = substr($dataUrl, strpos($dataUrl, ',') + 1);
$bin = base64_decode($base64, true);
if ($bin === false) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'decode_fail']);
  exit;
}
$maxBytes = 3 * 1024 * 1024; // 3MB
if (strlen($bin) > $maxBytes) {
  http_response_code(413);
  echo json_encode(['ok'=>false,'error'=>'file_too_large','max_bytes'=>$maxBytes]);
  exit;
}

$subdir = date('Y/m');
$uploadDir = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'quiz_images' . DIRECTORY_SEPARATOR . $subdir;
if (!is_dir($uploadDir)) {
  @mkdir($uploadDir, 0775, true);
}
$fname = $_SESSION['user_id'] . '_' . bin2hex(random_bytes(6)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
$path = $uploadDir . DIRECTORY_SEPARATOR . $fname;
$ok = @file_put_contents($path, $bin);
if ($ok === false) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'write_fail']);
  exit;
}

$webPath = '/uploads/quiz_images/' . str_replace('\\','/',$subdir) . '/' . $fname;
echo json_encode(['ok'=>true,'url'=>$webPath], JSON_UNESCAPED_SLASHES);
exit;

