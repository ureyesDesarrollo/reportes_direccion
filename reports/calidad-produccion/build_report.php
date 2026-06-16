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
$corteSegundosTotales = ($corteHoras * 3600) + ($corteMinutos * 60) + $corteSegundos;
$productosExcluidos = array_values(array_filter(array_map('intval', (array)($config['productos_excluidos'] ?? [])), static fn(int $id): bool => $id > 0));
$productosExcluidosSql = !empty($productosExcluidos) ? 'AND t.pro_id NOT IN (' . implode(',', $productosExcluidos) . ')' : '';

$setHoraCorte = static function (DateTimeImmutable $date) use ($corteHoras, $corteMinutos, $corteSegundos): DateTimeImmutable {
  return $date->setTime($corteHoras, $corteMinutos, $corteSegundos);
};

$segundosDelDia = static function (DateTimeImmutable $date): int {
  return ((int)$date->format('H') * 3600) + ((int)$date->format('i') * 60) + (int)$date->format('s');
};

$fechaCierreTurno = static function (DateTimeImmutable $date) use ($segundosDelDia, $corteSegundosTotales): DateTimeImmutable {
  return $segundosDelDia($date) >= $corteSegundosTotales
    ? $date->modify('+1 day')
    : $date;
};

$fechaProduccion = static function (DateTimeImmutable $date) use ($segundosDelDia, $corteSegundosTotales): DateTimeImmutable {
  return $segundosDelDia($date) < $corteSegundosTotales
    ? $date->modify('-1 day')
    : $date;
};

$semanaKey = static function (DateTimeImmutable $date): string {
  return $date->format('o-\WW');
};

$semanaInicio = static function (DateTimeImmutable $date, DateTimeZone $tz): DateTimeImmutable {
  return (new DateTimeImmutable('now', $tz))
    ->setISODate((int)$date->format('o'), (int)$date->format('W'), 1)
    ->setTime(0, 0, 0);
};

$periodosValidos = ['dia', 'semana', 'mes'];
$periodo = isset($_GET['periodo']) && in_array((string)$_GET['periodo'], $periodosValidos, true)
  ? (string)$_GET['periodo']
  : (string)($config['default_periodo'] ?? 'dia');

$fechaReferenciaDefault = (new DateTimeImmutable('today', $tz))->modify('-2 days');
$fechaDefault = $fechaReferenciaDefault->format('Y-m-d');
$semanaDefault = $fechaReferenciaDefault->format('o-\WW');
$mesDefault = $fechaReferenciaDefault->format('Y-m');

$parseDate = static function (string $value, string $fallback, DateTimeZone $tz): DateTimeImmutable {
  $value = trim($value);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
    $value = $fallback;
  }

  return new DateTimeImmutable($value . ' 00:00:00', $tz);
};

$parseWeek = static function (string $value, string $fallback, DateTimeZone $tz): DateTimeImmutable {
  $value = trim($value);
  if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $matches) !== 1) {
    $value = $fallback;
    preg_match('/^(\d{4})-W(\d{2})$/', $value, $matches);
  }

  $date = new DateTimeImmutable('now', $tz);
  return $date->setISODate((int)$matches[1], (int)$matches[2], 1)->setTime(0, 0, 0);
};

$parseMonth = static function (string $value, string $fallback, DateTimeZone $tz): DateTimeImmutable {
  $value = trim($value);
  if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
    $value = $fallback;
  }

  return new DateTimeImmutable($value . '-01 00:00:00', $tz);
};

$fechaSeleccionada = isset($_GET['fecha']) ? (string)$_GET['fecha'] : $fechaDefault;
$semanaSeleccionada = isset($_GET['semana']) ? (string)$_GET['semana'] : $semanaDefault;
$mesSeleccionado = isset($_GET['mes']) ? (string)$_GET['mes'] : $mesDefault;

if ($periodo === 'semana') {
  $inicio = $parseWeek($semanaSeleccionada, $semanaDefault, $tz);
  $fin = $inicio->modify('+6 days');
  $controlValue = $inicio->format('o-\WW');
  $periodoLabel = 'Semana ' . $inicio->format('W') . ' (' . $inicio->format('Y-m-d') . ' a ' . $fin->format('Y-m-d') . ')';
} elseif ($periodo === 'mes') {
  $inicio = $parseMonth($mesSeleccionado, $mesDefault, $tz);
  $fin = $inicio->modify('last day of this month');
  $controlValue = $inicio->format('Y-m');
  $periodoLabel = $inicio->format('Y-m');
} else {
  $inicio = $parseDate($fechaSeleccionada, $fechaDefault, $tz);
  $fin = $inicio;
  $controlValue = $inicio->format('Y-m-d');
  $periodoLabel = $inicio->format('Y-m-d');
}

$finExclusivo = $fin->modify('+1 day');

if ($periodo === 'semana') {
  $periodoInicioConsulta = $setHoraCorte($inicio);
  $periodoFinExclusivoConsulta = $setHoraCorte($inicio->modify('+1 week'));
} elseif ($periodo === 'mes') {
  $periodoInicioConsulta = $setHoraCorte($inicio);
  $periodoFinExclusivoConsulta = $setHoraCorte($inicio->modify('first day of next month'));
} else {
  $periodoInicioConsulta = $setHoraCorte($inicio->modify('-1 day'));
  $periodoFinExclusivoConsulta = $setHoraCorte($inicio);
}

$periodoRangoLabel = $periodoInicioConsulta->format('Y-m-d H:i')
  . ' a ' . $periodoFinExclusivoConsulta->format('Y-m-d H:i');

$targets = (array)($config['targets'] ?? []);
$semaforoCalidades = (array)($config['semaforo_calidades'] ?? []);
$semaforoFisicoquimicos = (array)($config['semaforo_fisicoquimicos'] ?? []);
$colores = (array)($config['colores'] ?? []);
$ordenCalidades = ['Azul', 'Dorada', 'Verde', 'Morada', 'Otros', 'Sin Calidad', 'Por Definir'];

$evaluarSemaforo = static function (?float $valor, array $reglas): array {
  if ($valor === null) {
    return [
      'estado' => 'Sin dato',
      'estado_key' => 'sin-dato',
      'rango_label' => 'Sin dato',
    ];
  }

  foreach ($reglas as $regla) {
    $min = array_key_exists('min', $regla) ? (float)$regla['min'] : null;
    $max = array_key_exists('max', $regla) ? (float)$regla['max'] : null;
    $minInclusive = array_key_exists('min_inclusive', $regla) ? (bool)$regla['min_inclusive'] : true;
    $maxInclusive = array_key_exists('max_inclusive', $regla) ? (bool)$regla['max_inclusive'] : true;

    if ($min !== null && ($minInclusive ? $valor < $min : $valor <= $min)) {
      continue;
    }
    if ($max !== null && ($maxInclusive ? $valor > $max : $valor >= $max)) {
      continue;
    }

    return [
      'estado' => (string)($regla['estado'] ?? ucfirst((string)($regla['estado_key'] ?? ''))),
      'estado_key' => (string)($regla['estado_key'] ?? 'sin-rango'),
      'rango_label' => (string)($regla['label'] ?? ''),
    ];
  }

  return [
    'estado' => 'Sin rango',
    'estado_key' => 'sin-rango',
    'rango_label' => 'Sin rango',
  ];
};

$rangoVerdeLabel = static function (array $reglas): ?string {
  foreach ($reglas as $regla) {
    if ((string)($regla['estado_key'] ?? '') === 'verde') {
      return (string)($regla['label'] ?? '');
    }
  }

  return null;
};

$clasificarCalidad = static function (?string $descripcion, ?int $calId = null): string {
  if (in_array($calId, [2, 4], true)) {
    return 'Dorada';
  }
  if (in_array($calId, [1, 6], true)) {
    return 'Verde';
  }

  if ($descripcion === null || $descripcion === '') {
    return 'Sin Calidad';
  }
  
  $descripcion = trim((string)$descripcion);
  if ($descripcion === '') {
    return 'Sin Calidad';
  }
  
  $normalizada = strtolower($descripcion);

  if ($normalizada === 'azul') {
    return 'Azul';
  }
  if ($normalizada === 'dorada' || $normalizada === '280') {
    return 'Dorada';
  }
  if ($normalizada === 'morada') {
    return 'Morada';
  }
  if ($normalizada === 'verde' || $normalizada === '250') {
    return 'Verde';
  }

  return 'Otros';
};

$pdo = conectar($dbConfig['prod']);

$sql = "
  SELECT
    t.tar_id,
    t.tar_folio,
    t.tar_fecha,
    t.tar_bloom,
    t.tar_viscosidad,
    t.tar_kilos,
    t.cal_id,
    c.cal_descripcion,
    c.cal_color
  FROM rev_tarimas t
  LEFT JOIN rev_calidad c ON c.cal_id = t.cal_id
  WHERE t.tar_fecha >= ?
    AND t.tar_fecha < ?
    {$productosExcluidosSql}
  ORDER BY t.tar_fecha ASC, t.tar_id ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  $periodoInicioConsulta->format('Y-m-d H:i:s'),
  $periodoFinExclusivoConsulta->format('Y-m-d H:i:s'),
]);

$calidades = [];
foreach ($ordenCalidades as $calidad) {
  $calidades[$calidad] = [
    'calidad' => $calidad,
    'target' => isset($targets[$calidad]) ? (float)$targets[$calidad] : null,
    'color' => (string)($colores[$calidad] ?? '#64748b'),
    'tarimas' => 0,
    'kilos' => 0.0,
    'bloom_sum' => 0.0,
    'viscosidad_sum' => 0.0,
  ];
}

$totalTarimas = 0;
$totalKilos = 0.0;
$bloomSum = 0.0;
$viscosidadSum = 0.0;

while ($row = $stmt->fetch()) {
  $calidad = $clasificarCalidad(
    $row['cal_descripcion'] ?? null,
    isset($row['cal_id']) ? (int)$row['cal_id'] : null
  );
  if (!isset($calidades[$calidad])) {
    $calidad = 'Otros';
  }

  $kilos = (float)($row['tar_kilos'] ?? 0);
  $bloom = (float)($row['tar_bloom'] ?? 0);
  $viscosidad = (float)($row['tar_viscosidad'] ?? 0);

  $calidades[$calidad]['tarimas']++;
  $calidades[$calidad]['kilos'] += $kilos;
  $calidades[$calidad]['bloom_sum'] += $bloom;
  $calidades[$calidad]['viscosidad_sum'] += $viscosidad;
  $totalTarimas++;
  $totalKilos += $kilos;
  $bloomSum += $bloom;
  $viscosidadSum += $viscosidad;
}

$resumenCalidades = [];
$pieLabels = [];
$pieData = [];
$pieColors = [];

foreach ($ordenCalidades as $calidad) {
  $item = $calidades[$calidad];
  $pct = $totalTarimas > 0 ? ($item['tarimas'] / $totalTarimas) * 100 : 0.0;
  $avgBloom = $item['tarimas'] > 0 ? $item['bloom_sum'] / $item['tarimas'] : null;
  $avgViscosidad = $item['tarimas'] > 0 ? $item['viscosidad_sum'] / $item['tarimas'] : null;
  $target = $item['target'];
  $reglasCalidad = (array)($semaforoCalidades[$calidad] ?? []);
  $semaforoCalidad = $evaluarSemaforo($totalTarimas > 0 ? $pct : null, $reglasCalidad);
  $rangoVerdeCalidad = $rangoVerdeLabel($reglasCalidad);

  $resumenCalidades[] = [
    'calidad' => $calidad,
    'target' => $target,
    'color' => $item['color'],
    'tarimas' => $item['tarimas'],
    'kilos' => $item['kilos'],
    'porcentaje' => $pct,
    'avg_bloom' => $avgBloom,
    'avg_viscosidad' => $avgViscosidad,
    'estado' => $semaforoCalidad['estado'],
    'estado_key' => $semaforoCalidad['estado_key'],
    'rango_label' => $semaforoCalidad['rango_label'],
    'rango_verde_label' => $rangoVerdeCalidad,
    'semaforo' => !empty($reglasCalidad),
  ];

  if ($item['tarimas'] > 0) {
    $pieLabels[] = $calidad;
    $pieData[] = (int)$item['tarimas'];
    $pieColors[] = $item['color'];
  }

}

if ($periodo === 'semana') {
  $trendInicio = $inicio->modify('-11 weeks');
  $trendFin = $inicio;
  $trendStep = '+1 week';
  $trendLabel = static fn(DateTimeImmutable $date): string => 'S' . $date->format('W');
  $trendTitle = 'Comportamiento semanal';
  $trendInicioConsulta = $setHoraCorte($trendInicio);
  $trendFinExclusivoConsulta = $setHoraCorte($trendFin->modify('+1 week'));
  $trendKeyFromFecha = static function (DateTimeImmutable $date) use ($fechaProduccion, $semanaInicio, $semanaKey, $tz): string {
    return $semanaKey($semanaInicio($fechaProduccion($date), $tz));
  };
} elseif ($periodo === 'mes') {
  $trendInicio = $inicio->modify('-11 months');
  $trendFin = $inicio;
  $trendStep = '+1 month';
  $trendLabel = static fn(DateTimeImmutable $date): string => $date->format('m/Y');
  $trendTitle = 'Comportamiento mensual';
  $trendInicioConsulta = $setHoraCorte($trendInicio);
  $trendFinExclusivoConsulta = $setHoraCorte($trendFin->modify('first day of next month'));
  $trendKeyFromFecha = static fn(DateTimeImmutable $date): string => $fechaProduccion($date)->format('Y-m');
} else {
  $trendInicio = $inicio->modify('-13 days');
  $trendFin = $inicio;
  $trendStep = '+1 day';
  $trendLabel = static fn(DateTimeImmutable $date): string => $date->format('d/m');
  $trendTitle = 'Comportamiento diario';
  $trendInicioConsulta = $setHoraCorte($trendInicio->modify('-1 day'));
  $trendFinExclusivoConsulta = $setHoraCorte($trendFin);
  $trendKeyFromFecha = static fn(DateTimeImmutable $date): string => $fechaCierreTurno($date)->format('Y-m-d');
}

$trendBuckets = [];
for ($cursor = $trendInicio; $cursor <= $trendFin; $cursor = $cursor->modify($trendStep)) {
  $key = $periodo === 'mes'
    ? $cursor->format('Y-m')
    : ($periodo === 'semana' ? $cursor->format('o-\WW') : $cursor->format('Y-m-d'));

  $trendBuckets[$key] = [
    'label' => $trendLabel($cursor),
    'total_tarimas' => 0,
    'calidades' => array_fill_keys($ordenCalidades, 0),
  ];
}

$trendSql = "
  SELECT
    t.tar_fecha,
    t.cal_id,
    c.cal_descripcion,
    t.tar_kilos
  FROM rev_tarimas t
  LEFT JOIN rev_calidad c ON c.cal_id = t.cal_id
  WHERE t.tar_fecha >= ?
    AND t.tar_fecha < ?
    {$productosExcluidosSql}
  ORDER BY t.tar_fecha ASC, t.tar_id ASC
";

$stmtTrend = $pdo->prepare($trendSql);
$stmtTrend->execute([
  $trendInicioConsulta->format('Y-m-d H:i:s'),
  $trendFinExclusivoConsulta->format('Y-m-d H:i:s'),
]);

while ($row = $stmtTrend->fetch()) {
  $fechaTarima = new DateTimeImmutable((string)$row['tar_fecha'], $tz);
  $key = $trendKeyFromFecha($fechaTarima);
  if ($key === '' || !isset($trendBuckets[$key])) {
    continue;
  }

  $calidad = $clasificarCalidad(
    $row['cal_descripcion'] ?? null,
    isset($row['cal_id']) ? (int)$row['cal_id'] : null
  );
  if (!isset($trendBuckets[$key]['calidades'][$calidad])) {
    $calidad = 'Otros';
  }

  $trendBuckets[$key]['calidades'][$calidad]++;
  $trendBuckets[$key]['total_tarimas']++;
}

$trendDatasets = [];
foreach ($ordenCalidades as $calidad) {
  $data = [];
  foreach ($trendBuckets as $bucket) {
    $totalBucket = (int)$bucket['total_tarimas'];
    $tarimasCalidad = (int)($bucket['calidades'][$calidad] ?? 0);
    $data[] = $totalBucket > 0 ? round(($tarimasCalidad / $totalBucket) * 100, 2) : 0.0;
  }

  $hasData = array_sum($data) > 0 || $calidad !== 'Otros';
  if (!$hasData) {
    continue;
  }

  $color = (string)($colores[$calidad] ?? '#64748b');
  $trendDatasets[] = [
    'label' => $calidad,
    'data' => $data,
    'borderColor' => $color,
    'backgroundColor' => $color,
    'tension' => 0.28,
    'pointRadius' => 4,
    'pointHoverRadius' => 6,
    'borderWidth' => 2,
  ];
}

if ($periodo === 'mes') {
  $fisicoPeriodoInicio = $inicio;
  $fisicoInicio = $fisicoPeriodoInicio->modify('-4 months');
  $fisicoFinExclusivo = $fisicoPeriodoInicio->modify('+1 month');
  $fisicoStep = '+1 month';
  $fisicoVentanaLabel = 'Mes seleccionado + 4 anteriores';
  $fisicoKeyFormatter = static fn(DateTimeImmutable $date): string => $date->format('Y-m');
  $fisicoLabelFormatter = static fn(DateTimeImmutable $date): string => $date->format('m/Y');
  $fisicoDesdeOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date);
  $fisicoHastaOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date->modify('first day of next month'));
  $fisicoInicioConsulta = $setHoraCorte($fisicoInicio);
  $fisicoFinExclusivoConsulta = $setHoraCorte($fisicoFinExclusivo);
  $fisicoKeyFromFecha = static fn(DateTimeImmutable $date): string => $fechaProduccion($date)->format('Y-m');
} elseif ($periodo === 'semana') {
  $fisicoPeriodoInicio = $inicio;
  $fisicoInicio = $fisicoPeriodoInicio->modify('-4 weeks');
  $fisicoFinExclusivo = $fisicoPeriodoInicio->modify('+1 week');
  $fisicoStep = '+1 week';
  $fisicoVentanaLabel = 'Semana seleccionada + 4 anteriores';
  $fisicoKeyFormatter = static fn(DateTimeImmutable $date): string => $date->format('o-\WW');
  $fisicoLabelFormatter = static fn(DateTimeImmutable $date): string => 'S' . $date->format('W');
  $fisicoDesdeOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date);
  $fisicoHastaOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date->modify('+1 week'));
  $fisicoInicioConsulta = $setHoraCorte($fisicoInicio);
  $fisicoFinExclusivoConsulta = $setHoraCorte($fisicoFinExclusivo);
  $fisicoKeyFromFecha = static function (DateTimeImmutable $date) use ($fechaProduccion, $semanaInicio, $semanaKey, $tz): string {
    return $semanaKey($semanaInicio($fechaProduccion($date), $tz));
  };
} else {
  $fisicoPeriodoInicio = $inicio;
  $fisicoInicio = $fisicoPeriodoInicio->modify('-4 days');
  $fisicoFinExclusivo = $fisicoPeriodoInicio->modify('+1 day');
  $fisicoStep = '+1 day';
  $fisicoVentanaLabel = 'Día seleccionado + 4 anteriores';
  $fisicoKeyFormatter = static fn(DateTimeImmutable $date): string => $date->format('Y-m-d');
  $fisicoLabelFormatter = static fn(DateTimeImmutable $date): string => $date->format('d/m');
  $fisicoDesdeOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date->modify('-1 day'));
  $fisicoHastaOperativo = static fn(DateTimeImmutable $date): DateTimeImmutable => $setHoraCorte($date);
  $fisicoInicioConsulta = $setHoraCorte($fisicoInicio->modify('-1 day'));
  $fisicoFinExclusivoConsulta = $setHoraCorte($fisicoPeriodoInicio);
  $fisicoKeyFromFecha = static fn(DateTimeImmutable $date): string => $fechaCierreTurno($date)->format('Y-m-d');
}

$fisicoRangeFormatter = static function (DateTimeImmutable $date) use ($fisicoDesdeOperativo, $fisicoHastaOperativo): string {
  return $fisicoDesdeOperativo($date)->format('d/m H:i') . ' - ' . $fisicoHastaOperativo($date)->format('d/m H:i');
};
$fisicoPeriodoKey = $fisicoKeyFormatter($fisicoPeriodoInicio);
$parametrosFisicoquimicos = [
  ['campo' => 'tar_malla_30', 'nombre' => 'Malla 30'],
  ['campo' => 'tar_malla_45', 'nombre' => 'Malla 45'],
  ['campo' => 'tar_trans', 'nombre' => 'Transparencia'],
  ['campo' => 'tar_porcentaje_t', 'nombre' => '% Transmitancia', 'rango_campo' => 'tar_por_t', 'parametro' => 'por_t'],
  ['campo' => 'tar_color', 'nombre' => 'Color'],
  ['campo' => 'tar_olor', 'nombre' => 'Olor'],
  ['campo' => 'tar_redox', 'nombre' => 'Redox'],
  ['campo' => 'tar_ph', 'nombre' => 'pH'],
  ['campo' => 'tar_ce', 'nombre' => 'Conductividad'],
  ['campo' => 'tar_humedad', 'nombre' => 'Humedad'],
  ['campo' => 'tar_cenizas', 'nombre' => 'Cenizas'],
];
$camposFisicoquimicos = array_column($parametrosFisicoquimicos, 'campo');

$fisicoBuckets = [];
for ($cursor = $fisicoInicio; $cursor <= $fisicoPeriodoInicio; $cursor = $cursor->modify($fisicoStep)) {
  $key = $fisicoKeyFormatter($cursor);
  $fisicoBuckets[$key] = [
    'key' => $key,
    'label' => $fisicoLabelFormatter($cursor),
    'rango' => $fisicoRangeFormatter($cursor),
    'actual' => $key === $fisicoPeriodoKey,
    'tarimas' => 0,
    'sumas' => array_fill_keys($camposFisicoquimicos, 0.0),
    'conteos' => array_fill_keys($camposFisicoquimicos, 0),
    'valores' => array_fill_keys($camposFisicoquimicos, null),
  ];
}

$fisicoSelectCampos = array_map(
  static fn(string $campo): string => "t.`{$campo}`",
  $camposFisicoquimicos
);

$fisicoSql = "
  SELECT
    t.tar_fecha,
    t.tar_fino,
    " . implode(",\n    ", $fisicoSelectCampos) . "
  FROM rev_tarimas t
  WHERE t.tar_fecha >= ?
    AND t.tar_fecha < ?
    {$productosExcluidosSql}
  ORDER BY t.tar_fecha ASC, t.tar_id ASC
";

$stmtFisico = $pdo->prepare($fisicoSql);
$stmtFisico->execute([
  $fisicoInicioConsulta->format('Y-m-d H:i:s'),
  $fisicoFinExclusivoConsulta->format('Y-m-d H:i:s'),
]);

while ($row = $stmtFisico->fetch()) {
  $fechaTarima = new DateTimeImmutable((string)$row['tar_fecha'], $tz);
  $key = $fisicoKeyFromFecha($fechaTarima);
  if ($key === '' || !isset($fisicoBuckets[$key])) {
    continue;
  }

  $fisicoBuckets[$key]['tarimas']++;
  $esFinoN = strtoupper(trim((string)($row['tar_fino'] ?? ''))) === 'N';

  foreach ($camposFisicoquimicos as $campo) {
    if (($row[$campo] ?? null) === null || $row[$campo] === '') {
      continue;
    }
    if (in_array($campo, ['tar_malla_30', 'tar_malla_45'], true) && !$esFinoN) {
      continue;
    }

    $fisicoBuckets[$key]['sumas'][$campo] += (float)$row[$campo];
    $fisicoBuckets[$key]['conteos'][$campo]++;
  }
}

foreach ($fisicoBuckets as &$fisicoBucket) {
  foreach ($camposFisicoquimicos as $campo) {
    $conteoCampo = (int)($fisicoBucket['conteos'][$campo] ?? 0);
    $fisicoBucket['valores'][$campo] = $conteoCampo > 0
      ? (float)$fisicoBucket['sumas'][$campo] / $conteoCampo
      : null;
  }
}
unset($fisicoBucket);

$fisicoSemanas = array_values($fisicoBuckets);
$fisicoParametros = [];
$fisicoAlertas = 0;

foreach ($parametrosFisicoquimicos as $parametro) {
  $campo = (string)$parametro['campo'];
  $rangoKey = (string)($parametro['rango_campo'] ?? $campo);
  $parametroKey = (string)($parametro['parametro'] ?? $rangoKey);
  $reglasParametro = (array)($semaforoFisicoquimicos[$campo] ?? $semaforoFisicoquimicos[$rangoKey] ?? $semaforoFisicoquimicos[$parametroKey] ?? []);
  $rangoVerdeParametro = $rangoVerdeLabel($reglasParametro);
  $semanasParametro = [];
  $alertasParametro = 0;

  foreach ($fisicoBuckets as $semana) {
    $promedio = $semana['valores'][$campo] ?? null;
    $semaforoParametro = $evaluarSemaforo($promedio !== null ? (float)$promedio : null, $reglasParametro);
    $estadoKeyParametro = (string)$semaforoParametro['estado_key'];
    if (in_array($estadoKeyParametro, ['rojo', 'amarillo'], true)) {
      $alertasParametro++;
      $fisicoAlertas++;
    }

    $semanasParametro[] = [
      'key' => (string)$semana['key'],
      'label' => (string)$semana['label'],
      'actual' => !empty($semana['actual']),
      'promedio' => $promedio,
      'estado' => $semaforoParametro['estado'],
      'estado_key' => $estadoKeyParametro,
      'rango_label' => $semaforoParametro['rango_label'],
    ];
  }

  $fisicoParametros[] = [
    'campo' => $campo,
    'rango_campo' => $rangoKey,
    'parametro' => $parametroKey,
    'codigo' => $parametroKey,
    'nombre' => (string)$parametro['nombre'],
    'inicio' => null,
    'fin' => null,
    'rango_label' => $rangoVerdeParametro,
    'alertas' => $alertasParametro,
    'semanas' => $semanasParametro,
  ];
}

$fisicoSemanasResumen = array_map(static function (array $semana): array {
  return [
    'key' => (string)$semana['key'],
    'label' => (string)$semana['label'],
    'rango' => (string)$semana['rango'],
    'actual' => !empty($semana['actual']),
    'tarimas' => (int)$semana['tarimas'],
  ];
}, $fisicoSemanas);

$fisicoquimicos = [
  'periodo' => $periodo,
  'ventana_label' => $fisicoVentanaLabel,
  'rango' => $fisicoDesdeOperativo($fisicoInicio)->format('Y-m-d H:i')
    . ' a ' . $fisicoHastaOperativo($fisicoPeriodoInicio)->format('Y-m-d H:i'),
  'semanas' => $fisicoSemanasResumen,
  'parametros' => $fisicoParametros,
  'alertas' => $fisicoAlertas,
];

$kpis = [
  'tarimas' => $totalTarimas,
  'kilos' => $totalKilos,
  'bloom_promedio' => $totalTarimas > 0 ? $bloomSum / $totalTarimas : null,
  'viscosidad_promedio' => $totalTarimas > 0 ? $viscosidadSum / $totalTarimas : null,
];

return [
  'titulo' => (string)($config['titulo'] ?? 'CALIDAD / Producción'),
  'periodo' => $periodo,
  'periodoLabel' => $periodoLabel,
  'periodoRangoLabel' => $periodoRangoLabel,
  'controlValue' => $controlValue,
  'fechaSeleccionada' => $periodo === 'dia' ? $controlValue : $fechaSeleccionada,
  'semanaSeleccionada' => $periodo === 'semana' ? $controlValue : $semanaSeleccionada,
  'mesSeleccionado' => $periodo === 'mes' ? $controlValue : $mesSeleccionado,
  'inicio' => $inicio->format('Y-m-d'),
  'fin' => $fin->format('Y-m-d'),
  'kpis' => $kpis,
  'resumenCalidades' => $resumenCalidades,
  'chartPie' => [
    'labels' => $pieLabels,
    'data' => $pieData,
    'colors' => $pieColors,
  ],
  'chartTrend' => [
    'title' => $trendTitle,
    'labels' => array_column($trendBuckets, 'label'),
    'datasets' => $trendDatasets,
  ],
  'fisicoquimicos' => $fisicoquimicos,
  'meta' => [
    'targets' => $targets,
    'horaCorte' => $horaCorte,
    'productosExcluidos' => $productosExcluidos,
    'filasPorPagina' => (int)($config['filas_por_pagina'] ?? $appConfig['filas_por_pagina'] ?? 20),
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? $appConfig['intervalo_actualizacion'] ?? 300000),
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
