<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = (int)($version ?? time());
$intervaloActualizacion = (int)($meta['intervaloActualizacion'] ?? 300000);
$mesesResumen = array_values(array_map(static function (array $mes): array {
  $key = (string)($mes['key'] ?? $mes['mes_key'] ?? '');

  return [
    'key' => $key,
    'label' => (string)($mes['label'] ?? $key),
    'fecha_referencia' => (string)($mes['fecha_referencia'] ?? ''),
    'reportes' => (int)($mes['reportes'] ?? 0),
  ];
}, (array)($mesesResumen ?? $meses ?? [])));
$normalizarMarca = static function (array $marca): array {
  $bloom = array_key_exists('bloom', $marca) && $marca['bloom'] !== null && $marca['bloom'] !== ''
    ? (int)$marca['bloom']
    : null;

  return [
    'id' => (int)($marca['id'] ?? $marca['marca_id'] ?? 0),
    'nombre' => (string)($marca['nombre'] ?? ''),
    'bloom' => $bloom,
    'bloom_key' => $bloom !== null ? (string)$bloom : 'sin-bloom',
    'bloom_label' => $bloom !== null ? 'Bloom ' . $bloom : 'Sin bloom',
  ];
};
$marcas = array_values(array_map($normalizarMarca, (array)($marcas ?? [])));
$gruposBloom = array_values(array_filter(array_map(static function (array $grupo) use ($normalizarMarca): array {
  $marcasGrupo = array_values(array_map($normalizarMarca, (array)($grupo['marcas'] ?? [])));
  $bloom = array_key_exists('bloom', $grupo) && $grupo['bloom'] !== null && $grupo['bloom'] !== ''
    ? (int)$grupo['bloom']
    : null;
  $key = (string)($grupo['key'] ?? ($bloom !== null ? (string)$bloom : 'sin-bloom'));

  return [
    'key' => $key,
    'label' => (string)($grupo['label'] ?? ($bloom !== null ? 'Bloom ' . $bloom : 'Sin bloom')),
    'bloom' => $bloom,
    'marcas' => $marcasGrupo,
  ];
}, (array)($gruposBloom ?? [])), static fn(array $grupo): bool => !empty($grupo['marcas'])));

if (empty($gruposBloom) && !empty($marcas)) {
  $gruposPorBloom = [];
  foreach ($marcas as $marca) {
    $bloomKey = (string)$marca['bloom_key'];
    if (!isset($gruposPorBloom[$bloomKey])) {
      $gruposPorBloom[$bloomKey] = [
        'key' => $bloomKey,
        'label' => (string)$marca['bloom_label'],
        'bloom' => $marca['bloom'],
        'marcas' => [],
      ];
    }
    $gruposPorBloom[$bloomKey]['marcas'][] = $marca;
  }
  $gruposBloom = array_values($gruposPorBloom);
}
$filas = (array)($filas ?? []);
$kpis = (array)($kpis ?? []);
$tendencias = (array)($tendencias ?? ['labels' => [], 'series' => []]);

$formatValue = static function (?float $value, int $decimals): string {
  return $value === null ? '-' : n($value, $decimals);
};

function generateBrandColors($count) {
  $colors = [];

  for ($i = 0; $i < $count; $i++) {
    $hue = (int)round(fmod(24 + ($i * 137.508), 360));
    $saturation = 62 + (($i % 3) * 7);
    $lightness = 34 + (($i % 4) * 4);
    $bgLightness = 93 + ($i % 2);

    $colors[] = [
      'main' => "hsl({$hue}, {$saturation}%, {$lightness}%)",
      'bg' => "hsl({$hue}, 76%, {$bgLightness}%)",
    ];
  }
  
  return $colors;
}

$brandColors = generateBrandColors(count($marcas));
$brandIndexById = [];
foreach ($marcas as $index => $marca) {
  $brandIndexById[(int)$marca['id']] = $index;
}

// Generar CSS dinámico para los colores
$dynamicCSS = '';
foreach ($brandColors as $index => $colorSet) {
  $mainColor = $colorSet['main'];
  $bgColor = $colorSet['bg'];
  
  $dynamicCSS .= "
    /* Color para el encabezado de la marca */
    .brand-color-{$index} { 
      background-color: {$mainColor} !important; 
    }
    /* Color de fondo para las celdas de datos de la marca */
    .brand-bg-{$index} { 
      background-color: {$bgColor} !important; 
    }
    /* Para filas pares */
    .comparison-table tbody tr:nth-child(even) td.brand-bg-{$index} { 
      background-color: {$bgColor} !important; 
    }
    /* Sombreado de todo el bloque de la marca */
    .comparison-table tbody tr.brand-row-{$index} td:not(.sticky-bloom) {
      background-color: {$bgColor} !important;
    }
  ";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars((string)$titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/css/dashboard.css') ?: 0))) ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: #f0f4f8;
      color: #1a2c3e;
      overflow-x: hidden;
    }

    .dashboard {
      max-width: 1760px;
      margin: 0 auto;
      padding: 24px 28px;
    }

    .header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 22px;
    }

    .header-left {
      flex: 1 1 520px;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border-radius: 40px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.8rem;
      border: 1px solid #dce5ec;
      background: #ffffff;
      color: #3a5a78;
      margin-bottom: 12px;
    }

    h1 {
      font-size: 1.95rem;
      font-weight: 760;
      color: #0f2b3d;
      margin-bottom: 6px;
      letter-spacing: -0.3px;
    }

    h1 i {
      color: #2f7d55;
      margin-right: 10px;
    }

    .sub {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      color: #5a6e7c;
      font-size: 0.82rem;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: #e6f0fa;
      border: 1px solid #cde3f5;
      color: #2c6e9e;
      border-radius: 999px;
      padding: 5px 11px;
      font-size: 0.72rem;
      font-weight: 700;
    }

    /* Leyenda de colores de marcas */
    .brand-legend {
      display: flex;
      flex-wrap: wrap;
      gap: 8px 12px;
      margin-top: 10px;
      padding: 10px 0;
    }

    .brand-legend-group {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      flex-wrap: wrap;
      padding: 5px 8px;
      border: 1px solid #dce7ef;
      border-radius: 999px;
      background: #ffffff;
    }

    .bloom-legend-title {
      color: #18384d;
      font-size: 0.68rem;
      font-weight: 820;
      white-space: nowrap;
    }

    .brand-legend-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.68rem;
      font-weight: 600;
      color: #2c3e50;
    }

    .brand-legend-color {
      width: 20px;
      height: 20px;
      border-radius: 6px;
      border: 1px solid #dce5ec;
    }

    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }

    .kpi-card {
      background: #ffffff;
      border-radius: 16px;
      border: 1px solid #e4edf5;
      padding: 13px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 66px;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
    }

    .kpi-icon {
      width: 38px;
      height: 38px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      color: #2f7d55;
      background: #eaf4ef;
    }

    .kpi-label {
      flex: 1 1 auto;
      color: #6b7f8f;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: 0.6px;
      text-transform: uppercase;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kpi-value {
      flex: 0 0 auto;
      color: #123247;
      font-size: 1.45rem;
      font-weight: 800;
      line-height: 1;
      white-space: nowrap;
    }

    .comparison-section {
      margin-bottom: 18px;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
      margin-bottom: 10px;
      flex-wrap: wrap;
    }

    .section-title h2 {
      color: #1a3a4f;
      font-size: 1.18rem;
      font-weight: 780;
      margin-bottom: 3px;
    }

    .section-title p {
      color: #6b7f8f;
      font-size: 0.78rem;
    }

    .section-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #ecf7f0;
      color: #2c7a4d;
      border-radius: 999px;
      padding: 8px 13px;
      font-size: 0.74rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .section-actions {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      flex-wrap: wrap;
    }

    .trend-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid #bcd4e8;
      background: #ffffff;
      color: #1f5f86;
      border-radius: 999px;
      padding: 8px 13px;
      font-size: 0.74rem;
      font-weight: 850;
      cursor: pointer;
      white-space: nowrap;
      box-shadow: 0 2px 7px rgba(15, 23, 42, 0.04);
    }

    .trend-btn.active {
      background: #e4f1fb;
      border-color: #7db7dd;
      color: #114563;
    }

    .comparison-list {
      background: #ffffff;
      border: 1px solid #e7edf4;
      border-radius: 12px;
      overflow: auto;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
      -webkit-overflow-scrolling: touch;
    }

    .comparison-table {
      width: 100%;
      min-width: max(1180px, calc(320px + <?= max(1, count($filas)) * 96 ?>px));
      border-collapse: separate;
      border-spacing: 0;
      table-layout: fixed;
    }

    .comparison-table th,
    .comparison-table td {
      border-bottom: 1px solid #edf2f8;
      padding: 7px 6px;
      vertical-align: middle;
    }

    .comparison-table thead th {
      position: sticky;
      top: 0;
      z-index: 6;
      background: #f8fbfe;
      color: #4a6272;
      text-transform: uppercase;
      letter-spacing: 0.2px;
      font-size: 0.58rem;
      font-weight: 800;
      text-align: center;
    }

    .comparison-table thead tr:nth-child(1) th {
      top: 0;
    }

    .comparison-table thead tr:nth-child(2) th {
      top: 27px;
    }

    .comparison-table .sticky-bloom,
    .comparison-table .sticky-brand-col,
    .comparison-table .sticky-month-col {
      position: sticky;
      background: #ffffff;
      z-index: 5;
      text-align: left;
    }

    .comparison-table thead .sticky-bloom,
    .comparison-table thead .sticky-brand-col,
    .comparison-table thead .sticky-month-col {
      background: #f8fbfe;
      z-index: 8;
      top: 0;
    }

    .sticky-bloom {
      left: 0;
      width: 82px;
      min-width: 82px;
      max-width: 82px;
    }

    .sticky-brand-col {
      left: 82px;
      width: 150px;
      min-width: 150px;
      max-width: 150px;
    }

    .sticky-month-col {
      left: 232px;
      width: 88px;
      min-width: 88px;
      max-width: 88px;
    }

    .bloom-head {
      background: #18384d !important;
      color: #ffffff !important;
      font-size: 0.66rem !important;
      letter-spacing: 0.25px;
      border-left: 1px solid #34566a;
    }

    .param-head {
      width: 96px;
      min-width: 96px;
      color: #18384d !important;
      font-size: 0.56rem !important;
      line-height: 1.12;
      white-space: normal;
      background: #f8fbfe !important;
      border-left: 1px solid #e4edf5;
    }

    .limit-head {
      width: 96px;
      min-width: 96px;
      background: #f8fbfe !important;
      color: #64748b !important;
      font-size: 0.5rem !important;
      line-height: 1.08;
      white-space: normal;
      border-left: 1px solid #e4edf5;
    }

    .limit-head .limit-pill {
      justify-content: center;
      padding: 3px 5px;
      font-size: 0.48rem;
    }

    .bloom-cell strong,
    .brand-cell strong,
    .month-cell strong {
      display: block;
      color: #123247;
      font-size: 0.72rem;
      line-height: 1.1;
    }

    .bloom-cell span,
    .brand-cell span,
    .month-cell span {
      display: block;
      margin-top: 3px;
      color: #6b7f8f;
      font-size: 0.52rem;
      font-weight: 760;
      text-transform: uppercase;
      line-height: 1;
    }

    .brand-cell {
      border-left: 4px solid #64748b;
    }

    .brand-cell .brand-name {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #123247;
      font-size: 0.7rem;
      font-weight: 820;
      line-height: 1.1;
    }

    .brand-dot {
      width: 8px;
      height: 8px;
      border-radius: 999px;
      flex: 0 0 auto;
    }

    .limit-pill {
      display: inline-flex;
      max-width: 100%;
      border-radius: 999px;
      background: #f0f4fa;
      color: #3a5a78;
      padding: 4px 7px;
      font-size: 0.56rem;
      font-weight: 760;
      line-height: 1.1;
      white-space: normal;
    }

    .value-cell {
      width: 96px;
      min-width: 96px;
      text-align: center;
      border-left: 1px solid #f0f4f8;
    }

    .value-box {
      display: grid;
      gap: 2px;
      min-height: 32px;
      align-content: center;
      border-radius: 8px;
      border: 1px solid #e4edf5;
      background: #ffffff;
      padding: 5px 6px;
    }

    .value-box strong {
      color: #123247;
      font-size: 0.76rem;
      line-height: 1;
    }

    .value-box span {
      color: #6b7f8f;
      font-size: 0.48rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0;
      line-height: 1;
    }

    .value-cell.dentro .value-box {
      background: #eaf8ef;
      border-color: #b8dfc7;
    }

    .value-cell.fuera .value-box {
      background: #fee9e7;
      border-color: #f5b7ad;
    }

    .value-cell.fuera .value-box strong {
      color: #b93227;
    }

    .value-cell.neutral .value-box {
      background: #f7fbff;
      border-color: #dce8f4;
    }

    .value-cell.sin-dato .value-box {
      background: #f8fafc;
      color: #94a3b8;
    }

    .comparison-table tbody tr:nth-child(even) td {
      background: #fbfdff;
    }

    .comparison-table tbody tr:nth-child(even) .sticky-bloom,
    .comparison-table tbody tr:nth-child(even) .sticky-brand-col,
    .comparison-table tbody tr:nth-child(even) .sticky-month-col {
      background: #fbfdff;
    }

    .comparison-table tbody tr.bloom-start td {
      border-top: 3px solid #d7e3ed;
    }

    .comparison-table tbody tr.brand-start td {
      border-top: 3px solid var(--brand-color, #cbd5e1);
      box-shadow: inset 0 4px 0 rgba(255, 255, 255, 0.55);
    }

    .comparison-table tbody tr.brand-start .sticky-brand-col {
      box-shadow:
        inset 0 4px 0 rgba(255, 255, 255, 0.55),
        inset 0 1px 0 var(--brand-color, #cbd5e1);
    }

    .trend-section {
      margin-top: 18px;
      margin-bottom: 20px;
    }

    .trend-section[hidden] {
      display: none;
    }

    .trend-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .trend-card {
      background: #ffffff;
      border: 1px solid #e4edf5;
      border-radius: 12px;
      padding: 14px;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
      min-height: 360px;
    }

    .trend-card h3 {
      color: #18384d;
      font-size: 0.98rem;
      font-weight: 820;
      margin-bottom: 10px;
    }

    .trend-chart {
      position: relative;
      height: 300px;
    }

    <?= $dynamicCSS ?>

    .empty-state {
      background: #ffffff;
      border: 1px dashed #cbd5e1;
      border-radius: 18px;
      padding: 38px 22px;
      text-align: center;
      color: #64748b;
    }

    @media (max-width: 980px) {
      .dashboard {
        padding: 18px;
      }

      .kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .trend-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 600px) {
      .kpi-grid {
        grid-template-columns: 1fr;
      }
    }

    html.executive-display .dashboard {
      padding: 14px 18px;
    }

    html.executive-display .kpi-grid {
      gap: 10px;
      margin-bottom: 12px;
    }

    html.executive-display .kpi-card {
      min-height: 58px;
      padding: 10px 12px;
    }

    html.executive-display .comparison-table th,
    html.executive-display .comparison-table td {
      padding: 5px 5px;
    }

    html.executive-display .value-box {
      min-height: 28px;
      padding: 4px 5px;
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <?php $isDireccionGeneral = (
          (isset($_GET['mode']) && (string)$_GET['mode'] === 'direccion-general') ||
          (isset($_GET['modo']) && (string)$_GET['modo'] === 'direccion-general')
        ); ?>
        <a href="<?= $isDireccionGeneral ? '../index.php?mode=direccion-general' : '../index.php' ?>" class="back-btn">
          <i class="fas fa-arrow-left"></i>
          <?= $isDireccionGeneral ? 'Regresar a Dirección General' : 'Regresar al inicio' ?>
        </a>
        <h1><i class="fas fa-chart-simple"></i><?= htmlspecialchars((string)$titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-calendar-alt"></i> Último mes registrado</span>
          <span class="badge"><i class="fas fa-layer-group"></i> <?= n((float)count($gruposBloom), 0) ?> blooms</span>
          <span class="badge"><i class="fas fa-tags"></i> <?= n((float)count($marcas), 0) ?> marcas</span>
          <span class="badge"><i class="fas fa-flask"></i> <?= n((float)count($filas), 0) ?> parámetros</span>
        </div>
        
        <div class="brand-legend">
          <?php 
          foreach ($gruposBloom as $grupoBloom):
          ?>
            <div class="brand-legend-group">
              <span class="bloom-legend-title"><?= htmlspecialchars((string)$grupoBloom['label']) ?></span>
              <?php foreach ((array)$grupoBloom['marcas'] as $marca): ?>
                <?php
                  $marcaIndex = $brandIndexById[(int)$marca['id']] ?? 0;
                  $colorSet = $brandColors[$marcaIndex] ?? ['main' => '#64748b'];
                ?>
                <span class="brand-legend-item">
                  <span class="brand-legend-color" style="background-color: <?= htmlspecialchars((string)$colorSet['main']) ?>;"></span>
                  <?= htmlspecialchars((string)$marca['nombre']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-tags"></i></div>
        <div class="kpi-label">Marcas</div>
        <div class="kpi-value"><?= n((float)($kpis['marcas'] ?? 0), 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-layer-group"></i></div>
        <div class="kpi-label">Blooms</div>
        <div class="kpi-value"><?= n((float)($kpis['blooms'] ?? count($gruposBloom)), 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-vial"></i></div>
        <div class="kpi-label">Parámetros</div>
        <div class="kpi-value"><?= n((float)($kpis['parametros'] ?? 0), 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-circle-exclamation"></i></div>
        <div class="kpi-label">Fuera de límite</div>
        <div class="kpi-value"><?= n((float)($kpis['alertas'] ?? 0), 0) ?></div>
      </div>
    </div>

    <section class="comparison-section">
      <div class="section-header">
        <div class="section-title">
          <h2>Comparativo por bloom</h2>
        </div>
        <div class="section-actions">
          <button type="button" class="trend-btn" id="trendToggle">
            <i class="fas fa-chart-line"></i>
            Tendencia
          </button>
        </div>
      </div>

      <?php if (empty($mesesResumen) || empty($gruposBloom) || empty($filas)): ?>
        <div class="empty-state">No hay información suficiente para generar el comparativo.</div>
      <?php else: ?>
        <div class="comparison-list">
          <table class="comparison-table">
            <thead>
              <tr>
                <th class="sticky-bloom bloom-head" rowspan="2">Bloom</th>
                <th class="sticky-brand-col bloom-head" rowspan="2">Marca</th>
                <th class="sticky-month-col bloom-head" rowspan="2">Mes</th>
                <?php foreach ($filas as $fila): ?>
                  <th class="param-head">
                    <?= htmlspecialchars((string)$fila['nombre']) ?>
                  </th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <?php foreach ($filas as $fila): ?>
                  <th class="limit-head">
                    <span class="limit-pill"><?= htmlspecialchars((string)($fila['limite'] ?? '')) ?></span>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($gruposBloom as $grupoBloom): ?>
                <?php
                  $marcasGrupo = (array)$grupoBloom['marcas'];
                  $rowspanBloom = max(1, count($marcasGrupo) * count($mesesResumen));
                  $isFirstBloomRow = true;
                ?>
                <?php foreach ($marcasGrupo as $marca): ?>
                  <?php
                    $marcaId = (int)$marca['id'];
                    $marcaIndex = $brandIndexById[$marcaId] ?? 0;
                    $colorMarca = (string)($brandColors[$marcaIndex]['main'] ?? '#64748b');
                    $bgMarcaClass = 'brand-bg-' . $marcaIndex;
                    $rowspanMarca = max(1, count($mesesResumen));
                    $isFirstMarcaRow = true;
                  ?>
                  <?php foreach ($mesesResumen as $mes): ?>
                    <?php
                      $rowClasses = [];
                      if ($isFirstBloomRow) {
                        $rowClasses[] = 'bloom-start';
                      }
                      if ($isFirstMarcaRow) {
                        $rowClasses[] = 'brand-start';
                      }
                      $rowClasses[] = 'brand-row-' . $marcaIndex;
                    ?>
                    <tr class="<?= htmlspecialchars(implode(' ', $rowClasses)) ?>" style="--brand-color: <?= htmlspecialchars($colorMarca) ?>;">
                      <?php if ($isFirstBloomRow): ?>
                        <td class="sticky-bloom bloom-cell" rowspan="<?= $rowspanBloom ?>">
                          <strong><?= htmlspecialchars((string)$grupoBloom['label']) ?></strong>
                          <span><?= n((float)count($marcasGrupo), 0) ?> marcas</span>
                        </td>
                      <?php endif; ?>
                      <?php if ($isFirstMarcaRow): ?>
                        <td class="sticky-brand-col brand-cell <?= htmlspecialchars($bgMarcaClass) ?>" rowspan="<?= $rowspanMarca ?>" style="border-left-color: <?= htmlspecialchars($colorMarca) ?>;">
                          <span class="brand-name">
                            <span class="brand-dot" style="background-color: <?= htmlspecialchars($colorMarca) ?>;"></span>
                            <?= htmlspecialchars((string)$marca['nombre']) ?>
                          </span>
                          <span><?= htmlspecialchars((string)$grupoBloom['label']) ?></span>
                        </td>
                      <?php endif; ?>
                      <td class="sticky-month-col month-cell">
                        <strong><?= htmlspecialchars((string)$mes['label']) ?></strong>
                      </td>
                      <?php foreach ($filas as $fila): ?>
                      <?php
                        $mesKey = (string)$mes['key'];
                        $cell = (array)($fila['valores'][$marcaId][$mesKey] ?? []);
                        $estadoKey = (string)($cell['estado_key'] ?? 'sin-dato');
                        $valor = array_key_exists('valor', $cell) ? (float)$cell['valor'] : null;
                        $cellClass = 'value-cell ' . htmlspecialchars($estadoKey);
                      ?>
                      <td class="<?= $cellClass ?>">
                        <div class="value-box">
                          <strong><?= $formatValue($valor, (int)($fila['decimales'] ?? 2)) ?></strong>
                          <span><?= htmlspecialchars((string)($cell['estado'] ?? 'Sin dato')) ?></span>
                        </div>
                      </td>
                    <?php endforeach; ?>
                    </tr>
                    <?php
                      $isFirstBloomRow = false;
                      $isFirstMarcaRow = false;
                    ?>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="trend-section" id="trendSection" hidden>
      <div class="section-header">
        <div class="section-title">
          <h2>Tendencia por marca</h2>
          <p>Promedio mensual de bloom y viscosidad.</p>
        </div>
      </div>

      <div class="trend-grid">
        <div class="trend-card">
          <h3>Bloom</h3>
          <div class="trend-chart"><canvas id="trendBloom"></canvas></div>
        </div>
        <div class="trend-card">
          <h3>Viscosidad</h3>
          <div class="trend-chart"><canvas id="trendViscosidad"></canvas></div>
        </div>
      </div>
    </section>
  </div>

  <script>
    (function () {
      const trendToggle = document.getElementById('trendToggle');
      const trendSection = document.getElementById('trendSection');
      const trendData = <?= json_encode($tendencias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const brandColors = <?= json_encode($brandColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const brandIndexById = <?= json_encode($brandIndexById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      let trendChartsReady = false;

      function buildTrendDatasets(metric) {
        const series = Array.isArray(trendData.series) ? trendData.series : [];

        return series.map(function (item) {
          const brandIndex = brandIndexById[String(item.marca_id)] ?? 0;
          const color = brandColors[brandIndex] || { main: '#64748b' };

          return {
            label: item.nombre,
            data: Array.isArray(item[metric]) ? item[metric] : [],
            borderColor: color.main,
            backgroundColor: color.main,
            tension: 0.32,
            pointRadius: 3,
            pointHoverRadius: 5,
            borderWidth: 2,
            spanGaps: true
          };
        });
      }

      function renderTrendChart(canvasId, metric) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') return;

        const existing = Chart.getChart(canvas);
        if (existing) {
          existing.destroy();
        }

        new Chart(canvas, {
          type: 'line',
          data: {
            labels: Array.isArray(trendData.labels) ? trendData.labels : [],
            datasets: buildTrendDatasets(metric)
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false
            },
            plugins: {
              legend: {
                position: 'bottom',
                labels: {
                  boxWidth: 10,
                  boxHeight: 10,
                  usePointStyle: true,
                  font: {
                    size: 10,
                    weight: '700'
                  }
                }
              },
              tooltip: {
                callbacks: {
                  label: function (context) {
                    const value = context.parsed.y;
                    return context.dataset.label + ': ' + (Number.isFinite(value) ? value.toLocaleString('es-MX') : '-');
                  }
                }
              }
            },
            scales: {
              x: {
                grid: {
                  display: false
                },
                ticks: {
                  color: '#64748b',
                  font: {
                    size: 10,
                    weight: '700'
                  }
                }
              },
              y: {
                beginAtZero: false,
                grid: {
                  color: 'rgba(148, 163, 184, 0.18)'
                },
                ticks: {
                  color: '#64748b',
                  font: {
                    size: 10,
                    weight: '700'
                  }
                }
              }
            }
          }
        });
      }

      function showTrendSection() {
        if (!trendSection) return;
        trendSection.hidden = false;
        trendToggle?.classList.add('active');

        if (!trendChartsReady) {
          renderTrendChart('trendBloom', 'bloom');
          renderTrendChart('trendViscosidad', 'viscosidad');
          trendChartsReady = true;
        }

        trendSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      function hideTrendSection() {
        if (!trendSection) return;
        trendSection.hidden = true;
        trendToggle?.classList.remove('active');
      }

      if (trendToggle) {
        trendToggle.addEventListener('click', function () {
          if (!trendSection || trendSection.hidden) {
            showTrendSection();
          } else {
            hideTrendSection();
          }
        });
      }

      const interval = <?= json_encode($intervaloActualizacion) ?>;
      if (interval > 0) {
        setTimeout(function () {
          window.location.reload();
        }, interval);
      }
    })();
  </script>
</body>
</html>
