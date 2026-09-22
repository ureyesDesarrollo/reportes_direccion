<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';
require_once __DIR__ . '/../../shared/ReportEngine.php';
require_once __DIR__ . '/../../shared/ChemicalMovementsApi.php';

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

$cacheKey = 'report_' . md5(serialize([
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

$sourceWarnings = [];
$pdoProd = null;
try {
  $pdoProd = conectar($dbConfig['prod']);
} catch (Throwable $exception) {
  $sourceWarnings[] = 'La producción no está disponible; el consumo y costo de empaques continúan visibles.';
}
$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$apiConfig = (array)($config['movimientos_api'] ?? []);
$apiConfig['productos_a_ignorar'] = $productosAIgnorar;
$apiResult = loadChemicalMovementsApi($apiConfig, [$anioAnterior, $anioActual], [], $timezone);
$movimientosEmpaques = array_values(array_filter((array)($apiResult['movements'] ?? []), static function ($movement) use ($fechaDesde): bool {
  return is_array($movement) && (string)($movement['semana_fin'] ?? '') >= $fechaDesde;
}));
$sourceWarnings = array_merge($sourceWarnings, (array)($apiResult['warnings'] ?? []));

/*
|--------------------------------------------------------------------------
| 1) DETALLE POR EMPAQUE Y SEMANA
|--------------------------------------------------------------------------
| Se usa para:
| - matriz de cantidad por empaque/semana/unidad
| - base histórica por empaque y unidad
|--------------------------------------------------------------------------
*/
$rowsPivot = aggregateMovementsApiWeekly(
  $movimientosEmpaques,
  !$usarTodosLosProductos ? (array)$productosEmpaques : [],
  true
);

/*
|--------------------------------------------------------------------------
| 2) TOTAL DE EMPAQUES POR SEMANA (kg normalizados + cantidad bruta, query unificada)
|--------------------------------------------------------------------------
*/
$empaquesPorPeriodo = [];
$empaquessPorPeriodo = [];
foreach ($movimientosEmpaques as $movement) {
  $periodo = (int)$movement['periodo'];
  if (!isset($empaquesPorPeriodo[$periodo])) {
    $empaquesPorPeriodo[$periodo] = [
      'periodo' => $periodo,
      'semana_iso' => $movement['semana_iso'],
      'semana_inicio' => $movement['semana_inicio'],
      'semana_fin' => $movement['semana_fin'],
      'empaques_kg' => 0.0,
    ];
    $empaquessPorPeriodo[$periodo] = [
      'periodo' => $periodo,
      'semana_iso' => $movement['semana_iso'],
      'semana_inicio' => $movement['semana_inicio'],
      'semana_fin' => $movement['semana_fin'],
      'empaques_cantidad' => 0.0,
    ];
  }
  $empaquesPorPeriodo[$periodo]['empaques_kg'] += (float)($movement['consumo_kg'] ?? 0.0);
  $empaquessPorPeriodo[$periodo]['empaques_cantidad'] += (float)($movement['cantidad_original'] ?? 0.0);
}

/*
|--------------------------------------------------------------------------
| 3) PRODUCCIÓN POR SEMANA
|--------------------------------------------------------------------------
*/
$produccionPorPeriodo = [];
if ($pdoProd instanceof PDO) {
  try {
    $produccionPorPeriodo = ReportEngine::fetchProductionSeries($pdoProd, $fechaDesde);
  } catch (Throwable $exception) {
    $sourceWarnings[] = 'La producción no está disponible; el consumo y costo de empaques continúan visibles.';
  }
}
$sourceWarning = implode(' ', array_values(array_unique($sourceWarnings)));

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
$matrizCostos = [];

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
  $cantidad = (float)$row['cantidad'];
  $costo = (float)($row['costo_promedio'] ?? 0.0);

  // Saltar si la unidad está vacía
  if (empty($unidad)) {
    continue;
  }

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
    $matrizCostos[$empaquesKey] = [];
  }

  $matrizEmpaques[$empaquesKey][$semanaLabel] = $cantidad;
  $matrizCostos[$empaquesKey][$semanaLabel] = $costo;
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
    $matrizCostos[$empaquesKey] = [];
  }

  if (!isset($matrizCostos[$empaquesKey])) {
    $matrizCostos[$empaquesKey] = [];
  }

  foreach ($semanasCatalogo as $semanaLabel) {
    if (!isset($matrizEmpaques[$empaquesKey][$semanaLabel])) {
      $matrizEmpaques[$empaquesKey][$semanaLabel] = 0.0;
    }
    if (!isset($matrizCostos[$empaquesKey][$semanaLabel])) {
      $matrizCostos[$empaquesKey][$semanaLabel] = 0.0;
    }
  }

  ksort($matrizEmpaques[$empaquesKey]);
  ksort($matrizCostos[$empaquesKey]);
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
| 7b) MATRIZ DE IMPACTO ECONÓMICO (CANTIDAD × COSTO)
|--------------------------------------------------------------------------
*/
$matrizImpactoEconomicoEmpaques = [];

foreach ($empaquessCatalogo as $empaqueKey) {
  $matrizImpactoEconomicoEmpaques[$empaqueKey] = [];

  foreach ($semanasCatalogo as $semanaLabel) {
    $cantidadEmpaque = (float)($matrizEmpaques[$empaqueKey][$semanaLabel] ?? 0.0);
    $costoPromedio = (float)($matrizCostos[$empaqueKey][$semanaLabel] ?? 0.0);

    $impactoEconomico = $cantidadEmpaque * $costoPromedio;
    $matrizImpactoEconomicoEmpaques[$empaqueKey][$semanaLabel] = $impactoEconomico;
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
| 9a) CANTIDAD Y COSTO POR EMPAQUE — AMBOS AÑOS (query unificada)
|--------------------------------------------------------------------------
*/
$cantidadEmpaqueAnioAnterior      = [];
$cantidadEmpaqueAnioActual        = [];
$costoPromedioEmpaqueAnioAnterior = [];
$costoPromedioEmpaqueAnioActual   = [];
$impactoEconomicoEmpaqueAnioAnterior = [];
$impactoEconomicoEmpaqueAnioActual   = [];

$annualPackages = [];
foreach ($rowsPivot as $row) {
  $anio = (int)substr((string)($row['semana_iso'] ?? ''), 0, 4);
  if (!in_array($anio, [$anioAnterior, $anioActual], true)) continue;
  $clave = trim((string)$row['cve_prod']) . '|' . trim((string)$row['unidad_normalizada']);
  if (!isset($annualPackages[$anio][$clave])) {
    $annualPackages[$anio][$clave] = ['cantidad' => 0.0, 'impacto' => 0.0];
  }
  $annualPackages[$anio][$clave]['cantidad'] += (float)$row['cantidad'];
  $annualPackages[$anio][$clave]['impacto'] += (float)($row['impacto_economico'] ?? 0.0);
}

foreach ($annualPackages as $anio => $packages) {
  foreach ($packages as $clave => $annualRow) {
    $cantidad = (float)$annualRow['cantidad'];
    $impacto = (float)$annualRow['impacto'];
    $costo = $cantidad != 0.0 ? $impacto / $cantidad : 0.0;

    if ((int)$anio === $anioAnterior) {
      $cantidadEmpaqueAnioAnterior[$clave] = $cantidad;
      $costoPromedioEmpaqueAnioAnterior[$clave] = $costo;
      $impactoEconomicoEmpaqueAnioAnterior[$clave] = $impacto;
    } else {
      $cantidadEmpaqueAnioActual[$clave] = $cantidad;
      $costoPromedioEmpaqueAnioActual[$clave] = $costo;
      $impactoEconomicoEmpaqueAnioActual[$clave] = $impacto;
    }
  }
}

/*
|--------------------------------------------------------------------------
| 9b) TOTALES DE CANTIDAD Y COSTO POR EMPAQUE/UNIDAD (VARIACIÓN ACTUAL VS ANTERIOR)
|--------------------------------------------------------------------------
*/
$totalesCantidadEmpaque = [];
$totalesCostoEmpaque = [];
$variacionCantidadEmpaque = []; // Para semáforo
$variacionCostoEmpaque = []; // Para semáforo en modo costo

foreach ($empaquessCatalogo as $empaque) {
  // Cantidad
  $cantidadActual = (float)($cantidadEmpaqueAnioActual[$empaque] ?? 0.0);
  $cantidadAnterior = (float)($cantidadEmpaqueAnioAnterior[$empaque] ?? 0.0);
  $variacionCantidad = $cantidadAnterior > 0 ? (($cantidadActual - $cantidadAnterior) / $cantidadAnterior) * 100 : 0;

  // Costo (precio promedio unitario)
  $costoPromActual = (float)($costoPromedioEmpaqueAnioActual[$empaque] ?? 0.0);
  $costoPromAnterior = (float)($costoPromedioEmpaqueAnioAnterior[$empaque] ?? 0.0);
  $variacionCosto = $costoPromAnterior > 0 ? (($costoPromActual - $costoPromAnterior) / $costoPromAnterior) * 100 : 0;

  // Para ordenamiento, usar impacto económico total
  $costoActual = (float)($impactoEconomicoEmpaqueAnioActual[$empaque] ?? 0.0);

  $totalesCantidadEmpaque[$empaque] = $cantidadActual;
  $totalesCostoEmpaque[$empaque] = $costoActual;
  $variacionCantidadEmpaque[$empaque] = $variacionCantidad;
  $variacionCostoEmpaque[$empaque] = $variacionCosto;
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
$result = [
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
  'matrizCostos' => $matrizCostos,
  'produccionPivotPorSemana' => $produccionPivotPorSemana,
  'matrizRatioEmpaques' => $matrizRatioEmpaques,
  'matrizImpactoEconomicoEmpaques' => $matrizImpactoEconomicoEmpaques,
  'ratioBasePorEmpaque' => $ratioBasePorEmpaque,
  'totalesPorSemana' => $totalesPorSemana,
  'produccionPorSemana' => $produccionPorSemana,
  'ratioPorSemana' => $ratioPorSemana,
  'totalesCantidadEmpaque' => $totalesCantidadEmpaque,
  'totalesCostoEmpaque' => $totalesCostoEmpaque,
  'cantidadEmpaqueAnioAnterior' => $cantidadEmpaqueAnioAnterior,
  'cantidadEmpaqueAnioActual' => $cantidadEmpaqueAnioActual,
  'variacionCantidadEmpaque' => $variacionCantidadEmpaque,
  'costoPromedioEmpaqueAnioAnterior' => $costoPromedioEmpaqueAnioAnterior,
  'costoPromedioEmpaqueAnioActual' => $costoPromedioEmpaqueAnioActual,
  'variacionCostoEmpaque' => $variacionCostoEmpaque,
  'impactoEconomicoEmpaqueAnioAnterior' => $impactoEconomicoEmpaqueAnioAnterior,
  'impactoEconomicoEmpaqueAnioActual' => $impactoEconomicoEmpaqueAnioActual,

  'maxRatio' => $maxRatio,
  'version' => $version,
  'sourceWarning' => $sourceWarning,

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
    'sourceWarning' => $sourceWarning,
    'fuenteMovimientos' => 'API movimientos-salida',
  ],
];

setCache($cacheKey, $result, 3600);
return $result;
