<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$secadoresConfig = require __DIR__ . '/../secadores/config.php';
$secadoresConfig = array_replace_recursive($secadoresConfig, [
  'votators_por_tunel' => (array)($config['secadores']['votator_campos_overlay'] ?? []),
]);
$secadoresReport = (static function () use ($secadoresConfig): array {
  $config = $secadoresConfig;
  return require __DIR__ . '/../secadores/build_report.php';
})();
$secadoresSummaryConfig = array_replace_recursive(
  (array)($secadoresConfig['monitoreo_produccion'] ?? []),
  (array)($config['secadores'] ?? [])
);
$sqlServerAvevaConfig = (array)($config['sqlserver_aveva'] ?? []);
$sqlServerAvevaConnection = (array)($sqlServerAvevaConfig['conexion'] ?? ($secadoresConfig['sqlserver'] ?? []));
$sqlServerAvevaTable = (string)($sqlServerAvevaConfig['tabla'] ?? ($secadoresConfig['tabla'] ?? 'TREND001'));
$sqlServerAvevaTimestamp = (string)($sqlServerAvevaConfig['campo_fecha'] ?? ($secadoresConfig['campo_fecha'] ?? 'Time_Stamp'));

$concentradoresConfig = require __DIR__ . '/../concentradores/config.php';
$concentradoresReport = (static function (): array {
  $config = require __DIR__ . '/../concentradores/config.php';
  return require __DIR__ . '/../concentradores/build_report.php';
})();

$quoteSqlServerIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador SQL Server invalido: ' . $name);
  }

  return '[' . $name . ']';
};

$connectSqlServer = static function (array $cfg): PDO {
  $server = trim((string)($cfg['server'] ?? ''));
  $database = trim((string)($cfg['database'] ?? ''));
  $port = (int)($cfg['port'] ?? 1433);
  $encrypt = !empty($cfg['encrypt']) ? 'yes' : 'no';
  $trust = !empty($cfg['trust_server_certificate']) ? 'yes' : 'no';
  $timeout = (int)($cfg['login_timeout'] ?? 5);
  $serverPart = $port > 0 ? $server . ',' . $port : $server;
  $dsn = "sqlsrv:Server={$serverPart};Database={$database};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout={$timeout}";

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$connectMysql = static function (array $cfg): PDO {
  $host = trim((string)($cfg['host'] ?? ''));
  $port = (int)($cfg['port'] ?? 3306);
  $dbname = trim((string)($cfg['dbname'] ?? ''));
  $charset = trim((string)($cfg['charset'] ?? 'utf8mb4'));
  $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => (int)($cfg['timeout'] ?? 3),
  ]);
};

$quoteMysqlIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador MySQL invalido: ' . $name);
  }

  return '`' . $name . '`';
};

$isOutOfOperation = static function ($value): bool {
  if ($value === null) {
    return false;
  }

  $normalized = mb_strtolower(trim((string)$value), 'UTF-8');
  if ($normalized === '') {
    return false;
  }

  return in_array($normalized, ['1', 'true', 'si', 'sí', 's', 'yes', 'y', 'fo', 'fuera', 'fuera de operacion', 'fuera de operación'], true);
};

$historyLimit = max(300, (int)($config['tendencia_limite_registros'] ?? 5000));
$sqlServerHistoryLimit = max(1440, (int)($config['tendencia_limite_sqlserver'] ?? 5000));

$normalizeTimestamp = static function ($value): ?DateTimeImmutable {
  if ($value instanceof DateTimeInterface) {
    try {
      return new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone() ?: null);
    } catch (Throwable $e) {
      return null;
    }
  }

  if (is_string($value) && trim($value) !== '') {
    try {
      return new DateTimeImmutable($value);
    } catch (Throwable $e) {
      return null;
    }
  }

  return null;
};

$formatHistoryTimestamp = static function ($value) use ($normalizeTimestamp): string {
  $timestamp = $normalizeTimestamp($value);
  return $timestamp !== null ? $timestamp->format('d/m H:i') : (is_scalar($value) ? (string)$value : '-');
};

$formatHistoryIsoTimestamp = static function ($value) use ($normalizeTimestamp): string {
  $timestamp = $normalizeTimestamp($value);
  return $timestamp !== null ? $timestamp->format(DateTimeInterface::ATOM) : '';
};

$aggregateHistoryPoints = static function (array $history, string $range) use ($normalizeTimestamp, $formatHistoryTimestamp, $formatHistoryIsoTimestamp): array {
  $buckets = [];
  $now = new DateTimeImmutable('now');
  $cutoff = $range === 'month'
    ? $now->modify('first day of this month')->setTime(0, 0, 0)
    : $now->modify('-7 days');

  foreach ($history as $point) {
    $value = $point['value'] ?? null;
    if (!is_numeric($value)) {
      continue;
    }

    $timestamp = $normalizeTimestamp($point['iso'] ?? ($point['timestamp'] ?? null));
    if ($timestamp === null || $timestamp < $cutoff) {
      continue;
    }

    if ($range === 'month') {
      $day = (int)$timestamp->format('N');
      $bucketTimestamp = $timestamp->modify('-' . ($day - 1) . ' days')->setTime(0, 0, 0);
    } else {
      $bucketTimestamp = $timestamp->setTime(0, 0, 0);
    }

    $bucketKey = $bucketTimestamp->format('Y-m-d H:i:s');
    if (!isset($buckets[$bucketKey])) {
      $buckets[$bucketKey] = ['timestamp' => $bucketTimestamp, 'sum' => 0.0, 'count' => 0];
    }
    $buckets[$bucketKey]['sum'] += (float)$value;
    $buckets[$bucketKey]['count']++;
  }

  ksort($buckets);
  $aggregated = [];
  foreach ($buckets as $bucket) {
    $average = $bucket['count'] > 0 ? $bucket['sum'] / $bucket['count'] : null;
    $aggregated[] = [
      'timestamp' => $formatHistoryTimestamp($bucket['timestamp']),
      'iso' => $formatHistoryIsoTimestamp($bucket['timestamp']),
      'value' => $average,
      'formatted' => $average !== null ? n($average, 2) : '-',
    ];
  }

  return $aggregated;
};

$fetchLatestMysqlRow = static function (array $sourceConfig, string $defaultTable) use ($connectMysql, $quoteMysqlIdentifier): array {
  try {
    $pdoMysql = $connectMysql((array)($sourceConfig['mysql_105'] ?? []));
    $safeTable = $quoteMysqlIdentifier((string)($sourceConfig['tabla_datos'] ?? $defaultTable));
    $columnsRows = $pdoMysql->query('SHOW COLUMNS FROM ' . $safeTable)->fetchAll() ?: [];
    $columns = array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $columnsRows);
    $hasDatosHora = in_array('id_datos_hora', $columns, true);
    $todayJoinSql = $hasDatosHora ? ' INNER JOIN `datos_hora` dh ON dh.`id` = d.`id_datos_hora`' : '';
    $todayWhereSql = $hasDatosHora ? ' WHERE DATE(COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`)) = CURDATE()' : '';
    $orderColumns = (array)($sourceConfig['columnas_orden'] ?? []);
    $orderParts = [];
    foreach ($orderColumns as $orderColumn) {
      $orderColumn = (string)$orderColumn;
      if ($orderColumn !== '') {
        $orderParts[] = 'd.' . $quoteMysqlIdentifier($orderColumn) . ' DESC';
      }
    }
    $orderSql = $orderParts !== [] ? ' ORDER BY ' . implode(', ', $orderParts) : '';

    return [$pdoMysql->query('SELECT d.* FROM ' . $safeTable . ' d' . $todayJoinSql . $todayWhereSql . $orderSql . ' LIMIT 1')->fetch() ?: [], ''];
  } catch (Throwable $e) {
    return [[], 'No se pudieron leer ' . $defaultTable . ' desde 105: ' . $e->getMessage()];
  }
};

$fetchMysqlTrendRows = static function (array $sourceConfig, string $defaultTable, string $extraWhereSql = '', array $params = []) use ($connectMysql, $quoteMysqlIdentifier, $historyLimit): array {
  try {
    $pdoMysql = $connectMysql((array)($sourceConfig['mysql_105'] ?? []));
    $safeTable = $quoteMysqlIdentifier((string)($sourceConfig['tabla_datos'] ?? $defaultTable));
    $columnsRows = $pdoMysql->query('SHOW COLUMNS FROM ' . $safeTable)->fetchAll() ?: [];
    $columns = array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $columnsRows);
    $hasDatosHora = in_array('id_datos_hora', $columns, true);
    $joinSql = $hasDatosHora ? ' INNER JOIN `datos_hora` dh ON dh.`id` = d.`id_datos_hora`' : '';
    $whereParts = [];
    if ($hasDatosHora) {
      $whereParts[] = 'COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) >= DATE_SUB(NOW(), INTERVAL 31 DAY)';
    }
    if (trim($extraWhereSql) !== '') {
      $whereParts[] = '(' . $extraWhereSql . ')';
    }
    $whereSql = $whereParts !== [] ? ' WHERE ' . implode(' AND ', $whereParts) : '';
    $timestampSql = $hasDatosHora ? ', COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) AS __history_timestamp' : '';
    $orderColumns = (array)($sourceConfig['columnas_orden'] ?? []);
    $orderParts = [];
    foreach ($orderColumns as $orderColumn) {
      $orderColumn = (string)$orderColumn;
      if ($orderColumn !== '') {
        $orderParts[] = 'd.' . $quoteMysqlIdentifier($orderColumn) . ' DESC';
      }
    }
    $orderSql = $orderParts !== [] ? ' ORDER BY ' . implode(', ', $orderParts) : '';
    $stmt = $pdoMysql->prepare('SELECT d.*' . $timestampSql . ' FROM ' . $safeTable . ' d' . $joinSql . $whereSql . $orderSql . ' LIMIT ' . $historyLimit);
    $stmt->execute($params);
    return [$stmt->fetchAll() ?: [], ''];
  } catch (Throwable $e) {
    return [[], 'No se pudo leer histórico de ' . $defaultTable . ' desde 105: ' . $e->getMessage()];
  }
};

$resolverValorBitacora = static function (string $field, array $row, array $historyRows) {
  $value = $field !== '' && array_key_exists($field, $row) ? $row[$field] : null;
  $needsFallback = $value === null || (is_numeric($value) && (float)$value === 0.0);

  if (!$needsFallback) {
    return $value;
  }

  foreach ($historyRows as $historyRow) {
    $historyValue = $field !== '' && array_key_exists($field, (array)$historyRow) ? $historyRow[$field] : null;
    if ($historyValue === null || (is_numeric($historyValue) && (float)$historyValue === 0.0)) {
      continue;
    }

    return $historyValue;
  }

  return $value;
};

$statusClass = static function (string $statusKey): string {
  return [
    'verde' => 'ok',
    'amarillo' => 'warning',
    'rojo' => 'danger',
    'azul' => 'neutral',
    'gris' => 'unavailable',
  ][$statusKey] ?? 'unavailable';
};

$formatMetricValue = static function (array $metric): string {
  $value = (string)($metric['formatted'] ?? '');
  $unit = trim((string)($metric['unit'] ?? ''));
  if ($value === '') {
    return (string)($metric['emptyLabel'] ?? 'Sin dato');
  }

  return $unit !== '' && $value !== (string)($metric['emptyLabel'] ?? 'Sin dato')
    ? trim($value . ' ' . $unit)
    : $value;
};

$makeItem = static function (string $key, string $label, string $value, string $statusKey, string $statusLabel, string $statusColor, string $rangeLabel = '', string $source = '', array $rule = [], array $history = [], string $unit = '', array $trends = []) use ($statusClass): array {
  return [
    'key' => $key,
    'label' => $label,
    'value' => $value,
    'rangeLabel' => $rangeLabel,
    'source' => $source,
    'rule' => $rule,
    'history' => $history,
    'trends' => $trends,
    'unit' => $unit,
    'statusKey' => $statusKey,
    'statusLabel' => $statusLabel,
    'statusColor' => $statusColor,
    'class' => $statusClass($statusKey),
  ];
};

$buildRangeLabel = static function (array $field): string {
  $clean = static function (string $label): string {
    $label = trim($label);
    $label = preg_replace('/^óptimo\s*:?\s*/iu', '', $label) ?? $label;
    $label = preg_replace('/^rango\s+óptimo\s*:?\s*/iu', '', $label) ?? $label;
    return trim($label) !== '' ? trim($label) : 'Por definir';
  };

  $fromText = static function (string $label) use ($clean): string {
    $label = trim($label);
    if ($label === '') {
      return '';
    }

    if (preg_match('/verde\s*(?:>=|≥)\s*([0-9.,]+)(?:\s*([^|]+))?/iu', $label, $matches) === 1) {
      return '≥ ' . (string)$matches[1] . trim(' ' . (string)($matches[2] ?? ''));
    }

    if (preg_match('/verde\s*(?:<=|≤)\s*([0-9.,]+)(?:\s*([^|]+))?/iu', $label, $matches) === 1) {
      return '≤ ' . (string)$matches[1] . trim(' ' . (string)($matches[2] ?? ''));
    }

    if (preg_match('/verde\s*([0-9.,]+)\s*(?:a|-)\s*([0-9.,]+)(?:\s*([^|]+))?/iu', $label, $matches) === 1) {
      return (string)$matches[1] . '-' . (string)$matches[2] . trim(' ' . (string)($matches[3] ?? ''));
    }

    return $clean($label);
  };

  $explicit = trim((string)($field['optimo_label'] ?? ''));
  if ($explicit !== '') {
    return $clean($explicit);
  }

  $rule = (array)($field['rule'] ?? []);
  $unit = trim((string)($field['unit'] ?? ''));
  $suffix = $unit !== '' ? ' ' . $unit : '';
  $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;

  if ($greenMin !== null && $greenMax !== null) {
    return rtrim(rtrim(n($greenMin, 2), '0'), '.') . '-' . rtrim(rtrim(n($greenMax, 2), '0'), '.') . $suffix;
  }

  if ($greenMin !== null) {
    return '≥ ' . rtrim(rtrim(n($greenMin, 2), '0'), '.') . $suffix;
  }

  if ($greenMax !== null) {
    return '≤ ' . rtrim(rtrim(n($greenMax, 2), '0'), '.') . $suffix;
  }

  $rangeText = trim((string)($field['rangeLabel'] ?? ''));
  if ($rangeText !== '') {
    return $fromText($rangeText);
  }

  return 'Por definir';
};

$compactLabel = static function (string $label): string {
  if (preg_match('/rec[aá]mara\s+(\d+)/iu', $label, $matches) === 1) {
    return 'Temp R' . (string)$matches[1];
  }

  return $label;
};

$compactMetricLabel = static function (array $metric): string {
  $label = (string)($metric['label'] ?? $metric['key'] ?? '');
  $group = mb_strtolower((string)($metric['group'] ?? ''), 'UTF-8');

  if ($group === 'humedades') {
    if (preg_match('/(?:zona|rec[aá]mara)\s+(\d+)/iu', $label, $matches) === 1) {
      return 'Hum R' . (string)$matches[1];
    }

    if (mb_stripos($label, 'aire', 0, 'UTF-8') !== false) {
      return 'Hum Aire';
    }
  }

  return $label;
};

$worstStatus = static function (array $items): array {
  $severity = [
    'rojo' => 4,
    'amarillo' => 3,
    'gris' => 2,
    'azul' => 1,
    'verde' => 0,
  ];
  $worst = ['statusKey' => 'gris', 'statusLabel' => 'Sin dato', 'statusColor' => '#94a3b8'];
  $worstRank = -1;

  foreach ($items as $item) {
    $key = (string)($item['statusKey'] ?? 'gris');
    $rank = $severity[$key] ?? 2;
    if ($rank > $worstRank) {
      $worstRank = $rank;
      $worst = [
        'statusKey' => $key,
        'statusLabel' => (string)($item['statusLabel'] ?? 'Sin dato'),
        'statusColor' => (string)($item['statusColor'] ?? '#94a3b8'),
      ];
    }
  }

  return $worst;
};

$evaluateThresholdRange = static function (?float $value, array $rule): array {
  if ($value === null) {
    return ['Sin dato', 'gris', '#94a3b8'];
  }

  $greenLt = isset($rule['verde_lt']) && is_numeric($rule['verde_lt']) ? (float)$rule['verde_lt'] : null;
  $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  if ($greenLt !== null && $value < $greenLt) {
    return ['Verde', 'verde', '#2e8b57'];
  }

  if (($yellowMin === null || $value >= $yellowMin) && ($yellowMax === null || $value <= $yellowMax)) {
    return ['Amarillo', 'amarillo', '#facc15'];
  }

  return ['Rojo', 'rojo', '#c94436'];
};

$evaluateMetricRange = static function (?float $value, array $rule): array {
  if ($value === null) {
    return ['Sin dato', 'gris', '#94a3b8'];
  }

  $mode = (string)($rule['modo'] ?? 'rango');
  $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
  $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  if ($mode === 'bandas') {
    $statusMap = [
      'verde' => ['Verde', 'verde', '#2e8b57'],
      'amarillo' => ['Amarillo', 'amarillo', '#facc15'],
      'rojo' => ['Rojo', 'rojo', '#c94436'],
      'gris' => ['Sin dato', 'gris', '#94a3b8'],
      'azul' => ['Lectura', 'azul', '#0ea5e9'],
    ];

    foreach ((array)($rule['bandas'] ?? []) as $band) {
      $min = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
      $max = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
      $status = (string)($band['estado'] ?? 'gris');

      if (($min === null || $value >= $min) && ($max === null || $value <= $max)) {
        return $statusMap[$status] ?? $statusMap['gris'];
      }
    }

    return ['Rojo', 'rojo', '#c94436'];
  }

  if ($mode === 'minimo') {
    if ($greenMin !== null && $value >= $greenMin) {
      return ['Verde', 'verde', '#2e8b57'];
    }
    if ($yellowMin !== null && $value >= $yellowMin) {
      return ['Amarillo', 'amarillo', '#facc15'];
    }
    return ['Rojo', 'rojo', '#c94436'];
  }

  if ($mode === 'maximo') {
    if ($greenMax !== null && $value <= $greenMax) {
      return ['Verde', 'verde', '#2e8b57'];
    }
    if ($yellowMax !== null && $value <= $yellowMax) {
      return ['Amarillo', 'amarillo', '#facc15'];
    }
    return ['Rojo', 'rojo', '#c94436'];
  }

  $inGreen = ($greenMin === null || $value >= $greenMin) && ($greenMax === null || $value <= $greenMax);
  if ($inGreen) {
    return ['Verde', 'verde', '#2e8b57'];
  }

  $inYellow = ($yellowMin === null || $value >= $yellowMin) && ($yellowMax === null || $value <= $yellowMax);
  if ($yellowMin !== null || $yellowMax !== null) {
    if ($inYellow) {
      return ['Amarillo', 'amarillo', '#facc15'];
    }
  }

  return ['Rojo', 'rojo', '#c94436'];
};

$roomNumberFromMetric = static function (array $metric): ?int {
  $label = (string)($metric['label'] ?? '');
  if (preg_match('/(?:zona|rec[aá]mara)\s+(\d+)/iu', $label, $matches) === 1) {
    return (int)$matches[1];
  }

  return null;
};

$buildTunnelSummary = static function (array $tunnel, array $summaryConfig) use ($formatMetricValue, $makeItem, $compactLabel, $compactMetricLabel, $buildRangeLabel, $evaluateThresholdRange, $roomNumberFromMetric): array {
  $items = [];
  $tunnelKey = (string)($tunnel['key'] ?? '');
  $temperatureLimit = max(0, (int)($summaryConfig['temperaturas_limite'] ?? 0));
  $temperatureCells = (array)($tunnel['cells'] ?? []);
  $humidityRangeTunnels = array_flip((array)($summaryConfig['humedad_rangos_tuneles'] ?? []));
  $humidityRanges = (array)($summaryConfig['humedad_rangos_recamaras'] ?? []);
  if ($temperatureLimit > 0) {
    $temperatureCells = array_slice($temperatureCells, 0, $temperatureLimit);
  }

  foreach ((array)($summaryConfig['metricas'] ?? []) as $metricKey) {
    $metric = (array)(($tunnel['metricas'] ?? [])[$metricKey] ?? []);
    if ($metric === []) {
      continue;
    }
    $statusLabel = (string)($metric['statusLabel'] ?? 'Sin dato');
    $statusKey = (string)($metric['statusKey'] ?? 'gris');
    $statusColor = (string)($metric['statusColor'] ?? '#94a3b8');
    $rangeLabel = $buildRangeLabel($metric);

    if (
      isset($humidityRangeTunnels[$tunnelKey])
      && mb_strtolower((string)($metric['group'] ?? ''), 'UTF-8') === 'humedades'
    ) {
      $roomNumber = $roomNumberFromMetric($metric);
      $roomRule = $roomNumber !== null ? (array)($humidityRanges[$roomNumber] ?? []) : [];
      if ($roomRule !== []) {
        $numericValue = isset($metric['value']) && is_numeric($metric['value'])
          ? (float)$metric['value']
          : (is_numeric($metric['formatted'] ?? null) ? (float)$metric['formatted'] : null);
        [$statusLabel, $statusKey, $statusColor] = $evaluateThresholdRange($numericValue, $roomRule);
        $rangeLabel = (string)($roomRule['label'] ?? $rangeLabel);
      }
    }

    $items[] = $makeItem(
      (string)$metricKey,
      $compactMetricLabel($metric + ['key' => $metricKey]),
      $formatMetricValue($metric),
      $statusKey,
      $statusLabel,
      $statusColor,
      $rangeLabel,
      (string)($metric['source'] ?? 'sqlserver'),
      (array)($metric['rule'] ?? []),
      (array)($metric['history'] ?? []),
      (string)($metric['unit'] ?? ''),
      (array)($metric['trends'] ?? [])
    );
  }

  foreach ($temperatureCells as $cell) {
    $value = (string)($cell['formatted'] ?? '-');
    if ($value !== '-' && $value !== '') {
      $value .= ' C';
    }

    $items[] = $makeItem(
      'temp_' . (string)($cell['field'] ?? count($items)),
      $compactLabel((string)($cell['label'] ?? 'Temperatura')),
      $value !== '' ? $value : '-',
      (string)($cell['statusKey'] ?? 'gris'),
      (string)($cell['statusLabel'] ?? 'Sin dato'),
      (string)($cell['statusColor'] ?? '#94a3b8'),
      $buildRangeLabel($cell),
      'sqlserver',
      (array)($cell['rule'] ?? []),
      (array)($cell['history'] ?? []),
      'C',
      (array)($cell['trends'] ?? [])
    );
  }

  return [
    'key' => (string)($tunnel['key'] ?? ''),
    'titulo' => (string)($tunnel['titulo'] ?? 'Tunel'),
    'statusLabel' => (string)($tunnel['statusLabel'] ?? 'Sin dato'),
    'statusKey' => (string)($tunnel['statusKey'] ?? 'gris'),
    'statusColor' => (string)($tunnel['statusColor'] ?? '#94a3b8'),
    'ultimaLectura' => (string)($tunnel['ultimaLectura'] ?? '-'),
    'items' => $items,
  ];
};

$buildVotatorMysqlItems = static function (array $mysqlConfig) use ($fetchLatestMysqlRow, $fetchMysqlTrendRows, $resolverValorBitacora, $formatHistoryTimestamp, $formatHistoryIsoTimestamp, $aggregateHistoryPoints, $isOutOfOperation, $makeItem, $buildRangeLabel, $evaluateMetricRange): array {
  $fieldsConfig = (array)($mysqlConfig['campos'] ?? []);
  if ($fieldsConfig === []) {
    return [[], ''];
  }

  [$row, $warning] = $fetchLatestMysqlRow($mysqlConfig, (string)($mysqlConfig['tabla_datos'] ?? 'datos_producto'));
  [$historyRows, $historyWarning] = $fetchMysqlTrendRows($mysqlConfig, (string)($mysqlConfig['tabla_datos'] ?? 'datos_producto'));
  $warning = trim($warning . ' ' . $historyWarning);
  $fueraOperacion = $isOutOfOperation($row[(string)($mysqlConfig['columna_fo'] ?? '')] ?? null);
  $items = [];

  foreach ($fieldsConfig as $fieldKey => $field) {
    $fieldKey = (string)$fieldKey;
    $sourceField = (string)($field['field'] ?? '');
    $rawValue = $resolverValorBitacora($sourceField, $row, $historyRows);
    $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
    $unit = trim((string)($field['unit'] ?? ''));
    $emptyLabel = (string)($field['empty_label'] ?? 'Sin dato');
    $formatted = $numericValue !== null ? n($numericValue, 2) : '-';
    $rule = (array)($field['semaforo'] ?? []);
    [$statusLabel, $statusKey, $statusColor] = $rule !== []
      ? $evaluateMetricRange($numericValue, $rule)
      : [
        $numericValue !== null ? 'Lectura' : 'Sin dato',
        $numericValue !== null ? 'azul' : 'gris',
        $numericValue !== null ? '#0ea5e9' : '#94a3b8',
      ];
    $history = [];
    foreach ($historyRows as $historyRow) {
      $historyValue = $sourceField !== '' ? ($historyRow[$sourceField] ?? null) : null;
      $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
      $timestamp = $historyRow['__history_timestamp'] ?? null;
      $history[] = [
        'timestamp' => $formatHistoryTimestamp($timestamp),
        'iso' => $formatHistoryIsoTimestamp($timestamp),
        'value' => $historyNumericValue,
        'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
      ];
    }

    $items[$fieldKey] = $makeItem(
      $fieldKey,
      (string)($field['label'] ?? $fieldKey),
      $fueraOperacion ? 'FO' : ($unit !== '' && $formatted !== '-' && $formatted !== $emptyLabel ? trim($formatted . ' ' . $unit) : $formatted),
      $fueraOperacion ? 'gris' : $statusKey,
      $fueraOperacion ? 'Fuera de operacion' : $statusLabel,
      $fueraOperacion ? '#94a3b8' : $statusColor,
      $buildRangeLabel((array)$field),
      'mysql_105',
      (array)($field['semaforo'] ?? []),
      $fueraOperacion ? [] : $history,
      $unit,
      [
        'week' => $fueraOperacion ? [] : $aggregateHistoryPoints($history, 'week'),
        'month' => $fueraOperacion ? [] : $aggregateHistoryPoints($history, 'month'),
      ]
    );
  }

  return [$items, $warning];
};

$buildVotatorSummary = static function (array $reportTuneles, array $summaryConfig, array $mysqlItems = []) use ($makeItem, $worstStatus, $buildRangeLabel): array {
  $votators = [];
  $allowedFields = array_flip((array)($summaryConfig['votator_campos'] ?? []));
  $extraFields = (array)($summaryConfig['votator_campos_extra'] ?? []);
  $buildPlaceholderItems = static function () use ($allowedFields, $extraFields, $mysqlItems, $makeItem, $buildRangeLabel): array {
    $items = [];
    foreach ($mysqlItems as $fieldKey => $item) {
      $fieldKey = (string)$fieldKey;
      if ($allowedFields !== [] && !isset($allowedFields[$fieldKey])) {
        continue;
      }

      $items[] = $item;
    }

    foreach ($extraFields as $fieldKey => $field) {
      $fieldKey = (string)$fieldKey;
      if ($allowedFields !== [] && !isset($allowedFields[$fieldKey])) {
        continue;
      }
      if (isset($mysqlItems[$fieldKey])) {
        continue;
      }

      $items[] = $makeItem(
        $fieldKey,
        (string)($field['label'] ?? $fieldKey),
        '-',
        'gris',
        'Sin dato',
        '#94a3b8',
        $buildRangeLabel((array)$field)
      );
    }

    return $items;
  };

  foreach ($reportTuneles as $tunnel) {
    foreach ((array)($tunnel['votators'] ?? []) as $votator) {
      $items = [];
      $seenFields = [];

      foreach ((array)($votator['fields'] ?? []) as $field) {
        $fieldKey = (string)($field['key'] ?? '');
        if ($allowedFields !== [] && !isset($allowedFields[$fieldKey])) {
          continue;
        }
        $seenFields[$fieldKey] = true;

        $unit = trim((string)($field['unit'] ?? ''));
        $emptyLabel = (string)($field['emptyLabel'] ?? 'Sin dato');
        $value = (string)($field['formatted'] ?? $emptyLabel);

        $items[] = $makeItem(
          $fieldKey,
          (string)($field['label'] ?? $fieldKey),
          $unit !== '' && $value !== $emptyLabel ? trim($value . ' ' . $unit) : $value,
          (string)($field['statusKey'] ?? 'gris'),
          (string)($field['statusLabel'] ?? 'Sin dato'),
          (string)($field['statusColor'] ?? '#94a3b8'),
          $buildRangeLabel($field),
          (string)($field['source'] ?? 'sqlserver'),
        (array)($field['rule'] ?? []),
        (array)($field['history'] ?? []),
        (string)($field['unit'] ?? ''),
        (array)($field['trends'] ?? [])
        );
      }

      foreach ($mysqlItems as $fieldKey => $item) {
        $fieldKey = (string)$fieldKey;
        if (isset($seenFields[$fieldKey])) {
          continue;
        }
        if ($allowedFields !== [] && !isset($allowedFields[$fieldKey])) {
          continue;
        }

        $seenFields[$fieldKey] = true;
        $items[] = $item;
      }

      foreach ($extraFields as $fieldKey => $field) {
        $fieldKey = (string)$fieldKey;
        if (isset($seenFields[$fieldKey])) {
          continue;
        }
        if ($allowedFields !== [] && !isset($allowedFields[$fieldKey])) {
          continue;
        }

        $items[] = $makeItem(
          $fieldKey,
          (string)($field['label'] ?? $fieldKey),
          '-',
          'gris',
          'Sin dato',
          '#94a3b8',
          $buildRangeLabel((array)$field)
        );
      }

      $status = $worstStatus($items);
      $votators[(string)($votator['key'] ?? 'votator_' . count($votators))] = [
        'key' => (string)($votator['key'] ?? ''),
        'titulo' => (string)($votator['label'] ?? 'Votator'),
        'statusLabel' => $status['statusLabel'],
        'statusKey' => $status['statusKey'],
        'statusColor' => $status['statusColor'],
        'ultimaLectura' => (string)($tunnel['ultimaLectura'] ?? '-'),
        'items' => $items,
      ];
    }
  }

  foreach ((array)($summaryConfig['votators_placeholder'] ?? []) as $votatorKey => $votatorTitle) {
    $votatorKey = (string)$votatorKey;
    if (isset($votators[$votatorKey])) {
      continue;
    }

    $items = $buildPlaceholderItems();
    $status = $worstStatus($items);
    $votators[$votatorKey] = [
      'key' => $votatorKey,
      'titulo' => (string)$votatorTitle,
      'statusLabel' => $status['statusLabel'],
      'statusKey' => $status['statusKey'],
      'statusColor' => $status['statusColor'],
      'ultimaLectura' => '-',
      'items' => $items,
    ];
  }

  return $votators;
};

$buildConcentradorSummary = static function (array $report, array $concentradoresConfig) use ($makeItem, $worstStatus, $buildRangeLabel): array {
  $equipos = [];
  $semaforos = (array)($concentradoresConfig['semaforos'] ?? []);

  foreach ((array)($report['concentradores'] ?? []) as $equipoKey => $equipo) {
    $items = [];
    $fueraOperacion = !empty($equipo['fuera_operacion']);
    foreach ((array)($equipo['metricas'] ?? []) as $metricKey => $metric) {
      $status = (array)($metric['status'] ?? []);
      $rule = (array)($semaforos[(string)$equipoKey][(string)$metricKey] ?? $semaforos[(string)$metricKey] ?? []);
      $source = (string)($metric['source'] ?? '');
      $aplicarFo = $fueraOperacion && $source !== 'sqlserver';

      $items[] = $makeItem(
        (string)$metricKey,
        (string)($metric['label'] ?? $metricKey),
        $aplicarFo ? 'FO' : (string)($metric['formatted'] ?? '-'),
        $aplicarFo ? 'gris' : (string)($status['key'] ?? 'gris'),
        $aplicarFo ? 'Fuera de operacion' : (string)($status['label'] ?? 'Sin dato'),
        $aplicarFo ? '#94a3b8' : (string)($status['color'] ?? '#94a3b8'),
        $buildRangeLabel([
          'rule' => $rule,
          'unit' => (string)($metric['unit'] ?? ''),
          'rangeLabel' => (string)($metric['leyenda'] ?? ''),
        ]),
        $source,
        $rule,
        $aplicarFo ? [] : (array)($metric['history'] ?? []),
        (string)($metric['unit'] ?? ''),
        $aplicarFo ? [] : (array)($metric['trends'] ?? [])
      );
    }

    $status = $worstStatus($items);
    if ($fueraOperacion && $status['statusKey'] === 'gris') {
      $status = ['statusKey' => 'gris', 'statusLabel' => 'Fuera de operacion', 'statusColor' => '#94a3b8'];
    }

    $equipos[(string)($equipo['key'] ?? $equipoKey)] = [
      'key' => (string)($equipo['key'] ?? $equipoKey),
      'titulo' => (string)($equipo['nombre'] ?? $equipoKey),
      'statusLabel' => $status['statusLabel'],
      'statusKey' => $status['statusKey'],
      'statusColor' => $status['statusColor'],
      'ultimaLectura' => (string)($equipo['timestamp_mysql'] ?? '-'),
      'items' => $items,
    ];
  }

  return $equipos;
};

$buildSqlServerIndicators = static function (array $indicatorsConfig, array $sqlConfig, string $tableName, string $timestampField) use ($connectSqlServer, $quoteSqlServerIdentifier, $evaluateMetricRange, $statusClass): array {
  $indicators = [];
  $fields = [];

  foreach ($indicatorsConfig as $indicator) {
    $field = trim((string)($indicator['field'] ?? ''));
    if ($field !== '') {
      $fields[$field] = $field;
    }
  }

  if ($fields === []) {
    return [$indicators, ''];
  }

  try {
    $pdo = $connectSqlServer($sqlConfig);
    $safeTable = $quoteSqlServerIdentifier($tableName);
    $safeTimestamp = $quoteSqlServerIdentifier($timestampField);
    $selectParts = [$safeTimestamp . ' AS [__timestamp]'];

    foreach ($fields as $fieldName) {
      $selectParts[] = $quoteSqlServerIdentifier($fieldName);
    }

    $sql = 'SELECT TOP (1) ' . implode(', ', $selectParts)
      . ' FROM ' . $safeTable
      . ' WHERE CAST(' . $safeTimestamp . ' AS date) = CAST(GETDATE() AS date)'
      . ' ORDER BY ' . $safeTimestamp . ' DESC';
    $row = $pdo->query($sql)->fetch() ?: [];

    foreach ($indicatorsConfig as $indicatorKey => $indicator) {
      $field = trim((string)($indicator['field'] ?? ''));
      $unit = trim((string)($indicator['unit'] ?? ''));
      $rawValue = $field !== '' && array_key_exists($field, $row) ? $row[$field] : null;
      $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
      $formatted = $numericValue !== null ? n($numericValue, 2) : '-';
      $rule = (array)($indicator['semaforo'] ?? []);
      [$statusLabel, $statusKey, $statusColor] = $rule !== []
        ? $evaluateMetricRange($numericValue, $rule)
        : [
          $numericValue !== null ? 'Lectura' : 'Sin dato',
          $numericValue !== null ? 'azul' : 'gris',
          $numericValue !== null ? '#0ea5e9' : '#94a3b8',
        ];

      $indicators[(string)$indicatorKey] = [
        'key' => (string)$indicatorKey,
        'label' => (string)($indicator['label'] ?? $indicatorKey),
        'value' => $unit !== '' && $formatted !== '-' ? trim($formatted . ' ' . $unit) : $formatted,
        'statusKey' => $statusKey,
        'statusLabel' => $statusLabel,
        'statusColor' => $statusColor,
        'class' => $statusClass($statusKey),
        'rangeLabel' => (string)($indicator['leyenda'] ?? ''),
        'source' => 'sqlserver',
      ];
    }
  } catch (Throwable $e) {
    return [[], 'No se pudieron leer indicadores de extracción desde SQL Server: ' . $e->getMessage()];
  }

  return [$indicators, ''];
};

$buildCocedoresSummary = static function (array $cocedoresConfig, array $headerConfig, array $mysqlMetricsConfig, array $cocedoresDbConfig, array $sqlConfig, string $tableName, string $timestampField) use ($connectSqlServer, $connectMysql, $quoteSqlServerIdentifier, $quoteMysqlIdentifier, $resolverValorBitacora, $formatHistoryTimestamp, $formatHistoryIsoTimestamp, $historyLimit, $sqlServerHistoryLimit, $isOutOfOperation, $makeItem, $worstStatus, $evaluateMetricRange, $statusClass): array {
  $cocedores = [];
  $fields = [];
  $headerMetrics = [];
  $sqlHistoryByField = [];
  $sqlMonthHistoryByField = [];

  foreach ($cocedoresConfig as $cocedor) {
    $field = trim((string)($cocedor['flujo_field'] ?? ''));
    if ($field !== '') {
      $fields[$field] = $field;
    }
  }
  foreach ($headerConfig as $metric) {
    $field = trim((string)($metric['field'] ?? ''));
    if ($field !== '') {
      $fields[$field] = $field;
    }
  }

  $row = [];
  $mysqlRowsByNumber = [];
  $mysqlStateRowsByNumber = [];
  $mysqlHistoryByNumber = [];
  $warning = '';
  if ($fields !== []) {
    try {
      $pdo = $connectSqlServer($sqlConfig);
      $safeTable = $quoteSqlServerIdentifier($tableName);
      $safeTimestamp = $quoteSqlServerIdentifier($timestampField);
      $selectParts = [$safeTimestamp . ' AS [__timestamp]'];

      foreach ($fields as $fieldName) {
        $selectParts[] = $quoteSqlServerIdentifier($fieldName);
      }

      $sql = 'SELECT TOP (1) ' . implode(', ', $selectParts)
        . ' FROM ' . $safeTable
        . ' WHERE CAST(' . $safeTimestamp . ' AS date) = CAST(GETDATE() AS date)'
        . ' ORDER BY ' . $safeTimestamp . ' DESC';
      $row = $pdo->query($sql)->fetch() ?: [];

      $sqlHistory = 'WITH tendencia AS ('
        . ' SELECT ' . implode(', ', $selectParts)
        . ', ROW_NUMBER() OVER (PARTITION BY DATEDIFF(minute, 0, ' . $safeTimestamp . ') ORDER BY ' . $safeTimestamp . ' DESC) AS [__minute_rn]'
        . ' FROM ' . $safeTable
        . ' WHERE ' . $safeTimestamp . ' >= DATEADD(day, -31, GETDATE())'
        . ') SELECT TOP (' . $sqlServerHistoryLimit . ') * FROM tendencia'
        . ' WHERE [__minute_rn] = 1'
        . ' ORDER BY [__timestamp] DESC';
      $historyRows = $pdo->query($sqlHistory)->fetchAll() ?: [];
      foreach ($fields as $fieldName) {
        $history = [];
        foreach ($historyRows as $historyRow) {
          $historyValue = $historyRow[$fieldName] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
            'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }
        $sqlHistoryByField[$fieldName] = $history;
      }

      $averageSelectParts = [
        'DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0) AS [__timestamp]',
      ];
      foreach ($fields as $fieldName) {
        $averageSelectParts[] = 'AVG(TRY_CONVERT(float, ' . $quoteSqlServerIdentifier($fieldName) . ')) AS ' . $quoteSqlServerIdentifier($fieldName);
      }
      $sqlMonthHistory = 'SELECT ' . implode(', ', $averageSelectParts)
        . ' FROM ' . $safeTable
        . ' WHERE ' . $safeTimestamp . ' >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)'
        . ' AND ' . $safeTimestamp . ' < DATEADD(month, DATEDIFF(month, 0, GETDATE()) + 1, 0)'
        . ' GROUP BY DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0)'
        . ' ORDER BY [__timestamp] DESC';
      $monthHistoryRows = $pdo->query($sqlMonthHistory)->fetchAll() ?: [];
      foreach ($fields as $fieldName) {
        $history = [];
        foreach ($monthHistoryRows as $historyRow) {
          $historyValue = $historyRow[$fieldName] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
            'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }
        $sqlMonthHistoryByField[$fieldName] = $history;
      }
    } catch (Throwable $e) {
      $warning = 'No se pudieron leer datos de cocedores desde SQL Server: ' . $e->getMessage();
    }
  }

  if ($mysqlMetricsConfig !== []) {
    try {
      $pdoMysql = $connectMysql((array)($cocedoresDbConfig['mysql_105'] ?? []));
      $safeTable = $quoteMysqlIdentifier((string)($cocedoresDbConfig['tabla_datos'] ?? 'datos_cocedores'));
      $safeNumberColumn = $quoteMysqlIdentifier((string)($cocedoresDbConfig['columna_numero'] ?? 'numero_cocedor'));
      $columnsRows = $pdoMysql->query('SHOW COLUMNS FROM ' . $safeTable)->fetchAll() ?: [];
      $columns = array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $columnsRows);
      $hasDatosHora = in_array('id_datos_hora', $columns, true);
      $todayJoinSql = $hasDatosHora ? ' INNER JOIN `datos_hora` dh ON dh.`id` = d.`id_datos_hora`' : '';
      $todayWhereSql = $hasDatosHora ? ' AND DATE(COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`)) = CURDATE()' : '';
      $historyWhereSql = $hasDatosHora ? ' AND COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) >= DATE_SUB(NOW(), INTERVAL 31 DAY)' : '';
      $historyTimestampSql = $hasDatosHora ? ', COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) AS __history_timestamp' : '';
      $orderColumns = (array)($cocedoresDbConfig['columnas_orden'] ?? []);
      $orderParts = [];
      foreach ($orderColumns as $orderColumn) {
        $orderColumn = (string)$orderColumn;
        if ($orderColumn !== '') {
          $orderParts[] = 'd.' . $quoteMysqlIdentifier($orderColumn) . ' DESC';
        }
      }
      $orderSql = $orderParts !== [] ? ' ORDER BY ' . implode(', ', $orderParts) : '';

      $stateStmt = $pdoMysql->prepare('SELECT d.* FROM ' . $safeTable . ' d' . $todayJoinSql . ' WHERE d.' . $safeNumberColumn . ' = :numero' . $todayWhereSql . $orderSql . ' LIMIT 1');
      $historyStmt = $pdoMysql->prepare('SELECT d.*' . $historyTimestampSql . ' FROM ' . $safeTable . ' d' . $todayJoinSql . ' WHERE d.' . $safeNumberColumn . ' = :numero' . $historyWhereSql . $orderSql . ' LIMIT ' . $historyLimit);

      foreach ($cocedoresConfig as $cocedorKey => $cocedor) {
        $number = isset($cocedor['numero']) ? (int)$cocedor['numero'] : (int)preg_replace('/\D+/', '', (string)$cocedorKey);
        if ($number <= 0) {
          continue;
        }

        $stateStmt->execute(['numero' => $number]);
        $stateRow = $stateStmt->fetch() ?: [];
        $mysqlStateRowsByNumber[$number] = $stateRow;
        $mysqlRowsByNumber[$number] = $stateRow;

        $historyStmt->execute(['numero' => $number]);
        $mysqlHistoryByNumber[$number] = $historyStmt->fetchAll() ?: [];
      }
    } catch (Throwable $e) {
      $warning = trim($warning . ' No se pudieron leer datos_cocedores desde 105: ' . $e->getMessage());
    }
  }

  foreach ($headerConfig as $metricKey => $metric) {
    $field = trim((string)($metric['field'] ?? ''));
    $unit = trim((string)($metric['unit'] ?? ''));
    $rawValue = $field !== '' && array_key_exists($field, $row) ? $row[$field] : null;
    $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
    $formatted = $numericValue !== null ? n($numericValue, 2) : '-';
    $rule = (array)($metric['semaforo'] ?? []);
    [$statusLabel, $statusKey, $statusColor] = $rule !== []
      ? $evaluateMetricRange($numericValue, $rule)
      : [
        $numericValue !== null ? 'Lectura' : 'Sin dato',
        $numericValue !== null ? 'azul' : 'gris',
        $numericValue !== null ? '#0ea5e9' : '#94a3b8',
      ];

    $headerMetrics[(string)$metricKey] = [
      'key' => (string)$metricKey,
      'label' => (string)($metric['label'] ?? $metricKey),
      'value' => $unit !== '' && $formatted !== '-' ? trim($formatted . ' ' . $unit) : $formatted,
      'statusKey' => $statusKey,
      'statusLabel' => $statusLabel,
      'statusColor' => $statusColor,
      'class' => $statusClass($statusKey),
      'rangeLabel' => (string)($metric['leyenda'] ?? ''),
      'source' => 'sqlserver',
    ];
  }

  foreach ($cocedoresConfig as $cocedorKey => $cocedor) {
    $number = isset($cocedor['numero']) ? (int)$cocedor['numero'] : (int)preg_replace('/\D+/', '', (string)$cocedorKey);
    $mysqlRow = (array)($mysqlRowsByNumber[$number] ?? []);
    $mysqlStateRow = (array)($mysqlStateRowsByNumber[$number] ?? $mysqlRow);
    $foColumn = (string)($cocedoresDbConfig['columna_fo'] ?? 'estado_fo');
    $fueraOperacion = $isOutOfOperation($mysqlStateRow[$foColumn] ?? null);
    $items = [];
    $field = trim((string)($cocedor['flujo_field'] ?? ''));
    $rawValue = $field !== '' && array_key_exists($field, $row) ? $row[$field] : null;
    $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
    $rule = (array)($cocedor['flujo_semaforo'] ?? []);
    [$statusLabel, $statusKey, $statusColor] = $rule !== []
      ? $evaluateMetricRange($numericValue, $rule)
      : [
        $numericValue !== null ? 'Lectura' : 'Sin dato',
        $numericValue !== null ? 'azul' : 'gris',
        $numericValue !== null ? '#0ea5e9' : '#94a3b8',
      ];
    $items[] = $makeItem(
      'flujo',
      'Flujo',
      $numericValue !== null ? n($numericValue, 2) : '-',
      $statusKey,
      $statusLabel,
      $statusColor,
      (string)($cocedor['flujo_leyenda'] ?? 'Por definir'),
      'sqlserver',
      $rule,
      (array)($sqlHistoryByField[$field] ?? []),
      '',
      [
        'month' => (array)($sqlMonthHistoryByField[$field] ?? []),
      ]
    );

    foreach ($mysqlMetricsConfig as $metricKey => $metric) {
      $metricField = (string)($metric['field'] ?? '');
      $unit = trim((string)($metric['unit'] ?? ''));
      $metricRawValue = $resolverValorBitacora($metricField, $mysqlRow, (array)($mysqlHistoryByNumber[$number] ?? []));
      $metricNumericValue = is_numeric($metricRawValue) ? (float)$metricRawValue : null;
      $metricFormatted = $metricNumericValue !== null ? n($metricNumericValue, 2) : '-';
      $metricRule = (array)($metric['semaforo'] ?? []);
      [$metricStatusLabel, $metricStatusKey, $metricStatusColor] = $metricRule !== []
        ? $evaluateMetricRange($metricNumericValue, $metricRule)
        : [
          $metricNumericValue !== null ? 'Lectura' : 'Sin dato',
          $metricNumericValue !== null ? 'azul' : 'gris',
          $metricNumericValue !== null ? '#0ea5e9' : '#94a3b8',
        ];
      $metricHistory = [];
      foreach ((array)($mysqlHistoryByNumber[$number] ?? []) as $historyRow) {
        $historyValue = $metricField !== '' ? ($historyRow[$metricField] ?? null) : null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $timestamp = $historyRow['__history_timestamp'] ?? null;
        $metricHistory[] = [
          'timestamp' => $formatHistoryTimestamp($timestamp),
          'iso' => $formatHistoryIsoTimestamp($timestamp),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }

      $items[] = $makeItem(
        (string)$metricKey,
        (string)($metric['label'] ?? $metricKey),
        $fueraOperacion ? 'FO' : ($unit !== '' && $metricFormatted !== '-' ? trim($metricFormatted . ' ' . $unit) : $metricFormatted),
        $fueraOperacion ? 'gris' : $metricStatusKey,
        $fueraOperacion ? 'Fuera de operacion' : $metricStatusLabel,
        $fueraOperacion ? '#94a3b8' : $metricStatusColor,
        (string)($metric['leyenda'] ?? 'Por definir'),
        'mysql_105',
        $metricRule,
        $fueraOperacion ? [] : $metricHistory,
        $unit
      );
    }

    $status = $fueraOperacion && $worstStatus($items)['statusKey'] === 'gris'
      ? ['statusKey' => 'gris', 'statusLabel' => 'Fuera de operacion', 'statusColor' => '#94a3b8']
      : $worstStatus($items);

    $cocedores[(string)$cocedorKey] = [
      'key' => (string)$cocedorKey,
      'titulo' => (string)($cocedor['titulo'] ?? $cocedorKey),
      'statusLabel' => $status['statusLabel'],
      'statusKey' => $status['statusKey'],
      'statusColor' => $status['statusColor'],
      'ultimaLectura' => isset($row['__timestamp']) && is_scalar($row['__timestamp']) ? (string)$row['__timestamp'] : '-',
      'items' => $items,
    ];
  }

  return [$cocedores, $headerMetrics, $warning];
};

$buildClarificadoresSummary = static function (array $clarificadoresConfig, array $defaultSqlConfig = []) use ($connectSqlServer, $connectMysql, $quoteSqlServerIdentifier, $quoteMysqlIdentifier, $resolverValorBitacora, $formatHistoryTimestamp, $formatHistoryIsoTimestamp, $historyLimit, $sqlServerHistoryLimit, $isOutOfOperation, $makeItem, $worstStatus, $evaluateMetricRange): array {
  $metricsConfig = (array)($clarificadoresConfig['metricas'] ?? []);
  $items = [];
  $warning = '';
  $row = [];
  $mysqlHistoryRows = [];
  $sqlServerRow = [];
  $sqlHistoryByField = [];
  $sqlMonthHistoryByField = [];
  $equipmentKey = (string)($clarificadoresConfig['key'] ?? 'clarificador');
  $equipmentTitle = (string)($clarificadoresConfig['titulo'] ?? 'Clarificador');

  try {
    $pdoMysql = $connectMysql((array)($clarificadoresConfig['mysql_105'] ?? []));
    $safeTable = $quoteMysqlIdentifier((string)($clarificadoresConfig['tabla_datos'] ?? 'datos_clarificador'));
    $columnsRows = $pdoMysql->query('SHOW COLUMNS FROM ' . $safeTable)->fetchAll() ?: [];
    $columns = array_map(static fn(array $column): string => (string)($column['Field'] ?? ''), $columnsRows);
    $hasDatosHora = in_array('id_datos_hora', $columns, true);
    $todayJoinSql = $hasDatosHora ? ' INNER JOIN `datos_hora` dh ON dh.`id` = d.`id_datos_hora`' : '';
    $todayWhereSql = $hasDatosHora ? ' WHERE DATE(COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`)) = CURDATE()' : '';
    $historyWhereSql = $hasDatosHora ? ' WHERE COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) >= DATE_SUB(NOW(), INTERVAL 31 DAY)' : '';
    $historyTimestampSql = $hasDatosHora ? ', COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) AS __history_timestamp' : '';
    $orderColumns = (array)($clarificadoresConfig['columnas_orden'] ?? []);
    $orderParts = [];
    foreach ($orderColumns as $orderColumn) {
      $orderColumn = (string)$orderColumn;
      if ($orderColumn !== '') {
        $orderParts[] = 'd.' . $quoteMysqlIdentifier($orderColumn) . ' DESC';
      }
    }
    $orderSql = $orderParts !== [] ? ' ORDER BY ' . implode(', ', $orderParts) : '';
    $row = $pdoMysql->query('SELECT d.* FROM ' . $safeTable . ' d' . $todayJoinSql . $todayWhereSql . $orderSql . ' LIMIT 1')->fetch() ?: [];
    $mysqlHistoryRows = $pdoMysql->query('SELECT d.*' . $historyTimestampSql . ' FROM ' . $safeTable . ' d' . $todayJoinSql . $historyWhereSql . $orderSql . ' LIMIT ' . $historyLimit)->fetchAll() ?: [];
  } catch (Throwable $e) {
    $warning = 'No se pudieron leer datos_clarificador desde 105: ' . $e->getMessage();
  }

  $sqlServerFields = [];
  foreach ($metricsConfig as $metric) {
    if ((string)($metric['source'] ?? 'mysql_105') !== 'sqlserver') {
      continue;
    }

    $field = trim((string)($metric['field'] ?? ''));
    if ($field !== '') {
      $sqlServerFields[$field] = $field;
    }
  }

  if ($sqlServerFields !== []) {
    try {
      $sqlConfig = array_replace($defaultSqlConfig, (array)($clarificadoresConfig['sqlserver']['conexion'] ?? []));
      $sqlMeta = (array)($clarificadoresConfig['sqlserver'] ?? []);
      $pdoSql = $connectSqlServer($sqlConfig);
      $safeTable = $quoteSqlServerIdentifier((string)($sqlMeta['tabla'] ?? 'TREND001'));
      $safeTimestamp = $quoteSqlServerIdentifier((string)($sqlMeta['campo_fecha'] ?? 'Time_Stamp'));
      $selectParts = [$safeTimestamp . ' AS [__timestamp]'];
      foreach ($sqlServerFields as $fieldName) {
        $selectParts[] = $quoteSqlServerIdentifier($fieldName);
      }

      $sql = 'SELECT TOP (1) ' . implode(', ', $selectParts)
        . ' FROM ' . $safeTable
        . ' WHERE CAST(' . $safeTimestamp . ' AS date) = CAST(GETDATE() AS date)'
        . ' ORDER BY ' . $safeTimestamp . ' DESC';
      $sqlServerRow = $pdoSql->query($sql)->fetch() ?: [];

      $sqlHistory = 'WITH tendencia AS ('
        . ' SELECT ' . implode(', ', $selectParts)
        . ', ROW_NUMBER() OVER (PARTITION BY DATEDIFF(minute, 0, ' . $safeTimestamp . ') ORDER BY ' . $safeTimestamp . ' DESC) AS [__minute_rn]'
        . ' FROM ' . $safeTable
        . ' WHERE ' . $safeTimestamp . ' >= DATEADD(day, -31, GETDATE())'
        . ') SELECT TOP (' . $sqlServerHistoryLimit . ') * FROM tendencia'
        . ' WHERE [__minute_rn] = 1'
        . ' ORDER BY [__timestamp] DESC';
      $historyRows = $pdoSql->query($sqlHistory)->fetchAll() ?: [];
      foreach ($sqlServerFields as $fieldName) {
        $history = [];
        foreach ($historyRows as $historyRow) {
          $historyValue = $historyRow[$fieldName] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
            'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }
        $sqlHistoryByField[$fieldName] = $history;
      }

      $averageSelectParts = [
        'DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0) AS [__timestamp]',
      ];
      foreach ($sqlServerFields as $fieldName) {
        $averageSelectParts[] = 'AVG(TRY_CONVERT(float, ' . $quoteSqlServerIdentifier($fieldName) . ')) AS ' . $quoteSqlServerIdentifier($fieldName);
      }
      $sqlMonthHistory = 'SELECT ' . implode(', ', $averageSelectParts)
        . ' FROM ' . $safeTable
        . ' WHERE ' . $safeTimestamp . ' >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)'
        . ' AND ' . $safeTimestamp . ' < DATEADD(month, DATEDIFF(month, 0, GETDATE()) + 1, 0)'
        . ' GROUP BY DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0)'
        . ' ORDER BY [__timestamp] DESC';
      $monthHistoryRows = $pdoSql->query($sqlMonthHistory)->fetchAll() ?: [];
      foreach ($sqlServerFields as $fieldName) {
        $history = [];
        foreach ($monthHistoryRows as $historyRow) {
          $historyValue = $historyRow[$fieldName] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
            'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }
        $sqlMonthHistoryByField[$fieldName] = $history;
      }
    } catch (Throwable $e) {
      $warning = trim($warning . ' No se pudo leer clarificador desde SQL Server: ' . $e->getMessage());
    }
  }

  $foColumn = (string)($clarificadoresConfig['columna_fo'] ?? 'estado_fo');
  $fueraOperacion = $isOutOfOperation($row[$foColumn] ?? null);

  foreach ($metricsConfig as $metricKey => $metric) {
    $field = (string)($metric['field'] ?? '');
    $unit = trim((string)($metric['unit'] ?? ''));
    $source = (string)($metric['source'] ?? 'mysql_105');
    $aplicarFo = $fueraOperacion && $source !== 'sqlserver';
    $sourceRow = $source === 'sqlserver' ? $sqlServerRow : $row;
    $rawValue = $source === 'sqlserver'
      ? ($field !== '' && array_key_exists($field, $sourceRow) ? $sourceRow[$field] : null)
      : $resolverValorBitacora($field, $sourceRow, $mysqlHistoryRows);
    $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
    $formatted = $numericValue !== null ? n($numericValue, 2) : '-';
    $rule = (array)($metric['semaforo'] ?? []);
    [$statusLabel, $statusKey, $statusColor] = $rule !== []
      ? $evaluateMetricRange($numericValue, $rule)
      : [
        $numericValue !== null ? 'Lectura' : 'Sin dato',
        $numericValue !== null ? 'azul' : 'gris',
        $numericValue !== null ? '#0ea5e9' : '#94a3b8',
      ];
    $history = [];
    if ($source === 'sqlserver') {
      $history = (array)($sqlHistoryByField[$field] ?? []);
    } else {
      foreach ($mysqlHistoryRows as $historyRow) {
        $historyValue = $field !== '' ? ($historyRow[$field] ?? null) : null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $timestamp = $historyRow['__history_timestamp'] ?? null;
        $history[] = [
          'timestamp' => $formatHistoryTimestamp($timestamp),
          'iso' => $formatHistoryIsoTimestamp($timestamp),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
    }

    $items[] = $makeItem(
      (string)$metricKey,
      (string)($metric['label'] ?? $metricKey),
      $aplicarFo ? 'FO' : ($unit !== '' && $formatted !== '-' ? trim($formatted . ' ' . $unit) : $formatted),
      $aplicarFo ? 'gris' : $statusKey,
      $aplicarFo ? 'Fuera de operacion' : $statusLabel,
      $aplicarFo ? '#94a3b8' : $statusColor,
      (string)($metric['leyenda'] ?? 'Por definir'),
      $source,
      $rule,
      $aplicarFo ? [] : $history,
      $unit,
      [
        'month' => ($aplicarFo || $source !== 'sqlserver') ? [] : (array)($sqlMonthHistoryByField[$field] ?? []),
      ]
    );
  }

  $status = $fueraOperacion && $worstStatus($items)['statusKey'] === 'gris'
    ? ['statusKey' => 'gris', 'statusLabel' => 'Fuera de operacion', 'statusColor' => '#94a3b8']
    : $worstStatus($items);

  return [[
    $equipmentKey => [
      'key' => $equipmentKey,
      'titulo' => $equipmentTitle,
      'statusLabel' => $status['statusLabel'],
      'statusKey' => $status['statusKey'],
      'statusColor' => $status['statusColor'],
      'ultimaLectura' => isset($row['id_datos_hora']) ? (string)$row['id_datos_hora'] : '-',
      'items' => $items,
    ],
  ], $warning];
};

$secadoresSummary = [];
foreach ((array)($secadoresReport['tuneles'] ?? []) as $tunnelKey => $tunnel) {
  $secadoresSummary[$tunnelKey] = $buildTunnelSummary((array)$tunnel, $secadoresSummaryConfig);
}
foreach ((array)($secadoresSummaryConfig['tuneles_placeholder'] ?? []) as $tunnelKey => $tunnelTitle) {
  if (isset($secadoresSummary[(string)$tunnelKey])) {
    continue;
  }

  $secadoresSummary[(string)$tunnelKey] = [
    'key' => (string)$tunnelKey,
    'titulo' => (string)$tunnelTitle,
    'statusLabel' => 'Sin datos',
    'statusKey' => 'gris',
    'statusColor' => '#94a3b8',
    'ultimaLectura' => '-',
    'items' => [],
  ];
}

$buildCardTable = static function (array $tuneles, array $preferredLabels = []): array {
  $rows = [];
  $rowOrder = [];
  $preferredOrder = [];

  foreach ($preferredLabels as $index => $label) {
    $key = mb_strtolower(trim((string)$label), 'UTF-8');
    if ($key !== '') {
      $preferredOrder[$key] = (int)$index;
    }
  }

  foreach ($tuneles as $tunnelKey => $tunnel) {
    foreach ((array)($tunnel['items'] ?? []) as $item) {
      $label = (string)($item['label'] ?? $item['key'] ?? '');
      $rowKey = mb_strtolower(trim($label), 'UTF-8');
      if ($rowKey === '') {
        continue;
      }

      if (!isset($rows[$rowKey])) {
        $rows[$rowKey] = [
          'key' => $rowKey,
          'label' => $label,
          'rangeLabel' => (string)($item['rangeLabel'] ?? ''),
          'sources' => [],
          'rule' => (array)($item['rule'] ?? []),
          'unit' => (string)($item['unit'] ?? ''),
          'values' => [],
        ];
        $rowOrder[] = $rowKey;
      }
      if ((string)($rows[$rowKey]['rangeLabel'] ?? '') === '' && (string)($item['rangeLabel'] ?? '') !== '') {
        $rows[$rowKey]['rangeLabel'] = (string)$item['rangeLabel'];
      }
      if ((array)($rows[$rowKey]['rule'] ?? []) === [] && (array)($item['rule'] ?? []) !== []) {
        $rows[$rowKey]['rule'] = (array)$item['rule'];
      }
      if ((string)($rows[$rowKey]['unit'] ?? '') === '' && (string)($item['unit'] ?? '') !== '') {
        $rows[$rowKey]['unit'] = (string)$item['unit'];
      }

      $source = (string)($item['source'] ?? '');
      if ($source !== '') {
        $rows[$rowKey]['sources'][$source] = $source;
      }
      $rows[$rowKey]['values'][(string)$tunnelKey] = $item;
    }
  }

  foreach ($rows as &$row) {
    $row['sources'] = array_values((array)($row['sources'] ?? []));
  }
  unset($row);

  if ($preferredOrder !== []) {
    $originalOrder = array_flip($rowOrder);
    usort($rowOrder, static function (string $a, string $b) use ($preferredOrder, $originalOrder): int {
      $rankA = $preferredOrder[$a] ?? null;
      $rankB = $preferredOrder[$b] ?? null;

      if ($rankA !== null || $rankB !== null) {
        return ($rankA ?? PHP_INT_MAX) <=> ($rankB ?? PHP_INT_MAX);
      }

      return ($originalOrder[$a] ?? 0) <=> ($originalOrder[$b] ?? 0);
    });
  }

  $orderedRows = [];
  foreach ($rowOrder as $rowKey) {
    if (isset($rows[$rowKey])) {
      $orderedRows[] = $rows[$rowKey];
    }
  }

  return $orderedRows;
};

$secadoresTableOrder = array_merge(
  [
    'Velocidad de banda',
    'Caudal de aire',
    'Agua caliente / suministro',
    'Agua caliente / retorno',
    'Presión de vapor',
  ],
  array_map(static fn(int $room): string => 'Hum R' . $room, range(1, 9)),
  ['Hum Aire'],
  array_map(static fn(int $room): string => 'Temp R' . $room, range(1, 9))
);

[$votatorMysqlItems, $votatorMysqlWarning] = $buildVotatorMysqlItems((array)($secadoresSummaryConfig['votator_mysql'] ?? []));
$votatorsSummary = $buildVotatorSummary((array)($secadoresReport['tuneles'] ?? []), $secadoresSummaryConfig, $votatorMysqlItems);
$votatorItems = [];
foreach ($votatorsSummary as $votator) {
  foreach ((array)($votator['items'] ?? []) as $item) {
    $votatorItems[] = $item;
  }
}
$votatorsStatus = $worstStatus($votatorItems);
$concentradoresSummary = $buildConcentradorSummary($concentradoresReport, (array)$concentradoresConfig);
$concentradoresItems = [];
foreach ($concentradoresSummary as $concentrador) {
  foreach ((array)($concentrador['items'] ?? []) as $item) {
    $concentradoresItems[] = $item;
  }
}
$concentradoresStatus = $worstStatus($concentradoresItems);
[$extraccionIndicadores, $extraccionIndicadoresWarning] = $buildSqlServerIndicators(
  (array)($config['extraccion']['indicadores'] ?? []),
  $sqlServerAvevaConnection,
  $sqlServerAvevaTable,
  $sqlServerAvevaTimestamp
);
[$cocedoresSummary, $cocedoresHeaderMetrics, $cocedoresWarning] = $buildCocedoresSummary(
  (array)($config['cocedores']['equipos'] ?? []),
  (array)($config['cocedores']['encabezado'] ?? []),
  (array)($config['cocedores']['metricas_mysql'] ?? []),
  (array)($config['cocedores'] ?? []),
  $sqlServerAvevaConnection,
  $sqlServerAvevaTable,
  $sqlServerAvevaTimestamp
);
$cocedoresItems = [];
foreach ($cocedoresSummary as $cocedor) {
  foreach ((array)($cocedor['items'] ?? []) as $item) {
    $cocedoresItems[] = $item;
  }
}
foreach ($cocedoresHeaderMetrics as $metric) {
  $cocedoresItems[] = $metric;
}
$cocedoresControlledItems = array_values(array_filter($cocedoresItems, static fn(array $item): bool => (string)($item['statusKey'] ?? '') !== 'azul'));
$cocedoresStatus = $cocedoresControlledItems !== [] ? $worstStatus($cocedoresControlledItems) : $worstStatus($cocedoresItems);
[$clarificadoresSummary, $clarificadoresWarning] = $buildClarificadoresSummary(
  (array)($config['clarificadores'] ?? []),
  $sqlServerAvevaConnection
);
$clarificadoresItems = [];
foreach ($clarificadoresSummary as $clarificador) {
  foreach ((array)($clarificador['items'] ?? []) as $item) {
    $clarificadoresItems[] = $item;
  }
}
$clarificadoresStatus = $worstStatus($clarificadoresItems);
[$integracionSummary, $integracionWarning] = $buildClarificadoresSummary(
  (array)($config['integracion'] ?? []),
  $sqlServerAvevaConnection
);
$integracionItems = [];
foreach ($integracionSummary as $integracion) {
  foreach ((array)($integracion['items'] ?? []) as $item) {
    $integracionItems[] = $item;
  }
}
$integracionStatus = $worstStatus($integracionItems);

return [
  'titulo' => (string)($config['titulo'] ?? 'Produccion Monitoreo'),
  'extraccion' => [
    'indicadores' => $extraccionIndicadores,
  ],
  'cards' => [
    'secadores' => [
      'key' => 'secadores',
      'titulo' => 'Secadores',
      'icon' => 'fa-fan',
      'tuneles' => $secadoresSummary,
      'tabla' => $buildCardTable($secadoresSummary, $secadoresTableOrder),
      'statusLabel' => (string)($secadoresReport['global']['statusLabel'] ?? 'Referencia'),
      'statusKey' => (string)($secadoresReport['global']['statusKey'] ?? 'gris'),
      'statusColor' => (string)($secadoresReport['global']['statusColor'] ?? '#94a3b8'),
    ],
    'votators' => [
      'key' => 'votators',
      'titulo' => 'Votators',
      'icon' => 'fa-sliders',
      'tuneles' => $votatorsSummary,
      'tabla' => $buildCardTable($votatorsSummary),
      'statusLabel' => $votatorsStatus['statusLabel'],
      'statusKey' => $votatorsStatus['statusKey'],
      'statusColor' => $votatorsStatus['statusColor'],
    ],
    'concentradores' => [
      'key' => 'concentradores',
      'titulo' => 'Concentradores',
      'icon' => 'fa-industry',
      'tuneles' => $concentradoresSummary,
      'tabla' => $buildCardTable($concentradoresSummary),
      'statusLabel' => $concentradoresStatus['statusLabel'],
      'statusKey' => $concentradoresStatus['statusKey'],
      'statusColor' => $concentradoresStatus['statusColor'],
    ],
    'cocedores' => [
      'key' => 'cocedores',
      'titulo' => 'Cocedores',
      'icon' => 'fa-fire-burner',
      'tuneles' => $cocedoresSummary,
      'tabla' => $buildCardTable($cocedoresSummary),
      'headMetrics' => $cocedoresHeaderMetrics,
      'statusLabel' => $cocedoresStatus['statusLabel'],
      'statusKey' => $cocedoresStatus['statusKey'],
      'statusColor' => $cocedoresStatus['statusColor'],
    ],
    'clarificadores' => [
      'key' => 'clarificadores',
      'titulo' => 'Clarificadores',
      'icon' => 'fa-filter',
      'tuneles' => $clarificadoresSummary,
      'tabla' => $buildCardTable($clarificadoresSummary),
      'statusLabel' => $clarificadoresStatus['statusLabel'],
      'statusKey' => $clarificadoresStatus['statusKey'],
      'statusColor' => $clarificadoresStatus['statusColor'],
    ],
    'integracion' => [
      'key' => 'integracion',
      'titulo' => 'Integración',
      'icon' => 'fa-link',
      'tuneles' => $integracionSummary,
      'tabla' => $buildCardTable($integracionSummary),
      'statusLabel' => $integracionStatus['statusLabel'],
      'statusKey' => $integracionStatus['statusKey'],
      'statusColor' => $integracionStatus['statusColor'],
    ],
  ],
  'meta' => [
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? 60000),
    'warnings' => array_values(array_filter(array_merge(
      (array)($secadoresReport['warnings'] ?? []),
      (array)($concentradoresReport['meta']['warnings'] ?? []),
      [$votatorMysqlWarning, $extraccionIndicadoresWarning, $cocedoresWarning, $clarificadoresWarning, $integracionWarning]
    ))),
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time(),
    @filemtime(__DIR__ . '/data.php') ?: time(),
    (int)($secadoresReport['version'] ?? 0),
    (int)($concentradoresReport['version'] ?? 0)
  ),
];
