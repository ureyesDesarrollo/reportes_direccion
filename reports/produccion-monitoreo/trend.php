<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$cardKey = trim((string)($_GET['card'] ?? ''));
$rowKey = trim((string)($_GET['row'] ?? ''));

try {
  if ($cardKey === '' || $rowKey === '') {
    throw new InvalidArgumentException('Faltan parametros de tendencia.');
  }

  $report = require __DIR__ . '/build_report.php';
  $card = (array)($report['cards'][$cardKey] ?? []);
  if ($card === []) {
    throw new RuntimeException('No se encontro la tarjeta solicitada.');
  }

  $row = null;
  foreach ((array)($card['tabla'] ?? []) as $tableRow) {
    if ((string)($tableRow['key'] ?? '') === $rowKey) {
      $row = (array)$tableRow;
      break;
    }
  }

  if ($row === null) {
    foreach ((array)($card['tuneles'] ?? []) as $tunnelKey => $tunnel) {
      foreach ((array)($tunnel['items'] ?? []) as $item) {
        if ((string)($item['key'] ?? '') !== $rowKey) {
          continue;
        }

        $item = (array)$item;
        $row = [
          'key' => (string)($item['key'] ?? $rowKey),
          'label' => (string)($item['label'] ?? $rowKey),
          'rangeLabel' => (string)($item['rangeLabel'] ?? ''),
          'sources' => !empty($item['source']) ? [(string)$item['source']] : [],
          'rule' => (array)($item['rule'] ?? []),
          'unit' => (string)($item['unit'] ?? ''),
          'trendTuneles' => [
            (string)$tunnelKey => [
              'key' => (string)($tunnel['key'] ?? $tunnelKey),
              'titulo' => (string)($tunnel['titulo'] ?? $tunnelKey),
            ],
          ],
          'values' => [
            (string)$tunnelKey => $item,
          ],
        ];
        break 2;
      }
    }
  }

  if ($row === null) {
    throw new RuntimeException('No se encontro el parametro solicitado.');
  }

  echo json_encode([
    'error' => false,
    'card' => [
      'key' => (string)($card['key'] ?? $cardKey),
      'titulo' => (string)($card['titulo'] ?? $cardKey),
    ],
    'row' => $row,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'error' => true,
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
