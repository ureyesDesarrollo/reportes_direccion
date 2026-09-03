<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatKg = static fn($value): string => is_numeric($value) ? number_format((float)$value, 0, '.', ',') : '—';
$formatRange = static function (array $band): string {
  $minimum = isset($band['min']) && is_numeric($band['min']) ? (float)$band['min'] : null;
  $maximum = isset($band['max']) && is_numeric($band['max']) ? (float)$band['max'] : null;
  $number = static fn(float $value): string => rtrim(rtrim(number_format($value, 1, '.', ','), '0'), '.');
  $template = trim((string)($band['plantilla'] ?? ''));
  if ($template !== '') {
    return strtr($template, [
      '{min}' => $minimum !== null ? $number(round($minimum, 4)) : '',
      '{max}' => $maximum !== null ? $number(round($maximum, 4)) : '',
    ]);
  }
  if ($minimum === null && $maximum !== null) return '≤ ' . $number($maximum);
  if ($minimum !== null && $maximum === null) return '≥ ' . $number($minimum);
  if ($minimum !== null && $maximum !== null) return $number($minimum) . ' – ' . $number($maximum);
  return '—';
};
$statusLabels = ['verde' => 'Verde', 'amarillo' => 'Amarillo', 'rojo' => 'Rojo', 'gris' => 'Sin dato'];
$dailyMetrics = (array)($report['diarios'] ?? []);
$weeklyMetrics = (array)($report['semanales'] ?? []);
$detailsJson = json_encode($report['detalles'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
$renderCard = static function (array $metric) use ($e, $formatKg, $formatRange, $statusLabels): void {
  $state = (string)($metric['estado'] ?? 'gris');
  $detailKey = (string)($metric['detalle_key'] ?? $metric['key'] ?? '');
  $kilograms = $metric['kilogramos'] ?? null;
  $valueClass = is_numeric($kilograms) && abs((float)$kilograms) >= 10000 ? ' value-large' : '';
  ?>
  <button class="metric-card" type="button" data-detail-key="<?= $e($detailKey) ?>" data-detail-title="<?= $e($metric['label'] ?? '') ?>">
    <span class="metric-main state-<?= $e($state) ?>">
      <span class="metric-title"><?= $e($metric['label'] ?? '') ?></span>
      <strong class="metric-value<?= $valueClass ?>"><?= $formatKg($kilograms) ?><small>kg</small></strong>
      <span class="metric-status"><?= $e($statusLabels[$state] ?? 'Sin dato') ?></span>
    </span>
    <span class="ranges" aria-label="Rangos en toneladas">
      <?php foreach ((array)($metric['bandas'] ?? []) as $index => $band): $bandState = (string)($band['estado'] ?? 'gris'); ?>
        <span class="range-row"><i class="dot dot-<?= $e($bandState) ?>"></i><span><?= $e($bandState === 'verde' ? 'Verde (Objetivo)' : ucfirst($bandState)) ?></span><b><?= $e($formatRange($band)) ?></b></span>
      <?php endforeach; ?>
      <small class="range-unit">Rangos en toneladas</small>
    </span>
  </button>
  <?php
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($report['titulo'] ?? 'Compra de Materia Prima') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--navy:#243f60;--navy2:#172f4d;--green:#2e8b57;--yellow:#facc15;--red:#c94436;--gray:#94a3b8}
    *{box-sizing:border-box}body{margin:0;color:var(--ink);background:#f1f5f9;font-family:Inter,system-ui,sans-serif}.report{width:min(1180px,calc(100% - 28px));margin:18px auto 38px}.topbar{display:flex;justify-content:space-between;gap:10px;margin-bottom:10px}.back{padding:8px 12px;border:1px solid var(--line);border-radius:999px;color:#334155;background:#fff;font-size:12px;font-weight:800;text-decoration:none}.header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:19px 22px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}h1{margin:0;font-size:clamp(27px,4vw,40px);font-weight:900}.subtitle{margin:5px 0 0;color:var(--muted);font-size:13px;font-weight:700}.meta{text-align:right}.meta strong,.meta span{display:block}.meta strong{font-size:14px}.meta span{margin-top:4px;color:var(--muted);font-size:11px;font-weight:800}.filter{display:flex;justify-content:flex-end;gap:6px;margin-top:8px}.filter input{height:31px;padding:4px 8px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:11px;font-weight:800}.filter button{border:0;border-radius:8px;color:#fff;background:var(--navy);font:inherit;font-size:11px;font-weight:900}.warning{margin-top:10px;padding:11px 14px;border:1px solid #fca5a5;border-radius:12px;color:#991b1b;background:#fee2e2;font-size:12px;font-weight:800}.section{margin-top:14px}.section-head{display:flex;align-items:end;justify-content:space-between;gap:10px;margin-bottom:7px}.section-head h2{margin:0;color:#334155;font-size:17px;font-weight:900;text-transform:uppercase}.section-head span{color:var(--muted);font-size:10px;font-weight:800}.metrics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metrics.weekly{grid-template-columns:repeat(3,minmax(0,1fr))}.metric-card{display:grid;grid-template-columns:minmax(160px,38%) 1fr;min-width:0;overflow:hidden;padding:0;border:2px solid var(--line);border-radius:17px;background:#fff;font:inherit;text-align:left;cursor:pointer}.metric-card:hover{border-color:#93a9c2;box-shadow:0 8px 20px rgba(15,23,42,.08)}.metric-main{display:flex;min-width:0;flex-direction:column;justify-content:center;padding:15px;color:#fff}.metric-title{min-height:32px;font-size:13px;line-height:1.2;font-weight:900;text-transform:uppercase}.metric-main strong{display:flex;align-items:baseline;gap:5px;margin-top:9px;font-size:clamp(29px,4vw,43px);line-height:1;font-weight:900;white-space:nowrap}.metric-main strong small{font-size:12px}.metric-status{margin-top:9px;font-size:11px;font-weight:900;text-transform:uppercase}.state-verde{background:var(--green)}.state-amarillo{color:#111827;background:var(--yellow)}.state-rojo{background:var(--red)}.state-gris{background:var(--gray)}.ranges{display:flex;flex-direction:column;justify-content:center;padding:10px 12px}.range-row{display:grid;grid-template-columns:9px minmax(105px,1fr) auto;align-items:center;gap:7px;padding:3px 0;color:#334155;font-size:10px}.range-row b{color:#0f172a;font-size:11px;white-space:nowrap}.dot{width:8px;height:8px;border-radius:50%}.dot-verde{background:var(--green)}.dot-amarillo{background:#e49a32}.dot-rojo{background:var(--red)}.dot-gris{background:var(--gray)}.range-unit{margin-top:4px;color:var(--muted);font-size:9px;font-weight:800;text-align:right}.modal{position:fixed;inset:0;z-index:20;display:none;place-items:center;padding:20px;background:rgba(15,23,42,.58)}.modal.open{display:grid}.dialog{width:min(850px,100%);max-height:85vh;overflow:auto;border-radius:18px;background:#fff;box-shadow:0 24px 70px rgba(0,0,0,.28)}.dialog-head{position:sticky;top:0;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:15px 18px;color:#fff;background:var(--navy2)}.dialog-head h3{margin:0;font-size:16px}.close{border:0;color:#fff;background:transparent;font-size:24px;cursor:pointer}.detail-table{width:100%;border-collapse:collapse}.detail-table th,.detail-table td{padding:10px 13px;border-bottom:1px solid #e7edf5;font-size:12px}.detail-table th{color:#475569;background:#f8fafc;text-align:left;text-transform:uppercase}.detail-table th:last-child,.detail-table td:last-child{text-align:right}.empty{padding:24px;color:var(--muted);font-size:13px;font-weight:800;text-align:center}
    .metric-value.value-large{gap:3px;font-size:clamp(25px,3vw,34px);letter-spacing:-1px}.metric-value.value-large small{font-size:10px;letter-spacing:0}.metrics.daily{grid-template-columns:repeat(3,minmax(0,1fr))}.metrics.daily .metric-card{grid-template-columns:minmax(145px,42%) 1fr}.metrics.daily .range-row{grid-template-columns:8px minmax(82px,1fr) auto;gap:5px;font-size:9px}.metrics.daily .range-row b{font-size:10px}.metrics.weekly .metric-card{grid-template-columns:145px minmax(0,1fr)}.metrics.weekly .metric-main{padding:11px}.metrics.weekly .metric-title{font-size:10px}.metrics.weekly .metric-main strong{font-size:28px}.metrics.weekly .metric-main .value-large{font-size:25px}.metrics.weekly .ranges{padding:7px 8px}.metrics.weekly .range-row{grid-template-columns:8px minmax(75px,1fr) auto;gap:4px;padding:2px 0;font-size:8px}.metrics.weekly .range-row b{font-size:9px}
    @media(max-width:850px){.metrics,.metrics.daily,.metrics.weekly{grid-template-columns:1fr}}@media(max-width:520px){.report{width:calc(100% - 16px);margin-top:8px}.header{align-items:flex-start;padding:15px}.meta{display:none}.metric-card{grid-template-columns:42% 1fr}.range-row{grid-template-columns:8px 1fr}.range-row b{grid-column:2}.metric-main{padding:12px}}
    <?php if ($captureMode): ?>body{background:#fff}.report{width:1120px;margin:7px auto}.topbar,.filter{display:none}.header{padding:12px 17px;box-shadow:none}.section{margin-top:9px}.metric-card{min-height:132px}.metric-main{padding:11px}.metric-title{min-height:27px;font-size:11px}.metric-main strong{font-size:31px}.metric-main .value-large{font-size:27px}.metrics.weekly .metric-main .value-large{font-size:24px}.ranges{padding:7px 10px}.range-row{padding:1px 0}.modal{display:none!important}<?php endif; ?>
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body><main class="report">
  <div class="topbar"><a class="back" href="../index.php">Centro de reportes</a></div>
  <header class="header"><div><h1><?= $e($report['titulo'] ?? '') ?></h1><p class="subtitle"><?= $e($report['subtitulo'] ?? '') ?></p></div><div class="meta"><strong><?= $e($report['fecha_label'] ?? '') ?></strong><span>Semana <?= $e($report['semana_inicio'] ?? '') ?> – <?= $e($report['semana_fin'] ?? '') ?></span><span>Actualizado <?= $e($report['actualizado'] ?? '') ?></span><form class="filter"><input type="date" name="fecha" value="<?= $e($report['fecha'] ?? '') ?>"><button>Consultar</button></form></div></header>
  <?php if (($report['warning'] ?? '') !== ''): ?><div class="warning"><?= $e($report['warning']) ?></div><?php endif; ?>
  <section class="section"><div class="section-head"><h2>Movimiento del día</h2><span>Haz clic en una tarjeta para ver el detalle</span></div><div class="metrics daily"><?php foreach ($dailyMetrics as $metric) $renderCard((array)$metric); ?></div></section>
  <section class="section"><div class="section-head"><h2>Acumulado semanal</h2><span>Objetivos progresivos al día <?= $e($report['factor_semanal'] ?? '') ?> de la semana</span></div><div class="metrics weekly"><?php foreach ($weeklyMetrics as $metric) $renderCard((array)$metric); ?></div></section>
</main>
<div class="modal" data-modal><div class="dialog"><header class="dialog-head"><h3 data-modal-title>Detalle</h3><button class="close" type="button" data-modal-close aria-label="Cerrar">×</button></header><div data-modal-body></div></div></div>
<script>
const reportDetails=<?= $detailsJson ?>;const modal=document.querySelector('[data-modal]');const body=document.querySelector('[data-modal-body]');const title=document.querySelector('[data-modal-title]');const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));const close=()=>modal.classList.remove('open');document.querySelectorAll('[data-detail-key]').forEach(card=>card.addEventListener('click',()=>{const rows=reportDetails[card.dataset.detailKey]||[];title.textContent=card.dataset.detailTitle||'Detalle';body.innerHTML=rows.length?`<table class="detail-table"><thead><tr><th>Concepto</th><th>Referencia</th><th>Kilogramos</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.concepto)}</td><td>${esc(row.referencia)}</td><td>${Number(row.kg||0).toLocaleString('es-MX',{minimumFractionDigits:0,maximumFractionDigits:2})}</td></tr>`).join('')}</tbody></table>`:'<div class="empty">No hay movimientos para este indicador.</div>';modal.classList.add('open')}));document.querySelector('[data-modal-close]').addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close()});document.addEventListener('keydown',event=>{if(event.key==='Escape')close()});
</script></body></html>
