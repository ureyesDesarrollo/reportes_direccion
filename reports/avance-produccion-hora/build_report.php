<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';

$connectSqlServer = static function (array $cfg): PDO {
  $server = trim((string)($cfg['server'] ?? ''));
  $database = trim((string)($cfg['database'] ?? ''));
  $port = (int)($cfg['port'] ?? 1433);
  $encrypt = !empty($cfg['encrypt']) ? 'yes' : 'no';
  $trust = !empty($cfg['trust_server_certificate']) ? 'yes' : 'no';
  $timeout = max(1, (int)($cfg['login_timeout'] ?? 5));
  $serverPart = $port > 0 ? $server . ',' . $port : $server;
  $dsn = "sqlsrv:Server={$serverPart};Database={$database};Encrypt={$encrypt};TrustServerCertificate={$trust};LoginTimeout={$timeout}";

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$quoteSqlServerIdentifier = static function (string $identifier): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
    throw new InvalidArgumentException('Identificador SQL Server inválido: ' . $identifier);
  }
  return '[' . $identifier . ']';
};

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mazatlan'));
$now = new DateTimeImmutable('now', $timezone);
$previewTurno = (string)($_GET['preview_turno'] ?? '');
if ($previewTurno === '2') {
  $now = $now->setTime(19, 5, 0);
}
$time = $now->format('H:i');
$turno = $time >= '07:00' && $time < '19:00' ? 1 : 2;
$turnoConfig = (array)(($config['turnos'] ?? [])[$turno] ?? []);

if ($turno === 1) {
  $turnoInicio = $now->setTime(7, 0, 0);
  $turnoFin = $now->setTime(19, 0, 0);
} elseif ($time >= '19:00') {
  $turnoInicio = $now->setTime(19, 0, 0);
  $turnoFin = $turnoInicio->modify('+1 day')->setTime(7, 0, 0);
} else {
  $turnoFin = $now->setTime(7, 0, 0);
  $turnoInicio = $turnoFin->modify('-1 day')->setTime(19, 0, 0);
}

$productionDayStart = $time < '07:00'
  ? $now->modify('-1 day')->setTime(7, 0, 0)
  : $now->setTime(7, 0, 0);
$summaryPeriod = strtolower(trim((string)($_GET['periodo'] ?? 'actual')));
if ($summaryPeriod === 'anterior') {
  $productionDayStart = $productionDayStart->modify('-1 day');
} else {
  $summaryPeriod = 'actual';
}
$productionTurn1End = $productionDayStart->setTime(19, 0, 0);
$productionDayEnd = $productionDayStart->modify('+1 day')->setTime(7, 0, 0);
$tarimasResumen = [
  'turno_1' => null,
  'turno_2' => null,
  'total' => null,
  'inicio' => $productionDayStart->format('Y-m-d H:i:s'),
  'fin' => $productionDayEnd->format('Y-m-d H:i:s'),
  'periodo' => $summaryPeriod,
];
$previousShiftEnd = $turnoInicio;
$previousShiftStart = $previousShiftEnd->modify('-12 hours');
$previousShiftNumber = $turno === 1 ? 2 : 1;
$previousShiftSummary = [
  'turno' => $previousShiftNumber,
  'tarimas' => null,
  'kg_hora' => null,
  'acumulado' => null,
  'supervisor' => 'Sin asignar',
  'inicio' => $previousShiftStart->format('Y-m-d H:i:s'),
  'fin' => $previousShiftEnd->format('Y-m-d H:i:s'),
];

$metricConfig = (array)($config['metricas'] ?? []);
$supervisor = trim((string)($config['supervisor'] ?? ''));
$acumuladoUsaAnterior = false;
try {
  $databaseKey = (string)($config['database_key'] ?? 'prod');
  $reportDatabase = (array)($config['database'] ?? []);
  $pdo = conectar($reportDatabase !== [] ? $reportDatabase : (array)($dbConfig[$databaseKey] ?? $dbConfig['prod']));
  $tarimasStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.tar_id)
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, 0) > 0
  ");
  $tarimasStmt->execute([
    $turnoInicio->format('Y-m-d H:i:s'),
    $turnoFin->format('Y-m-d H:i:s'),
  ]);
  $metricConfig['tarimas']['value'] = (int)$tarimasStmt->fetchColumn();

  $tarimasResumenStmt = $pdo->prepare("
    SELECT
      COUNT(DISTINCT CASE WHEN t.tar_fecha >= ? AND t.tar_fecha < ? THEN t.tar_id END) AS turno_1,
      COUNT(DISTINCT CASE WHEN t.tar_fecha >= ? AND t.tar_fecha < ? THEN t.tar_id END) AS turno_2,
      COUNT(DISTINCT t.tar_id) AS total
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, 0) > 0
  ");
  $tarimasResumenStmt->execute([
    $productionDayStart->format('Y-m-d H:i:s'),
    $productionTurn1End->format('Y-m-d H:i:s'),
    $productionTurn1End->format('Y-m-d H:i:s'),
    $productionDayEnd->format('Y-m-d H:i:s'),
    $productionDayStart->format('Y-m-d H:i:s'),
    $productionDayEnd->format('Y-m-d H:i:s'),
  ]);
  $tarimasResumenRow = $tarimasResumenStmt->fetch() ?: [];
  $tarimasResumen['turno_1'] = (int)($tarimasResumenRow['turno_1'] ?? 0);
  $tarimasResumen['turno_2'] = (int)($tarimasResumenRow['turno_2'] ?? 0);
  $tarimasResumen['total'] = (int)($tarimasResumenRow['total'] ?? 0);

  $previousShiftStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT t.tar_id)
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, 0) > 0
  ");
  $previousShiftStmt->execute([
    $previousShiftStart->format('Y-m-d H:i:s'),
    $previousShiftEnd->format('Y-m-d H:i:s'),
  ]);
  $previousShiftSummary['tarimas'] = (int)$previousShiftStmt->fetchColumn();

  $previousShiftSupervisorStmt = $pdo->prepare("
    SELECT u.usu_nombre
    FROM rev_tarimas t
    INNER JOIN usuarios u ON u.usu_id = t.usu_id
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, 0) > 0
      AND TRIM(COALESCE(u.usu_nombre, '')) <> ''
    ORDER BY t.tar_fecha DESC, t.tar_id DESC
    LIMIT 1
  ");
  $previousShiftSupervisorStmt->execute([
    $previousShiftStart->format('Y-m-d H:i:s'),
    $previousShiftEnd->format('Y-m-d H:i:s'),
  ]);
  $previousShiftSupervisor = $previousShiftSupervisorStmt->fetchColumn();
  if (is_scalar($previousShiftSupervisor) && trim((string)$previousShiftSupervisor) !== '') {
    $previousShiftSummary['supervisor'] = trim((string)$previousShiftSupervisor);
  }

  $supervisorStmt = $pdo->prepare("
    SELECT u.usu_nombre
    FROM rev_tarimas t
    INNER JOIN usuarios u ON u.usu_id = t.usu_id
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, 0) > 0
      AND TRIM(COALESCE(u.usu_nombre, '')) <> ''
    ORDER BY t.tar_fecha DESC, t.tar_id DESC
    LIMIT 1
  ");
  $supervisorStmt->execute([
    $turnoInicio->format('Y-m-d H:i:s'),
    $turnoFin->format('Y-m-d H:i:s'),
  ]);
  $supervisorValue = $supervisorStmt->fetchColumn();
  if (is_scalar($supervisorValue) && trim((string)$supervisorValue) !== '') {
    $supervisor = trim((string)$supervisorValue);
  }
} catch (Throwable $e) {
  $metricConfig['tarimas']['value'] = null;
}

try {
  $clarifierPdo = conectar((array)($config['clarificador_database'] ?? []));
  $clarifierStmt = $clarifierPdo->prepare("
    SELECT d.solidos, COALESCE(dh.hora_exacta, dh.fecha_creacion) AS fecha_lectura
    FROM datos_clarificador d
    INNER JOIN datos_hora dh ON dh.id = d.id_datos_hora
    WHERE COALESCE(dh.hora_exacta, dh.fecha_creacion) <= ?
      AND d.solidos IS NOT NULL
    ORDER BY COALESCE(dh.hora_exacta, dh.fecha_creacion) DESC, d.id DESC
    LIMIT 1
  ");
  $clarifierStmt->execute([$now->format('Y-m-d H:i:s')]);
  $clarifierRow = $clarifierStmt->fetch() ?: [];
  $clarifierValue = $clarifierRow['solidos'] ?? null;
  $metricConfig['solidos_clarificador']['value'] = is_numeric($clarifierValue)
    ? (float)$clarifierValue
    : null;
  $metricConfig['solidos_clarificador']['fecha_lectura'] = (string)($clarifierRow['fecha_lectura'] ?? '');
} catch (Throwable $e) {
  $metricConfig['solidos_clarificador']['value'] = null;
}

try {
  $membranesPdo = isset($clarifierPdo)
    ? $clarifierPdo
    : conectar((array)($config['clarificador_database'] ?? []));
  $membranesStmt = $membranesPdo->prepare("
    SELECT d.solidos_etapa4, COALESCE(dh.hora_exacta, dh.fecha_creacion) AS fecha_lectura
    FROM datos_uf_etapas d
    INNER JOIN datos_hora dh ON dh.id = d.id_datos_hora
    WHERE COALESCE(dh.hora_exacta, dh.fecha_creacion) <= ?
      AND d.solidos_etapa4 IS NOT NULL
    ORDER BY COALESCE(dh.hora_exacta, dh.fecha_creacion) DESC, d.id DESC
    LIMIT 1
  ");
  $membranesStmt->execute([$now->format('Y-m-d H:i:s')]);
  $membranesRow = $membranesStmt->fetch() ?: [];
  $membranesValue = $membranesRow['solidos_etapa4'] ?? null;
  $metricConfig['solidos_membranas']['value'] = is_numeric($membranesValue)
    ? (float)$membranesValue
    : null;
  $metricConfig['solidos_membranas']['fecha_lectura'] = (string)($membranesRow['fecha_lectura'] ?? '');
} catch (Throwable $e) {
  $metricConfig['solidos_membranas']['value'] = null;
}

try {
  $sensorPdo = $connectSqlServer((array)($config['sensor_database'] ?? []));
  $sensorTable = $quoteSqlServerIdentifier((string)($config['sensor_table'] ?? 'TREND001'));
  $sensorTimestamp = $quoteSqlServerIdentifier((string)($config['sensor_timestamp'] ?? 'Time_Stamp'));
  $formatSensorDate = static function ($value): string {
    if ($value instanceof DateTimeInterface) {
      return $value->format('Y-m-d H:i:s');
    }
    return is_scalar($value) ? trim((string)$value) : '';
  };
  try {
    $concentratorSensor = (string)($config['solidos_concentradores_sensor'] ?? 'SOLIDOS_DE_VOTATORS');
    $safeConcentratorSensor = $quoteSqlServerIdentifier($concentratorSensor);
    $concentratorSensorSql = "
      SELECT TOP (1)
        TRY_CONVERT(float, {$safeConcentratorSensor}) AS valor,
        {$sensorTimestamp} AS fecha_lectura
      FROM {$sensorTable}
      WHERE {$sensorTimestamp} <= GETDATE()
        AND TRY_CONVERT(float, {$safeConcentratorSensor}) IS NOT NULL
      ORDER BY {$sensorTimestamp} DESC
    ";
    $concentratorSensorRow = $sensorPdo->query($concentratorSensorSql)->fetch() ?: [];
    $concentratorSensorValue = $concentratorSensorRow['valor'] ?? null;
    $metricConfig['solidos_concentradores']['value'] = is_numeric($concentratorSensorValue)
      ? trim((string)$concentratorSensorValue)
      : null;
    $metricConfig['solidos_concentradores']['fecha_lectura'] = $formatSensorDate($concentratorSensorRow['fecha_lectura'] ?? null);
    $metricConfig['solidos_concentradores']['componentes'] = [
      $concentratorSensor => is_numeric($concentratorSensorValue) ? trim((string)$concentratorSensorValue) : null,
    ];
  } catch (Throwable $e) {
    $metricConfig['solidos_concentradores']['value'] = null;
    $metricConfig['solidos_concentradores']['fecha_lectura'] = '';
  }
  foreach ((array)($config['flujo_sensores'] ?? []) as $metricKey => $sensorFields) {
    $metricKey = (string)$metricKey;
    $sensorFields = array_values((array)$sensorFields);
    if ($sensorFields === [] || !isset($metricConfig[$metricKey])) {
      throw new RuntimeException($metricKey . ' no tiene sensores de flujo configurados.');
    }
    $sensorSelects = [];
    foreach ($sensorFields as $sensorIndex => $sensorField) {
      $sensorNumber = $sensorIndex + 1;
      $safeSensorField = $quoteSqlServerIdentifier((string)$sensorField);
      $sensorSelects[] = "(SELECT TOP (1) TRY_CONVERT(float, {$safeSensorField}) FROM {$sensorTable} WHERE {$sensorTimestamp} <= GETDATE() AND TRY_CONVERT(float, {$safeSensorField}) IS NOT NULL ORDER BY {$sensorTimestamp} DESC) AS valor_{$sensorNumber}";
      $sensorSelects[] = "(SELECT TOP (1) {$sensorTimestamp} FROM {$sensorTable} WHERE {$sensorTimestamp} <= GETDATE() AND TRY_CONVERT(float, {$safeSensorField}) IS NOT NULL ORDER BY {$sensorTimestamp} DESC) AS fecha_{$sensorNumber}";
    }
    $sensorSql = "SELECT\n        " . implode(",\n        ", $sensorSelects);
    $sensorRow = $sensorPdo->query($sensorSql)->fetch() ?: [];
    $sensorTotal = 0.0;
    $allSensorsAvailable = true;
    $sensorDates = [];
    $sensorComponents = [];
    foreach ($sensorFields as $sensorIndex => $sensorField) {
      $sensorNumber = $sensorIndex + 1;
      $sensorValue = $sensorRow['valor_' . $sensorNumber] ?? null;
      if (!is_numeric($sensorValue)) {
        $allSensorsAvailable = false;
      } else {
        $sensorTotal += (float)$sensorValue;
      }
      $sensorDate = $formatSensorDate($sensorRow['fecha_' . $sensorNumber] ?? null);
      if ($sensorDate !== '') {
        $sensorDates[] = $sensorDate;
      }
      $sensorComponents[(string)$sensorField] = is_numeric($sensorValue) ? (float)$sensorValue : null;
    }
    $metricConfig[$metricKey]['value'] = $allSensorsAvailable ? $sensorTotal : null;
    rsort($sensorDates);
    $metricConfig[$metricKey]['fecha_lectura'] = (string)($sensorDates[0] ?? '');
    $metricConfig[$metricKey]['componentes'] = $sensorComponents;
  }
} catch (Throwable $e) {
  $metricConfig['solidos_concentradores']['value'] = null;
  $metricConfig['solidos_concentradores']['fecha_lectura'] = '';
  foreach ((array)($config['flujo_sensores'] ?? []) as $metricKey => $sensorFields) {
    $metricConfig[(string)$metricKey]['value'] = null;
    $metricConfig[(string)$metricKey]['fecha_lectura'] = '';
  }
}

$sensorFlowTotal = 0.0;
$hasSensorFlow = false;
foreach (array_keys((array)($config['flujo_sensores'] ?? [])) as $sensorMetricKey) {
  $sensorFlowValue = $metricConfig[(string)$sensorMetricKey]['value'] ?? null;
  if (is_numeric($sensorFlowValue)) {
    $sensorFlowTotal += (float)$sensorFlowValue;
    $hasSensorFlow = true;
  }
}
$metricConfig['flujo_total']['value'] = $hasSensorFlow ? $sensorFlowTotal : null;

try {
  $productionPdo = isset($flowPdo)
    ? $flowPdo
    : conectar((array)($config['progel_core_database'] ?? []));
  $previousProductionStart = $turnoInicio->modify('-12 hours');
  $productionStmt = $productionPdo->prepare("
    SELECT
      (
        SELECT p2.kg_reales
        FROM sup_captura_produccion p2
        WHERE TIMESTAMP(p2.fecha, p2.hora) >= ?
          AND TIMESTAMP(p2.fecha, p2.hora) <= ?
          AND p2.kg_reales IS NOT NULL
        ORDER BY p2.fecha DESC, p2.hora DESC, p2.id DESC
        LIMIT 1
      ) AS kg_hora,
      COUNT(p.id) AS registros_actuales,
      SUM(p.kg_reales) AS acumulado_actual,
      (
        SELECT p3.kg_reales
        FROM sup_captura_produccion p3
        WHERE TIMESTAMP(p3.fecha, p3.hora) >= ?
          AND TIMESTAMP(p3.fecha, p3.hora) < ?
          AND p3.kg_reales IS NOT NULL
        ORDER BY p3.fecha DESC, p3.hora DESC, p3.id DESC
        LIMIT 1
      ) AS valor_base
    FROM sup_captura_produccion p
    WHERE TIMESTAMP(p.fecha, p.hora) >= ?
      AND TIMESTAMP(p.fecha, p.hora) <= ?
  ");
  $productionStmt->execute([
    $turnoInicio->format('Y-m-d H:i:s'),
    $now->format('Y-m-d H:i:s'),
    $previousProductionStart->format('Y-m-d H:i:s'),
    $turnoInicio->format('Y-m-d H:i:s'),
    $turnoInicio->format('Y-m-d H:i:s'),
    $now->format('Y-m-d H:i:s'),
  ]);
  $productionRow = $productionStmt->fetch() ?: [];
  $currentRecordCount = (int)($productionRow['registros_actuales'] ?? 0);
  $currentAccumulated = $productionRow['acumulado_actual'] ?? null;
  $baseValue = $productionRow['valor_base'] ?? null;
  $hasCurrentProduction = $currentRecordCount > 0 && is_numeric($currentAccumulated);
  $acumuladoUsaAnterior = $hasCurrentProduction && is_numeric($baseValue);
  $metricConfig['kg_hora']['value'] = is_numeric($productionRow['kg_hora'] ?? null)
    ? (float)$productionRow['kg_hora']
    : null;
  $metricConfig['acumulado']['value'] = $hasCurrentProduction
    ? (is_numeric($baseValue) ? (float)$baseValue : 0.0) + (float)$currentAccumulated
    : null;
  $metricConfig['acumulado']['registros_actuales'] = $currentRecordCount;

  $previousProductionStmt = $productionPdo->prepare("
    SELECT
      (
        SELECT p4.kg_reales
        FROM sup_captura_produccion p4
        WHERE TIMESTAMP(p4.fecha, p4.hora) >= ?
          AND TIMESTAMP(p4.fecha, p4.hora) < ?
          AND p4.kg_reales IS NOT NULL
        ORDER BY p4.fecha DESC, p4.hora DESC, p4.id DESC
        LIMIT 1
      ) AS kg_hora,
      SUM(p5.kg_reales) AS acumulado
    FROM sup_captura_produccion p5
    WHERE TIMESTAMP(p5.fecha, p5.hora) >= ?
      AND TIMESTAMP(p5.fecha, p5.hora) < ?
  ");
  $previousProductionStmt->execute([
    $previousShiftStart->format('Y-m-d H:i:s'),
    $previousShiftEnd->format('Y-m-d H:i:s'),
    $previousShiftStart->format('Y-m-d H:i:s'),
    $previousShiftEnd->format('Y-m-d H:i:s'),
  ]);
  $previousProductionRow = $previousProductionStmt->fetch() ?: [];
  $previousShiftSummary['kg_hora'] = is_numeric($previousProductionRow['kg_hora'] ?? null)
    ? (float)$previousProductionRow['kg_hora']
    : null;
  $previousShiftSummary['acumulado'] = is_numeric($previousProductionRow['acumulado'] ?? null)
    ? (float)$previousProductionRow['acumulado']
    : null;
} catch (Throwable $e) {
  $metricConfig['kg_hora']['value'] = null;
  $metricConfig['acumulado']['value'] = null;
  $previousShiftSummary['kg_hora'] = null;
  $previousShiftSummary['acumulado'] = null;
}

$elapsedShiftSeconds = max(0, min(
  $turnoFin->getTimestamp() - $turnoInicio->getTimestamp(),
  $now->getTimestamp() - $turnoInicio->getTimestamp()
));
$elapsedShiftHours = (int)floor($elapsedShiftSeconds / 3600);
$acumuladoHorasEvaluadas = $acumuladoUsaAnterior
  ? min(12, $elapsedShiftHours + 1)
  : $elapsedShiftHours;
if ($acumuladoHorasEvaluadas > 0) {
  $metricConfig['acumulado']['semaforo'] = [
    'modo' => 'bandas',
    'bandas' => [
      [
        'max' => (875 * $acumuladoHorasEvaluadas) - 0.000001,
        'estado' => 'rojo',
        'leyenda' => '< ' . number_format(875 * $acumuladoHorasEvaluadas, 0, '.', ','),
      ],
      [
        'min' => 875 * $acumuladoHorasEvaluadas,
        'max' => (1000 * $acumuladoHorasEvaluadas) - 0.000001,
        'estado' => 'amarillo',
        'leyenda' => number_format(875 * $acumuladoHorasEvaluadas, 0, '.', ',') . '–' . number_format((1000 * $acumuladoHorasEvaluadas) - 1, 0, '.', ','),
      ],
      [
        'min' => 1000 * $acumuladoHorasEvaluadas,
        'estado' => 'verde',
        'leyenda' => '≥ ' . number_format(1000 * $acumuladoHorasEvaluadas, 0, '.', ','),
      ],
    ],
  ];
  $metricConfig['acumulado']['horas_transcurridas'] = $acumuladoHorasEvaluadas;
  $metricConfig['acumulado']['usa_valor_anterior'] = $acumuladoUsaAnterior;
}

$statusFor = static function ($value, array $metric): string {
  if (!is_numeric($value)) {
    return 'neutral';
  }

  $rule = (array)($metric['semaforo'] ?? []);
  if (($rule['modo'] ?? '') === 'bandas') {
    $number = (float)$value;
    foreach ((array)($rule['bandas'] ?? []) as $band) {
      $minimum = $band['min'] ?? null;
      $maximum = $band['max'] ?? null;
      if ((!is_numeric($minimum) || $number >= (float)$minimum)
        && (!is_numeric($maximum) || $number <= (float)$maximum)) {
        $status = (string)($band['estado'] ?? 'neutral');
        return in_array($status, ['verde', 'amarillo', 'rojo'], true) ? $status : 'neutral';
      }
    }
    return 'neutral';
  }
  if (($rule['modo'] ?? '') === 'rango') {
    $number = (float)$value;
    $greenMin = $rule['verde_min'] ?? null;
    $greenMax = $rule['verde_max'] ?? null;
    $yellowMin = $rule['amarillo_min'] ?? null;
    $yellowMax = $rule['amarillo_max'] ?? null;
    if (is_numeric($greenMin) && is_numeric($greenMax)
      && $number >= (float)$greenMin && $number <= (float)$greenMax) {
      return 'verde';
    }
    if (is_numeric($yellowMin) && is_numeric($yellowMax)
      && $number >= (float)$yellowMin && $number <= (float)$yellowMax) {
      return 'amarillo';
    }
    return 'rojo';
  }

  $yellow = $metric['amarillo_min'] ?? null;
  $green = $metric['verde_min'] ?? null;
  if (!is_numeric($yellow) || !is_numeric($green)) {
    return 'neutral';
  }

  $number = (float)$value;
  if ($number >= (float)$green) {
    return 'verde';
  }
  if ($number >= (float)$yellow) {
    return 'amarillo';
  }
  return 'rojo';
};

$metrics = [];
foreach ($metricConfig as $key => $metric) {
  $metric = (array)$metric;
  $value = $metric['value'] ?? null;
  $metrics[$key] = $metric + ['label' => $key, 'unit' => ''];
  $metrics[$key]['status'] = $statusFor($value, $metric);
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Avance de Producción Hora por Hora'),
  'supervisor' => $supervisor !== '' ? $supervisor : 'Sin asignar',
  'turno' => $turno,
  'turno_horario' => trim(sprintf('%s - %s', $turnoConfig['inicio'] ?? '', $turnoConfig['fin'] ?? ''), ' -'),
  'turno_inicio' => $turnoInicio->format('Y-m-d H:i:s'),
  'turno_fin' => $turnoFin->format('Y-m-d H:i:s'),
  'horas_transcurridas_turno' => $elapsedShiftHours,
  'metricas' => $metrics,
  'resumen_tarimas' => $tarimasResumen,
  'resumen_turno_anterior' => $previousShiftSummary,
  'actualizado' => $now->format('d/m/Y H:i'),
  'intervalo_actualizacion_ms' => max(60000, (int)($config['intervalo_actualizacion_ms'] ?? 300000)),
];
