<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
  echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => true,
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
