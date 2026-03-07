<?php
declare(strict_types=1);
// Simple DOCX reader that extracts plain text from a whitelisted file.
// Security: only allow Prompt_Generator_ModulAjar.docx in project root.
header('Content-Type: application/json; charset=utf-8');
try {
  $allowed = ['Prompt_Generator_ModulAjar.docx'];
  $file = isset($_GET['file']) ? basename((string)$_GET['file']) : '';
  if (!in_array($file, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'file_not_allowed']);
    exit;
  }
  $path = realpath(__DIR__ . '/../' . $file);
  if (!$path || !is_file($path)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_found']);
    exit;
  }
  if (!class_exists('ZipArchive')) {
    echo json_encode(['ok' => false, 'error' => 'zip_not_available']);
    exit;
  }
  $zip = new ZipArchive();
  if ($zip->open($path) !== true) {
    echo json_encode(['ok' => false, 'error' => 'zip_open_failed']);
    exit;
  }
  $index = $zip->locateName('word/document.xml', ZipArchive::FL_NODIR);
  if ($index === false) {
    $zip->close();
    echo json_encode(['ok' => false, 'error' => 'docx_invalid']);
    exit;
  }
  $xml = $zip->getFromIndex($index);
  $zip->close();
  if ($xml === false) {
    echo json_encode(['ok' => false, 'error' => 'read_failed']);
    exit;
  }
  // Replace Word paragraph and breaks with newlines, strip all tags
  $xml = preg_replace('/<w:tab[^>]*\\/>/i', "\t", $xml);
  $xml = preg_replace('/<w:br[^>]*\\/>/i', "\n", $xml);
  $xml = preg_replace('/<w:p[^>]*>/i', "\n", $xml);
  $xml = str_replace(['</w:p>'], ["\n"], $xml);
  $text = trim(strip_tags($xml));
  // Normalize multiple newlines
  $text = preg_replace("/\\n{3,}/", "\n\n", $text);
  echo json_encode(['ok' => true, 'text' => $text], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'exception']);
}

