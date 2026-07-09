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
$objetivoDiarioTarimas = max(0.01, (float)($config['objetivo_diario_tarimas'] ?? 22.0));
$tarimasAmarilloMinDiario = max(0.0, (float)($config['tarimas_amarillo_min_diario'] ?? 20.0));
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
$mpByProcessId = [];
$registerMp = static function (?int $processId, float $mpKilos) use (&$mpByProcessId): void {
  if ($processId === null || $processId <= 0 || $mpKilos <= 0) {
    return;
  }

  $mpByProcessId[$processId] = $mpKilos;
};
foreach ($processStmt->fetchAll() ?: [] as $row) {
  $proId1 = isset($row['pro_id']) ? (int)$row['pro_id'] : null;
  $proId2 = isset($row['pro_id_2']) ? (int)$row['pro_id_2'] : null;
  $kilos = (float)($row['kilos'] ?? 0);
  $toneladas = (float)($row['toneladas'] ?? 0);
  $tarimas = (int)($row['tarimas'] ?? 0);
  $tarimasFinos = (int)($row['tarimas_finos'] ?? 0);
  $mp1 = is_numeric($row['mp_1'] ?? null) ? (float)$row['mp_1'] : 0.0;
  $hasSecondProcess = $proId2 !== null && $proId2 > 0 && $proId2 !== $proId1;
  $mp2 = $hasSecondProcess && is_numeric($row['mp_2'] ?? null) ? (float)$row['mp_2'] : 0.0;
  $mpKilos = $mp1 + $mp2;
  $rendimiento = $mpKilos > 0 ? ($kilos / $mpKilos) * 100 : null;

  $totalProcessKilos += $kilos;
  $totalProcessToneladas += $toneladas;
  $totalProcessTarimas += $tarimas;
  $totalProcessFinos += $tarimasFinos;
  $registerMp($proId1, $mp1);
  if ($hasSecondProcess) {
    $registerMp($proId2, $mp2);
  }

  $processRows[] = [
    'proc_1' => $proId1,
    'proc_2' => $proId2,
    'tarimas' => $tarimas,
    'kilos' => $kilos,
    'toneladas' => $toneladas,
    'mp_kilos' => $mpKilos > 0 ? $mpKilos : null,
    'tarimas_finos' => $tarimasFinos,
    'rendimiento' => $rendimiento,
  ];
}

$totalToneladas = array_sum(array_map(static fn(array $row): float => (float)$row['toneladas'], $dailySeries));
$totalTarimas = array_sum(array_map(static fn(array $row): int => (int)$row['tarimas'], $dailySeries));
$totalTarimasFinos = array_sum(array_map(static fn(array $row): int => (int)$row['tarimas_finos'], $dailySeries));
$totalBarreduraTon = array_sum(array_map(static fn(array $row): float => (float)$row['barredura_ton'], $dailySeries));
$totalKilosTarimas = array_sum(array_map(static fn(array $row): float => (float)$row['kilos'], $dailySeries));
$totalMpKilos = array_sum($mpByProcessId);
$porcentajeFinos = $totalTarimas > 0 ? ($totalTarimasFinos / $totalTarimas) * 100 : 0.0;
$rendimientoGlobal = $totalMpKilos > 0 ? ($totalKilosTarimas / $totalMpKilos) * 100 : 0.0;

$daysInRange = max(1, (int)$periodStart->diff($periodEnd)->days);
$objetivoTarimasPeriodo = $objetivoDiarioTarimas * $daysInRange;
$tarimasAmarilloMinPeriodo = $tarimasAmarilloMinDiario * $daysInRange;
$targetPeriod = $objetivoDiario * $daysInRange;
$produccionAmarilloMinDiario = 20.0;
$produccionAmarilloMinPeriodo = $produccionAmarilloMinDiario * $daysInRange;
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
$deficitToneladas = max(0.0, $objetivoAcumulado - $totalToneladas);
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
    'acumulado_toneladas' => $objetivoAcumulado,
    'comparacion_toneladas' => $objetivoComparacion,
    'produccion_amarillo_min_comparacion' => $produccionAmarilloMinComparacion,
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
    'tarimas' => $totalTarimas,
    'barredura_toneladas' => $totalBarreduraTon,
    'kilos_tarimas' => $totalKilosTarimas,
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
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time()
  ),
];
