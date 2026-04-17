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

$state = ReportEngine::createContext($config, $appConfig, $dbConfig);
$pdoMovs = $state['pdoMovs'];
$pdoProd = $state['pdoProd'];
$campoFechaMovsSql = $state['campoFechaMovsSql'];
$campoCostoSql = "m.`{$campoCosto}`";
$campoCantidadSql = "
        CASE
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
            WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
            ELSE m.CANT_PROD
        END
    ";

$weekFields = $state['weekFields'];
$placeholdersGrupo = createPlaceholders($productosGrupo);

$consumoExpr = getConsumoExpression();
$costoPromedioExpr = getCostoExpression($campoCosto);

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
if ($modo === 'consumo') {
  $sqlDetalle = "
        SELECT
            YEARWEEK($campoFechaMovsSql, 3) AS periodo,
            DATE_FORMAT($campoFechaMovsSql, '%x-S%v') AS semana_iso,
            DATE_FORMAT(DATE_SUB(DATE($campoFechaMovsSql), INTERVAL WEEKDAY($campoFechaMovsSql) DAY), '%Y-%m-%d') AS semana_inicio,
            DATE_FORMAT(DATE_ADD(DATE($campoFechaMovsSql), INTERVAL (6 - WEEKDAY($campoFechaMovsSql)) DAY), '%Y-%m-%d') AS semana_fin,
            $consumoExpr AS consumo_kg
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) IN ($placeholdersGrupo)
    ";
} elseif ($modo === 'costo') {
  $sqlDetalle = "
        SELECT
            YEARWEEK($campoFechaMovsSql, 3) AS periodo,
            DATE_FORMAT($campoFechaMovsSql, '%x-S%v') AS semana_iso,
            DATE_FORMAT(DATE_SUB(DATE($campoFechaMovsSql), INTERVAL WEEKDAY($campoFechaMovsSql) DAY), '%Y-%m-%d') AS semana_inicio,
            DATE_FORMAT(DATE_ADD(DATE($campoFechaMovsSql), INTERVAL (6 - WEEKDAY($campoFechaMovsSql)) DAY), '%Y-%m-%d') AS semana_fin,
            $costoPromedioExpr AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) IN ($placeholdersGrupo)
    ";
} else {
  // impacto
  $sqlDetalle = "
        SELECT
            YEARWEEK($campoFechaMovsSql, 3) AS periodo,
            DATE_FORMAT($campoFechaMovsSql, '%x-S%v') AS semana_iso,
            DATE_FORMAT(DATE_SUB(DATE($campoFechaMovsSql), INTERVAL WEEKDAY($campoFechaMovsSql) DAY), '%Y-%m-%d') AS semana_inicio,
            DATE_FORMAT(DATE_ADD(DATE($campoFechaMovsSql), INTERVAL (6 - WEEKDAY($campoFechaMovsSql)) DAY), '%Y-%m-%d') AS semana_fin,
            $consumoExpr AS consumo_kg,
            $costoPromedioExpr AS costo_promedio
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) IN ($placeholdersGrupo)
    ";
}

$paramsDetalle = array_merge([$fechaDesde], $productosGrupo);

if ($cveMov !== null && $cveMov !== '') {
  $sqlDetalle .= " AND m.CVE_MOV = ? ";
  $paramsDetalle[] = $cveMov;
}

$sqlDetalle .= "
    GROUP BY" . buildWeekGroupBy($campoFechaMovsSql) . "
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
| 3) BASE DE COSTO DEL GRUPO PARA costo/impacto
|--------------------------------------------------------------------------
*/
$costoBaseGrupo = null;
$costoPromedioActualGrupo = null;

if ($modo === 'costo' || $modo === 'impacto') {
  $sqlCostoBase = "
        SELECT
            CASE WHEN SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCantidadSql END) > 0
                 THEN SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCostoSql * $campoCantidadSql END) /
                      SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCantidadSql END)
                 ELSE NULL END AS promedio_anio_anterior,
            CASE WHEN SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCantidadSql END) > 0
                 THEN SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCostoSql * $campoCantidadSql END) /
                      SUM(CASE WHEN CAST(DATE_FORMAT($campoFechaMovsSql, '%x') AS UNSIGNED) = ? THEN $campoCantidadSql END)
                 ELSE NULL END AS promedio_anio_actual
        FROM movs m
        WHERE $campoFechaMovsSql >= ?
          AND TRIM(m.TIPO_MOV) = 'S'
          AND TRIM(m.CVE_PROD) IN ($placeholdersGrupo)
    ";

  $paramsCostoBase = array_merge([$anioAnterior, $anioAnterior, $anioAnterior, $anioActual, $anioActual, $anioActual, $fechaDesde], $productosGrupo);

  if ($cveMov !== null && $cveMov !== '') {
    $sqlCostoBase .= " AND m.CVE_MOV = ? ";
    $paramsCostoBase[] = $cveMov;
  }

  $stmtCostoBase = $pdoMovs->prepare($sqlCostoBase);
  $stmtCostoBase->execute($paramsCostoBase);
  $rowCostoBase = $stmtCostoBase->fetch();

  $costoBaseGrupo = isset($rowCostoBase['promedio_anio_anterior']) && $rowCostoBase['promedio_anio_anterior'] !== null
    ? (float)$rowCostoBase['promedio_anio_anterior']
    : null;

  $costoPromedioActualGrupo = isset($rowCostoBase['promedio_anio_actual']) && $rowCostoBase['promedio_anio_actual'] !== null
    ? (float)$rowCostoBase['promedio_anio_actual']
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
$sqlProductosSemana = "
    SELECT
        YEARWEEK($campoFechaMovsSql, 3) AS periodo,
        DATE_FORMAT($campoFechaMovsSql, '%x-S%v') AS semana_iso,
        TRIM(m.CVE_PROD) AS cve_prod,
        $consumoExpr AS consumo_kg,
        $costoPromedioExpr AS costo_promedio
    FROM movs m
    WHERE $campoFechaMovsSql >= ?
      AND TRIM(m.TIPO_MOV) = 'S'
      AND TRIM(m.CVE_PROD) IN ($placeholdersGrupo)
";

$paramsProductosSemana = array_merge([$fechaDesde], $productosGrupo);

if ($cveMov !== null && $cveMov !== '') {
  $sqlProductosSemana .= " AND m.CVE_MOV = ? ";
  $paramsProductosSemana[] = $cveMov;
}

$sqlProductosSemana .= "
    GROUP BY
        YEARWEEK($campoFechaMovsSql, 3),
        DATE_FORMAT($campoFechaMovsSql, '%x-S%v'),
        TRIM(m.CVE_PROD)
    ORDER BY periodo, cve_prod
";

$stmtProductosSemana = $pdoMovs->prepare($sqlProductosSemana);
$stmtProductosSemana->execute($paramsProductosSemana);

$detalleProductosPorPeriodo = [];
while ($row = $stmtProductosSemana->fetch()) {
  $periodo = (int)$row['periodo'];
  $cveProd = trim((string)$row['cve_prod']);

  if (!isset($detalleProductosPorPeriodo[$periodo])) {
    $detalleProductosPorPeriodo[$periodo] = [];
  }

  $detalleProductosPorPeriodo[$periodo][$cveProd] = [
    'consumo_kg' => (float)($row['consumo_kg'] ?? 0),
    'costo_promedio' => isset($row['costo_promedio']) ? (float)$row['costo_promedio'] : null,
  ];
}

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
    $costoPromedioExpr,
    $consumoExpr,
    $campoFechaMovsSql,
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
  ],
];

// Cachear el resultado por 1 hora
setCache($cacheKey, $result, 3600);

return $result;
