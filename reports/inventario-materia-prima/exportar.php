<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(120);

$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/xlsx_writer.php';

$c = static fn($value, string $type = 'string', string $style = 'default'): array
  => InventarioMateriaPrimaXlsxWriter::cell($value, $type, $style);
$statusKey = static function (array $metric): string {
  $key = (string)($metric['status']['key'] ?? 'gris');
  return in_array($key, ['verde', 'amarillo', 'rojo'], true) ? $key : 'gris';
};
$excelDate = static function ($value): ?float {
  $date = DateTimeImmutable::createFromFormat('!d/m/Y', (string)$value, new DateTimeZone('UTC'));
  return $date ? ((float)$date->format('U') / 86400) + 25569 : null;
};

try {
  $report = require __DIR__ . '/build_report.php';
  $filters = (array)$report['filtros'];
  $options = (array)$report['opciones'];
  $meta = (array)$report['meta'];

  $materialLabel = 'Todos';
  foreach ((array)($options['materiales'] ?? []) as $option) {
    if ((int)($option['mat_id'] ?? 0) === (int)($filters['material'] ?? 0)) {
      $materialLabel = (string)$option['mat_nombre'];
    }
  }
  $providerLabel = 'Todos';
  foreach ((array)($options['proveedores'] ?? []) as $option) {
    if ((int)($option['prv_id'] ?? 0) === (int)($filters['proveedor'] ?? 0)) {
      $providerLabel = (string)$option['prv_nombre'];
    }
  }
  $scope = 'Periodo: ' . (string)($meta['periodo_label'] ?? '')
    . ' | Material: ' . $materialLabel . ' | Proveedor: ' . $providerLabel;

  $resultRows = [
    [$c('Inventario de Materia Prima', 'string', 'title')],
    [$c($scope, 'string', 'subtitle')],
    [],
    array_map(static fn(string $label): array => InventarioMateriaPrimaXlsxWriter::cell($label, 'string', 'header'), [
      'No.', 'Ticket', 'Fecha', 'Kilos', 'Tipo de material', 'Proveedor',
      'Humedad', 'Conductividad', 'pH', 'Sólidos', 'Extractibilidad', 'Rendimiento',
      'Semáforo', 'Observaciones',
    ]),
  ];

  foreach ((array)$report['filas'] as $row) {
    $metrics = (array)$row['metricas'];
    $overall = (array)$row['semaforo'];
    $overallKey = in_array((string)($overall['key'] ?? ''), ['verde', 'amarillo', 'rojo'], true)
      ? (string)$overall['key'] : 'gris';
    $resultRows[] = [
      $c((int)$row['numero'], 'integer'),
      $c((string)$row['ticket']),
      $c($excelDate($row['fecha']), 'date'),
      $c((float)$row['kilos'], 'number'),
      $c($row['material']),
      $c($row['proveedor']),
      $c($metrics['humedad']['value'] ?? null, 'number', $statusKey((array)$metrics['humedad'])),
      $c($metrics['conductividad']['value'] ?? null, 'number', $statusKey((array)$metrics['conductividad'])),
      $c($metrics['ph']['value'] ?? null, 'number', $statusKey((array)$metrics['ph'])),
      $c($metrics['solidos']['value'] ?? null, 'number', $statusKey((array)$metrics['solidos'])),
      $c($metrics['extractibilidad']['value'] ?? null, 'number', $statusKey((array)$metrics['extractibilidad'])),
      $c($metrics['rendimiento']['value'] ?? null, 'number', $statusKey((array)$metrics['rendimiento'])),
      $c((string)($overall['label'] ?? 'Pendiente'), 'string', $overallKey),
      $c((string)($row['observaciones'] ?? '')),
    ];
  }

  $wholeLeatherRows = [
    [$c('Cuero entero', 'string', 'title')],
    [$c($scope, 'string', 'subtitle')],
    [],
    array_map(static fn(string $label): array => InventarioMateriaPrimaXlsxWriter::cell($label, 'string', 'header'), [
      'Fecha', 'Ticket', 'Proveedor', 'Material', 'Kg compra', 'Humedad origen',
      'Entregas', 'Kg granja', 'Rend. granja', 'Prom. humedad', 'Prom. conductividad',
      'Prom. pH', 'Prom. sólidos', 'Prom. extractibilidad', 'Prom. rendimiento', 'Observaciones',
    ]),
  ];

  foreach ((array)($report['compras_cuero_americano'] ?? []) as $row) {
    $averages = (array)$row['promedios'];
    $origin = (array)$row['humedad_origen'];
    $deliveries = (int)$row['numero_entregas'];
    $wholeLeatherRows[] = [
      $c($excelDate($row['fecha']), 'date'),
      $c((string)$row['ticket']),
      $c($row['proveedor']),
      $c($row['material']),
      $c((float)$row['kilos_compra'], 'number'),
      $c($origin['value'] ?? null, 'number', $statusKey($origin)),
      $c($deliveries > 0 ? $deliveries : null, 'integer'),
      $c($row['kilos_granja'] ?? null, 'number'),
      $c($row['rendimiento_granja'] ?? null, 'percent_points'),
      $c($averages['humedad']['value'] ?? null, 'number', $statusKey((array)$averages['humedad'])),
      $c($averages['conductividad']['value'] ?? null, 'number', $statusKey((array)$averages['conductividad'])),
      $c($averages['ph']['value'] ?? null, 'number', $statusKey((array)$averages['ph'])),
      $c($averages['solidos']['value'] ?? null, 'number', $statusKey((array)$averages['solidos'])),
      $c($averages['extractibilidad']['value'] ?? null, 'number', $statusKey((array)$averages['extractibilidad'])),
      $c($averages['rendimiento']['value'] ?? null, 'number', $statusKey((array)$averages['rendimiento'])),
      $c((string)($row['observaciones'] ?? '')),
    ];
  }

  $path = InventarioMateriaPrimaXlsxWriter::create([
    [
      'name' => 'Resultados',
      'rows' => $resultRows,
      'header_row' => 4,
      'widths' => [7, 11, 12, 14, 28, 28, 13, 16, 11, 12, 16, 15, 17, 32],
    ],
    [
      'name' => 'Cuero entero',
      'rows' => $wholeLeatherRows,
      'header_row' => 4,
      'widths' => [12, 11, 28, 30, 14, 16, 11, 14, 15, 15, 17, 12, 14, 17, 18, 30],
    ],
  ], 'Inventario de Materia Prima');

  $filename = sprintf('inventario-materia-prima-%04d-%02d.xlsx', (int)$filters['anio'], (int)$filters['mes']);
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . (string)filesize($path));
  header('Cache-Control: private, max-age=0, must-revalidate');
  readfile($path);
  @unlink($path);
} catch (Throwable $exception) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'No fue posible generar el archivo Excel.';
}
