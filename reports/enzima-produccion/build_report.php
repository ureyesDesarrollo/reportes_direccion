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
| - require ../../shared/ReportEngine.php
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

// Cache key basada en configuración relevante
$cacheKey = 'report_' . md5(serialize([
  $config,
  $appConfig['cards_por_pagina'] ?? 9,
  $appConfig['filas_por_pagina'] ?? 15,
  filemtime(__FILE__),
  date('Y-m-d'), // Cache diario
]));

// Intentar obtener del cache
$cached = getCache($cacheKey);
if ($cached !== null) {
  return $cached;
}

$fechaDesde = $config['fecha_desde'];
$campoFechaMovs = $config['campo_fecha_movs'];
$productosGrupo = $config['productos'] ?? [];
$toleranciaPct = (float)($config['tolerancia_pct'] ?? 10);
$cveMov = $config['cve_mov'] ?? null;
$grupoActual = $config['grupo_actual'] ?? 'grupo';
$productoSeleccionado = $config['producto_seleccionado'] ?? null;
$modo = $config['modo'] ?? 'consumo';
$campoCosto = $config['campo_costo'] ?? 'COST_ENT';

$cardsPorPagina = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs, $campoCosto);

if (empty($productosGrupo)) {
  throw new RuntimeException('No hay productos definidos para el grupo.');
}

$sourceWarnings = [];
$pdoProd = null;
try {
  $pdoProd = conectar($dbConfig['prod']);
} catch (Throwable $exception) {
  $sourceWarnings[] = 'La producción no está disponible; los ratios se muestran sin ese dato.';
}

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$apiResult = loadChemicalMovementsApi(
  (array)($config['movimientos_api'] ?? []),
  [$anioAnterior, $anioActual],
  (array)($config['conversiones_unidad_producto'] ?? []),
  $timezone
);
$chemicalMovements = array_values(array_filter(
  (array)($apiResult['movements'] ?? []),
  static function ($movement) use ($fechaDesde): bool {
    return is_array($movement) && (string)($movement['semana_fin'] ?? '') >= $fechaDesde;
  }
));
$sourceWarnings = array_merge($sourceWarnings, (array)($apiResult['warnings'] ?? []));
$sourceWarning = implode(' ', array_values(array_unique($sourceWarnings)));
$rowsProductosApi = aggregateChemicalMovementsWeekly($chemicalMovements, (array)$productosGrupo);

/*
|--------------------------------------------------------------------------
| DEFINICIÓN DE MODO
|--------------------------------------------------------------------------
*/
$metricaNombre = 'consumo';
$badgeRatio = 'kg grupo / kg producidos';
$metricaTitulo = 'Consumo Grupo';
$metricaUnidad = 'kg';

if ($modo === 'costo') {
  $metricaNombre = 'costo';
  $badgeRatio = 'Promedio del costo del grupo';
  $metricaTitulo = 'Costo Promedio Grupo';
  $metricaUnidad = '$';
} elseif ($modo === 'impacto') {
  $metricaNombre = 'impacto';
  $badgeRatio = 'Impacto $ por kg producido';
  $metricaTitulo = 'Impacto Total Grupo';
  $metricaUnidad = '$';
}

/*
|--------------------------------------------------------------------------
| Semáforo centralizado en shared/ReportHelpers.php
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| 1) DETALLE SEMANAL DEL GRUPO
|--------------------------------------------------------------------------
*/
$detallePorPeriodo = [];
$detalleProductosPorPeriodo = [];
foreach ($rowsProductosApi as $row) {
  $periodo = (int)($row['periodo'] ?? 0);
  $cveProd = trim((string)($row['cve_prod'] ?? ''));
  if ($periodo <= 0 || $cveProd === '') continue;

  if (!isset($detallePorPeriodo[$periodo])) {
    $detallePorPeriodo[$periodo] = [
      'periodo' => $periodo,
      'semana_iso' => (string)($row['semana_iso'] ?? ''),
      'semana_inicio' => (string)($row['semana_inicio'] ?? ''),
      'semana_fin' => (string)($row['semana_fin'] ?? ''),
      'consumo_kg' => 0.0,
      'impacto_economico' => 0.0,
      'costo_promedio' => 0.0,
    ];
  }

  $consumo = (float)($row['consumo_kg'] ?? 0.0);
  $impacto = (float)($row['impacto_economico'] ?? 0.0);
  $detallePorPeriodo[$periodo]['consumo_kg'] += $consumo;
  $detallePorPeriodo[$periodo]['impacto_economico'] += $impacto;
  $detalleProductosPorPeriodo[$periodo][$cveProd] = [
    'consumo_kg' => $consumo,
    'costo_promedio' => is_numeric($row['costo_promedio'] ?? null) ? (float)$row['costo_promedio'] : null,
  ];
}

foreach ($detallePorPeriodo as &$row) {
  $consumo = (float)($row['consumo_kg'] ?? 0.0);
  $row['costo_promedio'] = $consumo > 0
    ? (float)($row['impacto_economico'] ?? 0.0) / $consumo
    : 0.0;
}
unset($row);

/*
|--------------------------------------------------------------------------
| 2) PRODUCCIÓN POR SEMANA
|--------------------------------------------------------------------------
*/
$produccionPorPeriodo = $pdoProd instanceof PDO
  ? ReportEngine::fetchProductionSeries($pdoProd, $fechaDesde)
  : [];

/*
|--------------------------------------------------------------------------
| 3) BASE DE COSTO DEL GRUPO PARA costo/impacto
|--------------------------------------------------------------------------
*/
$costoBaseGrupo = null;
$costoPromedioActualGrupo = null;

if ($modo === 'costo' || $modo === 'impacto') {
  $costosPorAnio = [
    $anioAnterior => ['consumo' => 0.0, 'impacto' => 0.0],
    $anioActual => ['consumo' => 0.0, 'impacto' => 0.0],
  ];
  foreach ($rowsProductosApi as $row) {
    $anioIso = (int)substr((string)($row['semana_iso'] ?? ''), 0, 4);
    if (!isset($costosPorAnio[$anioIso])) continue;
    $costosPorAnio[$anioIso]['consumo'] += (float)($row['consumo_kg'] ?? 0.0);
    $costosPorAnio[$anioIso]['impacto'] += (float)($row['impacto_economico'] ?? 0.0);
  }

  $costoBaseGrupo = $costosPorAnio[$anioAnterior]['consumo'] > 0
    ? $costosPorAnio[$anioAnterior]['impacto'] / $costosPorAnio[$anioAnterior]['consumo']
    : null;
  $costoPromedioActualGrupo = $costosPorAnio[$anioActual]['consumo'] > 0
    ? $costosPorAnio[$anioActual]['impacto'] / $costosPorAnio[$anioActual]['consumo']
    : null;

  // Si no hay costo base histórico, usar el promedio actual como referencia
  if ($costoBaseGrupo === null && $costoPromedioActualGrupo !== null) {
    $costoBaseGrupo = $costoPromedioActualGrupo;
  }
}

/*
|--------------------------------------------------------------------------
| 4) DETALLE POR PRODUCTO DENTRO DEL GRUPO
|--------------------------------------------------------------------------
| Útil para resaltar producto seleccionado o ver desglose futuro
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| 5) ARMADO DEL REPORTE
|--------------------------------------------------------------------------
*/
$periodoData = ReportEngine::assemblePeriods(
  $detallePorPeriodo,
  $produccionPorPeriodo,
  static function (array $row, array $produccion, int $periodo) use (
    $modo,
    $costoBaseGrupo,
    $detalleProductosPorPeriodo
  ): array {
    $produccionKg = isset($produccion['kilos_producidos']) ? (float)$produccion['kilos_producidos'] : 0.0;
    $metrica = 0.0;
    $ratio = null;
    $consumoKg = null;
    $costoPromedioSemana = null;
    $diferenciaPrecio = null;
    $impactoTotal = null;

    if ($modo === 'consumo') {
      $metrica = (float)($row['consumo_kg'] ?? 0.0);
      $ratio = $produccionKg > 0 ? ($metrica / $produccionKg) : null;
    } elseif ($modo === 'costo') {
      $metrica = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
      $ratio = $metrica > 0 ? $metrica : null;
      $costoPromedioSemana = $metrica;
    } else {
      $consumoKg = isset($row['consumo_kg']) ? (float)$row['consumo_kg'] : 0.0;
      $costoPromedioSemana = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0;
      $diferenciaPrecio = ($costoBaseGrupo !== null) ? ($costoPromedioSemana - $costoBaseGrupo) : null;
      $impactoTotal = $diferenciaPrecio !== null ? ($diferenciaPrecio * $consumoKg) : null;

      $metrica = $impactoTotal ?? 0.0;
      $ratio = ($impactoTotal !== null && $produccionKg > 0)
        ? ($impactoTotal / $produccionKg)
        : null;
    }

    return [
      'semana_iso' => $row['semana_iso'] ?? null,
      'semana_inicio' => $row['semana_inicio'] ?? null,
      'semana_fin' => $row['semana_fin'] ?? null,
      'metrica' => $metrica,
      'quimicos' => $metrica,
      'produccion' => $produccionKg,
      'ratio' => $ratio,
      'consumo_kg' => $consumoKg,
      'costo_promedio_semana' => $costoPromedioSemana,
      'costo_base' => $costoBaseGrupo,
      'diferencia_precio' => $diferenciaPrecio,
      'impacto_total' => $impactoTotal,
      'detalle_productos' => $detalleProductosPorPeriodo[$periodo] ?? [],
    ];
  },
  $anioAnterior,
  $anioActual
);

$itemsTemporales = $periodoData['items'];
$datosAnioAnterior = $periodoData['datosAnioAnterior'];
$datosAnioActual = $periodoData['datosAnioActual'];

$ratioBase = null;
$ratioPromedioAnioActual = null;
$totalMetricaAnioAnterior = 0.0;
$totalMetricaAnioActual = 0.0;
$totalProduccionAnioAnterior = 0.0;
$totalProduccionAnioActual = 0.0;

if ($modo === 'consumo') {
  $totalMetricaAnioAnterior = array_sum(array_column($datosAnioAnterior, 'metrica'));
  $totalMetricaAnioActual = array_sum(array_column($datosAnioActual, 'metrica'));
  $totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));
  $totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));
  $ratioBase = $totalProduccionAnioAnterior > 0
    ? ($totalMetricaAnioAnterior / $totalProduccionAnioAnterior)
    : null;
  $ratioPromedioAnioActual = $totalProduccionAnioActual > 0
    ? ($totalMetricaAnioActual / $totalProduccionAnioActual)
    : null;
} elseif ($modo === 'costo') {
  $totalMetricaAnioAnterior = $costoBaseGrupo;
  $totalMetricaAnioActual = $costoPromedioActualGrupo;
  $totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));
  $totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));
  $ratioBase = $costoBaseGrupo;
  $ratioPromedioAnioActual = $costoPromedioActualGrupo;
} else {
  $totalMetricaAnioAnterior = array_sum(array_map(
    fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioAnterior
  ));
  $totalMetricaAnioActual = array_sum(array_map(
    fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioActual
  ));
  $totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));
  $totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));
  $ratioBase = $totalProduccionAnioAnterior > 0
    ? ($totalMetricaAnioAnterior / $totalProduccionAnioAnterior)
    : null;
  $ratioPromedioAnioActual = $totalProduccionAnioActual > 0
    ? ($totalMetricaAnioActual / $totalProduccionAnioActual)
    : null;
}

$limiteVerde = null;
$limiteAmarillo = null;

if ($modo === 'impacto') {
  $limiteVerde = 0;
  $limiteAmarillo = $ratioBase !== null ? $ratioBase * 1.06 : null;
} else {
  $limiteVerde = $ratioBase;
  $limiteAmarillo = $ratioBase !== null
    ? $ratioBase * (1 + $toleranciaPct / 100)
    : null;
}

$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, $modo);
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = ReportEngine::sortByPeriodDesc($yearSplit['anterior']);
$datosAnioActual = ReportEngine::sortByPeriodDesc($yearSplit['actual']);

$maxRatio = ReportEngine::maxRatio($reporte);

$ratioGlobal = $ratioPromedioAnioActual;
[$estadoGlobal, $colorGlobal, $colorGlobalHex] = resolveTrafficLight($ratioGlobal, $ratioBase, $toleranciaPct, $modo);

$variacionMetrica = ($ratioBase !== null && abs($ratioBase) > 0.0000001 && $ratioPromedioAnioActual !== null)
  ? (($ratioPromedioAnioActual - $ratioBase) / abs($ratioBase)) * 100
  : null;

$variacionProduccion = $totalProduccionAnioAnterior > 0
  ? (($totalProduccionAnioActual - $totalProduccionAnioAnterior) / $totalProduccionAnioAnterior) * 100
  : null;

$variacionRatio = $variacionMetrica;
$version = time();

/*
|--------------------------------------------------------------------------
| 7B) DATOS DESGLOSADOS POR GRUPO (Para tabla pivote)
|--------------------------------------------------------------------------
*/
$consumoEnzimaAnioAnterior = [];
$consumoEnzimaAnioActual = [];
$variacionConsumoEnzima = [];
$costoPromedioAnioAnterior = [];
$costoPromedioAnioActual = [];
$variacionCostoEnzima = [];
$impactoEconomicoAnioAnterior = [];
$impactoEconomicoAnioActual = [];

// Extraer estructura de grupos del config
$grupoEstructura = $config['grupo_estructura'] ?? [];

// Totales por grupo calculados exclusivamente con movimientos del API.
foreach ($grupoEstructura as $grupoKey => $grupoInfo) {
  $grupoTitulo = (string)($grupoInfo['titulo'] ?? $grupoKey);
  $productosEnGrupo = array_fill_keys(array_map('strval', (array)($grupoInfo['productos'] ?? [])), true);
  $totales = [
    $anioAnterior => ['consumo' => 0.0, 'impacto' => 0.0],
    $anioActual => ['consumo' => 0.0, 'impacto' => 0.0],
  ];

  foreach ($chemicalMovements as $movement) {
    $product = (string)($movement['cve_prod'] ?? '');
    $year = (int)($movement['anio_iso'] ?? 0);
    if (!isset($productosEnGrupo[$product], $totales[$year])) continue;
    $totales[$year]['consumo'] += (float)($movement['consumo_kg'] ?? 0.0);
    $totales[$year]['impacto'] += (float)($movement['impacto_economico'] ?? 0.0);
  }

  $consumoEnzimaAnioAnterior[$grupoTitulo] = $totales[$anioAnterior]['consumo'];
  $consumoEnzimaAnioActual[$grupoTitulo] = $totales[$anioActual]['consumo'];
  $impactoEconomicoAnioAnterior[$grupoTitulo] = $totales[$anioAnterior]['impacto'];
  $impactoEconomicoAnioActual[$grupoTitulo] = $totales[$anioActual]['impacto'];
  $costoPromedioAnioAnterior[$grupoTitulo] = $totales[$anioAnterior]['consumo'] > 0
    ? $totales[$anioAnterior]['impacto'] / $totales[$anioAnterior]['consumo']
    : 0.0;
  $costoPromedioAnioActual[$grupoTitulo] = $totales[$anioActual]['consumo'] > 0
    ? $totales[$anioActual]['impacto'] / $totales[$anioActual]['consumo']
    : 0.0;
  $variacionConsumoEnzima[$grupoTitulo] = $totales[$anioAnterior]['consumo'] > 0
    ? (($totales[$anioActual]['consumo'] - $totales[$anioAnterior]['consumo']) / $totales[$anioAnterior]['consumo']) * 100
    : 0.0;
  $variacionCostoEnzima[$grupoTitulo] = $costoPromedioAnioAnterior[$grupoTitulo] > 0
    ? (($costoPromedioAnioActual[$grupoTitulo] - $costoPromedioAnioAnterior[$grupoTitulo]) / $costoPromedioAnioAnterior[$grupoTitulo]) * 100
    : 0.0;
}

// Crear catálogos y matrices para tabla pivote
$quimicosCatalogo = array_keys($grupoEstructura);
$quimicosEtiquetas = array_combine($quimicosCatalogo, array_map(fn($g) => $g['titulo'] ?? $g, $grupoEstructura));
$totalesConsumoQuimico = $consumoEnzimaAnioActual;
$totalesCostoQuimico = $impactoEconomicoAnioActual;
$anioPivot = $anioActual;

// Matrices semanales construidas con API + producción de rev_tarimas.
$semanasCatalogo = array_map(static fn(int $week): string => 'S' . str_pad((string)$week, 2, '0', STR_PAD_LEFT), range(1, 52));
$matrizRatioQuimicos = [];
$matrizImpactoEconomicoQuimicos = [];
$ratioBasePorQuimico = [];
$produccionPivotPorSemana = [];
foreach ($produccionPorPeriodo as $row) {
  $weekIso = (string)($row['semana_iso'] ?? '');
  if ((int)substr($weekIso, 0, 4) !== $anioPivot) continue;
  $produccionPivotPorSemana[substr($weekIso, -3)] = (float)($row['kilos_producidos'] ?? 0.0);
}

foreach ($grupoEstructura as $grupoKey => $grupoInfo) {
  $productosEnGrupo = array_fill_keys(array_map('strval', (array)($grupoInfo['productos'] ?? [])), true);
  $consumoSemanal = [];
  $impactoSemanal = [];
  foreach ($chemicalMovements as $movement) {
    if ((int)($movement['anio_iso'] ?? 0) !== $anioPivot) continue;
    if (!isset($productosEnGrupo[(string)($movement['cve_prod'] ?? '')])) continue;
    $weekLabel = substr((string)($movement['semana_iso'] ?? ''), -3);
    $consumoSemanal[$weekLabel] = ($consumoSemanal[$weekLabel] ?? 0.0) + (float)($movement['consumo_kg'] ?? 0.0);
    $impactoSemanal[$weekLabel] = ($impactoSemanal[$weekLabel] ?? 0.0) + (float)($movement['impacto_economico'] ?? 0.0);
  }

  foreach ($semanasCatalogo as $weekLabel) {
    $production = (float)($produccionPivotPorSemana[$weekLabel] ?? 0.0);
    $consumption = (float)($consumoSemanal[$weekLabel] ?? 0.0);
    $matrizRatioQuimicos[$grupoKey][$weekLabel] = $production > 0 ? $consumption / $production : null;
    $matrizImpactoEconomicoQuimicos[$grupoKey][$weekLabel] = (float)($impactoSemanal[$weekLabel] ?? 0.0);
  }

  $grupoTitulo = (string)($grupoInfo['titulo'] ?? $grupoKey);
  $ratioBasePorQuimico[$grupoKey] = $totalProduccionAnioAnterior > 0
    ? (float)($consumoEnzimaAnioAnterior[$grupoTitulo] ?? 0.0) / $totalProduccionAnioAnterior
    : null;
}

/*
|--------------------------------------------------------------------------
| 8) CHART DATA
|--------------------------------------------------------------------------
*/
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase);

/*
|--------------------------------------------------------------------------
| 9) RESPUESTA FINAL
|--------------------------------------------------------------------------
*/
$result = [
  'titulo' => $config['titulo'] ?? 'Grupo / Producción',
  'grupoActual' => $grupoActual,
  'productoSeleccionado' => $productoSeleccionado,
  'modo' => $modo,
  'metricaNombre' => $metricaNombre,
  'metricaTitulo' => $metricaTitulo,
  'metricaUnidad' => $metricaUnidad,
  'badgeRatio' => $badgeRatio,

  'anioAnterior' => $anioAnterior,
  'anioActual' => $anioActual,

  'ratioBase' => $ratioBase,
  'ratioGlobal' => $ratioGlobal,
  'ratioPromedioAnioActual' => $ratioPromedioAnioActual,

  'limiteVerde' => $limiteVerde,
  'limiteAmarillo' => $limiteAmarillo,

  'estadoGlobal' => $estadoGlobal,
  'colorGlobal' => $colorGlobal,
  'colorGlobalHex' => $colorGlobalHex,

  'totalMetricaAnioAnterior' => $totalMetricaAnioAnterior,
  'totalProduccionAnioAnterior' => $totalProduccionAnioAnterior,
  'totalMetricaAnioActual' => $totalMetricaAnioActual,
  'totalProduccionAnioActual' => $totalProduccionAnioActual,

  // compatibilidad con parciales viejos
  'totalQuimicosAnioAnterior' => $totalMetricaAnioAnterior,
  'totalQuimicosAnioActual' => $totalMetricaAnioActual,

  'variacionMetrica' => $variacionMetrica,
  'variacionProduccion' => $variacionProduccion,
  'variacionRatio' => $variacionRatio,

  'metricaPorPeriodo' => $detallePorPeriodo,
  'produccionPorPeriodo' => $produccionPorPeriodo,

  'reporte' => $reporte,
  'datosAnioAnterior' => $datosAnioAnterior,
  'datosAnioActual' => $datosAnioActual,

  'chartData' => $chartData,

  'maxRatio' => $maxRatio,
  'version' => $version,

  'meta' => [
    'fechaDesde' => $fechaDesde,
    'campoFechaMovs' => $campoFechaMovs,
    'productos' => $productosGrupo,
    'grupoActual' => $grupoActual,
    'productoSeleccionado' => $productoSeleccionado,
    'cardsPorPagina' => $cardsPorPagina,
    'filasPorPagina' => $filasPorPagina,
    'toleranciaPct' => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'cveMov' => $cveMov,
    'modo' => $modo,
    'campoCosto' => $campoCosto,
    'metricaTitulo' => $metricaTitulo,
    'metricaUnidad' => $metricaUnidad,
    'badgeRatio' => $badgeRatio,
    'sourceWarning' => $sourceWarning,
    'fuenteMovimientos' => 'API movimientos-salida',
    'grupo_estructura' => $config['grupo_estructura'] ?? [],
    'consumoEnzimaAnioAnterior' => $consumoEnzimaAnioAnterior,
    'consumoEnzimaAnioActual' => $consumoEnzimaAnioActual,
    'variacionConsumoEnzima' => $variacionConsumoEnzima,
    'costoPromedioAnioAnterior' => $costoPromedioAnioAnterior,
    'costoPromedioAnioActual' => $costoPromedioAnioActual,
    'variacionCostoEnzima' => $variacionCostoEnzima,
    'impactoEconomicoAnioAnterior' => $impactoEconomicoAnioAnterior,
    'impactoEconomicoAnioActual' => $impactoEconomicoAnioActual,
    'quimicosCatalogo' => $quimicosCatalogo,
    'quimicosEtiquetas' => $quimicosEtiquetas,
    'totalesConsumoQuimico' => $totalesConsumoQuimico,
    'totalesCostoQuimico' => $totalesCostoQuimico,
    'anioPivot' => $anioPivot,
    'semanasCatalogo' => $semanasCatalogo,
    'matrizRatioQuimicos' => $matrizRatioQuimicos,
    'matrizImpactoEconomicoQuimicos' => $matrizImpactoEconomicoQuimicos,
    'ratioBasePorQuimico' => $ratioBasePorQuimico,
    'produccionPivotPorSemana' => $produccionPivotPorSemana,
  ],
];

// Cachear el resultado por 1 hora
setCache($cacheKey, $result, 3600);

return $result;
