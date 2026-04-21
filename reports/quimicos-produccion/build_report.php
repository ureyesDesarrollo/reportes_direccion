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
$grupoEstructura = $config['grupo_estructura'] ?? [];
$grupoPorProducto = [];
$grupoTitulos = [];

foreach ($grupoEstructura as $grupoKey => $grupoInfo) {
  $grupoTitulos[$grupoKey] = (string)($grupoInfo['titulo'] ?? $grupoKey);

  foreach (($grupoInfo['productos'] ?? []) as $productoGrupo) {
    $grupoPorProducto[(string)$productoGrupo] = $grupoKey;
  }
}

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
        ) AS quimicos_kg,
        AVG(m.COSTO_ENT) AS costo_promedio
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
$matrizCostos = [];
$matrizImpactoAcumulado = [];

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
  $costo = (float)($row['costo_promedio'] ?? 0.0);

  $quimicoKey = $grupoPorProducto[$cveProd] ?? $cveProd;
  $quimicoLabel = $grupoTitulos[$quimicoKey] ?? ($descProd !== '' ? $descProd : $cveProd);

  $quimicosEtiquetas[$quimicoKey] = $quimicoLabel;

  if (!in_array($semanaLabel, $semanasCatalogo, true)) {
    $semanasCatalogo[] = $semanaLabel;
  }

  if (!in_array($quimicoKey, $quimicosCatalogo, true)) {
    $quimicosCatalogo[] = $quimicoKey;
  }

  if (!isset($matrizQuimicos[$quimicoKey])) {
    $matrizQuimicos[$quimicoKey] = [];
    $matrizCostos[$quimicoKey] = [];
    $matrizImpactoAcumulado[$quimicoKey] = [];
  }

  if (!isset($matrizQuimicos[$quimicoKey][$semanaLabel])) {
    $matrizQuimicos[$quimicoKey][$semanaLabel] = 0.0;
    $matrizCostos[$quimicoKey][$semanaLabel] = 0.0;
    $matrizImpactoAcumulado[$quimicoKey][$semanaLabel] = 0.0;
  }

  $matrizQuimicos[$quimicoKey][$semanaLabel] += $kg;
  $matrizImpactoAcumulado[$quimicoKey][$semanaLabel] += $kg * $costo;
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
    $matrizCostos[$quimicoKey] = [];
    $matrizImpactoAcumulado[$quimicoKey] = [];
  }

  if (!isset($matrizCostos[$quimicoKey])) {
    $matrizCostos[$quimicoKey] = [];
  }

  foreach ($semanasCatalogo as $semanaLabel) {
    if (!isset($matrizQuimicos[$quimicoKey][$semanaLabel])) {
      $matrizQuimicos[$quimicoKey][$semanaLabel] = 0.0;
    }
    if (!isset($matrizImpactoAcumulado[$quimicoKey][$semanaLabel])) {
      $matrizImpactoAcumulado[$quimicoKey][$semanaLabel] = 0.0;
    }

    $matrizCostos[$quimicoKey][$semanaLabel] = $matrizQuimicos[$quimicoKey][$semanaLabel] > 0
      ? ($matrizImpactoAcumulado[$quimicoKey][$semanaLabel] / $matrizQuimicos[$quimicoKey][$semanaLabel])
      : 0.0;
  }

  ksort($matrizQuimicos[$quimicoKey]);
  ksort($matrizCostos[$quimicoKey]);
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

  $cveProd = trim((string)$row['cve_prod']);
  $quimicoKey = $grupoPorProducto[$cveProd] ?? $cveProd;
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
| 7b) MATRIZ DE IMPACTO ECONÓMICO (KG × COSTO)
|--------------------------------------------------------------------------
*/
$matrizImpactoEconomicoQuimicos = [];

foreach ($quimicosCatalogo as $quimicoKey) {
  $matrizImpactoEconomicoQuimicos[$quimicoKey] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $kgQuimico = (float)($matrizQuimicos[$quimicoKey][$semanaLabel] ?? 0.0);
    $costoPromedio = (float)($matrizCostos[$quimicoKey][$semanaLabel] ?? 0.0);

    $impactoEconomico = $kgQuimico * $costoPromedio;
    $matrizImpactoEconomicoQuimicos[$quimicoKey][$semanaLabel] = $impactoEconomico;
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
| 9a) CONSUMO POR QUÍMICO DEL AÑO ANTERIOR (para ordenamiento)
|--------------------------------------------------------------------------
*/
$consumoQuimicoAnioAnterior = [];

$sqlConsumoAnioAnterior = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS consumo_kg
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsAnioAnterior = [$anioAnterior];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlConsumoAnioAnterior .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsAnioAnterior = array_merge($paramsAnioAnterior, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlConsumoAnioAnterior .= " AND m.CVE_MOV = ? ";
  $paramsAnioAnterior[] = $cveMov;
}

$sqlConsumoAnioAnterior .= " GROUP BY TRIM(m.CVE_PROD) ORDER BY consumo_kg DESC ";

$stmtConsumoAnioAnterior = $pdoMovs->prepare($sqlConsumoAnioAnterior);
$stmtConsumoAnioAnterior->execute($paramsAnioAnterior);

while ($row = $stmtConsumoAnioAnterior->fetch()) {
  $consumoQuimicoAnioAnterior[$row['cve_prod']] = (float)$row['consumo_kg'];
}

/*
|--------------------------------------------------------------------------
| 9a2) CONSUMO POR QUÍMICO DEL AÑO ACTUAL
|--------------------------------------------------------------------------
*/
$consumoQuimicoAnioActual = [];

$sqlConsumoAnioActual = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS consumo_kg
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsAnioActual = [$anioActual];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlConsumoAnioActual .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsAnioActual = array_merge($paramsAnioActual, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlConsumoAnioActual .= " AND m.CVE_MOV = ? ";
  $paramsAnioActual[] = $cveMov;
}

$sqlConsumoAnioActual .= " GROUP BY TRIM(m.CVE_PROD) ORDER BY consumo_kg DESC ";

$stmtConsumoAnioActual = $pdoMovs->prepare($sqlConsumoAnioActual);
$stmtConsumoAnioActual->execute($paramsAnioActual);

while ($row = $stmtConsumoAnioActual->fetch()) {
  $consumoQuimicoAnioActual[$row['cve_prod']] = (float)$row['consumo_kg'];
}

/*
|--------------------------------------------------------------------------
| 9a3) PRECIO PROMEDIO POR QUÍMICO DEL AÑO ANTERIOR
|--------------------------------------------------------------------------
*/
$costoPromedioAnioAnterior = [];

$sqlCostoPromedioAnioAnterior = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        AVG(m.COSTO_ENT) AS costo_promedio
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsCostoPromedioAnioAnterior = [$anioAnterior];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlCostoPromedioAnioAnterior .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsCostoPromedioAnioAnterior = array_merge($paramsCostoPromedioAnioAnterior, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlCostoPromedioAnioAnterior .= " AND m.CVE_MOV = ? ";
  $paramsCostoPromedioAnioAnterior[] = $cveMov;
}

$sqlCostoPromedioAnioAnterior .= " GROUP BY TRIM(m.CVE_PROD) ";

$stmtCostoPromedioAnioAnterior = $pdoMovs->prepare($sqlCostoPromedioAnioAnterior);
$stmtCostoPromedioAnioAnterior->execute($paramsCostoPromedioAnioAnterior);

while ($row = $stmtCostoPromedioAnioAnterior->fetch()) {
  $costoPromedioAnioAnterior[$row['cve_prod']] = (float)$row['costo_promedio'];
}

/*
|--------------------------------------------------------------------------
| 9a3.5) PRECIO PROMEDIO POR QUÍMICO DEL AÑO ACTUAL
|--------------------------------------------------------------------------
*/
$costoPromedioAnioActual = [];

$sqlCostoPromedioAnioActual = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        AVG(m.COSTO_ENT) AS costo_promedio
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsCostoPromedioAnioActual = [$anioActual];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlCostoPromedioAnioActual .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsCostoPromedioAnioActual = array_merge($paramsCostoPromedioAnioActual, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlCostoPromedioAnioActual .= " AND m.CVE_MOV = ? ";
  $paramsCostoPromedioAnioActual[] = $cveMov;
}

$sqlCostoPromedioAnioActual .= " GROUP BY TRIM(m.CVE_PROD) ";

$stmtCostoPromedioAnioActual = $pdoMovs->prepare($sqlCostoPromedioAnioActual);
$stmtCostoPromedioAnioActual->execute($paramsCostoPromedioAnioActual);

while ($row = $stmtCostoPromedioAnioActual->fetch()) {
  $costoPromedioAnioActual[$row['cve_prod']] = (float)$row['costo_promedio'];
}

/*
|--------------------------------------------------------------------------
| 9a4) IMPACTO ECONÓMICO POR QUÍMICO DEL AÑO ANTERIOR (consumo * costo)
|--------------------------------------------------------------------------
*/
$impactoEconomicoAnioAnterior = [];

$sqlImpactoAnioAnterior = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS consumo_kg,
        AVG(m.COSTO_ENT) AS costo_promedio
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsImpactoAnioAnterior = [$anioAnterior];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlImpactoAnioAnterior .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsImpactoAnioAnterior = array_merge($paramsImpactoAnioAnterior, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlImpactoAnioAnterior .= " AND m.CVE_MOV = ? ";
  $paramsImpactoAnioAnterior[] = $cveMov;
}

$sqlImpactoAnioAnterior .= " GROUP BY TRIM(m.CVE_PROD) ";

$stmtImpactoAnioAnterior = $pdoMovs->prepare($sqlImpactoAnioAnterior);
$stmtImpactoAnioAnterior->execute($paramsImpactoAnioAnterior);

while ($row = $stmtImpactoAnioAnterior->fetch()) {
  $consumo = (float)$row['consumo_kg'];
  $costo = (float)$row['costo_promedio'];
  $impactoEconomicoAnioAnterior[$row['cve_prod']] = $consumo * $costo;
}

/*
|--------------------------------------------------------------------------
| 9a5) IMPACTO ECONÓMICO POR QUÍMICO DEL AÑO ACTUAL (consumo * costo)
|--------------------------------------------------------------------------
*/
$impactoEconomicoAnioActual = [];

$sqlImpactoAnioActual = "
    SELECT
        TRIM(m.CVE_PROD) as cve_prod,
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        ) AS consumo_kg,
        AVG(m.COSTO_ENT) AS costo_promedio
    FROM movs m
    WHERE CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) = ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsImpactoAnioActual = [$anioActual];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlImpactoAnioActual .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsImpactoAnioActual = array_merge($paramsImpactoAnioActual, $productosQuimicos);
}

if ($cveMov !== null && $cveMov !== '') {
  $sqlImpactoAnioActual .= " AND m.CVE_MOV = ? ";
  $paramsImpactoAnioActual[] = $cveMov;
}

$sqlImpactoAnioActual .= " GROUP BY TRIM(m.CVE_PROD) ";

$stmtImpactoAnioActual = $pdoMovs->prepare($sqlImpactoAnioActual);
$stmtImpactoAnioActual->execute($paramsImpactoAnioActual);

while ($row = $stmtImpactoAnioActual->fetch()) {
  $consumo = (float)$row['consumo_kg'];
  $costo = (float)$row['costo_promedio'];
  $impactoEconomicoAnioActual[$row['cve_prod']] = $consumo * $costo;
}

$agruparPorClaveVisible = static function (array $valores) use ($grupoPorProducto): array {
  $agrupados = [];

  foreach ($valores as $clave => $valor) {
    $claveVisible = $grupoPorProducto[$clave] ?? $clave;

    if (!isset($agrupados[$claveVisible])) {
      $agrupados[$claveVisible] = 0.0;
    }

    $agrupados[$claveVisible] += (float)$valor;
  }

  return $agrupados;
};

$consumoQuimicoAnioAnterior = $agruparPorClaveVisible($consumoQuimicoAnioAnterior);
$consumoQuimicoAnioActual = $agruparPorClaveVisible($consumoQuimicoAnioActual);
$impactoEconomicoAnioAnterior = $agruparPorClaveVisible($impactoEconomicoAnioAnterior);
$impactoEconomicoAnioActual = $agruparPorClaveVisible($impactoEconomicoAnioActual);

$costoPromedioAnioAnteriorAgrupado = [];
$costoPromedioAnioActualAgrupado = [];

foreach ($quimicosCatalogo as $quimicoKey) {
  $consumoAnteriorAgrupado = (float)($consumoQuimicoAnioAnterior[$quimicoKey] ?? 0.0);
  $consumoActualAgrupado = (float)($consumoQuimicoAnioActual[$quimicoKey] ?? 0.0);
  $impactoAnteriorAgrupado = (float)($impactoEconomicoAnioAnterior[$quimicoKey] ?? 0.0);
  $impactoActualAgrupado = (float)($impactoEconomicoAnioActual[$quimicoKey] ?? 0.0);

  $costoPromedioAnioAnteriorAgrupado[$quimicoKey] = $consumoAnteriorAgrupado > 0
    ? ($impactoAnteriorAgrupado / $consumoAnteriorAgrupado)
    : 0.0;
  $costoPromedioAnioActualAgrupado[$quimicoKey] = $consumoActualAgrupado > 0
    ? ($impactoActualAgrupado / $consumoActualAgrupado)
    : 0.0;
}

$costoPromedioAnioAnterior = $costoPromedioAnioAnteriorAgrupado;
$costoPromedioAnioActual = $costoPromedioAnioActualAgrupado;

/*
|--------------------------------------------------------------------------
| 9b) TOTALES DE CONSUMO Y COSTO POR QUÍMICO (VARIACIÓN ACTUAL VS ANTERIOR)
|--------------------------------------------------------------------------
*/
$totalesConsumoQuimico = [];
$totalesCostoQuimico = [];
$variacionConsumoQuimico = []; // Para semáforo
$variacionCostoQuimico = []; // Para semáforo en modo costo

foreach ($quimicosCatalogo as $quimico) {
  // Consumo
  $consumoActual = (float)($consumoQuimicoAnioActual[$quimico] ?? 0.0);
  $consumoAnterior = (float)($consumoQuimicoAnioAnterior[$quimico] ?? 0.0);
  $variacionConsumo = $consumoAnterior > 0 ? (($consumoActual - $consumoAnterior) / $consumoAnterior) * 100 : 0;

  // Costo (precio promedio unitario)
  $costoPromActual = (float)($costoPromedioAnioActual[$quimico] ?? 0.0);
  $costoPromAnterior = (float)($costoPromedioAnioAnterior[$quimico] ?? 0.0);
  $variacionCosto = $costoPromAnterior > 0 ? (($costoPromActual - $costoPromAnterior) / $costoPromAnterior) * 100 : 0;

  // Para ordenamiento, usar impacto económico total
  $costoActual = (float)($impactoEconomicoAnioActual[$quimico] ?? 0.0);

  $totalesConsumoQuimico[$quimico] = $consumoActual;
  $totalesCostoQuimico[$quimico] = $costoActual;
  $variacionConsumoQuimico[$quimico] = $variacionConsumo;
  $variacionCostoQuimico[$quimico] = $variacionCosto;
}

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
  'matrizCostos' => $matrizCostos,
  'produccionPivotPorSemana' => $produccionPivotPorSemana,
  'matrizRatioQuimicos' => $matrizRatioQuimicos,
  'matrizImpactoEconomicoQuimicos' => $matrizImpactoEconomicoQuimicos,
  'ratioBasePorQuimico' => $ratioBasePorQuimico,
  'totalesPorSemana' => $totalesPorSemana,
  'produccionPorSemana' => $produccionPorSemana,
  'ratioPorSemana' => $ratioPorSemana,
  'totalesConsumoQuimico' => $totalesConsumoQuimico,
  'totalesCostoQuimico' => $totalesCostoQuimico,
  'consumoQuimicoAnioAnterior' => $consumoQuimicoAnioAnterior,
  'consumoQuimicoAnioActual' => $consumoQuimicoAnioActual,
  'variacionConsumoQuimico' => $variacionConsumoQuimico,
  'costoPromedioAnioAnterior' => $costoPromedioAnioAnterior,
  'costoPromedioAnioActual' => $costoPromedioAnioActual,
  'variacionCostoQuimico' => $variacionCostoQuimico,
  'impactoEconomicoAnioAnterior' => $impactoEconomicoAnioAnterior,
  'impactoEconomicoAnioActual' => $impactoEconomicoAnioActual,

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
    'grupo_estructura' => $grupoEstructura,
  ],
];
