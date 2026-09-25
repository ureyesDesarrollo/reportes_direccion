<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../shared/helpers.php';

$loadError = null;
try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  $loadError = 'No fue posible consultar la información del servidor 105.';
  $report = [
    'titulo' => (string)($config['titulo'] ?? 'Rendimiento por Proceso'),
    'filtros' => ['anio' => (int)date('Y'), 'mes' => (int)date('n'), 'mes_nombre' => '', 'anios' => [], 'meses' => [], 'material' => 'all', 'proveedor' => null],
    'opciones' => ['materiales' => [], 'proveedores' => []],
    'kpis' => [], 'graficas' => [], 'filas' => [], 'meta' => [], 'version' => time(),
  ];
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 2): string => is_numeric($value) ? n((float)$value, $decimals) : '—';
$fmtKg = static fn($value): string => is_numeric($value) ? n((float)$value, 0) . ' kg' : '—';
$fmtPct = static fn($value): string => is_numeric($value) ? n((float)$value, 2) . '%' : '—';

$titulo = (string)$report['titulo'];
$filtros = (array)$report['filtros'];
$opciones = (array)$report['opciones'];
$kpis = (array)$report['kpis'];
$graficas = (array)$report['graficas'];
$filas = (array)$report['filas'];
$meta = (array)$report['meta'];
$version = (int)$report['version'];
$capture = isset($_GET['capture']) && (string)$_GET['capture'] === '1';

$materialChart = (array)($graficas['materiales'] ?? []);
$providerChart = (array)($graficas['proveedores'] ?? []);
$processChart = (array)($graficas['procesos'] ?? []);
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
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
    .rp-filters { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)) auto; gap: 10px; padding: 12px; margin-bottom: 14px; align-items: end; }
    .rp-field label { display: block; margin: 0 0 5px; color: #52657a; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .04em; }
    .rp-field input, .rp-field select { width: 100%; min-height: 40px; border: 1px solid #cbd8e6; border-radius: 10px; background: #fff; color: #172033; padding: 8px 10px; font: inherit; font-size: .84rem; }
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
    .rp-charts { display: grid; grid-template-columns: .8fr 1.2fr 1.4fr; gap: 12px; margin-bottom: 14px; }
    .rp-chart { padding: 14px; min-height: 315px; }
    .rp-chart h2, .rp-table-head h2 { margin: 0; color: #17324d; font-size: 1rem; }
    .rp-chart p, .rp-table-head p { margin: 4px 0 10px; color: #718096; font-size: .73rem; }
    .rp-chart canvas { width: 100% !important; height: 250px !important; }
    .rp-chart-with-values canvas { height: 190px !important; }
    .rp-chart-values { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; margin-top: 8px; }
    .rp-chart-value { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 7px; min-width: 0; padding: 6px 8px; border-radius: 9px; background: #f5f8fb; color: #52657a; font-size: .67rem; }
    .rp-chart-value-dot { width: 8px; height: 8px; border-radius: 50%; }
    .rp-chart-value span:nth-child(2) { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rp-chart-value strong { color: #17324d; font-size: .72rem; white-space: nowrap; }
    .rp-table-panel { overflow: hidden; }
    .rp-table-head { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 14px 16px 10px; border-bottom: 1px solid #e4ebf3; }
    .rp-count { background: #e7f3f1; color: #0f766e; border-radius: 999px; padding: 5px 10px; font-size: .72rem; font-weight: 800; }
    .rp-table-wrap { overflow: auto; max-height: 610px; }
    table { border-collapse: separate; border-spacing: 0; min-width: 2150px; width: 100%; font-size: .72rem; }
    th { position: sticky; top: 0; z-index: 2; background: #174d6b; color: #fff; padding: 9px 8px; text-align: center; white-space: nowrap; }
    thead .rp-group-row th { top: 0; background: #0f344a; border-right: 1px solid rgba(255,255,255,.22); font-size: .66rem; letter-spacing: .045em; text-transform: uppercase; }
    thead .rp-column-row th { top: 31px; }
    td { padding: 8px; border-bottom: 1px solid #e5edf4; color: #24364b; background: #fff; white-space: nowrap; text-align: right; }
    tbody tr:nth-child(even) td { background: #f7fafc; }
    td.rp-left { text-align: left; }
    td.rp-center { text-align: center; }
    .rp-risk { display: inline-flex; align-items: center; padding: 3px 7px; border-radius: 999px; font-size: .66rem; font-weight: 800; }
    .rp-risk-high { background: #fee2e2; color: #991b1b; }
    .rp-risk-medium { background: #fef3c7; color: #92400e; }
    .rp-risk-low { background: #dcfce7; color: #166534; }
    .rp-risk-none { background: #e5e7eb; color: #64748b; }
    .rp-empty { padding: 38px; text-align: center; color: #64748b; }
    @media (max-width: 1450px) { .rp-kpis { grid-template-columns: repeat(4, 1fr); } .rp-charts { grid-template-columns: 1fr 1fr; } .rp-chart:last-child { grid-column: 1 / -1; } }
    @media (max-width: 900px) { .rp-filters { grid-template-columns: 1fr 1fr; } .rp-kpis { grid-template-columns: repeat(2, 1fr); } .rp-charts { grid-template-columns: 1fr; } .rp-chart:last-child { grid-column: auto; } .rp-updated { display: none; } }
    @media (max-width: 560px) { .rp-page { padding: 12px; } .rp-filters, .rp-kpis { grid-template-columns: 1fr; } .rp-actions { grid-column: 1 / -1; } }
    body.capture-mode .rp-page { max-width: 1920px; padding: 10px 14px; }
    body.capture-mode .rp-top { margin-bottom: 8px; }
    body.capture-mode .rp-filters { display: none; }
    body.capture-mode .rp-kpis { gap: 7px; margin-bottom: 8px; }
    body.capture-mode .rp-kpi { min-height: 74px; padding: 9px 10px; }
    body.capture-mode .rp-charts { gap: 8px; margin-bottom: 8px; }
    body.capture-mode .rp-chart { min-height: 260px; padding: 10px; }
    body.capture-mode .rp-chart canvas { height: 205px !important; }
    body.capture-mode .rp-table-wrap { max-height: 410px; }
  </style>
</head>
<body class="<?= $capture ? 'capture-mode' : '' ?>">
<main class="rp-page">
  <header class="rp-top">
    <div>
      <a class="rp-back" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Reportes</a>
      <h1 class="rp-title"><?= $e($titulo) ?></h1>
      <p class="rp-subtitle"><?= $e(($filtros['mes_nombre'] ?? '') . ' ' . ($filtros['anio'] ?? '')) ?> · <?= $e($meta['periodo_inicio'] ?? '') ?> al <?= $e($meta['periodo_fin'] ?? '') ?></p>
    </div>
    <div class="rp-updated">Actualizado: <?= $e($meta['generado_en'] ?? '—') ?></div>
  </header>

  <?php if ($loadError !== null): ?>
    <div class="rp-alert"><?= $e($loadError) ?></div>
  <?php endif; ?>

  <form class="rp-panel rp-filters" method="get">
    <div class="rp-field">
      <label for="anio">Año</label>
      <select id="anio" name="anio">
        <?php foreach ((array)($filtros['anios'] ?? []) as $anio): ?>
          <option value="<?= (int)$anio ?>" <?= (int)$anio === (int)($filtros['anio'] ?? 0) ? 'selected' : '' ?>><?= (int)$anio ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="rp-field">
      <label for="mes">Mes</label>
      <select id="mes" name="mes">
        <?php foreach ((array)($filtros['meses'] ?? []) as $monthNumber => $monthName): ?>
          <option value="<?= (int)$monthNumber ?>" <?= (int)$monthNumber === (int)($filtros['mes'] ?? 0) ? 'selected' : '' ?>><?= $e($monthName) ?></option>
        <?php endforeach; ?>
      </select>
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

  <section class="rp-kpis">
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Procesos</div><div class="rp-kpi-value"><?= $fmt($kpis['procesos'] ?? null, 0) ?></div><div class="rp-kpi-note">Cerrados asignados al periodo</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Materia prima</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_mp_filtrada'] ?? null) ?></div><div class="rp-kpi-note">Material seleccionado</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Riesgos altos MP</div><div class="rp-kpi-value"><?= $fmt($kpis['riesgos_altos'] ?? null, 0) ?></div><div class="rp-kpi-note">Análisis de inventario</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Tarimas etiquetadas</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_producto_terminado'] ?? null) ?></div><div class="rp-kpi-note">Suma de tar_kilos</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">PT para rendimiento</div><div class="rp-kpi-value"><?= $fmtKg($kpis['kg_producto_rendimiento'] ?? null) ?></div><div class="rp-kpi-note"><?= !empty($kpis['participacion_filtrada']) ? 'Asignado por participación MP' : 'Procesos cerrados + barredura' ?></div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Rendimiento PT</div><div class="rp-kpi-value"><?= $fmtPct($kpis['rendimiento_pt'] ?? null) ?></div><div class="rp-kpi-note"><?= !empty($kpis['participacion_filtrada']) ? 'Kg PT asignados / kg MP' : 'Base ' . $fmtKg($kpis['kg_producto_rendimiento'] ?? null) . ' cerradas + barredura' ?></div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Bloom promedio</div><div class="rp-kpi-value"><?= $fmt($kpis['bloom'] ?? null, 1) ?></div><div class="rp-kpi-note">Ponderado por tarimas</div></article>
    <article class="rp-panel rp-kpi"><div class="rp-kpi-label">Viscosidad promedio</div><div class="rp-kpi-value"><?= $fmt($kpis['viscosidad'] ?? null, 1) ?></div><div class="rp-kpi-note">Ponderada por tarimas</div></article>
  </section>

  <section class="rp-charts">
    <article class="rp-panel rp-chart rp-chart-with-values">
      <h2>Materia prima</h2><p>Distribución de kilos por familia.</p><canvas id="materialChart"></canvas>
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
    <article class="rp-panel rp-chart"><h2>Proveedores</h2><p>Kilos de MP y rendimiento</p><canvas id="providerChart"></canvas></article>
    <article class="rp-panel rp-chart"><h2>Rendimiento reciente</h2><p>Últimos procesos con producto terminado.</p><canvas id="processChart"></canvas></article>
  </section>

  <section class="rp-panel rp-table-panel">
    <div class="rp-table-head">
      <div><h2>Detalle por proceso, material y proveedor</h2><p>Los datos de proceso se repiten cuando un proceso tiene más de una combinación de material y proveedor.</p></div>
      <span class="rp-count"><?= count($filas) ?> filas</span>
    </div>
    <div class="rp-table-wrap">
      <?php if ($filas === []): ?>
        <div class="rp-empty">No hay información para los filtros seleccionados.</div>
      <?php else: ?>
        <table>
          <thead>
            <tr class="rp-group-row">
              <th colspan="2">Proceso</th><th colspan="11">Compra e inventario</th><th colspan="1">Preparadores</th>
              <th colspan="8">Etapas y liberación</th><th colspan="5">Producto terminado</th>
            </tr>
            <tr class="rp-column-row">
              <th>Proceso</th><th>Fecha carga</th>
              <th>Material</th><th>Proveedor</th><th>Kg MP</th><th>No. ticket</th><th>Hum. MP</th><th>Extrac. MP</th><th>Sólidos MP</th><th>pH MP</th><th>Rend. MP</th><th>Riesgo MP</th><th>Rend. maquila</th>
              <th>Equipo inicial</th>
              <th>Extrac. enzima</th><th>Enzima kg</th><th>Horas enzima</th><th>Ácido lts</th><th>Normalidad</th><th>pH cocimiento</th><th>CE cocimiento</th><th>Extrac. final</th>
              <th>Tarimas</th><th>Kg PT asign.</th><th>Rend. PT</th><th>Bloom</th><th>Viscosidad</th>
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
              <td><?= $fmt($row['kg_mp_filtrada'] ?? null, 0) ?></td><td class="rp-left"><?= $e(($row['tickets'] ?? '') !== '' ? $row['tickets'] : '—') ?></td>
              <td><?= $fmt($row['inv_humedad'] ?? null) ?></td><td><?= $fmt($row['inv_extractibilidad'] ?? null) ?></td><td><?= $fmt($row['inv_solidos'] ?? null) ?></td>
              <td><?= $fmt($row['inv_ph'] ?? null) ?></td><td><?= $fmt($row['inv_rendimiento'] ?? null) ?></td>
              <td class="rp-center"><span class="rp-risk <?= $riskClass ?>"><?= $e($risk !== '' ? $risk : 'Sin dato') ?></span></td>
              <td><?= $row['rendimiento_maquila'] === null ? '—' : $fmtPct((float)$row['rendimiento_maquila'] * 100) ?></td>
              <td class="rp-left"><?= $e($row['equipo_inicial'] ?? '—') ?></td>
              <td><?= $fmt($row['extractibilidad_enzima_2b'] ?? null) ?></td><td><?= $fmt($row['enzima_kg'] ?? null) ?></td><td><?= $fmt($row['horas_enzima'] ?? null) ?></td>
              <td><?= $fmt($row['acido_litros'] ?? null) ?></td><td><?= $fmt($row['acido_normalidad'] ?? null) ?></td>
              <td><?= $fmt($row['cocimiento_ph'] ?? null) ?></td><td><?= $fmt($row['cocimiento_ce'] ?? null) ?></td><td><?= $fmt($row['extractibilidad_final'] ?? null) ?></td>
              <td><?= $fmt($row['tarimas'] ?? null, 0) ?></td><td><?= $fmt($row['kg_producto_terminado'] ?? null, 0) ?></td>
              <td><?= $row['rendimiento_pt'] === null ? '—' : $fmtPct((float)$row['rendimiento_pt'] * 100) ?></td>
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
const filtersForm = document.querySelector('.rp-filters');
const filterStatus = document.querySelector('.rp-filter-status');
if (filtersForm) {
  filtersForm.addEventListener('change', event => {
    if (!event.target.matches('input, select')) return;
    filterStatus?.classList.add('is-visible');
    filtersForm.classList.add('is-loading');
    filtersForm.submit();
  });
}

const materialRows = <?= json_encode($materialChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const providerRows = <?= json_encode($providerChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const processRows = <?= json_encode($processChart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
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
  options: { responsive: true, maintainAspectRatio: false, scales: { x: { ticks: { maxRotation: 45, minRotation: 25 } }, y: { beginAtZero: true, title: { display: true, text: 'Toneladas' } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: '%' } } }, plugins: { visiblePercentages: { datasetIndexes: [1], decimals: 1, color: '#1d4ed8' }, legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('processChart'), {
  type: 'bar',
  data: { labels: processRows.map(r => `P${r.proceso}`), datasets: [{ label: 'Rendimiento PT (%)', data: processRows.map(r => +r.rendimiento.toFixed(2)), backgroundColor: '#0f766e', borderRadius: 5 }] },
  options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grace: '12%', title: { display: true, text: '%' } } }, plugins: { visiblePercentages: { decimals: 1, color: '#0f766e' }, legend: { display: false } } }
});

<?php if (($meta['intervalo_actualizacion_ms'] ?? 0) > 0 && !$capture): ?>
setTimeout(() => window.location.reload(), <?= (int)$meta['intervalo_actualizacion_ms'] ?>);
<?php endif; ?>
</script>
</body>
</html>
