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
$productosEmpaques = $config['productos'] ?? [];
$toleranciaPct = (float)($config['tolerancia_pct'] ?? 10);
$cveMov = $config['cve_mov'] ?? null;
$usarTodosLosProductos = (bool)($config['usar_todos_los_productos'] ?? true);
$anioPivot = (int)($config['anio_pivot'] ?? date('Y'));
$lugarEmpaques = $config['lugar'] ?? 'EMPAQUES';
$productosAIgnorar = $config['productos_a_ignorar'] ?? ['DIES01'];

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

// Detectar los lugares reales para los empaques
// SOLO buscar en EMPAQUES
$sqlDetectarLugares = "
    SELECT m.LUGAR
    FROM movs m
    WHERE TRIM(m.TIPO_MOV) = 'S'
    AND m.LUGAR = 'EMPAQUES'
    AND $campoFechaMovsSql >= ?
";
$paramsDetectarLugares = [$fechaDesde];

// Si hay productos específicos, filtrar por ellos
if (!$usarTodosLosProductos && !empty($productosEmpaques)) {
  $placeholdersProd = createPlaceholders($productosEmpaques);
  $sqlDetectarLugares .= " AND TRIM(m.CVE_PROD) IN ($placeholdersProd) ";
  $paramsDetectarLugares = array_merge($paramsDetectarLugares, $productosEmpaques);
}

if (!empty($productosAIgnorar)) {
  $placeholdersIgn = createPlaceholders($productosAIgnorar);
  $sqlDetectarLugares .= " AND m.CVE_PROD NOT IN ($placeholdersIgn) ";
  $paramsDetectarLugares = array_merge($paramsDetectarLugares, $productosAIgnorar);
}

$sqlDetectarLugares .= " LIMIT 1";

$stmtLugares = $pdoMovs->prepare($sqlDetectarLugares);
$stmtLugares->execute($paramsDetectarLugares);
$lugarRow = $stmtLugares->fetch();

// Usar EMPAQUES siempre
$lugarEmpaques = 'EMPAQUES';

/*
|--------------------------------------------------------------------------
| 1) DETALLE POR EMPAQUE Y SEMANA
|--------------------------------------------------------------------------
| Se usa para:
| - matriz de cantidad por empaque/semana/unidad
| - base histórica por empaque y unidad
|--------------------------------------------------------------------------
*/
$sqlPivot = "
    SELECT
        " . $weekFields . ",
        TRIM(m.CVE_PROD) AS cve_prod,
        COALESCE(TRIM(p.DESC_PROD), '') AS desc_prod,
        UPPER(TRIM(m.UNIUSU)) AS unidad_original,
        CASE
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN 'KG'
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN 'G'
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('PZA','PIEZA','PIEZAS') THEN 'PZA'
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('ROLLO','ROLLOS') THEN 'ROLLO'
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('CAJA','CAJAS') THEN 'CAJA'
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('MILLA','MILL','MILLES','MILLARES') THEN 'MILLA'
            ELSE UPPER(TRIM(m.UNIUSU))
        END AS unidad_normalizada,
        SUM(m.CANT_PROD) AS cantidad
    FROM movs m
    LEFT JOIN producto p
        ON TRIM(p.CVE_PROD) = TRIM(m.CVE_PROD)
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = ?
";

$paramsPivot = [$fechaDesde, $lugarEmpaques];

// Agregar productos a ignorar
if (!empty($productosAIgnorar)) {
  $placeholdersIgnorar = createPlaceholders($productosAIgnorar);
  $sqlPivot .= " AND m.CVE_PROD NOT IN ($placeholdersIgnorar) ";
  $paramsPivot = array_merge($paramsPivot, $productosAIgnorar);
}

if (!$usarTodosLosProductos && !empty($productosEmpaques)) {
  $placeholdersPivot = createPlaceholders($productosEmpaques);
  $sqlPivot .= " AND TRIM(m.CVE_PROD) IN ($placeholdersPivot) ";
  $paramsPivot = array_merge($paramsPivot, $productosEmpaques);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlPivot .= " AND m.CVE_MOV = ? ";
  $paramsPivot[] = $cveMov;
}

$sqlPivot .= "
    GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ",
        TRIM(m.CVE_PROD),
        p.DESC_PROD,
        unidad_normalizada
    ORDER BY semana_iso, cve_prod, unidad_normalizada
";

$stmtPivot = $pdoMovs->prepare($sqlPivot);
$stmtPivot->execute($paramsPivot);
$rowsPivot = $stmtPivot->fetchAll();

// Si no hay datos con CVE_MOV, intentar sin ese filtro
if (empty($rowsPivot) && $cveMov !== null && $cveMov !== '') {
  $sqlPivotSinCve = str_replace(" AND m.CVE_MOV = ? ", "", $sqlPivot);
  // Remover el parámetro CVE_MOV
  $paramsPivotSinCve = array_slice($paramsPivot, 0, -1);

  $stmtPivotSinCve = $pdoMovs->prepare($sqlPivotSinCve);
  $stmtPivotSinCve->execute($paramsPivotSinCve);
  $rowsPivot = $stmtPivotSinCve->fetchAll();
}

/*
|--------------------------------------------------------------------------
| 2) TOTAL DE EMPAQUES POR SEMANA
|--------------------------------------------------------------------------
*/
$sqlEmpaques = "
    SELECT
        " . $weekFields . ",
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS empaques_kg
    FROM movs m
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = ?
";

$paramsEmpaques = [$fechaDesde, $lugarEmpaques];

// Agregar productos a ignorar
if (!empty($productosAIgnorar)) {
  $placeholdersIgnorar = createPlaceholders($productosAIgnorar);
  $sqlEmpaques .= " AND m.CVE_PROD NOT IN ($placeholdersIgnorar) ";
  $paramsEmpaques = array_merge($paramsEmpaques, $productosAIgnorar);
}

if (!$usarTodosLosProductos && !empty($productosEmpaques)) {
  $placeholders = createPlaceholders($productosEmpaques);
  $sqlEmpaques .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsEmpaques = array_merge($paramsEmpaques, $productosEmpaques);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlEmpaques .= " AND m.CVE_MOV = ? ";
  $paramsEmpaques[] = $cveMov;
}

$sqlEmpaques .= "
    GROUP BY YEARWEEK($campoFechaMovsSql, 3)
    ORDER BY periodo
";

$stmtE = $pdoMovs->prepare($sqlEmpaques);
$stmtE->execute($paramsEmpaques);

$empaquesPorPeriodo = [];
while ($row = $stmtE->fetch()) {
  $periodo = (int)$row['periodo'];
  $empaquesPorPeriodo[$periodo] = [
    'periodo' => $periodo,
    'semana_iso' => $row['semana_iso'],
    'semana_inicio' => $row['semana_inicio'],
    'semana_fin' => $row['semana_fin'],
    'empaques_kg' => (float)$row['empaques_kg'],
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
| 3.5) TOTAL DE EMPAQUES POR SEMANA
|--------------------------------------------------------------------------
*/
$sqlEmpaques = "
    SELECT
        " . $weekFields . ",
        SUM(m.CANT_PROD) AS empaques_cantidad
    FROM movs m
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = ?
";

$paramsEmpaques = [$fechaDesde, $lugarEmpaques];

// Agregar productos a ignorar
if (!empty($productosAIgnorar)) {
  $placeholdersIgnorar = createPlaceholders($productosAIgnorar);
  $sqlEmpaques .= " AND m.CVE_PROD NOT IN ($placeholdersIgnorar) ";
  $paramsEmpaques = array_merge($paramsEmpaques, $productosAIgnorar);
}

if (!$usarTodosLosProductos && !empty($productosEmpaques)) {
  $placeholders = createPlaceholders($productosEmpaques);
  $sqlEmpaques .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsEmpaques = array_merge($paramsEmpaques, $productosEmpaques);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlEmpaques .= " AND m.CVE_MOV = ? ";
  $paramsEmpaques[] = $cveMov;
}

$sqlEmpaques .= "
    GROUP BY YEARWEEK($campoFechaMovsSql, 3)
    ORDER BY periodo
";

$stmtE = $pdoMovs->prepare($sqlEmpaques);
$stmtE->execute($paramsEmpaques);

$empaquessPorPeriodo = [];
while ($row = $stmtE->fetch()) {
  $periodo = (int)$row['periodo'];
  $empaquessPorPeriodo[$periodo] = [
    'periodo' => $periodo,
    'semana_iso' => $row['semana_iso'],
    'semana_inicio' => $row['semana_inicio'],
    'semana_fin' => $row['semana_fin'],
    'empaques_cantidad' => (float)$row['empaques_cantidad'],
  ];
}

/*
|--------------------------------------------------------------------------
| 4) MATRIZ BASE DE CANTIDAD POR EMPAQUE/UNIDAD Y SEMANA DEL AÑO PIVOT
|--------------------------------------------------------------------------
*/
$semanasCatalogo = [];
$empaquessCatalogo = [];
$empaquesEtiquetas = [];
$unidadesCatalogo = [];
$matrizEmpaques = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioPivot) {
    continue;
  }

  $semanaLabel = substr($semanaIso, -3); // S01, S02...
  $cveProd = trim((string)$row['cve_prod']);
  $descProd = trim((string)$row['desc_prod']);
  $unidad = trim((string)$row['unidad_normalizada']);

  // Saltar si la unidad está vacía
  if (empty($unidad)) {
    continue;
  }

  $cantidad = (float)$row['cantidad'];

  $empaquesKey = $cveProd . '|' . $unidad;
  $empaquesLabel = $descProd !== '' ? $descProd : $cveProd;
  $empaquesLabelConUnidad = $empaquesLabel . ' (' . $unidad . ')';

  $empaquesEtiquetas[$empaquesKey] = $empaquesLabelConUnidad;

  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }

  if (!in_array($empaquesKey, $empaquessCatalogo, true)) {
    $empaquessCatalogo[] = $empaquesKey;
  }

  if (!in_array($unidad, $unidadesCatalogo, true)) {
    $unidadesCatalogo[] = $unidad;
  }

  if (!isset($matrizEmpaques[$empaquesKey])) {
    $matrizEmpaques[$empaquesKey] = [];
  }

  $matrizEmpaques[$empaquesKey][$semanaLabel] = $cantidad;
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
sort($empaquessCatalogo);

// Rellenar con 0 donde falte
foreach ($empaquessCatalogo as $empaquesKey) {
  if (!isset($matrizEmpaques[$empaquesKey])) {
    $matrizEmpaques[$empaquesKey] = [];
  }

  foreach ($semanasCatalogo as $semanaLabel) {
    if (!isset($matrizEmpaques[$empaquesKey][$semanaLabel])) {
      $matrizEmpaques[$empaquesKey][$semanaLabel] = 0.0;
    }
  }

  ksort($matrizEmpaques[$empaquesKey]);
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
| 6) BASE HISTÓRICA POR EMPAQUE/UNIDAD (AÑO ANTERIOR)
|--------------------------------------------------------------------------
| ratioBasePorEmpaque[empaque|unidad] = consumo histórico / producción histórica
|--------------------------------------------------------------------------
*/
$consumoBasePorEmpaque = [];

foreach ($rowsPivot as $row) {
  $semanaIso = (string)$row['semana_iso'];
  $anioIso = (int)substr($semanaIso, 0, 4);

  if ($anioIso !== $anioAnterior) {
    continue;
  }

  $cveProd = trim((string)$row['cve_prod']);
  $unidad = trim((string)$row['unidad_normalizada']);

  // Saltar si la unidad está vacía
  if (empty($unidad)) {
    continue;
  }

  $empaquesKey = $cveProd . '|' . $unidad;
  $cantidad = (float)$row['cantidad'];

  if (!isset($consumoBasePorEmpaque[$empaquesKey])) {
    $consumoBasePorEmpaque[$empaquesKey] = 0.0;
  }

  $consumoBasePorEmpaque[$empaquesKey] += $cantidad;
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

$ratioBasePorEmpaque = [];

foreach ($empaquessCatalogo as $empaquesKey) {
  $consumoBase = (float)($consumoBasePorEmpaque[$empaquesKey] ?? 0.0);

  $ratioBasePorEmpaque[$empaquesKey] = $produccionBaseTotal > 0
    ? ($consumoBase / $produccionBaseTotal)
    : null;
}

/*
|--------------------------------------------------------------------------
| 7) MATRIZ DE RATIO POR EMPAQUE / PRODUCCIÓN SEMANAL
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| Cada celda de la matriz debe ser:
| ratio_empaque_semana = kg_del_empaque / produccion_de_la_semana
|--------------------------------------------------------------------------
*/
$matrizRatioEmpaques = [];
$maxRatio = 0.0;

foreach ($empaquessCatalogo as $empaquesKey) {
  $matrizRatioEmpaques[$empaquesKey] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $kgEmpaque = (float)($matrizEmpaques[$empaquesKey][$semanaLabel] ?? 0.0);
    $produccionSemana = (float)($produccionPivotPorSemana[$semanaLabel] ?? 0.0);

    $ratioEmpaque = $produccionSemana > 0
      ? ($kgEmpaque / $produccionSemana)
      : null;

    $matrizRatioEmpaques[$empaquesKey][$semanaLabel] = $ratioEmpaque;

    if ($ratioEmpaque !== null) {
      $maxRatio = max($maxRatio, $ratioEmpaque);
    }
  }
}

/*
|--------------------------------------------------------------------------
| 8) REPORTE GENERAL SEMANAL
|--------------------------------------------------------------------------
|--------------------------------------------------------------------------
| Este sigue siendo el global de la semana:
| total_empaques_semana / produccion_semana
|--------------------------------------------------------------------------
*/
$periodos = array_unique(array_merge(
  array_keys($empaquesPorPeriodo),
  array_keys($produccionPorPeriodo)
));

sort($periodos);

$reporte = [];
$totales = [
  'empaques' => 0.0,
  'produccion' => 0.0,
];

$datosAnioAnterior = [];
$datosAnioActual = [];

$itemsTemporales = [];

foreach ($periodos as $periodo) {
  $semanaIso = $empaquesPorPeriodo[$periodo]['semana_iso']
    ?? $produccionPorPeriodo[$periodo]['semana_iso']
    ?? (string)$periodo;

  $semanaInicio = $empaquesPorPeriodo[$periodo]['semana_inicio']
    ?? $produccionPorPeriodo[$periodo]['semana_inicio']
    ?? '';

  $semanaFin = $empaquesPorPeriodo[$periodo]['semana_fin']
    ?? $produccionPorPeriodo[$periodo]['semana_fin']
    ?? '';

  $empaques = $empaquesPorPeriodo[$periodo]['empaques_kg'] ?? 0.0;
  $produccion = $produccionPorPeriodo[$periodo]['kilos_producidos'] ?? 0.0;

  $totales['empaques'] += $empaques;
  $totales['produccion'] += $produccion;

  $ratio = null;
  if ($produccion > 0) {
    $ratio = $empaques / $produccion;
    $maxRatio = max($maxRatio, $ratio);
  }

  $semanaLabel = substr((string)$semanaIso, -3);

  $item = [
    'periodo' => $periodo,
    'semana_iso' => $semanaIso,
    'semana_label' => $semanaLabel,
    'semana_inicio' => $semanaInicio,
    'semana_fin' => $semanaFin,
    'empaques' => $empaques,
    'quimicos' => $empaques,
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
$totalEmpaquesAnioAnterior = array_sum(array_column($datosAnioAnterior, 'empaques'));
$totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));

$ratioBase = $totalProduccionAnioAnterior > 0
  ? $totalEmpaquesAnioAnterior / $totalProduccionAnioAnterior
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
  ? $totales['empaques'] / $totales['produccion']
  : null;

[$estadoGlobal, $colorGlobal, $colorGlobalHex] = semaforo($ratioGlobal, $ratioBase, $toleranciaPct);

$totalEmpaquesAnioActual = array_sum(array_column($datosAnioActual, 'empaques'));
$totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));

$ratioPromedioAnioActual = $totalProduccionAnioActual > 0
  ? $totalEmpaquesAnioActual / $totalProduccionAnioActual
  : null;

$variacionEmpaques = $totalEmpaquesAnioAnterior > 0
  ? (($totalEmpaquesAnioActual - $totalEmpaquesAnioAnterior) / $totalEmpaquesAnioAnterior) * 100
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
  $totalesPorSemana[$semanaLabel] = (float)$row['empaques'];
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
  'titulo' => $config['titulo'] ?? 'Empaques en General / Producción',

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

  'totalQuimicosAnioAnterior' => $totalEmpaquesAnioAnterior,
  'totalProduccionAnioAnterior' => $totalProduccionAnioAnterior,
  'totalQuimicosAnioActual' => $totalEmpaquesAnioActual,
  'totalProduccionAnioActual' => $totalProduccionAnioActual,

  'variacionQuimicos' => $variacionEmpaques,
  'variacionProduccion' => $variacionProduccion,
  'variacionRatio' => $variacionRatio,

  'empaquessPorPeriodo' => $empaquessPorPeriodo,
  'produccionPorPeriodo' => $produccionPorPeriodo,

  'reporte' => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual' => $datosAnioActual,

  'chartData' => $chartData,

  // Datos de vista pivote
  'semanasCatalogo' => $semanasCatalogo,
  'empaquessCatalogo' => $empaquessCatalogo,
  'empaquesEtiquetas' => $empaquesEtiquetas,
  'unidadesCatalogo' => $unidadesCatalogo,
  'matrizEmpaques' => $matrizEmpaques,
  'produccionPivotPorSemana' => $produccionPivotPorSemana,
  'matrizRatioEmpaques' => $matrizRatioEmpaques,
  'ratioBasePorEmpaque' => $ratioBasePorEmpaque,
  'totalesPorSemana' => $totalesPorSemana,
  'produccionPorSemana' => $produccionPorSemana,
  'ratioPorSemana' => $ratioPorSemana,

  'maxRatio' => $maxRatio,
  'version' => $version,

  'meta' => [
    'fechaDesde' => $fechaDesde,
    'campoFechaMovs' => $campoFechaMovs,
    'productos' => $productosEmpaques,
    'usarTodosLosProductos' => $usarTodosLosProductos,
    'cardsPorPagina' => $cardsPorPagina,
    'filasPorPagina' => $filasPorPagina,
    'toleranciaPct' => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'cveMov' => $cveMov,
  ],
];
