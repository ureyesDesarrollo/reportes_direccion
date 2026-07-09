<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';

$detailReport = require __DIR__ . '/../secadores-temperatura/build_report.php';
$detailConfig = require __DIR__ . '/../secadores-temperatura/config.php';



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
  ]);
};


$quoteSqlIdentifier = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Identificador SQL inválido: ' . $name);
  }

  return '[' . $name . ']';
};

$historyLimit = 5;
$reportTimezone = new DateTimeZone((string)($detailConfig['timezone'] ?? 'America/Mexico_City'));

$normalizeTimestamp = static function ($value) use ($reportTimezone): ?DateTimeImmutable {
  if ($value instanceof DateTimeInterface) {
    try {
      return (new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $value->getTimezone() ?: $reportTimezone))
        ->setTimezone($reportTimezone);
    } catch (Throwable $e) {
      return null;
    }
  }

  if (is_string($value) && trim($value) !== '') {
    try {
      return (new DateTimeImmutable($value, $reportTimezone))->setTimezone($reportTimezone);
    } catch (Throwable $e) {
      return null;
    }
  }

  return null;
};

$formatHistoryTimestamp = static function ($value) use ($normalizeTimestamp): string {
  $timestamp = $normalizeTimestamp($value);
  if ($timestamp === null) {
    return is_scalar($value) ? (string)$value : '-';
  }

  return $timestamp->format('d/m H:i');
};

$evaluateMetricStatus = static function (?float $value, array $rule): array {
  if ($value === null) {
    return ['Sin dato', 'gris', '#94a3b8'];
  }

  $mode = (string)($rule['modo'] ?? 'rango');
  $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
  $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  if ($mode === 'minimo') {
    if ($greenMin !== null && $value >= $greenMin) {
      return ['Óptimo', 'verde', '#2e8b57'];
    }

    if ($yellowMin !== null && $value >= $yellowMin) {
      return ['Atención', 'amarillo', '#e49a32'];
    }

    return ['Crítico', 'rojo', '#c94436'];
  }

  if ($mode === 'maximo') {
    if ($greenMax !== null && $value <= $greenMax) {
      return ['Óptimo', 'verde', '#2e8b57'];
    }

    if ($yellowMax !== null && $value <= $yellowMax) {
      return ['Atención', 'amarillo', '#e49a32'];
    }

    return ['Crítico', 'rojo', '#c94436'];
  }

  if ($mode === 'rango') {
    $inGreen = ($greenMin === null || $value >= $greenMin) && ($greenMax === null || $value <= $greenMax);
    if ($inGreen) {
      return ['Óptimo', 'verde', '#2e8b57'];
    }

    $hasYellow = $yellowMin !== null || $yellowMax !== null;
    if ($hasYellow) {
      $inYellow = ($yellowMin === null || $value >= $yellowMin) && ($yellowMax === null || $value <= $yellowMax);
      if ($inYellow) {
        return ['Atención', 'amarillo', '#e49a32'];
      }
    }
  }

  return ['Crítico', 'rojo', '#c94436'];
};

$metricConfigByTunnel = (array)($config['metricas_por_tunel'] ?? []);
$votatorConfigByTunnel = (array)($config['votators_por_tunel'] ?? []);
$metricValuesByTunnel = [];
$metricRow = [];
$metricHistoryRows = [];
$metricFields = [];
$mysqlMetricLookups = [];

foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
  foreach ((array)$metricGroup as $metricKey => $metricConfig) {
    $field = trim((string)($metricConfig['field'] ?? ''));
    $source = (string)($metricConfig['source'] ?? 'sqlserver');
    if ($field !== '' && $source === 'sqlserver') {
      $metricFields[$field] = $field;
    }
    if ($source === 'mysql_secadores') {
      $mysqlMetricLookups[$tunnelKey][$metricKey] = (array)$metricConfig;
    }
  }
}

foreach ($votatorConfigByTunnel as $votatorGroup) {
  foreach ((array)$votatorGroup as $votator) {
    foreach ((array)($votator['campos'] ?? []) as $fieldConfig) {
      $field = trim((string)($fieldConfig['field'] ?? ''));
      $source = (string)($fieldConfig['source'] ?? 'sqlserver');
      if ($field !== '' && $source === 'sqlserver') {
        $metricFields[$field] = $field;
      }
    }
  }
}

if (!empty($metricFields)) {
  try {
    $pdo = $connectSqlServer((array)($detailConfig['sqlserver'] ?? []));
    $safeTable = $quoteSqlIdentifier((string)($detailConfig['tabla'] ?? 'TREND001'));
    $safeTimestamp = $quoteSqlIdentifier((string)($detailConfig['campo_fecha'] ?? 'Time_Stamp'));

    $selectParts = [$safeTimestamp . ' AS [__timestamp]'];
    foreach ($metricFields as $fieldName) {
      $selectParts[] = $quoteSqlIdentifier($fieldName);
    }

    $sql = 'SELECT TOP (1) ' . implode(', ', $selectParts)
      . ' FROM ' . $safeTable
      . ' ORDER BY ' . $safeTimestamp . ' DESC';

    $metricRow = $pdo->query($sql)->fetch() ?: [];

    $sqlHistory = 'SELECT TOP (' . $historyLimit . ') ' . implode(', ', $selectParts)
      . ' FROM ' . $safeTable
      . ' ORDER BY ' . $safeTimestamp . ' DESC';
    $metricHistoryRows = $pdo->query($sqlHistory)->fetchAll() ?: [];

    foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
      foreach ((array)$metricGroup as $metricKey => $metricConfig) {
        $field = trim((string)($metricConfig['field'] ?? ''));
        $value = ($field !== '' && array_key_exists($field, $metricRow)) ? $metricRow[$field] : null;
        $numericValue = is_numeric($value) ? (float)$value : null;
        $rule = (array)($metricConfig['semaforo'] ?? []);
        [$statusLabel, $statusKey, $statusColor] = !empty($rule)
          ? $evaluateMetricStatus($numericValue, $rule)
          : [($numericValue !== null ? 'Lectura' : 'Sin dato'), ($numericValue !== null ? 'azul' : 'gris'), ($numericValue !== null ? '#0ea5e9' : '#94a3b8')];
        $history = [];

        if ($field !== '') {
          foreach ($metricHistoryRows as $historyRow) {
            $historyValue = $historyRow[$field] ?? null;
            $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
            $history[] = [
              'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
              'value' => $historyNumericValue,
              'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
            ];
          }
        }

        $metricValuesByTunnel[$tunnelKey][$metricKey] = [
          'key' => $metricKey,
          'group' => (string)($metricConfig['group'] ?? 'General'),
          'label' => (string)($metricConfig['label'] ?? $metricKey),
          'unit' => (string)($metricConfig['unit'] ?? ''),
          'available' => !empty($metricConfig['available']) && $field !== '',
          'field' => $field,
          'value' => $numericValue,
          'formatted' => $numericValue !== null ? n($numericValue, 2) : (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => $statusLabel,
          'statusKey' => $statusKey,
          'statusColor' => $statusColor,
          'rangeLabel' => (string)($metricConfig['leyenda'] ?? ''),
          'rule' => $rule,
          'history' => $history,
        ];
      }
    }
  } catch (Throwable $e) {
    foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
      foreach ((array)$metricGroup as $metricKey => $metricConfig) {
        $metricValuesByTunnel[$tunnelKey][$metricKey] = [
          'key' => $metricKey,
          'group' => (string)($metricConfig['group'] ?? 'General'),
          'label' => (string)($metricConfig['label'] ?? $metricKey),
          'unit' => (string)($metricConfig['unit'] ?? ''),
          'available' => false,
          'field' => (string)($metricConfig['field'] ?? ''),
          'value' => null,
          'formatted' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => 'Sin dato',
          'statusKey' => 'gris',
          'statusColor' => '#94a3b8',
          'rangeLabel' => (string)($metricConfig['leyenda'] ?? ''),
          'rule' => (array)($metricConfig['semaforo'] ?? []),
          'history' => [],
        ];
      }
    }
  }
}

if (!empty($mysqlMetricLookups)) {
  try {
    $pdoMysql = $connectMysql((array)($config['mysql_secadores'] ?? []));

    foreach ($mysqlMetricLookups as $tunnelKey => $metricGroup) {
      foreach ($metricGroup as $metricKey => $metricConfig) {
        $lookup = (array)($metricConfig['lookup'] ?? []);
        $table = (string)($lookup['table'] ?? '');
        $keyColumn = (string)($lookup['key_column'] ?? '');
        $keyValue = (string)($lookup['key_value'] ?? '');
        $timestampColumn = (string)($lookup['timestamp_column'] ?? 'fecha_hora');
        $field = trim((string)($metricConfig['field'] ?? ''));

        if ($table === '' || $keyColumn === '' || $keyValue === '' || $field === '') {
          continue;
        }

        $sql = sprintf(
          'SELECT `%s` AS metric_value FROM `%s` WHERE `%s` = :key_value ORDER BY `%s` DESC LIMIT 1',
          $field,
          $table,
          $keyColumn,
          $timestampColumn
        );

        $stmt = $pdoMysql->prepare($sql);
        $stmt->execute(['key_value' => $keyValue]);
        $row = $stmt->fetch() ?: [];

        $sqlHistory = sprintf(
          'SELECT `%s` AS metric_value, `%s` AS metric_timestamp FROM `%s` WHERE `%s` = :key_value ORDER BY `%s` DESC LIMIT %d',
          $field,
          $timestampColumn,
          $table,
          $keyColumn,
          $timestampColumn,
          $historyLimit
        );
        $stmtHistory = $pdoMysql->prepare($sqlHistory);
        $stmtHistory->execute(['key_value' => $keyValue]);
        $historyRows = $stmtHistory->fetchAll() ?: [];

        $value = $row['metric_value'] ?? null;
        $numericValue = is_numeric($value) ? (float)$value : null;
        $rule = (array)($metricConfig['semaforo'] ?? []);
        [$statusLabel, $statusKey, $statusColor] = !empty($rule)
          ? $evaluateMetricStatus($numericValue, $rule)
          : [($numericValue !== null ? 'Lectura' : 'Sin dato'), ($numericValue !== null ? 'azul' : 'gris'), ($numericValue !== null ? '#0ea5e9' : '#94a3b8')];
        $history = [];

        foreach ($historyRows as $historyRow) {
          $historyValue = $historyRow['metric_value'] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['metric_timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }

        $metricValuesByTunnel[$tunnelKey][$metricKey] = [
          'key' => $metricKey,
          'group' => (string)($metricConfig['group'] ?? 'General'),
          'label' => (string)($metricConfig['label'] ?? $metricKey),
          'unit' => (string)($metricConfig['unit'] ?? ''),
          'available' => !empty($metricConfig['available']) && $field !== '',
          'field' => $field,
          'value' => $numericValue,
          'formatted' => $numericValue !== null ? n($numericValue, 2) : (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => $statusLabel,
          'statusKey' => $statusKey,
          'statusColor' => $statusColor,
          'rangeLabel' => (string)($metricConfig['leyenda'] ?? ''),
          'rule' => $rule,
          'history' => $history,
        ];
      }
    }
  } catch (Throwable $e) {
    foreach ($mysqlMetricLookups as $tunnelKey => $metricGroup) {
      foreach ($metricGroup as $metricKey => $metricConfig) {
        if (isset($metricValuesByTunnel[$tunnelKey][$metricKey])) {
          continue;
        }

        $metricValuesByTunnel[$tunnelKey][$metricKey] = [
          'key' => $metricKey,
          'group' => (string)($metricConfig['group'] ?? 'General'),
          'label' => (string)($metricConfig['label'] ?? $metricKey),
          'unit' => (string)($metricConfig['unit'] ?? ''),
          'available' => false,
          'field' => (string)($metricConfig['field'] ?? ''),
          'value' => null,
          'formatted' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => 'Sin dato',
          'statusKey' => 'gris',
          'statusColor' => '#94a3b8',
          'rangeLabel' => (string)($metricConfig['leyenda'] ?? ''),
          'rule' => (array)($metricConfig['semaforo'] ?? []),
          'history' => [],
        ];
      }
    }
  }
}

$summarizeTunnel = static function (array $tunnel, array $summary, array $rows): array {
  $latestRow = $rows[0] ?? null;
  $fieldRanges = [];
  foreach ((array)($tunnel['campos'] ?? []) as $fieldConfig) {
    $fieldRanges[(string)($fieldConfig['key'] ?? '')] = (string)($fieldConfig['rangeLabel'] ?? '');
  }

  $cells = array_map(static function (array $cell) use ($fieldRanges): array {
    $cell['rangeLabel'] = $fieldRanges[(string)($cell['field'] ?? '')] ?? '';
    return $cell;
  }, (array)($latestRow['cells'] ?? []));
  $total = count($cells);
  $critical = 0;
  $warning = 0;
  $optimal = 0;
  $neutral = 0;
  $actions = [];

  foreach ($cells as $cell) {
    $statusKey = (string)($cell['statusKey'] ?? 'gris');
    if ($statusKey === 'rojo') {
      $critical++;
    } elseif ($statusKey === 'amarillo') {
      $warning++;
    } elseif ($statusKey === 'verde') {
      $optimal++;
    } else {
      $neutral++;
    }

    if (!empty($cell['adjustmentCue']) && $statusKey !== 'verde' && $statusKey !== 'gris') {
      $actions[] = [
        'label' => (string)($cell['label'] ?? 'Variable'),
        'statusLabel' => (string)($cell['statusLabel'] ?? 'Atención'),
        'statusKey' => $statusKey,
        'statusColor' => (string)($cell['statusColor'] ?? '#b094b8'),
        'message' => (string)($cell['adjustmentCue']['label'] ?? ''),
      ];
    }
  }

  if (count($actions) > 2) {
    $actions = array_slice($actions, 0, 2);
  }

  $compliance = $total > 0 ? (($optimal / $total) * 100) : 0.0;
  $statusLabel = 'Óptimo';
  $statusKey = 'verde';
  $statusColor = '#2e8b57';

  if ($critical > 0) {
    $statusLabel = 'Crítico';
    $statusKey = 'rojo';
    $statusColor = '#c94436';
  } elseif ($warning > 0) {
    $statusLabel = 'Atención';
    $statusKey = 'amarillo';
    $statusColor = '#e49a32';
  } elseif ($neutral === $total && $total > 0) {
    $statusLabel = 'Referencia';
    $statusKey = 'gris';
    $statusColor = '#94a3b8';
  }

  return [
    'titulo' => (string)($tunnel['titulo'] ?? 'Túnel'),
    'key' => (string)($tunnel['key'] ?? ''),
    'ultimaLectura' => (string)($summary['ultimaLectura'] ?? '-'),
    'statusLabel' => $statusLabel,
    'statusKey' => $statusKey,
    'statusColor' => $statusColor,
    'cumplimiento' => $compliance,
    'totales' => [
      'monitoreadas' => $total,
      'verdes' => $optimal,
      'alertas' => $warning,
      'criticas' => $critical,
      'grises' => $neutral,
    ],
    'promedioActual' => $summary['promedioActual'] ?? null,
    'maximoActual' => $summary['maximoActual'] ?? null,
    'minimoActual' => $summary['minimoActual'] ?? null,
    'cells' => $cells,
    'actions' => $actions,
  ];
};

$normalizeVotators = static function (array $votatorConfig, array $latestMetricRow) use ($evaluateMetricStatus): array {
  $votators = [];

  foreach ($votatorConfig as $votatorKey => $votator) {
    $fields = [];
    foreach ((array)($votator['campos'] ?? []) as $fieldKey => $field) {
      $value = $field['value'] ?? null;
      $source = (string)($field['source'] ?? 'sqlserver');
      $sourceField = trim((string)($field['field'] ?? ''));
      if ($source === 'sqlserver' && $sourceField !== '' && array_key_exists($sourceField, $latestMetricRow)) {
        $value = $latestMetricRow[$sourceField];
      }

      $numericValue = is_numeric($value) ? (float)$value : null;
      $emptyLabel = (string)($field['empty_label'] ?? 'Pendiente');
      $rule = (array)($field['semaforo'] ?? []);
      [$statusLabel, $statusKey, $statusColor] = !empty($rule)
        ? $evaluateMetricStatus($numericValue, $rule)
        : [
          (string)($field['status_label'] ?? ($numericValue !== null ? 'Lectura' : 'Pendiente')),
          (string)($field['status_key'] ?? ($numericValue !== null ? 'azul' : 'gris')),
          (string)($field['status_color'] ?? ($numericValue !== null ? '#0ea5e9' : '#94a3b8')),
        ];

      $fields[] = [
        'key' => (string)$fieldKey,
        'label' => (string)($field['label'] ?? $fieldKey),
        'unit' => (string)($field['unit'] ?? ''),
        'available' => !empty($field['available']) && $sourceField !== '',
        'field' => $sourceField,
        'value' => $numericValue,
        'formatted' => $numericValue !== null ? n($numericValue, 2) : $emptyLabel,
        'emptyLabel' => $emptyLabel,
        'statusLabel' => $statusLabel,
        'statusKey' => $statusKey,
        'statusColor' => $statusColor,
        'rangeLabel' => (string)($field['leyenda'] ?? ''),
        'rule' => $rule,
        'icon' => (string)($field['icon'] ?? ''),
      ];
    }

    $votators[] = [
      'key' => (string)$votatorKey,
      'label' => (string)($votator['label'] ?? 'Votator'),
      'statusLabel' => (string)($votator['status_label'] ?? 'Visual'),
      'statusKey' => (string)($votator['status_key'] ?? 'gris'),
      'fields' => $fields,
    ];
  }

  return $votators;
};

$executiveTunels = [];
$globalTotals = [
  'monitoreadas' => 0,
  'verdes' => 0,
  'alertas' => 0,
  'criticas' => 0,
  'grises' => 0,
];

foreach (($detailReport['tuneles'] ?? []) as $tunnelKey => $tunnel) {
  $executiveTunels[$tunnelKey] = $summarizeTunnel(
    (array)$tunnel,
    (array)($detailReport['resumenes'][$tunnelKey] ?? []),
    (array)($detailReport['tablas'][$tunnelKey] ?? [])
  );

  $executiveTunels[$tunnelKey]['metricas'] = (array)($metricValuesByTunnel[$tunnelKey] ?? []);
  $executiveTunels[$tunnelKey]['votators'] = $normalizeVotators((array)($votatorConfigByTunnel[$tunnelKey] ?? []), $metricRow);

  foreach ($globalTotals as $metric => $value) {
    $globalTotals[$metric] += (int)($executiveTunels[$tunnelKey]['totales'][$metric] ?? 0);
  }
}

$globalCompliance = $globalTotals['monitoreadas'] > 0
  ? (($globalTotals['verdes'] / $globalTotals['monitoreadas']) * 100)
  : 0.0;

$globalStatusLabel = 'Óptimo';
$globalStatusKey = 'verde';
$globalStatusColor = '#2e8b57';

if ($globalTotals['criticas'] > 0) {
  $globalStatusLabel = 'Crítico';
  $globalStatusKey = 'rojo';
  $globalStatusColor = '#c94436';
} elseif ($globalTotals['alertas'] > 0) {
  $globalStatusLabel = 'Atención';
  $globalStatusKey = 'amarillo';
  $globalStatusColor = '#e49a32';
} elseif ($globalTotals['grises'] === $globalTotals['monitoreadas'] && $globalTotals['monitoreadas'] > 0) {
  $globalStatusLabel = 'Referencia';
  $globalStatusKey = 'gris';
  $globalStatusColor = '#94a3b8';
}

$meta = (array)($detailReport['meta'] ?? []);
$meta['intervaloActualizacionRapida'] = max(15000, (int)($config['intervalo_actualizacion_rapida_ms'] ?? 60000));

return [
  'titulo' => (string)($config['titulo'] ?? 'Secadores'),
  'meta' => $meta,
  'warnings' => (array)(($detailReport['meta'] ?? [])['warnings'] ?? []),
  'tuneles' => $executiveTunels,
  'global' => [
    'cumplimiento' => $globalCompliance,
    'statusLabel' => $globalStatusLabel,
    'statusKey' => $globalStatusKey,
    'statusColor' => $globalStatusColor,
    'totales' => $globalTotals,
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/../secadores-temperatura/build_report.php') ?: time(),
    @filemtime(__DIR__ . '/../secadores-temperatura/config.php') ?: time()
  ),
];
