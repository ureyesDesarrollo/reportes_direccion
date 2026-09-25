<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$requestedKeys = array_values(array_map('strval', (array)($config['votators'] ?? [])));
$primaryKeys = array_values(array_map('strval', (array)($config['campos_principales'] ?? [])));
$secondaryKeys = array_values(array_map('strval', (array)($config['campos_secundarios'] ?? [])));

$emptyField = static function (string $key): array {
  $labels = [
    'flujo' => 'Flujo',
    'presion_cuajado' => 'Presión cuajado',
    'temperatura_nariz' => 'Temp. nariz',
    'solidos' => 'Sólidos',
    'amperaje_bomba' => 'Amp. bomba',
    'amperaje_reductor' => 'Amp. reductor',
    'corriente_votator' => 'Corriente',
    'tiempo_fuera' => 'Tiempo fuera',
  ];

  return [
    'key' => $key,
    'label' => (string)($labels[$key] ?? $key),
    'formatted' => '—',
    'unit' => '',
    'statusKey' => 'gris',
    'statusLabel' => 'Sin dato',
    'statusColor' => '#94a3b8',
    'rule' => [],
    'source' => 'sqlserver',
  ];
};

$normaliseField = static function (array $field) use ($emptyField): array {
  $key = trim((string)($field['key'] ?? ''));
  $fallback = $emptyField($key);
  $formatted = trim((string)($field['formatted'] ?? ''));
  $emptyLabel = trim((string)($field['emptyLabel'] ?? 'Sin dato'));
  if ($formatted === '' || $formatted === $emptyLabel) {
    $formatted = '—';
  }
  $status = (string)($field['statusKey'] ?? 'gris');
  if (!in_array($status, ['verde', 'amarillo', 'rojo'], true)) {
    $status = 'gris';
  }

  return array_replace($fallback, [
    'label' => (string)($field['label'] ?? $fallback['label']),
    'formatted' => $formatted,
    'unit' => trim((string)($field['unit'] ?? '')),
    'statusKey' => $status,
    'statusLabel' => (string)($field['statusLabel'] ?? 'Sin dato'),
    'statusColor' => (string)($field['statusColor'] ?? '#94a3b8'),
    'rule' => (array)($field['rule'] ?? []),
    'source' => (string)($field['source'] ?? 'sqlserver'),
  ]);
};

$statusForFields = static function (array $fields): array {
  $priority = ['gris' => 0, 'verde' => 1, 'amarillo' => 2, 'rojo' => 3];
  $worst = 'gris';
  foreach ($fields as $field) {
    $status = (string)($field['statusKey'] ?? 'gris');
    if (($priority[$status] ?? 0) > ($priority[$worst] ?? 0)) {
      $worst = $status;
    }
  }
  $labels = [
    'verde' => 'En objetivo',
    'amarillo' => 'Alerta',
    'rojo' => 'Crítico',
    'gris' => 'Sin datos',
  ];
  return ['key' => $worst, 'label' => $labels[$worst]];
};

$equipment = [];
$evaluateBands = static function (?float $value, array $rule): array {
  if ($value === null) return ['key' => 'gris', 'label' => 'Sin dato'];
  foreach ((array)($rule['bandas'] ?? []) as $band) {
    $min = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
    $max = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
    if (($min === null || $value >= $min) && ($max === null || $value <= $max)) {
      $key = (string)($band['estado'] ?? 'gris');
      return [
        'key' => in_array($key, ['verde', 'amarillo', 'rojo'], true) ? $key : 'gris',
        'label' => ['verde' => 'En objetivo', 'amarillo' => 'Alerta', 'rojo' => 'Crítico', 'gris' => 'Sin dato'][$key] ?? 'Sin dato',
      ];
    }
  }
  return ['key' => 'rojo', 'label' => 'Crítico'];
};

foreach ($requestedKeys as $index => $key) {
  $equipment[$key] = [
    'key' => $key,
    'label' => 'Votator ' . ($index + 1),
    'statusKey' => 'gris',
    'statusLabel' => 'Sin datos',
    'ultimaLectura' => '—',
    'principales' => array_map($emptyField, $primaryKeys),
    'secundarios' => array_map($emptyField, $secondaryKeys),
  ];
}

$warning = '';

$feedTemperatureConfig = (array)($config['temperatura_alimentacion_votators_3_4'] ?? []);
$feedTemperature = [
  'nombre' => 'TEMP. ENTRADA TANQUE ALIMENTACIÓN V3 Y V4',
  'campo' => (string)($feedTemperatureConfig['sensor'] ?? ''),
  'valor' => null,
  'formatted' => '—',
  'unit' => (string)($feedTemperatureConfig['unidad'] ?? '°C'),
  'fecha' => '—',
  'available' => false,
  'statusKey' => 'gris',
  'statusLabel' => 'Sin dato',
  'rule' => (array)($feedTemperatureConfig['semaforo'] ?? []),
];
$temperatureWarning = '';
try {
  $connection = (array)($feedTemperatureConfig['conexion'] ?? []);
  $table = (string)($feedTemperatureConfig['tabla'] ?? 'TREND001');
  $dateField = (string)($feedTemperatureConfig['campo_fecha'] ?? 'Time_Stamp');
  $feedSensor = (string)($feedTemperatureConfig['sensor'] ?? '');
  foreach ([$table, $dateField, $feedSensor] as $identifier) {
    if ($identifier === '' || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
      throw new RuntimeException('Identificador AVEVA no válido.');
    }
  }
  $dsn = sprintf(
    'sqlsrv:Server=%s,%d;Database=%s;TrustServerCertificate=1',
    (string)($connection['server'] ?? ''),
    (int)($connection['port'] ?? 1433),
    (string)($connection['database'] ?? '')
  );
  $pdo = new PDO($dsn, (string)($connection['user'] ?? ''), (string)($connection['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $sql = sprintf(
    'SELECT TOP (1) [%1$s] AS sensor_value, [%2$s] AS sensor_timestamp FROM [%3$s] WHERE [%1$s] IS NOT NULL ORDER BY [%2$s] DESC',
    $feedSensor,
    $dateField,
    $table
  );
  $row = $pdo->query($sql)->fetch();
  if (is_array($row) && is_numeric($row['sensor_value'] ?? null)) {
    $value = (float)$row['sensor_value'];
    $feedTemperature['valor'] = $value;
    $feedTemperature['formatted'] = number_format($value, 2, '.', ',');
    $feedTemperature['available'] = true;
    $feedStatus = $evaluateBands($value, (array)($feedTemperature['rule'] ?? []));
    $feedTemperature['statusKey'] = $feedStatus['key'];
    $feedTemperature['statusLabel'] = $feedStatus['label'];
    $rawTimestamp = trim((string)($row['sensor_timestamp'] ?? ''));
    if ($rawTimestamp !== '') {
      try {
        $feedTemperature['fecha'] = (new DateTimeImmutable($rawTimestamp))->format('d/m/Y H:i');
      } catch (Throwable $ignored) {
        $feedTemperature['fecha'] = $rawTimestamp;
      }
    }
  }
} catch (Throwable $exception) {
  $temperatureWarning = 'No fue posible consultar la temperatura de entrada del tanque de alimentación.';
  error_log('[votators] ' . $temperatureWarning . ' ' . $exception->getMessage());
}
try {
  $secadoresConfig = (array)($config['secadores_config'] ?? []);
  $secadoresReport = (static function (array $reportConfig): array {
    $config = $reportConfig;
    return require __DIR__ . '/../secadores/build_report.php';
  })($secadoresConfig);

  foreach ((array)($secadoresReport['tuneles'] ?? []) as $tunnel) {
    foreach ((array)($tunnel['votators'] ?? []) as $votator) {
      $key = (string)($votator['key'] ?? '');
      if (!isset($equipment[$key])) continue;

      $fields = [];
      foreach ((array)($votator['fields'] ?? []) as $field) {
        $fieldKey = (string)($field['key'] ?? '');
        if ($fieldKey === '') continue;
        $fields[$fieldKey] = $normaliseField((array)$field);
      }
      $primary = [];
      foreach ($primaryKeys as $fieldKey) {
        $primary[] = $fields[$fieldKey] ?? $emptyField($fieldKey);
      }
      $secondary = [];
      foreach ($secondaryKeys as $fieldKey) {
        $secondary[] = $fields[$fieldKey] ?? $emptyField($fieldKey);
      }
      $status = $statusForFields($primary);

      $equipment[$key] = [
        'key' => $key,
        'label' => (string)($votator['label'] ?? $equipment[$key]['label']),
        'statusKey' => $status['key'],
        'statusLabel' => $status['label'],
        'ultimaLectura' => (string)($tunnel['ultimaLectura'] ?? '—'),
        'principales' => $primary,
        'secundarios' => $secondary,
      ];
    }
  }
} catch (Throwable $exception) {
  $warning = 'No fue posible consultar AVEVA; se muestran los Votators sin lectura.';
}

$lastReadings = array_values(array_filter(array_map(
  static fn(array $item): string => trim((string)($item['ultimaLectura'] ?? '')),
  $equipment
), static fn(string $value): bool => $value !== '' && $value !== '—'));

return [
  'titulo' => (string)($config['titulo'] ?? 'Votators'),
  'subtitulo' => (string)($config['subtitulo'] ?? 'Monitoreo en tiempo real'),
  'equipos' => array_values($equipment),
  'ultima_lectura' => $lastReadings !== [] ? max($lastReadings) : '—',
  'warning' => $warning,
  'temperatura_alimentacion' => $feedTemperature,
  'temperatura_warning' => $temperatureWarning,
  'intervalo_actualizacion_ms' => max(1000, (int)($config['intervalo_actualizacion_ms'] ?? 1000)),
];
