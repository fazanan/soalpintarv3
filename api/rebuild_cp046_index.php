<?php
if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "cli_only";
  exit;
}

$_GET['force'] = 1;
include __DIR__ . DIRECTORY_SEPARATOR . 'parse_cp046_stage2.php';

