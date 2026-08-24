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
$defaultBloomKey = '';
$defaultBloomBrands = -1;
foreach ($gruposBloom as $grupoBloomCandidate) {
  $candidateBrands = count((array)($grupoBloomCandidate['marcas'] ?? []));
  if ($candidateBrands > $defaultBloomBrands) {
    $defaultBloomBrands = $candidateBrands;
    $defaultBloomKey = (string)($grupoBloomCandidate['key'] ?? 'sin-bloom');
  }
}
$filas = (array)($report['filas'] ?? []);
$rankingsNatural = (array)($rankingsNatural ?? []);
$kpis = (array)($kpis ?? []);
$tendencias = (array)($tendencias ?? ['labels' => [], 'series' => []]);
$mesActualResumen = !empty($mesesResumen) ? (array)$mesesResumen[count($mesesResumen) - 1] : [];
$mesActualKey = (string)($mesActualResumen['key'] ?? '');
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

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

    .bloom-stack {
      display: grid;
      gap: 10px;
    }

    .bloom-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 10px;
      overflow-x: auto;
      padding-bottom: 2px;
    }

    .bloom-tab {
      flex: 0 0 auto;
      padding: 7px 14px;
      border: 1px solid #d9dee5;
      border-radius: 999px;
      color: #475467;
      background: #ffffff;
      font-size: .72rem;
      font-weight: 900;
      cursor: pointer;
    }

    .bloom-tab.is-active {
      color: #ffffff;
      border-color: #1e5b78;
      background: #1e5b78;
    }

    .bloom-panel[hidden] { display: none; }

    h1 i { color: #1e5b78; }

    .badge {
      color: #1e5b78;
      border-color: #bdd7e3;
      background: #edf6f9;
    }

    .kpi-card:nth-child(1) .kpi-icon { color: #1e5b78; background: #e8f2f6; }
    .kpi-card:nth-child(2) .kpi-icon { color: #25677f; background: #eaf4f7; }
    .kpi-card:nth-child(3) .kpi-icon { color: #475467; background: #eef0f2; }
    .kpi-card:nth-child(4) .kpi-icon { color: #a65f00; background: #fff4df; }

    .trend-btn {
      color: #1e5b78;
      border-color: #bdd7e3;
    }

    .trend-btn.active {
      color: #ffffff;
      border-color: #1e5b78;
      background: #1e5b78;
    }

    .bloom-panel {
      overflow: hidden;
      border: 1px solid #d9dee5;
      border-radius: 20px;
      background: #ffffff;
      box-shadow: 0 8px 24px rgba(36, 52, 71, .06);
    }

    .bloom-panel-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 16px 18px;
      border-bottom: 1px solid #e5e7eb;
      background: #f5f3f0;
    }

    .bloom-panel-title {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .bloom-panel-title h3 {
      color: #243447;
      font-size: 1.25rem;
      font-weight: 900;
    }

    .bloom-number {
      min-width: 88px;
      padding: 7px 12px;
      border-radius: 10px;
      color: #ffffff;
      background: #1e5b78;
      font-size: .78rem;
      font-weight: 900;
      text-align: center;
    }

    .bloom-period {
      color: #667085;
      font-size: .76rem;
      font-weight: 800;
    }

    .bloom-content {
      display: grid;
      gap: 10px;
      padding: 10px;
    }

    .bloom-summary-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 10px;
      align-items: stretch;
    }

    .top3-block {
      display: grid;
      gap: 6px;
    }

    .top3-heading {
      display: flex;
      align-items: center;
      gap: 7px;
      color: #243447;
      font-size: .83rem;
      font-weight: 950;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .top3-heading i { color: #c99a2e; }

    .top3-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px;
    }

    .ranking-card {
      border: 2px solid var(--ranking-border);
      border-left: 7px solid var(--ranking-color);
      border-radius: 15px;
      background: var(--ranking-bg);
      padding: 11px;
    }

    .ranking-card.is-outside { --ranking-color: #c65d3b; --ranking-bg: #fff8f5; --ranking-border: #edc1b2; }
    .ranking-card.is-inside { --ranking-color: #6d5bd0; --ranking-bg: #f8f6ff; --ranking-border: #d5cef4; }

    .ranking-card h4 {
      color: #243447;
      font-size: 1rem;
      font-weight: 900;
    }

    .ranking-card > p {
      margin: 2px 0 7px;
      color: #667085;
      font-size: .7rem;
      font-weight: 700;
    }

    .ranking-list {
      display: grid;
      gap: 5px;
    }

    .ranking-item {
      display: grid;
      grid-template-columns: 31px minmax(0, 1fr) auto;
      align-items: center;
      gap: 9px;
      min-height: 43px;
      padding: 5px 8px;
      border-radius: 10px;
      border: 1px solid rgba(148, 163, 184, .2);
      background: #ffffff;
    }

    .ranking-position {
      display: grid;
      place-items: center;
      width: 31px;
      height: 31px;
      border-radius: 9px;
      color: #ffffff;
      background: #8d99a6;
      font-size: .86rem;
      font-weight: 900;
    }

    .ranking-item:nth-child(1) .ranking-position { background: #c99a2e; }
    .ranking-item:nth-child(2) .ranking-position { background: #8d99a6; }
    .ranking-item:nth-child(3) .ranking-position { background: #b56f45; }

    .ranking-brand {
      min-width: 0;
      color: #344054;
      font-size: .84rem;
      font-weight: 850;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .ranking-brand small {
      display: block;
      margin-top: 2px;
      color: #98a2b3;
      font-size: .58rem;
      font-weight: 750;
    }

    .ranking-value {
      color: var(--ranking-color);
      min-width: 58px;
      font-size: 1.3rem;
      font-weight: 900;
      text-align: right;
    }

    .natural-comparison {
      border: 1px solid #e4e7ec;
      border-radius: 15px;
      background: #faf9f7;
      padding: 10px;
    }

    .natural-comparison h4 {
      margin-bottom: 3px;
      color: #243447;
      font-size: .9rem;
      font-weight: 900;
    }

    .natural-comparison > p {
      margin-bottom: 7px;
      color: #667085;
      font-size: .68rem;
      font-weight: 700;
    }

    .natural-comparison-grid {
      display: grid;
      gap: 5px;
    }

    .natural-brand-row {
      display: grid;
      grid-template-columns: minmax(105px, 150px) repeat(2, minmax(130px, 1fr));
      align-items: center;
      gap: 7px;
    }

    .natural-brand-name {
      color: #344054;
      font-size: .7rem;
      font-weight: 850;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .natural-metric {
      display: grid;
      grid-template-columns: 46px minmax(55px, 1fr) 39px;
      align-items: center;
      gap: 7px;
    }

    .natural-metric-label {
      color: #667085;
      font-size: .58rem;
      font-weight: 850;
      text-transform: uppercase;
    }

    .natural-track {
      height: 9px;
      overflow: hidden;
      border-radius: 999px;
      background: #e7e5e4;
    }

    .natural-fill {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--metric-color);
    }

    .natural-metric.is-outside { --metric-color: #c65d3b; }
    .natural-metric.is-inside { --metric-color: #6d5bd0; }

    .natural-metric-value {
      color: #344054;
      font-size: .7rem;
      font-weight: 900;
      text-align: right;
    }

    .parameter-matrix-wrap {
      overflow-x: auto;
      border: 1px solid #e4e7ec;
      border-radius: 15px;
    }

    .parameter-matrix {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      table-layout: fixed;
    }

    .parameter-matrix th,
    .parameter-matrix td {
      padding: 4px 6px;
      border-right: 1px solid #edf0f3;
      border-bottom: 1px solid #edf0f3;
      text-align: center;
      vertical-align: middle;
    }

    .parameter-matrix thead th {
      color: #ffffff;
      background: #243447;
      font-size: .66rem;
      font-weight: 900;
    }

    .parameter-matrix .matrix-brand-name-head,
    .parameter-matrix .matrix-brand-cell {
      position: sticky;
      left: 0;
      z-index: 3;
      width: 165px;
      min-width: 165px;
      text-align: left;
    }

    .parameter-matrix .matrix-brand-cell {
      border-left: 5px solid #94a3b8;
      color: #243447;
      background: #ffffff;
      font-size: .68rem;
      font-weight: 900;
    }

    .matrix-param-head {
      width: 105px;
      min-width: 105px;
      line-height: 1.15;
    }

    .matrix-param-head small {
      display: block;
      margin-top: 3px;
      color: #cbd5e1;
      font-size: .48rem;
      font-weight: 750;
      line-height: 1.1;
    }

    .parameter-matrix .parameter-name-head,
    .parameter-matrix .parameter-name {
      position: sticky;
      left: 0;
      z-index: 2;
      width: 190px;
      text-align: left;
    }

    .parameter-matrix .parameter-limit-head,
    .parameter-matrix .parameter-limit {
      width: 135px;
    }

    .parameter-matrix .parameter-name {
      color: #344054;
      background: #ffffff;
      font-size: .7rem;
      font-weight: 850;
    }

    .parameter-matrix .parameter-limit {
      color: #667085;
      background: #fafafa;
      font-size: .62rem;
      font-weight: 750;
    }

    .parameter-matrix tbody tr:nth-child(even) .parameter-name { background: #fafafa; }
    .parameter-matrix tbody tr.is-natural-outside .parameter-name { border-left: 5px solid #c65d3b; }
    .parameter-matrix tbody tr.is-natural-inside .parameter-name { border-left: 5px solid #6d5bd0; }

    .matrix-brand {
      min-width: 115px;
      border-top: 4px solid var(--brand-color);
    }

    .matrix-value {
      display: grid;
      gap: 2px;
      min-height: 27px;
      align-content: center;
      border: 1px solid #e4e7ec;
      border-radius: 8px;
      background: #ffffff;
    }

    .matrix-value strong {
      color: #243447;
      font-size: .72rem;
      font-weight: 900;
    }

    .matrix-value small {
      color: #98a2b3;
      font-size: .46rem;
      font-weight: 750;
      text-transform: uppercase;
    }

    .matrix-value.dentro { background: #eaf8ef; border-color: #b8dfc7; }
    .matrix-value.fuera { background: #fee9e7; border-color: #f5b7ad; }
    .matrix-value.fuera strong { color: #b93227; }
    .matrix-value.natural-outside { background: #fff7e8; border-color: #f1c780; }
    .matrix-value.natural-outside strong { color: #a65f00; }
    .matrix-value.natural-inside { background: #eaf7fd; border-color: #8ed0ed; }
    .matrix-value.natural-inside strong { color: #036b9f; }

    .ranking-empty {
      padding: 14px;
      border-radius: 10px;
      color: #98a2b3;
      background: #f8fafc;
      font-size: .7rem;
      font-weight: 750;
      text-align: center;
    }

    .podium-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .podium-card {
      overflow: hidden;
      border: 1px solid var(--podium-border);
      border-radius: 16px;
      background: #ffffff;
    }

    .podium-card.is-outside { --podium-color: #d97706; --podium-soft: #fff7e8; --podium-border: #f1c780; }
    .podium-card.is-inside { --podium-color: #0284c7; --podium-soft: #eaf7fd; --podium-border: #8ed0ed; }

    .podium-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 9px 12px;
      color: #ffffff;
      background: var(--podium-color);
    }

    .podium-header strong {
      font-size: .84rem;
      font-weight: 950;
    }

    .podium-header span {
      font-size: .62rem;
      font-weight: 800;
      opacity: .9;
    }

    .podium-results {
      display: grid;
      grid-template-columns: 1.18fr .82fr;
      grid-template-rows: repeat(2, minmax(46px, auto));
      gap: 7px;
      padding: 8px;
    }

    .podium-place {
      display: grid;
      grid-template-columns: 29px minmax(0, 1fr) auto;
      align-items: center;
      gap: 7px;
      min-width: 0;
      border: 1px solid #e4e7ec;
      border-radius: 11px;
      background: #ffffff;
      padding: 6px 8px;
    }

    .podium-place.is-first {
      grid-row: 1 / 3;
      grid-template-columns: 38px minmax(0, 1fr);
      align-content: center;
      background: var(--podium-soft);
    }

    .podium-results.count-1 .podium-place.is-first {
      grid-column: 1 / -1;
    }

    .podium-results.count-2 .podium-place.is-first {
      grid-row: 1 / 2;
    }

    .podium-rank {
      display: grid;
      place-items: center;
      width: 28px;
      height: 28px;
      border-radius: 9px;
      color: #ffffff;
      background: #8d99a6;
      font-size: .76rem;
      font-weight: 950;
    }

    .podium-place.is-first .podium-rank {
      width: 36px;
      height: 36px;
      background: #c99a2e;
      font-size: 1rem;
    }

    .podium-place.is-third .podium-rank { background: #b56f45; }

    .podium-name {
      min-width: 0;
      color: #344054;
      font-size: .72rem;
      font-weight: 900;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .podium-name small {
      display: block;
      margin-top: 2px;
      color: #98a2b3;
      font-size: .5rem;
      font-weight: 750;
    }

    .podium-score {
      color: var(--podium-color);
      font-size: 1rem;
      font-weight: 950;
    }

    .podium-place.is-first .podium-score {
      grid-column: 1 / -1;
      margin-top: 4px;
      font-size: 1.8rem;
      text-align: center;
    }

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

      .natural-brand-row {
        grid-template-columns: 130px repeat(2, minmax(150px, 1fr));
      }

      .bloom-summary-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 600px) {
      .kpi-grid {
        grid-template-columns: 1fr;
      }

      .top3-grid {
        grid-template-columns: 1fr;
      }

      .podium-grid {
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
          <h2>Desempeño por Bloom</h2>
          <p>Top 3 de comportamiento Natural y comparativo completo de parámetros.</p>
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
        <nav class="bloom-tabs" aria-label="Seleccionar Bloom">
          <?php foreach ($gruposBloom as $grupoIndex => $grupoBloom): ?>
            <?php $isDefaultBloom = (string)($grupoBloom['key'] ?? 'sin-bloom') === $defaultBloomKey; ?>
            <button
              type="button"
              class="bloom-tab <?= $isDefaultBloom ? 'is-active' : '' ?>"
              data-bloom-target="<?= $e($grupoBloom['key'] ?? 'sin-bloom') ?>"
              aria-pressed="<?= $isDefaultBloom ? 'true' : 'false' ?>">
              <?= $e($grupoBloom['label'] ?? 'Sin Bloom') ?>
            </button>
          <?php endforeach; ?>
        </nav>
        <div class="bloom-stack">
          <?php foreach ($gruposBloom as $grupoIndex => $grupoBloom): ?>
            <?php
              $bloomKey = (string)($grupoBloom['key'] ?? 'sin-bloom');
              $marcasGrupo = (array)($grupoBloom['marcas'] ?? []);
              $rankingBloom = (array)($rankingsNatural[$bloomKey] ?? []);
            ?>
            <article class="bloom-panel" data-bloom-panel="<?= $e($bloomKey) ?>" <?= $bloomKey === $defaultBloomKey ? '' : 'hidden' ?>>
              <header class="bloom-panel-header">
                <div class="bloom-panel-title">
                  <span class="bloom-number"><?= $e($grupoBloom['label'] ?? 'Sin Bloom') ?></span>
                  <h3>Comparativo de competencia</h3>
                </div>
                <span class="bloom-period"><?= $e($mesActualResumen['label'] ?? '') ?> · <?= n((float)count($marcasGrupo), 0) ?> marca<?= count($marcasGrupo) === 1 ? '' : 's' ?></span>
              </header>

              <div class="bloom-content">
                <div class="podium-grid">
                  <?php foreach ([
                    ['key' => 'fuera', 'class' => 'is-outside', 'title' => 'Natural · Fuera de refrigeración'],
                    ['key' => 'dentro', 'class' => 'is-inside', 'title' => 'Natural · En refrigeración'],
                  ] as $rankingDefinition): ?>
                    <?php $rankingItems = (array)($rankingBloom[$rankingDefinition['key']] ?? []); ?>
                    <section class="podium-card <?= $e($rankingDefinition['class']) ?>">
                      <header class="podium-header">
                        <strong><?= $e($rankingDefinition['title']) ?></strong>
                        <span>Top 3 del Bloom</span>
                      </header>
                      <?php if ($rankingItems === []): ?>
                        <div class="ranking-empty">Sin datos Natural para esta condición.</div>
                      <?php else: ?>
                        <div class="podium-results count-<?= count($rankingItems) ?>">
                          <?php foreach ($rankingItems as $position => $rankingItem): ?>
                            <div class="podium-place <?= $position === 0 ? 'is-first' : ($position === 2 ? 'is-third' : '') ?>">
                              <span class="podium-rank"><?= $position + 1 ?></span>
                              <span class="podium-name" title="<?= $e($rankingItem['nombre'] ?? '') ?>">
                                <?= $e($rankingItem['nombre'] ?? 'Marca') ?>
                                <small><?= n((float)($rankingItem['muestras'] ?? 0), 0) ?> muestra<?= (int)($rankingItem['muestras'] ?? 0) === 1 ? '' : 's' ?></small>
                              </span>
                              <strong class="podium-score"><?= $formatValue(isset($rankingItem['valor']) ? (float)$rankingItem['valor'] : null, 1) ?></strong>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </section>
                  <?php endforeach; ?>
                </div>

                <div class="parameter-matrix-wrap" aria-label="Todos los parámetros por marca">
                  <table class="parameter-matrix" style="min-width:<?= max(900, 165 + (count($filas) * 105)) ?>px">
                    <thead>
                      <tr>
                        <th class="matrix-brand-name-head">Marca</th>
                        <?php foreach ($filas as $fila): ?>
                          <th class="matrix-param-head" title="<?= $e($fila['nombre'] ?? 'Parámetro') ?>">
                            <?= $e($fila['nombre'] ?? 'Parámetro') ?>
                            <small><?= $e($fila['limite'] ?? '—') ?></small>
                          </th>
                        <?php endforeach; ?>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($marcasGrupo as $marca): ?>
                        <?php
                          $marcaId = (int)($marca['id'] ?? 0);
                          $marcaIndex = $brandIndexById[$marcaId] ?? 0;
                          $colorMarca = (string)($brandColors[$marcaIndex]['main'] ?? '#64748b');
                        ?>
                        <tr>
                          <td class="matrix-brand-cell" style="--brand-color:<?= $e($colorMarca) ?>" title="<?= $e($marca['nombre'] ?? '') ?>"><?= $e($marca['nombre'] ?? 'Marca') ?></td>
                          <?php foreach ($filas as $fila): ?>
                            <?php
                              $filaKey = (string)($fila['key'] ?? '');
                              $cell = (array)($fila['valores'][$marcaId][$mesActualKey] ?? []);
                              $value = is_numeric($cell['valor'] ?? null) ? (float)$cell['valor'] : null;
                              $status = (string)($cell['estado_key'] ?? 'sin-dato');
                              $valueClass = $filaKey === 'comp:FUERA:NATURAL'
                                ? 'natural-outside'
                                : ($filaKey === 'comp:DENTRO:NATURAL' ? 'natural-inside' : $status);
                            ?>
                            <td title="<?= $e($fila['nombre'] ?? 'Parámetro') ?> · <?= $e($marca['nombre'] ?? 'Marca') ?>">
                              <div class="matrix-value <?= $e($valueClass) ?>">
                                <strong><?= $formatValue($value, (int)($fila['decimales'] ?? 2)) ?></strong>
                                <small><?= $value === null ? 'Sin dato' : n((float)($cell['muestras'] ?? 0), 0) . ' muestra' . ((int)($cell['muestras'] ?? 0) === 1 ? '' : 's') ?></small>
                              </div>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
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
      const bloomTabs = Array.from(document.querySelectorAll('[data-bloom-target]'));
      const bloomPanels = Array.from(document.querySelectorAll('[data-bloom-panel]'));
      const trendData = <?= json_encode($tendencias, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const brandColors = <?= json_encode($brandColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      const brandIndexById = <?= json_encode($brandIndexById, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      let trendChartsReady = false;

      bloomTabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          const target = String(tab.dataset.bloomTarget || '');
          bloomTabs.forEach(function (item) {
            const active = item === tab;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-pressed', active ? 'true' : 'false');
          });
          bloomPanels.forEach(function (panel) {
            panel.hidden = String(panel.dataset.bloomPanel || '') !== target;
          });
        });
      });

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
