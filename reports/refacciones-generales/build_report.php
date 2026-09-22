<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';
require_once __DIR__ . '/../../shared/ChemicalMovementsApi.php';

/*
|--------------------------------------------------------------------------
| build_report.php — Refacciones Generales (LUGAR = 'REFACCIONE')
|--------------------------------------------------------------------------
| Idéntico a refacciones-criticas/build_report.php excepto:
|  - lugar viene de config: 'REFACCIONE'
|  - textos de badge/titulo ajustados
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$fechaDesde            = $config['fecha_desde'];
$campoFechaMovs        = $config['campo_fecha_movs'];
$productosConfig       = $config['productos'] ?? [];
$toleranciaPct         = (float)($config['tolerancia_pct'] ?? 10);
$usarTodosLosProductos = (bool)($config['usar_todos_los_productos'] ?? true);
$anioPivot             = (int)($config['anio_pivot'] ?? date('Y'));
$lugar                 = $config['lugar'] ?? 'REFACCIONE';
$productosIgnorar      = $config['productos_a_ignorar'] ?? [];

$cardsPorPagina         = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina         = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual   = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

$cacheKey = 'report_refacciones_generales_' . md5(serialize([
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
$apiConfig['productos_a_ignorar'] = $productosIgnorar;
$apiResult = loadChemicalMovementsApi($apiConfig, [$anioAnterior, $anioActual], [], $timezone);
$movimientosRefacciones = array_values(array_filter((array)($apiResult['movements'] ?? []), static function ($movement) use ($fechaDesde): bool {
  return is_array($movement) && (string)($movement['semana_fin'] ?? '') >= $fechaDesde;
}));
$sourceWarning = implode(' ', array_values(array_unique((array)($apiResult['warnings'] ?? []))));

/*
|--------------------------------------------------------------------------
| 1) DETALLE POR REFACCIÓN Y SEMANA
|--------------------------------------------------------------------------
*/
$rowsPivot = aggregateMovementsApiWeekly(
  $movimientosRefacciones,
  !$usarTodosLosProductos ? (array)$productosConfig : [],
  false
);

/*
|--------------------------------------------------------------------------
| 2) TOTALES POR PERÍODO
|--------------------------------------------------------------------------
*/
$refaccionesPorPeriodo = [];

foreach ($rowsPivot as $row) {
  $periodo = (int)$row['periodo'];

  if (!isset($refaccionesPorPeriodo[$periodo])) {
    $refaccionesPorPeriodo[$periodo] = [
      'periodo'            => $periodo,
      'semana_iso'         => $row['semana_iso'],
      'semana_inicio'      => $row['semana_inicio'],
      'semana_fin'         => $row['semana_fin'],
      'refaccion_cantidad' => 0.0,
    ];
  }

  $refaccionesPorPeriodo[$periodo]['refaccion_cantidad'] += (float)$row['refaccion_cantidad'];
}

/*
|--------------------------------------------------------------------------
| 3) MATRIZ DEL AÑO PIVOT
|--------------------------------------------------------------------------
*/
$semanasCatalogo      = [];
$refaccionesCatalogo  = [];
$refaccionesEtiquetas = [];
$matrizRefacciones    = [];
$matrizImpactoAcum    = [];
$matrizCostos         = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso   = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel = substr($semanaIso, -3);
  $cveProd     = trim((string)$row['cve_prod']);
  $descProd    = trim((string)$row['desc_prod']);
  $cantidad    = (float)$row['refaccion_cantidad'];
  $costo       = (float)($row['costo_promedio'] ?? 0.0);
  $key         = $cveProd;
  $label       = $descProd !== '' ? $descProd : $cveProd;

  $refaccionesEtiquetas[$key] = $label;

  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }

  if (!in_array($key, $refaccionesCatalogo, true)) {
    $refaccionesCatalogo[] = $key;
  }

  if (!isset($matrizRefacciones[$key])) {
    $matrizRefacciones[$key] = [];
    $matrizImpactoAcum[$key] = [];
  }

  if (!isset($matrizRefacciones[$key][$semanaLabel])) {
    $matrizRefacciones[$key][$semanaLabel] = 0.0;
    $matrizImpactoAcum[$key][$semanaLabel] = 0.0;
  }

  $matrizRefacciones[$key][$semanaLabel] += $cantidad;
  $matrizImpactoAcum[$key][$semanaLabel] += $cantidad * $costo;
}

sort($semanasCatalogo);
sort($refaccionesCatalogo);

foreach ($refaccionesCatalogo as $key) {
  if (!isset($matrizRefacciones[$key])) {
    $matrizRefacciones[$key] = [];
    $matrizImpactoAcum[$key] = [];
  }

  $matrizCostos[$key] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    if (!isset($matrizRefacciones[$key][$semanaLabel])) {
      $matrizRefacciones[$key][$semanaLabel] = 0.0;
      $matrizImpactoAcum[$key][$semanaLabel] = 0.0;
    }

    $cant = $matrizRefacciones[$key][$semanaLabel];
    $matrizCostos[$key][$semanaLabel] = $cant > 0
      ? ($matrizImpactoAcum[$key][$semanaLabel] / $cant)
      : 0.0;
  }

  ksort($matrizRefacciones[$key]);
  ksort($matrizCostos[$key]);
}

/*
|--------------------------------------------------------------------------
| 4) BASE HISTÓRICA POR REFACCIÓN (AÑO ANTERIOR)
|--------------------------------------------------------------------------
*/
$consumoBasePorRefaccion    = [];
$semanasActivasPorRefaccion = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso   = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioAnterior) {
    continue;
  }

  $key      = trim((string)$row['cve_prod']);
  $cantidad = (float)$row['refaccion_cantidad'];
  $semLabel = substr($semanaIso, -3);

  if (!isset($consumoBasePorRefaccion[$key])) {
    $consumoBasePorRefaccion[$key]    = 0.0;
    $semanasActivasPorRefaccion[$key] = [];
  }

  $consumoBasePorRefaccion[$key] += $cantidad;
  $semanasActivasPorRefaccion[$key][$semLabel] = true;
}

$ratioBasePorRefaccion = [];

foreach ($refaccionesCatalogo as $key) {
  $consumoBase = (float)($consumoBasePorRefaccion[$key] ?? 0.0);
  $numSemanas  = count($semanasActivasPorRefaccion[$key] ?? []);

  $ratioBasePorRefaccion[$key] = $numSemanas > 0
    ? ($consumoBase / $numSemanas)
    : null;
}

/*
|--------------------------------------------------------------------------
| 5) MATRIZ DE RATIO (cantidad absoluta, sin producción)
|--------------------------------------------------------------------------
*/
$matrizRatioRefacciones = [];
$maxRatio = 0.0;

foreach ($refaccionesCatalogo as $key) {
  $matrizRatioRefacciones[$key] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $cantidad = (float)($matrizRefacciones[$key][$semanaLabel] ?? 0.0);
    $ratio    = $cantidad > 0 ? $cantidad : null;

    $matrizRatioRefacciones[$key][$semanaLabel] = $ratio;

    if ($ratio !== null) {
      $maxRatio = max($maxRatio, $ratio);
    }
  }
}

/*
|--------------------------------------------------------------------------
| 6) REPORTE GLOBAL SEMANAL
|--------------------------------------------------------------------------
*/
$periodos = array_keys($refaccionesPorPeriodo);
sort($periodos);

$itemsTemporales = [];

foreach ($periodos as $periodo) {
  $row       = $refaccionesPorPeriodo[$periodo];
  $semanaIso = (string)$row['semana_iso'];
  $cantidad  = (float)$row['refaccion_cantidad'];

  $maxRatio = max($maxRatio, $cantidad);

  $semanaLabel = substr($semanaIso, -3);

  $itemsTemporales[] = [
    'periodo'       => $periodo,
    'semana_iso'    => $semanaIso,
    'semana_label'  => $semanaLabel,
    'semana_inicio' => (string)$row['semana_inicio'],
    'semana_fin'    => (string)$row['semana_fin'],
    'quimicos'      => $cantidad,
    'produccion'    => 0.0,
    'ratio'         => $cantidad,
  ];
}

/*
|--------------------------------------------------------------------------
| 7) BASE Y SEMÁFORO GLOBAL
|--------------------------------------------------------------------------
*/
$datosAnioAnteriorTemp = array_filter($itemsTemporales, static function ($item) use ($anioAnterior) {
  return (int)substr((string)$item['semana_iso'], 0, 4) === $anioAnterior;
});

$totalRefaccionesAnioAnterior = array_sum(array_column(array_values($datosAnioAnteriorTemp), 'quimicos'));
$numSemanasAnioAnterior       = count($datosAnioAnteriorTemp);

$ratioBase = $numSemanasAnioAnterior > 0
  ? ($totalRefaccionesAnioAnterior / $numSemanasAnioAnterior)
  : null;

$limiteVerde    = $ratioBase;
$limiteAmarillo = $ratioBase !== null
  ? $ratioBase * (1 + $toleranciaPct / 100)
  : null;

$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, 'consumo');
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit         = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = $yearSplit['anterior'];
$datosAnioActual   = $yearSplit['actual'];

$maxRatio = ReportEngine::maxRatio($reporte);

$totalRefaccionesAnioActual = array_sum(array_column($datosAnioActual, 'quimicos'));
$numSemanasAnioActual       = count($datosAnioActual);

$totalProduccionAnioAnterior = 0.0;
$totalProduccionAnioActual   = 0.0;

$ratioPromedioAnioActual = $numSemanasAnioActual > 0
  ? ($totalRefaccionesAnioActual / $numSemanasAnioActual)
  : null;

$ratioGlobal = $ratioPromedioAnioActual;

[$estadoGlobal, $colorGlobal, $colorGlobalHex] = semaforo($ratioGlobal, $ratioBase, $toleranciaPct);

$variacionQuimicos = $totalRefaccionesAnioAnterior > 0
  ? (($totalRefaccionesAnioActual - $totalRefaccionesAnioAnterior) / $totalRefaccionesAnioAnterior) * 100
  : null;

$variacionProduccion = null;

$variacionRatio = ($ratioBase !== null && $ratioBase > 0 && $ratioPromedioAnioActual !== null)
  ? (($ratioPromedioAnioActual - $ratioBase) / $ratioBase) * 100
  : null;

$version = time();

/*
|--------------------------------------------------------------------------
| 8) CONSUMO Y COSTO ANUAL POR REFACCIÓN (ambos años)
|--------------------------------------------------------------------------
*/
$consumoRefaccionAnioAnterior = [];
$consumoRefaccionAnioActual   = [];
$costoPromedioAnioAnterior    = [];
$costoPromedioAnioActual      = [];
$impactoEconomicoAnioAnterior = [];
$impactoEconomicoAnioActual   = [];

$annualRefacciones = [];
foreach ($rowsPivot as $row) {
  $anio = (int)substr((string)($row['semana_iso'] ?? ''), 0, 4);
  if (!in_array($anio, [$anioAnterior, $anioActual], true)) continue;
  $key = trim((string)$row['cve_prod']);
  if (!isset($annualRefacciones[$anio][$key])) {
    $annualRefacciones[$anio][$key] = ['cantidad' => 0.0, 'impacto' => 0.0];
  }
  $annualRefacciones[$anio][$key]['cantidad'] += (float)$row['refaccion_cantidad'];
  $annualRefacciones[$anio][$key]['impacto'] += (float)($row['impacto_economico'] ?? 0.0);
}

foreach ($annualRefacciones as $anio => $products) {
  foreach ($products as $key => $annualRow) {
    $consumo = (float)$annualRow['cantidad'];
    $impacto = (float)$annualRow['impacto'];
    $costo = $consumo != 0.0 ? $impacto / $consumo : 0.0;

    if ((int)$anio === $anioAnterior) {
      $consumoRefaccionAnioAnterior[$key] = $consumo;
      $costoPromedioAnioAnterior[$key] = $costo;
      $impactoEconomicoAnioAnterior[$key] = $impacto;
    } else {
      $consumoRefaccionAnioActual[$key] = $consumo;
      $costoPromedioAnioActual[$key] = $costo;
      $impactoEconomicoAnioActual[$key] = $impacto;
    }
  }
}

/*
|--------------------------------------------------------------------------
| 9) TOTALES POR REFACCIÓN (VARIACIÓN)
|--------------------------------------------------------------------------
*/
$totalesConsumoRefaccion  = [];
$totalesCostoRefaccion    = [];
$variacionConsumoRefaccion = [];
$variacionCostoRefaccion   = [];

foreach ($refaccionesCatalogo as $key) {
  $consumoActual    = (float)($consumoRefaccionAnioActual[$key] ?? 0.0);
  $consumoAnterior  = (float)($consumoRefaccionAnioAnterior[$key] ?? 0.0);
  $varConsumo       = $consumoAnterior > 0
    ? (($consumoActual - $consumoAnterior) / $consumoAnterior) * 100
    : 0.0;

  $costoPromActual   = (float)($costoPromedioAnioActual[$key] ?? 0.0);
  $costoPromAnterior = (float)($costoPromedioAnioAnterior[$key] ?? 0.0);
  $varCosto          = $costoPromAnterior > 0
    ? (($costoPromActual - $costoPromAnterior) / $costoPromAnterior) * 100
    : 0.0;

  $totalesConsumoRefaccion[$key]   = $consumoActual;
  $totalesCostoRefaccion[$key]     = (float)($impactoEconomicoAnioActual[$key] ?? 0.0);
  $variacionConsumoRefaccion[$key] = $varConsumo;
  $variacionCostoRefaccion[$key]   = $varCosto;
}

/*
|--------------------------------------------------------------------------
| 10) RESÚMENES POR SEMANA
|--------------------------------------------------------------------------
*/
$totalesPorSemana  = [];
$produccionPorSemana = [];
$ratioPorSemana    = [];

foreach ($reporte as $row) {
  $anioRow = (int)substr((string)$row['semana_iso'], 0, 4);
  if ($anioRow !== $anioPivot) {
    continue;
  }

  $semanaLabel = $row['semana_label'];
  $totalesPorSemana[$semanaLabel]    = (float)$row['quimicos'];
  $produccionPorSemana[$semanaLabel] = 0.0;
  $ratioPorSemana[$semanaLabel]      = $row['ratio'];
}

foreach ($semanasCatalogo as $semanaLabel) {
  $totalesPorSemana[$semanaLabel]    = $totalesPorSemana[$semanaLabel] ?? 0.0;
  $produccionPorSemana[$semanaLabel] = 0.0;
  $ratioPorSemana[$semanaLabel]      = $ratioPorSemana[$semanaLabel] ?? null;
}

/*
|--------------------------------------------------------------------------
| 11) CHART DATA
|--------------------------------------------------------------------------
*/
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase);

/*
|--------------------------------------------------------------------------
| 12) RESULTADO FINAL
|--------------------------------------------------------------------------
*/
$result = [
  'titulo' => $config['titulo'] ?? 'Refacciones Generales',

  'anioAnterior' => $anioAnterior,
  'anioActual'   => $anioActual,
  'anioPivot'    => $anioPivot,

  'ratioBase'               => $ratioBase,
  'ratioGlobal'             => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde'    => $limiteVerde,
  'limiteAmarillo' => $limiteAmarillo,

  'estadoGlobal'   => $estadoGlobal,
  'colorGlobal'    => $colorGlobal,
  'colorGlobalHex' => $colorGlobalHex,

  'totalQuimicosAnioAnterior'   => $totalRefaccionesAnioAnterior,
  'totalProduccionAnioAnterior' => $totalProduccionAnioAnterior,
  'totalQuimicosAnioActual'     => $totalRefaccionesAnioActual,
  'totalProduccionAnioActual'   => $totalProduccionAnioActual,

  'variacionQuimicos'   => $variacionQuimicos,
  'variacionProduccion' => $variacionProduccion,
  'variacionRatio'      => $variacionRatio,

  'reporte'           => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual'   => $datosAnioActual,

  'chartData' => $chartData,

  'semanasCatalogo'          => $semanasCatalogo,
  'refaccionesCatalogo'      => $refaccionesCatalogo,
  'refaccionesEtiquetas'     => $refaccionesEtiquetas,
  'matrizRefacciones'        => $matrizRefacciones,
  'matrizCostos'             => $matrizCostos,
  'produccionPivotPorSemana' => [],
  'matrizRatioRefacciones'   => $matrizRatioRefacciones,
  'ratioBasePorRefaccion'    => $ratioBasePorRefaccion,
  'totalesPorSemana'         => $totalesPorSemana,
  'produccionPorSemana'      => $produccionPorSemana,
  'ratioPorSemana'           => $ratioPorSemana,

  'consumoRefaccionAnioAnterior'  => $consumoRefaccionAnioAnterior,
  'consumoRefaccionAnioActual'    => $consumoRefaccionAnioActual,
  'costoPromedioAnioAnterior'     => $costoPromedioAnioAnterior,
  'costoPromedioAnioActual'       => $costoPromedioAnioActual,
  'impactoEconomicoAnioAnterior'  => $impactoEconomicoAnioAnterior,
  'impactoEconomicoAnioActual'    => $impactoEconomicoAnioActual,

  'totalesConsumoRefaccion'   => $totalesConsumoRefaccion,
  'totalesCostoRefaccion'     => $totalesCostoRefaccion,
  'variacionConsumoRefaccion' => $variacionConsumoRefaccion,
  'variacionCostoRefaccion'   => $variacionCostoRefaccion,

  'maxRatio' => $maxRatio,
  'version'  => $version,
  'sourceWarning' => $sourceWarning,

  'meta' => [
    'fechaDesde'            => $fechaDesde,
    'campoFechaMovs'        => $campoFechaMovs,
    'productos'             => $productosConfig,
    'usarTodosLosProductos' => $usarTodosLosProductos,
    'cardsPorPagina'        => $cardsPorPagina,
    'filasPorPagina'        => $filasPorPagina,
    'toleranciaPct'         => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'lugar'                 => $lugar,
    'metricaTitulo'         => 'Consumo Refacciones',
    'metricaUnidad'         => '',
    'badgeRatio'            => 'Consumo de refacciones generales',
    'mostrarProduccion'     => false,
    'sourceWarning'         => $sourceWarning,
    'fuenteMovimientos'     => 'API movimientos-salida',
  ],
];

setCache($cacheKey, $result, 3600);

return $result;
