<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';

/*
|--------------------------------------------------------------------------
| build_report.php — Refacciones Críticas
|--------------------------------------------------------------------------
| DIFERENCIAS clave vs quimicos/empaques:
|  - No hay ratio vs producción: ratio = cantidad absoluta por semana
|  - Base = promedio semanal del año anterior (total_año / semanas_activas)
|  - produccionPorSemana = 0 (no se usa producción)
|  - LUGAR = 'CRITICOS'
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$fechaDesde         = $config['fecha_desde'];
$campoFechaMovs     = $config['campo_fecha_movs'];
$productosConfig    = $config['productos'] ?? [];
$toleranciaPct      = (float)($config['tolerancia_pct'] ?? 10);
$usarTodosLosProductos = (bool)($config['usar_todos_los_productos'] ?? true);
$anioPivot          = (int)($config['anio_pivot'] ?? date('Y'));
$lugar              = $config['lugar'] ?? 'CRITICOS';
$productosIgnorar   = $config['productos_a_ignorar'] ?? [];

$cardsPorPagina          = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina          = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion  = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual   = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

$cacheKey = 'report_refacciones_' . md5(serialize([
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
$pdoMovs             = $state['pdoMovs'];
$pdoProd             = $state['pdoProd'];   // no se usa, pero createContext lo requiere
$campoFechaMovsSql   = $state['campoFechaMovsSql'];
$weekFields          = $state['weekFields'];

/*
|--------------------------------------------------------------------------
| 1) DETALLE POR REFACCIÓN Y SEMANA
|--------------------------------------------------------------------------
*/
$sqlPivot = "
    SELECT
        " . $weekFields . ",
        TRIM(m.CVE_PROD) AS cve_prod,
        COALESCE(TRIM(p.DESC_PROD), '') AS desc_prod,
        SUM(m.CANT_PROD) AS refaccion_cantidad,
        AVG(m.COSTO_ENT) AS costo_promedio
    FROM movs m
    LEFT JOIN producto p
        ON TRIM(p.CVE_PROD) = TRIM(m.CVE_PROD)
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND TRIM(m.LUGAR) = ?
";

$paramsPivot = [$fechaDesde, $lugar];

if (!$usarTodosLosProductos && !empty($productosConfig)) {
  $ph = createPlaceholders($productosConfig);
  $sqlPivot .= " AND TRIM(m.CVE_PROD) IN ($ph) ";
  $paramsPivot = array_merge($paramsPivot, $productosConfig);
}

if (!empty($productosIgnorar)) {
  $ph = createPlaceholders($productosIgnorar);
  $sqlPivot .= " AND TRIM(m.CVE_PROD) NOT IN ($ph) ";
  $paramsPivot = array_merge($paramsPivot, $productosIgnorar);
}

$sqlPivot .= "
    GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ",
        TRIM(m.CVE_PROD),
        p.DESC_PROD
    ORDER BY semana_iso, cve_prod
";

$stmtPivot = $pdoMovs->prepare($sqlPivot);
$stmtPivot->execute($paramsPivot);
$rowsPivot = $stmtPivot->fetchAll();

/*
|--------------------------------------------------------------------------
| 2) TOTALES POR PERÍODO (derivado de rowsPivot)
|--------------------------------------------------------------------------
*/
$refaccionesPorPeriodo = [];

foreach ($rowsPivot as $row) {
  $periodo = (int)$row['periodo'];

  if (!isset($refaccionesPorPeriodo[$periodo])) {
    $refaccionesPorPeriodo[$periodo] = [
      'periodo'         => $periodo,
      'semana_iso'      => $row['semana_iso'],
      'semana_inicio'   => $row['semana_inicio'],
      'semana_fin'      => $row['semana_fin'],
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
$semanasCatalogo       = [];
$refaccionesCatalogo   = [];
$refaccionesEtiquetas  = [];
$matrizRefacciones     = [];   // [key][semana] = cantidad
$matrizImpactoAcum     = [];   // [key][semana] = cantidad × costo (para calcular costo prom)
$matrizCostos          = [];   // [key][semana] = costo promedio ponderado

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso   = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel  = substr($semanaIso, -3);
  $cveProd      = trim((string)$row['cve_prod']);
  $descProd     = trim((string)$row['desc_prod']);
  $cantidad     = (float)$row['refaccion_cantidad'];
  $costo        = (float)($row['costo_promedio'] ?? 0.0);
  $key          = $cveProd;
  $label        = $descProd !== '' ? $descProd : $cveProd;

  $refaccionesEtiquetas[$key] = $label;

  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }

  if (!in_array($key, $refaccionesCatalogo, true)) {
    $refaccionesCatalogo[] = $key;
  }

  if (!isset($matrizRefacciones[$key])) {
    $matrizRefacciones[$key]  = [];
    $matrizImpactoAcum[$key]  = [];
  }

  if (!isset($matrizRefacciones[$key][$semanaLabel])) {
    $matrizRefacciones[$key][$semanaLabel]  = 0.0;
    $matrizImpactoAcum[$key][$semanaLabel]  = 0.0;
  }

  $matrizRefacciones[$key][$semanaLabel]  += $cantidad;
  $matrizImpactoAcum[$key][$semanaLabel]  += $cantidad * $costo;
}

sort($semanasCatalogo);
sort($refaccionesCatalogo);

// Rellenar celdas vacías y calcular costos promedio ponderados
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
| ratioBasePorRefaccion[key] = consumo_año_anterior / semanas_con_actividad
|--------------------------------------------------------------------------
*/
$consumoBasePorRefaccion   = [];
$semanasActivasPorRefaccion = [];  // [key][semanaLabel] = true

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
| 5) MATRIZ DE RATIO (cantidad = el propio ratio, sin producción)
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
| 6) REPORTE GLOBAL SEMANAL (sin producción)
|--------------------------------------------------------------------------
*/
$periodos = array_keys($refaccionesPorPeriodo);
sort($periodos);

$totales = ['refacciones' => 0.0];
$itemsTemporales = [];

foreach ($periodos as $periodo) {
  $row       = $refaccionesPorPeriodo[$periodo];
  $semanaIso = (string)$row['semana_iso'];
  $cantidad  = (float)$row['refaccion_cantidad'];

  $totales['refacciones'] += $cantidad;
  $maxRatio = max($maxRatio, $cantidad);

  $semanaLabel = substr($semanaIso, -3);

  $itemsTemporales[] = [
    'periodo'      => $periodo,
    'semana_iso'   => $semanaIso,
    'semana_label' => $semanaLabel,
    'semana_inicio' => (string)$row['semana_inicio'],
    'semana_fin'   => (string)$row['semana_fin'],
    'quimicos'     => $cantidad,    // alias compatible con partials compartidos
    'produccion'   => 0.0,
    'ratio'        => $cantidad,    // ratio = cantidad absoluta
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

$limiteVerde   = $ratioBase;
$limiteAmarillo = $ratioBase !== null
  ? $ratioBase * (1 + $toleranciaPct / 100)
  : null;

$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, 'consumo');
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit        = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = $yearSplit['anterior'];
$datosAnioActual   = $yearSplit['actual'];

$maxRatio = ReportEngine::maxRatio($reporte);

$totalRefaccionesAnioActual = array_sum(array_column($datosAnioActual, 'quimicos'));
$numSemanasAnioActual       = count($datosAnioActual);

// Alias de producción para compatibilidad con kpis.php
$totalProduccionAnioAnterior = 0.0;
$totalProduccionAnioActual   = 0.0;

// Promedio semanal para comparación de tendencia
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
$consumoRefaccionAnioAnterior  = [];
$consumoRefaccionAnioActual    = [];
$costoPromedioAnioAnterior     = [];
$costoPromedioAnioActual       = [];
$impactoEconomicoAnioAnterior  = [];
$impactoEconomicoAnioActual    = [];

$sqlAnual = "
    SELECT
        CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) AS anio_iso,
        TRIM(m.CVE_PROD) AS cve_prod,
        SUM(m.CANT_PROD) AS consumo_cantidad,
        CASE
            WHEN SUM(m.CANT_PROD) > 0
            THEN SUM(m.COSTO_ENT * m.CANT_PROD) / SUM(m.CANT_PROD)
            ELSE 0
        END AS costo_ponderado
    FROM movs m
    WHERE CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) IN (?, ?)
      AND TRIM(m.TIPO_MOV) = 'S'
      AND TRIM(m.LUGAR) = ?
";

$paramsAnual = [$anioAnterior, $anioActual, $lugar];

if (!$usarTodosLosProductos && !empty($productosConfig)) {
  $ph = createPlaceholders($productosConfig);
  $sqlAnual .= " AND TRIM(m.CVE_PROD) IN ($ph) ";
  $paramsAnual = array_merge($paramsAnual, $productosConfig);
}

if (!empty($productosIgnorar)) {
  $ph = createPlaceholders($productosIgnorar);
  $sqlAnual .= " AND TRIM(m.CVE_PROD) NOT IN ($ph) ";
  $paramsAnual = array_merge($paramsAnual, $productosIgnorar);
}

$sqlAnual .= "
    GROUP BY
        CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED),
        TRIM(m.CVE_PROD)
";

$stmtAnual = $pdoMovs->prepare($sqlAnual);
$stmtAnual->execute($paramsAnual);

while ($row = $stmtAnual->fetch()) {
  $key     = $row['cve_prod'];
  $anio    = (int)$row['anio_iso'];
  $consumo = (float)$row['consumo_cantidad'];
  $costo   = (float)$row['costo_ponderado'];

  if ($anio === $anioAnterior) {
    $consumoRefaccionAnioAnterior[$key]   = $consumo;
    $costoPromedioAnioAnterior[$key]      = $costo;
    $impactoEconomicoAnioAnterior[$key]   = $consumo * $costo;
  } else {
    $consumoRefaccionAnioActual[$key]     = $consumo;
    $costoPromedioAnioActual[$key]        = $costo;
    $impactoEconomicoAnioActual[$key]     = $consumo * $costo;
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
| 10) RESÚMENES POR SEMANA (para la fila de totales del pivot)
|--------------------------------------------------------------------------
*/
$totalesPorSemana  = [];
$produccionPorSemana = [];   // ceros (no hay producción)
$ratioPorSemana    = [];

foreach ($reporte as $row) {
  $anioRow = (int)substr((string)$row['semana_iso'], 0, 4);
  if ($anioRow !== $anioPivot) {
    continue;
  }

  $semanaLabel = $row['semana_label'];
  $totalesPorSemana[$semanaLabel]   = (float)$row['quimicos'];
  $produccionPorSemana[$semanaLabel] = 0.0;
  $ratioPorSemana[$semanaLabel]     = $row['ratio'];
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
  'titulo' => $config['titulo'] ?? 'Refacciones Críticas',

  'anioAnterior' => $anioAnterior,
  'anioActual'   => $anioActual,
  'anioPivot'    => $anioPivot,

  'ratioBase'              => $ratioBase,
  'ratioGlobal'            => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde'   => $limiteVerde,
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

  'reporte'          => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual'   => $datosAnioActual,

  'chartData' => $chartData,

  // Datos del pivot
  'semanasCatalogo'          => $semanasCatalogo,
  'refaccionesCatalogo'      => $refaccionesCatalogo,
  'refaccionesEtiquetas'     => $refaccionesEtiquetas,
  'matrizRefacciones'        => $matrizRefacciones,
  'matrizCostos'             => $matrizCostos,
  'produccionPivotPorSemana' => [],                     // vacío, no hay producción
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

  'meta' => [
    'fechaDesde'          => $fechaDesde,
    'campoFechaMovs'      => $campoFechaMovs,
    'productos'           => $productosConfig,
    'usarTodosLosProductos' => $usarTodosLosProductos,
    'cardsPorPagina'      => $cardsPorPagina,
    'filasPorPagina'      => $filasPorPagina,
    'toleranciaPct'       => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'lugar'               => $lugar,
    'metricaTitulo'       => 'Consumo Refacciones',
    'metricaUnidad'       => '',
    'badgeRatio'          => 'Consumo de refacciones críticas',
    'mostrarProduccion'   => false,
  ],
];

setCache($cacheKey, $result, 3600);

return $result;
