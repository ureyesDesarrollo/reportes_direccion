<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/build_report.php';
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$money = static fn($value): string => is_numeric($value) ? '$' . number_format((float)$value, 2, '.', ',') : '—';
$hasVisibleValue = static fn(array $metric): bool => !is_numeric($metric['value'] ?? null) || round((float)$metric['value'], 2) !== 0.0;
$expenses = array_filter((array)($report['gastos'] ?? []), $hasVisibleValue);
$departments = array_values(array_filter((array)($report['departamentos'] ?? []), $hasVisibleValue));
usort($departments, static function (array $left, array $right): int {
  $leftValue = is_numeric($left['value'] ?? null) ? (float)$left['value'] : -1.0;
  $rightValue = is_numeric($right['value'] ?? null) ? (float)$right['value'] : -1.0;
  return $rightValue <=> $leftValue;
});
$departmentTotal = 0.0;
$departmentMaximum = 0.0;
$departmentsWithValue = 0;
foreach ($departments as $department) {
  if (!is_numeric($department['value'] ?? null)) continue;
  $departmentValue = (float)$department['value'];
  $departmentTotal += $departmentValue;
  $departmentMaximum = max($departmentMaximum, $departmentValue);
  $departmentsWithValue++;
}
$leadingDepartment = $departments[0] ?? null;
$indicators = (array)($report['indicadores'] ?? []);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($report['titulo'] ?? 'Reporte de Finanzas') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--navy:#17365d;--blue:#245d8f;--blue-soft:#eaf2f8;--slate:#334155;}
    *{box-sizing:border-box}body{margin:0;color:var(--ink);background:#f4f7fb;font-family:Inter,system-ui,sans-serif}.report{width:min(1500px,calc(100% - 48px));margin:0 auto;padding:20px 0 34px}.header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:12px}.header-left{min-width:0}.top-actions{display:flex;align-items:center;gap:10px;margin-bottom:10px}.link{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border:1px solid var(--line);border-radius:999px;color:#475569;background:#fff;font-size:12px;font-weight:800;text-decoration:none;box-shadow:0 2px 8px rgba(15,23,42,.04)}h1{margin:0;color:#0f172a;font-size:clamp(30px,3.2vw,42px);line-height:1;font-weight:900;letter-spacing:-.03em}.subtitle{margin:8px 0 0;color:var(--muted);font-size:13px;font-weight:700}.header-period{display:inline-flex;align-items:center;gap:7px;margin-top:9px;color:#64748b;font-size:12px;font-weight:800}.header-period i{color:var(--blue)}.meta{display:flex;align-items:center}.update-panel{display:flex;align-items:center;gap:8px;padding:9px 14px;border:1px solid var(--line);border-radius:999px;background:#fff;box-shadow:0 2px 8px rgba(15,23,42,.04)}.update-dot{width:9px;height:9px;border-radius:50%;background:#10b981}.update-dot.pending{background:#ef4444}.status{color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase}.period{font-size:12px;font-weight:900;text-transform:capitalize}.picker{display:flex;align-items:end;gap:12px;margin-bottom:15px;padding:12px 16px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.04)}.filter-control{display:grid;gap:5px;min-width:150px}.filter-control label{color:#64748b;font-size:9px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}.picker input,.picker select{width:100%;min-width:150px;height:39px;padding:7px 10px;border:1px solid #cfd9e5;border-radius:10px;color:#334155;background:#f8fafc;font:inherit;font-size:12px;font-weight:800;outline:none}.picker select{min-width:120px}.picker input:focus,.picker select:focus{border-color:#7aa8cf;box-shadow:0 0 0 3px rgba(36,93,143,.1)}.period-field[hidden]{display:none}.filter-loading{display:none;align-items:center;gap:7px;padding-bottom:10px;color:#64748b;font-size:10px;font-weight:800}.picker.is-loading .filter-loading{display:flex}.warning{margin-top:10px;padding:11px 14px;border:1px solid #fecaca;border-radius:12px;color:#991b1b;background:#fef2f2;font-size:12px;font-weight:800}.section{margin-top:15px}.section h2{display:flex;align-items:center;gap:9px;margin:0 0 9px;color:#334155;font-size:16px;font-weight:900;text-transform:uppercase}.section h2 i{color:var(--blue)}.summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.summary-card{position:relative;min-height:115px;padding:16px 18px;overflow:hidden;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.04)}.summary-card::before,.expense-card::before{content:"";position:absolute;left:0;top:0;width:4px;height:100%;background:var(--blue)}.summary-label{display:flex;align-items:center;gap:9px;color:#64748b}.summary-label i{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;color:var(--blue);background:#eaf2f8;font-size:15px}.summary-label span{font-size:10px;font-weight:900;text-transform:uppercase}.summary-value{display:block;margin-top:13px;color:#0f172a;font-size:clamp(28px,3vw,39px);line-height:1;font-weight:900;white-space:nowrap}.summary-meta{display:block;margin-top:7px;color:#64748b;font-size:9px;font-weight:800}.expenses-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.expense-card{position:relative;display:block;min-height:130px;overflow:hidden;border:1px solid var(--line);border-radius:16px;background:#fff;box-shadow:0 4px 14px rgba(15,23,42,.04)}.expense-icon{display:grid;place-items:center;width:34px;height:34px;margin:15px 16px 0;border-radius:10px;color:var(--blue);background:#eaf2f8;font-size:15px}.source-api .expense-icon{color:var(--blue);background:#eaf2f8}.expense-content{display:block;min-width:0;padding:9px 16px 15px}.expense-content span{color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase}.expense-content strong{display:block;margin-top:7px;color:#0f172a;font-size:clamp(25px,2.5vw,34px);line-height:1;font-weight:900;white-space:nowrap}.expense-content small{display:block;margin-top:6px;color:#64748b;font-size:9px;font-weight:800}.department-code{flex:0 0 auto;padding:4px 7px;border-radius:7px;color:#fff;background:var(--blue);font-size:10px;font-weight:900}.department-name{color:#334155;font-size:11px;line-height:1.25;font-weight:900;text-transform:uppercase}
    details>summary{list-style:none}details>summary::-webkit-details-marker{display:none}.expense-accordion[open]{grid-column:1/-1}.expense-summary{position:relative;display:block;min-height:128px;cursor:pointer}.expense-summary .expense-content{padding-right:42px}.accordion-chevron{position:absolute;right:16px;top:50%;color:#64748b;font-size:12px;transform:translateY(-50%);transition:transform .2s ease}.expense-accordion[open]>.expense-summary .accordion-chevron{transform:translateY(-50%) rotate(180deg)}.invoice-panel{padding:14px 16px 16px;border-top:1px solid var(--line);background:#f8fafc}.invoice-panel-title{margin-bottom:9px;color:#334155;font-size:12px;font-weight:900;text-transform:uppercase}.invoice-item{margin-top:7px;overflow:hidden;border:1px solid #d8e1eb;border-radius:11px;background:#fff}.invoice-item>summary{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:4px 14px;padding:11px 13px;cursor:pointer}.invoice-provider{overflow:hidden;color:#263c57;font-size:12px;font-weight:900;text-overflow:ellipsis;white-space:nowrap}.invoice-reference{color:#64748b;font-size:10px;font-weight:700}.invoice-item>summary>strong{grid-column:2;grid-row:1/3;align-self:center;color:var(--navy);font-size:17px;white-space:nowrap}.invoice-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;padding:12px 13px;border-top:1px solid #e2e8f0;background:#f8fafc}.invoice-detail-grid>div{min-width:0;padding:8px;border-radius:8px;background:#fff}.invoice-detail-grid span{display:block;color:#64748b;font-size:8px;font-weight:900;text-transform:uppercase}.invoice-detail-grid strong{display:block;margin-top:4px;overflow-wrap:anywhere;color:#1e293b;font-size:11px}
    .department-board{overflow:hidden;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 8px 24px rgba(15,23,42,.05)}.department-overview{display:grid;grid-template-columns:1.15fr 1fr .75fr;border-bottom:1px solid var(--line);background:linear-gradient(135deg,#f8fbfe,#edf4fa)}.overview-metric{min-width:0;padding:17px 20px;border-right:1px solid #d6e2ed}.overview-metric:last-child{border-right:0}.overview-metric span{display:block;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.overview-metric strong{display:block;margin-top:7px;overflow:hidden;color:var(--navy);font-size:clamp(20px,3vw,30px);line-height:1;text-overflow:ellipsis;white-space:nowrap}.overview-metric small{display:block;margin-top:6px;overflow:hidden;color:#64748b;font-size:9px;font-weight:800;text-overflow:ellipsis;white-space:nowrap}.ranking-heading,.department-row-summary{display:grid;grid-template-columns:42px minmax(180px,1.25fr) minmax(160px,2fr) 150px 90px 26px;align-items:center;gap:12px}.ranking-heading{padding:10px 17px;color:#64748b;background:#f8fafc;font-size:8px;font-weight:900;text-transform:uppercase}.department-row{border-top:1px solid #edf1f5}.department-row:first-child{border-top:0}.department-row-summary{position:relative;min-height:67px;padding:10px 17px;cursor:pointer;transition:background .15s ease}.department-row-summary:hover{background:#f8fbfe}.rank-number{color:#94a3b8;font-size:12px;font-weight:900}.department-identity{display:flex;min-width:0;align-items:center;gap:9px}.department-identity .department-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.department-bar{height:10px;overflow:hidden;border-radius:999px;background:#e5edf5}.department-bar span{display:block;height:100%;min-width:4px;border-radius:inherit;background:linear-gradient(90deg,var(--blue),#5b91bd)}.department-amount{color:var(--navy);font-size:16px;font-weight:900;text-align:right;white-space:nowrap}.department-invoices{color:#64748b;font-size:9px;font-weight:800;text-align:right}.department-row .accordion-chevron{position:static;justify-self:end;transform:none}.department-row[open]>.department-row-summary{background:#eef5fa}.department-row[open]>.department-row-summary .accordion-chevron{transform:rotate(180deg)}.department-row>.invoice-panel{border-top:1px solid #d8e3ec}.empty-ranking{padding:30px;color:#64748b;text-align:center;font-size:12px;font-weight:800}
    .line-items{padding:0 13px 13px;background:#f8fafc}.line-items-placeholder{padding:12px;border:1px dashed #cbd8e5;border-radius:9px;color:#64748b;background:#fff;text-align:center;font-size:10px;font-weight:800}.line-items-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;color:#334155;font-size:11px;font-weight:900;text-transform:uppercase}.line-items-title span{padding:4px 7px;border-radius:999px;color:#1e4f78;background:#dceaf5;font-size:9px}.line-items-table{overflow:hidden;border:1px solid #d8e1eb;border-radius:10px;background:#fff}.line-item{display:grid;grid-template-columns:42px minmax(210px,1.8fr) 105px 125px 125px;align-items:center;gap:10px;padding:10px 12px;border-top:1px solid #e8edf2}.line-item:first-child{border-top:0}.line-item.line-header{padding-top:7px;padding-bottom:7px;color:#64748b;background:#eef3f7;font-size:8px;font-weight:900;text-transform:uppercase}.line-item-number{color:#64748b;font-size:10px;font-weight:900}.line-product{min-width:0}.line-product strong{display:block;overflow:hidden;color:#263c57;font-size:11px;text-overflow:ellipsis;white-space:nowrap}.line-product small{display:block;margin-top:3px;overflow:hidden;color:#64748b;font-size:9px;text-overflow:ellipsis;white-space:nowrap}.line-quantity,.line-money{color:#334155;font-size:10px;font-weight:800;text-align:right}.line-quantity strong{display:inline;color:var(--navy);font-size:14px;font-weight:900}.line-quantity small{display:block;margin-top:2px;color:#64748b;font-size:8px;font-weight:900;text-transform:uppercase}.line-money{color:var(--navy);font-size:11px;font-weight:900}.line-extra{grid-column:2/-1;display:flex;flex-wrap:wrap;gap:5px;margin-top:-3px}.line-extra span{padding:3px 6px;border-radius:5px;color:#64748b;background:#eef3f7;font-size:8px;font-weight:800}
    @media(max-width:820px){.report{width:calc(100% - 28px)}.header{flex-direction:column}.picker{flex-wrap:wrap}.expenses-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid{grid-template-columns:1fr}.invoice-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.department-overview{grid-template-columns:1fr 1fr}.overview-metric:last-child{grid-column:1/-1;border-top:1px solid #d6e2ed}.ranking-heading,.department-row-summary{grid-template-columns:34px minmax(150px,1.3fr) minmax(110px,1fr) 125px 22px}.ranking-heading span:nth-child(5),.department-invoices{display:none}.line-items-table{overflow-x:auto}.line-item{min-width:700px}}@media(max-width:570px){.report{width:calc(100% - 18px)}.meta{display:none}.expenses-grid{grid-template-columns:1fr}.picker{display:grid;grid-template-columns:1fr}.filter-control,.picker input,.picker select{width:100%;min-width:0}.summary-card{min-height:100px}.invoice-item>summary{grid-template-columns:1fr}.invoice-item>summary>strong{grid-column:1;grid-row:auto}.invoice-detail-grid{grid-template-columns:1fr}.department-overview{grid-template-columns:1fr}.overview-metric,.overview-metric:last-child{grid-column:auto;border-right:0;border-top:1px solid #d6e2ed}.overview-metric:first-child{border-top:0}.ranking-heading{display:none}.department-row-summary{grid-template-columns:28px minmax(0,1fr) 22px;gap:8px}.department-bar{grid-column:2;height:7px}.department-amount{grid-column:2;text-align:left}.department-row-summary .accordion-chevron{grid-column:3;grid-row:1/4}.department-identity .department-name{white-space:normal}}
    <?php if ($captureMode): ?>body{background:#fff}.report{width:1080px;padding:8px 0}.top-actions,.picker{display:none}.header{margin-bottom:8px}.section{margin-top:11px}.summary-card{min-height:105px}.expense-card{min-height:118px}<?php endif; ?>
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body>
<main class="report">
  <header class="header">
    <div class="header-left"><div class="top-actions"><a class="link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Regresar al inicio</a></div><h1><?= $e($report['titulo'] ?? '') ?></h1><p class="subtitle"><?= $e($report['subtitulo'] ?? '') ?></p><span class="header-period"><i class="fa-regular fa-calendar"></i><?= $e($periodLabel) ?></span></div>
    <div class="meta"><div class="update-panel"><span class="update-dot <?= $warnings !== [] ? 'pending' : '' ?>"></span><span class="status"><?= $warnings === [] ? 'API conectada' : 'Fuente no disponible' ?></span></div></div>
  </header>
  <form class="picker" method="get" id="period-form">
    <div class="filter-control"><label for="period-mode">Vista</label><select name="periodo" id="period-mode"><option value="semana" <?= $periodMode === 'semana' ? 'selected' : '' ?>>Semana</option><option value="mes" <?= $periodMode === 'mes' ? 'selected' : '' ?>>Mes</option></select></div>
    <div class="filter-control period-field" data-period="semana" <?= $periodMode !== 'semana' ? 'hidden' : '' ?>><label for="week-filter">Semana</label><input id="week-filter" aria-label="Semana" type="week" name="semana" min="2020-W01" max="2100-W53" value="<?= $e($selectedWeek) ?>"></div>
    <div class="filter-control period-field" data-period="mes" <?= $periodMode !== 'mes' ? 'hidden' : '' ?>><label for="month-filter">Mes</label><input id="month-filter" aria-label="Mes" type="month" name="mes" min="2020-01" max="2100-12" value="<?= $e($selectedMonth) ?>"></div>
    <span class="filter-loading"><i class="fa-solid fa-spinner fa-spin"></i> Actualizando</span>
  </form>
  <?php foreach ($warnings as $warning): ?><div class="warning"><?= $e($warning) ?></div><?php endforeach; ?>
  <section class="section"><h2><i class="fa-solid fa-chart-line"></i> Indicadores principales</h2><div class="summary-grid"><?php foreach ($indicators as $metric): ?><article class="summary-card"><div class="summary-label"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-chart-line') ?>"></i><span><?= $e($metric['label'] ?? '') ?></span></div><strong class="summary-value"><?= $money($metric['value'] ?? null) ?></strong><?php if (!empty($metric['meta'])): ?><small class="summary-meta"><?= $e($metric['meta']) ?><?= !empty($metric['cached']) ? ' · caché' : '' ?></small><?php endif; ?></article><?php endforeach; ?></div></section>
  <section class="section"><h2><i class="fa-solid fa-file-invoice-dollar"></i> Gastos</h2><div class="expenses-grid">
    <?php foreach ($expenses as $metric): ?>
      <?php if (!empty($metric['detalle_facturas'])): ?><details class="expense-card expense-accordion source-api"><summary class="expense-summary"><?php else: ?><article class="expense-card <?= ($metric['source'] ?? '') === 'api_facturas_compra' ? 'source-api' : '' ?>"><?php endif; ?>
        <div class="expense-icon"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-coins') ?>"></i></div><div class="expense-content"><span>Gasto <?= $e($metric['label'] ?? '') ?></span><strong><?= $money($metric['value'] ?? null) ?></strong><?php if (isset($metric['facturas'])): ?><small><?= $e($metric['facturas']) ?> factura<?= (int)$metric['facturas'] === 1 ? '' : 's' ?> · API 104<?= !empty($metric['cached']) ? ' · caché' : '' ?><?= !empty($metric['detalle_facturas']) ? ' · Ver detalle' : '' ?></small><?php endif; ?><?php if (!empty($metric['source_detail'])): ?><small><?= $e($metric['source_detail']) ?></small><?php endif; ?></div>
      <?php if (!empty($metric['detalle_facturas'])): ?><i class="fa-solid fa-chevron-down accordion-chevron"></i></summary><?php require __DIR__ . '/invoice_details.php'; ?></details><?php else: ?></article><?php endif; ?>
    <?php endforeach; ?>
  </div></section>
  <section class="section"><h2><i class="fa-solid fa-chart-bar"></i> Distribución del gasto por departamento</h2>
    <div class="department-board">
      <div class="department-overview">
        <div class="overview-metric"><span>Gasto clasificado</span><strong><?= $money($departmentTotal) ?></strong><small>Suma de departamentos con movimiento</small></div>
        <div class="overview-metric"><span>Mayor gasto</span><strong><?= $money($leadingDepartment['value'] ?? null) ?></strong><small><?= $e($leadingDepartment['code'] ?? '—') ?> · <?= $e($leadingDepartment['label'] ?? 'Sin información') ?></small></div>
        <div class="overview-metric"><span>Áreas con movimiento</span><strong><?= $e($departmentsWithValue) ?></strong><small>Ordenadas de mayor a menor</small></div>
      </div>
      <div class="ranking-heading"><span>#</span><span>Departamento</span><span>Participación relativa</span><span style="text-align:right">Importe</span><span style="text-align:right">Facturas</span><span></span></div>
      <div class="department-ranking">
        <?php foreach ($departments as $rank => $metric): ?>
          <?php $barWidth = is_numeric($metric['value'] ?? null) && $departmentMaximum > 0 ? max(1.0, ((float)$metric['value'] / $departmentMaximum) * 100) : 0.0; ?>
          <details class="department-row"><summary class="department-row-summary"><span class="rank-number"><?= $e($rank + 1) ?></span><span class="department-identity"><span class="department-code"><?= $e($metric['code'] ?? '') ?></span><span class="department-name"><?= $e($metric['label'] ?? '') ?></span></span><span class="department-bar"><span style="width:<?= $e(number_format($barWidth, 2, '.', '')) ?>%"></span></span><strong class="department-amount"><?= $money($metric['value'] ?? null) ?></strong><span class="department-invoices"><?= $e($metric['facturas'] ?? 0) ?> factura<?= (int)($metric['facturas'] ?? 0) === 1 ? '' : 's' ?></span><i class="fa-solid fa-chevron-down accordion-chevron"></i></summary><?php require __DIR__ . '/invoice_details.php'; ?></details>
        <?php endforeach; ?>
        <?php if ($departments === []): ?><div class="empty-ranking">No hay departamentos con gasto para el periodo seleccionado.</div><?php endif; ?>
      </div>
    </div>
  </section>
</main>
<script>
(() => {
  const periodMode = document.getElementById('period-mode');
  const periodForm = document.getElementById('period-form');
  const submitPeriod = () => {
    if (!periodForm || periodForm.classList.contains('is-loading')) return;
    periodForm.classList.add('is-loading');
    periodForm.requestSubmit();
  };
  if (periodMode) periodMode.addEventListener('change', () => {
    document.querySelectorAll('.period-field').forEach(field => {
      field.hidden = field.dataset.period !== periodMode.value;
    });
    submitPeriod();
  });
  document.querySelectorAll('.period-field input').forEach(field => field.addEventListener('change', submitPeriod));
  const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[character]));
  const number = value => value === null || value === undefined || value === '' ? '—' : Number(value).toLocaleString('es-MX', {maximumFractionDigits: 2});
  const moneyValue = value => value === null || value === undefined || value === '' ? '—' : '$' + Number(value).toLocaleString('es-MX', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const optionalTag = (label, value) => value === null || value === undefined || value === '' || Number(value) === 0 ? '' : `<span>${escapeHtml(label)} ${escapeHtml(value)}</span>`;

  document.querySelectorAll('.invoice-item').forEach(invoice => {
    invoice.addEventListener('toggle', async () => {
      const container = invoice.querySelector('.line-items');
      if (!invoice.open || !container || container.dataset.linesState !== 'idle') return;
      const provider = invoice.dataset.provider || '';
      const invoiceNumber = invoice.dataset.invoice || '';
      if (!provider || !invoiceNumber || invoiceNumber === 'Sin número') {
        container.dataset.linesState = 'error';
        container.innerHTML = '<div class="line-items-placeholder">No hay una referencia válida para consultar las partidas.</div>';
        return;
      }
      container.dataset.linesState = 'loading';
      container.innerHTML = '<div class="line-items-placeholder">Consultando partidas…</div>';
      try {
        const response = await fetch(`factura_detalle.php?proveedor=${encodeURIComponent(provider)}&factura=${encodeURIComponent(invoiceNumber)}`, {headers: {'Accept': 'application/json'}});
        const payload = await response.json();
        if (!response.ok || payload.ok !== true) throw new Error(payload.message || 'No fue posible consultar las partidas.');
        const items = Array.isArray(payload.partidas) ? payload.partidas : [];
        if (items.length === 0) {
          container.dataset.linesState = 'loaded';
          container.innerHTML = '<div class="line-items-placeholder">La factura no contiene partidas.</div>';
          return;
        }
        const rows = items.map(item => `<div class="line-item">
          <span class="line-item-number">${escapeHtml(item.partida ?? '—')}</span>
          <span class="line-product"><strong>${escapeHtml(item.producto || item.descripcion || 'Producto sin descripción')}</strong><small>${escapeHtml(item.clave_producto || 'Sin clave')}${item.descripcion && item.descripcion !== item.producto ? ' · ' + escapeHtml(item.descripcion) : ''}</small></span>
          <span class="line-quantity"><strong>${number(item.cantidad_surtida)}</strong><small>${escapeHtml(item.unidad || 'Sin unidad')}</small></span>
          <span class="line-money">${moneyValue(item.valor_unitario)}</span>
          <span class="line-money">${moneyValue(item.subtotal)}</span>
          <span class="line-extra">${optionalTag('Pedido', item.pedido)}${optionalTag('Orden', item.orden)}${optionalTag('Req.', item.requisicion)}${optionalTag('Lote', item.lote)}</span>
        </div>`).join('');
        container.dataset.linesState = 'loaded';
        container.innerHTML = `<div class="line-items-title">Partidas de la factura <span>${items.length}</span></div><div class="line-items-table"><div class="line-item line-header"><span>#</span><span>Producto</span><span style="text-align:right">Cantidad surtida</span><span style="text-align:right">Precio unitario</span><span style="text-align:right">Subtotal</span></div>${rows}</div>`;
      } catch (error) {
        container.dataset.linesState = 'error';
        container.innerHTML = `<div class="line-items-placeholder">${escapeHtml(error.message || 'No fue posible consultar las partidas.')}</div>`;
      }
    });
  });
})();
</script>
</body>
</html>
