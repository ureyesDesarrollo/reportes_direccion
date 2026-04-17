<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';

/*
|--------------------------------------------------------------------------
| build_report.php
|--------------------------------------------------------------------------
| Requiere que antes se carguen:
| - $appConfig = require ../../config/app.php
| - $dbConfig  = require ../../config/database.php
| - $config    = require ./config.php
| - require ../../shared/helpers.php
| - require ../../shared/ReportHelpers.php
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$fechaDesde = $config['fecha_desde'];
$campoFechaMovs = $config['campo_fecha_movs'];
$productosQuimicos = $config['productos'] ?? [];
$toleranciaPct = (float)($config['tolerancia_pct'] ?? 10);
$cveMov = $config['cve_mov'] ?? null;
$usarTodosLosProductos = (bool)($config['usar_todos_los_productos'] ?? true);
$anioPivot = (int)($config['anio_pivot'] ?? date('Y'));

$cardsPorPagina = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

$state = ReportEngine::createContext($config, $appConfig, $dbConfig);
$pdoMovs = $state['pdoMovs'];
$pdoProd = $state['pdoProd'];
$campoFechaMovsSql = $state['campoFechaMovsSql'];
$weekFields = $state['weekFields'];

/*
|--------------------------------------------------------------------------
| 1) DETALLE POR QUÍMICO Y SEMANA
|--------------------------------------------------------------------------
| Se usa para:
| - matriz de kg por químico/semana
| - base histórica por químico
|--------------------------------------------------------------------------
*/
$sqlPivot = "
    SELECT
        " . $weekFields . ",
        TRIM(m.CVE_PROD) AS cve_prod,
        COALESCE(TRIM(p.DESC_PROD), '') AS desc_prod,
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS quimicos_kg
    FROM movs m
    LEFT JOIN producto p
        ON TRIM(p.CVE_PROD) = TRIM(m.CVE_PROD)
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsPivot = [$fechaDesde];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholdersPivot = createPlaceholders($productosQuimicos);
  $sqlPivot .= " AND TRIM(m.CVE_PROD) IN ($placeholdersPivot) ";
  $paramsPivot = array_merge($paramsPivot, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlPivot .= " AND m.CVE_MOV = ? ";
  $paramsPivot[] = $cveMov;
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
| 2) TOTAL DE QUÍMICOS POR SEMANA
|--------------------------------------------------------------------------
*/
$sqlQuimicos = "
    SELECT
        " . $weekFields . ",
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS quimicos_kg
    FROM movs m
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsQuimicos = [$fechaDesde];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlQuimicos .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsQuimicos = array_merge($paramsQuimicos, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlQuimicos .= " AND m.CVE_MOV = ? ";
  $paramsQuimicos[] = $cveMov;
}

$sqlQuimicos .= "
    GROUP BY YEARWEEK($campoFechaMovsSql, 3)
    ORDER BY periodo
";

$stmtQ = $pdoMovs->prepare($sqlQuimicos);
$stmtQ->execute($paramsQuimicos);

$quimicosPorPeriodo = [];
while ($row = $stmtQ->fetch()) {
  $periodo = (int)$row['periodo'];
  $quimicosPorPeriodo[$periodo] = [
    'periodo' => $periodo,
    'semana_iso' => $row['semana_iso'],
    'semana_inicio' => $row['semana_inicio'],
    'semana_fin' => $row['semana_fin'],
    'quimicos_kg' => (float)$row['quimicos_kg'],
  ];
}

/*
|--------------------------------------------------------------------------
| 3) PRODUCCIÓN POR SEMANA
|--------------------------------------------------------------------------
*/
$produccionPorPeriodo = ReportEngine::fetchProductionSeries($pdoProd, $fechaDesde);

/*
|--------------------------------------------------------------------------
| 4) MATRIZ BASE DE KG POR QUÍMICO Y SEMANA DEL AÑO PIVOT
|--------------------------------------------------------------------------
*/
$semanasCatalogo = [];
$quimicosCatalogo = [];
$quimicosEtiquetas = [];
$matrizQuimicos = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel = substr($semanaIso, -3); // S01, S02...
  $cveProd = trim((string)$row['cve_prod']);
  $descProd = trim((string)$row['desc_prod']);
  $kg = (float)$row['quimicos_kg'];

  $quimicoKey = $cveProd;
  $quimicoLabel = $descProd !== '' ? $descProd : $cveProd;

  $quimicosEtiquetas[$quimicoKey] = $quimicoLabel;

  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }

  if (!in_array($quimicoKey, $quimicosCatalogo, true)) {
    $quimicosCatalogo[] = $quimicoKey;
  }

  if (!isset($matrizQuimicos[$quimicoKey])) {
    $matrizQuimicos[$quimicoKey] = [];
  }

  $matrizQuimicos[$quimicoKey][$semanaLabel] = $kg;
}

// si existe producción en una semana del año pivot, asegúrala como columna
foreach ($produccionPorPeriodo as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel = substr($semanaIso, -3);
  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }
}

sort($semanasCatalogo);
sort($quimicosCatalogo);

// Rellenar con 0 donde falte
foreach ($quimicosCatalogo as $quimicoKey) {
  if (!isset($matrizQuimicos[$quimicoKey])) {
    $matrizQuimicos[$quimicoKey] = [];
  }

  foreach ($semanasCatalogo as $semanaLabel) {
    if (!isset($matrizQuimicos[$quimicoKey][$semanaLabel])) {
      $matrizQuimicos[$quimicoKey][$semanaLabel] = 0.0;
    }
  }

  ksort($matrizQuimicos[$quimicoKey]);
}

/*
|--------------------------------------------------------------------------
| 5) PRODUCCIÓN DEL AÑO PIVOT POR SEMANA
|--------------------------------------------------------------------------
*/
$produccionPivotPorSemana = [];

foreach ($produccionPorPeriodo as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel = substr($semanaIso, -3);
  $produccionPivotPorSemana[$semanaLabel] = (float)$row['kilos_producidos'];
}

foreach ($semanasCatalogo as $semanaLabel) {
  if (!isset($produccionPivotPorSemana[$semanaLabel])) {
    $produccionPivotPorSemana[$semanaLabel] = 0.0;
  }
}

/*
|--------------------------------------------------------------------------
| 6) BASE HISTÓRICA POR QUÍMICO (AÑO ANTERIOR)
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| ratioBasePorQuimico[quimico] = consumo histórico del químico / producción histórica
|--------------------------------------------------------------------------
*/
$consumoBasePorQuimico = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioAnterior) {
    continue;
  }

  $quimicoKey = trim((string)$row['cve_prod']);
  $kg = (float)$row['quimicos_kg'];

  if (!isset($consumoBasePorQuimico[$quimicoKey])) {
    $consumoBasePorQuimico[$quimicoKey] = 0.0;
  }

  $consumoBasePorQuimico[$quimicoKey] += $kg;
}

$produccionBaseTotal = 0.0;

foreach ($produccionPorPeriodo as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioAnterior) {
    continue;
  }

  $produccionBaseTotal += (float)$row['kilos_producidos'];
}

$ratioBasePorQuimico = [];

foreach ($quimicosCatalogo as $quimicoKey) {
  $consumoBase = (float)($consumoBasePorQuimico[$quimicoKey] ?? 0.0);

  $ratioBasePorQuimico[$quimicoKey] = $produccionBaseTotal > 0
    ? ($consumoBase / $produccionBaseTotal)
    : null;
}

/*
|--------------------------------------------------------------------------
| 7) MATRIZ DE RATIO POR QUÍMICO / PRODUCCIÓN SEMANAL
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| Cada celda de la matriz debe ser:
| ratio_quimico_semana = kg_del_quimico / produccion_de_la_semana
|--------------------------------------------------------------------------
*/
$matrizRatioQuimicos = [];
$maxRatio = 0.0;

foreach ($quimicosCatalogo as $quimicoKey) {
  $matrizRatioQuimicos[$quimicoKey] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $kgQuimico = (float)($matrizQuimicos[$quimicoKey][$semanaLabel] ?? 0.0);
    $produccionSemana = (float)($produccionPivotPorSemana[$semanaLabel] ?? 0.0);

    $ratioQuimico = $produccionSemana > 0
      ? ($kgQuimico / $produccionSemana)
      : null;

    $matrizRatioQuimicos[$quimicoKey][$semanaLabel] = $ratioQuimico;

    if ($ratioQuimico !== null) {
      $maxRatio = max($maxRatio, $ratioQuimico);
    }
  }
}

/*
|--------------------------------------------------------------------------
| 8) REPORTE GENERAL SEMANAL
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| Este sigue siendo el global de la semana:
| total_quimicos_semana / produccion_semana
|--------------------------------------------------------------------------
*/
$periodos = array_unique(array_merge(
  array_keys($quimicosPorPeriodo),
  array_keys($produccionPorPeriodo)
));

sort($periodos);

$reporte = [];
$totales = [
  'quimicos' => 0.0,
  'produccion' => 0.0,
];

$datosAnioAnterior = [];
$datosAnioActual = [];

$itemsTemporales = [];

foreach ($periodos as $periodo) {
  $semanaIso = $quimicosPorPeriodo[$periodo]['semana_iso']
    ?? $produccionPorPeriodo[$periodo]['semana_iso']
    ?? (string)$periodo;

  $semanaInicio = $quimicosPorPeriodo[$periodo]['semana_inicio']
    ?? $produccionPorPeriodo[$periodo]['semana_inicio']
    ?? '';

  $semanaFin = $quimicosPorPeriodo[$periodo]['semana_fin']
    ?? $produccionPorPeriodo[$periodo]['semana_fin']
    ?? '';

  $quimicos = $quimicosPorPeriodo[$periodo]['quimicos_kg'] ?? 0.0;
  $produccion = $produccionPorPeriodo[$periodo]['kilos_producidos'] ?? 0.0;

  $totales['quimicos'] += $quimicos;
  $totales['produccion'] += $produccion;

  $ratio = null;
  if ($produccion > 0) {
    $ratio = $quimicos / $produccion;
    $maxRatio = max($maxRatio, $ratio);
  }

  $semanaLabel = substr((string)$semanaIso, -3);

  $item = [
    'periodo' => $periodo,
    'semana_iso' => $semanaIso,
    'semana_label' => $semanaLabel,
    'semana_inicio' => $semanaInicio,
    'semana_fin' => $semanaFin,
    'quimicos' => $quimicos,
    'produccion' => $produccion,
    'ratio' => $ratio,
  ];

  $itemsTemporales[] = $item;

  $anioItem = (int)substr((string)$semanaIso, 0, 4);
  if ($anioItem === $anioAnterior) {
    $datosAnioAnterior[] = $item;
  } elseif ($anioItem === $anioActual) {
    $datosAnioActual[] = $item;
  }
}

/*
|--------------------------------------------------------------------------
| 9) BASE Y SEMÁFORO GLOBAL
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| Esta base sigue siendo la del reporte global semanal
|--------------------------------------------------------------------------
*/
$totalQuimicosAnioAnterior = array_sum(array_column($datosAnioAnterior, 'quimicos'));
$totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));

$ratioBase = $totalProduccionAnioAnterior > 0
  ? $totalQuimicosAnioAnterior / $totalProduccionAnioAnterior
  : null;

$limiteVerde = $ratioBase;
$limiteAmarillo = $ratioBase !== null
  ? $ratioBase * (1 + $toleranciaPct / 100)
  : null;

$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, 'consumo');
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = $yearSplit['anterior'];
$datosAnioActual = $yearSplit['actual'];

$maxRatio = ReportEngine::maxRatio($reporte);

$ratioGlobal = $totales['produccion'] > 0
  ? $totales['quimicos'] / $totales['produccion']
  : null;

[$estadoGlobal, $colorGlobal, $colorGlobalHex] = semaforo($ratioGlobal, $ratioBase, $toleranciaPct);

$totalQuimicosAnioActual = array_sum(array_column($datosAnioActual, 'quimicos'));
$totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));

$ratioPromedioAnioActual = $totalProduccionAnioActual > 0
  ? $totalQuimicosAnioActual / $totalProduccionAnioActual
  : null;

$variacionQuimicos = $totalQuimicosAnioAnterior > 0
  ? (($totalQuimicosAnioActual - $totalQuimicosAnioAnterior) / $totalQuimicosAnioAnterior) * 100
  : null;

$variacionProduccion = $totalProduccionAnioAnterior > 0
  ? (($totalProduccionAnioActual - $totalProduccionAnioAnterior) / $totalProduccionAnioAnterior) * 100
  : null;

$variacionRatio = ($ratioBase !== null && $ratioBase > 0 && $ratioPromedioAnioActual !== null)
  ? (($ratioPromedioAnioActual - $ratioBase) / $ratioBase) * 100
  : null;

$version = time();

/*
|--------------------------------------------------------------------------
| 10) RESÚMENES POR SEMANA PARA LA MATRIZ
|--------------------------------------------------------------------------
*/
$totalesPorSemana = [];
$produccionPorSemana = [];
$ratioPorSemana = [];

foreach ($reporte as $row) {
  $anioRow = (int)substr((string)$row['semana_iso'], 0, 4);
  if ($anioRow !== $anioPivot) {
    continue;
  }

  $semanaLabel = $row['semana_label'];
  $totalesPorSemana[$semanaLabel] = (float)$row['quimicos'];
  $produccionPorSemana[$semanaLabel] = (float)$row['produccion'];
  $ratioPorSemana[$semanaLabel] = $row['ratio'];
}

foreach ($semanasCatalogo as $semanaLabel) {
  $totalesPorSemana[$semanaLabel] = $totalesPorSemana[$semanaLabel] ?? 0.0;
  $produccionPorSemana[$semanaLabel] = $produccionPorSemana[$semanaLabel] ?? 0.0;
  $ratioPorSemana[$semanaLabel] = $ratioPorSemana[$semanaLabel] ?? null;
}

/*
|--------------------------------------------------------------------------
| 11) CHART DATA
|--------------------------------------------------------------------------
*/
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase);

/*
|--------------------------------------------------------------------------
| 12) RESPUESTA FINAL
|--------------------------------------------------------------------------
*/
return [
  'titulo' => $config['titulo'] ?? 'Químicos en General / Producción',

  'anioAnterior' => $anioAnterior,
  'anioActual' => $anioActual,
  'anioPivot' => $anioPivot,

  'ratioBase' => $ratioBase,
  'ratioGlobal' => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde' => $limiteVerde,
  'limiteAmarillo' => $limiteAmarillo,

  'estadoGlobal' => $estadoGlobal,
  'colorGlobal' => $colorGlobal,
  'colorGlobalHex' => $colorGlobalHex,

  'totalQuimicosAnioAnterior' => $totalQuimicosAnioAnterior,
  'totalProduccionAnioAnterior' => $totalProduccionAnioAnterior,
  'totalQuimicosAnioActual' => $totalQuimicosAnioActual,
  'totalProduccionAnioActual' => $totalProduccionAnioActual,

  'variacionQuimicos' => $variacionQuimicos,
  'variacionProduccion' => $variacionProduccion,
  'variacionRatio' => $variacionRatio,

  'quimicosPorPeriodo' => $quimicosPorPeriodo,
  'produccionPorPeriodo' => $produccionPorPeriodo,

  'reporte' => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual' => $datosAnioActual,

  'chartData' => $chartData,

  // Datos de vista pivote
  'semanasCatalogo' => $semanasCatalogo,
  'quimicosCatalogo' => $quimicosCatalogo,
  'quimicosEtiquetas' => $quimicosEtiquetas,
  'matrizQuimicos' => $matrizQuimicos,
  'produccionPivotPorSemana' => $produccionPivotPorSemana,
  'matrizRatioQuimicos' => $matrizRatioQuimicos,
  'ratioBasePorQuimico' => $ratioBasePorQuimico,
  'totalesPorSemana' => $totalesPorSemana,
  'produccionPorSemana' => $produccionPorSemana,
  'ratioPorSemana' => $ratioPorSemana,

  'maxRatio' => $maxRatio,
  'version' => $version,

  'meta' => [
    'fechaDesde' => $fechaDesde,
    'campoFechaMovs' => $campoFechaMovs,
    'productos' => $productosQuimicos,
    'usarTodosLosProductos' => $usarTodosLosProductos,
    'cardsPorPagina' => $cardsPorPagina,
    'filasPorPagina' => $filasPorPagina,
    'toleranciaPct' => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'cveMov' => $cveMov,
  ],
];
