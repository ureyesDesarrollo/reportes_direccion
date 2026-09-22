<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';
require_once __DIR__ . '/../../shared/ChemicalMovementsApi.php';

/*
|--------------------------------------------------------------------------
| build_report.php — Refacción Crítica (detalle por producto)
|--------------------------------------------------------------------------
| Diferencias vs quimico-detalle:
|  - Filtro LUGAR = 'CRITICOS' (en lugar de cve_mov)
|  - Sin ratio vs producción: ratio = cantidad (consumo) o costo
|  - Sin modo impacto
|  - ratioBase = promedio semanal año anterior (consumo) o avg costo año anterior
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$fechaDesde          = $config['fecha_desde'];
$campoFechaMovs      = $config['campo_fecha_movs'];
$modo                = $config['modo'] ?? 'consumo';
$toleranciaPct       = (float)($config['tolerancia_pct'] ?? 10);
$lugar               = $config['lugar'] ?? 'CRITICOS';
$productoSeleccionado = $config['producto_seleccionado'] ?? null;
$productoLabel       = $config['productoLabel'] ?? $productoSeleccionado;

$cardsPorPagina         = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina         = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual   = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

if (empty($productoSeleccionado)) {
  throw new RuntimeException('No se recibió producto para el reporte.');
}

$cacheKey = 'report_refaccion_detalle_' . md5(serialize([
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

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$apiConfig = (array)($config['movimientos_api'] ?? []);
$apiConfig['productos_a_ignorar'] = (array)($config['productos_a_ignorar'] ?? []);
$apiResult = loadChemicalMovementsApi($apiConfig, [$anioAnterior, $anioActual], [], $timezone);
$movimientosRefaccion = array_values(array_filter((array)($apiResult['movements'] ?? []), static function ($movement) use ($fechaDesde, $productoSeleccionado): bool {
  return is_array($movement)
    && (string)($movement['semana_fin'] ?? '') >= $fechaDesde
    && trim((string)($movement['cve_prod'] ?? '')) === $productoSeleccionado;
}));
$sourceWarning = implode(' ', array_values(array_unique((array)($apiResult['warnings'] ?? []))));

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
  // consumo (default)
  $metricaNombre = 'consumo';
  $badgeRatio    = 'Consumo de refacciones críticas';
  $metricaTitulo = 'Consumo Refacción';
  $metricaUnidad = '';
}

/*
|--------------------------------------------------------------------------
| 1) DETALLE SEMANAL
|--------------------------------------------------------------------------
*/
$detallePorPeriodo = [];
$rowsDetalle = aggregateMovementsApiWeekly($movimientosRefaccion, [$productoSeleccionado], false);
foreach ($rowsDetalle as $row) {
  $row['consumo_cantidad'] = (float)($row['refaccion_cantidad'] ?? 0.0);
  $detallePorPeriodo[(int)$row['periodo']] = $row;
}

/*
|--------------------------------------------------------------------------
| 2) BASE DE COSTO (solo para modo costo)
|--------------------------------------------------------------------------
*/
$costoBase          = null;
$costoPromedioActual = null;

if ($modo === 'costo' || $modo === 'impacto') {
  $costByYear = [];
  foreach ($movimientosRefaccion as $movement) {
    $year = (int)substr((string)($movement['semana_iso'] ?? ''), 0, 4);
    if (!isset($costByYear[$year])) $costByYear[$year] = ['cantidad' => 0.0, 'impacto' => 0.0];
    $costByYear[$year]['cantidad'] += (float)($movement['cantidad_original'] ?? 0.0);
    $costByYear[$year]['impacto'] += (float)($movement['impacto_economico'] ?? 0.0);
  }
  $costoBase = !empty($costByYear[$anioAnterior]['cantidad'])
    ? $costByYear[$anioAnterior]['impacto'] / $costByYear[$anioAnterior]['cantidad']
    : null;
  $costoPromedioActual = !empty($costByYear[$anioActual]['cantidad'])
    ? $costByYear[$anioActual]['impacto'] / $costByYear[$anioActual]['cantidad']
    : null;
}

/*
|--------------------------------------------------------------------------
| 3) CONSTRUIR ITEMS (sin producción)
|--------------------------------------------------------------------------
*/
$itemsTemporales = [];
$maxRatio        = 0.0;

foreach ($detallePorPeriodo as $periodo => $row) {
  $semanaIso   = (string)$row['semana_iso'];
  $semanaInicio = (string)($row['semana_inicio'] ?? '');
  $semanaFin    = (string)($row['semana_fin']    ?? '');

  $consumoCantidad      = null;
  $costoPromedioSemana  = null;
  $diferenciaPrecio     = null;
  $impactoTotal         = null;

  if ($modo === 'costo') {
    $metrica = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
    $ratio   = $metrica > 0 ? $metrica : null;
    $costoPromedioSemana = $metrica;
  } elseif ($modo === 'impacto') {
    $consumoCantidad     = (float)($row['consumo_cantidad'] ?? 0.0);
    $costoPromedioSemana = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
    $diferenciaPrecio    = ($costoBase !== null) ? ($costoPromedioSemana - $costoBase) : null;
    $impactoTotal        = ($diferenciaPrecio !== null) ? ($diferenciaPrecio * $consumoCantidad) : null;
    $metrica             = $impactoTotal ?? 0.0;
    $ratio               = $impactoTotal; // impacto puede ser negativo (ahorro)
  } else {
    $metrica = (float)($row['consumo_cantidad'] ?? 0.0);
    $ratio   = $metrica > 0 ? $metrica : null;
  }

  if ($ratio !== null && abs($ratio) > $maxRatio) {
    $maxRatio = abs($ratio);
  }

  $itemsTemporales[] = [
    'periodo'      => $periodo,
    'semana_iso'   => $semanaIso,
    'semana_inicio' => $semanaInicio,
    'semana_fin'   => $semanaFin,
    'metrica'      => $metrica,
    'quimicos'     => $metrica,    // alias para compatibilidad con partials compartidos
    'produccion'   => 0.0,
    'ratio'        => $ratio,
    'consumo_kg'   => $modo === 'consumo' ? $metrica : $consumoCantidad,
    'costo_promedio_semana' => $costoPromedioSemana,
    'costo_base'   => $costoBase,
    'diferencia_precio' => $diferenciaPrecio,
    'impacto_total' => $impactoTotal,
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
  $ratioPromedioAnioActual  = $costoPromedioActual;
} elseif ($modo === 'impacto') {
  // ratioBase = promedio semanal del impacto del año anterior
  // (sirve como umbral amarillo en resolveTrafficLight: base * 1.06)
  $numSemanasAnioAnterior = count($datosAnioAnteriorTemp);
  $totalImpactoAnioAnterior = array_sum(array_map(
    static fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioAnteriorTemp
  ));
  $ratioBase = $numSemanasAnioAnterior > 0
    ? ($totalImpactoAnioAnterior / $numSemanasAnioAnterior)
    : null;

  $numSemanasAnioActual = count($datosAnioActualTemp);
  $totalImpactoAnioActual = array_sum(array_map(
    static fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioActualTemp
  ));
  $ratioPromedioAnioActual = $numSemanasAnioActual > 0
    ? ($totalImpactoAnioActual / $numSemanasAnioActual)
    : null;
} else {
  $numSemanasAnioAnterior = count($datosAnioAnteriorTemp);
  $ratioBase = $numSemanasAnioAnterior > 0
    ? ($totalMetricaAnioAnterior / $numSemanasAnioAnterior)
    : null;

  $numSemanasAnioActual    = count($datosAnioActualTemp);
  $ratioPromedioAnioActual = $numSemanasAnioActual > 0
    ? ($totalMetricaAnioActual / $numSemanasAnioActual)
    : null;
}

$limiteVerde   = $ratioBase;
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

// Para impacto, totalQuimicos = sum de impacto_total (puede ser negativo)
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
  'titulo'             => ($config['productoLabel'] ?? $productoSeleccionado) . ' / Refacción Crítica',
  'productoSeleccionado' => $productoSeleccionado,
  'modo'               => $modo,
  'metricaNombre'      => $metricaNombre,
  'metricaTitulo'      => $metricaTitulo,
  'metricaUnidad'      => $metricaUnidad,
  'badgeRatio'         => $badgeRatio,

  'anioAnterior' => $anioAnterior,
  'anioActual'   => $anioActual,

  'ratioBase'               => $ratioBase,
  'ratioGlobal'             => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde'   => $limiteVerde,
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
  'sourceWarning' => $sourceWarning,

  'meta' => [
    'fechaDesde'          => $fechaDesde,
    'campoFechaMovs'      => $campoFechaMovs,
    'cardsPorPagina'      => $cardsPorPagina,
    'filasPorPagina'      => $filasPorPagina,
    'toleranciaPct'       => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'lugar'               => $lugar,
    'metricaTitulo'       => $metricaTitulo,
    'metricaUnidad'       => $metricaUnidad,
    'badgeRatio'          => $badgeRatio,
    'modo'                => $modo,
    'mostrarProduccion'   => false,
    'ratioHeader'         => $modo === 'costo' ? 'Costo promedio ($)' : ($modo === 'impacto' ? 'Impacto semanal ($)' : 'Consumo semanal'),
    'kpi3LabelImpacto'    => 'Impacto promedio semanal ' . $anioActual,
    'productoSeleccionado' => $productoSeleccionado,
    'productoLabel'       => $config['productoLabel'] ?? $productoSeleccionado,
    'sourceWarning'       => $sourceWarning,
    'fuenteMovimientos'   => 'API movimientos-salida',
  ],
];

setCache($cacheKey, $result, 3600);

return $result;
