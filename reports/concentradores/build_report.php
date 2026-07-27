<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

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

$quoteSqlServerIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador SQL Server invalido: ' . $name);
  }

  return '[' . $name . ']';
};

$quoteMysqlIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador MySQL invalido: ' . $name);
  }

  return '`' . $name . '`';
};

$evaluateMetricStatus = static function (?float $value, array $rule): array {
  if ($value === null) {
    return ['label' => 'Sin dato', 'key' => 'gris', 'color' => '#94a3b8', 'class' => 'unavailable'];
  }

  if ($rule === []) {
    return ['label' => 'Lectura', 'key' => 'azul', 'color' => '#0ea5e9', 'class' => 'info'];
  }

  $mode = (string)($rule['modo'] ?? 'rango');
  $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
  $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  if ($mode === 'minimo') {
    if ($greenMin !== null && $value >= $greenMin) {
      return ['label' => 'Optimo', 'key' => 'verde', 'color' => '#2e8b57', 'class' => 'ok'];
    }
    if ($yellowMin !== null && $value >= $yellowMin) {
      return ['label' => 'Atencion', 'key' => 'amarillo', 'color' => '#e49a32', 'class' => 'warning'];
    }

    return ['label' => 'Critico', 'key' => 'rojo', 'color' => '#c94436', 'class' => 'danger'];
  }

  if ($mode === 'maximo') {
    if ($greenMax !== null && $value <= $greenMax) {
      return ['label' => 'Optimo', 'key' => 'verde', 'color' => '#2e8b57', 'class' => 'ok'];
    }
    if ($yellowMax !== null && $value <= $yellowMax) {
      return ['label' => 'Atencion', 'key' => 'amarillo', 'color' => '#e49a32', 'class' => 'warning'];
    }

    return ['label' => 'Critico', 'key' => 'rojo', 'color' => '#c94436', 'class' => 'danger'];
  }

  $inGreen = ($greenMin === null || $value >= $greenMin) && ($greenMax === null || $value <= $greenMax);
  if ($inGreen) {
    return ['label' => 'Optimo', 'key' => 'verde', 'color' => '#2e8b57', 'class' => 'ok'];
  }

  if ($yellowMin !== null || $yellowMax !== null) {
    $inYellow = ($yellowMin === null || $value >= $yellowMin) && ($yellowMax === null || $value <= $yellowMax);
    if ($inYellow) {
      return ['label' => 'Atencion', 'key' => 'amarillo', 'color' => '#e49a32', 'class' => 'warning'];
    }
  }

  return ['label' => 'Critico', 'key' => 'rojo', 'color' => '#c94436', 'class' => 'danger'];
};

$formatValue = static function (?float $value, int $decimals, string $unit): string {
  if ($value === null) {
    return '-';
  }

  $formatted = n($value, $decimals);
  return $unit !== '' ? $formatted . ' ' . $unit : $formatted;
};

$findColumn = static function (array $columns, array $candidates): ?string {
  $normalized = [];
  foreach ($columns as $column) {
    $normalized[mb_strtolower((string)$column, 'UTF-8')] = (string)$column;
  }

  foreach ($candidates as $candidate) {
    $key = mb_strtolower((string)$candidate, 'UTF-8');
    if (isset($normalized[$key])) {
      return $normalized[$key];
    }
  }

  return null;
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

$warnings = [];
$concentratorConfig = (array)($config['concentradores'] ?? []);
$metricConfig = (array)($config['metricas'] ?? []);
$semaforos = (array)($config['semaforos'] ?? []);
$historyLimit = max(300, (int)($config['tendencia_limite_registros'] ?? 5000));
$sqlServerHistoryLimit = max(1440, (int)($config['tendencia_limite_sqlserver'] ?? 5000));
$flowValues = [];
$flowHistoryByField = [];
$flowMonthHistoryByField = [];
$flowTimestamp = null;
$mysqlRowsByType = [];
$mysqlStateRowsByType = [];
$mysqlHistoryByType = [];
$mysqlColumns = [];
$mysqlTimestampColumn = null;

$flowFields = [];
foreach ($concentratorConfig as $concentrator) {
  $field = trim((string)($concentrator['flujo_field'] ?? ''));
  if ($field !== '') {
    $flowFields[$field] = $field;
  }
  foreach ((array)($concentrator['metric_fields'] ?? []) as $metricField) {
    $metricField = trim((string)$metricField);
    if ($metricField !== '') {
      $flowFields[$metricField] = $metricField;
    }
  }
  foreach ((array)($concentrator['metricas_extra'] ?? []) as $metric) {
    if ((string)($metric['source'] ?? '') !== 'sqlserver') {
      continue;
    }

    $metricField = trim((string)($metric['field'] ?? ''));
    if ($metricField !== '') {
      $flowFields[$metricField] = $metricField;
    }
  }
}

if ($flowFields !== []) {
  try {
    $pdoSqlServer = $connectSqlServer((array)($config['sqlserver'] ?? []));
    $safeTable = $quoteSqlServerIdentifier((string)($config['tabla_aveva'] ?? 'TREND001'));
    $safeTimestamp = $quoteSqlServerIdentifier((string)($config['campo_fecha_aveva'] ?? 'Time_Stamp'));

    $selectParts = [$safeTimestamp . ' AS [__timestamp]'];
    foreach ($flowFields as $field) {
      $selectParts[] = $quoteSqlServerIdentifier($field);
    }

    $sql = 'SELECT TOP (1) ' . implode(', ', $selectParts)
      . ' FROM ' . $safeTable
      . ' WHERE CAST(' . $safeTimestamp . ' AS date) = CAST(GETDATE() AS date)'
      . ' ORDER BY ' . $safeTimestamp . ' DESC';
    $row = $pdoSqlServer->query($sql)->fetch() ?: [];
    $flowTimestamp = $row['__timestamp'] ?? null;

    foreach ($flowFields as $field) {
      $value = $row[$field] ?? null;
      $flowValues[$field] = is_numeric($value) ? (float)$value : null;
    }

    $sqlHistory = 'WITH tendencia AS ('
      . ' SELECT ' . implode(', ', $selectParts)
      . ', ROW_NUMBER() OVER (PARTITION BY DATEDIFF(minute, 0, ' . $safeTimestamp . ') ORDER BY ' . $safeTimestamp . ' DESC) AS [__minute_rn]'
      . ' FROM ' . $safeTable
      . ' WHERE ' . $safeTimestamp . ' >= DATEADD(day, -31, GETDATE())'
      . ') SELECT TOP (' . $sqlServerHistoryLimit . ') * FROM tendencia'
      . ' WHERE [__minute_rn] = 1'
      . ' ORDER BY [__timestamp] DESC';
    $historyRows = $pdoSqlServer->query($sqlHistory)->fetchAll() ?: [];

    foreach ($flowFields as $field) {
      $history = [];
      foreach ($historyRows as $historyRow) {
        $historyValue = $historyRow[$field] ?? null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $history[] = [
          'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
          'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
      $flowHistoryByField[$field] = $history;
    }

    $averageSelectParts = [
      'DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0) AS [__timestamp]',
    ];
    foreach ($flowFields as $fieldName) {
      $averageSelectParts[] = 'AVG(TRY_CONVERT(float, ' . $quoteSqlServerIdentifier($fieldName) . ')) AS ' . $quoteSqlServerIdentifier($fieldName);
    }
    $sqlMonthHistory = 'SELECT ' . implode(', ', $averageSelectParts)
      . ' FROM ' . $safeTable
      . ' WHERE ' . $safeTimestamp . ' >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)'
      . ' AND ' . $safeTimestamp . ' < DATEADD(month, DATEDIFF(month, 0, GETDATE()) + 1, 0)'
      . ' GROUP BY DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0)'
      . ' ORDER BY [__timestamp] DESC';
    $monthHistoryRows = $pdoSqlServer->query($sqlMonthHistory)->fetchAll() ?: [];
    foreach ($flowFields as $field) {
      $history = [];
      foreach ($monthHistoryRows as $historyRow) {
        $historyValue = $historyRow[$field] ?? null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $history[] = [
          'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
          'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
      $flowMonthHistoryByField[$field] = $history;
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudo leer flujo desde AVEVA: ' . $e->getMessage();
  }
}

try {
  $pdoMysql = $connectMysql((array)($config['mysql_105'] ?? []));
  $safeTable = $quoteMysqlIdentifier((string)($config['tabla_datos'] ?? 'datos_concentradores'));
  $safeTypeColumn = $quoteMysqlIdentifier((string)($config['columna_tipo'] ?? 'tipo'));

  $columnsRows = $pdoMysql->query('SHOW COLUMNS FROM ' . $safeTable)->fetchAll() ?: [];
  $mysqlColumns = array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $columnsRows);
  $mysqlTimestampColumn = $findColumn($mysqlColumns, (array)($config['columnas_orden'] ?? []));
  $orderSql = $mysqlTimestampColumn !== null ? ' ORDER BY d.' . $quoteMysqlIdentifier($mysqlTimestampColumn) . ' DESC' : '';
  $hasDatosHora = in_array('id_datos_hora', $mysqlColumns, true);
  $todayJoinSql = $hasDatosHora ? ' INNER JOIN `datos_hora` dh ON dh.`id` = d.`id_datos_hora`' : '';
  $todayWhereSql = $hasDatosHora ? ' AND DATE(COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`)) = CURDATE()' : '';
  $historyWhereSql = $hasDatosHora ? ' AND COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) >= DATE_SUB(NOW(), INTERVAL 31 DAY)' : '';
  $historyTimestampSql = $hasDatosHora ? ', COALESCE(dh.`hora_exacta`, dh.`fecha_creacion`) AS __history_timestamp' : '';

  $stateStmt = $pdoMysql->prepare('SELECT d.* FROM ' . $safeTable . ' d' . $todayJoinSql . ' WHERE d.' . $safeTypeColumn . ' = :tipo' . $todayWhereSql . $orderSql . ' LIMIT 1');
  $historyStmt = $pdoMysql->prepare('SELECT d.*' . $historyTimestampSql . ' FROM ' . $safeTable . ' d' . $todayJoinSql . ' WHERE d.' . $safeTypeColumn . ' = :tipo' . $historyWhereSql . $orderSql . ' LIMIT ' . $historyLimit);

  foreach ($concentratorConfig as $concentrator) {
    $tipo = (string)($concentrator['tipo'] ?? '');
    if ($tipo === '') {
      continue;
    }
    $stateStmt->execute(['tipo' => $tipo]);
    $mysqlStateRowsByType[$tipo] = $stateStmt->fetch() ?: [];
    $mysqlRowsByType[$tipo] = $mysqlStateRowsByType[$tipo];

    $historyStmt->execute(['tipo' => $tipo]);
    $mysqlHistoryByType[$tipo] = $historyStmt->fetchAll() ?: [];
  }
} catch (Throwable $e) {
  $message = $e->getMessage();
  if (strpos($message, 'Access denied') !== false) {
    $warnings[] = 'MySQL 105 responde, pero rechaza el usuario configurado desde este contenedor. Revisa permisos del usuario para el host de la app: ' . $message;
  } else {
    $warnings[] = 'No se pudo leer datos_concentradores desde 105: ' . $message;
  }
}

$concentradores = [];
foreach ($concentratorConfig as $concentratorKey => $concentrator) {
  $tipo = (string)($concentrator['tipo'] ?? '');
  $row = (array)($mysqlRowsByType[$tipo] ?? []);
  $stateRow = (array)($mysqlStateRowsByType[$tipo] ?? $row);
  $metrics = [];
  $estadoFoColumn = $findColumn($mysqlColumns, ['estado_fo']);
  $fueraOperacion = $estadoFoColumn !== null ? $isOutOfOperation($stateRow[$estadoFoColumn] ?? null) : false;

  $useBaseMetrics = !array_key_exists('usar_metricas_base', $concentrator) || !empty($concentrator['usar_metricas_base']);
  $metricsForConcentrator = $useBaseMetrics
    ? array_replace($metricConfig, (array)($concentrator['metricas_extra'] ?? []))
    : (array)($concentrator['metricas_extra'] ?? []);
  foreach ($metricsForConcentrator as $metricKey => $metric) {
    $metricFieldOverride = trim((string)($concentrator['metric_fields'][$metricKey] ?? ''));
    $source = $metricFieldOverride !== '' ? 'sqlserver' : (string)($metric['source'] ?? 'mysql_105');
    $numericValue = null;
    $sourceColumn = null;
    $historyRows = [];

    if ($source === 'sqlserver') {
      $metricSourceField = trim((string)($metric['field'] ?? ''));
      $sourceColumn = $metricFieldOverride !== ''
        ? $metricFieldOverride
        : ($metricSourceField !== '' ? $metricSourceField : (string)($concentrator['flujo_field'] ?? ''));
      $numericValue = $sourceColumn !== '' ? ($flowValues[$sourceColumn] ?? null) : null;
      $historyRows = $sourceColumn !== '' ? (array)($flowHistoryByField[$sourceColumn] ?? []) : [];
    } else {
      $sourceColumn = $findColumn($mysqlColumns, (array)($metric['columns'] ?? []));
      $value = $sourceColumn !== null ? ($row[$sourceColumn] ?? null) : null;
      $numericValue = is_numeric($value) ? (float)$value : null;

      foreach ((array)($mysqlHistoryByType[$tipo] ?? []) as $historyRow) {
        $historyValue = $sourceColumn !== null ? ($historyRow[$sourceColumn] ?? null) : null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $timestamp = $historyRow['__history_timestamp'] ?? ($mysqlTimestampColumn !== null ? ($historyRow[$mysqlTimestampColumn] ?? null) : null);
        $historyRows[] = [
          'timestamp' => $formatHistoryTimestamp($timestamp),
          'iso' => $formatHistoryIsoTimestamp($timestamp),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
    }

    $rule = (array)($semaforos[$concentratorKey][$metricKey] ?? $semaforos[$metricKey] ?? []);
    if ($fueraOperacion) {
      $numericValue = null;
    }

    $status = $fueraOperacion
      ? ['label' => 'Fuera de operacion', 'key' => 'gris', 'color' => '#94a3b8', 'class' => 'unavailable']
      : $evaluateMetricStatus($numericValue, $rule);
    $decimals = (int)($metric['decimals'] ?? 2);
    $unit = (string)($metric['unit'] ?? '');

    $metrics[$metricKey] = [
      'key' => $metricKey,
      'label' => (string)($metric['label'] ?? $metricKey),
      'value' => $numericValue,
      'formatted' => $formatValue($numericValue, $decimals, $unit),
      'unit' => $unit,
      'source' => $source,
      'source_column' => $sourceColumn,
      'status' => $status,
      'leyenda' => (string)($metric['leyenda'] ?? ''),
      'history' => $fueraOperacion ? [] : $historyRows,
      'trends' => [
        'month' => (!$fueraOperacion && $source === 'sqlserver' && $sourceColumn !== '') ? (array)($flowMonthHistoryByField[$sourceColumn] ?? []) : [],
      ],
    ];
  }

  $timestampRow = $fueraOperacion ? $stateRow : $row;

  $concentradores[$concentratorKey] = [
    'key' => $concentratorKey,
    'nombre' => (string)($concentrator['nombre'] ?? $concentratorKey),
    'tipo' => $tipo,
    'fuera_operacion' => $fueraOperacion,
    'estado_fo' => $estadoFoColumn !== null ? ($stateRow[$estadoFoColumn] ?? null) : null,
    'metricas' => $metrics,
    'timestamp_mysql' => $mysqlTimestampColumn !== null ? ($timestampRow[$mysqlTimestampColumn] ?? null) : null,
  ];
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Concentradores'),
  'concentradores' => $concentradores,
  'meta' => [
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? 60000),
    'warnings' => $warnings,
    'flowTimestamp' => $flowTimestamp,
    'mysqlTimestampColumn' => $mysqlTimestampColumn,
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time(),
    @filemtime(__DIR__ . '/data.php') ?: time()
  ),
];
