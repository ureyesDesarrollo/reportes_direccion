<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';
$buildReportPath = $buildReportPath ?? (__DIR__ . '/build_report.php');
require __DIR__ . '/../../shared/helpers.php';

$loadError = null;
try {
  $report = require $buildReportPath;
} catch (Throwable $e) {
  $loadError = 'No fue posible consultar la información del servidor 105.';
  $report = [
    'titulo' => (string)($config['titulo'] ?? 'Rendimiento por Proceso'),
    'filtros' => ['periodo' => 'mes', 'anio' => (int)date('Y'), 'mes' => (int)date('n'), 'mes_nombre' => '', 'semana' => date('o-\WW'), 'semana_inicio' => date('o-\WW'), 'semana_fin' => date('o-\WW'), 'fecha' => date('Y-m-d'), 'fecha_inicio' => date('Y-m-d'), 'fecha_fin' => date('Y-m-d'), 'hoy' => date('Y-m-d'), 'anios' => [], 'meses' => [], 'material' => 'all', 'proveedor' => null],
    'opciones' => ['materiales' => [], 'proveedores' => []],
    'kpis' => [], 'graficas' => [], 'filas' => [], 'tabla_proveedor_material' => ['grupos' => [], 'error' => null], 'meta' => [], 'version' => time(),
  ];
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 2): string => is_numeric($value) ? n((float)$value, $decimals) : '—';
$fmtKg = static fn($value): string => is_numeric($value) ? n((float)$value, 0) . ' kg' : '—';
$fmtPct = static fn($value): string => is_numeric($value) ? n((float)$value, 2) . '%' : '—';
$fmtTablePct = static fn($value): string => is_numeric($value)
  ? n((float)$value, 2) . '<span class="rp-percent-sign">&#37;</span>'
  : '—';
$fmtMoney = static fn($value): string => is_numeric($value) ? '$ ' . n((float)$value, 2) : '-';
$compactWeekLabel = static function ($value): array {
  $label = trim((string)$value);
  if (preg_match('/^Semana\s+(\d+)\s*(?:·|-)\s*(.+)$/u', $label, $matches) === 1) {
    return ['S' . $matches[1], str_replace(' a ', '–', $matches[2])];
  }
  return [$label !== '' ? $label : 'Sin semana', ''];
};
$costSemaforo = (array)($config['semaforo_costo_kg'] ?? []);
$costStatusKey = static function ($value) use ($costSemaforo): string {
  if (!is_numeric($value)) return 'gris';
  $number = (float)$value;
  if ($number < (float)($costSemaforo['verde_menor_que'] ?? 43)) return 'verde';
  if ($number <= (float)($costSemaforo['amarillo_hasta'] ?? 50)) return 'amarillo';
  return 'rojo';
};
$costRangeLabel = (string)($costSemaforo['leyenda'] ?? 'Verde < $43 · Amarillo $43–$50 · Rojo > $50');
$labParams = (array)($config['parametros_lab'] ?? []);
$processParams = (array)($config['parametros_proceso'] ?? []);
$recorteMaterialNames = ['DESBARBE', 'RECORTE', 'DESORILLE', 'GARRA', 'DELANTERO'];
$yieldStatusKey = static function (string $group, $value) use ($recorteMaterialNames): string {
  if (!is_numeric($value)) return 'gris';
  $number = (float)$value;
  if ($group === 'Carnaza') return $number > 15.5 ? 'verde' : ($number >= 15 ? 'amarillo' : 'rojo');
  if (strpos($group, 'Cuero Entero') === 0) return $number > 18 ? 'verde' : ($number >= 17 ? 'amarillo' : 'rojo');
  if (stripos($group, 'Pedacera') === 0 || in_array($group, $recorteMaterialNames, true)) {
    return $number > 14 ? 'verde' : ($number >= 13.5 ? 'amarillo' : 'rojo');
  }
  return $number >= 17 ? 'verde' : ($number >= 16 ? 'amarillo' : 'rojo');
};
$labRange = static fn(string $key): string => (string)($labParams[$key]['leyenda'] ?? '');
$labCell = static function ($value, array $status) use ($e, $fmt): string {
  $key = (string)($status['key'] ?? 'gris');
  if (!in_array($key, ['verde', 'amarillo', 'rojo'], true)) $key = 'gris';
  $title = trim((string)($status['label'] ?? 'Sin dato') . ' · ' . (string)($status['range'] ?? ''), ' ·');
  return '<td class="rp-lab-cell rp-state-' . $e($key) . '" title="' . $e($title) . '"><strong>' . $e($fmt($value)) . '</strong></td>';
};
$optionalLabCell = static function ($value, $status) use ($labCell, $e, $fmt): string {
  return is_array($status)
    ? $labCell($value, $status)
    : '<td title="Sin rango definido para este material">' . $e($fmt($value)) . '</td>';
};
$optionalPctCell = static function ($value, $status) use ($e, $fmtPct): string {
  $text = $value === null ? '—' : $fmtPct((float)$value * 100);
  if (!is_array($status)) return '<td title="Sin rango definido para este material">' . $e($text) . '</td>';
  $key = (string)($status['key'] ?? 'gris');
  if (!in_array($key, ['verde', 'amarillo', 'rojo'], true)) $key = 'gris';
  $title = trim((string)($status['label'] ?? 'Sin dato') . ' · ' . (string)($status['range'] ?? ''), ' ·');
  return '<td class="rp-lab-cell rp-state-' . $e($key) . '" title="' . $e($title) . '"><strong>' . $e($text) . '</strong></td>';
};
$rendimientoPtRangeLabels = [];
foreach ((array)($processParams['rendimiento_pt'] ?? []) as $materialName => $rule) {
  $displayMaterialName = str_replace('_', ' ', (string)$materialName);
  $rendimientoPtRangeLabels[] = $displayMaterialName . ': ' . (string)($rule['leyenda'] ?? '');
}
$rendimientoPtRangeTitle = implode(' · ', $rendimientoPtRangeLabels);
$enzymeRangeLabels = [];
foreach ((array)($processParams['extractibilidad_enzima'] ?? []) as $materialName => $rule) {
  $displayMaterialName = str_replace('_', ' ', (string)$materialName);
  $enzymeRangeLabels[] = $displayMaterialName . ': ' . (string)($rule['leyenda'] ?? '');
}
$enzymeRangeTitle = implode(' · ', $enzymeRangeLabels);

$titulo = (string)$report['titulo'];
$filtros = (array)$report['filtros'];
$opciones = (array)$report['opciones'];
$kpis = (array)$report['kpis'];
$graficas = (array)$report['graficas'];
$filas = (array)$report['filas'];
$providerMaterialTable = (array)($report['tabla_proveedor_material'] ?? []);
$providerMaterialGroups = (array)($providerMaterialTable['grupos'] ?? []);
$inventoryEntryTable = (array)($report['tabla_inventario_entrada'] ?? []);
$inventoryEntryRows = (array)($inventoryEntryTable['filas'] ?? []);
$inventoryEntryCriteria = (array)($inventoryEntryTable['criterios'] ?? []);
$meta = (array)$report['meta'];
$version = (int)$report['version'];
$capture = isset($_GET['capture']) && (string)$_GET['capture'] === '1';
$showSummary = !array_key_exists('mostrar_resumen', $config) || !empty($config['mostrar_resumen']);
$showCharts = !array_key_exists('mostrar_graficas', $config) || !empty($config['mostrar_graficas']);
$showInventoryEntryTable = !empty($config['mostrar_tabla_inventario_entrada']);
$periodMode = in_array((string)($filtros['periodo'] ?? 'mes'), ['mes', 'semana', 'fecha'], true)
  ? (string)$filtros['periodo']
  : 'mes';
$todayParams = array_filter([
  'periodo' => 'fecha',
  'fecha_inicio' => $filtros['hoy'] ?? date('Y-m-d'),
  'fecha_fin' => $filtros['hoy'] ?? date('Y-m-d'),
  'material' => ($filtros['material'] ?? 'all') !== 'all' ? $filtros['material'] : null,
  'proveedor' => $filtros['proveedor'] ?? null,
], static fn($value): bool => $value !== null && $value !== '');
$todayUrl = './?' . http_build_query($todayParams);

$materialChart = (array)($graficas['materiales'] ?? []);
$providerChart = (array)($graficas['proveedores'] ?? []);
$chartPalette = ['#0f766e', '#2563eb', '#7c3aed', '#d97706', '#dc2626', '#0891b2'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= $version ?>">
  <script src="../../assets/js/display-mode.js?v=<?= $version ?>"></script>
  <?php if ($showCharts): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script><?php endif; ?>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; background: #f3f6fa; color: #172033; font-family: Inter, sans-serif; }
    .rp-page { max-width: 1880px; margin: 0 auto; padding: 18px 22px 28px; }
    .rp-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 14px; }
    .rp-back { display: inline-flex; align-items: center; gap: 7px; color: #31516f; text-decoration: none; font-size: .82rem; font-weight: 700; margin-bottom: 8px; }
    .rp-title { margin: 0; font-size: clamp(1.65rem, 2.4vw, 2.35rem); color: #102a43; }
    .rp-subtitle { margin: 5px 0 0; color: #64748b; font-size: .9rem; font-weight: 500; }
    .rp-updated { color: #64748b; font-size: .75rem; font-weight: 600; white-space: nowrap; padding-top: 28px; }
    .rp-panel { background: #fff; border: 1px solid #dbe5ef; border-radius: 16px; box-shadow: 0 8px 24px rgba(15,23,42,.05); }
    .rp-filters { display: grid; grid-template-columns: auto minmax(390px, 1.25fr) minmax(190px, .9fr) minmax(220px, 1fr) auto; gap: 10px; padding: 12px; margin-bottom: 14px; align-items: end; }
    .rp-field label { display: block; margin: 0 0 5px; color: #52657a; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .rp-field input, .rp-field select { width: 100%; min-height: 40px; border: 1px solid #cbd8e6; border-radius: 10px; background: #fff; color: #172033; padding: 8px 10px; font: inherit; font-size: .84rem; }
    .rp-period-control > label, .rp-period-values > label { display: block; margin: 0 0 5px; color: #52657a; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .rp-period-switch { display: flex; height: 40px; padding: 3px; border: 1px solid #cbd8e6; border-radius: 10px; background: #edf3f8; }
    .rp-period-btn { border: 0; border-radius: 7px; padding: 0 12px; background: transparent; color: #52657a; font: inherit; font-size: .76rem; font-weight: 800; cursor: pointer; }
    .rp-period-btn.is-active { background: #0f766e; color: #fff; box-shadow: 0 2px 6px rgba(15,118,110,.2); }
    .rp-period-panel { display: none; align-items: end; gap: 8px; }
    .rp-period-panel.is-active { display: flex; }
    .rp-period-panel .rp-field { flex: 1; min-width: 0; }
    .rp-today { min-height: 40px; flex: 0 0 auto; padding: 0 12px; border: 1px solid #b8d8d3; border-radius: 10px; background: #edf8f6; color: #0f766e; text-decoration: none; font-size: .78rem; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
    .rp-actions { display: flex; gap: 8px; }
    .rp-filter-status { display: none; align-items: center; gap: 7px; color: #0f766e; font-size: .76rem; font-weight: 800; white-space: nowrap; }
    .rp-filter-status.is-visible { display: inline-flex; }
    .rp-filter-status i { animation: rp-spin .8s linear infinite; }
    .rp-filters.is-loading .rp-field, .rp-filters.is-loading .rp-btn { pointer-events: none; opacity: .72; }
    @keyframes rp-spin { to { transform: rotate(360deg); } }
    .rp-btn { min-height: 40px; border: 0; border-radius: 10px; padding: 0 15px; font: inherit; font-size: .82rem; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
    .rp-btn-primary { background: #0f766e; color: #fff; }
    .rp-btn-light { background: #edf3f8; color: #31516f; }
    .rp-alert { padding: 14px 16px; margin-bottom: 14px; border-radius: 12px; background: #fff1f2; border: 1px solid #fecdd3; color: #9f1239; font-weight: 700; }
    .rp-kpis { display: grid; grid-template-columns: repeat(8, minmax(130px, 1fr)); gap: 10px; margin-bottom: 14px; }
    .rp-kpi { padding: 13px 14px; min-height: 92px; position: relative; overflow: hidden; }
    .rp-kpi::before { content: ''; position: absolute; inset: 0 auto 0 0; width: 4px; background: #0f766e; }
    .rp-kpi-label { color: #64748b; font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .035em; }
    .rp-kpi-value { margin-top: 9px; color: #102a43; font-size: 1.32rem; font-weight: 800; white-space: nowrap; }
    .rp-kpi-note { margin-top: 4px; color: #8392a5; font-size: .67rem; }
    .rp-kpi-cost.rp-cost-state-verde { background: #2e8b57; color: #fff; border-color: #2e8b57; }
    .rp-kpi-cost.rp-cost-state-amarillo { background: #facc15; color: #111827; border-color: #facc15; }
    .rp-kpi-cost.rp-cost-state-rojo { background: #c94436; color: #fff; border-color: #c94436; }
    .rp-kpi-cost.rp-cost-state-gris { background: #94a3b8; color: #fff; border-color: #94a3b8; }
    .rp-kpi-cost.rp-cost-state-verde::before { background: #2e8b57; }
    .rp-kpi-cost.rp-cost-state-amarillo::before { background: #d6a900; }
    .rp-kpi-cost.rp-cost-state-rojo::before { background: #c94436; }
    .rp-kpi-cost.rp-cost-state-gris::before { background: #94a3b8; }
    .rp-kpi-cost .rp-kpi-label,
    .rp-kpi-cost .rp-kpi-value,
    .rp-kpi-cost .rp-kpi-note { color: inherit; }
    .rp-charts { display: grid; grid-template-columns: minmax(0, .75fr) minmax(0, 1fr) minmax(0, 1.65fr); gap: 12px; margin-bottom: 14px; align-items: stretch; }
    .rp-chart { min-width: 0; height: 315px; padding: 14px; overflow: hidden; }
    .rp-chart h2, .rp-table-head h2 { margin: 0; color: #17324d; font-size: 1rem; }
    .rp-chart p, .rp-table-head p { margin: 4px 0 10px; color: #718096; font-size: .73rem; }
    .rp-chart canvas { width: 100% !important; height: 250px !important; }
    .rp-chart-with-values { display: flex; flex-direction: column; }
    .rp-chart-with-values canvas { flex: 0 0 150px; height: 150px !important; min-height: 150px; }
    .rp-chart-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-content: start; gap: 6px; min-height: 0; margin-top: 8px; padding-right: 4px; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: thin; scrollbar-color: #94a3b8 #edf3f8; }
    .rp-chart-values::-webkit-scrollbar { width: 7px; }
    .rp-chart-values::-webkit-scrollbar-track { border-radius: 999px; background: #edf3f8; }
    .rp-chart-values::-webkit-scrollbar-thumb { border-radius: 999px; background: #94a3b8; }
    .rp-chart-value { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 7px; min-width: 0; padding: 6px 8px; border-radius: 9px; background: #f5f8fb; color: #52657a; font-size: .67rem; }
    .rp-chart-value-dot { width: 8px; height: 8px; border-radius: 50%; }
    .rp-chart-value span:nth-child(2) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rp-chart-value strong { color: #17324d; font-size: .72rem; white-space: nowrap; }
    .rp-provider-material-card { grid-column: auto; min-width: 0; height: 315px; padding: 11px; overflow: hidden; display: flex; flex-direction: column; }
    .rp-provider-material-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 7px; }
    .rp-provider-material-head h2 { margin: 0; color: #17324d; font-size: .9rem; }
    .rp-provider-material-head p { margin: 2px 0 0; color: #718096; font-size: .6rem; line-height: 1.2; }
    .rp-provider-material-count { flex: 0 0 auto; border-radius: 999px; padding: 4px 7px; background: #e7f3f1; color: #0f766e; font-size: .58rem; font-weight: 800; }
    .rp-cost-formula { margin-bottom: 7px; padding: 5px 7px; border: 1px solid #cce3df; border-radius: 8px; background: #f0fdfa; color: #31516f; font-size: .55rem; line-height: 1.25; }
    .rp-cost-formula strong { color: #0f766e; }
    .rp-provider-material-wrap { min-height: 0; flex: 1; overflow: auto; border: 1px solid #dbe5ef; border-radius: 8px; }
    .rp-provider-material-table { min-width: 800px; width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; font-size: .58rem; }
    .rp-provider-material-table th { position: sticky; top: 0; z-index: 3; padding: 5px 3px; background: #174d6b; color: #fff; text-align: center; white-space: nowrap; line-height: 1.1; }
    .rp-provider-material-table thead tr:nth-child(2) th { top: 27px; }
    .rp-provider-material-table .rp-period-group { padding: 7px 4px; letter-spacing: .03em; text-transform: uppercase; }
    .rp-provider-material-table .rp-period-monthly { background: #0f766e; }
    .rp-provider-material-table .rp-period-weekly { background: #31516f; }
    .rp-provider-material-table th small { display: inline; margin-left: 5px; }
    .rp-provider-material-table td { padding: 5px 4px; border-bottom: 1px solid #e5edf4; background: #fff; color: #24364b; text-align: right; vertical-align: middle; white-space: nowrap; line-height: 1.15; }
    .rp-provider-material-table tbody tr:nth-child(even) td { background: #f7fafc; }
    .rp-provider-material-table td:nth-child(-n+2) { text-align: left; }
    .rp-provider-material-table thead tr:nth-child(2) th { font-size: .5rem; letter-spacing: -.01em; }
    .rp-provider-material-table th:nth-child(-n+2),
    .rp-provider-material-table td:nth-child(-n+2) { padding-left: 3px; padding-right: 3px; font-size: .48rem; line-height: 1.08; }
    .rp-provider-material-table td:nth-child(-n+2) { white-space: normal; overflow-wrap: normal; word-break: normal; }
    .rp-provider-material-table td:nth-child(-n+2) strong { font-size: .48rem; font-weight: 800; }
    .rp-period-start { border-left: 3px solid #cbd5e1 !important; }
    .rp-price-total { color: #0f766e !important; font-weight: 900; }
    .rp-cost-cell { color: #174d6b !important; font-weight: 900; }
    .rp-cost-band-verde { background: #2e8b57 !important; color: #fff !important; }
    .rp-cost-band-amarillo { background: #facc15 !important; color: #111827 !important; }
    .rp-cost-band-rojo { background: #c94436 !important; color: #fff !important; }
    .rp-cost-band-gris { background: #94a3b8 !important; color: #fff !important; }
    .rp-provider-material-table td.rp-yield-cell,
    .rp-provider-material-table td.rp-cost-cell,
    .rp-week-state-cell .rp-week-stack > span { font-size: .90rem; font-weight: 900; }
    .rp-week-state-cell { position: relative; padding: 0 !important; overflow: hidden; }
    .rp-week-state-cell .rp-week-stack {
      position: absolute;
      inset: 0;
      display: grid;
      grid-auto-rows: minmax(0, 1fr);
      gap: 0;
      width: 100%;
      height: 100%;
    }
    .rp-week-state-cell .rp-week-stack > span {
      min-width: 0;
      min-height: 0;
      width: 100%;
      height: 100%;
      padding: 5px 4px;
      border-bottom: 1px solid rgba(255,255,255,.42);
      border-radius: 0;
      text-align: right;
    }
    .rp-week-state-cell .rp-week-stack > span:last-child { border-bottom: 0; }
    .rp-week-stack { display: grid; gap: 4px; }
    .rp-week-stack > span { display: flex; min-height: 23px; align-items: center; justify-content: flex-end; padding: 3px; border-bottom: 1px solid #e2e8f0; white-space: nowrap; line-height: 1.1; }
    .rp-week-label .rp-week-stack > span { flex-direction: column; justify-content: center; gap: 1px; text-align: center; font-size: .52rem; }
    .rp-week-label .rp-week-stack b { font-size: .54rem; line-height: 1; }
    .rp-week-label .rp-week-stack small { font-size: .46rem; line-height: 1; white-space: nowrap; }
    .rp-week-stack > span:last-child { border-bottom: 0; }
    .rp-week-stack .rp-yield-cell { width: 100%; box-sizing: border-box; justify-content: flex-end; }
    .rp-percent-sign { display: inline; margin: 0; padding: 0; font-family: Arial, sans-serif; font-size: 1.12em; font-weight: 900; line-height: 1; vertical-align: baseline; }
    .rp-yield-verde { background: #2e8b57 !important; color: #fff !important; }
    .rp-yield-amarillo { background: #facc15 !important; color: #111827 !important; }
    .rp-yield-rojo { background: #c94436 !important; color: #fff !important; }
    .rp-yield-gris { background: #94a3b8 !important; color: #fff !important; }
    .rp-table-panel { overflow: hidden; }
    .rp-inventory-panel { margin-bottom: 14px; }
    .rp-inventory-wrap { max-height: 430px; overflow: auto; }
    .rp-inventory-table { width: 100%; min-width: 1450px; font-size: .7rem; }
    .rp-inventory-table th, .rp-inventory-table td { padding: 8px 7px; }
    .rp-inventory-table td:nth-child(5), .rp-inventory-table td:nth-child(6),
    .rp-inventory-table td:last-child { text-align: left; }
    .rp-inventory-table td:last-child { max-width: 250px; overflow: hidden; text-overflow: ellipsis; }
    .rp-entry-status { display: inline-flex; align-items: center; gap: 6px; padding: 5px 8px; border-radius: 999px; font-size: .63rem; font-weight: 900; }
    .rp-entry-status::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: currentColor; opacity: .82; }
    .rp-table-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 14px 16px 10px; border-bottom: 1px solid #e4ebf3; }
    .rp-count { background: #e7f3f1; color: #0f766e; border-radius: 999px; padding: 5px 10px; font-size: .72rem; font-weight: 800; }
    .rp-table-tools, .rp-lab-legend, .rp-lab-legend span { display: flex; align-items: center; }
    .rp-table-tools { gap: 12px; }
    .rp-lab-legend { gap: 9px; color: #52657a; font-size: .66rem; font-weight: 700; white-space: nowrap; }
    .rp-lab-legend span { gap: 4px; }
    .rp-lab-dot { width: 8px; height: 8px; border-radius: 50%; }
    .rp-lab-dot.verde { background: #2e8b57; }.rp-lab-dot.amarillo { background: #facc15; }.rp-lab-dot.rojo { background: #c94436; }.rp-lab-dot.gris { background: #94a3b8; }
    .rp-table-wrap { overflow: auto; max-height: 610px; }
    table { border-collapse: separate; border-spacing: 0; min-width: 2150px; width: 100%; font-size: .72rem; }
    th { position: sticky; top: 0; z-index: 2; background: #174d6b; color: #fff; padding: 9px 8px; text-align: center; white-space: nowrap; }
    thead .rp-group-row th { top: 0; background: #0f344a; border-right: 1px solid rgba(255,255,255,.22); font-size: .66rem; letter-spacing: .045em; text-transform: uppercase; }
    thead .rp-group-row .rp-group-sticky { position: sticky; z-index: 8; }
    thead .rp-group-row .rp-group-process { left: 0; width: 185px; min-width: 185px; max-width: 185px; }
    thead .rp-group-row .rp-group-purchase { left: 185px; width: 500px; min-width: 500px; max-width: 500px; box-shadow: 7px 0 10px -8px rgba(15,23,42,.7); }
    thead .rp-column-row th { top: 31px; }
    td { padding: 8px; border-bottom: 1px solid #e5edf4; color: #24364b; background: #fff; white-space: nowrap; text-align: right; }
    tbody tr:nth-child(even) td { background: #f7fafc; }
    .rp-data-table thead .rp-column-row th:nth-child(-n+5), .rp-data-table tbody td:nth-child(-n+5) { position: sticky; background-clip: padding-box; }
    .rp-data-table thead .rp-column-row th:nth-child(-n+5) { z-index: 6; }
    .rp-data-table tbody td:nth-child(-n+5) { z-index: 1; }
    .rp-data-table thead .rp-column-row th:nth-child(1), .rp-data-table tbody td:nth-child(1) { left: 0; width: 80px; min-width: 80px; max-width: 80px; }
    .rp-data-table thead .rp-column-row th:nth-child(2), .rp-data-table tbody td:nth-child(2) { left: 80px; width: 105px; min-width: 105px; max-width: 105px; }
    .rp-data-table thead .rp-column-row th:nth-child(3), .rp-data-table tbody td:nth-child(3) { left: 185px; width: 220px; min-width: 220px; max-width: 220px; white-space: normal; line-height: 1.18; overflow-wrap: anywhere; }
    .rp-data-table thead .rp-column-row th:nth-child(4), .rp-data-table tbody td:nth-child(4) { left: 405px; width: 180px; min-width: 180px; max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
    .rp-data-table thead .rp-column-row th:nth-child(5), .rp-data-table tbody td:nth-child(5) { left: 585px; width: 100px; min-width: 100px; max-width: 100px; box-shadow: 7px 0 10px -8px rgba(15,23,42,.7); }
    .rp-data-table thead .rp-column-row th:nth-child(n+6):nth-child(-n+13),
    .rp-data-table tbody td:nth-child(n+6):nth-child(-n+13) { padding-left: 5px; padding-right: 5px; white-space: normal; line-height: 1.12; }
    .rp-data-table thead .rp-column-row th:nth-child(6), .rp-data-table tbody td:nth-child(6) { width: 90px; min-width: 90px; max-width: 90px; }
    .rp-data-table thead .rp-column-row th:nth-child(7), .rp-data-table tbody td:nth-child(7) { width: 90px; min-width: 90px; max-width: 90px; }
    .rp-data-table thead .rp-column-row th:nth-child(8), .rp-data-table tbody td:nth-child(8) { width: 95px; min-width: 95px; max-width: 95px; }
    .rp-data-table thead .rp-column-row th:nth-child(9), .rp-data-table tbody td:nth-child(9) { width: 85px; min-width: 85px; max-width: 85px; }
    .rp-data-table thead .rp-column-row th:nth-child(10), .rp-data-table tbody td:nth-child(10) { width: 95px; min-width: 95px; max-width: 95px; }
    .rp-data-table thead .rp-column-row th:nth-child(11), .rp-data-table tbody td:nth-child(11) { width: 90px; min-width: 90px; max-width: 90px; }
    .rp-data-table thead .rp-column-row th:nth-child(12), .rp-data-table tbody td:nth-child(12) { width: 80px; min-width: 80px; max-width: 80px; }
    .rp-data-table thead .rp-column-row th:nth-child(13), .rp-data-table tbody td:nth-child(13) { width: 120px; min-width: 120px; max-width: 120px; }
    td.rp-left { text-align: left; }
    td.rp-center { text-align: center; }
    .rp-risk { display: inline-flex; align-items: center; padding: 3px 7px; border-radius: 999px; font-size: .66rem; font-weight: 800; }
    .rp-risk-high { background: #fee2e2; color: #991b1b; }
    .rp-risk-medium { background: #fef3c7; color: #92400e; }
    .rp-risk-low { background: #dcfce7; color: #166534; }
    .rp-risk-none { background: #e5e7eb; color: #64748b; }
    th small { display: block; margin-top: 3px; color: #dbeafe; font-size: .59rem; font-weight: 600; text-transform: none; letter-spacing: 0; }
    td.rp-lab-cell { text-align: center; font-variant-numeric: tabular-nums; }
    .rp-state-verde { background: #2e8b57 !important; color: #fff !important; }
    .rp-state-amarillo { background: #facc15 !important; color: #111827 !important; }
    .rp-state-rojo { background: #c94436 !important; color: #fff !important; }
    .rp-state-gris { background: #94a3b8 !important; color: #fff !important; }
    .rp-empty { padding: 38px; text-align: center; color: #64748b; }
    @media (max-width: 1450px) { .rp-kpis { grid-template-columns: repeat(4, 1fr); } .rp-charts { grid-template-columns: 1fr 1fr; } .rp-provider-material-card { grid-column: 1 / -1; } }
    @media (max-width: 1100px) { .rp-filters { grid-template-columns: 1fr 1fr; } .rp-period-values, .rp-actions { grid-column: auto; } }
    @media (max-width: 900px) { .rp-filters { grid-template-columns: 1fr 1fr; } .rp-kpis { grid-template-columns: repeat(2, 1fr); } .rp-charts { grid-template-columns: 1fr; } .rp-provider-material-card { grid-column: auto; height: 390px; } .rp-updated { display: none; } .rp-table-head { align-items: flex-start; flex-direction: column; } .rp-table-tools, .rp-lab-legend { flex-wrap: wrap; } }
    @media (max-width: 560px) { .rp-page { padding: 12px; } .rp-filters, .rp-kpis { grid-template-columns: 1fr; } .rp-period-values, .rp-actions { grid-column: 1 / -1; } .rp-period-btn { flex: 1; padding: 0 7px; } .rp-period-panel { width: 100%; } }
    body.capture-mode .rp-page { max-width: 1920px; padding: 10px 14px; }
    body.capture-mode .rp-top { margin-bottom: 8px; }
    body.capture-mode .rp-filters { display: none; }
    body.capture-mode .rp-kpis { gap: 7px; margin-bottom: 8px; }
    body.capture-mode .rp-kpi { min-height: 74px; padding: 9px 10px; }
    body.capture-mode .rp-charts { gap: 8px; margin-bottom: 8px; }
    body.capture-mode .rp-chart { min-height: 260px; padding: 10px; }
    body.capture-mode .rp-chart canvas { height: 205px !important; }
    body.capture-mode .rp-chart-with-values canvas { flex-basis: 120px; height: 120px !important; min-height: 120px; }
    body.capture-mode .rp-provider-material-card { height: 260px; padding: 8px; }
    body.capture-mode .rp-table-wrap { max-height: 410px; }
    body.rp-table-only .rp-page { max-width: none; padding: 18px 16px 24px; }
    body.rp-table-only .rp-filters { margin-bottom: 12px; }
    body.rp-table-only .rp-table-head { padding: 16px 18px 12px; }
    body.rp-table-only .rp-table-head h2 { font-size: 1.15rem; }
    body.rp-table-only .rp-table-head p { font-size: .8rem; }
    body.rp-table-only .rp-count { font-size: .78rem; padding: 6px 11px; }
    body.rp-table-only .rp-lab-legend { font-size: .72rem; }
    body.rp-table-only .rp-table-wrap { max-height: calc(100vh - 285px); min-height: 560px; }
    body.rp-table-only .rp-data-table { min-width: 2350px; font-size: .8rem; }
    body.rp-table-only .rp-data-table th { padding: 11px 9px; }
    body.rp-table-only .rp-data-table td { padding: 10px 9px; }
    body.rp-table-only .rp-data-table thead .rp-group-row th { font-size: .72rem; }
    body.rp-table-only .rp-data-table th small { font-size: .63rem; }
    body.rp-table-only .rp-risk { font-size: .71rem; padding: 4px 8px; }
    @media (max-height: 760px) {
      body.rp-table-only .rp-table-wrap { min-height: 360px; max-height: calc(100vh - 260px); }
    }
  </style>
</head>
<body class="<?= trim(($capture ? 'capture-mode ' : '') . (!$showSummary && !$showCharts ? 'rp-table-only' : '')) ?>">
<main class="rp-page">
  <header class="rp-top">
    <div>
      <?php if (!array_key_exists('mostrar_regresar', $config) || !empty($config['mostrar_regresar'])): ?>
        <a class="rp-back" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Reportes</a>
      <?php endif; ?>
      <h1 class="rp-title"><?= $e($titulo) ?></h1>
      <p class="rp-subtitle"><?= $e($meta['periodo_label'] ?? '') ?> · <?= $e($meta['periodo_inicio'] ?? '') ?> al <?= $e($meta['periodo_fin'] ?? '') ?> · corte 07:00</p>
    </div>
    <div class="rp-updated">Actualizado: <?= $e($meta['generado_en'] ?? '—') ?></div>
  </header>

  <?php if ($loadError !== null): ?>
    <div class="rp-alert"><?= $e($loadError) ?></div>
  <?php endif; ?>

  <form class="rp-panel rp-filters" method="get" data-filter-form>
    <input type="hidden" id="periodo" name="periodo" value="<?= $e($periodMode) ?>">
    <div class="rp-period-control">
      <label>Periodo</label>
      <div class="rp-period-switch" role="group" aria-label="Seleccionar periodo">
        <button class="rp-period-btn <?= $periodMode === 'mes' ? 'is-active' : '' ?>" type="button" data-period-mode="mes">Mes</button>
        <button class="rp-period-btn <?= $periodMode === 'semana' ? 'is-active' : '' ?>" type="button" data-period-mode="semana">Semana</button>
        <button class="rp-period-btn <?= $periodMode === 'fecha' ? 'is-active' : '' ?>" type="button" data-period-mode="fecha">Fecha</button>
      </div>
    </div>
    <div class="rp-period-values">
      <label>Selección</label>
      <div class="rp-period-panel <?= $periodMode === 'mes' ? 'is-active' : '' ?>" data-period-panel="mes">
        <div class="rp-field">
          <label for="anio">Año</label>
          <select id="anio" name="anio" data-period-input="mes">
            <?php foreach ((array)($filtros['anios'] ?? []) as $anio): ?>
              <option value="<?= (int)$anio ?>" <?= (int)$anio === (int)($filtros['anio'] ?? 0) ? 'selected' : '' ?>><?= (int)$anio ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rp-field">
          <label for="mes">Mes</label>
          <select id="mes" name="mes" data-period-input="mes">
            <?php foreach ((array)($filtros['meses'] ?? []) as $monthNumber => $monthName): ?>
              <option value="<?= (int)$monthNumber ?>" <?= (int)$monthNumber === (int)($filtros['mes'] ?? 0) ? 'selected' : '' ?>><?= $e($monthName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="rp-period-panel <?= $periodMode === 'semana' ? 'is-active' : '' ?>" data-period-panel="semana">
        <div class="rp-field">
          <label for="semana_inicio">Semana inicio</label>
          <input id="semana_inicio" name="semana_inicio" type="week" value="<?= $e($filtros['semana_inicio'] ?? $filtros['semana'] ?? '') ?>" data-period-input="semana">
        </div>
        <div class="rp-field">
          <label for="semana_fin">Semana fin</label>
          <input id="semana_fin" name="semana_fin" type="week" value="<?= $e($filtros['semana_fin'] ?? $filtros['semana'] ?? '') ?>" data-period-input="semana">
        </div>
      </div>
      <div class="rp-period-panel <?= $periodMode === 'fecha' ? 'is-active' : '' ?>" data-period-panel="fecha">
        <a class="rp-today" href="<?= $e($todayUrl) ?>"><i class="fa-solid fa-bullseye"></i> Hoy</a>
        <div class="rp-field">
          <label for="fecha_inicio">Inicio</label>
          <input id="fecha_inicio" name="fecha_inicio" type="date" value="<?= $e($filtros['fecha_inicio'] ?? $filtros['fecha'] ?? '') ?>" data-period-input="fecha">
        </div>
        <div class="rp-field">
          <label for="fecha_fin">Fin</label>
          <input id="fecha_fin" name="fecha_fin" type="date" value="<?= $e($filtros['fecha_fin'] ?? $filtros['fecha'] ?? '') ?>" data-period-input="fecha">
        </div>
      </div>
    </div>
    <div class="rp-field">
      <label for="material">Material</label>
      <select id="material" name="material">
        <option value="all">Todos los materiales</option>
        <?php foreach ((array)($opciones['materiales'] ?? []) as $material): ?>
          <option value="<?= $e($material) ?>" <?= ($filtros['material'] ?? 'all') === $material ? 'selected' : '' ?>><?= $e($material) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rp-field">
      <label for="proveedor">Proveedor</label>
      <select id="proveedor" name="proveedor">
        <option value="">Todos los proveedores</option>
        <?php foreach ((array)($opciones['proveedores'] ?? []) as $provider): ?>
          <option value="<?= (int)$provider['prv_id'] ?>" <?= (int)($filtros['proveedor'] ?? 0) === (int)$provider['prv_id'] ? 'selected' : '' ?>><?= $e($provider['prv_nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rp-actions">
      <span class="rp-filter-status" role="status" aria-live="polite"><i class="fa-solid fa-spinner"></i> Actualizando</span>
      <noscript><button class="rp-btn rp-btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Aplicar</button></noscript>
      <a class="rp-btn rp-btn-light" href="./"><i class="fa-solid fa-rotate-left"></i> Limpiar</a>
    </div>
  </form>

  <?php if ($showSummary): ?>
  <section class="rp-kpis">
    <article class="rp-panel rp-kpi rp-kpi-cost rp-cost-state-<?= $e($costStatusKey($kpis['costo_kg_semanal'] ?? null)) ?>" title="<?= $e($costRangeLabel) ?>"><div class="rp-kpi-label">Costo kg semanal</div><div class="rp-kpi-value"><?= $fmtMoney($kpis['costo_kg_semanal'] ?? null) ?></div><div class="rp-kpi-note"><?= $e($costRangeLabel) ?></div></article>
    <article class="rp-panel rp-kpi rp-kpi-cost rp-cost-state-<?= $e($costStatusKey($kpis['costo_kg_mensual'] ?? null)) ?>" title="<?= $e($costRangeLabel) ?>"><div class="rp-kpi-label">Costo kg mensual</div><div class="rp-kpi-value"><?= $fmtMoney($kpis['costo_kg_mensual'] ?? null) ?></div><div class="rp-kpi-note"><?= $e($costRangeLabel) ?></div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Materia prima</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_mp_filtrada'] ?? null) ?></div><div class="rp-kpi-note">Material seleccionado</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Tarimas etiquetadas</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_producto_terminado'] ?? null) ?></div><div class="rp-kpi-note">Suma de tar_kilos</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">PT para rendimiento</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_producto_rendimiento'] ?? null) ?></div><div class="rp-kpi-note"><?= !empty($kpis['participacion_filtrada']) ? 'Asignado por participación MP' : 'Procesos cerrados + barredura' ?></div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Rendimiento PT</div><div class="rp-kpi-value"><?= $fmtPct($kpis['rendimiento_pt'] ?? null) ?></div><div class="rp-kpi-note"><?= !empty($kpis['participacion_filtrada']) ? 'Kg PT asignados / kg MP' : 'Base ' . $fmtKg($kpis['kg_producto_rendimiento'] ?? null) . ' cerradas + barredura' ?></div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Bloom promedio</div><div class="rp-kpi-value"><?= $fmt($kpis['bloom'] ?? null, 1) ?></div><div class="rp-kpi-note">Ponderado por tarimas</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Viscosidad promedio</div><div class="rp-kpi-value"><?= $fmt($kpis['viscosidad'] ?? null, 1) ?></div><div class="rp-kpi-note">Ponderada por tarimas</div></article>
  </section>
  <?php endif; ?>

  <?php if ($showCharts): ?>
  <section class="rp-charts">
    <article class="rp-panel rp-chart rp-chart-with-values">
      <h2>Materia prima</h2><p>Distribución de kilos por material.</p><canvas id="materialChart"></canvas>
      <div class="rp-chart-values">
        <?php foreach ($materialChart as $index => $item):
          $itemKg = (float)($item['kg'] ?? 0);
        ?>
          <div class="rp-chart-value">
            <span class="rp-chart-value-dot" style="background: <?= $e($chartPalette[$index % count($chartPalette)]) ?>"></span>
            <span><?= $e($item['label'] ?? 'Sin material') ?></span>
            <strong><?= $fmt($itemKg / 1000, 1) ?> t</strong>
          </div>
        <?php endforeach; ?>
      </div>
    </article>
    <article class="rp-panel rp-chart rp-provider-chart"><h2>Proveedores</h2><p>Kilos de MP y rendimiento</p><canvas id="providerChart"></canvas></article>
    <article class="rp-panel rp-provider-material-card">
      <div class="rp-provider-material-head">
        <div><h2>Proveedor por material</h2><p><?= (($filtros['material'] ?? 'all') !== 'all' || !empty($filtros['proveedor'])) ? 'Mes completo y todas sus semanas para el filtro seleccionado.' : 'Mes completo y semana seleccionada.' ?> Ordenado por costo/kg mensual.</p></div>
        <span class="rp-provider-material-count"><?= count($providerMaterialGroups) ?> combinaciones</span>
      </div>
      <div class="rp-cost-formula"><strong>Base:</strong> kg consumidos en proceso · <strong>Precio compra:</strong> precio base · <strong>Precio granja:</strong> compra + $1.50 para C/P o Con pelo, + $0.50 para Depilado/a · <strong>Costo/kg:</strong> precio granja ÷ rendimiento</div>
      <?php if (!empty($providerMaterialTable['error'])): ?>
        <div class="rp-empty"><?= $e($providerMaterialTable['error']) ?></div>
      <?php elseif ($providerMaterialGroups === []): ?>
        <div class="rp-empty">Sin información para el periodo seleccionado.</div>
      <?php else: ?>
        <div class="rp-provider-material-wrap">
          <table class="rp-provider-material-table">
            <colgroup>
              <col style="width:10%"><col style="width:14%">
              <col style="width:7.5%"><col style="width:7.5%"><col style="width:7.5%"><col style="width:8.5%">
              <col style="width:10.5%"><col style="width:7.5%"><col style="width:7.5%"><col style="width:7.5%"><col style="width:12%">
            </colgroup>
            <thead>
              <tr>
                <th rowspan="2">Proveedor</th><th rowspan="2">Material</th>
                <th colspan="4" class="rp-period-group rp-period-monthly">Mensual <small><?= $e(ucfirst((string)($providerMaterialTable['mes_nombre'] ?? '')) . ' ' . (string)($providerMaterialTable['anio'] ?? '')) ?></small></th>
                <th colspan="5" class="rp-period-group rp-period-weekly">Semanal</th>
              </tr>
              <tr>
                <th class="rp-period-start">P. compra</th><th>P. granja</th><th>Rend.</th><th>Costo/kg ↓</th>
                <th class="rp-period-start">Semana</th><th>P. compra</th><th>P. granja</th><th>Rend.</th><th>Costo/kg</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($providerMaterialGroups as $comparisonGroup):
                $identityRow = (array)($comparisonGroup['identidad'] ?? []);
                $monthlyRow = (array)($comparisonGroup['mensual'] ?? []);
                $weeklyRows = (array)($comparisonGroup['semanas'] ?? []);
                $monthlyYieldKey = $yieldStatusKey((string)($monthlyRow['grupo'] ?? ''), $monthlyRow['rendimiento'] ?? null);
              ?>
                <tr>
                  <td><strong><?= $e($identityRow['proveedor'] ?? 'Sin proveedor') ?></strong></td>
                  <td><strong><?= $e($identityRow['material'] ?? 'Sin material') ?></strong></td>
                  <td class="rp-price-total rp-period-start"><?= $fmtMoney($monthlyRow['precio_proveedor_base'] ?? null) ?></td>
                  <td class="rp-price-total"><?= $fmtMoney($monthlyRow['precio_proveedor'] ?? null) ?></td>
                  <td class="rp-yield-cell rp-yield-<?= $e($monthlyYieldKey) ?>"><?= $fmtTablePct($monthlyRow['rendimiento'] ?? null) ?></td>
                  <td class="rp-cost-cell rp-cost-band-<?= $e($costStatusKey($monthlyRow['costo_cuero_kg_produccion'] ?? null)) ?>" title="<?= $e($costRangeLabel) ?>"><?= $fmtMoney($monthlyRow['costo_cuero_kg_produccion'] ?? null) ?></td>
                  <td class="rp-week-label rp-period-start"><div class="rp-week-stack"><?php if ($weeklyRows === []): ?><span>Sin semana</span><?php else: ?><?php foreach ($weeklyRows as $weeklyRow): $weekLabelParts = $compactWeekLabel($weeklyRow['periodo_etiqueta'] ?? 'Sin semana'); ?><span><b><?= $e($weekLabelParts[0]) ?></b><?php if ($weekLabelParts[1] !== ''): ?><small><?= $e($weekLabelParts[1]) ?></small><?php endif; ?></span><?php endforeach; ?><?php endif; ?></div></td>
                  <td class="rp-price-total"><div class="rp-week-stack"><?php if ($weeklyRows === []): ?><span>-</span><?php else: ?><?php foreach ($weeklyRows as $weeklyRow): ?><span><?= $fmtMoney($weeklyRow['precio_proveedor_base'] ?? null) ?></span><?php endforeach; ?><?php endif; ?></div></td>
                  <td class="rp-price-total"><div class="rp-week-stack"><?php if ($weeklyRows === []): ?><span>-</span><?php else: ?><?php foreach ($weeklyRows as $weeklyRow): ?><span><?= $fmtMoney($weeklyRow['precio_proveedor'] ?? null) ?></span><?php endforeach; ?><?php endif; ?></div></td>
                  <td class="rp-week-state-cell"><div class="rp-week-stack"><?php if ($weeklyRows === []): ?><span class="rp-yield-gris">-</span><?php else: ?><?php foreach ($weeklyRows as $weeklyRow): $weeklyYieldKey = $yieldStatusKey((string)($weeklyRow['grupo'] ?? ''), $weeklyRow['rendimiento'] ?? null); ?><span class="rp-yield-cell rp-yield-<?= $e($weeklyYieldKey) ?>"><?= $fmtTablePct($weeklyRow['rendimiento'] ?? null) ?></span><?php endforeach; ?><?php endif; ?></div></td>
                  <td class="rp-cost-cell rp-week-state-cell"><div class="rp-week-stack"><?php if ($weeklyRows === []): ?><span class="rp-cost-band-gris">-</span><?php else: ?><?php foreach ($weeklyRows as $weeklyRow): ?><span class="rp-cost-band-<?= $e($costStatusKey($weeklyRow['costo_cuero_kg_produccion'] ?? null)) ?>" title="<?= $e($costRangeLabel) ?>"><?= $fmtMoney($weeklyRow['costo_cuero_kg_produccion'] ?? null) ?></span><?php endforeach; ?><?php endif; ?></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </article>
  </section>
  <?php endif; ?>

  <?php if ($showInventoryEntryTable): ?>
  <section class="rp-panel rp-table-panel rp-inventory-panel">
    <div class="rp-table-head">
      <div><h2>Resultados de entrada</h2><p>Una fila por registro de inventario dentro del periodo seleccionado.</p></div>
      <div class="rp-table-tools">
        <div class="rp-lab-legend" aria-label="Semáforo LAB"><span><i class="rp-lab-dot verde"></i>Objetivo</span><span><i class="rp-lab-dot amarillo"></i>Alerta</span><span><i class="rp-lab-dot rojo"></i>Fuera</span><span><i class="rp-lab-dot gris"></i>Sin dato</span></div>
        <span class="rp-count"><?= count($inventoryEntryRows) ?> registros</span>
      </div>
    </div>
    <div class="rp-inventory-wrap">
      <?php if (!empty($inventoryEntryTable['error'])): ?>
        <div class="rp-empty"><?= $e($inventoryEntryTable['error']) ?></div>
      <?php elseif ($inventoryEntryRows === []): ?>
        <div class="rp-empty">No hay entradas de inventario para los filtros seleccionados.</div>
      <?php else: ?>
        <table class="rp-inventory-table">
          <thead><tr>
            <th>No.</th><th>Ticket</th><th>Fecha</th><th>Kilos</th><th>Tipo de material</th><th>Proveedor</th>
            <th>Humedad<small><?= $e($inventoryEntryCriteria['humedad']['leyenda'] ?? '') ?></small></th>
            <th>Conductividad<small>Objetivo según material · máx. 20</small></th>
            <th>pH<small><?= $e($inventoryEntryCriteria['ph']['leyenda'] ?? '') ?></small></th>
            <th>Sólidos<small><?= $e($inventoryEntryCriteria['solidos']['leyenda'] ?? '') ?></small></th>
            <th>Extractibilidad<small><?= $e($inventoryEntryCriteria['extractibilidad']['leyenda'] ?? '') ?></small></th>
            <th>Rendimiento<small><?= $e($inventoryEntryCriteria['rendimiento']['leyenda'] ?? '') ?></small></th>
            <th>Semáforo</th><th>Observaciones</th>
          </tr></thead>
          <tbody>
          <?php foreach ($inventoryEntryRows as $inventoryEntry):
            $entryMetrics = (array)($inventoryEntry['metricas'] ?? []);
            $entryStatus = (array)($inventoryEntry['semaforo'] ?? []);
            $entryStatusKey = in_array((string)($entryStatus['key'] ?? ''), ['verde', 'amarillo', 'rojo'], true) ? (string)$entryStatus['key'] : 'gris';
          ?>
            <tr>
              <td><?= (int)($inventoryEntry['numero'] ?? 0) ?></td>
              <td><strong><?= (int)($inventoryEntry['ticket'] ?? 0) ?></strong></td>
              <td><?= $e($inventoryEntry['fecha'] ?? '—') ?></td>
              <td><strong><?= $fmt($inventoryEntry['kilos'] ?? null) ?></strong></td>
              <td><?= $e($inventoryEntry['material'] ?? '—') ?></td>
              <td><?= $e($inventoryEntry['proveedor'] ?? '—') ?></td>
              <?= $labCell($entryMetrics['humedad']['value'] ?? null, (array)($entryMetrics['humedad']['status'] ?? [])) ?>
              <?= $labCell($entryMetrics['conductividad']['value'] ?? null, (array)($entryMetrics['conductividad']['status'] ?? [])) ?>
              <?= $labCell($entryMetrics['ph']['value'] ?? null, (array)($entryMetrics['ph']['status'] ?? [])) ?>
              <?= $labCell($entryMetrics['solidos']['value'] ?? null, (array)($entryMetrics['solidos']['status'] ?? [])) ?>
              <?= $labCell($entryMetrics['extractibilidad']['value'] ?? null, (array)($entryMetrics['extractibilidad']['status'] ?? [])) ?>
              <?= $labCell($entryMetrics['rendimiento']['value'] ?? null, (array)($entryMetrics['rendimiento']['status'] ?? [])) ?>
              <td><span class="rp-entry-status rp-state-<?= $e($entryStatusKey) ?>"><?= $e($entryStatus['label'] ?? 'Pendiente') ?></span></td>
              <td title="<?= $e($inventoryEntry['observaciones'] ?? '') ?>"><?= $e(($inventoryEntry['observaciones'] ?? '') !== '' ? $inventoryEntry['observaciones'] : '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="rp-panel rp-table-panel">
    <div class="rp-table-head">
      <div>
        <h2>Detalle por proceso, material y proveedor</h2>
        <p>
          <?php if (!empty($config['incluir_todos_procesos'])): ?>
            Incluye procesos abiertos y cerrados de acuerdo con su fecha de carga.
          <?php elseif (($filtros['periodo'] ?? '') === 'semana'): ?>
            Periodo semanal seleccionado: <?= $e($meta['detalle_periodo_label'] ?? '') ?>.
          <?php else: ?>
            Los datos de proceso se repiten cuando un proceso tiene más de una combinación de material y proveedor.
          <?php endif; ?>
        </p>
      </div>
      <div class="rp-table-tools">
        <div class="rp-lab-legend" aria-label="Semáforo LAB">
          <span><i class="rp-lab-dot verde"></i>Objetivo</span>
          <span><i class="rp-lab-dot amarillo"></i>Alerta</span>
          <span><i class="rp-lab-dot rojo"></i>Fuera</span>
          <span><i class="rp-lab-dot gris"></i>Sin dato</span>
        </div>
        <span class="rp-count"><?= count($filas) ?> filas</span>
      </div>
    </div>
    <div class="rp-table-wrap">
      <?php if ($filas === []): ?>
        <div class="rp-empty">No hay información para los filtros seleccionados.</div>
      <?php else: ?>
        <table class="rp-data-table">
          <thead>
            <tr class="rp-group-row">
              <th class="rp-group-sticky rp-group-process" colspan="2">Proceso</th>
              <th class="rp-group-sticky rp-group-purchase" colspan="3">Compra</th>
              <th colspan="8">Granja y laboratorio</th><th colspan="1">Preparadores</th>
              <th colspan="8">Etapas y liberación</th><th colspan="5">Producto terminado</th>
            </tr>
            <tr class="rp-column-row">
              <th>Proceso</th><th>Fecha carga</th>
              <th>Material</th><th>Proveedor</th><th>Kg compra</th><th>Kilos granja</th><th>Rend. Granja</th><th>Rend. MP LAB<small><?= $e($labRange('rendimiento')) ?></small></th><th>Hum. MP LAB<small><?= $e($labRange('humedad')) ?></small></th><th>Extrac. MP LAB<small><?= $e($labRange('extractibilidad')) ?></small></th><th>Sólidos MP LAB<small><?= $e($labRange('solidos')) ?></small></th><th>pH MP LAB<small><?= $e($labRange('ph')) ?></small></th><th>Riesgo MP LAB</th>
              <th>Equipo inicial</th>
              <th title="<?= $e($enzymeRangeTitle) ?>">Extrac. enzima<small>Rango según material</small></th><th>Enzima kg</th><th>Horas enzima</th><th>Ácido lts</th><th>Normalidad</th><th>pH cocimiento</th><th>CE cocimiento</th><th>Extrac. final</th>
              <th>Tarimas</th><th>Kg PT asign.</th><th title="<?= $e($rendimientoPtRangeTitle) ?>">Rend. PT<small>Rango según material</small></th><th>Bloom</th><th>Viscosidad</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($filas as $row):
            $risk = (string)($row['inv_riesgo'] ?? '');
            $riskClass = strpos($risk, 'ALTO') === 0 ? 'rp-risk-high' : (strpos($risk, 'MEDIO') === 0 ? 'rp-risk-medium' : (strpos($risk, 'BAJO') === 0 ? 'rp-risk-low' : 'rp-risk-none'));
          ?>
            <tr>
              <td class="rp-center"><strong><?= (int)$row['pro_id'] ?></strong></td>
              <td class="rp-center"><?= $e($row['pro_fe_carga'] ?? '—') ?></td>
              <td class="rp-left"><?= $e($row['material'] ?? '—') ?></td>
              <td class="rp-left"><?= $e($row['proveedor'] ?? '—') ?></td>
              <td><?= $fmt($row['kg_mp_filtrada'] ?? null, 0) ?></td>
              <td><?= $fmt($row['kg_granja'] ?? null, 0) ?></td>
              <td><?= $row['rendimiento_granja'] === null ? '—' : $fmtPct((float)$row['rendimiento_granja']) ?></td>
              <?= $labCell($row['inv_rendimiento'] ?? null, (array)($row['semaforos_lab']['rendimiento'] ?? [])) ?>
              <?= $labCell($row['inv_humedad'] ?? null, (array)($row['semaforos_lab']['humedad'] ?? [])) ?>
              <?= $labCell($row['inv_extractibilidad'] ?? null, (array)($row['semaforos_lab']['extractibilidad'] ?? [])) ?>
              <?= $labCell($row['inv_solidos'] ?? null, (array)($row['semaforos_lab']['solidos'] ?? [])) ?>
              <?= $labCell($row['inv_ph'] ?? null, (array)($row['semaforos_lab']['ph'] ?? [])) ?>
              <td class="rp-center"><span class="rp-risk <?= $riskClass ?>"><?= $e($risk !== '' ? $risk : 'Sin dato') ?></span></td>
              <td class="rp-left"><?= $e($row['equipo_inicial'] ?? '—') ?></td>
              <?= $optionalLabCell($row['extractibilidad_enzima_2b'] ?? null, $row['semaforos_proceso']['extractibilidad_enzima'] ?? null) ?><td><?= $fmt($row['enzima_kg'] ?? null) ?></td><td><?= $fmt($row['horas_enzima'] ?? null) ?></td>
              <td><?= $fmt($row['acido_litros'] ?? null) ?></td><td><?= $fmt($row['acido_normalidad'] ?? null) ?></td>
              <td><?= $fmt($row['cocimiento_ph'] ?? null) ?></td><td><?= $fmt($row['cocimiento_ce'] ?? null) ?></td><td><?= $fmt($row['extractibilidad_final'] ?? null) ?></td>
              <td><?= $fmt($row['tarimas'] ?? null, 0) ?></td><td><?= $fmt($row['kg_producto_terminado'] ?? null, 0) ?></td>
              <?= $optionalPctCell($row['rendimiento_pt'] ?? null, $row['semaforos_proceso']['rendimiento_pt'] ?? null) ?>
              <td><?= $fmt($row['bloom_promedio'] ?? null, 1) ?></td><td><?= $fmt($row['viscosidad_promedio'] ?? null, 1) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
const filtersForm = document.querySelector('[data-filter-form]');
const filterStatus = document.querySelector('.rp-filter-status');
const periodInput = document.getElementById('periodo');
const initialWeekStart = document.getElementById('semana_inicio')?.value || '';
const initialWeekEnd = document.getElementById('semana_fin')?.value || '';
let filterSubmitTimer = null;
const submitFilters = () => {
  if (filterSubmitTimer !== null) window.clearTimeout(filterSubmitTimer);
  filterStatus?.classList.add('is-visible');
  filtersForm?.classList.add('is-loading');
  filtersForm?.submit();
};
const scheduleFilterSubmit = (delay = 450) => {
  if (filterSubmitTimer !== null) window.clearTimeout(filterSubmitTimer);
  filterStatus?.classList.add('is-visible');
  filterSubmitTimer = window.setTimeout(submitFilters, delay);
};
filtersForm?.addEventListener('submit', () => {
  filterStatus?.classList.add('is-visible');
  filtersForm.classList.add('is-loading');
});
document.querySelectorAll('[data-period-mode]').forEach(button => button.addEventListener('click', () => {
  const mode = button.dataset.periodMode;
  if (!mode || !periodInput) return;
  const wasActive = periodInput.value === mode;
  periodInput.value = mode;
  document.querySelectorAll('[data-period-mode]').forEach(item => item.classList.toggle('is-active', item === button));
  document.querySelectorAll('[data-period-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.periodPanel === mode));
  if (wasActive) return;
  scheduleFilterSubmit();
}));
if (filtersForm) {
  filtersForm.addEventListener('change', event => {
    if (!event.target.matches('input, select')) return;
    const inputMode = event.target.dataset.periodInput;
    if (inputMode && periodInput) periodInput.value = inputMode;
    if (inputMode === 'semana') {
      const startInput = document.getElementById('semana_inicio');
      const endInput = document.getElementById('semana_fin');
      if (event.target === startInput && endInput && initialWeekStart === initialWeekEnd && endInput.value === initialWeekEnd) {
        endInput.value = startInput.value;
      }
      const start = startInput?.value;
      const end = endInput?.value;
      if (!start || !end) return;
      scheduleFilterSubmit(650);
      return;
    }
    if (inputMode === 'fecha') {
      const start = document.getElementById('fecha_inicio')?.value;
      const end = document.getElementById('fecha_fin')?.value;
      if (!start || !end) return;
      scheduleFilterSubmit(650);
      return;
    }
    scheduleFilterSubmit();
  });
}

<?php if ($showCharts): ?>
const materialRows = <?= json_encode($materialChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const providerRows = <?= json_encode($providerChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const palette = ['#0f766e','#2563eb','#7c3aed','#d97706','#dc2626','#0891b2'];
const visiblePercentages = {
  id: 'visiblePercentages',
  afterDatasetsDraw(chart, args, options) {
    if (!options || options.display === false) return;
    const indexes = options.datasetIndexes || [0];
    const ctx = chart.ctx;
    ctx.save();
    ctx.font = '700 11px Inter';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    indexes.forEach(datasetIndex => {
      const dataset = chart.data.datasets[datasetIndex];
      const meta = chart.getDatasetMeta(datasetIndex);
      if (!dataset || meta.hidden) return;
      const total = (dataset.data || []).reduce((sum, value) => sum + (Number(value) || 0), 0);
      meta.data.forEach((element, index) => {
        const raw = Number(dataset.data[index]);
        if (!Number.isFinite(raw)) return;
        const value = options.mode === 'share' ? (total > 0 ? raw / total * 100 : 0) : raw;
        if (options.minValue !== undefined && value < options.minValue) return;
        const label = `${value.toFixed(options.decimals ?? 1)}%`;
        const position = element.tooltipPosition();
        const y = meta.type === 'bar' || meta.type === 'line' ? position.y - 11 : position.y;
        ctx.lineWidth = meta.type === 'doughnut' ? 2 : 3;
        ctx.strokeStyle = meta.type === 'doughnut' ? 'rgba(15,23,42,.82)' : 'rgba(255,255,255,.95)';
        ctx.strokeText(label, position.x, y);
        ctx.fillStyle = meta.type === 'doughnut' ? '#ffffff' : (options.color || '#17324d');
        ctx.fillText(label, position.x, y);
      });
    });
    ctx.restore();
  }
};
Chart.register(visiblePercentages);
Chart.defaults.font.family = 'Inter';
Chart.defaults.color = '#52657a';

new Chart(document.getElementById('materialChart'), {
  type: 'bar',
  data: { labels: materialRows.map(r => r.label), datasets: [{ label: 'MP (t)', data: materialRows.map(r => +(r.kg / 1000).toFixed(2)), backgroundColor: palette, borderRadius: 6 }] },
  options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grace: '15%', title: { display: true, text: 'Toneladas' } } }, plugins: { visiblePercentages: { mode: 'share', decimals: 1, color: '#17324d' }, legend: { display: false }, tooltip: { callbacks: { label: c => { const total = c.dataset.data.reduce((sum, value) => sum + Number(value || 0), 0); const percent = total > 0 ? Number(c.raw) / total * 100 : 0; return `${Number(c.raw).toLocaleString('es-MX')} t · ${percent.toFixed(1)}%`; } } } } }
});

new Chart(document.getElementById('providerChart'), {
  type: 'bar',
  data: {
    labels: providerRows.map(r => r.label),
    datasets: [
      { label: 'MP (t)', data: providerRows.map(r => +(r.kg / 1000).toFixed(2)), backgroundColor: '#7dd3c7', borderRadius: 5, yAxisID: 'y', order: 2 },
      { label: 'Rendimiento PT (%)', data: providerRows.map(r => r.rendimiento === null ? null : +r.rendimiento.toFixed(2)), type: 'line', borderColor: '#1d4ed8', backgroundColor: '#1d4ed8', borderWidth: 3, pointRadius: 6, pointHoverRadius: 9, pointHitRadius: 14, tension: .25, yAxisID: 'y1', order: 1 }
    ]
  },
  options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 55, minRotation: 35, font: { size: 9 } } }, y: { beginAtZero: true, title: { display: true, text: 'Toneladas' } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '%' } } }, plugins: { visiblePercentages: { datasetIndexes: [1], decimals: 1, color: '#1d4ed8' }, legend: { position: 'bottom' } } }
});
<?php endif; ?>


<?php if (($meta['intervalo_actualizacion_ms'] ?? 0) > 0 && !$capture): ?>
setTimeout(() => window.location.reload(), <?= (int)$meta['intervalo_actualizacion_ms'] ?>);
<?php endif; ?>
</script>
</body>
</html>
