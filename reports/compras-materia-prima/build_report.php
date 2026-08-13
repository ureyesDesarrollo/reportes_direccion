<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$today = new DateTimeImmutable('today', $timezone);
$requestedDate = trim((string)($_GET['fecha'] ?? ''));
$selectedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, $timezone);
if (!$selectedDate instanceof DateTimeImmutable || $selectedDate->format('Y-m-d') !== $requestedDate) $selectedDate = $today;
$date = $selectedDate->format('Y-m-d');
$monday = $selectedDate->modify('monday this week');
$weekFactor = (int)$selectedDate->format('N');
$warning = '';
$sourceAvailable = true;

$daily = ['mp_molienda' => 0.0, 'entrada_granja' => 0.0, 'total_entradas' => 0.0, 'entrega_apelambrado' => 0.0, 'stock_granja' => 0.0];
$weekly = ['mp_molienda' => 0.0, 'entrada_granja' => 0.0, 'entrega_apelambrado' => 0.0];
$details = [];
$weekRows = [];

$fetchAll = static function (PDO $pdo, string $sql, array $params = []): array {
  $statement = $pdo->prepare($sql);
  $statement->execute($params);
  return $statement->fetchAll() ?: [];
};

try {
  $pdo = conectar((array)($config['database'] ?? []));

  $localRows = $fetchAll($pdo, "
    SELECT i.inv_no_ticket, i.inv_placas, i.inv_camioneta, i.inv_kilos, i.inv_kg_totales, p.prv_nombre
    FROM inventario i
    INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE i.inv_fecha = ? AND p.prv_tipo = 'L'
    ORDER BY i.inv_hora DESC, i.inv_id DESC
  ", [$date]);
  $warehouseRows = $fetchAll($pdo, "
    SELECT i.inv_no_ticket, i.inv_placas, i.inv_camioneta, i.inv_kilos, p.prv_nombre
    FROM inventario i
    INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE i.inv_fecha = ? AND p.prv_tipo = 'E' AND i.inv_id_key IS NULL AND i.inv_enviado = 0
    ORDER BY i.inv_hora DESC, i.inv_id DESC
  ", [$date]);
  $processedRows = $fetchAll($pdo, "
    SELECT i.inv_no_ticket, i.inv_placas, i.inv_camioneta, i.inv_kilos, i.inv_kg_totales, p.prv_nombre
    FROM inventario i
    INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE DATE(i.inv_fe_recibe) = ? AND i.inv_enviado = 2 AND p.prv_ban = 0
    ORDER BY i.inv_hora_salida2 DESC, i.inv_id DESC
  ", [$date]);
  $stockRows = $fetchAll($pdo, "
    SELECT CASE
      WHEN m.mat_nombre LIKE '%DEPILADO%' THEN 'Cuero depilado'
      WHEN m.mat_nombre LIKE '%PEDACERA%' THEN 'Pedacera americana'
      ELSE 'Cuero salado'
    END AS categoria, SUM(i.inv_kg_totales) AS kilos, COUNT(i.inv_id) AS camiones
    FROM inventario i
    INNER JOIN proveedores p ON p.prv_id = i.prv_id
    INNER JOIN materiales m ON m.mat_id = i.mat_id
    WHERE p.prv_tipo = 'E' AND i.inv_enviado = 0 AND i.inv_tomado = 0 AND p.prv_ban = 0
    GROUP BY categoria
    ORDER BY categoria
  ");

  foreach ($localRows as $row) $daily['mp_molienda'] += is_numeric($row['inv_kg_totales'] ?? null) ? (float)$row['inv_kg_totales'] : 0.0;
  foreach ($warehouseRows as $row) $daily['entrada_granja'] += is_numeric($row['inv_kilos'] ?? null) ? (float)$row['inv_kilos'] : 0.0;
  foreach ($processedRows as $row) $daily['entrega_apelambrado'] += is_numeric($row['inv_kg_totales'] ?? null) ? (float)$row['inv_kg_totales'] : 0.0;
  foreach ($stockRows as $row) $daily['stock_granja'] += is_numeric($row['kilos'] ?? null) ? (float)$row['kilos'] : 0.0;
  $daily['total_entradas'] = $daily['mp_molienda'] + $daily['entrada_granja'];

  $ticketDetail = static function (array $rows, string $valueField): array {
    return array_map(static fn(array $row): array => [
      'concepto' => (string)($row['prv_nombre'] ?? 'Sin proveedor'),
      'referencia' => 'Ticket ' . (string)($row['inv_no_ticket'] ?? '—') . ' · ' . trim((string)($row['inv_placas'] ?? '') . ' ' . (string)($row['inv_camioneta'] ?? '')),
      'kg' => is_numeric($row[$valueField] ?? null) ? (float)$row[$valueField] : 0.0,
    ], $rows);
  };
  $details['mp_molienda'] = $ticketDetail($localRows, 'inv_kg_totales');
  $details['entrada_granja'] = $ticketDetail($warehouseRows, 'inv_kilos');
  $details['entrega_apelambrado'] = $ticketDetail($processedRows, 'inv_kg_totales');
  $details['total_entradas'] = [
    ['concepto' => 'MP para molienda', 'referencia' => count($localRows) . ' movimiento(s)', 'kg' => $daily['mp_molienda']],
    ['concepto' => 'Entrada para la granja', 'referencia' => count($warehouseRows) . ' movimiento(s)', 'kg' => $daily['entrada_granja']],
  ];
  $details['stock_granja'] = array_map(static fn(array $row): array => [
    'concepto' => (string)($row['categoria'] ?? 'Material'),
    'referencia' => (string)($row['camiones'] ?? 0) . ' camión(es)',
    'kg' => is_numeric($row['kilos'] ?? null) ? (float)$row['kilos'] : 0.0,
  ], $stockRows);

  $weeklyLocal = $fetchAll($pdo, "
    SELECT i.inv_fecha AS fecha, SUM(i.inv_kg_totales) AS kilos
    FROM inventario i INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE i.inv_fecha BETWEEN ? AND ? AND p.prv_tipo = 'L'
    GROUP BY i.inv_fecha
  ", [$monday->format('Y-m-d'), $date]);
  $weeklyWarehouse = $fetchAll($pdo, "
    SELECT i.inv_fecha AS fecha, SUM(i.inv_kilos) AS kilos
    FROM inventario i INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE i.inv_fecha BETWEEN ? AND ? AND p.prv_tipo = 'E' AND i.inv_id_key IS NULL AND i.inv_enviado = 0
    GROUP BY i.inv_fecha
  ", [$monday->format('Y-m-d'), $date]);
  $weeklyProcessed = $fetchAll($pdo, "
    SELECT DATE(i.inv_fe_recibe) AS fecha, SUM(i.inv_kg_totales) AS kilos
    FROM inventario i INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE i.inv_fe_recibe >= ? AND i.inv_fe_recibe < DATE_ADD(?, INTERVAL 1 DAY)
      AND i.inv_enviado = 2 AND p.prv_ban = 0
    GROUP BY DATE(i.inv_fe_recibe)
  ", [$monday->format('Y-m-d'), $date]);

  $byDate = [];
  foreach (['mp_molienda' => $weeklyLocal, 'entrada_granja' => $weeklyWarehouse, 'entrega_apelambrado' => $weeklyProcessed] as $key => $rows) {
    foreach ($rows as $row) {
      $rowDate = (string)($row['fecha'] ?? '');
      $value = is_numeric($row['kilos'] ?? null) ? (float)$row['kilos'] : 0.0;
      $byDate[$key][$rowDate] = $value;
      $weekly[$key] += $value;
    }
  }
  $dayNames = [1 => 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
  for ($index = 0; $index < $weekFactor; $index++) {
    $rowDate = $monday->modify('+' . $index . ' days');
    $rowKey = $rowDate->format('Y-m-d');
    $weekRows[] = [
      'fecha' => $rowDate->format('d/m/Y'),
      'dia' => $dayNames[(int)$rowDate->format('N')],
      'mp_molienda' => (float)($byDate['mp_molienda'][$rowKey] ?? 0),
      'entrada_granja' => (float)($byDate['entrada_granja'][$rowKey] ?? 0),
      'entrega_apelambrado' => (float)($byDate['entrega_apelambrado'][$rowKey] ?? 0),
    ];
  }
} catch (Throwable $exception) {
  $sourceAvailable = false;
  $warning = 'La fuente de materia prima no está disponible en este momento.';
}

$scaleBands = static function (array $bands, float $factor): array {
  return array_map(static function (array $band) use ($factor): array {
    if (isset($band['min']) && is_numeric($band['min'])) $band['min'] = (float)$band['min'] * $factor;
    if (isset($band['max']) && is_numeric($band['max'])) $band['max'] = (float)$band['max'] * $factor;
    return $band;
  }, $bands);
};
$statusFor = static function (?float $tonnes, array $bands, bool $available): string {
  if (!$available || $tonnes === null) return 'gris';
  foreach ($bands as $band) {
    $minimum = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
    $maximum = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
    if (($minimum === null || $tonnes >= $minimum) && ($maximum === null || $tonnes <= $maximum)) return (string)($band['estado'] ?? 'gris');
  }
  return 'gris';
};

$makeMetric = static function (string $key, float $kilograms, array $definition, bool $weeklyMetric = false) use ($sourceAvailable, $weekFactor, $scaleBands, $statusFor): array {
  $factor = $weeklyMetric ? (float)$weekFactor : 1.0;
  $bands = $scaleBands((array)($definition['bandas'] ?? []), $factor);
  $tonnes = $sourceAvailable ? $kilograms / 1000 : null;
  return [
    'key' => $key,
    'label' => (string)($definition['label'] ?? $key),
    'kilogramos' => $sourceAvailable ? $kilograms : null,
    'toneladas' => $tonnes,
    'estado' => $statusFor($tonnes, $bands, $sourceAvailable),
    'bandas' => $bands,
    'semanal' => $weeklyMetric,
  ];
};

$metricDefinitions = (array)($config['metricas'] ?? []);
$dailyMetrics = [];
foreach ($daily as $key => $value) $dailyMetrics[$key] = $makeMetric($key, $value, (array)($metricDefinitions[$key] ?? []));
$weeklyMetrics = [];
foreach ($weekly as $key => $value) {
  $weeklyMetrics[$key] = $makeMetric('semanal_' . $key, $value, (array)($metricDefinitions[$key] ?? []), true);
  $weeklyMetrics[$key]['label'] = 'Acumulado · ' . (string)($metricDefinitions[$key]['label'] ?? $key);
  $weeklyMetrics[$key]['detalle_key'] = 'semanal_' . $key;
  $details['semanal_' . $key] = array_map(static fn(array $row): array => [
    'concepto' => (string)$row['dia'],
    'referencia' => (string)$row['fecha'],
    'kg' => (float)$row[$key],
  ], $weekRows);
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Compra de Materia Prima'),
  'subtitulo' => (string)($config['subtitulo'] ?? ''),
  'fecha' => $date,
  'fecha_label' => $selectedDate->format('d/m/Y'),
  'semana_inicio' => $monday->format('d/m/Y'),
  'semana_fin' => $selectedDate->format('d/m/Y'),
  'factor_semanal' => $weekFactor,
  'diarios' => $dailyMetrics,
  'semanales' => $weeklyMetrics,
  'detalles' => $details,
  'warning' => $warning,
  'actualizado' => (new DateTimeImmutable('now', $timezone))->format('d/m/Y H:i'),
];
