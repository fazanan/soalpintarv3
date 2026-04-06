<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
  }

  if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'file_required']);
    exit;
  }

  $f = $_FILES['file'];
  if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'upload_failed']);
    exit;
  }

  $name = (string)($f['name'] ?? '');
  $tmp  = (string)($f['tmp_name'] ?? '');
  $size = (int)($f['size'] ?? 0);

  if ($size <= 0 || $size > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'file_too_large']);
    exit;
  }

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext !== 'docx') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_extension']);
    exit;
  }

  if (!class_exists('ZipArchive')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'zip_not_available']);
    exit;
  }

  $zip = new ZipArchive();
  if ($zip->open($tmp) !== true) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'zip_open_failed']);
    exit;
  }

  $index = $zip->locateName('word/document.xml', ZipArchive::FL_NODIR);
  if ($index === false) {
    $zip->close();
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'docx_invalid']);
    exit;
  }

  $xml = $zip->getFromIndex($index);
  $zip->close();
  if ($xml === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'read_failed']);
    exit;
  }

  $xml = preg_replace('/<w:tab[^>]*\\/>/i', "\t", $xml);
  $xml = preg_replace('/<w:br[^>]*\\/>/i', "\n", $xml);
  $xml = preg_replace('/<w:p[^>]*>/i', "\n", $xml);
  $xml = str_replace(['</w:p>'], ["\n"], $xml);
  $text = trim(strip_tags($xml));
  $text = preg_replace("/\\n{3,}/", "\n\n", $text);

  echo json_encode(['ok' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'exception']);
}

