<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'Etc/GMT+6');
date_default_timezone_set($timezone);
$tz = new DateTimeZone($timezone);
$today = new DateTimeImmutable('today', $tz);
$params = (array)($config['parametros'] ?? []);
$monthNames = [
  1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
  5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
  9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];
$safeInt = static function ($value, int $fallback, int $min, int $max): int {
  if (!is_scalar($value) || !is_numeric($value)) return $fallback;
  $number = (int)$value;
  return $number >= $min && $number <= $max ? $number : $fallback;
};

$pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'prod')] ?? $dbConfig['prod']));

$latestDate = (string)($pdo->query("
  SELECT COALESCE(MAX(fecha), '')
  FROM (
    SELECT inv_fecha fecha
    FROM inventario
    WHERE inv_fecha IS NOT NULL
      AND (inv_enviado IS NULL OR inv_enviado <> 3)
    UNION ALL
    SELECT DATE(inv_fe_recibe) fecha
    FROM inventario
    WHERE inv_fe_recibe IS NOT NULL
      AND (inv_enviado IS NULL OR inv_enviado <> 3)
  ) fechas
")->fetchColumn() ?: '');

$latestReference = $latestDate !== '' ? new DateTimeImmutable($latestDate . ' 00:00:00', $tz) : $today;
$selectedYear = $safeInt($_GET['anio'] ?? null, (int)$latestReference->format('Y'), 2020, 2100);
$selectedMonth = $safeInt($_GET['mes'] ?? null, (int)$latestReference->format('n'), 1, 12);
$periodMode = (string)($_GET['periodo'] ?? 'mes');
if (!in_array($periodMode, ['mes', 'semana', 'fecha'], true)) $periodMode = 'mes';
$legacyDateValue = trim((string)($_GET['fecha'] ?? ''));
$selectedStartDateValue = trim((string)($_GET['fecha_inicio'] ?? $legacyDateValue));
$selectedEndDateValue = trim((string)($_GET['fecha_fin'] ?? $legacyDateValue));
$parseSelectedDate = static function (string $value, DateTimeImmutable $fallback, DateTimeZone $timezone): DateTimeImmutable {
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
  return $date instanceof DateTimeImmutable
    && $date->format('Y-m-d') === $value
    && (int)$date->format('Y') >= 2020
    && (int)$date->format('Y') <= 2100
      ? $date
      : $fallback;
};
$selectedStartDate = $parseSelectedDate($selectedStartDateValue, $today, $tz);
$selectedEndDate = $parseSelectedDate($selectedEndDateValue, $selectedStartDate, $tz);
if ($selectedEndDate < $selectedStartDate) {
  [$selectedStartDate, $selectedEndDate] = [$selectedEndDate, $selectedStartDate];
}
$legacyWeekValue = trim((string)($_GET['semana'] ?? ''));
$selectedWeekStartValue = trim((string)($_GET['semana_inicio'] ?? $legacyWeekValue));
$selectedWeekEndValue = trim((string)($_GET['semana_fin'] ?? $legacyWeekValue));
$parseSelectedWeek = static function (string $value, DateTimeImmutable $fallback): DateTimeImmutable {
  $matches = [];
  if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $matches) !== 1) return $fallback;
  $weekStart = $fallback->setISODate((int)$matches[1], (int)$matches[2], 1)->setTime(0, 0);
  return $weekStart->format('o-\WW') === $value ? $weekStart : $fallback;
};
$currentWeekStart = $today->modify('monday this week')->setTime(0, 0);
$selectedWeekStart = $parseSelectedWeek($selectedWeekStartValue, $currentWeekStart);
$selectedWeekEnd = $parseSelectedWeek($selectedWeekEndValue, $selectedWeekStart);
if ($selectedWeekEnd < $selectedWeekStart) {
  [$selectedWeekStart, $selectedWeekEnd] = [$selectedWeekEnd, $selectedWeekStart];
}
$selectedWeekValue = $selectedWeekStart->format('o-\WW');
$selectedWeekEndValue = $selectedWeekEnd->format('o-\WW');

$yearOptions = array_values(array_filter(array_map('intval', array_column($pdo->query("
  SELECT DISTINCT YEAR(fecha) anio
  FROM (
    SELECT inv_fecha fecha
    FROM inventario
    WHERE inv_fecha IS NOT NULL
      AND (inv_enviado IS NULL OR inv_enviado <> 3)
    UNION ALL
    SELECT inv_fe_recibe fecha
    FROM inventario
    WHERE inv_fe_recibe IS NOT NULL
      AND (inv_enviado IS NULL OR inv_enviado <> 3)
  ) fechas
  ORDER BY anio DESC
")->fetchAll() ?: [], 'anio'))));
if ($periodMode === 'mes' && !in_array($selectedYear, $yearOptions, true)) {
  $selectedYear = (int)$latestReference->format('Y');
}
if ($periodMode === 'fecha') {
  $selectedYear = (int)$selectedStartDate->format('Y');
  $selectedMonth = (int)$selectedStartDate->format('n');
  $periodStart = $selectedStartDate->setTime(0, 0);
  $periodEnd = $selectedEndDate->setTime(0, 0)->modify('+1 day');
} elseif ($periodMode === 'semana') {
  $periodStart = $selectedWeekStart;
  $periodEnd = $selectedWeekEnd->modify('+7 days');
  $selectedYear = (int)$periodStart->format('Y');
  $selectedMonth = (int)$periodStart->format('n');
} else {
  $periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
  $periodEnd = $periodStart->modify('first day of next month');
}

$selectedMaterial = filter_var($_GET['material'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedMaterial = $selectedMaterial === false ? null : (int)$selectedMaterial;
$selectedProvider = filter_var($_GET['proveedor'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$selectedProvider = $selectedProvider === false ? null : (int)$selectedProvider;
$wholeLeatherMaterialIds = array_values(array_filter(array_map('intval', (array)($config['materiales_cuero_entero'] ?? []))));

$materialOptions = $pdo->query("
  SELECT DISTINCT m.mat_id, m.mat_nombre
  FROM inventario i
  INNER JOIN materiales m ON m.mat_id = i.mat_id
  WHERE i.inv_enviado IS NULL OR i.inv_enviado <> 3
  ORDER BY m.mat_nombre
")->fetchAll() ?: [];
$providerOptions = $pdo->query("
  SELECT DISTINCT p.prv_id, p.prv_nombre
  FROM inventario i
  INNER JOIN proveedores p ON p.prv_id = i.prv_id
  WHERE i.inv_enviado IS NULL OR i.inv_enviado <> 3
  ORDER BY p.prv_nombre
")->fetchAll() ?: [];

$validMaterialIds = array_map('intval', array_column($materialOptions, 'mat_id'));
if ($selectedMaterial !== null && !in_array($selectedMaterial, $validMaterialIds, true)) $selectedMaterial = null;
$validProviderIds = array_map('intval', array_column($providerOptions, 'prv_id'));
if ($selectedProvider !== null && !in_array($selectedProvider, $validProviderIds, true)) $selectedProvider = null;

$where = [
  'i.inv_fecha >= ?',
  'i.inv_fecha < ?',
  '(i.inv_enviado IS NULL OR i.inv_enviado <> 3)',
];
$queryParams = [$periodStart->format('Y-m-d'), $periodEnd->format('Y-m-d')];
if ($selectedMaterial !== null) {
  $where[] = 'i.mat_id = ?';
  $queryParams[] = $selectedMaterial;
}
if ($selectedProvider !== null) {
  $where[] = 'i.prv_id = ?';
  $queryParams[] = $selectedProvider;
}

$stmt = $pdo->prepare("
  SELECT i.inv_id, i.inv_fecha, i.inv_no_ticket, i.inv_kilos,
         i.mat_id, m.mat_nombre, i.prv_id, p.prv_nombre,
         i.inv_humedad, i.inv_ce, i.inv_ph, i.inv_solidos,
         i.inv_extrac, i.inv_rendimiento, i.inv_observaciones
  FROM inventario i
  INNER JOIN materiales m ON m.mat_id = i.mat_id
  INNER JOIN proveedores p ON p.prv_id = i.prv_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY i.inv_fecha DESC, i.inv_hora DESC, i.inv_id DESC
");
$stmt->execute($queryParams);
$rawRows = $stmt->fetchAll() ?: [];

$evaluateBands = static function ($value, array $rule): array {
  if ($value === null || $value === '' || !is_numeric($value)) {
    return ['key' => 'gris', 'label' => 'Sin dato', 'range' => (string)($rule['leyenda'] ?? '')];
  }
  $number = (float)$value;
  foreach ((array)($rule['bandas'] ?? []) as $band) {
    $minOk = !array_key_exists('min', $band) || $number >= (float)$band['min'];
    $maxOk = !array_key_exists('max', $band) || $number <= (float)$band['max'];
    if ($minOk && $maxOk) {
      return [
        'key' => (string)($band['estado'] ?? 'gris'),
        'label' => ['verde' => 'En objetivo', 'amarillo' => 'Alerta', 'rojo' => 'Fuera de rango'][(string)($band['estado'] ?? '')] ?? 'Sin dato',
        'range' => (string)($rule['leyenda'] ?? ''),
      ];
    }
  }
  return ['key' => 'gris', 'label' => 'Sin rango', 'range' => (string)($rule['leyenda'] ?? '')];
};

$conductivityRule = static function (int $materialId, string $materialName) use ($config, $params): array {
  $map = (array)($params['conductividad'] ?? []);
  $key = strtoupper(trim($materialName));
  if (in_array($materialId, array_map('intval', (array)($config['materiales_cuero_entero_pedacera'] ?? [])), true)) {
    $key = 'CUERO_ENTERO_PEDACERA';
  }
  $limits = (array)($map[$key] ?? []);
  if ($limits === []) return [];
  $greenMin = (float)$limits['verde_min'];
  $greenMax = (float)$limits['verde_max'];
  $yellowMin = (float)$limits['amarillo_min'];
  $yellowMax = (float)$limits['amarillo_max'];
  return [
    'modo' => 'bandas',
    'leyenda' => number_format($greenMin, 2, '.', '') . '–' . number_format($greenMax, 2, '.', '')
      . ' · máx. ' . number_format($yellowMax, 2, '.', ''),
    'bandas' => [
      ['min' => $greenMin, 'max' => $greenMax, 'estado' => 'verde'],
      ['min' => $yellowMin, 'max' => $greenMin, 'estado' => 'amarillo'],
      ['min' => $greenMax, 'max' => $yellowMax, 'estado' => 'amarillo'],
      ['max' => $yellowMin - 0.000001, 'estado' => 'rojo'],
      ['min' => $yellowMax + 0.000001, 'estado' => 'rojo'],
    ],
  ];
};

$priority = ['gris' => 0, 'verde' => 1, 'amarillo' => 2, 'rojo' => 3];
$rows = [];

foreach ($rawRows as $index => $raw) {
  $conductivity = $conductivityRule((int)$raw['mat_id'], (string)$raw['mat_nombre']);
  $metrics = [
    'humedad' => ['value' => $raw['inv_humedad'], 'status' => $evaluateBands($raw['inv_humedad'], (array)($params['humedad'] ?? []))],
    'conductividad' => ['value' => $raw['inv_ce'], 'status' => $conductivity === [] ? ['key' => 'gris', 'label' => 'Sin rango', 'range' => 'Según material'] : $evaluateBands($raw['inv_ce'], $conductivity)],
    'ph' => ['value' => $raw['inv_ph'], 'status' => $evaluateBands($raw['inv_ph'], (array)($params['ph'] ?? []))],
    'solidos' => ['value' => $raw['inv_solidos'], 'status' => $evaluateBands($raw['inv_solidos'], (array)($params['solidos'] ?? []))],
    'extractibilidad' => ['value' => $raw['inv_extrac'], 'status' => $evaluateBands($raw['inv_extrac'], (array)($params['extractibilidad'] ?? []))],
    'rendimiento' => ['value' => $raw['inv_rendimiento'], 'status' => $evaluateBands($raw['inv_rendimiento'], (array)($params['rendimiento'] ?? []))],
  ];

  $overall = ['key' => 'gris', 'label' => 'Pendiente'];
  foreach ($metrics as $metric) {
    $status = (array)$metric['status'];
    $key = (string)($status['key'] ?? 'gris');
    if (($priority[$key] ?? 0) > ($priority[$overall['key']] ?? 0)) {
      $overall = ['key' => $key, 'label' => (string)($status['label'] ?? 'Sin dato')];
    }
  }
  if ($overall['key'] === 'verde') $overall['label'] = 'En objetivo';
  if ($overall['key'] === 'amarillo') $overall['label'] = 'Alerta';
  if ($overall['key'] === 'rojo') $overall['label'] = 'Fuera de rango';

  $rows[] = [
    'numero' => $index + 1,
    'id' => (int)$raw['inv_id'],
    'mat_id' => (int)$raw['mat_id'],
    'prv_id' => (int)$raw['prv_id'],
    'ticket' => (int)$raw['inv_no_ticket'],
    'fecha' => (new DateTimeImmutable((string)$raw['inv_fecha'] . ' 00:00:00', $tz))->format('d/m/Y'),
    'kilos' => (float)$raw['inv_kilos'],
    'material' => (string)$raw['mat_nombre'],
    'proveedor' => (string)$raw['prv_nombre'],
    'observaciones' => trim((string)($raw['inv_observaciones'] ?? '')),
    'metricas' => $metrics,
    'semaforo' => $overall,
  ];
}

/*
 * Segunda sección del formato: cuero entero recibido de Pelambre. El periodo
 * se aplica sobre inv_fe_recibe; la compra original se conserva como cabecera
 * para mostrar proveedor, material y kilos del ticket.
 */
$americanMaterialIds = $wholeLeatherMaterialIds;
$americanPurchases = [];
if ($americanMaterialIds !== []) {
  $purchaseWhere = [
    'i.mat_id IN (' . implode(',', array_fill(0, count($americanMaterialIds), '?')) . ')',
    '(i.inv_enviado IS NULL OR i.inv_enviado <> 3)',
    'EXISTS (
      SELECT 1
      FROM inventario recibido
      WHERE recibido.inv_no_ticket = i.inv_no_ticket
        AND recibido.inv_enviado = 2
        AND recibido.prv_recibe = 126
        AND recibido.inv_fe_recibe >= ?
        AND recibido.inv_fe_recibe < ?
    )',
  ];
  $purchaseParams = array_merge(
    $americanMaterialIds,
    [$periodStart->format('Y-m-d H:i:s'), $periodEnd->format('Y-m-d H:i:s')]
  );
  if ($selectedMaterial !== null) {
    $purchaseWhere[] = 'i.mat_id = ?';
    $purchaseParams[] = $selectedMaterial;
  }
  if ($selectedProvider !== null) {
    $purchaseWhere[] = 'i.prv_id = ?';
    $purchaseParams[] = $selectedProvider;
  }

  $purchaseStmt = $pdo->prepare("
    SELECT MIN(i.inv_id) inv_id, MAX(i.inv_fecha) inv_fecha, i.inv_no_ticket,
           SUM(COALESCE(i.inv_kilos, 0)) inv_kilos, MAX(i.inv_enviado) inv_enviado,
           AVG(i.inv_humedad_origen) inv_humedad_origen,
           GROUP_CONCAT(DISTINCT i.inv_observaciones ORDER BY i.inv_id SEPARATOR ' / ') inv_observaciones,
           MIN(i.mat_id) mat_id,
           GROUP_CONCAT(DISTINCT m.mat_nombre ORDER BY m.mat_nombre SEPARATOR ' / ') mat_nombre,
           MIN(i.prv_id) prv_id,
           GROUP_CONCAT(DISTINCT p.prv_nombre ORDER BY p.prv_nombre SEPARATOR ' / ') prv_nombre
    FROM inventario i
    INNER JOIN materiales m ON m.mat_id = i.mat_id
    INNER JOIN proveedores p ON p.prv_id = i.prv_id
    WHERE " . implode(' AND ', $purchaseWhere) . "
      AND i.inv_fecha IS NOT NULL
    GROUP BY i.inv_no_ticket
    ORDER BY MAX(i.inv_fecha) DESC, i.inv_no_ticket DESC
  ");
  $purchaseStmt->execute($purchaseParams);
  $purchaseRows = $purchaseStmt->fetchAll() ?: [];

  $deliveriesByTicket = [];
  $tickets = array_values(array_unique(array_map('intval', array_column($purchaseRows, 'inv_no_ticket'))));
  if ($tickets !== []) {
    $deliveryStmt = $pdo->prepare("
      SELECT i.inv_id, i.inv_no_ticket, i.inv_fecha, i.inv_fe_recibe,
             i.inv_kilos, i.inv_kg_totales, i.inv_humedad, i.inv_ce, i.inv_ph,
             i.inv_solidos, i.inv_extrac, i.inv_rendimiento
      FROM inventario i
      WHERE i.inv_no_ticket IN (" . implode(',', array_fill(0, count($tickets), '?')) . ")
        AND i.inv_enviado = 2
        AND i.prv_recibe = 126
        AND i.inv_fe_recibe IS NOT NULL
      ORDER BY i.inv_no_ticket, i.inv_fe_recibe, i.inv_id
    ");
    $deliveryStmt->execute($tickets);
    foreach (($deliveryStmt->fetchAll() ?: []) as $delivery) {
      $deliveriesByTicket[(int)$delivery['inv_no_ticket']][] = $delivery;
    }
  }

  $originHumidityRule = [
    'leyenda' => '40–48',
    'bandas' => [
      ['min' => 40, 'max' => 48, 'estado' => 'verde'],
      ['max' => 39.999999, 'estado' => 'rojo'],
      ['min' => 48.000001, 'estado' => 'rojo'],
    ],
  ];
  $average = static function (array $items, string $field): ?float {
    $values = [];
    foreach ($items as $item) {
      $value = $item[$field] ?? null;
      if ($value !== null && $value !== '' && is_numeric($value)) $values[] = (float)$value;
    }
    return $values === [] ? null : array_sum($values) / count($values);
  };

  foreach ($purchaseRows as $purchase) {
    $ticket = (int)$purchase['inv_no_ticket'];
    $deliveryRows = (array)($deliveriesByTicket[$ticket] ?? []);
    $deliveryKilos = 0.0;
    $deliveryDetails = [];
    foreach ($deliveryRows as $delivery) {
      $deliveryKilos += is_numeric($delivery['inv_kg_totales'] ?? null) ? (float)$delivery['inv_kg_totales'] : 0.0;
      $deliveryDetails[] = [
        'fecha' => (new DateTimeImmutable((string)$delivery['inv_fe_recibe'], $tz))->format('d/m/Y H:i'),
        'kilos' => $delivery['inv_kg_totales'],
        'humedad' => $delivery['inv_humedad'],
        'conductividad' => $delivery['inv_ce'],
        'ph' => $delivery['inv_ph'],
        'solidos' => $delivery['inv_solidos'],
        'extractibilidad' => $delivery['inv_extrac'],
        'rendimiento' => $delivery['inv_rendimiento'],
      ];
    }
    // Total comprado: suma de inv_kilos de los registros con inv_fecha, agrupados por ticket.
    // inv_fe_recibe sólo determina qué recepciones de Pelambre entran en el periodo.
    $purchaseKilos = (float)$purchase['inv_kilos'];
    $averageValues = [
      'humedad' => $average($deliveryRows, 'inv_humedad'),
      'conductividad' => $average($deliveryRows, 'inv_ce'),
      'ph' => $average($deliveryRows, 'inv_ph'),
      'solidos' => $average($deliveryRows, 'inv_solidos'),
      'extractibilidad' => $average($deliveryRows, 'inv_extrac'),
      'rendimiento' => $average($deliveryRows, 'inv_rendimiento'),
    ];
    $averageConductivityRule = $conductivityRule((int)$purchase['mat_id'], (string)$purchase['mat_nombre']);
    $averages = [
      'humedad' => ['value' => $averageValues['humedad'], 'status' => $evaluateBands($averageValues['humedad'], (array)($params['humedad'] ?? []))],
      'conductividad' => ['value' => $averageValues['conductividad'], 'status' => $averageConductivityRule === [] ? ['key' => 'gris', 'label' => 'Sin rango', 'range' => 'Según material'] : $evaluateBands($averageValues['conductividad'], $averageConductivityRule)],
      'ph' => ['value' => $averageValues['ph'], 'status' => $evaluateBands($averageValues['ph'], (array)($params['ph'] ?? []))],
      'solidos' => ['value' => $averageValues['solidos'], 'status' => $evaluateBands($averageValues['solidos'], (array)($params['solidos'] ?? []))],
      'extractibilidad' => ['value' => $averageValues['extractibilidad'], 'status' => $evaluateBands($averageValues['extractibilidad'], (array)($params['extractibilidad'] ?? []))],
      'rendimiento' => ['value' => $averageValues['rendimiento'], 'status' => $evaluateBands($averageValues['rendimiento'], (array)($params['rendimiento'] ?? []))],
    ];
    $periodDeliveryRows = array_values(array_filter($deliveryRows, static function (array $delivery) use ($periodStart, $periodEnd, $tz): bool {
      if (empty($delivery['inv_fe_recibe'])) return false;
      $receivedAt = new DateTimeImmutable((string)$delivery['inv_fe_recibe'], $tz);
      return $receivedAt >= $periodStart && $receivedAt < $periodEnd;
    }));
    $latestReceipt = $periodDeliveryRows === []
      ? null
      : new DateTimeImmutable((string)$periodDeliveryRows[count($periodDeliveryRows) - 1]['inv_fe_recibe'], $tz);
    $americanPurchases[] = [
      'fecha' => $latestReceipt instanceof DateTimeImmutable ? $latestReceipt->format('d/m/Y') : '—',
      'fecha_orden' => $latestReceipt instanceof DateTimeImmutable ? $latestReceipt->format('Y-m-d H:i:s') : '',
      'ticket' => $ticket,
      'proveedor' => (string)$purchase['prv_nombre'],
      'material' => (string)$purchase['mat_nombre'],
      'grupo' => 'cuero_entero',
      'kilos_compra' => $purchaseKilos,
      'humedad_origen' => [
        'value' => $purchase['inv_humedad_origen'],
        'status' => $evaluateBands($purchase['inv_humedad_origen'], $originHumidityRule),
      ],
      'numero_entregas' => count($deliveryDetails),
      'kilos_granja' => $deliveryRows === [] ? null : $deliveryKilos,
      'rendimiento_granja' => $deliveryRows !== [] && $purchaseKilos > 0
        ? (($deliveryKilos / $purchaseKilos) - 1) * 100
        : null,
      'promedios' => $averages,
      'observaciones' => trim((string)($purchase['inv_observaciones'] ?? '')),
      'entregas' => $deliveryDetails,
    ];
  }
  usort($americanPurchases, static function (array $left, array $right): int {
    return strcmp((string)($right['fecha_orden'] ?? ''), (string)($left['fecha_orden'] ?? ''));
  });
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Inventario de Materia Prima'),
  'subtitulo' => (string)($config['subtitulo'] ?? ''),
  'filtros' => [
    'anio' => $selectedYear,
    'mes' => $selectedMonth,
    'periodo' => $periodMode,
    'fecha' => $selectedStartDate->format('Y-m-d'),
    'fecha_inicio' => $selectedStartDate->format('Y-m-d'),
    'fecha_fin' => $selectedEndDate->format('Y-m-d'),
    'semana' => $selectedWeekValue,
    'semana_inicio' => $selectedWeekValue,
    'semana_fin' => $selectedWeekEndValue,
    'hoy' => $today->format('Y-m-d'),
    'anios' => $yearOptions,
    'meses' => $monthNames,
    'material' => $selectedMaterial,
    'proveedor' => $selectedProvider,
  ],
  'opciones' => ['materiales' => $materialOptions, 'proveedores' => $providerOptions],
  'filas' => $rows,
  'compras_cuero_americano' => $americanPurchases,
  'criterios' => $params,
  'meta' => [
    'periodo_label' => $periodMode === 'fecha'
      ? ($selectedStartDate->format('Y-m-d') === $selectedEndDate->format('Y-m-d')
        ? $selectedStartDate->format('d/m/Y')
        : $selectedStartDate->format('d/m/Y') . ' al ' . $selectedEndDate->format('d/m/Y'))
      : ($periodMode === 'semana'
        ? ($selectedWeekStart->format('o-\WW') === $selectedWeekEnd->format('o-\WW')
          ? 'Semana ' . $periodStart->format('W')
          : 'Semanas ' . $periodStart->format('W') . ' a ' . $selectedWeekEnd->format('W')) . ' · ' . $periodStart->format('d/m/Y') . ' al ' . $periodEnd->modify('-1 day')->format('d/m/Y')
        : ucfirst($monthNames[$selectedMonth]) . ' ' . $selectedYear),
    'ultima_fecha_disponible' => $latestDate,
    'generado_en' => (new DateTimeImmutable('now', $tz))->format('d/m/Y H:i'),
  ],
  'version' => max((int)(@filemtime(__FILE__) ?: time()), (int)(@filemtime(__DIR__ . '/index.php') ?: time())),
];
