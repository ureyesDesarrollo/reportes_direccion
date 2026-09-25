<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$response = [
  'ok' => false,
  'timestamp' => '—',
  'feedTemperature' => ['formatted' => '—', 'unit' => '°C', 'timestamp' => '—', 'statusKey' => 'gris'],
  'equipment' => [],
];

$evaluate = static function (?float $value, array $rule): array {
  if ($value === null) return ['key' => 'gris', 'label' => 'Sin dato'];
  $mode = (string)($rule['modo'] ?? 'rango');

  if ($mode === 'bandas') {
    foreach ((array)($rule['bandas'] ?? []) as $band) {
      $min = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
      $max = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
      if (($min === null || $value >= $min) && ($max === null || $value <= $max)) {
        $key = (string)($band['estado'] ?? 'gris');
        if (!in_array($key, ['verde', 'amarillo', 'rojo'], true)) $key = 'gris';
        return ['key' => $key, 'label' => ['verde' => 'Óptimo', 'amarillo' => 'Atención', 'rojo' => 'Crítico', 'gris' => 'Sin rango'][$key]];
      }
    }
    return ['key' => 'rojo', 'label' => 'Crítico'];
  }

  $greenMin = isset($rule['verde_min']) && is_numeric($rule['verde_min']) ? (float)$rule['verde_min'] : null;
  $greenMax = isset($rule['verde_max']) && is_numeric($rule['verde_max']) ? (float)$rule['verde_max'] : null;
  $yellowMin = isset($rule['amarillo_min']) && is_numeric($rule['amarillo_min']) ? (float)$rule['amarillo_min'] : null;
  $yellowMax = isset($rule['amarillo_max']) && is_numeric($rule['amarillo_max']) ? (float)$rule['amarillo_max'] : null;

  if ($mode === 'minimo') {
    if ($greenMin !== null && $value >= $greenMin) return ['key' => 'verde', 'label' => 'Óptimo'];
    if ($yellowMin !== null && $value >= $yellowMin) return ['key' => 'amarillo', 'label' => 'Atención'];
    return ['key' => 'rojo', 'label' => 'Crítico'];
  }
  if ($mode === 'maximo') {
    if ($greenMax !== null && $value <= $greenMax) return ['key' => 'verde', 'label' => 'Óptimo'];
    if ($yellowMax !== null && $value <= $yellowMax) return ['key' => 'amarillo', 'label' => 'Atención'];
    return ['key' => 'rojo', 'label' => 'Crítico'];
  }

  if (($greenMin === null || $value >= $greenMin) && ($greenMax === null || $value <= $greenMax)) {
    return ['key' => 'verde', 'label' => 'Óptimo'];
  }
  if (($yellowMin !== null || $yellowMax !== null)
    && ($yellowMin === null || $value >= $yellowMin)
    && ($yellowMax === null || $value <= $yellowMax)) {
    return ['key' => 'amarillo', 'label' => 'Atención'];
  }
  return ['key' => 'rojo', 'label' => 'Crítico'];
};

$quote = static function (string $identifier): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
    throw new InvalidArgumentException('Identificador AVEVA no válido.');
  }
  return '[' . $identifier . ']';
};

$formatTimestamp = static function ($value): string {
  if ($value instanceof DateTimeInterface) return $value->format('d/m/Y H:i:s');
  $raw = trim((string)$value);
  if ($raw === '') return '—';
  try {
    return (new DateTimeImmutable($raw))->format('d/m/Y H:i:s');
  } catch (Throwable $exception) {
    return $raw;
  }
};

try {
  $secadores = (array)($config['secadores_config'] ?? []);
  $connection = (array)($secadores['sqlserver'] ?? []);
  $table = (string)($secadores['tabla'] ?? 'TREND001');
  $timestampField = (string)($secadores['campo_fecha'] ?? 'Time_Stamp');
  $requested = array_fill_keys(array_map('strval', (array)($config['votators'] ?? [])), true);
  $equipmentConfig = [];
  $fields = [];

  foreach ((array)($secadores['votators_por_tunel'] ?? []) as $votators) {
    foreach ((array)$votators as $equipmentKey => $equipment) {
      if (!isset($requested[(string)$equipmentKey])) continue;
      $equipmentConfig[(string)$equipmentKey] = (array)$equipment;
      foreach ((array)($equipment['campos'] ?? []) as $field) {
        if ((string)($field['source'] ?? 'sqlserver') !== 'sqlserver') continue;
        $sensor = trim((string)($field['field'] ?? ''));
        if ($sensor !== '') $fields[$sensor] = $sensor;
      }
    }
  }

  $feedTemperatureSensor = (string)(($config['temperatura_alimentacion_votators_3_4'] ?? [])['sensor'] ?? 'TEMPERATURA_ENTRADA_TANQUE_ALIMENTACION_VOTATORS_3_Y_4');
  $fields[$feedTemperatureSensor] = $feedTemperatureSensor;
  $select = [$quote($timestampField) . ' AS [__timestamp]'];
  foreach ($fields as $sensor) $select[] = $quote($sensor);

  $server = trim((string)($connection['server'] ?? ''));
  $port = (int)($connection['port'] ?? 1433);
  $database = trim((string)($connection['database'] ?? ''));
  $encrypt = !empty($connection['encrypt']) ? 'yes' : 'no';
  $trust = !empty($connection['trust_server_certificate']) ? 'yes' : 'no';
  $timeout = max(1, (int)($connection['login_timeout'] ?? 5));
  $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s;Encrypt=%s;TrustServerCertificate=%s;LoginTimeout=%d', $server, $port, $database, $encrypt, $trust, $timeout);
  $pdo = new PDO($dsn, (string)($connection['user'] ?? ''), (string)($connection['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $sql = 'SELECT TOP (1) ' . implode(', ', $select)
    . ' FROM ' . $quote($table)
    . ' WHERE CAST(' . $quote($timestampField) . ' AS date) = CAST(GETDATE() AS date)'
    . ' ORDER BY ' . $quote($timestampField) . ' DESC';
  $row = $pdo->query($sql)->fetch() ?: [];
  $timestamp = $formatTimestamp($row['__timestamp'] ?? null);

  $primaryKeys = array_fill_keys(array_map('strval', (array)($config['campos_principales'] ?? [])), true);
  $priority = ['gris' => 0, 'verde' => 1, 'amarillo' => 2, 'rojo' => 3];
  foreach ($equipmentConfig as $equipmentKey => $equipment) {
    $item = ['statusKey' => 'gris', 'statusLabel' => 'Sin datos', 'timestamp' => $timestamp, 'fields' => []];
    foreach ((array)($equipment['campos'] ?? []) as $fieldKey => $field) {
      $sensor = trim((string)($field['field'] ?? ''));
      $value = $sensor !== '' && is_numeric($row[$sensor] ?? null) ? (float)$row[$sensor] : null;
      $rule = (array)($field['semaforo'] ?? []);
      $status = $rule !== [] ? $evaluate($value, $rule) : ['key' => 'gris', 'label' => ($value === null ? 'Sin dato' : 'Lectura')];
      $item['fields'][(string)$fieldKey] = [
        'formatted' => $value !== null ? n($value, 2) : '—',
        'unit' => trim((string)($field['unit'] ?? '')),
        'statusKey' => $status['key'],
      ];
      if (isset($primaryKeys[(string)$fieldKey]) && ($priority[$status['key']] ?? 0) > ($priority[$item['statusKey']] ?? 0)) {
        $item['statusKey'] = $status['key'];
      }
    }
    $item['statusLabel'] = ['verde' => 'En objetivo', 'amarillo' => 'Alerta', 'rojo' => 'Crítico', 'gris' => 'Sin datos'][$item['statusKey']];
    $response['equipment'][$equipmentKey] = $item;
  }

  $feedTemperature = is_numeric($row[$feedTemperatureSensor] ?? null) ? (float)$row[$feedTemperatureSensor] : null;
  $feedTemperatureStatus = $evaluate($feedTemperature, (array)(($config['temperatura_alimentacion_votators_3_4'] ?? [])['semaforo'] ?? []));
  $response['feedTemperature'] = [
    'formatted' => $feedTemperature !== null ? n($feedTemperature, 2) : '—',
    'unit' => (string)(($config['temperatura_alimentacion_votators_3_4'] ?? [])['unidad'] ?? '°C'),
    'timestamp' => $timestamp,
    'statusKey' => $feedTemperatureStatus['key'],
  ];
  $response['timestamp'] = $timestamp;
  $response['ok'] = true;
} catch (Throwable $exception) {
  http_response_code(503);
  $response['error'] = 'No fue posible consultar AVEVA.';
  error_log('[votators-live] ' . $exception->getMessage());
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
