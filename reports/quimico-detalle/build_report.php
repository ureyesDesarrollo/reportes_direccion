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
$productos = $config['productos'] ?? [];
$modo = $config['modo'] ?? 'consumo';
$toleranciaPct = (float)($config['tolerancia_pct'] ?? 10);

if ($modo === 'impacto') {
  $toleranciaPct = 6.0;
}
$cveMov = $config['cve_mov'] ?? null;
$productoSeleccionado = $config['producto_seleccionado'] ?? ($productos[0] ?? null);
$campoCosto = $config['campo_costo'] ?? 'COST_ENT';

$cardsPorPagina = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);

$anioActual = (int)date('Y');
$anioAnterior = $anioActual - 1;

validateReportColumns($campoFechaMovs, $campoCosto);

if (empty($productoSeleccionado)) {
  throw new RuntimeException('No se recibió producto para el reporte.');
}

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

$state = ReportEngine::createContext($config, $appConfig, $dbConfig);
$pdoMovs = $state['pdoMovs'];
$pdoProd = $state['pdoProd'];
$campoFechaMovsSql = $state['campoFechaMovsSql'];
$weekFields = $state['weekFields'];
$campoCostoSql = "m.`{$campoCosto}`";

$consumoExpr = getConsumoExpression();
$costoPromedioExpr = getCostoExpression($campoCosto);

/*
|--------------------------------------------------------------------------
| DEFINICIÓN DE MODO
|--------------------------------------------------------------------------
*/
$metricaNombre = 'consumo';
$badgeRatio = 'kg químico / kg producidos';
$metricaTitulo = 'Consumo';
$metricaUnidad = 'kg';

if ($modo === 'costo') {
  $metricaNombre = 'costo';
  $badgeRatio = 'Promedio del costo del químico';
  $metricaTitulo = 'Costo Promedio';
  $metricaUnidad = '$';
} elseif ($modo === 'impacto') {
  $metricaNombre = 'impacto';
  $badgeRatio = 'Impacto $ por kg producido';
  $metricaTitulo = 'Impacto Total';
  $metricaUnidad = '$';
}

/*
|--------------------------------------------------------------------------
| Semáforo centralizado en shared/ReportHelpers.php
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| 1) DETALLE SEMANAL DEL PRODUCTO
|--------------------------------------------------------------------------
*/
if ($modo === 'consumo') {
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            $consumoExpr AS consumo_kg
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
    ";
} elseif ($modo === 'costo') {
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            $costoPromedioExpr AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
    ";
} else {
  // impacto
  $sqlDetalle = "
        SELECT
            " . $weekFields . ",
            TRIM(m.CVE_PROD) AS cve_prod,
            $consumoExpr AS consumo_kg,
            $costoPromedioExpr AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
    ";
}

$paramsDetalle = [$fechaDesde, $productoSeleccionado];

if ($cveMov !== null && $cveMov !== '') {
  $sqlDetalle .= " AND m.CVE_MOV = ? ";
  $paramsDetalle[] = $cveMov;
}

$sqlDetalle .= "
    GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . ",
        TRIM(m.CVE_PROD)
    ORDER BY periodo
";

$stmtDetalle = $pdoMovs->prepare($sqlDetalle);
$stmtDetalle->execute($paramsDetalle);

$detallePorPeriodo = [];
while ($row = $stmtDetalle->fetch()) {
  $periodo = (int)$row['periodo'];
  $detallePorPeriodo[$periodo] = $row;
}

/*
|--------------------------------------------------------------------------
| 2) PRODUCCIÓN POR SEMANA
|--------------------------------------------------------------------------
*/
$produccionPorPeriodo = ReportEngine::fetchProductionSeries($pdoProd, $fechaDesde);

/*
|--------------------------------------------------------------------------
| 3) BASE DE COSTO PARA costo/impacto
|--------------------------------------------------------------------------
*/
$costoBase = null;
$costoPromedioActual = null;

if ($modo === 'costo' || $modo === 'impacto') {
  $sqlCostoBase = "
        SELECT
            AVG(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCostoSql END) AS promedio_anio_anterior,
            AVG(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCostoSql END) AS promedio_anio_actual
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) = ?
    ";

  $paramsCostoBase = [$anioAnterior, $anioActual, $fechaDesde, $productoSeleccionado];

  if ($cveMov !== null && $cveMov !== '') {
    $sqlCostoBase .= " AND m.CVE_MOV = ? ";
    $paramsCostoBase[] = $cveMov;
  }

  $stmtCostoBase = $pdoMovs->prepare($sqlCostoBase);
  $stmtCostoBase->execute($paramsCostoBase);
  $rowCostoBase = $stmtCostoBase->fetch();

  $costoBase = isset($rowCostoBase['promedio_anio_anterior']) && $rowCostoBase['promedio_anio_anterior'] !== null
    ? (float)$rowCostoBase['promedio_anio_anterior']
    : null;

  $costoPromedioActual = isset($rowCostoBase['promedio_anio_actual']) && $rowCostoBase['promedio_anio_actual'] !== null
    ? (float)$rowCostoBase['promedio_anio_actual']
    : null;
}

/*
|--------------------------------------------------------------------------
| 4) ARMADO DEL REPORTE
|--------------------------------------------------------------------------
*/
$periodoData = ReportEngine::assemblePeriods(
  $detallePorPeriodo,
  $produccionPorPeriodo,
  static function (array $row, array $produccion, int $periodo) use (
    $modo,
    $costoBase
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
      // MODO IMPACTO: Cálculo detallado del impacto económico por semana
      $consumoKg = isset($row['consumo_kg']) ? (float)$row['consumo_kg'] : 0.0; // Consumo semanal del químico en kg
      $costoPromedioSemana = isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : 0.0; // Costo promedio semanal del químico
      $costoBase = $costoBase; // Costo base histórico (promedio del año anterior para este producto)

      // Diferencia de precio: cuánto más (o menos) cuesta esta semana vs. el histórico
      $diferenciaPrecio = ($costoBase !== null) ? ($costoPromedioSemana - $costoBase) : null;

      // Impacto total semanal: diferencia multiplicada por consumo (costo adicional absoluto)
      $impactoTotal = $diferenciaPrecio !== null ? ($diferenciaPrecio * $consumoKg) : null;

      // Métrica principal: el impacto total (para suma anual)
      $metrica = $impactoTotal ?? 0.0;

      // Ratio: impacto por kg de producción (para comparación normalizada)
      $ratio = ($impactoTotal !== null && $produccionKg > 0)
        ? ($impactoTotal / $produccionKg)
        : null;
    }

    return [
      'semana_iso' => $row['semana_iso'] ?? null,
      'semana_inicio' => $row['semana_inicio'] ?? null,
      'semana_fin' => $row['semana_fin'] ?? null,
      'metrica' => $metrica, // Impacto total semanal (para suma)
      'quimicos' => $metrica, // Alias para compatibilidad
      'produccion' => $produccionKg, // Producción semanal en kg
      'ratio' => $ratio, // Impacto $ por kg producido
      'consumo_kg' => $consumoKg, // Consumo semanal del químico
      'costo_promedio_semana' => $costoPromedioSemana, // Costo promedio semanal
      'costo_base' => $costoBase, // Costo base histórico
      'diferencia_precio' => $diferenciaPrecio, // Diferencia vs. base
      'impacto_total' => $impactoTotal, // Impacto económico semanal
    ];
  },
  $anioAnterior,
  $anioActual
);

$itemsTemporales = $periodoData['items'];
$datosAnioAnterior = $periodoData['datosAnioAnterior'];
$datosAnioActual = $periodoData['datosAnioActual'];

$maxRatio = 0.0;

/*
|--------------------------------------------------------------------------
| 5) BASE Y KPI
|--------------------------------------------------------------------------
*/
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
  $totalMetricaAnioAnterior = $costoBase;
  $totalMetricaAnioActual = $costoPromedioActual;

  $totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));
  $totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));

  $ratioBase = $costoBase;
  $ratioPromedioAnioActual = $costoPromedioActual;
} else {
  // IMPACTO: Cálculo del total impacto base y actual
  // totalMetricaAnioAnterior = Suma de impacto_total de cada semana del año anterior
  // Esto representa el impacto económico total acumulado del año base (sin normalizar por producción)
  $totalMetricaAnioAnterior = array_sum(array_map(
    fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioAnterior
  ));

  // totalMetricaAnioActual = Suma de impacto_total del año actual
  $totalMetricaAnioActual = array_sum(array_map(
    fn($r) => (float)($r['impacto_total'] ?? 0.0),
    $datosAnioActual
  ));

  $totalProduccionAnioAnterior = array_sum(array_column($datosAnioAnterior, 'produccion'));
  $totalProduccionAnioActual = array_sum(array_column($datosAnioActual, 'produccion'));

  // ratioBase = Impacto promedio por kg producido en el año base
  // Se usa para comparación normalizada y semáforo
  $ratioBase = $totalProduccionAnioAnterior > 0
    ? ($totalMetricaAnioAnterior / $totalProduccionAnioAnterior)
    : null;

  // ratioPromedioAnioActual = Impacto promedio por kg en el año actual
  $ratioPromedioAnioActual = $totalProduccionAnioActual > 0
    ? ($totalMetricaAnioActual / $totalProduccionAnioActual)
    : null;
}

$limiteVerde = $ratioBase;
$limiteAmarillo = $ratioBase !== null
  ? $ratioBase * (1 + $toleranciaPct / 100)
  : null;

if ($modo === 'impacto') {
  $limiteAmarillo = $ratioBase !== null ? $ratioBase * 1.06 : null;
}

/*
|--------------------------------------------------------------------------
| 6) SEMÁFORO
|--------------------------------------------------------------------------
*/
$reporte = ReportEngine::applyTrafficLights($itemsTemporales, $ratioBase, $toleranciaPct, $modo);
$reporte = ReportEngine::sortByPeriodDesc($reporte);

$yearSplit = separateByYear($reporte, $anioAnterior, $anioActual);
$datosAnioAnterior = $yearSplit['anterior'];
$datosAnioActual = $yearSplit['actual'];

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
| 7) CHART DATA
|--------------------------------------------------------------------------
*/
$chartData = buildChartData($datosAnioActual, $datosAnioAnterior, $anioAnterior, $anioActual, $ratioBase);

/*
|--------------------------------------------------------------------------
| 8) RESPUESTA FINAL
|--------------------------------------------------------------------------
*/
$result = [
  'titulo' => $config['titulo'] ?? 'Químico / Producción',
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
    'productos' => $productos,
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
  ],
];

setCache($cacheKey, $result, 3600);
return $result;
