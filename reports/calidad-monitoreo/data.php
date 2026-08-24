<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
  echo json_encode(require __DIR__ . '/build_report.php', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
  http_response_code(500);
  echo json_encode(['error' => true, 'message' => 'No fue posible construir el reporte.'], JSON_UNESCAPED_UNICODE);
}
