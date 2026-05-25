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
$normalizarCveMov = static function ($valor): array {
  $valores = is_array($valor) ? $valor : [$valor];
  $normalizados = [];

  foreach ($valores as $item) {
    $item = trim((string)$item);
    if ($item !== '' && !in_array($item, $normalizados, true)) {
      $normalizados[] = $item;
    }
  }

  return $normalizados;
};

$cveMovConsumo = $normalizarCveMov($config['cve_mov_consumo'] ?? ($config['cve_mov'] ?? '17'));
$cveMovAjuste = $normalizarCveMov($config['cve_mov_ajuste'] ?? '15');
$cveMovReporte = array_values(array_unique(array_merge($cveMovConsumo, $cveMovAjuste)));
$conversionesUnidadProducto = $config['conversiones_unidad_producto'] ?? [];
$usarTodosLosProductos = (bool)($config['usar_todos_los_productos'] ?? true);
$anioPivot = (int)($config['anio_pivot'] ?? date('Y'));

$cardsPorPagina = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs);

// Cache diario — se invalida al modificar este archivo
$cacheKey = 'report_' . md5(serialize([
  $config,
  $appConfig['cards_por_pagina'] ?? 9,
  $appConfig['filas_por_pagina'] ?? 15,
  filemtime(__FILE__),
  filesize(__FILE__),
  date('Y-m-d'),
]));
$cached = getCache($cacheKey);
if ($cached !== null) {
  return $cached;
}

$state = ReportEngine::createContext($config, $appConfig, $dbConfig);
$pdoMovs = $state['pdoMovs'];
$pdoProd = $state['pdoProd'];
$dbCompras = $dbConfig['movs'];
$dbCompras['dbname'] = 'saipbi2';
$pdoCompras = conectar($dbCompras);
$campoFechaMovsSql = $state['campoFechaMovsSql'];
$weekFields = $state['weekFields'];
$conversionCases = [];
$costQuantityConversionCases = [];

foreach ($conversionesUnidadProducto as $productoConversion => $unidadesConversion) {
  foreach ((array)$unidadesConversion as $unidadConversion => $factorConversion) {
    $productoConversion = trim((string)$productoConversion);
    $unidadConversion = trim((string)$unidadConversion);
    $factorConversion = (float)$factorConversion;

    if ($productoConversion === '' || $unidadConversion === '' || $factorConversion <= 0) {
      continue;
    }

    $conversionCases[] = "WHEN TRIM(m.CVE_PROD) = " . $pdoMovs->quote($productoConversion)
      . " AND UPPER(TRIM(m.UNIUSU)) = " . $pdoMovs->quote(strtoupper($unidadConversion))
      . " THEN m.CANT_PROD * " . $factorConversion;
    $costQuantityConversionCases[] = "WHEN TRIM(m.CVE_PROD) = " . $pdoMovs->quote($productoConversion)
      . " AND UPPER(TRIM(m.UNIUSU)) = " . $pdoMovs->quote(strtoupper($unidadConversion))
      . " THEN m.CANT_PROD";
  }
}

$conversionCasesSql = $conversionCases !== [] ? implode("\n                ", $conversionCases) . "\n                " : '';
$costQuantityConversionCasesSql = $costQuantityConversionCases !== [] ? implode("\n                ", $costQuantityConversionCases) . "\n                " : '';
$cantidadQuimicoExpr = "
            CASE
                $conversionCasesSql
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END";
$cantidadCostoExpr = "
            CASE
                $costQuantityConversionCasesSql
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END";
$signoMovimientoExpr = "
            CASE
                WHEN UPPER(TRIM(m.TIPO_MOV)) = 'E' THEN -1
                WHEN UPPER(TRIM(m.TIPO_MOV)) = 'S' THEN 1
                ELSE 0
            END";
$cantidadNetaExpr = "(($cantidadQuimicoExpr) * ($signoMovimientoExpr))";
$cantidadCostoNetaExpr = "(($cantidadCostoExpr) * ($signoMovimientoExpr))";
$impactoEconomicoExpr = "(COALESCE(m.COSTO_ENT, 0) * $cantidadCostoNetaExpr)";
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
        SUM($cantidadNetaExpr) AS quimicos_kg,
        SUM($impactoEconomicoExpr) AS impacto_economico,
        CASE WHEN SUM($cantidadNetaExpr) <> 0
             THEN SUM($impactoEconomicoExpr) / SUM($cantidadNetaExpr)
             ELSE AVG(m.COSTO_ENT) END AS costo_promedio
    FROM movs m
    LEFT JOIN producto p
        ON TRIM(p.CVE_PROD) = TRIM(m.CVE_PROD)
    WHERE $campoFechaMovsSql >= ?
      AND UPPER(TRIM(m.TIPO_MOV)) IN ('E', 'S')
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsPivot = [$fechaDesde];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholdersPivot = createPlaceholders($productosQuimicos);
  $sqlPivot .= " AND TRIM(m.CVE_PROD) IN ($placeholdersPivot) ";
  $paramsPivot = array_merge($paramsPivot, $productosQuimicos);
}

if (!empty($cveMovReporte)) {
  $placeholdersMov = createPlaceholders($cveMovReporte);
  $sqlPivot .= " AND TRIM(m.CVE_MOV) IN ($placeholdersMov) ";
  $paramsPivot = array_merge($paramsPivot, $cveMovReporte);
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
$productosCompraCatalogo = [];

foreach ($rowsPivot as $row) {
  $cveProdCompra = trim((string)($row['cve_prod'] ?? ''));
  if ($cveProdCompra !== '' && !in_array($cveProdCompra, $productosCompraCatalogo, true)) {
    $productosCompraCatalogo[] = $cveProdCompra;
  }
}

/*
|--------------------------------------------------------------------------
| 2) TOTAL DE QUÍMICOS POR SEMANA (derivado de rowsPivot, sin query extra)
|--------------------------------------------------------------------------
*/
$quimicosPorPeriodo = [];
$impactoEconomicoPorPeriodo = [];
foreach ($rowsPivot as $row) {
  $periodo = (int)$row['periodo'];
  if (!isset($quimicosPorPeriodo[$periodo])) {
    $quimicosPorPeriodo[$periodo] = [
      'periodo'       => $periodo,
      'semana_iso'    => $row['semana_iso'],
      'semana_inicio' => $row['semana_inicio'],
      'semana_fin'    => $row['semana_fin'],
      'quimicos_kg'   => 0.0,
    ];
  }
  $quimicosPorPeriodo[$periodo]['quimicos_kg'] += (float)$row['quimicos_kg'];
  $impactoEconomicoPorPeriodo[$periodo] = ($impactoEconomicoPorPeriodo[$periodo] ?? 0.0)
    + (float)($row['impacto_economico'] ?? 0.0);
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
$semanasCatalogo = array_map(fn($week) => 'S' . str_pad((string)$week, 2, '0', STR_PAD_LEFT), range(1, 52));
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
  $impacto = (float)($row['impacto_economico'] ?? ($kg * $costo));

  $quimicoKey = $grupoPorProducto[$cveProd] ?? $cveProd;
  $quimicoLabel = $grupoTitulos[$quimicoKey] ?? ($descProd !== '' ? $descProd : $cveProd);

  $quimicosEtiquetas[$quimicoKey] = $quimicoLabel;

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
  $matrizImpactoAcumulado[$quimicoKey][$semanaLabel] += $impacto;
}

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
$matrizCostoProduccionQuimicos = [];

foreach ($quimicosCatalogo as $quimicoKey) {
  $matrizImpactoEconomicoQuimicos[$quimicoKey] = [];
  $matrizCostoProduccionQuimicos[$quimicoKey] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $kgQuimico = (float)($matrizQuimicos[$quimicoKey][$semanaLabel] ?? 0.0);
    $costoPromedio = (float)($matrizCostos[$quimicoKey][$semanaLabel] ?? 0.0);
    $produccionSemana = (float)($produccionPivotPorSemana[$semanaLabel] ?? 0.0);

    $impactoEconomico = $kgQuimico * $costoPromedio;
    $matrizImpactoEconomicoQuimicos[$quimicoKey][$semanaLabel] = $impactoEconomico;
    $matrizCostoProduccionQuimicos[$quimicoKey][$semanaLabel] = $produccionSemana > 0
      ? ($impactoEconomico / $produccionSemana)
      : null;
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

$totalImpactoQuimicosAnioAnterior = 0.0;
$totalImpactoQuimicosAnioActual = 0.0;

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
| 9a) CONSUMO, COSTO E IMPACTO POR QUÍMICO — AMBOS AÑOS (query unificada)
|--------------------------------------------------------------------------
*/
$consumoQuimicoAnioAnterior = [];
$consumoQuimicoAnioActual   = [];
$costoPromedioAnioAnterior  = [];
$costoPromedioAnioActual    = [];
$impactoEconomicoAnioAnterior = [];
$impactoEconomicoAnioActual   = [];

$sqlAnual = "
    SELECT
        CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) AS anio_iso,
        TRIM(m.CVE_PROD) AS cve_prod,
        SUM($cantidadNetaExpr) AS consumo_kg,
        SUM($impactoEconomicoExpr) AS impacto_economico,
        CASE WHEN SUM($cantidadNetaExpr) <> 0
             THEN SUM($impactoEconomicoExpr) / SUM($cantidadNetaExpr)
             ELSE 0 END AS costo_ponderado
    FROM movs m
    WHERE $campoFechaMovsSql >= ?
      AND CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED) IN (?, ?)
      AND UPPER(TRIM(m.TIPO_MOV)) IN ('E', 'S')
      AND m.LUGAR = 'QUIMICOS'
      AND m.CVE_PROD <> 'DIES01'
";

$paramsAnual = [$fechaDesde, $anioAnterior, $anioActual];

if (!$usarTodosLosProductos && !empty($productosQuimicos)) {
  $placeholders = createPlaceholders($productosQuimicos);
  $sqlAnual .= " AND TRIM(m.CVE_PROD) IN ($placeholders) ";
  $paramsAnual = array_merge($paramsAnual, $productosQuimicos);
}

if (!empty($cveMovReporte)) {
  $placeholdersMov = createPlaceholders($cveMovReporte);
  $sqlAnual .= " AND TRIM(m.CVE_MOV) IN ($placeholdersMov) ";
  $paramsAnual = array_merge($paramsAnual, $cveMovReporte);
}

$sqlAnual .= " GROUP BY CAST(DATE_FORMAT(" . $campoFechaMovsSql . ", '%x') AS UNSIGNED), TRIM(m.CVE_PROD) ";

$stmtAnual = $pdoMovs->prepare($sqlAnual);
$stmtAnual->execute($paramsAnual);

while ($row = $stmtAnual->fetch()) {
  $cve    = $row['cve_prod'];
  $anio   = (int)$row['anio_iso'];
  $consumo = (float)$row['consumo_kg'];
  $costo   = (float)$row['costo_ponderado'];
  $impacto = (float)($row['impacto_economico'] ?? ($consumo * $costo));

  if ($anio === $anioAnterior) {
    $consumoQuimicoAnioAnterior[$cve]   = $consumo;
    $costoPromedioAnioAnterior[$cve]    = $costo;
    $impactoEconomicoAnioAnterior[$cve] = $impacto;
  } else {
    $consumoQuimicoAnioActual[$cve]   = $consumo;
    $costoPromedioAnioActual[$cve]    = $costo;
    $impactoEconomicoAnioActual[$cve] = $impacto;
  }
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

$totalImpactoQuimicosAnioAnterior = array_sum($impactoEconomicoAnioAnterior);
$totalImpactoQuimicosAnioActual = array_sum($impactoEconomicoAnioActual);

$costoPromedioPorProduccionAnioAnterior = $totalProduccionAnioAnterior > 0
  ? ($totalImpactoQuimicosAnioAnterior / $totalProduccionAnioAnterior)
  : null;
$costoPromedioPorProduccionAnioActual = $totalProduccionAnioActual > 0
  ? ($totalImpactoQuimicosAnioActual / $totalProduccionAnioActual)
  : null;
$variacionCostoProduccion = ($costoPromedioPorProduccionAnioAnterior !== null
    && $costoPromedioPorProduccionAnioAnterior > 0
    && $costoPromedioPorProduccionAnioActual !== null)
  ? (($costoPromedioPorProduccionAnioActual - $costoPromedioPorProduccionAnioAnterior) / $costoPromedioPorProduccionAnioAnterior) * 100
  : null;

$datosCostoAnioAnterior = [];
$datosCostoAnioActual = [];
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

  $produccionPeriodo = (float)($produccionPorPeriodo[$periodo]['kilos_producidos'] ?? 0.0);
  $impactoPeriodo = (float)($impactoEconomicoPorPeriodo[$periodo] ?? 0.0);
  $costoProduccionPeriodo = $produccionPeriodo > 0 ? ($impactoPeriodo / $produccionPeriodo) : null;
  [$estadoCosto, $colorCosto, $colorHexCosto] = semaforo($costoProduccionPeriodo, $costoPromedioPorProduccionAnioAnterior, $toleranciaPct);

  $itemCosto = [
    'periodo' => $periodo,
    'semana_iso' => $semanaIso,
    'semana_label' => substr((string)$semanaIso, -3),
    'semana_inicio' => $semanaInicio,
    'semana_fin' => $semanaFin,
    'quimicos' => $impactoPeriodo,
    'produccion' => $produccionPeriodo,
    'ratio' => $costoProduccionPeriodo,
    'estado' => $estadoCosto,
    'color' => $colorCosto,
    'colorHex' => $colorHexCosto,
  ];

  $anioItem = (int)substr((string)$semanaIso, 0, 4);
  if ($anioItem === $anioAnterior) {
    $datosCostoAnioAnterior[] = $itemCosto;
  } elseif ($anioItem === $anioActual) {
    $datosCostoAnioActual[] = $itemCosto;
  }
}

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
| 9a.1) IMPORTE DE COMPRA POR QUÍMICO Y SEMANA (SAIPBI2)
|--------------------------------------------------------------------------
*/
$importeCompraAnioAnterior = [];
$importeCompraAnioActual = [];
$matrizCompraQuimicos = [];
$compraPorSemana = [];
$comprasPorPeriodo = [];

foreach ($quimicosCatalogo as $quimicoKey) {
  $matrizCompraQuimicos[$quimicoKey] = array_fill_keys($semanasCatalogo, 0.0);
}

foreach ($semanasCatalogo as $semanaLabel) {
  $compraPorSemana[$semanaLabel] = 0.0;
}

$productosCompraCatalogo = [];
foreach ($quimicosCatalogo as $quimicoKey) {
  if (isset($grupoEstructura[$quimicoKey])) {
    foreach (($grupoEstructura[$quimicoKey]['productos'] ?? []) as $productoGrupo) {
      $productoGrupo = trim((string)$productoGrupo);
      if ($productoGrupo !== '' && !in_array($productoGrupo, $productosCompraCatalogo, true)) {
        $productosCompraCatalogo[] = $productoGrupo;
      }
    }
    continue;
  }

  if (!in_array($quimicoKey, $productosCompraCatalogo, true)) {
    $productosCompraCatalogo[] = $quimicoKey;
  }
}

if (!empty($productosCompraCatalogo)) {
  $placeholdersCompra = createPlaceholders($productosCompraCatalogo);
  $importeCompraExpr = "
    COALESCE(d.SUBT_PROD, COALESCE(d.VALOR_PROD, 0) * COALESCE(d.CANT_PROD, 0))
    * CASE
        WHEN COALESCE(c.CVE_MON, 1) = 1 THEN 1
        ELSE COALESCE(NULLIF(c.TIP_CAM, 0), NULLIF(c.U_TIP_CAM, 0), 1)
      END";

  $sqlCompras = "
    SELECT
      YEARWEEK(c.FALTA_FAC, 3) AS periodo,
      DATE_FORMAT(c.FALTA_FAC, '%x-S%v') AS semana_iso,
      DATE_FORMAT(DATE_SUB(DATE(c.FALTA_FAC), INTERVAL WEEKDAY(c.FALTA_FAC) DAY), '%Y-%m-%d') AS semana_inicio,
      DATE_FORMAT(DATE_ADD(DATE(c.FALTA_FAC), INTERVAL (6 - WEEKDAY(c.FALTA_FAC)) DAY), '%Y-%m-%d') AS semana_fin,
      CAST(DATE_FORMAT(c.FALTA_FAC, '%x') AS UNSIGNED) AS anio_iso,
      TRIM(d.CVE_PROD) AS cve_prod,
      SUM($importeCompraExpr) AS importe_compra
    FROM comprafd d
    INNER JOIN comprafc c
      ON c.CLAVE_FACTURA = d.CLAVE_FACTURA
    WHERE c.FALTA_FAC >= ?
      AND TRIM(d.CVE_PROD) IN ($placeholdersCompra)
      AND UPPER(TRIM(COALESCE(c.STATUS_FAC, ''))) <> 'CANCELADA'
    GROUP BY
      YEARWEEK(c.FALTA_FAC, 3),
      DATE_FORMAT(c.FALTA_FAC, '%x-S%v'),
      DATE_FORMAT(DATE_SUB(DATE(c.FALTA_FAC), INTERVAL WEEKDAY(c.FALTA_FAC) DAY), '%Y-%m-%d'),
      DATE_FORMAT(DATE_ADD(DATE(c.FALTA_FAC), INTERVAL (6 - WEEKDAY(c.FALTA_FAC)) DAY), '%Y-%m-%d'),
      CAST(DATE_FORMAT(c.FALTA_FAC, '%x') AS UNSIGNED),
      TRIM(d.CVE_PROD)
    ORDER BY semana_iso, cve_prod
  ";

  $stmtCompras = $pdoCompras->prepare($sqlCompras);
  $stmtCompras->execute(array_merge([$fechaDesde], $productosCompraCatalogo));

  while ($row = $stmtCompras->fetch()) {
    $cveProd = trim((string)$row['cve_prod']);
    $quimicoKey = $grupoPorProducto[$cveProd] ?? $cveProd;

    if (!in_array($quimicoKey, $quimicosCatalogo, true)) {
      continue;
    }

    $anioIso = (int)$row['anio_iso'];
    $periodo = (int)$row['periodo'];
    $semanaIso = (string)$row['semana_iso'];
    $semanaLabel = substr($semanaIso, -3);
    $importeCompra = (float)$row['importe_compra'];

    if ($anioIso === $anioAnterior) {
      $importeCompraAnioAnterior[$quimicoKey] = ($importeCompraAnioAnterior[$quimicoKey] ?? 0.0) + $importeCompra;
    } elseif ($anioIso === $anioActual) {
      $importeCompraAnioActual[$quimicoKey] = ($importeCompraAnioActual[$quimicoKey] ?? 0.0) + $importeCompra;
    }

    if ($anioIso === $anioPivot && isset($matrizCompraQuimicos[$quimicoKey][$semanaLabel])) {
      $matrizCompraQuimicos[$quimicoKey][$semanaLabel] += $importeCompra;
      $compraPorSemana[$semanaLabel] = ($compraPorSemana[$semanaLabel] ?? 0.0) + $importeCompra;
    }

    if (!isset($comprasPorPeriodo[$periodo])) {
      $comprasPorPeriodo[$periodo] = [
        'periodo' => $periodo,
        'semana_iso' => $semanaIso,
        'semana_label' => $semanaLabel,
        'semana_inicio' => $row['semana_inicio'],
        'semana_fin' => $row['semana_fin'],
        'importe_compra' => 0.0,
      ];
    }

    $comprasPorPeriodo[$periodo]['importe_compra'] += $importeCompra;
  }
}

$totalCompraAnioAnterior = array_sum($importeCompraAnioAnterior);
$totalCompraAnioActual = array_sum($importeCompraAnioActual);
$variacionCompra = $totalCompraAnioAnterior > 0
  ? (($totalCompraAnioActual - $totalCompraAnioAnterior) / $totalCompraAnioAnterior) * 100
  : null;

$variacionCompraQuimico = [];
$totalesCompraQuimico = [];

foreach ($quimicosCatalogo as $quimico) {
  $compraAnterior = (float)($importeCompraAnioAnterior[$quimico] ?? 0.0);
  $compraActual = (float)($importeCompraAnioActual[$quimico] ?? 0.0);

  $totalesCompraQuimico[$quimico] = $compraActual;
  $variacionCompraQuimico[$quimico] = $compraAnterior > 0
    ? (($compraActual - $compraAnterior) / $compraAnterior) * 100
    : 0.0;
}

$datosCompraAnioAnterior = [];
$datosCompraAnioActual = [];

foreach ($comprasPorPeriodo as $row) {
  $itemCompra = [
    'periodo' => $row['periodo'],
    'semana_iso' => $row['semana_iso'],
    'semana_label' => $row['semana_label'],
    'semana_inicio' => $row['semana_inicio'],
    'semana_fin' => $row['semana_fin'],
    'quimicos' => $row['importe_compra'],
    'produccion' => 0.0,
    'ratio' => $row['importe_compra'],
    'colorHex' => '#64748b',
  ];

  $anioItem = (int)substr((string)$row['semana_iso'], 0, 4);
  if ($anioItem === $anioAnterior) {
    $datosCompraAnioAnterior[] = $itemCompra;
  } elseif ($anioItem === $anioActual) {
    $datosCompraAnioActual[] = $itemCompra;
  }
}

$semanasCompraBase = count(array_filter($datosCompraAnioAnterior, fn($item) => (float)($item['ratio'] ?? 0.0) > 0));
$promedioCompraSemanalAnioAnterior = $semanasCompraBase > 0
  ? ($totalCompraAnioAnterior / $semanasCompraBase)
  : null;

$compraBasePorSemana = [];
foreach ($datosCompraAnioAnterior as $itemCompraBase) {
  $compraBasePorSemana[$itemCompraBase['semana_label']] = (float)($itemCompraBase['ratio'] ?? 0.0);
}

foreach ($datosCompraAnioActual as &$itemCompraActual) {
  $semanaLabelCompra = (string)($itemCompraActual['semana_label'] ?? '');
  $importeCompraActual = (float)($itemCompraActual['ratio'] ?? 0.0);
  $importeCompraBase = $compraBasePorSemana[$semanaLabelCompra] ?? null;
  [, , $colorCompraHex] = semaforo($importeCompraActual > 0 ? $importeCompraActual : null, $importeCompraBase, $toleranciaPct);
  $itemCompraActual['colorHex'] = $colorCompraHex;
}
unset($itemCompraActual);

$costoPorProduccionAnioAnterior = [];
$costoPorProduccionAnioActual = [];
$variacionCostoPorProduccionQuimico = [];

foreach ($quimicosCatalogo as $quimico) {
  $costoPorProduccionAnterior = $totalProduccionAnioAnterior > 0
    ? (float)($impactoEconomicoAnioAnterior[$quimico] ?? 0.0) / $totalProduccionAnioAnterior
    : 0.0;
  $costoPorProduccionActual = $totalProduccionAnioActual > 0
    ? (float)($impactoEconomicoAnioActual[$quimico] ?? 0.0) / $totalProduccionAnioActual
    : 0.0;

  $costoPorProduccionAnioAnterior[$quimico] = $costoPorProduccionAnterior;
  $costoPorProduccionAnioActual[$quimico] = $costoPorProduccionActual;
  $variacionCostoPorProduccionQuimico[$quimico] = $costoPorProduccionAnterior > 0
    ? (($costoPorProduccionActual - $costoPorProduccionAnterior) / $costoPorProduccionAnterior) * 100
    : 0.0;
}

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
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase, $semanasCatalogo);
$chartDataCosto = buildChartData(
  $datosCostoAnioActual,
  $datosCostoAnioAnterior,
  $anioAnterior,
  $anioActual,
  $costoPromedioPorProduccionAnioAnterior,
  $semanasCatalogo
);
$chartDataCompra = buildChartData(
  $datosCompraAnioActual,
  $datosCompraAnioAnterior,
  $anioAnterior,
  $anioActual,
  $promedioCompraSemanalAnioAnterior,
  $semanasCatalogo
);

/*
|--------------------------------------------------------------------------
| 12) RESPUESTA FINAL
|--------------------------------------------------------------------------
*/
$result = [
  'titulo' => $config['titulo'] ?? 'Químicos en General / Producción',

  'anioAnterior' => $anioAnterior,
  'anioActual' => $anioActual,
  'anioPivot' => $anioPivot,

  'ratioBase' => $ratioBase,
  'ratioGlobal' => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,
  'costoPromedioPorProduccionAnioAnterior' => $costoPromedioPorProduccionAnioAnterior,
  'costoPromedioPorProduccionAnioActual' => $costoPromedioPorProduccionAnioActual,
  'variacionCostoProduccion' => $variacionCostoProduccion,
  'totalCompraAnioAnterior' => $totalCompraAnioAnterior,
  'totalCompraAnioActual' => $totalCompraAnioActual,
  'promedioCompraSemanalAnioAnterior' => $promedioCompraSemanalAnioAnterior,
  'variacionCompra' => $variacionCompra,

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
  'datosCostoAnioAnterior' => $datosCostoAnioAnterior,
  'datosCostoAnioActual' => $datosCostoAnioActual,
  'datosCompraAnioAnterior' => $datosCompraAnioAnterior,
  'datosCompraAnioActual' => $datosCompraAnioActual,

  'chartData' => $chartData,
  'chartDataCosto' => $chartDataCosto,
  'chartDataCompra' => $chartDataCompra,

  // Datos de vista pivote
  'semanasCatalogo' => $semanasCatalogo,
  'quimicosCatalogo' => $quimicosCatalogo,
  'quimicosEtiquetas' => $quimicosEtiquetas,
  'matrizQuimicos' => $matrizQuimicos,
  'matrizCostos' => $matrizCostos,
  'produccionPivotPorSemana' => $produccionPivotPorSemana,
  'matrizRatioQuimicos' => $matrizRatioQuimicos,
  'matrizImpactoEconomicoQuimicos' => $matrizImpactoEconomicoQuimicos,
  'matrizCostoProduccionQuimicos' => $matrizCostoProduccionQuimicos,
  'matrizCompraQuimicos' => $matrizCompraQuimicos,
  'ratioBasePorQuimico' => $ratioBasePorQuimico,
  'totalesPorSemana' => $totalesPorSemana,
  'produccionPorSemana' => $produccionPorSemana,
  'ratioPorSemana' => $ratioPorSemana,
  'compraPorSemana' => $compraPorSemana,
  'totalesConsumoQuimico' => $totalesConsumoQuimico,
  'totalesCostoQuimico' => $totalesCostoQuimico,
  'totalesCompraQuimico' => $totalesCompraQuimico,
  'consumoQuimicoAnioAnterior' => $consumoQuimicoAnioAnterior,
  'consumoQuimicoAnioActual' => $consumoQuimicoAnioActual,
  'variacionConsumoQuimico' => $variacionConsumoQuimico,
  'importeCompraAnioAnterior' => $importeCompraAnioAnterior,
  'importeCompraAnioActual' => $importeCompraAnioActual,
  'variacionCompraQuimico' => $variacionCompraQuimico,
  'costoPromedioAnioAnterior' => $costoPromedioAnioAnterior,
  'costoPromedioAnioActual' => $costoPromedioAnioActual,
  'variacionCostoQuimico' => $variacionCostoQuimico,
  'costoPorProduccionAnioAnterior' => $costoPorProduccionAnioAnterior,
  'costoPorProduccionAnioActual' => $costoPorProduccionAnioActual,
  'variacionCostoPorProduccionQuimico' => $variacionCostoPorProduccionQuimico,
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
    'cveMovConsumo' => $cveMovConsumo,
    'cveMovAjuste' => $cveMovAjuste,
    'cveMovReporte' => $cveMovReporte,
    'grupo_estructura' => $grupoEstructura,
  ],
];

setCache($cacheKey, $result, 3600);

return $result;
