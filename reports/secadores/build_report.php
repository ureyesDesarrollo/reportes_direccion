<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';

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

$quoteSqlIdentifier = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Identificador SQL inválido: ' . $name);
  }

  return '[' . $name . ']';
};

$evaluateStatus = static function ($value, array $rule): array {
  if ($value === null || $value === '' || !is_numeric($value)) {
    return ['Sin dato', 'gris', '#94a3b8'];
  }

  $number = (float)$value;
  $mode = (string)($rule['modo'] ?? 'rango');
  $greenMin = isset($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) ? (float)$rule['verde_max'] : null;
  $yellowMin = isset($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  $hasAnyLimit = $greenMin !== null || $greenMax !== null || $yellowMin !== null || $yellowMax !== null;
  if (!$hasAnyLimit) {
    return ['Sin límite', 'gris', '#94a3b8'];
  }

  if ($mode === 'minimo') {
    if ($greenMin !== null && $number >= $greenMin) {
      return ['Óptimo', 'verde', '#10b981'];
    }
    if ($yellowMin !== null && $number >= $yellowMin) {
      return ['Cuidado', 'amarillo', '#f59e0b'];
    }
    return ['Crítico', 'rojo', '#ef4444'];
  }

  if ($mode === 'maximo') {
    if ($greenMax !== null && $number <= $greenMax) {
      return ['Óptimo', 'verde', '#10b981'];
    }
    if ($yellowMax !== null && $number <= $yellowMax) {
      return ['Cuidado', 'amarillo', '#f59e0b'];
    }
    return ['Crítico', 'rojo', '#ef4444'];
  }

  $withinGreen = ($greenMin === null || $number >= $greenMin) && ($greenMax === null || $number <= $greenMax);
  if ($withinGreen) {
    return ['Óptimo', 'verde', '#10b981'];
  }

  $withinYellow = ($yellowMin === null || $number >= $yellowMin) && ($yellowMax === null || $number <= $yellowMax);
  if ($withinYellow) {
    return ['Cuidado', 'amarillo', '#f59e0b'];
  }

  return ['Crítico', 'rojo', '#ef4444'];
};

$buildTunnelBands = static function (array $fields): array {
  $greenMins = [];
  $greenMaxs = [];
  $yellowMins = [];
  $yellowMaxs = [];
  $mode = 'rango';

  foreach ($fields as $fieldDef) {
    $rule = (array)($fieldDef['semaforo'] ?? []);
    $mode = (string)($rule['modo'] ?? $mode);

    if (isset($rule['verde_min']) && is_numeric($rule['verde_min'])) {
      $greenMins[] = (float)$rule['verde_min'];
    }
    if (isset($rule['verde_max']) && is_numeric($rule['verde_max'])) {
      $greenMaxs[] = (float)$rule['verde_max'];
    }
    if (isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min'])) {
      $yellowMins[] = (float)$rule['amarillo_min'];
    }
    if (isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max'])) {
      $yellowMaxs[] = (float)$rule['amarillo_max'];
    }
  }

  $avg = static function (array $values): ?float {
    return empty($values) ? null : array_sum($values) / count($values);
  };

  $greenMin = $avg($greenMins);
  $greenMax = $avg($greenMaxs);
  $yellowMin = $avg($yellowMins);
  $yellowMax = $avg($yellowMaxs);

  return [
    'mode' => $mode,
    'greenMin' => $greenMin,
    'greenMax' => $greenMax,
    'yellowMin' => $yellowMin,
    'yellowMax' => $yellowMax,
    'hasBands' => $greenMin !== null || $greenMax !== null || $yellowMin !== null || $yellowMax !== null,
  ];
};

$palette = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16'];

$titulo = (string)($config['titulo'] ?? 'Secadores');
$fechaDesde = (string)($config['fecha_desde'] ?? date('Y-m-d'));
$intervaloActualizacion = (int)($config['intervalo_actualizacion_ms'] ?? 5000);
$limiteRegistros = max(10, (int)($config['limite_registros'] ?? 120));
$intervaloMuestreoMinutos = max(1, (int)($config['intervalo_muestreo_minutos'] ?? 30));
$timezoneName = (string)($config['timezone'] ?? 'America/Mexico_City');
$reportTimezone = new DateTimeZone($timezoneName);
$formatoFecha = (string)($config['formato_fecha'] ?? 'Y-m-d H:i:s');
$formatoFechaGrafica = (string)($config['formato_fecha_grafica'] ?? 'H:i:s');
$tabla = (string)($config['tabla'] ?? 'TREND001');
$campoFecha = (string)($config['campo_fecha'] ?? 'DateTime');
$tunelesConfig = (array)($config['tuneles'] ?? []);
$tunelSeleccionado = (string)($_GET['tunel'] ?? ($config['tunel_default'] ?? array_key_first($tunelesConfig)));

if (!isset($tunelesConfig[$tunelSeleccionado])) {
  $tunelSeleccionado = (string)array_key_first($tunelesConfig);
}

$warnings = [];
$rows = [];

$allFields = [];
foreach ($tunelesConfig as $tunel) {
  foreach (array_keys((array)($tunel['campos'] ?? [])) as $fieldName) {
    $allFields[$fieldName] = $fieldName;
  }
}

$sqlCfg = (array)($config['sqlserver'] ?? []);
$serverConfigured = trim((string)($sqlCfg['server'] ?? '')) !== '';

if (!$serverConfigured) {
  $warnings[] = 'Configura la conexión SQL Server en reports/secadores/config.php para consultar AVEVA_TAGS.TREND001.';
} else {
  try {
    $pdo = $connectSqlServer($sqlCfg);

    $safeTable = $quoteSqlIdentifier($tabla);
    $safeTimestamp = $quoteSqlIdentifier($campoFecha);
    $safeLimit = max($limiteRegistros, min(5000, $limiteRegistros * $intervaloMuestreoMinutos));

    $selectParts = [$safeTimestamp . ' AS [__timestamp]'];
    foreach ($allFields as $fieldName) {
      $selectParts[] = $quoteSqlIdentifier($fieldName);
    }

    $sql = 'SELECT TOP (' . $safeLimit . ') ' . implode(', ', $selectParts)
      . ' FROM ' . $safeTable
      . ' ORDER BY ' . $safeTimestamp . ' DESC';

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    $warnings[] = 'No fue posible consultar SQL Server: ' . $e->getMessage();
  }
}

$normalizeTimestamp = static function ($value) use ($reportTimezone): ?DateTimeImmutable {
  if ($value instanceof DateTimeInterface) {
    try {
      $baseTimezone = $value->getTimezone() ?: $reportTimezone;
      $immutable = new DateTimeImmutable($value->format('Y-m-d H:i:s.u'), $baseTimezone);
      return $immutable->setTimezone($reportTimezone);
    } catch (Throwable $e) {
      return null;
    }
  }

  if (is_numeric($value)) {
    try {
      $numeric = (string)$value;
      $timezone = new DateTimeZone(date_default_timezone_get());
      $minUnixSeconds = 946684800; // 2000-01-01 00:00:00
      $maxUnixSeconds = 4102444800; // 2100-01-01 00:00:00
      $trimmedNumeric = ltrim($numeric, '-');

      if (strpos($numeric, '.') !== false) {
        $numeric = strtok($numeric, '.');
        $trimmedNumeric = ltrim($numeric, '-');
      }

      if (strlen($trimmedNumeric) >= 13) {
        $milliseconds = (int)$numeric;
        if ($milliseconds < ($minUnixSeconds * 1000) || $milliseconds > ($maxUnixSeconds * 1000)) {
          return null;
        }
        $seconds = (int)floor($milliseconds / 1000);
        return (new DateTimeImmutable('@' . $seconds))->setTimezone($timezone);
      }

      // Formato compacto HHMMSSmmm o variantes recortadas; usar fecha actual.
      if (strlen($trimmedNumeric) >= 3 && strlen($trimmedNumeric) <= 9) {
        $padded = str_pad($trimmedNumeric, 9, '0', STR_PAD_LEFT);
        $hour = (int)substr($padded, 0, 2);
        $minute = (int)substr($padded, 2, 2);
        $second = (int)substr($padded, 4, 2);
        $millisecond = (int)substr($padded, 6, 3);

        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59 && $millisecond >= 0 && $millisecond <= 999) {
          $today = new DateTimeImmutable('now', $timezone);
          return $today
            ->setTime($hour, $minute, $second, $millisecond * 1000);
        }
      }

      $seconds = (int)$numeric;
      if ($seconds < $minUnixSeconds || $seconds > $maxUnixSeconds) {
        return null;
      }

      return (new DateTimeImmutable('@' . $seconds))->setTimezone($timezone);
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

$formatTimestamp = static function ($value, string $format) use ($normalizeTimestamp): string {
  $normalized = $normalizeTimestamp($value);
  if ($normalized !== null) {
    return $normalized->format($format);
  }

  return is_scalar($value) && $value !== '' ? (string)$value : '-';
};

$floorTimestampToInterval = static function ($value, int $minutes) use ($normalizeTimestamp, $reportTimezone): ?DateTimeImmutable {
  $normalized = $normalizeTimestamp($value);
  if ($normalized === null) {
    return null;
  }

  $minutes = max(1, $minutes);
  $hour = (int)$normalized->format('H');
  $minute = (int)$normalized->format('i');
  $bucketMinute = (int)(floor($minute / $minutes) * $minutes);

  return $normalized
    ->setTimezone($reportTimezone)
    ->setTime($hour, $bucketMinute, 0);
};

$bucketRows = [];
foreach ($rows as $row) {
  $bucketTimestamp = $floorTimestampToInterval($row['__timestamp'] ?? null, $intervaloMuestreoMinutos);
  if ($bucketTimestamp === null) {
    continue;
  }

  $bucketKey = $bucketTimestamp->format('Y-m-d H:i:s');
  if (!isset($bucketRows[$bucketKey])) {
    $row['__bucket_timestamp'] = $bucketTimestamp;
    $bucketRows[$bucketKey] = $row;
  }
}

$rows = array_slice(array_values($bucketRows), 0, $limiteRegistros);

$resumenes = [];
$tablas = [];
$charts = [];
$tuneles = [];

foreach ($tunelesConfig as $tunelKey => $tunelDef) {
  $campos = (array)($tunelDef['campos'] ?? []);
  $tuneles[$tunelKey] = [
    'key' => $tunelKey,
    'titulo' => (string)($tunelDef['titulo'] ?? $tunelKey),
    'campos' => array_map(
      static fn(string $fieldKey, array $fieldDef): array => [
        'key' => $fieldKey,
        'label' => (string)($fieldDef['label'] ?? $fieldKey),
      ],
      array_keys($campos),
      array_values($campos)
    ),
  ];

  $tablaRows = [];
  $latestValues = [];
  $latestTimestamp = '-';

  foreach ($rows as $rowIndex => $row) {
    $timestampValue = $row['__bucket_timestamp'] ?? ($row['__timestamp'] ?? null);
    $timestamp = $formatTimestamp($timestampValue, $formatoFecha);
    $chartTimestamp = $formatTimestamp($timestampValue, $formatoFechaGrafica);
    if ($rowIndex === 0) {
      $latestTimestamp = $timestamp;
    }

    $cells = [];
    foreach ($campos as $fieldKey => $fieldDef) {
      $rawValue = $row[$fieldKey] ?? null;
      $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
      [$statusLabel, $statusKey, $statusColor] = $evaluateStatus($numericValue, (array)($fieldDef['semaforo'] ?? []));

      $cell = [
        'field' => $fieldKey,
        'label' => (string)($fieldDef['label'] ?? $fieldKey),
        'value' => $numericValue,
        'formatted' => $numericValue !== null ? n($numericValue, 2) : '-',
        'statusLabel' => $statusLabel,
        'statusKey' => $statusKey,
        'statusColor' => $statusColor,
      ];

      if ($rowIndex === 0) {
        $latestValues[] = $cell;
      }

      $cells[] = $cell;
    }

    $tablaRows[] = [
      'timestamp' => $timestamp,
      'chartTimestamp' => $chartTimestamp,
      'cells' => $cells,
    ];
  }

  $numericLatest = array_values(array_filter(array_map(
    static fn(array $item): ?float => isset($item['value']) && $item['value'] !== null ? (float)$item['value'] : null,
    $latestValues
  ), static fn($value): bool => $value !== null));

  $countByState = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'gris' => 0];
  foreach ($latestValues as $item) {
    $stateKey = $item['statusKey'] ?? 'gris';
    if (!isset($countByState[$stateKey])) {
      $countByState[$stateKey] = 0;
    }
    $countByState[$stateKey]++;
  }

  $resumenes[$tunelKey] = [
    'ultimaLectura' => $latestTimestamp,
    'variablesMonitoreadas' => count($campos),
    'enVerde' => $countByState['verde'] ?? 0,
    'enAlerta' => $countByState['amarillo'] ?? 0,
    'enCritico' => $countByState['rojo'] ?? 0,
    'sinLimite' => $countByState['gris'] ?? 0,
    'promedioActual' => !empty($numericLatest) ? array_sum($numericLatest) / count($numericLatest) : null,
    'maximoActual' => !empty($numericLatest) ? max($numericLatest) : null,
    'minimoActual' => !empty($numericLatest) ? min($numericLatest) : null,
  ];

  $tablas[$tunelKey] = $tablaRows;

  $chartLabels = [];
  $datasets = [];

  $rowsAsc = array_reverse($tablaRows);
  foreach ($rowsAsc as $row) {
    $chartLabels[] = $row['chartTimestamp'];
  }

  $colorIndex = 0;
  foreach ($campos as $fieldKey => $fieldDef) {
    $color = $palette[$colorIndex % count($palette)];
    $dataset = [
      'label' => (string)($fieldDef['label'] ?? $fieldKey),
      'data' => [],
      'borderColor' => $color,
      'backgroundColor' => $color,
      'borderWidth' => 2,
      'pointRadius' => 0,
      'pointHoverRadius' => 4,
      'tension' => 0.25,
    ];

    foreach ($rowsAsc as $row) {
      $matched = null;
      foreach ($row['cells'] as $cell) {
        if ($cell['field'] === $fieldKey) {
          $matched = $cell['value'];
          break;
        }
      }
      $dataset['data'][] = $matched;
    }

    $datasets[] = $dataset;
    $colorIndex++;
  }

  $charts[$tunelKey] = [
    'labels' => $chartLabels,
    'datasets' => $datasets,
    'bands' => $buildTunnelBands($campos),
  ];
}

$meta = [
  'cardsPorPagina' => 9,
  'filasPorPagina' => 15,
  'intervaloActualizacion' => $intervaloActualizacion,
  'limiteRegistros' => $limiteRegistros,
  'fechaDesde' => $fechaDesde,
  'warnings' => $warnings,
  'tabla' => $tabla,
  'baseDatos' => (string)($sqlCfg['database'] ?? 'AVEVA_TAGS'),
  'campoFecha' => $campoFecha,
  'timezone' => $timezoneName,
  'formatoFecha' => $formatoFecha,
  'formatoFechaGrafica' => $formatoFechaGrafica,
  'intervaloMuestreoMinutos' => $intervaloMuestreoMinutos,
  'tunelSeleccionado' => $tunelSeleccionado,
];

return [
  'titulo' => $titulo,
  'fechaDesde' => $fechaDesde,
  'tuneles' => $tuneles,
  'tunelSeleccionado' => $tunelSeleccionado,
  'resumenes' => $resumenes,
  'tablas' => $tablas,
  'charts' => $charts,
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
