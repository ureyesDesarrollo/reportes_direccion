<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
extract($report, EXTR_SKIP);

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static function ($value, int $decimals = 2): string {
  if (!is_numeric($value)) return '—';
  $decimals = max(0, min(2, $decimals));
  return number_format((float)$value, $decimals, '.', ',');
};
$fmtMoney = static function ($value, int $decimals = 2): string {
  if (!is_numeric($value)) return '—';
  $decimals = max(0, min(2, $decimals));
  return '$' . number_format((float)$value, $decimals, '.', ',');
};
$fmtPct = static function ($value): string {
  if (!is_numeric($value)) return '—';
  return ((float)$value > 0 ? '+' : '') . number_format((float)$value, 1, '.', ',') . '%';
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($titulo) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= (int)$version ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root { color-scheme: light; --bg: #edf2f7; --ink: #0f172a; --muted: #64748b; --line: #d8e0ea; }
    * { box-sizing: border-box; }
    body { margin: 0; min-width: 1180px; color: var(--ink); background: var(--bg); font-family: Inter, Arial, sans-serif; }
    .purchases-shell { width: min(1900px, calc(100vw - 24px)); margin: 0 auto; padding: 12px 0 24px; }
    .purchases-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 10px; }
    .purchases-title-wrap { display: flex; align-items: center; gap: 12px; }
    .production-header-card { display: inline-flex; align-items: center; gap: 9px; padding: 7px 12px; border: 1px solid #cfe0fb; border-radius: 12px; color: #0f172a; background: #fff; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); }
    .production-header-card i { display: grid; place-items: center; width: 27px; height: 27px; border-radius: 8px; color: #fff; background: #2563eb; }
    .production-header-label { color: #64748b; font-size: 8px; font-weight: 900; letter-spacing: .05em; text-transform: uppercase; }
    .production-header-value { margin-top: 2px; font-size: 16px; font-weight: 900; line-height: 1; }
    .report-filters { display: inline-flex; align-items: center; gap: 4px; padding: 4px; border: 1px solid #d8e0ea; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(15, 23, 42, .05); }
    .report-filter { display: inline-flex; align-items: center; gap: 6px; padding: 8px 13px; border: 0; border-radius: 9px; color: #475569; background: transparent; font: inherit; font-size: 11px; font-weight: 900; cursor: pointer; }
    .report-filter.is-active { color: #fff; background: #0f172a; }
    .back-link { display: inline-flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 9px; color: #334155; background: #fff; font-size: 13px; font-weight: 800; text-decoration: none; }
    h1 { margin: 0; font-size: clamp(28px, 3vw, 42px); font-weight: 900; }
    .updated { color: var(--muted); font-size: 13px; font-weight: 700; }
    .warning { margin-bottom: 8px; padding: 8px 12px; border: 1px solid #f2c66d; border-radius: 9px; color: #854d0e; background: #fff7d6; font-size: 12px; font-weight: 800; }
    .quadrant-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; align-items: stretch; }
    .purchase-quadrant { --accent: #0f766e; --status: #94a3b8; min-width: 0; padding: 10px; border: 1px solid #d8e0ea; border-top: 4px solid var(--accent); border-radius: 18px; background: #fff; box-shadow: 0 8px 22px rgba(15, 23, 42, .07); }
    .quadrant-header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding-bottom: 7px; border-bottom: 1px solid var(--line); }
    .quadrant-title { display: flex; align-items: center; gap: 8px; min-width: 0; }
    .quadrant-icon { display: inline-flex; align-items: center; justify-content: center; width: 31px; height: 31px; border-radius: 9px; color: #fff; background: var(--accent); }
    .quadrant-title h2 { margin: 0; font-size: 20px; font-weight: 900; text-transform: uppercase; }
    .detail-link { flex: 0 0 auto; color: var(--accent); font-size: 11px; font-weight: 900; text-decoration: none; }
    .quadrant-actions { display: flex; align-items: center; gap: 8px; }
    .source-status { display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border: 1px solid color-mix(in srgb, var(--status) 45%, #fff); border-radius: 999px; color: #334155; background: color-mix(in srgb, var(--status) 12%, #fff); font-size: 9px; font-weight: 900; text-transform: uppercase; }
    .source-status::before { width: 8px; height: 8px; border-radius: 50%; background: var(--status); content: ''; }
    .source-error { min-height: 390px; display: grid; place-items: center; color: #991b1b; font-weight: 800; text-align: center; }
    .kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin: 8px 0 0; }
    .kpi-card { min-width: 0; min-height: 0; padding: 8px; border: 1px solid var(--line); border-radius: 12px; background: #fff; box-shadow: 0 1px 3px rgba(15, 23, 42, .05); }
    .kpi-card::before { height: 3px; opacity: 1; background: var(--kpi-color, var(--accent)); }
    .kpi-icon { display: inline-flex; align-items: center; justify-content: center; width: 23px; height: 23px; margin: 0 0 5px; border-radius: 7px; color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, #fff); font-size: 12px; }
    .kpi-label { min-height: 27px; color: var(--muted); font-size: 9px; font-weight: 900; line-height: 1.15; text-transform: uppercase; }
    .kpi-value { margin-top: 3px; overflow: hidden; font-size: clamp(17px, 1.55vw, 25px); font-weight: 900; line-height: 1; text-overflow: ellipsis; white-space: nowrap; }
    .kpi-meta { margin-top: 4px; overflow: hidden; color: var(--muted); font-size: 8px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
    .kpi-card.semaforo .kpi-value { color: var(--status); }
    .chemical-kpi-layout { display: grid; grid-template-columns: minmax(0, 2fr) minmax(190px, .72fr); gap: 6px; margin-top: 8px; }
    .dryer-metric-card { min-width: 0; min-height: 112px; display: grid; grid-template-columns: minmax(145px, .72fr) minmax(0, 1.28fr); overflow: hidden; border: 1px solid #d9e0ea; border-radius: 12px; background: #fff; }
    .dryer-metric-status { min-width: 0; padding: 10px 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 7px; color: #fff; background: var(--dryer-status, #2563eb); text-align: center; }
    .dryer-metric-status.is-yellow { color: #111827; }
    .dryer-metric-label { font-size: 11px; font-weight: 900; line-height: 1.15; letter-spacing: .04em; text-transform: uppercase; }
    .dryer-metric-value { font-size: clamp(21px, 1.7vw, 29px); font-weight: 900; line-height: 1; white-space: nowrap; }
    .dryer-metric-change { font-size: 9px; font-weight: 800; opacity: .9; }
    .dryer-metric-base { display: inline-flex; align-items: baseline; gap: 5px; padding: 5px 8px; border: 1px solid rgba(255,255,255,.52); border-radius: 8px; background: rgba(255,255,255,.16); font-size: 9px; font-weight: 800; }
    .dryer-metric-base strong { font-size: 12px; font-weight: 900; }
    .dryer-metric-status.is-yellow .dryer-metric-base { border-color: rgba(17,24,39,.28); background: rgba(255,255,255,.34); }
    .dryer-metric-ranges { min-width: 0; display: grid; align-content: center; padding: 11px 13px; color: #111827; background: #fff; }
    .dryer-range-list { display: grid; gap: 8px; margin: 0; padding: 0; list-style: none; }
    .dryer-range-item { display: grid; grid-template-columns: 9px minmax(108px, auto) minmax(0, 1fr); align-items: center; gap: 7px; font-size: 11px; font-weight: 800; line-height: 1.15; }
    .dryer-range-dot { width: 9px; height: 9px; border-radius: 50%; }
    .dryer-range-dot.green { background: #2e8b57; }
    .dryer-range-dot.yellow { background: #facc15; }
    .dryer-range-dot.red { background: #c94436; }
    .dryer-range-name { text-transform: uppercase; }
    .dryer-range-value { text-align: right; overflow-wrap: anywhere; }
    .chemical-support-card { min-width: 0; min-height: 112px; display: flex; flex-direction: column; justify-content: center; padding: 10px; border: 1px solid var(--line); border-top: 3px solid var(--accent); border-radius: 12px; background: #fff; }
    .chemical-support-card .kpi-icon { margin-bottom: 7px; }
    .tops-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; margin-top: 7px; }
    .top-panel { min-width: 0; padding: 7px; border: 1px solid var(--line); border-radius: 10px; }
    .top-panel h3 { display: flex; align-items: center; gap: 5px; margin: 0 0 4px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .top-panel.increases h3 { color: #b91c1c; }
    .top-panel.decreases h3 { color: #15803d; }
    .price-list { margin: 0; padding: 0; list-style: none; }
    .price-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 5px; align-items: center; min-height: 21px; border-top: 1px solid #eef2f7; }
    .price-row:first-child { border-top: 0; }
    .price-name { overflow: hidden; font-size: 9px; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
    .price-values { color: var(--muted); font-size: 8px; font-weight: 700; white-space: nowrap; }
    .price-change { margin-left: 3px; font-size: 9px; font-weight: 900; }
    .increases .price-change { color: #dc2626; }
    .decreases .price-change { color: #15803d; }
    .empty-list { min-height: 42px; display: grid; place-items: center; color: #94a3b8; font-size: 9px; font-weight: 700; }
    .frequency-panel { margin-top: 7px; padding: 8px; border: 1px solid var(--line); border-radius: 10px; background: #f8fafc; }
    .frequency-panel h3 { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; color: #334155; font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .frequency-panel h3 i { color: var(--accent); }
    .frequency-list { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 5px; }
    .frequency-row { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 3px 8px; padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
    .frequency-name { overflow: hidden; color: #334155; font-size: 9px; font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .frequency-rate { color: var(--accent); font-size: 10px; font-weight: 900; white-space: nowrap; }
    .frequency-meta { grid-column: 1/-1; color: #64748b; font-size: 8px; font-weight: 700; }
    .trend-panel { margin-top: 7px; padding: 7px; border: 1px solid var(--line); border-radius: 10px; }
    .trend-header { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 3px; }
    .trend-title { font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .trend-heading { display: flex; align-items: center; gap: 9px; min-width: 0; }
    .traffic-legend { display: inline-flex; gap: 7px; color: #64748b; font-size: 8px; font-weight: 800; }
    .traffic-legend span { display: inline-flex; align-items: center; gap: 3px; }
    .traffic-legend i { width: 7px; height: 7px; border-radius: 50%; }
    .trend-metric { color: var(--accent); font-size: 9px; font-weight: 900; white-space: nowrap; }
    .chart-wrap { position: relative; height: 128px; }
    @media (max-width: 1250px) { .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 1050px) { body { min-width: 0; } .quadrant-grid { grid-template-columns: 1fr; } .purchases-shell { width: min(760px, calc(100vw - 16px)); } }
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body>
  <main class="purchases-shell">
    <header class="purchases-header">
      <div class="purchases-title-wrap">
        <a class="back-link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Reportes</a>
        <h1><?= $e($titulo) ?></h1>
      </div>
      <div class="production-header-card" aria-label="Producción acumulada anual">
        <i class="fa-solid fa-industry"></i>
        <div><div class="production-header-label">Producción acumulada anual</div><div class="production-header-value"><?= $e($fmt($produccion_acumulada ?? null, 0)) ?></div></div>
      </div>
      <div class="report-filters" role="group" aria-label="Filtro general del reporte">
        <button class="report-filter is-active" type="button" data-report-mode="costo_produccion"><i class="fa-solid fa-industry"></i> Costo / producción</button>
        <button class="report-filter" type="button" data-report-mode="consumo"><i class="fa-solid fa-flask"></i> Consumo / producción</button>
      </div>
      <div class="updated">Actualizado <?= $e($actualizado) ?></div>
    </header>

    <?php foreach ((array)$warnings as $warning): ?>
      <div class="warning"><?= $e($warning) ?></div>
    <?php endforeach; ?>

    <section class="quadrant-grid" aria-label="Resumen general de compras">
      <?php foreach ((array)$cuadrantes as $quadrant): ?>
        <?php
          $key = (string)($quadrant['key'] ?? 'panel');
          $usesProduction = !empty($quadrant['usa_produccion']);
          $tops = (array)($quadrant['tops'] ?? []);
          $fixedMode = (string)($quadrant['modo_fijo'] ?? '');
          $isConsumption = $fixedMode === 'consumo';
          $isFixedCost = $fixedMode === 'costo_produccion';
          $initialMode = $fixedMode !== '' ? $fixedMode : 'costo_produccion';
          $initialSummary = (array)($initialMode === 'consumo' ? ($quadrant['resumen_consumo'] ?? []) : ($quadrant['resumen_costo'] ?? []));
          $metricBase = is_numeric($initialSummary['base'] ?? null) ? (float)$initialSummary['base'] : null;
          $metricYellow = is_numeric($initialSummary['limite_amarillo'] ?? null) ? (float)$initialSummary['limite_amarillo'] : null;
          $initialIsMoney = !empty($initialSummary['es_dinero']);
          $statusIsYellow = strtolower((string)($initialSummary['color'] ?? '')) === 'amarillo';
          $dryerPalette = ['verde' => '#2e8b57', 'amarillo' => '#facc15', 'rojo' => '#c94436'];
          $dryerStatusColor = $dryerPalette[strtolower((string)($initialSummary['color'] ?? 'gris'))] ?? '#94a3b8';
        ?>
        <article class="purchase-quadrant" style="--accent: <?= $e($quadrant['color'] ?? '#0f766e') ?>; --status: <?= $e($initialSummary['color_hex'] ?? $quadrant['color_global_hex'] ?? '#94a3b8') ?>" data-quadrant="<?= $e($key) ?>" data-fixed-mode="<?= $e($fixedMode) ?>">
          <header class="quadrant-header">
            <div class="quadrant-title">
              <span class="quadrant-icon"><i class="fa-solid <?= $e($quadrant['icono'] ?? 'fa-cart-shopping') ?>"></i></span>
              <h2><?= $e($quadrant['titulo'] ?? $key) ?></h2>
            </div>
            <div class="quadrant-actions">
              <?php if (!empty($quadrant['available'])): ?>
                <span class="source-status"><?= $e($initialSummary['estado'] ?? $quadrant['estado_global'] ?? 'Sin dato') ?></span>
              <?php endif; ?>
              <a class="detail-link" href="<?= $e($quadrant['url'] ?? '#') ?>">Ver detalle <i class="fa-solid fa-arrow-up-right-from-square"></i></a>
            </div>
          </header>

          <?php if (empty($quadrant['available'])): ?>
            <div class="source-error"><div><i class="fa-solid fa-triangle-exclamation"></i><br><?= $e($quadrant['error'] ?? 'Fuente no disponible') ?></div></div>
          <?php else: ?>
            <?php if (!empty($initialSummary)): ?>
              <div class="chemical-kpi-layout">
                <article class="dryer-metric-card" style="--dryer-status: <?= $e($dryerStatusColor) ?>" data-role="metric-card">
                  <div class="dryer-metric-status<?= $statusIsYellow ? ' is-yellow' : '' ?>" data-role="metric-status">
                    <div class="dryer-metric-label" data-role="metric-label"><?= $e($initialSummary['etiqueta'] ?? '') ?></div>
                    <div class="dryer-metric-value" data-role="metric-value"><?= $e($initialIsMoney ? $fmtMoney($initialSummary['actual'] ?? null, 2) : $fmt($initialSummary['actual'] ?? null, 2)) ?></div>
                    <div class="dryer-metric-base"><span data-role="metric-base-year">Base <?= $e($quadrant['anio_anterior'] ?? '') ?></span><strong data-role="metric-base-value"><?= $e($initialIsMoney ? $fmtMoney($metricBase, 2) : $fmt($metricBase, 2)) ?></strong></div>
                    <div class="dryer-metric-change" data-role="metric-change"><?= $e($fmtPct($initialSummary['variacion'] ?? null)) ?> vs base</div>
                  </div>
                  <div class="dryer-metric-ranges">
                    <ul class="dryer-range-list" aria-label="Rangos del semáforo">
                      <li class="dryer-range-item"><span class="dryer-range-dot green"></span><span class="dryer-range-name">Verde (objetivo)</span><span class="dryer-range-value" data-role="range-green">≤ <?= $e($initialIsMoney ? $fmtMoney($metricBase, 2) : $fmt($metricBase, 2)) ?></span></li>
                      <li class="dryer-range-item"><span class="dryer-range-dot yellow"></span><span class="dryer-range-name">Amarillo</span><span class="dryer-range-value" data-role="range-yellow">&gt; <?= $e($initialIsMoney ? $fmtMoney($metricBase, 2) : $fmt($metricBase, 2)) ?> – ≤ <?= $e($initialIsMoney ? $fmtMoney($metricYellow, 2) : $fmt($metricYellow, 2)) ?></span></li>
                      <li class="dryer-range-item"><span class="dryer-range-dot red"></span><span class="dryer-range-name">Rojo</span><span class="dryer-range-value" data-role="range-red">&gt; <?= $e($initialIsMoney ? $fmtMoney($metricYellow, 2) : $fmt($metricYellow, 2)) ?></span></li>
                    </ul>
                  </div>
                </article>
                <article class="chemical-support-card">
                  <div class="kpi-icon"><i class="fa-solid <?= $isConsumption ? 'fa-flask' : 'fa-sack-dollar' ?>"></i></div>
                  <div class="kpi-label" data-role="support-one-label"><?= $isConsumption ? 'Consumo acumulado anual' : 'Gasto acumulado anual' ?></div>
                  <div class="kpi-value" data-role="support-one-value"><?= $e($isConsumption ? $fmt($quadrant['consumo_actual'] ?? null, 0) : $fmtMoney($quadrant['gasto_actual'] ?? null, 0)) ?></div>
                  <div class="kpi-meta"><?= $e($quadrant['anio_actual'] ?? '') ?></div>
                </article>
              </div>
            <?php else: ?>
            <div class="kpi-grid">
              <div class="kpi-card">
                <div class="kpi-icon"><i class="fa-solid <?= $isConsumption ? 'fa-flask' : ($isFixedCost ? 'fa-sack-dollar' : 'fa-tags') ?>"></i></div>
                <div class="kpi-label"><?= $isConsumption ? 'Consumo acumulado anual' : ($isFixedCost ? 'Gasto químico acumulado anual' : 'Costo unitario promedio') ?></div>
                <div class="kpi-value"><?= $e($isConsumption ? $fmt($quadrant['consumo_actual'] ?? null, 0) : ($isFixedCost ? $fmtMoney($quadrant['gasto_actual'] ?? null, 0) : $fmtMoney($quadrant['costo_unitario'] ?? null))) ?></div>
                <div class="kpi-meta"><?= $isConsumption ? 'Consumo total ' : ($isFixedCost ? 'Costo/kg × kg consumidos ' : 'Promedio ponderado ') ?><?= $e($quadrant['anio_actual'] ?? '') ?></div>
              </div>
              <div class="kpi-card">
                <div class="kpi-icon"><i class="fa-solid <?= ($isConsumption || $isFixedCost) ? 'fa-industry' : 'fa-money-bill-trend-up' ?>"></i></div>
                <div class="kpi-label"><?= ($isConsumption || $isFixedCost) ? 'Producción acumulada anual' : ($usesProduction ? 'Gasto / producción' : 'Gasto acumulado anual') ?></div>
                <div class="kpi-value"><?= $e(($isConsumption || $isFixedCost) ? $fmt($quadrant['produccion_actual'] ?? null, 0) : $fmtMoney($usesProduction ? ($quadrant['gasto_produccion'] ?? null) : ($quadrant['gasto_actual'] ?? null))) ?></div>
                <div class="kpi-meta"><?= ($isConsumption || $isFixedCost) ? 'Producción total ' . $e($quadrant['anio_actual'] ?? '') : 'Gasto total ' . $e($fmtMoney($quadrant['gasto_actual'] ?? null, 0)) ?></div>
              </div>
              <div class="kpi-card semaforo" style="--kpi-color: var(--status)">
                <div class="kpi-icon"><i class="fa-solid fa-chart-pie"></i></div>
                <div class="kpi-label"><?= $isFixedCost ? 'Costo base' : 'Ratio base' ?> <?= $e($quadrant['anio_anterior'] ?? '') ?></div>
                <div class="kpi-value"><?= $e($isFixedCost ? $fmtMoney($quadrant['ratio_base'] ?? null) : $fmt($quadrant['ratio_base'] ?? null, 2)) ?></div>
                <div class="kpi-meta">Actual <?= $e($isFixedCost ? $fmtMoney($quadrant['ratio_actual'] ?? null) : $fmt($quadrant['ratio_actual'] ?? null, 2)) ?></div>
              </div>
              <div class="kpi-card semaforo" style="--kpi-color: var(--status)">
                <div class="kpi-icon"><i class="fa-solid fa-gauge-high"></i></div>
                <div class="kpi-label">Variación vs base</div>
                <div class="kpi-value"><?= $e($fmtPct($quadrant['variacion_base'] ?? null)) ?></div>
                <div class="kpi-meta"><?= $e($quadrant['estado_global'] ?? 'Sin dato') ?></div>
              </div>
            </div>
            <?php endif; ?>

            <?php $purchaseFrequency = (array)($quadrant['frecuencia_compras'] ?? []); ?>
            <?php if ($purchaseFrequency !== []): ?>
              <section class="frequency-panel">
                <h3><i class="fa-solid fa-calendar-days"></i> Frecuencia de compra · <?= $e($quadrant['anio_actual'] ?? '') ?></h3>
                <div class="frequency-list">
                  <?php foreach ($purchaseFrequency as $frequency): ?>
                    <div class="frequency-row">
                      <span class="frequency-name" title="<?= $e($frequency['label'] ?? $frequency['key'] ?? '') ?>"><?= $e($frequency['label'] ?? $frequency['key'] ?? 'Refacción') ?></span>
                      <strong class="frequency-rate"><?= is_numeric($frequency['promedio_dias'] ?? null) ? 'Cada ' . $e($fmt($frequency['promedio_dias'], 1)) . ' días' : 'Una compra' ?></strong>
                      <small class="frequency-meta"><?= $e($frequency['eventos'] ?? 0) ?> pedido<?= (int)($frequency['eventos'] ?? 0) === 1 ? '' : 's' ?> · último <?= $e($frequency['ultima_compra'] ?? '—') ?></small>
                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>

            <div class="tops-grid">
              <?php foreach (['aumentos' => [$isConsumption ? 'Aumento consumo / producción' : ($isFixedCost ? 'Aumento costo químico' : 'Aumento precio unitario'), 'increases', 'fa-arrow-trend-up'], 'disminuciones' => [$isConsumption ? 'Disminución consumo / producción' : ($isFixedCost ? 'Disminución costo químico' : 'Disminución precio unitario'), 'decreases', 'fa-arrow-trend-down']] as $topKey => $topInfo): ?>
                <section class="top-panel <?= $e($topInfo[1]) ?>">
                  <h3><i class="fa-solid <?= $e($topInfo[2]) ?>"></i> Top 5 <?= $e($topInfo[0]) ?></h3>
                  <?php if (!empty($tops[$topKey])): ?>
                    <ol class="price-list">
                      <?php foreach ((array)$tops[$topKey] as $item): ?>
                        <li class="price-row" title="<?= $e($item['label'] ?? '') ?>">
                          <span class="price-name"><?= $e($item['label'] ?? $item['key'] ?? '') ?></span>
                          <span class="price-values">
                            <?= $e($isConsumption ? $fmt($item['previous'] ?? null, 2) : $fmtMoney($item['previous'] ?? null, 2)) ?> → <?= $e($isConsumption ? $fmt($item['current'] ?? null, 2) : $fmtMoney($item['current'] ?? null, 2)) ?>
                            <strong class="price-change"><?= $e($fmtPct($item['variation'] ?? null)) ?></strong>
                          </span>
                        </li>
                      <?php endforeach; ?>
                    </ol>
                  <?php else: ?>
                    <div class="empty-list">Sin comparativos disponibles</div>
                  <?php endif; ?>
                </section>
              <?php endforeach; ?>
            </div>

            <section class="trend-panel">
              <div class="trend-header">
                <div class="trend-heading">
                  <div class="trend-title">Tendencia semanal</div>
                  <div class="traffic-legend" aria-label="Semáforo del reporte fuente">
                    <span><i style="background:#10b981"></i>Óptimo</span>
                    <span><i style="background:#f59e0b"></i>Cuidado</span>
                    <span><i style="background:#ef4444"></i>Alto</span>
                  </div>
                </div>
                <div class="trend-metric"><?= $e($initialSummary['etiqueta'] ?? ($isConsumption ? 'Consumo / producción' : 'Costo / producción')) ?></div>
              </div>
              <div class="chart-wrap"><canvas id="chart-<?= $e($key) ?>"></canvas></div>
            </section>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  </main>

  <script>
    const purchasesData = <?= json_encode($cuadrantes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const charts = {};

    function moneyTick(value) {
      return '$' + Number(value || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function numberValue(value, decimals = 2) {
      if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
      const precision = Math.max(0, Math.min(2, Math.trunc(Number(decimals) || 0)));
      return Number(value).toLocaleString('es-MX', { minimumFractionDigits: precision, maximumFractionDigits: precision });
    }

    function percentValue(value) {
      if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
      const number = Number(value);
      return (number > 0 ? '+' : '') + number.toLocaleString('es-MX', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
    }

    function updateMetricCard(panel, source, mode) {
      const summary = mode === 'costo_produccion' ? (source.resumen_costo || {}) : (source.resumen_consumo || {});
      const money = Boolean(summary.es_dinero);
      const format = value => money ? moneyTick(value) : numberValue(value, 2);
      const dryerPalette = { verde: '#2e8b57', amarillo: '#facc15', rojo: '#c94436' };
      const statusColor = dryerPalette[String(summary.color || '').toLowerCase()] || '#94a3b8';
      const statusPanel = panel.querySelector('[data-role="metric-status"]');
      const setText = (selector, value) => {
        const element = panel.querySelector(selector);
        if (element) element.textContent = value;
      };

      panel.style.setProperty('--status', summary.color_hex || '#94a3b8');
      const metricCard = panel.querySelector('[data-role="metric-card"]');
      if (metricCard) metricCard.style.setProperty('--dryer-status', statusColor);
      if (statusPanel) statusPanel.classList.toggle('is-yellow', String(summary.color || '').toLowerCase() === 'amarillo');
      const badge = panel.querySelector('.source-status');
      if (badge) badge.textContent = summary.estado || 'Sin dato';
      setText('[data-role="metric-label"]', summary.etiqueta || 'Indicador');
      setText('[data-role="metric-value"]', format(summary.actual));
      setText('[data-role="metric-base-year"]', 'Base ' + (source.anio_anterior || 'año anterior'));
      setText('[data-role="metric-base-value"]', format(summary.base));
      setText('[data-role="metric-change"]', percentValue(summary.variacion) + ' vs base');
      setText('[data-role="range-green"]', '≤ ' + format(summary.limite_verde));
      setText('[data-role="range-yellow"]', '> ' + format(summary.limite_verde) + ' – ≤ ' + format(summary.limite_amarillo));
      setText('[data-role="range-red"]', '> ' + format(summary.limite_amarillo));

      if (mode === 'costo_produccion') {
        setText('[data-role="support-one-label"]', 'Gasto acumulado anual');
        setText('[data-role="support-one-value"]', moneyTick(source.gasto_actual));
        setText('[data-role="support-two-label"]', source.usa_produccion ? 'Producción acumulada anual' : 'Consumo acumulado anual');
        setText('[data-role="support-two-value"]', numberValue(source.usa_produccion ? source.produccion_actual : source.consumo_actual, 0));
      } else {
        setText('[data-role="support-one-label"]', 'Consumo acumulado anual');
        setText('[data-role="support-one-value"]', numberValue(source.consumo_actual, 0));
        setText('[data-role="support-two-label"]', source.usa_produccion ? 'Producción acumulada anual' : 'Periodos registrados');
        setText('[data-role="support-two-value"]', numberValue(source.usa_produccion ? source.produccion_actual : source.periodos_actual, 0));
      }
    }

    function drawChart(panel, mode) {
      const key = panel.dataset.quadrant;
      const source = purchasesData[key] || {};
      const trend = source.tendencia || {};
      const canvas = panel.querySelector('canvas');
      if (!canvas) return;
      const ratioSource = trend.ratio || {};
      const costSource = trend.costo_fuente || {};
      const hasSourceCost = Array.isArray(costSource.actual) && costSource.actual.length > 0;
      const selectedChart = mode === 'costo_produccion' && hasSourceCost ? costSource : ratioSource;
      const labels = selectedChart.labels || [];
      const values = selectedChart.actual || [];
      const fallbackColor = source.color_global_hex || '#10b981';
      const colors = (selectedChart.colors || []).map(color => color && color !== '#94a3b8' ? color : fallbackColor);
      const baseValues = selectedChart.base || [];
      const isMoney = mode === 'costo_produccion' && hasSourceCost;
      const metricLabel = isMoney
        ? ((source.resumen_costo || {}).etiqueta || 'Costo / producción')
        : (mode === 'consumo' ? ((source.resumen_consumo || {}).etiqueta || 'Consumo / producción') : 'Gasto / producción');
      if (charts[key]) charts[key].destroy();
      const datasets = [{
        label: metricLabel,
        data: values,
        borderColor: '#2736c5',
        backgroundColor: 'rgba(39, 54, 197, .05)',
        borderWidth: 2.5,
        pointRadius: 4,
        pointHoverRadius: 6,
        pointBackgroundColor: colors,
        pointBorderColor: '#fff',
        pointBorderWidth: 1.5,
        tension: .28,
        fill: false,
        spanGaps: true
      }];
      if (baseValues.some(value => Number.isFinite(Number(value)))) {
        datasets.push({
          label: 'Base ' + (source.anio_anterior || ''),
          data: baseValues,
          borderColor: '#3b82f6',
          borderDash: [5, 4],
          borderWidth: 1.5,
          pointRadius: 0,
          tension: .25,
          fill: false,
          spanGaps: true
        });
      }
      const semaphoreBands = {
        id: 'semaphoreBands-' + key,
        beforeDatasetsDraw(chart) {
          const chartBase = Number(selectedChart.ratio_base);
          const hasConfiguredGreen = selectedChart.limite_verde !== null && selectedChart.limite_verde !== undefined;
          const hasConfiguredYellow = selectedChart.limite_amarillo !== null && selectedChart.limite_amarillo !== undefined;
          const configuredGreen = Number(selectedChart.limite_verde);
          const configuredYellow = Number(selectedChart.limite_amarillo);
          const greenLimit = hasConfiguredGreen && Number.isFinite(configuredGreen) ? configuredGreen : (chartBase > 0 ? chartBase : Number(source.limite_verde));
          const yellowLimit = hasConfiguredYellow && Number.isFinite(configuredYellow) ? configuredYellow : Number(source.limite_amarillo);
          if (!Number.isFinite(greenLimit) || !Number.isFinite(yellowLimit)) return;
          const { ctx, chartArea, scales } = chart;
          if (!chartArea || !scales.y) return;
          const greenY = scales.y.getPixelForValue(greenLimit);
          const yellowY = scales.y.getPixelForValue(yellowLimit);
          ctx.save();
          ctx.fillStyle = 'rgba(239,68,68,.08)';
          ctx.fillRect(chartArea.left, chartArea.top, chartArea.width, Math.max(0, yellowY - chartArea.top));
          ctx.fillStyle = 'rgba(245,158,11,.09)';
          ctx.fillRect(chartArea.left, yellowY, chartArea.width, Math.max(0, greenY - yellowY));
          ctx.fillStyle = 'rgba(16,185,129,.08)';
          ctx.fillRect(chartArea.left, greenY, chartArea.width, Math.max(0, chartArea.bottom - greenY));
          ctx.restore();
        }
      };
      charts[key] = new Chart(canvas, {
        type: 'line',
        data: {
          labels,
          datasets
        },
        plugins: [semaphoreBands],
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: { display: datasets.length > 1, position: 'top', labels: { boxWidth: 10, font: { size: 8, weight: '700' } } },
            tooltip: { callbacks: { label: context => (context.dataset.label || '') + ': ' + (isMoney ? moneyTick(context.parsed.y) : Number(context.parsed.y).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })) } }
          },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 9, font: { size: 8, weight: '700' } } },
            y: { beginAtZero: true, ticks: { maxTicksLimit: 4, font: { size: 8 }, callback: value => isMoney ? moneyTick(value) : Number(value).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }, grid: { color: '#e8edf3' } }
          }
        }
      });
    }

    function applyReportMode(mode) {
      document.querySelectorAll('.purchase-quadrant[data-quadrant]').forEach(panel => {
        const source = purchasesData[panel.dataset.quadrant] || {};
        const fixedMode = panel.dataset.fixedMode || source.modo_fijo || '';
        const effectiveMode = fixedMode || mode;
        const metric = panel.querySelector('.trend-metric');
        if (metric) {
          metric.textContent = effectiveMode === 'consumo'
            ? 'Consumo / producción'
            : (effectiveMode === 'gasto'
              ? (source.usa_produccion ? 'Gasto / producción' : 'Gasto semanal')
              : ((source.resumen_costo || {}).etiqueta || 'Costo / producción'));
        }
        updateMetricCard(panel, source, effectiveMode);
        drawChart(panel, effectiveMode);
      });
    }

    document.querySelectorAll('.report-filter').forEach(button => {
      button.addEventListener('click', () => {
        document.querySelectorAll('.report-filter').forEach(item => item.classList.remove('is-active'));
        button.classList.add('is-active');
        applyReportMode(button.dataset.reportMode || 'costo_produccion');
      });
    });

    applyReportMode('costo_produccion');

    setTimeout(() => location.reload(), <?= (int)$intervalo_actualizacion_ms ?>);
  </script>
</body>
</html>
