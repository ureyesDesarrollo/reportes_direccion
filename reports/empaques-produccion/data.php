<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
  $appConfig = require __DIR__ . '/../../config/app.php';
  $dbConfig = require __DIR__ . '/../../config/database.php';
  $config = require __DIR__ . '/config.php';
  require __DIR__ . '/../../shared/helpers.php';
  require __DIR__ . '/../../shared/ReportHelpers.php';

  $report = require __DIR__ . '/build_report.php';

  // Debug para modo impacto
  $debugInfo = [];
  if (isset($_GET['modo']) && $_GET['modo'] === 'impacto') {
    $debugInfo = [
      'datosAnioActual_count' => count($report['datosAnioActual'] ?? []),
      'datosAnioAnterior_count' => count($report['datosAnioAnterior'] ?? []),
      'chartData_labels_count' => count($report['chartData']['labels'] ?? []),
      'chartData_ratios_count' => count($report['chartData']['ratiosActual'] ?? []),
    ];
  }

  $response = [
    'ok' => true,

    'titulo' => $report['titulo'] ?? 'Empaques en General / Producción',
    'anioAnterior' => $report['anioAnterior'] ?? null,
    'anioActual' => $report['anioActual'] ?? null,
    'anioPivot' => $report['anioPivot'] ?? null,

    'ratioBase' => $report['ratioBase'] ?? null,
    'ratioGlobal' => $report['ratioGlobal'] ?? null,
    'ratioPromedioAnioActual' => $report['ratioPromedioAnioActual'] ?? null,

    'limiteVerde' => $report['limiteVerde'] ?? null,
    'limiteAmarillo' => $report['limiteAmarillo'] ?? null,

    'estadoGlobal' => $report['estadoGlobal'] ?? null,
    'colorGlobal' => $report['colorGlobal'] ?? null,
    'colorGlobalHex' => $report['colorGlobalHex'] ?? null,

    'totalQuimicosAnioAnterior' => $report['totalQuimicosAnioAnterior'] ?? 0,
    'totalProduccionAnioAnterior' => $report['totalProduccionAnioAnterior'] ?? 0,
    'totalQuimicosAnioActual' => $report['totalQuimicosAnioActual'] ?? 0,
    'totalProduccionAnioActual' => $report['totalProduccionAnioActual'] ?? 0,

    'variacionQuimicos' => $report['variacionQuimicos'] ?? null,
    'variacionProduccion' => $report['variacionProduccion'] ?? null,
    'variacionRatio' => $report['variacionRatio'] ?? null,

    'empaquesporPeriodo' => $report['empaquesporPeriodo'] ?? [],
    'produccionPorPeriodo' => $report['produccionPorPeriodo'] ?? [],

    'reporte' => $report['reporte'] ?? [],
    'datosAnioAnterior' => $report['datosAnioAnterior'] ?? [],
    'datosAnioActual' => $report['datosAnioActual'] ?? [],

    'chartData' => $report['chartData'] ?? [
      'anioAnterior' => null,
      'anioActual' => null,
      'ratioBase' => null,
      'labels' => [],
      'ratiosActual' => [],
      'colorsActual' => [],
      'ratiosBase' => [],
    ],

    'semanasCatalogo' => $report['semanasCatalogo'] ?? [],
    'empaquessCatalogo' => $report['empaquessCatalogo'] ?? [],
    'empaquesEtiquetas' => $report['empaquesEtiquetas'] ?? [],
    'unidadesCatalogo' => $report['unidadesCatalogo'] ?? [],
    'matrizEmpaques' => $report['matrizEmpaques'] ?? [],
    'produccionPivotPorSemana' => $report['produccionPivotPorSemana'] ?? [],
    'matrizRatioEmpaques' => $report['matrizRatioEmpaques'] ?? [],
    'ratioBasePorEmpaque' => $report['ratioBasePorEmpaque'] ?? [],
    'totalesPorSemana' => $report['totalesPorSemana'] ?? [],
    'produccionPorSemana' => $report['produccionPorSemana'] ?? [],
    'ratioPorSemana' => $report['ratioPorSemana'] ?? [],

    'maxRatio' => $report['maxRatio'] ?? 0,
    'version' => $report['version'] ?? time(),

    'meta' => $report['meta'] ?? [],
    'generated_at' => date('Y-m-d H:i:s'),
    'debug' => $debugInfo,
  ];

  echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);

  echo json_encode([
    'ok' => false,
    'message' => 'Error al generar el reporte.',
    'error' => $e->getMessage(),
    'generated_at' => date('Y-m-d H:i:s'),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
