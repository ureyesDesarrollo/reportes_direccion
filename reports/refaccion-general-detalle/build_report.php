<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';

/*
|--------------------------------------------------------------------------
| build_report.php — Refacción General (detalle por producto, LUGAR='REFACCIONE')
|--------------------------------------------------------------------------
| Idéntico a refaccion-detalle/build_report.php excepto:
|  - lugar viene de config: 'REFACCIONE'
|  - textos de badge ajustados
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$fechaDesde           = $config['fecha_desde'];
$campoFechaMovs       = $config['campo_fecha_movs'];
$modo                 = $config['modo'] ?? 'consumo';
$toleranciaPct        = (float)($config['tolerancia_pct'] ?? 10);
$lugar                = $config['lugar'] ?? 'REFACCIONE';
$productoSeleccionado = $config['producto_seleccionado'] ?? null;
$productoLabel        = $config['productoLabel'] ?? $productoSeleccionado;

$cardsPorPagina         = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina         = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual   = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

if (empty($productoSeleccionado)) {
  throw new RuntimeException('No se recibió producto para el reporte.');
}

$cacheKey = 'report_refaccion_general_detalle_' . md5(serialize([
  $config,
  $appConfig['cards_por_pagina'] ?? 9,
  $appConfig['filas_por_pagina'] ?? 15,
  filemtime(__FILE__),
  date('Y-m-d'),
]));

$cached = getCache($cacheKey);
if ($cached !== null) {
  return $cached;
}

$state = ReportEngine::createContext($config, $appConfig, $dbConfig);
$pdoMovs           = $state['pdoMovs'];
$campoFechaMovsSql = $state['campoFechaMovsSql'];
$weekFields        = $state['weekFields'];

/*
|--------------------------------------------------------------------------
| MODO: metadatos del reporte
|--------------------------------------------------------------------------
*/
if ($modo === 'impacto') {
  $toleranciaPct = 6.0;
}

if ($modo === 'costo') {
  $metricaNombre = 'costo';
  $badgeRatio    = 'Promedio del costo de la refacción';
  $metricaTitulo = 'Costo Promedio';
  $metricaUnidad = '$';
} elseif ($modo === 'impacto') {
  $metricaNombre = 'impacto';
  $badgeRatio    = 'Impacto económico de la refacción';
  $metricaTitulo = 'Impacto Total';
  $metricaUnidad = '$';
} else {
  $metricaNombre = 'consumo';
  $badgeRatio    = 'Consumo de refacciones generales';
  $metricaTitulo = 'Consumo Refacción';
  $metricaUnidad = '';
}

/*
|--------------------------------------------------------------------------
| 1) DETALLE SEMANAL
|--------------------------------------------------------------------------
*/
if ($modo === 'costo') {
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            AVG(m.COSTO_ENT) AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
          AND TRIM(m.LUGAR) = ?
        GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ", TRIM(m.CVE_PROD)
        ORDER BY periodo
    ";
} elseif ($modo === 'impacto') {
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            SUM(m.CANT_PROD) AS consumo_cantidad,
            AVG(m.COSTO_ENT) AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
          AND TRIM(m.LUGAR) = ?
        GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ", TRIM(m.CVE_PROD)
        ORDER BY periodo
    ";
} else {
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            SUM(m.CANT_PROD) AS consumo_cantidad
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
          AND TRIM(m.LUGAR) = ?
        GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ", TRIM(m.CVE_PROD)
        ORDER BY periodo
    ";
}
$paramsDetalle = [$fechaDesde, $productoSeleccionado, $lugar];

$stmtDetalle = $pdoMovs->prepare($sqlDetalle);
$stmtDetalle->execute($paramsDetalle);

$detallePorPeriodo = [];
while ($row = $stmtDetalle->fetch()) {
  $detallePorPeriodo[(int)$row['periodo']] = $row;
}

/*
|--------------------------------------------------------------------------
| 2) BASE DE COSTO (para modo costo e impacto)
|--------------------------------------------------------------------------
*/
$costoBase           = null;
$costoPromedioActual = null;

if ($modo === 'costo' || $modo === 'impacto') {
  $sqlCostoBase = "
        SELECT
            AVG(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN m.COSTO_ENT END) AS promedio_anio_anterior,
            AVG(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN m.COSTO_ENT END) AS promedio_anio_actual
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
          AND TRIM(m.LUGAR) = ?
    ";

  $stmtCostoBase = $pdoMovs->prepare($sqlCostoBase);
  $stmtCostoBase->execute([$anioAnterior, $anioActual, $fechaDesde, $productoSeleccionado, $lugar]);
  $rowCostoBase = $stmtCostoBase->fetch();

  $costoBase           = isset($rowCostoBase['promedio_anio_anterior']) ? (float)$rowCostoBase['promedio_anio_anterior'] : null;
  $costoPromedioActual = isset($rowCostoBase['promedio_anio_actual'])   ? (float)$rowCostoBase['promedio_anio_actual']   : null;
}

/*
|--------------------------------------------------------------------------
| 3) CONSTRUIR ITEMS (sin producción)
|--------------------------------------------------------------------------
*/
$itemsTemporales = [];
$maxRatio        = 0.0;

foreach ($detallePorPeriodo as $periodo => $row) {
  $semanaIso    = (string)$row['semana_iso'];
  $semanaInicio = (string)($row['semana_inicio'] ?? '');
  $semanaFin    = (string)($row['semana_fin']    ?? '');

  $consumoCantidad     = null;
  $costoPromedioSemana = null;
  $diferenciaPrecio    = null;
  $impactoTotal        = null;

  if ($modo === 'costo') {
    $metrica             = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
    $ratio               = $metrica > 0 ? $metrica : null;
    $costoPromedioSemana = $metrica;
  } elseif ($modo === 'impacto') {
    $consumoCantidad     = (float)($row['consumo_cantidad'] ?? 0.0);
    $costoPromedioSemana = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
    $diferenciaPrecio    = ($costoBase !== null) ? ($costoPromedioSemana - $costoBase) : null;
    $impactoTotal        = ($diferenciaPrecio !== null) ? ($diferenciaPrecio * $consumoCantidad) : null;
    $metrica             = $impactoTotal ?? 0.0;
    $ratio               = $impactoTotal;
  } else {
    $metrica = (float)($row['consumo_cantidad'] ?? 0.0);
    $ratio   = $metrica > 0 ? $metrica : null;
  }

  if ($ratio !== null && abs($ratio) > $maxRatio) {
    $maxRatio = abs($ratio);
  }

  $itemsTemporales[] = [
    'periodo'               => $periodo,
    'semana_iso'            => $semanaIso,
    'semana_inicio'         => $semanaInicio,
    'semana_fin'            => $semanaFin,
    'metrica'               => $metrica,
    'quimicos'              => $metrica,
    'produccion'            => 0.0,
    'ratio'                 => $ratio,
    'consumo_kg'            => $modo === 'consumo' ? $metrica : $consumoCantidad,
    'costo_promedio_semana' => $costoPromedioSemana,
    'costo_base'            => $costoBase,
    'diferencia_precio'     => $diferenciaPrecio,
    'impacto_total'         => $impactoTotal,
  ];
}

/*
|--------------------------------------------------------------------------
| 4) BASE Y KPIs
|--------------------------------------------------------------------------
*/
$datosAnioAnteriorTemp = array_filter($itemsTemporales, static function ($item) use ($anioAnterior) {
  return (int)substr((string)$item['semana_iso'], 0, 4) === $anioAnterior;
});

$datosAnioActualTemp = array_filter($itemsTemporales, static function ($item) use ($anioActual) {
  return (int)substr((string)$item['semana_iso'], 0, 4) === $anioActual;
});

$totalMetricaAnioAnterior = array_sum(array_column(array_values($datosAnioAnteriorTemp), 'metrica'));
$totalMetricaAnioActual   = array_sum(array_column(array_values($datosAnioActualTemp),   'metrica'));

if ($modo === 'costo') {
  $ratioBase               = $costoBase;
  $ratioPromedioAnioActual = $costoPromedioActual;
} elseif ($modo === 'impacto') {
  $numSemanasAnioAnterior   = count($datosAnioAnteriorTemp);
  $totalImpactoAnioAnterior = array_sum(array_map(
    static fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioAnteriorTemp
  ));
  $ratioBase = $numSemanasAnioAnterior > 0
    ? ($totalImpactoAnioAnterior / $numSemanasAnioAnterior)
    : null;

  $numSemanasAnioActual   = count($datosAnioActualTemp);
  $totalImpactoAnioActual = array_sum(array_map(
    static fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioActualTemp
  ));
  $ratioPromedioAnioActual = $numSemanasAnioActual > 0
    ? ($totalImpactoAnioActual / $numSemanasAnioActual)
    : null;
} else {
  $numSemanasAnioAnterior  = count($datosAnioAnteriorTemp);
  $ratioBase               = $numSemanasAnioAnterior > 0
    ? ($totalMetricaAnioAnterior / $numSemanasAnioAnterior)
    : null;

  $numSemanasAnioActual    = count($datosAnioActualTemp);
  $ratioPromedioAnioActual = $numSemanasAnioActual > 0
    ? ($totalMetricaAnioActual / $numSemanasAnioActual)
    : null;
}

$limiteVerde    = $ratioBase;
$limiteAmarillo = $ratioBase !== null
  ? $ratioBase * (1 + $toleranciaPct / 100)
  : null;

/*
|--------------------------------------------------------------------------
| 5) SEMÁFORO
|--------------------------------------------------------------------------
*/
$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, $modo);
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit         = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = $yearSplit['anterior'];
$datosAnioActual   = $yearSplit['actual'];

$maxRatio = ReportEngine::maxRatio($reporte);

$ratioGlobal = $ratioPromedioAnioActual;

[$estadoGlobal, $colorGlobal, $colorGlobalHex] = resolveTrafficLight($ratioGlobal, $ratioBase, $toleranciaPct, $modo);

$totalQuimicosAnioAnterior = $modo === 'impacto'
  ? array_sum(array_map(static fn($r) => (float)($r['impacto_total'] ?? 0.0), $datosAnioAnterior))
  : $totalMetricaAnioAnterior;
$totalQuimicosAnioActual = $modo === 'impacto'
  ? array_sum(array_map(static fn($r) => (float)($r['impacto_total'] ?? 0.0), $datosAnioActual))
  : $totalMetricaAnioActual;

$totalProduccionAnioAnterior = 0.0;
$totalProduccionAnioActual   = 0.0;

$variacionQuimicos = (abs($totalQuimicosAnioAnterior) > 0.0001)
  ? (($totalQuimicosAnioActual - $totalQuimicosAnioAnterior) / abs($totalQuimicosAnioAnterior)) * 100
  : null;

$variacionProduccion = null;

$variacionRatio = ($ratioBase !== null && $ratioBase > 0 && $ratioPromedioAnioActual !== null)
  ? (($ratioPromedioAnioActual - $ratioBase) / $ratioBase) * 100
  : null;

$version = time();

/*
|--------------------------------------------------------------------------
| 6) CHART DATA
|--------------------------------------------------------------------------
*/
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase);

/*
|--------------------------------------------------------------------------
| 7) RESULTADO FINAL
|--------------------------------------------------------------------------
*/
$result = [
  'titulo'               => ($config['productoLabel'] ?? $productoSeleccionado) . ' / Refacción General',
  'productoSeleccionado' => $productoSeleccionado,
  'modo'                 => $modo,
  'metricaNombre'        => $metricaNombre,
  'metricaTitulo'        => $metricaTitulo,
  'metricaUnidad'        => $metricaUnidad,
  'badgeRatio'           => $badgeRatio,

  'anioAnterior' => $anioAnterior,
  'anioActual'   => $anioActual,

  'ratioBase'               => $ratioBase,
  'ratioGlobal'             => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde'    => $limiteVerde,
  'limiteAmarillo' => $limiteAmarillo,

  'estadoGlobal'   => $estadoGlobal,
  'colorGlobal'    => $colorGlobal,
  'colorGlobalHex' => $colorGlobalHex,

  'totalQuimicosAnioAnterior'   => $totalQuimicosAnioAnterior,
  'totalProduccionAnioAnterior' => $totalProduccionAnioAnterior,
  'totalQuimicosAnioActual'     => $totalQuimicosAnioActual,
  'totalProduccionAnioActual'   => $totalProduccionAnioActual,

  'variacionQuimicos'   => $variacionQuimicos,
  'variacionProduccion' => $variacionProduccion,
  'variacionRatio'      => $variacionRatio,

  'reporte'           => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual'   => $datosAnioActual,
  'chartData'         => $chartData,

  'maxRatio' => $maxRatio,
  'version'  => $version,

  'meta' => [
    'fechaDesde'             => $fechaDesde,
    'campoFechaMovs'         => $campoFechaMovs,
    'cardsPorPagina'         => $cardsPorPagina,
    'filasPorPagina'         => $filasPorPagina,
    'toleranciaPct'          => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'lugar'                  => $lugar,
    'metricaTitulo'          => $metricaTitulo,
    'metricaUnidad'          => $metricaUnidad,
    'badgeRatio'             => $badgeRatio,
    'modo'                   => $modo,
    'mostrarProduccion'      => false,
    'ratioHeader'            => $modo === 'costo' ? 'Costo promedio ($)' : ($modo === 'impacto' ? 'Impacto semanal ($)' : 'Consumo semanal'),
    'kpi3LabelImpacto'       => 'Impacto promedio semanal ' . $anioActual,
    'productoSeleccionado'   => $productoSeleccionado,
    'productoLabel'          => $config['productoLabel'] ?? $productoSeleccionado,
  ],
];

setCache($cacheKey, $result, 3600);

return $result;
