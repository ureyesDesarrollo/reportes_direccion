<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$appConfig = $appConfig ?? require __DIR__ . '/../../config/app.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'America/Mazatlan');
date_default_timezone_set($timezone);
$tz = new DateTimeZone($timezone);

$horaCorte = (string)($config['hora_corte'] ?? '07:00:00');
if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $horaCorte, $corteMatches) !== 1) {
  $horaCorte = '07:00:00';
  $corteMatches = ['07:00:00', '07', '00', '00'];
}
$corteHoras = (int)$corteMatches[1];
$corteMinutos = (int)$corteMatches[2];
$corteSegundos = (int)$corteMatches[3];
$objetivoDiario = max(0.01, (float)($config['objetivo_diario_toneladas'] ?? 20.0));
$objetivoMensual = max($objetivoDiario, (float)($config['objetivo_mensual_toneladas'] ?? 600.0));
$produccionAmarilloMinDiario = max(0.0, (float)($config['produccion_amarillo_min_diario'] ?? 21.0));
$produccionVerdeMinDiario = max($produccionAmarilloMinDiario, (float)($config['produccion_verde_min_diario'] ?? 24.0));
$objetivoDiarioTarimas = max(0.01, (float)($config['objetivo_diario_tarimas'] ?? 24.0));
$tarimasAmarilloMinDiario = max(0.0, (float)($config['tarimas_amarillo_min_diario'] ?? 21.0));
$barreduraProId = (int)($config['barredura_pro_id'] ?? 2);
$intervaloActualizacion = (int)($config['intervalo_actualizacion_ms'] ?? ($appConfig['intervalo_actualizacion'] ?? 300000));

$monthNames = [
  1 => 'enero',
  2 => 'febrero',
  3 => 'marzo',
  4 => 'abril',
  5 => 'mayo',
  6 => 'junio',
  7 => 'julio',
  8 => 'agosto',
  9 => 'septiembre',
  10 => 'octubre',
  11 => 'noviembre',
  12 => 'diciembre',
];

$setCutoff = static function (DateTimeImmutable $date) use ($corteHoras, $corteMinutos, $corteSegundos): DateTimeImmutable {
  return $date->setTime($corteHoras, $corteMinutos, $corteSegundos);
};

$secondsOfDay = static function (DateTimeImmutable $date): int {
  return ((int)$date->format('H') * 3600) + ((int)$date->format('i') * 60) + (int)$date->format('s');
};

$cutoffSeconds = ($corteHoras * 3600) + ($corteMinutos * 60) + $corteSegundos;
$productionDate = static function (DateTimeImmutable $date) use ($secondsOfDay, $cutoffSeconds): DateTimeImmutable {
  return $secondsOfDay($date) < $cutoffSeconds
    ? $date->modify('-1 day')
    : $date;
};

$safeInt = static function ($value, int $fallback, int $min, int $max): int {
  if (!is_scalar($value) || !is_numeric($value)) {
    return $fallback;
  }

  $number = (int)$value;
  if ($number < $min || $number > $max) {
    return $fallback;
  }

  return $number;
};

$currentProductionDate = $productionDate(new DateTimeImmutable('now', $tz))->setTime(0, 0, 0);
$selectedYear = $safeInt($_GET['anio'] ?? null, (int)$currentProductionDate->format('Y'), 2020, 2100);
$selectedMonth = $safeInt($_GET['mes'] ?? null, (int)$currentProductionDate->format('n'), 1, 12);
$selectedWeek = trim((string)($_GET['semana'] ?? 'all'));

$pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'prod')] ?? $dbConfig['prod']));

$operationDateSql = "DATE(CASE WHEN TIME(t.tar_fecha) < '{$horaCorte}' THEN DATE_SUB(t.tar_fecha, INTERVAL 1 DAY) ELSE t.tar_fecha END)";

$yearStmt = $pdo->query("
  SELECT DISTINCT YEAR(op_dia) AS anio
  FROM (
    SELECT {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_count_etiquetado > 0
  ) y
  ORDER BY anio DESC
");
$yearOptions = array_values(array_filter(array_map('intval', array_column($yearStmt->fetchAll() ?: [], 'anio'))));
if (!in_array($selectedYear, $yearOptions, true)) {
  $yearOptions[] = $selectedYear;
  rsort($yearOptions);
}

$monthStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
$monthEnd = $monthStart->modify('first day of next month');
$monthQueryStart = $setCutoff($monthStart);
$monthQueryEnd = $setCutoff($monthEnd);

$weekStmt = $pdo->prepare("
  SELECT
    DATE_FORMAT(op_dia, '%x-W%v') AS week_key,
    DATE_FORMAT(op_dia, 'S%v') AS week_label,
    MIN(op_dia) AS start_date,
    MAX(op_dia) AS end_date
  FROM (
    SELECT {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND t.tar_count_etiquetado > 0
  ) w
  WHERE op_dia >= ?
    AND op_dia < ?
  GROUP BY week_key, week_label
  ORDER BY MIN(op_dia)
");
$weekStmt->execute([
  $monthQueryStart->format('Y-m-d H:i:s'),
  $monthQueryEnd->format('Y-m-d H:i:s'),
  $monthStart->format('Y-m-d'),
  $monthEnd->format('Y-m-d'),
]);
$weekOptions = [];
foreach ($weekStmt->fetchAll() ?: [] as $row) {
  $key = (string)($row['week_key'] ?? '');
  if ($key === '') {
    continue;
  }
  $weekOptions[$key] = [
    'key' => $key,
    'label' => (string)($row['week_label'] ?? $key),
    'start' => (string)($row['start_date'] ?? ''),
    'end' => (string)($row['end_date'] ?? ''),
  ];
}

if ($selectedWeek !== 'all' && !isset($weekOptions[$selectedWeek])) {
  $selectedWeek = 'all';
}

$periodStart = $monthStart;
$periodEnd = $monthEnd;
if ($selectedWeek !== 'all') {
  if (preg_match('/^(\d{4})-W(\d{2})$/', $selectedWeek, $weekMatches) === 1) {
    $weekStart = (new DateTimeImmutable('now', $tz))
      ->setISODate((int)$weekMatches[1], (int)$weekMatches[2], 1)
      ->setTime(0, 0, 0);
    $weekEnd = $weekStart->modify('+7 days');
    $periodStart = $weekStart > $monthStart ? $weekStart : $monthStart;
    $periodEnd = $weekEnd < $monthEnd ? $weekEnd : $monthEnd;
  }
}

$periodQueryStart = $setCutoff($periodStart);
$periodQueryEnd = $setCutoff($periodEnd);

$dailyStmt = $pdo->prepare("
  SELECT
    op_dia,
    DAY(op_dia) AS dia_num,
    COUNT(*) AS tarimas,
    SUM(tar_kilos) AS kilos,
    SUM(tar_kilos) / 1000 AS toneladas,
    SUM(CASE WHEN tar_fino = 'F' THEN 1 ELSE 0 END) AS tarimas_finos,
    SUM(CASE WHEN pro_id = ? OR pro_id_2 = ? THEN tar_kilos ELSE 0 END) / 1000 AS barredura_ton
  FROM (
    SELECT t.*, {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND t.tar_count_etiquetado > 0
  ) d
  WHERE op_dia >= ?
    AND op_dia < ?
  GROUP BY op_dia
  ORDER BY op_dia
");
$dailyStmt->execute([
  $barreduraProId,
  $barreduraProId,
  $periodQueryStart->format('Y-m-d H:i:s'),
  $periodQueryEnd->format('Y-m-d H:i:s'),
  $periodStart->format('Y-m-d'),
  $periodEnd->format('Y-m-d'),
]);

$dailyRowsByDate = [];
foreach ($dailyStmt->fetchAll() ?: [] as $row) {
  $dateKey = (string)($row['op_dia'] ?? '');
  if ($dateKey === '') {
    continue;
  }

  $dailyRowsByDate[$dateKey] = [
    'date' => $dateKey,
    'day' => (int)($row['dia_num'] ?? 0),
    'tarimas' => (int)($row['tarimas'] ?? 0),
    'kilos' => (float)($row['kilos'] ?? 0),
    'toneladas' => (float)($row['toneladas'] ?? 0),
    'tarimas_finos' => (int)($row['tarimas_finos'] ?? 0),
    'barredura_ton' => (float)($row['barredura_ton'] ?? 0),
  ];
}

$dailySeries = [];
for ($cursor = $periodStart; $cursor < $periodEnd; $cursor = $cursor->modify('+1 day')) {
  $dateKey = $cursor->format('Y-m-d');
  $row = $dailyRowsByDate[$dateKey] ?? [
    'date' => $dateKey,
    'day' => (int)$cursor->format('j'),
    'tarimas' => 0,
    'kilos' => 0.0,
    'toneladas' => 0.0,
    'tarimas_finos' => 0,
    'barredura_ton' => 0.0,
  ];

  $dailySeries[] = $row;
}

$processStmt = $pdo->prepare("
  SELECT
    d.pro_id,
    d.pro_id_2,
    COUNT(*) AS tarimas,
    SUM(d.tar_kilos) AS kilos,
    SUM(d.tar_kilos) / 1000 AS toneladas,
    SUM(CASE WHEN d.tar_fino = 'F' THEN 1 ELSE 0 END) AS tarimas_finos,
    MAX(mp1.mp_kilos) AS mp_1,
    MAX(mp2.mp_kilos) AS mp_2
  FROM (
    SELECT t.*, {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND t.tar_count_etiquetado > 0
  ) d
  LEFT JOIN (
    SELECT
      pm.pro_id,
      SUM(i.inv_kilos) AS mp_kilos
    FROM procesos_materiales pm
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    GROUP BY pm.pro_id
  ) mp1 ON mp1.pro_id = d.pro_id
  LEFT JOIN (
    SELECT
      pm.pro_id,
      SUM(i.inv_kilos) AS mp_kilos
    FROM procesos_materiales pm
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    GROUP BY pm.pro_id
  ) mp2 ON mp2.pro_id = d.pro_id_2
    AND d.pro_id_2 IS NOT NULL
    AND d.pro_id_2 <> 0
    AND d.pro_id_2 <> d.pro_id
  WHERE d.op_dia >= ?
    AND d.op_dia < ?
    AND EXISTS (
      SELECT 1
      FROM procesos_agrupados pa_cerrado_1
      INNER JOIN lotes_anio lote_cerrado_1
        ON lote_cerrado_1.lote_id = pa_cerrado_1.lote_id
       AND lote_cerrado_1.lote_estatus = 3
      WHERE pa_cerrado_1.pro_id = d.pro_id
    )
    AND (
      d.pro_id_2 IS NULL
      OR d.pro_id_2 = 0
      OR d.pro_id_2 = d.pro_id
      OR EXISTS (
        SELECT 1
        FROM procesos_agrupados pa_cerrado_2
        INNER JOIN lotes_anio lote_cerrado_2
          ON lote_cerrado_2.lote_id = pa_cerrado_2.lote_id
         AND lote_cerrado_2.lote_estatus = 3
        WHERE pa_cerrado_2.pro_id = d.pro_id_2
      )
    )
  GROUP BY d.pro_id, d.pro_id_2
  ORDER BY toneladas DESC, d.pro_id ASC, d.pro_id_2 ASC
");
$processStmt->execute([
  $periodQueryStart->format('Y-m-d H:i:s'),
  $periodQueryEnd->format('Y-m-d H:i:s'),
  $periodStart->format('Y-m-d'),
  $periodEnd->format('Y-m-d'),
]);

$processRows = [];
$totalProcessKilos = 0.0;
$totalProcessToneladas = 0.0;
$totalProcessTarimas = 0;
$totalProcessFinos = 0;
$totalMpKilos = 0.0;
$processDataRows = $processStmt->fetchAll() ?: [];
$candidateProcessIds = [];
$candidateProcessPairs = [];
$processPairKey = static function (?int $processId1, ?int $processId2): string {
  $processId1 = $processId1 !== null && $processId1 > 0 ? $processId1 : 0;
  $processId2 = $processId2 !== null && $processId2 > 0 && $processId2 !== $processId1 ? $processId2 : 0;
  return $processId1 . '|' . $processId2;
};
foreach ($processDataRows as $row) {
  $processId1 = isset($row['pro_id']) ? (int)$row['pro_id'] : 0;
  $processId2 = isset($row['pro_id_2']) ? (int)$row['pro_id_2'] : 0;
  $candidateProcessPairs[$processPairKey($processId1, $processId2)] = true;
  foreach (['pro_id', 'pro_id_2'] as $processField) {
    $processId = isset($row[$processField]) ? (int)$row[$processField] : 0;
    if ($processId > 0) {
      $candidateProcessIds[$processId] = $processId;
    }
  }
}

$productionByPair = [];
if ($candidateProcessIds !== []) {
  $candidateProcessIds = array_values($candidateProcessIds);
  $processPlaceholders = implode(',', array_fill(0, count($candidateProcessIds), '?'));
  $productionByPairStmt = $pdo->prepare("
    SELECT
      d.pro_id,
      d.pro_id_2,
      d.op_dia,
      COUNT(*) AS tarimas,
      SUM(d.tar_kilos) AS kilos,
      SUM(CASE WHEN d.tar_fino = 'F' THEN 1 ELSE 0 END) AS tarimas_finos
    FROM (
      SELECT t.*, {$operationDateSql} AS op_dia
      FROM rev_tarimas t
      WHERE t.tar_count_etiquetado > 0
        AND (t.pro_id IN ({$processPlaceholders}) OR t.pro_id_2 IN ({$processPlaceholders}))
    ) d
    GROUP BY d.pro_id, d.pro_id_2, d.op_dia
    ORDER BY d.op_dia
  ");
  $productionByPairStmt->execute(array_merge($candidateProcessIds, $candidateProcessIds));
  foreach ($productionByPairStmt->fetchAll() ?: [] as $productionRow) {
    $processId1 = isset($productionRow['pro_id']) ? (int)$productionRow['pro_id'] : 0;
    $processId2 = isset($productionRow['pro_id_2']) ? (int)$productionRow['pro_id_2'] : 0;
    $pairKey = $processPairKey($processId1, $processId2);
    if (!isset($candidateProcessPairs[$pairKey])) {
      continue;
    }

    $operationDay = (string)($productionRow['op_dia'] ?? '');
    $periodKey = substr($operationDay, 0, 7);
    if (!isset($productionByPair[$pairKey])) {
      $productionByPair[$pairKey] = [
        'tarimas' => 0,
        'kilos' => 0.0,
        'tarimas_finos' => 0,
        'periodos' => [],
      ];
    }
    if (!isset($productionByPair[$pairKey]['periodos'][$periodKey])) {
      $productionByPair[$pairKey]['periodos'][$periodKey] = ['tarimas' => 0, 'kilos' => 0.0];
    }

    $tarimasDia = (int)($productionRow['tarimas'] ?? 0);
    $kilosDia = (float)($productionRow['kilos'] ?? 0);
    $productionByPair[$pairKey]['tarimas'] += $tarimasDia;
    $productionByPair[$pairKey]['kilos'] += $kilosDia;
    $productionByPair[$pairKey]['tarimas_finos'] += (int)($productionRow['tarimas_finos'] ?? 0);
    $productionByPair[$pairKey]['periodos'][$periodKey]['tarimas'] += $tarimasDia;
    $productionByPair[$pairKey]['periodos'][$periodKey]['kilos'] += $kilosDia;
  }

  foreach ($productionByPair as &$pairProduction) {
    $dominantPeriod = null;
    $dominantValues = ['tarimas' => -1, 'kilos' => -1.0];
    foreach ($pairProduction['periodos'] as $periodKey => $periodValues) {
      $isDominant = (int)$periodValues['tarimas'] > (int)$dominantValues['tarimas']
        || (
          (int)$periodValues['tarimas'] === (int)$dominantValues['tarimas']
          && (float)$periodValues['kilos'] > (float)$dominantValues['kilos']
        )
        || (
          (int)$periodValues['tarimas'] === (int)$dominantValues['tarimas']
          && (float)$periodValues['kilos'] === (float)$dominantValues['kilos']
          && ($dominantPeriod === null || strcmp((string)$periodKey, $dominantPeriod) < 0)
        );
      if ($isDominant) {
        $dominantPeriod = (string)$periodKey;
        $dominantValues = $periodValues;
      }
    }
    $pairProduction['periodo_dominante'] = $dominantPeriod;
  }
  unset($pairProduction);
}

$selectedYieldPeriod = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
foreach ($processDataRows as $row) {
  $proId1 = isset($row['pro_id']) ? (int)$row['pro_id'] : null;
  $proId2 = isset($row['pro_id_2']) ? (int)$row['pro_id_2'] : null;
  $pairKey = $processPairKey($proId1, $proId2);
  $pairProduction = (array)($productionByPair[$pairKey] ?? []);
  if (($pairProduction['periodo_dominante'] ?? null) !== $selectedYieldPeriod) {
    continue;
  }

  $kilos = (float)($pairProduction['kilos'] ?? 0);
  $toneladas = $kilos / 1000;
  $tarimas = (int)($pairProduction['tarimas'] ?? 0);
  $tarimasFinos = (int)($pairProduction['tarimas_finos'] ?? 0);
  $mp1 = is_numeric($row['mp_1'] ?? null) ? (float)$row['mp_1'] : 0.0;
  $hasSecondProcess = $proId2 !== null && $proId2 > 0 && $proId2 !== $proId1;
  $mp2 = $hasSecondProcess && is_numeric($row['mp_2'] ?? null) ? (float)$row['mp_2'] : 0.0;
  $mpKilos = $mp1 + $mp2;
  $rendimiento = $mpKilos > 0 ? ($kilos / $mpKilos) * 100 : null;

  $totalProcessKilos += $kilos;
  $totalProcessToneladas += $toneladas;
  $totalProcessTarimas += $tarimas;
  $totalProcessFinos += $tarimasFinos;
  $totalMpKilos += $mpKilos;

  $processRows[] = [
    'proc_1' => $proId1,
    'proc_2' => $proId2,
    'tarimas' => $tarimas,
    'kilos' => $kilos,
    'toneladas' => $toneladas,
    'mp_kilos' => $mpKilos > 0 ? $mpKilos : null,
    'mp_kilos_proceso_completo' => $mpKilos > 0 ? $mpKilos : null,
    'tarimas_finos' => $tarimasFinos,
    'rendimiento' => $rendimiento,
  ];
}

$totalToneladas = array_sum(array_map(static fn(array $row): float => (float)$row['toneladas'], $dailySeries));
$totalTarimas = array_sum(array_map(static fn(array $row): int => (int)$row['tarimas'], $dailySeries));
$totalTarimasFinos = array_sum(array_map(static fn(array $row): int => (int)$row['tarimas_finos'], $dailySeries));
$totalBarreduraTon = array_sum(array_map(static fn(array $row): float => (float)$row['barredura_ton'], $dailySeries));
$totalKilosTarimas = array_sum(array_map(static fn(array $row): float => (float)$row['kilos'], $dailySeries));
$currentProductionDateKey = $currentProductionDate->format('Y-m-d');
$totalToneladasCerradas = array_sum(array_map(
  static fn(array $row): float => (string)($row['date'] ?? '') < $currentProductionDateKey
    ? (float)($row['toneladas'] ?? 0)
    : 0.0,
  $dailySeries
));
$porcentajeFinos = $totalTarimas > 0 ? ($totalTarimasFinos / $totalTarimas) * 100 : 0.0;
$rendimientoGlobal = $totalMpKilos > 0 ? ($totalProcessKilos / $totalMpKilos) * 100 : 0.0;

$daysInRange = max(1, (int)$periodStart->diff($periodEnd)->days);
$objetivoTarimasPeriodo = $objetivoDiarioTarimas * $daysInRange;
$tarimasAmarilloMinPeriodo = $tarimasAmarilloMinDiario * $daysInRange;
$targetPeriod = $objetivoDiario * $daysInRange;
$produccionAmarilloMinPeriodo = $produccionAmarilloMinDiario * $daysInRange;
$produccionVerdeMinPeriodo = $produccionVerdeMinDiario * $daysInRange;
$todayStart = $currentProductionDate;
if ($todayStart <= $periodStart) {
  $diasTranscurridos = 0;
} elseif ($todayStart >= $periodEnd) {
  $diasTranscurridos = $daysInRange;
} else {
  $diasTranscurridos = max(0, (int)$periodStart->diff($todayStart)->days);
}
$diasConProduccion = count(array_filter($dailySeries, static fn(array $row): bool => (float)$row['toneladas'] > 0));
$diasPromedio = $diasTranscurridos > 0 ? $diasTranscurridos : max(1, $diasConProduccion);
$promedioDiario = $totalToneladas / max(1, $diasPromedio);
$diasParaMeta = max(0.0, ($targetPeriod - $totalToneladas) / $objetivoDiario);
$objetivoAcumulado = $objetivoDiario * max(0, $diasTranscurridos);
$objetivoComparacion = $objetivoDiario * max(1, $diasPromedio);
$produccionAmarilloMinComparacion = $produccionAmarilloMinDiario * max(1, $diasPromedio);
$produccionVerdeMinComparacion = $produccionVerdeMinDiario * max(1, $diasPromedio);
$deficitToneladas = max(0.0, $objetivoAcumulado - $totalToneladasCerradas);
$deficitDias = $deficitToneladas / $objetivoDiario;

$barreduraRows = array_values(array_filter(array_map(static function (array $row): array {
  return [
    'day' => (int)$row['day'],
    'date' => (string)$row['date'],
    'toneladas' => (float)$row['barredura_ton'],
  ];
}, $dailySeries), static fn(array $row): bool => (float)$row['toneladas'] > 0));

$formatDate = static function (DateTimeImmutable $date): string {
  return $date->format('d/m/Y');
};

return [
  'titulo' => (string)($config['titulo'] ?? 'Avance Producción'),
  'filtros' => [
    'anio' => $selectedYear,
    'mes' => $selectedMonth,
    'semana' => $selectedWeek,
    'anios' => $yearOptions,
    'meses' => $monthNames,
    'semanas' => array_values($weekOptions),
    'periodo_label' => ($selectedWeek === 'all' ? ucfirst($monthNames[$selectedMonth]) . ' ' . $selectedYear : (($weekOptions[$selectedWeek]['label'] ?? $selectedWeek) . ' | ' . ucfirst($monthNames[$selectedMonth]) . ' ' . $selectedYear)),
    'periodo_inicio' => $formatDate($periodStart),
    'periodo_fin' => $formatDate($periodEnd->modify('-1 day')),
  ],
  'objetivos' => [
    'diario_toneladas' => $objetivoDiario,
    'periodo_toneladas' => $targetPeriod,
    'produccion_amarillo_min_periodo' => $produccionAmarilloMinPeriodo,
    'produccion_verde_min_periodo' => $produccionVerdeMinPeriodo,
    'acumulado_toneladas' => $objetivoAcumulado,
    'comparacion_toneladas' => $objetivoComparacion,
    'produccion_amarillo_min_comparacion' => $produccionAmarilloMinComparacion,
    'produccion_verde_min_comparacion' => $produccionVerdeMinComparacion,
    'dias_periodo' => $daysInRange,
    'dias_transcurridos' => $diasTranscurridos,
    'dias_promedio' => $diasPromedio,
    'diario_tarimas' => $objetivoDiarioTarimas,
    'tarimas_periodo' => $objetivoTarimasPeriodo,
    'tarimas_amarillo_min_periodo' => $tarimasAmarilloMinPeriodo,
  ],
  'kpis' => [
    'toneladas' => $totalToneladas,
    'rendimiento' => $rendimientoGlobal,
    'porcentaje_finos' => $porcentajeFinos,
    'tarimas_finos' => $totalTarimasFinos,
    'promedio_diario' => $promedioDiario,
    'dias_para_meta' => $diasParaMeta,
    'deficit_toneladas' => $deficitToneladas,
    'deficit_dias' => $deficitDias,
    'toneladas_cerradas_deficit' => $totalToneladasCerradas,
    'tarimas' => $totalTarimas,
    'barredura_toneladas' => $totalBarreduraTon,
    'kilos_tarimas' => $totalKilosTarimas,
    'kilos_tarimas_rendimiento' => $totalProcessKilos,
    'mp_kilos' => $totalMpKilos,
  ],
  'series' => [
    'diaria' => $dailySeries,
    'barredura' => $barreduraRows,
  ],
  'tablas' => [
    'barredura' => $barreduraRows,
    'procesos' => $processRows,
    'procesos_total' => [
      'tarimas' => $totalProcessTarimas,
      'kilos' => $totalProcessKilos,
      'toneladas' => $totalProcessToneladas,
      'mp_kilos' => $totalMpKilos,
      'tarimas_finos' => $totalProcessFinos,
      'rendimiento' => $rendimientoGlobal,
    ],
  ],
  'meta' => [
    'timezone' => $timezone,
    'hora_corte' => $horaCorte,
    'intervaloActualizacion' => $intervaloActualizacion,
    'actualizado' => (new DateTimeImmutable('now', $tz))->format('d/m/Y H:i'),
    'rendimiento_solo_procesos_cerrados' => true,
    'rendimiento_asignado_periodo_mayor_tarimas' => true,
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time()
  ),
];
