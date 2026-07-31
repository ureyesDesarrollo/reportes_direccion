<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$warnings = [];

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
    PDO::ATTR_TIMEOUT => (int)($cfg['timeout'] ?? 3),
  ]);
};


$quoteSqlIdentifier = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Identificador SQL inválido: ' . $name);
  }

  return '[' . $name . ']';
};

$quoteMysqlIdentifier = static function (string $name): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
    throw new InvalidArgumentException('Identificador MySQL inválido: ' . $name);
  }

  return '`' . $name . '`';
};

$historyLimit = max(300, (int)($config['tendencia_limite_registros'] ?? 5000));
$sqlServerHistoryLimit = max(1440, (int)($config['tendencia_limite_sqlserver'] ?? 5000));
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

$evaluateMetricStatus = static function (?float $value, array $rule): array {
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
      'verde' => ['Óptimo', 'verde', '#2e8b57'],
      'amarillo' => ['Atención', 'amarillo', '#facc15'],
      'dorado' => ['Dorado', 'dorado', '#d4a017'],
      'rojo' => ['Crítico', 'rojo', '#c94436'],
      'gris' => ['Sin dato', 'gris', '#94a3b8'],
      'azul' => ['Azul', 'azul', '#2563eb'],
    ];

    foreach ((array)($rule['bandas'] ?? []) as $band) {
      $min = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
      $max = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
      $status = (string)($band['estado'] ?? 'gris');

      if (($min === null || $value >= $min) && ($max === null || $value <= $max)) {
        return $statusMap[$status] ?? $statusMap['gris'];
      }
    }

    return ['Crítico', 'rojo', '#c94436'];
  }

  if ($mode === 'minimo') {
    if ($greenMin !== null && $value >= $greenMin) {
      return ['Óptimo', 'verde', '#2e8b57'];
    }

    if ($yellowMin !== null && $value >= $yellowMin) {
      return ['Atención', 'amarillo', '#facc15'];
    }

    return ['Crítico', 'rojo', '#c94436'];
  }

  if ($mode === 'maximo') {
    if ($greenMax !== null && $value <= $greenMax) {
      return ['Óptimo', 'verde', '#2e8b57'];
    }

    if ($yellowMax !== null && $value <= $yellowMax) {
      return ['Atención', 'amarillo', '#facc15'];
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
        return ['Atención', 'amarillo', '#facc15'];
      }
    }
  }

  return ['Crítico', 'rojo', '#c94436'];
};

$normalizeStatusText = static function (string $value): string {
  $value = mb_strtolower(trim($value), 'UTF-8');
  return strtr($value, [
    'á' => 'a',
    'é' => 'e',
    'í' => 'i',
    'ó' => 'o',
    'ú' => 'u',
    'ü' => 'u',
    'Á' => 'a',
    'É' => 'e',
    'Í' => 'i',
    'Ó' => 'o',
    'Ú' => 'u',
    'Ü' => 'u',
  ]);
};

$evaluateTextMetricStatus = static function (string $value, array $rule) use ($normalizeStatusText): array {
  $value = $normalizeStatusText($value);
  if ($value === '') {
    return ['Sin dato', 'gris', '#94a3b8'];
  }

  $statusMap = [
    'verde' => ['Óptimo', 'verde', '#2e8b57'],
    'amarillo' => ['Atención', 'amarillo', '#facc15'],
    'rojo' => ['Crítico', 'rojo', '#c94436'],
  ];

  foreach (['verde', 'amarillo', 'rojo'] as $status) {
    foreach ((array)($rule[$status] ?? []) as $expected) {
      if ($value === $normalizeStatusText((string)$expected)) {
        return $statusMap[$status];
      }
    }
  }

  return ['Lectura', 'azul', '#ffffff'];
};

$formatRangeValue = static function ($value): string {
  if (!is_numeric($value)) {
    return trim((string)$value);
  }

  return rtrim(rtrim(n((float)$value, 2), '0'), '.');
};

$buildSemaphoreRangeLabel = static function (array $rule, string $unit = '', string $fallback = '') use ($formatRangeValue): string {
  if ($rule === []) {
    return $fallback;
  }

  $mode = (string)($rule['modo'] ?? 'rango');
  $parts = [];

  if ($mode === 'bandas') {
    $bands = (array)($rule['bandas'] ?? []);
    usort($bands, static function (array $a, array $b): int {
      $aMin = isset($a['min']) && is_numeric($a['min']) ? (float)$a['min'] : -INF;
      $bMin = isset($b['min']) && is_numeric($b['min']) ? (float)$b['min'] : -INF;
      return $aMin <=> $bMin;
    });

    foreach ($bands as $band) {
      $status = ucfirst((string)($band['estado'] ?? 'Rango'));
      $hasMin = isset($band['min']) && is_numeric($band['min']);
      $hasMax = isset($band['max']) && is_numeric($band['max']);

      if (!$hasMin && $hasMax) {
        $parts[] = $status . ' <' . $formatRangeValue($band['max']);
      } elseif ($hasMin && !$hasMax) {
        $parts[] = $status . ' >' . $formatRangeValue($band['min']);
      } elseif ($hasMin && $hasMax) {
        $parts[] = $status . ' ' . $formatRangeValue($band['min']) . '-' . $formatRangeValue($band['max']);
      }
    }
  } elseif ($mode === 'texto') {
    foreach (['verde' => 'Verde', 'amarillo' => 'Amarillo', 'rojo' => 'Rojo'] as $key => $label) {
      $values = array_values(array_filter(array_map('trim', array_map('strval', (array)($rule[$key] ?? [])))));
      if ($values !== []) {
        $parts[] = $label . ' ' . implode(', ', $values);
      }
    }
  } elseif ($mode === 'rango') {
    $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
    $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
    $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
    $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

    if ($greenMin !== null && $greenMax !== null) {
      if ($yellowMin !== null && $yellowMin < $greenMin) {
        $parts[] = 'Rojo <' . $formatRangeValue($yellowMin);
        $parts[] = 'Amarillo ' . $formatRangeValue($yellowMin) . '-' . $formatRangeValue($greenMin);
      } else {
        $parts[] = 'Rojo <' . $formatRangeValue($greenMin);
      }

      $parts[] = 'Verde ' . $formatRangeValue($greenMin) . '-' . $formatRangeValue($greenMax);

      if ($yellowMax !== null && $yellowMax > $greenMax) {
        $parts[] = 'Amarillo ' . $formatRangeValue($greenMax) . '-' . $formatRangeValue($yellowMax);
        $parts[] = 'Rojo >' . $formatRangeValue($yellowMax);
      } else {
        $parts[] = 'Rojo >' . $formatRangeValue($greenMax);
      }
    }
  } elseif ($mode === 'minimo') {
    $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
    $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;

    if ($yellowMin !== null && $greenMin !== null) {
      $parts[] = 'Rojo <' . $formatRangeValue($yellowMin);
      $parts[] = 'Amarillo ' . $formatRangeValue($yellowMin) . '-' . $formatRangeValue($greenMin);
      $parts[] = 'Verde >=' . $formatRangeValue($greenMin);
    } elseif ($greenMin !== null) {
      $parts[] = 'Rojo <' . $formatRangeValue($greenMin);
      $parts[] = 'Verde >=' . $formatRangeValue($greenMin);
    }
  } elseif ($mode === 'maximo') {
    $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
    $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

    if ($greenMax !== null && $yellowMax !== null) {
      $parts[] = 'Verde <' . $formatRangeValue($greenMax);
      $parts[] = 'Amarillo ' . $formatRangeValue($greenMax) . '-' . $formatRangeValue($yellowMax);
      $parts[] = 'Rojo >' . $formatRangeValue($yellowMax);
    }
  }

  if ($parts === []) {
    return $fallback;
  }

  $label = implode(' | ', $parts);
  $unit = trim($unit);
  return $unit !== '' ? $label . ' ' . $unit : $label;
};

$applyMetricFormula = static function (?float $value, array $metricConfig): ?float {
  if ($value === null) {
    return null;
  }

  $formula = (array)($metricConfig['formula'] ?? []);
  $type = (string)($formula['tipo'] ?? '');

  if ($type === 'factor' && isset($formula['factor']) && is_numeric($formula['factor'])) {
    return $value * (float)$formula['factor'];
  }

  return $value;
};

$metricConfigByTunnel = (array)($config['metricas_por_tunel'] ?? []);
$votatorConfigByTunnel = (array)($config['votators_por_tunel'] ?? []);

$applyCentralHumidityRanges = static function (array $metricConfigByTunnel, array $monitorConfig): array {
  $rangeTunnels = array_flip((array)($monitorConfig['humedad_rangos_tuneles'] ?? []));
  $roomRanges = (array)($monitorConfig['humedad_rangos_recamaras'] ?? []);

  foreach ($metricConfigByTunnel as $tunnelKey => &$metricGroup) {
    if (!isset($rangeTunnels[(string)$tunnelKey])) {
      continue;
    }

    foreach ($metricGroup as $metricKey => &$metricConfig) {
      if (mb_strtolower((string)($metricConfig['group'] ?? ''), 'UTF-8') !== 'humedades') {
        continue;
      }

      $label = (string)($metricConfig['label'] ?? '');
      $roomNumber = null;
      if (preg_match('/(?:zona|recamara)_(\d+)/i', (string)$metricKey, $matches) === 1) {
        $roomNumber = (int)$matches[1];
      } elseif (preg_match('/(?:zona|rec[aá]mara|rec)\s*(\d+)/iu', $label, $matches) === 1) {
        $roomNumber = (int)$matches[1];
      }

      if ($roomNumber === null) {
        continue;
      }

      $range = (array)($roomRanges[$roomNumber] ?? []);
      if ($range === []) {
        continue;
      }

      $greenLt = isset($range['verde_lt']) && is_numeric($range['verde_lt']) ? (float)$range['verde_lt'] : null;
      $yellowMax = isset($range['amarillo_max']) && is_numeric($range['amarillo_max']) ? (float)$range['amarillo_max'] : null;
      if ($greenLt === null || $yellowMax === null) {
        continue;
      }

      $metricConfig['semaforo'] = [
        'modo' => 'maximo',
        'verde_max' => $greenLt - 0.000001,
        'amarillo_max' => $yellowMax,
      ];
      $metricConfig['leyenda'] = (string)($range['label'] ?? ('< ' . $greenLt . ' ' . (string)($metricConfig['unit'] ?? '')));
    }
    unset($metricConfig);
  }
  unset($metricGroup);

  return $metricConfigByTunnel;
};

$metricConfigByTunnel = $applyCentralHumidityRanges(
  $metricConfigByTunnel,
  (array)($config['monitoreo_produccion'] ?? [])
);

$metricValuesByTunnel = [];
$topIndicators = [];
$metricRow = [];
$metricHistoryRows = [];
$metricWeekHistoryByField = [];
$metricMonthHistoryByField = [];
$metricFields = [];
$mysqlMetricLookups = [];

foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
  foreach ((array)$metricGroup as $metricKey => $metricConfig) {
    $field = trim((string)($metricConfig['field'] ?? ''));
    $source = (string)($metricConfig['source'] ?? 'sqlserver');
    if ($field !== '' && $source === 'sqlserver') {
      $metricFields[$field] = $field;
    }
    if (in_array($source, ['mysql_secadores', 'mysql_verificacion_secado'], true)) {
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
      . ' WHERE CAST(' . $safeTimestamp . ' AS date) = CAST(GETDATE() AS date)'
      . ' ORDER BY ' . $safeTimestamp . ' DESC';

    $metricRow = $pdo->query($sql)->fetch() ?: [];

    $sqlHistory = 'WITH tendencia AS ('
      . ' SELECT ' . implode(', ', $selectParts)
      . ', ROW_NUMBER() OVER (PARTITION BY DATEDIFF(minute, 0, ' . $safeTimestamp . ') ORDER BY ' . $safeTimestamp . ' DESC) AS [__minute_rn]'
      . ' FROM ' . $safeTable
      . ' WHERE ' . $safeTimestamp . ' >= DATEADD(day, -31, GETDATE())'
      . ') SELECT TOP (' . $sqlServerHistoryLimit . ') * FROM tendencia'
      . ' WHERE [__minute_rn] = 1'
      . ' ORDER BY [__timestamp] DESC';
    $metricHistoryRows = $pdo->query($sqlHistory)->fetchAll() ?: [];

    $dailyAverageSelectParts = [
      'CAST(' . $safeTimestamp . ' AS date) AS [__timestamp]',
    ];
    foreach ($metricFields as $fieldName) {
      $dailyAverageSelectParts[] = 'AVG(TRY_CONVERT(float, ' . $quoteSqlIdentifier($fieldName) . ')) AS ' . $quoteSqlIdentifier($fieldName);
    }

    $sqlWeekHistory = 'SELECT ' . implode(', ', $dailyAverageSelectParts)
      . ' FROM ' . $safeTable
      . ' WHERE ' . $safeTimestamp . ' >= DATEADD(day, -7, GETDATE())'
      . ' GROUP BY CAST(' . $safeTimestamp . ' AS date)'
      . ' ORDER BY [__timestamp] DESC';
    $metricWeekHistoryRows = $pdo->query($sqlWeekHistory)->fetchAll() ?: [];

    foreach ($metricFields as $fieldName) {
      $history = [];
      foreach ($metricWeekHistoryRows as $historyRow) {
        $historyValue = $historyRow[$fieldName] ?? null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $history[] = [
          'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
          'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
      $metricWeekHistoryByField[$fieldName] = $history;
    }

    $averageSelectParts = [
      'DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0) AS [__timestamp]',
    ];
    foreach ($metricFields as $fieldName) {
      $averageSelectParts[] = 'AVG(TRY_CONVERT(float, ' . $quoteSqlIdentifier($fieldName) . ')) AS ' . $quoteSqlIdentifier($fieldName);
    }

    $sqlMonthHistory = 'SELECT ' . implode(', ', $averageSelectParts)
      . ' FROM ' . $safeTable
      . ' WHERE ' . $safeTimestamp . ' >= DATEADD(month, DATEDIFF(month, 0, GETDATE()), 0)'
      . ' AND ' . $safeTimestamp . ' < DATEADD(month, DATEDIFF(month, 0, GETDATE()) + 1, 0)'
      . ' GROUP BY DATEADD(week, DATEDIFF(week, 0, ' . $safeTimestamp . '), 0)'
      . ' ORDER BY [__timestamp] DESC';
    $metricMonthHistoryRows = $pdo->query($sqlMonthHistory)->fetchAll() ?: [];

    foreach ($metricFields as $fieldName) {
      $history = [];
      foreach ($metricMonthHistoryRows as $historyRow) {
        $historyValue = $historyRow[$fieldName] ?? null;
        $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
        $history[] = [
          'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
          'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
          'value' => $historyNumericValue,
          'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
        ];
      }
      $metricMonthHistoryByField[$fieldName] = $history;
    }

    foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
      foreach ((array)$metricGroup as $metricKey => $metricConfig) {
        $field = trim((string)($metricConfig['field'] ?? ''));
        $value = ($field !== '' && array_key_exists($field, $metricRow)) ? $metricRow[$field] : null;
        $numericValue = is_numeric($value) ? $applyMetricFormula((float)$value, (array)$metricConfig) : null;
        $rule = (array)($metricConfig['semaforo'] ?? []);
        [$statusLabel, $statusKey, $statusColor] = !empty($rule)
          ? $evaluateMetricStatus($numericValue, $rule)
          : [($numericValue !== null ? 'Lectura' : 'Sin dato'), ($numericValue !== null ? 'azul' : 'gris'), ($numericValue !== null ? '#ffffff' : '#94a3b8')];
        $history = [];

        if ($field !== '') {
          foreach ($metricHistoryRows as $historyRow) {
            $historyValue = $historyRow[$field] ?? null;
            $historyNumericValue = is_numeric($historyValue) ? $applyMetricFormula((float)$historyValue, (array)$metricConfig) : null;
            $history[] = [
              'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
              'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
              'value' => $historyNumericValue,
              'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
            ];
          }
        }
        $monthHistory = [];
        $weekHistory = [];
        if ($field !== '') {
          foreach ((array)($metricWeekHistoryByField[$field] ?? []) as $historyPoint) {
            $historyNumericValue = isset($historyPoint['value']) && is_numeric($historyPoint['value'])
              ? $applyMetricFormula((float)$historyPoint['value'], (array)$metricConfig)
              : null;
            $weekHistory[] = [
              'timestamp' => (string)($historyPoint['timestamp'] ?? '-'),
              'iso' => (string)($historyPoint['iso'] ?? ''),
              'value' => $historyNumericValue,
              'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
            ];
          }

          foreach ((array)($metricMonthHistoryByField[$field] ?? []) as $historyPoint) {
            $historyNumericValue = isset($historyPoint['value']) && is_numeric($historyPoint['value'])
              ? $applyMetricFormula((float)$historyPoint['value'], (array)$metricConfig)
              : null;
            $monthHistory[] = [
              'timestamp' => (string)($historyPoint['timestamp'] ?? '-'),
              'iso' => (string)($historyPoint['iso'] ?? ''),
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
          'source' => (string)($metricConfig['source'] ?? 'sqlserver'),
          'hidden' => !empty($metricConfig['hidden']),
          'value' => $numericValue,
          'formatted' => $numericValue !== null ? n($numericValue, 2) : '-',
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => $statusLabel,
          'statusKey' => $statusKey,
          'statusColor' => $statusColor,
          'rangeLabel' => $buildSemaphoreRangeLabel($rule, (string)($metricConfig['unit'] ?? ''), (string)($metricConfig['leyenda'] ?? '')),
          'rule' => $rule,
          'history' => $history,
          'trends' => [
            'week' => $weekHistory,
            'month' => $monthHistory,
          ],
        ];
      }
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudieron leer metricas de secadores desde SQL Server: ' . $e->getMessage();
    foreach ($metricConfigByTunnel as $tunnelKey => $metricGroup) {
      foreach ((array)$metricGroup as $metricKey => $metricConfig) {
        $metricValuesByTunnel[$tunnelKey][$metricKey] = [
          'key' => $metricKey,
          'group' => (string)($metricConfig['group'] ?? 'General'),
          'label' => (string)($metricConfig['label'] ?? $metricKey),
          'unit' => (string)($metricConfig['unit'] ?? ''),
          'available' => false,
          'field' => (string)($metricConfig['field'] ?? ''),
          'source' => (string)($metricConfig['source'] ?? 'sqlserver'),
          'hidden' => !empty($metricConfig['hidden']),
          'value' => null,
          'formatted' => '-',
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => 'Sin dato',
          'statusKey' => 'gris',
          'statusColor' => '#94a3b8',
          'rangeLabel' => $buildSemaphoreRangeLabel((array)($metricConfig['semaforo'] ?? []), (string)($metricConfig['unit'] ?? ''), (string)($metricConfig['leyenda'] ?? '')),
          'rule' => (array)($metricConfig['semaforo'] ?? []),
          'history' => [],
        ];
      }
    }
  }
}

if (!empty($mysqlMetricLookups)) {
  try {
    $mysqlConnections = [];

    foreach ($mysqlMetricLookups as $tunnelKey => $metricGroup) {
      foreach ($metricGroup as $metricKey => $metricConfig) {
        $source = (string)($metricConfig['source'] ?? 'mysql_secadores');
        $connectionKey = $source === 'mysql_verificacion_secado' ? 'mysql_verificacion_secado' : 'mysql_secadores';
        if (!isset($mysqlConnections[$connectionKey])) {
          $mysqlConnections[$connectionKey] = $connectMysql((array)($config[$connectionKey] ?? []));
        }
        $pdoMysql = $mysqlConnections[$connectionKey];

        $lookup = (array)($metricConfig['lookup'] ?? []);
        $table = (string)($lookup['table'] ?? '');
        $keyColumn = (string)($lookup['key_column'] ?? '');
        $keyValue = (string)($lookup['key_value'] ?? '');
        $timestampColumn = (string)($lookup['timestamp_column'] ?? 'fecha_hora');
        $lookupDate = trim((string)($lookup['date'] ?? ''));
        $useDateFilter = array_key_exists('date_filter', $lookup) ? (bool)$lookup['date_filter'] : true;
        $field = trim((string)($metricConfig['field'] ?? ''));

        if ($table === '' || $keyColumn === '' || $keyValue === '' || $field === '') {
          continue;
        }

        $safeField = $quoteMysqlIdentifier($field);
        $safeTimestampColumn = $quoteMysqlIdentifier($timestampColumn);
        $safeTable = $quoteMysqlIdentifier($table);
        $safeKeyColumn = $quoteMysqlIdentifier($keyColumn);
        $orderColumns = (array)($lookup['order_columns'] ?? [$timestampColumn]);
        $orderParts = [];
        foreach ($orderColumns as $orderColumn) {
          $orderParts[] = $quoteMysqlIdentifier((string)$orderColumn) . ' DESC';
        }
        $orderSql = implode(', ', $orderParts !== [] ? $orderParts : [$safeTimestampColumn . ' DESC']);
        $dateSql = '';
        if ($useDateFilter) {
          $dateSql = $lookupDate !== '' ? ' AND DATE(' . $safeTimestampColumn . ') = :lookup_date' : ' AND DATE(' . $safeTimestampColumn . ') = CURDATE()';
        }
        $sql = sprintf(
          'SELECT %s AS metric_value, %s AS metric_timestamp FROM %s WHERE %s = :key_value%s ORDER BY %s LIMIT 1',
          $safeField,
          $safeTimestampColumn,
          $safeTable,
          $safeKeyColumn,
          $dateSql,
          $orderSql
        );

        $stmt = $pdoMysql->prepare($sql);
        $params = ['key_value' => $keyValue];
        if ($lookupDate !== '') {
          $params['lookup_date'] = $lookupDate;
        }
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];

        $sqlHistory = sprintf(
          'SELECT %s AS metric_value, %s AS metric_timestamp FROM %s WHERE %s = :key_value AND %s >= DATE_SUB(NOW(), INTERVAL 31 DAY) ORDER BY %s LIMIT %d',
          $safeField,
          $safeTimestampColumn,
          $safeTable,
          $safeKeyColumn,
          $safeTimestampColumn,
          $orderSql,
          $historyLimit
        );
        $stmtHistory = $pdoMysql->prepare($sqlHistory);
        $stmtHistory->execute(['key_value' => $keyValue]);
        $historyRows = $stmtHistory->fetchAll() ?: [];

        $value = $row['metric_value'] ?? null;
        $timestampValue = $row['metric_timestamp'] ?? null;
        $isTextMetric = (string)($metricConfig['tipo'] ?? 'numero') === 'texto';
        $textValue = is_scalar($value) ? trim((string)$value) : '';
        $numericValue = (!$isTextMetric && is_numeric($value)) ? (float)$value : null;
        $rule = (array)($metricConfig['semaforo'] ?? []);
        $hasMetricValue = $numericValue !== null || ($isTextMetric && $textValue !== '');
        if (!empty($rule) && $isTextMetric) {
          [$statusLabel, $statusKey, $statusColor] = $evaluateTextMetricStatus($textValue, $rule);
        } elseif (!empty($rule)) {
          [$statusLabel, $statusKey, $statusColor] = $evaluateMetricStatus($numericValue, $rule);
        } else {
          [$statusLabel, $statusKey, $statusColor] = [($hasMetricValue ? 'Lectura' : 'Sin dato'), ($hasMetricValue ? 'azul' : 'gris'), ($hasMetricValue ? '#ffffff' : '#94a3b8')];
        }
        $history = [];

        if (!$isTextMetric) {
          foreach ($historyRows as $historyRow) {
            $historyValue = $historyRow['metric_value'] ?? null;
            $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
            $history[] = [
              'timestamp' => $formatHistoryTimestamp($historyRow['metric_timestamp'] ?? null),
              'iso' => $formatHistoryIsoTimestamp($historyRow['metric_timestamp'] ?? null),
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
          'source' => $source,
          'hidden' => !empty($metricConfig['hidden']),
          'value' => $numericValue,
          'formatted' => $isTextMetric ? ($textValue !== '' ? $textValue : '-') : ($numericValue !== null ? n($numericValue, 2) : '-'),
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => $statusLabel,
          'statusKey' => $statusKey,
          'statusColor' => $statusColor,
          'rangeLabel' => $buildSemaphoreRangeLabel($rule, (string)($metricConfig['unit'] ?? ''), (string)($metricConfig['leyenda'] ?? '')),
          'timestampLabel' => $formatHistoryTimestamp($timestampValue),
          'rule' => $rule,
          'history' => $history,
          'trends' => [
            'week' => $aggregateHistoryPoints($history, 'week'),
            'month' => $aggregateHistoryPoints($history, 'month'),
          ],
        ];
      }
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudieron leer metricas de secadores desde MySQL: ' . $e->getMessage();
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
          'source' => (string)($metricConfig['source'] ?? 'mysql_105'),
          'hidden' => !empty($metricConfig['hidden']),
          'value' => null,
          'formatted' => '-',
          'emptyLabel' => (string)($metricConfig['empty_label'] ?? 'Sin dato'),
          'statusLabel' => 'Sin dato',
          'statusKey' => 'gris',
          'statusColor' => '#94a3b8',
          'rangeLabel' => $buildSemaphoreRangeLabel((array)($metricConfig['semaforo'] ?? []), (string)($metricConfig['unit'] ?? ''), (string)($metricConfig['leyenda'] ?? '')),
          'rule' => (array)($metricConfig['semaforo'] ?? []),
          'history' => [],
        ];
      }
    }
  }
}

$buildTopIndicators = static function (array $indicatorConfig, array $config) use ($connectMysql, $evaluateMetricStatus, $formatHistoryTimestamp, $buildSemaphoreRangeLabel): array {
  $indicators = [];
  $mysqlProductConfig = (array)($config['mysql_producto'] ?? []);
  $mysqlProductRow = [];
  $mysqlProductTimestamp = null;
  $pdoMysql = null;
  $mysqlProductTable = (string)($mysqlProductConfig['tabla_datos'] ?? 'datos_producto');
  $mysqlProductOrderClause = '';

  $needsMysqlProduct = false;
  foreach ($indicatorConfig as $indicator) {
    if ((string)($indicator['source'] ?? '') === 'mysql_producto') {
      $needsMysqlProduct = true;
      break;
    }
  }

  if ($needsMysqlProduct && $mysqlProductConfig !== []) {
    try {
      $pdoMysql = $connectMysql($mysqlProductConfig);
      $orderColumns = (array)($mysqlProductConfig['columnas_orden'] ?? ['id_datos_hora', 'id']);
      $orderSql = [];
      foreach ($orderColumns as $column) {
        $column = (string)$column;
        if (preg_match('/^[A-Za-z0-9_]+$/', $column)) {
          $orderSql[] = '`' . $column . '` DESC';
        }
      }
      $mysqlProductOrderClause = $orderSql !== [] ? ' ORDER BY ' . implode(', ', $orderSql) : '';
      $mysqlProductRow = $pdoMysql->query('SELECT * FROM `' . $mysqlProductTable . '`' . $mysqlProductOrderClause . ' LIMIT 1')->fetch() ?: [];
      $mysqlProductTimestamp = $mysqlProductRow['id_datos_hora'] ?? null;
    } catch (Throwable $e) {
      $mysqlProductRow = [];
    }
  }

  foreach ($indicatorConfig as $indicatorKey => $indicator) {
    $source = (string)($indicator['source'] ?? '');
    $field = (string)($indicator['field'] ?? '');
    $rawValue = null;
    $indicatorTimestamp = $mysqlProductTimestamp;

    if ($source === 'mysql_producto' && $field !== '') {
      $rawValue = $mysqlProductRow[$field] ?? null;
    }

    if (
      !empty($indicator['usar_anterior_si_cero_o_null'])
      && $source === 'mysql_producto'
      && $pdoMysql instanceof PDO
      && preg_match('/^[A-Za-z0-9_]+$/', $mysqlProductTable) === 1
      && preg_match('/^[A-Za-z0-9_]+$/', $field) === 1
      && ($rawValue === null || (is_numeric($rawValue) && (float)$rawValue === 0.0))
    ) {
      try {
        $fallbackSql = 'SELECT `' . $field . '` AS value, `id_datos_hora` AS timestamp FROM `' . $mysqlProductTable . '` WHERE `' . $field . '` IS NOT NULL AND `' . $field . '` <> 0' . $mysqlProductOrderClause . ' LIMIT 1';
        $fallbackRow = $pdoMysql->query($fallbackSql)->fetch() ?: [];
        if (array_key_exists('value', $fallbackRow)) {
          $rawValue = $fallbackRow['value'];
          $indicatorTimestamp = $fallbackRow['timestamp'] ?? $indicatorTimestamp;
        }
      } catch (Throwable $e) {
        // Si no se puede consultar el anterior, se conserva el valor original.
      }
    }

    $numericValue = is_numeric($rawValue) ? (float)$rawValue : null;
    $rule = (array)($indicator['semaforo'] ?? []);
    [$statusLabel, $statusKey, $statusColor] = $rule !== []
      ? $evaluateMetricStatus($numericValue, $rule)
      : [($numericValue !== null ? 'Lectura' : 'Sin dato'), ($numericValue !== null ? 'azul' : 'gris'), ($numericValue !== null ? '#ffffff' : '#94a3b8')];

    $unit = trim((string)($indicator['unit'] ?? ''));
    $formatted = $numericValue !== null ? n($numericValue, 2) : (string)($indicator['empty_label'] ?? '-');
    $indicators[(string)$indicatorKey] = [
      'key' => (string)$indicatorKey,
      'label' => (string)($indicator['label'] ?? $indicatorKey),
      'value' => $numericValue,
      'formatted' => $formatted,
      'unit' => $unit,
      'statusLabel' => $statusLabel,
      'statusKey' => $statusKey,
      'statusColor' => $statusColor,
      'rangeLabel' => $buildSemaphoreRangeLabel($rule, $unit, (string)($indicator['leyenda'] ?? '')),
      'rule' => $rule,
      'unit' => $unit,
      'timestampLabel' => $indicatorTimestamp !== null ? (string)$indicatorTimestamp : '',
    ];
  }

  return $indicators;
};

$topIndicators = $buildTopIndicators((array)($config['indicadores_superiores'] ?? []), $config);

$summarizeTunnel = static function (array $tunnel, array $summary, array $rows): array {
  $latestRow = $rows[0] ?? null;
  $fieldRanges = [];
  $fieldRules = [];
  foreach ((array)($tunnel['campos'] ?? []) as $fieldKey => $fieldConfig) {
    $resolvedFieldKey = (string)($fieldConfig['key'] ?? $fieldKey);
    $fieldRanges[$resolvedFieldKey] = (string)($fieldConfig['rangeLabel'] ?? '');
    $fieldRules[$resolvedFieldKey] = (array)($fieldConfig['rule'] ?? $fieldConfig['semaforo'] ?? []);
  }

  $cells = array_map(static function (array $cell) use ($fieldRanges, $fieldRules): array {
    $fieldKey = (string)($cell['field'] ?? '');
    $cell['rangeLabel'] = $fieldRanges[$fieldKey] ?? '';
    $cell['rule'] = $fieldRules[$fieldKey] ?? [];
    $cell['unit'] = '°C';
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
    $statusColor = '#facc15';
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

$extractTemperatureRoomNumber = static function (array $cell): ?int {
  $field = (string)($cell['field'] ?? '');
  if (preg_match('/recamara_(\d+)/i', $field, $matches) === 1) {
    return (int)$matches[1];
  }

  $label = (string)($cell['label'] ?? '');
  if (preg_match('/(?:rec[aá]mara|rec)\s*(\d+)/iu', $label, $matches) === 1) {
    return (int)$matches[1];
  }

  return null;
};

$ensureTemperatureRooms = static function (array $tunnelSummary, array $rooms) use ($extractTemperatureRoomNumber): array {
  $cells = (array)($tunnelSummary['cells'] ?? []);
  $present = [];

  foreach ($cells as $cell) {
    $room = $extractTemperatureRoomNumber($cell);
    if ($room !== null) {
      $cell['label'] = 'REC ' . $room;
      $present[$room] = true;
    }
    $normalizedCells[] = $cell;
  }
  $cells = $normalizedCells ?? $cells;

  foreach ($rooms as $room) {
    $room = (int)$room;
    if (isset($present[$room])) {
      continue;
    }

    $cells[] = [
      'field' => 'recamara_' . $room . '_placeholder',
      'label' => 'REC ' . $room,
      'formatted' => '-',
      'value' => null,
      'statusLabel' => 'Sin dato',
      'statusKey' => 'gris',
      'statusColor' => '#94a3b8',
      'rangeLabel' => '',
    ];
  }

  usort($cells, static function (array $a, array $b) use ($extractTemperatureRoomNumber): int {
    $roomA = $extractTemperatureRoomNumber($a) ?? 999;
    $roomB = $extractTemperatureRoomNumber($b) ?? 999;
    return $roomA <=> $roomB;
  });

  $tunnelSummary['cells'] = $cells;
  return $tunnelSummary;
};

$normalizeVotators = static function (
  array $votatorConfig,
  array $latestMetricRow,
  array $metricHistoryRows,
  array $metricWeekHistoryByField,
  array $metricMonthHistoryByField
) use ($evaluateMetricStatus, $formatHistoryTimestamp, $formatHistoryIsoTimestamp, $buildSemaphoreRangeLabel): array {
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
          (string)($field['status_color'] ?? ($numericValue !== null ? '#ffffff' : '#94a3b8')),
        ];
      $history = [];
      $weekHistory = [];
      $monthHistory = [];

      if ($source === 'sqlserver' && $sourceField !== '') {
        foreach ($metricHistoryRows as $historyRow) {
          $historyValue = $historyRow[$sourceField] ?? null;
          $historyNumericValue = is_numeric($historyValue) ? (float)$historyValue : null;
          $history[] = [
            'timestamp' => $formatHistoryTimestamp($historyRow['__timestamp'] ?? null),
            'iso' => $formatHistoryIsoTimestamp($historyRow['__timestamp'] ?? null),
            'value' => $historyNumericValue,
            'formatted' => $historyNumericValue !== null ? n($historyNumericValue, 2) : '-',
          ];
        }

        $weekHistory = (array)($metricWeekHistoryByField[$sourceField] ?? []);
        $monthHistory = (array)($metricMonthHistoryByField[$sourceField] ?? []);
      }

      $fields[] = [
        'key' => (string)$fieldKey,
        'label' => (string)($field['label'] ?? $fieldKey),
        'unit' => (string)($field['unit'] ?? ''),
        'available' => !empty($field['available']) && $sourceField !== '',
        'field' => $sourceField,
        'source' => $source,
        'value' => $numericValue,
        'formatted' => $numericValue !== null ? n($numericValue, 2) : $emptyLabel,
        'emptyLabel' => $emptyLabel,
        'statusLabel' => $statusLabel,
        'statusKey' => $statusKey,
        'statusColor' => $statusColor,
        'rangeLabel' => $buildSemaphoreRangeLabel($rule, (string)($field['unit'] ?? ''), (string)($field['leyenda'] ?? '')),
        'rule' => $rule,
        'icon' => (string)($field['icon'] ?? ''),
        'history' => $history,
        'trends' => [
          'week' => $weekHistory,
          'month' => $monthHistory,
        ],
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

  if ((string)$tunnelKey === 'tunel_2') {
    $executiveTunels[$tunnelKey] = $ensureTemperatureRooms($executiveTunels[$tunnelKey], range(1, 9));
  }

  $executiveTunels[$tunnelKey]['metricas'] = (array)($metricValuesByTunnel[$tunnelKey] ?? []);
  $executiveTunels[$tunnelKey]['votators'] = $normalizeVotators(
    (array)($votatorConfigByTunnel[$tunnelKey] ?? []),
    $metricRow,
    $metricHistoryRows,
    $metricWeekHistoryByField,
    $metricMonthHistoryByField
  );

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
  $globalStatusColor = '#facc15';
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
  'warnings' => array_values(array_filter(array_merge(
    (array)(($detailReport['meta'] ?? [])['warnings'] ?? []),
    $warnings
  ))),
  'indicadores' => $topIndicators,
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
