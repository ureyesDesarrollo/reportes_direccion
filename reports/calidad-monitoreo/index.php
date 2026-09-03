<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$number = static fn($value, int $decimals = 0): string => is_numeric($value)
  ? number_format((float)$value, max(0, min(2, $decimals)), '.', ',')
  : '—';
$compactNumber = static function ($value, int $decimals = 2): string {
  if (!is_numeric($value)) return '—';
  $decimals = max(0, min(2, $decimals));
  return rtrim(rtrim(number_format((float)$value, $decimals, '.', ''), '0'), '.');
};
$palletIdentifier = static function (array $pallet): string {
  $product = is_numeric($pallet['pro_id'] ?? null) ? 'P' . (int)$pallet['pro_id'] : 'P—';
  if (is_numeric($pallet['pro_id_2'] ?? null)) $product .= '/' . (int)$pallet['pro_id_2'];
  $folio = trim((string)($pallet['folio'] ?? ''));
  return $product . ' T' . ($folio !== '' ? $folio : '—');
};
$cellClass = static fn(array $pallet, string $key): string => in_array($key, (array)($pallet['fuera_parametro'] ?? []), true)
  ? 'out-of-range'
  : '';
$qualityStyle = static function (array $item): string {
  $color = strtoupper(trim((string)($item['calidad_color'] ?? '#9CA3AF')));
  if (preg_match('/^#[0-9A-F]{6}$/', $color) !== 1) $color = '#9CA3AF';
  $red = hexdec(substr($color, 1, 2));
  $green = hexdec(substr($color, 3, 2));
  $blue = hexdec(substr($color, 5, 2));
  $textColor = (($red * 299 + $green * 587 + $blue * 114) / 1000) > 155 ? '#111827' : '#FFFFFF';
  return 'background-color:' . $color . ';border-color:' . $color . ';color:' . $textColor . ';';
};
$shipmentStatusClass = static function ($status): string {
  $normalized = strtoupper(trim((string)$status));
  if (in_array($normalized, ['PENDIENTE', 'PENDIENTES'], true)) return 'status-pending';
  if ($normalized === 'PROCESO') return 'status-process';
  if (in_array($normalized, ['COMPLETADA', 'COMPLETADO', 'FACTURADA', 'FACTURADO'], true)) return 'status-completed';
  if (in_array($normalized, ['CANCELADA', 'CANCELADO'], true)) return 'status-cancelled';
  if (in_array($normalized, ['LIBERADO', 'LIBERADA', 'ETIQUETA LIBERADA'], true)) return 'status-released';
  return 'status-neutral';
};
$captureMode = isset($_GET['capture']);
$allowedViews = ['tarimas', 'revolturas', 'terminadas', 'embarques'];
$requestedView = trim((string)($_GET['vista'] ?? ''));
$fixedView = in_array($requestedView, $allowedViews, true) ? $requestedView : null;
$initialView = $fixedView ?? 'tarimas';
$autoRotate = !$captureMode && $fixedView === null;
$pallets = (array)($report['tarimas'] ?? []);
$mixes = (array)($report['revolturas'] ?? []);
$completedMixes = (array)($report['revolturas_terminadas'] ?? []);
$shipments = (array)($report['embarques'] ?? []);
$summary = (array)($report['resumen'] ?? []);
$meta = (array)($report['meta'] ?? []);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($report['titulo'] ?? 'Calidad Monitoreo') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe5ee;--teal:#0f766e;--soft:#f0fdfa;--surface:#fff;--danger:#c94436;--danger-border:#a9362c}*{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Inter,system-ui,sans-serif;color:var(--ink);background:#f2f6f8}body{overflow:hidden}.page{width:min(1580px,calc(100% - 28px));height:100vh;margin:0 auto;padding:13px 0;display:grid;grid-template-rows:auto minmax(0,1fr);gap:10px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 16px;border:1px solid var(--line);border-radius:18px;background:var(--surface);box-shadow:0 8px 24px rgba(15,23,42,.06)}.title{display:flex;align-items:center;gap:12px}.title-icon{display:grid;place-items:center;width:45px;height:45px;border-radius:13px;color:#fff;background:var(--teal);font-size:20px}.title h1{margin:0;font-size:clamp(24px,2.4vw,34px);font-weight:900}.title p{margin:3px 0 0;color:var(--muted);font-size:10px;font-weight:800}.top-meta{display:flex;align-items:center;gap:8px}.meta-pill,.back{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid var(--line);border-radius:999px;color:#475569;background:#f8fafc;font-size:9px;font-weight:900;text-decoration:none;white-space:nowrap}.dashboard{min-height:0;display:grid;grid-template-rows:minmax(0,1.65fr) minmax(150px,.72fr);gap:9px}.section{min-height:0;overflow:hidden;border:1px solid var(--line);border-radius:18px;background:var(--surface);box-shadow:0 4px 14px rgba(15,23,42,.04)}.section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 13px;border-bottom:1px solid var(--line)}.section-title{display:flex;align-items:center;gap:9px}.section-number{display:grid;place-items:center;width:27px;height:27px;border-radius:8px;color:#fff;background:var(--teal);font-size:10px;font-weight:900}.section-title h2{margin:0;font-size:15px;font-weight:900}.section-title p{margin:2px 0 0;color:var(--muted);font-size:8px;font-weight:800}.section-summary{display:flex;gap:7px}.summary-chip{padding:5px 8px;border-radius:999px;color:#115e59;background:#ccfbf1;font-size:9px;font-weight:900;white-space:nowrap}.table-wrap{height:calc(100% - 49px);padding:7px 10px;overflow:hidden}.analysis-table{width:100%;border-collapse:separate;border-spacing:0 3px;table-layout:fixed;font-size:8px}.analysis-table th{height:25px;padding:4px 3px;color:#fff;background:#0f766e;font-size:7px;font-weight:900;letter-spacing:.02em;text-align:center;text-transform:uppercase;white-space:nowrap}.analysis-table th:first-child{border-radius:8px 0 0 8px}.analysis-table th:last-child{border-radius:0 8px 8px 0}.analysis-table td{height:22px;padding:3px 3px;border-top:1px solid #d9eee9;border-bottom:1px solid #d9eee9;color:#334155;background:#f8fffd;font-weight:800;text-align:center;white-space:nowrap}.analysis-table tbody tr:nth-child(even) td{background:#effbf8}.analysis-table td:first-child{border-left:1px solid #d9eee9;border-radius:7px 0 0 7px;color:#0f766e;font-size:10px;font-weight:900}.analysis-table td:last-child{border-right:1px solid #d9eee9;border-radius:0 7px 7px 0}.analysis-table .quality{max-width:92px;overflow:hidden;text-align:left;text-overflow:ellipsis}.analysis-table td.out-of-range{color:#fff;background:var(--danger);box-shadow:inset 0 0 0 1px var(--danger-border);font-weight:900}.empty{display:grid;place-items:center;height:calc(100% - 49px);padding:24px;color:#94a3b8;font-size:12px;font-weight:800;text-align:center}.warnings{margin:0 11px 8px;padding:7px 9px;border-radius:9px;color:#92400e;background:#fffbeb;font-size:9px;font-weight:800}.pending-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;min-height:0}.pending{display:flex;min-width:0;flex-direction:column;justify-content:space-between;padding:12px 13px;background:linear-gradient(145deg,#fff,#f8fafc)}.pending .section-number{background:#94a3b8}.pending h2{margin:10px 0 0;font-size:14px;font-weight:900}.pending p{margin:5px 0;color:#94a3b8;font-size:9px;font-weight:700}.pending-foot{display:flex;align-items:center;gap:6px;color:#94a3b8;font-size:8px;font-weight:900;text-transform:uppercase}.pending-foot::before{content:"";width:7px;height:7px;border-radius:50%;background:#cbd5e1}@media(max-width:1050px){body{overflow:auto}.page{height:auto;min-height:100vh}.dashboard{grid-template-rows:auto auto}.table-wrap{height:auto;overflow-x:auto}.analysis-table{min-width:1350px}.pending-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pending{min-height:125px}}@media(max-width:620px){.top{align-items:flex-start}.top-meta{display:none}.pending-grid{grid-template-columns:1fr}}<?php if($captureMode): ?>.back{display:none}.page{padding-top:7px;padding-bottom:7px}<?php endif; ?>
    .analysis-table tbody tr td.out-of-range{color:#fff;background:var(--danger)!important;box-shadow:inset 0 0 0 1px var(--danger-border);font-weight:900}
    .analysis-table td.quality,.mix-table td.quality{overflow:hidden;font-weight:900;text-align:center;text-overflow:ellipsis}
    :root{--teal:#1d4ed8;--soft:#eff6ff}
    .dashboard{grid-template-rows:auto minmax(150px,1fr)}
    .table-wrap{height:auto}
    .summary-chip{color:#1e40af;background:#dbeafe}
    .analysis-table th{background:#1d4ed8}
    .analysis-table td{border-color:#dbeafe;background:#f8fafc}
    .analysis-table tbody tr:nth-child(even) td{background:#eff6ff}
    .analysis-table td:first-child{border-left-color:#dbeafe;color:#1d4ed8}
    .analysis-table td:last-child{border-right-color:#dbeafe}
    :root{--teal:#475569;--soft:#f8fafc}
    .summary-chip{color:#334155;background:#e2e8f0}
    .analysis-table th{background:#334155}
    .analysis-table td{border-color:#e2e8f0;background:#fff}
    .analysis-table tbody tr:nth-child(even) td{background:#f1f5f9}
    .analysis-table td:first-child{border-left-color:#e2e8f0;color:#334155}
    .analysis-table td:last-child{border-right-color:#e2e8f0}
    .pending-grid{grid-template-columns:repeat(20,minmax(0,1fr))}
    .mix-section{grid-column:span 8;display:flex;min-width:0;flex-direction:column}
    .completed-section{grid-column:span 8;display:flex;min-width:0;flex-direction:column}
    .shipments-section{grid-column:span 4;display:flex;min-width:0;flex-direction:column}
    .mix-section .section-head{padding:9px 12px}
    .mix-table-wrap{min-height:0;padding:7px 9px;overflow:auto}
    .mix-table{width:100%;border-collapse:separate;border-spacing:0 3px;table-layout:fixed;font-size:8px}
    .mix-table th{padding:5px 3px;color:#fff;background:#334155;font-size:7px;font-weight:900;text-align:center;text-transform:uppercase;white-space:nowrap}
    .mix-table th:first-child{border-radius:7px 0 0 7px}.mix-table th:last-child{border-radius:0 7px 7px 0}
    .mix-table td{padding:5px 3px;border-top:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;color:#334155;background:#fff;font-weight:800;text-align:center;white-space:nowrap}
    .mix-table tbody tr:nth-child(even) td{background:#f1f5f9}
    .mix-table td:first-child{border-left:1px solid #e2e8f0;color:#334155;font-size:10px;font-weight:900}.mix-table td:last-child{border-right:1px solid #e2e8f0}
    .mix-table tbody tr td.out-of-range{color:#fff;background:var(--danger)!important;box-shadow:inset 0 0 0 1px var(--danger-border);font-weight:900}
    .mix-empty{display:grid;place-items:center;min-height:90px;color:#94a3b8;font-size:9px;font-weight:800;text-align:center}
    .shipment-client{max-width:0;overflow:hidden;text-align:left!important;text-overflow:ellipsis}
    .shipment-state span{display:inline-flex;align-items:center;justify-content:center;max-width:100%;padding:4px 6px;border-radius:999px;font-size:7px;font-weight:900;line-height:1;white-space:nowrap}
    .status-pending
    {
      color: #00000010;
      background: #bcc2c9
    }

    .status-process{color:#422006;background:#fde047}
    .status-completed{color:#fff;background:#2563eb}
    .status-cancelled{color:#fff;background:var(--danger)}
    .status-released{color:#fff;background:#16a34a}
    .status-neutral{color:#334155;background:#e2e8f0}
    .shipment-list{display:grid;gap:7px;padding:8px 9px;align-content:start;overflow:auto}
    .shipment-card{display:grid;grid-template-columns:8px minmax(0,1fr);gap:0;align-items:stretch;min-width:0;padding:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:10px;background:#fff}
    .shipment-indicator{display:block;width:100%;height:100%;min-height:52px;border-radius:0}
    .shipment-main{min-width:0;padding:7px 9px 8px}
    .shipment-topline{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-bottom:5px}
    .shipment-order{display:block;color:#64748b;font-size:8px;font-weight:900}
    .shipment-name{display:block;overflow:hidden;color:#0f172a;font-size:11px;font-weight:900;line-height:1.2;text-overflow:ellipsis;white-space:nowrap}
    .shipment-card .shipment-state{display:block;min-width:0}
    .shipment-card .shipment-state span{width:auto;height:auto;min-height:0;padding:4px 8px;border-radius:999px;font-size:7px;letter-spacing:.02em}
    .analysis-table{border-spacing:0 2px}
    .analysis-table td{height:19px;padding:2px 3px}
    .completed-section .mix-table-wrap{overflow:hidden}
    .completed-section .mix-table{border-spacing:0 1px;font-size:6.8px}
    .completed-section .mix-table th{padding:3px 2px;font-size:6px}
    .completed-section .mix-table td{height:14px;padding:2px;font-size:6.5px}
    body{overflow:auto}
    .page{height:auto;min-height:100vh}
    .dashboard{grid-template-rows:auto auto}
    .pending-grid{grid-template-columns:1fr}
    .mix-section,.completed-section,.shipments-section{grid-column:1/-1}
    .table-wrap,.mix-table-wrap{height:auto;overflow:visible}
    .analysis-table{border-spacing:0 4px;font-size:9px}
    .analysis-table th{height:29px;padding:6px 4px;font-size:8px}
    .analysis-table td{height:27px;padding:5px 4px;font-size:9px}
    .analysis-table td:first-child{font-size:11px}
    .mix-table,.completed-section .mix-table{border-spacing:0 4px;font-size:9px}
    .mix-table th,.completed-section .mix-table th{height:29px;padding:6px 4px;font-size:8px}
    .mix-table td,.completed-section .mix-table td{height:27px;padding:5px 4px;font-size:9px}
    .mix-table td:first-child{font-size:11px}
    .shipment-list{grid-template-columns:repeat(3,minmax(0,1fr));overflow:visible}
    .shipment-name{font-size:12px}
    .shipment-card .shipment-state span{font-size:8px}
    @media(max-width:850px){.shipment-list{grid-template-columns:1fr}}
    body{overflow:hidden}
    .page{height:100vh;min-height:0;padding:5px 0;gap:5px}
    .top{padding:6px 11px;border-radius:11px;box-shadow:none}
    .title{gap:9px}.title-icon{width:34px;height:34px;border-radius:8px;font-size:15px}.title h1{font-size:24px}.title p{margin-top:1px;font-size:8px}
    .meta-pill,.back{padding:5px 8px;font-size:7px}
    .dashboard{display:grid;grid-template-rows:auto auto;gap:5px;min-height:0}
    .pending-grid{display:grid;grid-template-columns:1fr;gap:5px;min-height:0}
    .mix-section,.completed-section,.shipments-section{grid-column:1/-1}
    .section{border-radius:10px;box-shadow:none}
    .section-head,.mix-section .section-head{min-height:27px;padding:3px 8px}
    .section-number{width:21px;height:21px;border-radius:5px;font-size:8px}.section-title{gap:6px}.section-title h2{font-size:12px}.section-title p{display:none}
    .summary-chip{padding:3px 6px;font-size:7px}
    .table-wrap,.mix-table-wrap{height:auto;padding:2px 8px 3px;overflow:visible}
    .analysis-table,.mix-table,.completed-section .mix-table{border-spacing:0 1px;font-size:8px;line-height:1}
    .analysis-table th,.mix-table th,.completed-section .mix-table th{height:18px;padding:2px 3px;border-radius:0!important;font-size:7px;line-height:1}
    .analysis-table td,.mix-table td,.completed-section .mix-table td{height:14px;padding:1px 3px;border-radius:0!important;font-size:8px;line-height:1}
    .analysis-table td:first-child,.mix-table td:first-child{font-size:9px}
    .shipment-list{grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;padding:3px 8px 5px;overflow:visible}
    .shipment-card{border-radius:6px}.shipment-indicator{min-height:35px}.shipment-main{padding:4px 7px}.shipment-topline{margin-bottom:2px}.shipment-order{font-size:7px}.shipment-name{font-size:9px}.shipment-card .shipment-state span{padding:3px 6px;font-size:6px}
    .dashboard{display:block;min-height:0}
    .pending-grid{display:contents}
    .monitor-panel{display:none!important;height:100%;min-height:0}
    .monitor-panel.is-active{display:flex!important;flex-direction:column}
    .monitor-panel .section-head{min-height:48px;padding:8px 13px}
    .monitor-panel .section-number{width:30px;height:30px;border-radius:7px;font-size:10px}
    .monitor-panel .section-title h2{font-size:19px}.monitor-panel .section-title p{display:block;font-size:9px}
    .monitor-panel .summary-chip{padding:6px 10px;font-size:10px}
    .monitor-panel .table-wrap,.monitor-panel .mix-table-wrap{flex:1;min-height:0;padding:10px 14px;overflow:hidden}
    .monitor-panel .analysis-table,.monitor-panel .mix-table,.monitor-panel.completed-section .mix-table{border-spacing:0 4px;font-size:11px;line-height:1.1}
    .monitor-panel .analysis-table th,.monitor-panel .mix-table th,.monitor-panel.completed-section .mix-table th{height:31px;padding:7px 5px;border-radius:7px!important;font-size:9px}
    .monitor-panel .analysis-table td,.monitor-panel .mix-table td,.monitor-panel.completed-section .mix-table td{height:30px;padding:6px 5px;border-radius:6px!important;font-size:11px}
    .monitor-panel .analysis-table td:first-child,.monitor-panel .mix-table td:first-child{font-size:13px}
    .monitor-panel.shipments-section .shipment-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-content:start;flex:1;padding:24px;overflow:hidden}
    .monitor-panel.shipments-section .shipment-card{border-radius:12px}.monitor-panel.shipments-section .shipment-indicator{min-height:110px;width:12px}.monitor-panel.shipments-section .shipment-main{padding:18px 20px}.monitor-panel.shipments-section .shipment-topline{margin-bottom:16px}.monitor-panel.shipments-section .shipment-order{font-size:12px}.monitor-panel.shipments-section .shipment-name{font-size:18px;white-space:normal}.monitor-panel.shipments-section .shipment-state span{padding:7px 11px;font-size:10px}
    .pending-grid>.pending{grid-column:span 1;padding:10px}
    @media(max-width:1050px){.pending-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mix-section,.completed-section,.shipments-section{grid-column:1/-1}.pending-grid>.pending{grid-column:span 1}}
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body>
  <main class="page">
    <header class="top">
      <div class="title"><span class="title-icon"><i class="fa-solid fa-vial-circle-check"></i></span><div><h1><?= $e($report['titulo'] ?? '') ?></h1><p>Turno anterior · <?= $e($report['periodo']['label'] ?? '') ?></p></div></div>
      <div class="top-meta"><span class="meta-pill"><i class="fa-solid fa-database"></i><?= $e($meta['fuente'] ?? '') ?></span><span class="meta-pill"><i class="fa-regular fa-clock"></i><?= $e($meta['actualizado'] ?? '') ?></span><a class="back" href="../index.php"><i class="fa-solid fa-arrow-left"></i>Reportes</a></div>
    </header>

    <div class="dashboard">
      <section class="section monitor-panel<?= $initialView === 'tarimas' ? ' is-active' : '' ?>" data-view="tarimas">
        <header class="section-head"><div class="section-title"><span class="section-number">01</span><div><h2>Tarimas en proceso de análisis</h2><p>Todas las tarimas registradas en el turno anterior de 07:00 a 07:00</p></div></div><div class="section-summary"><span class="summary-chip"><?= $e($number($summary['tarimas'] ?? null)) ?> tarimas</span></div></header>
        <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?><div class="warnings"><?= $e($warning) ?></div><?php endforeach; ?>
        <?php if ($pallets === []): ?><div class="empty"><div><i class="fa-regular fa-circle-check"></i><br>No hay tarimas registradas en el turno anterior.</div></div><?php else: ?>
        <div class="table-wrap">
          <table class="analysis-table">
            <colgroup><col style="width:11%"><col style="width:10%"><col style="width:5%"><col style="width:5.4%"><col style="width:4.8%"><col style="width:4.8%"><col style="width:6%"><col style="width:5.3%"><col style="width:4.3%"><col style="width:3.5%"><col style="width:5.3%"><col style="width:4%"><col style="width:6.6%"><col style="width:5.3%"><col style="width:5.3%"></colgroup>
            <thead><tr><th title="pro_id/pro_id_2 tar_folio">Proceso / Folio</th><th>Calidad</th><th>Bloom</th><th>Viscosidad</th><th title="Malla 30">Malla 30</th><th title="Malla 45">Malla 45</th><th title="Transparencia">Transp.</th><th title="Porcentaje de transmitancia">% Transm.</th><th>Color</th><th>Olor</th><th>Redox</th><th>pH</th><th title="Conductividad">Conduct.</th><th>Humedad</th><th>Cenizas</th></tr></thead>
            <tbody>
            <?php foreach ($pallets as $pallet): ?>
              <tr><td title="<?= $e($palletIdentifier($pallet)) ?>"><?= $e($palletIdentifier($pallet)) ?></td><td class="quality" style="<?= $e($qualityStyle($pallet)) ?>" title="Calidad calculada: <?= $e($pallet['calidad'] ?? 'Pendiente') ?>"><?= $e($pallet['calidad'] ?? 'Pendiente') ?></td><td class="<?= $e($cellClass($pallet, 'bloom')) ?>"><?= $e($compactNumber($pallet['bloom'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'viscosidad')) ?>"><?= $e($compactNumber($pallet['viscosidad'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'malla_30')) ?>"><?= $e($compactNumber($pallet['malla_30'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'malla_45')) ?>"><?= $e($compactNumber($pallet['malla_45'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'transparencia')) ?>"><?= $e($compactNumber($pallet['transparencia'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'transmitancia')) ?>"><?= $e($compactNumber($pallet['transmitancia'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'color')) ?>"><?= $e($compactNumber($pallet['color'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'olor')) ?>"><?= $e($compactNumber($pallet['olor'] ?? null, 0)) ?></td><td class="<?= $e($cellClass($pallet, 'redox')) ?>"><?= $e($compactNumber($pallet['redox'] ?? null, 3)) ?></td><td class="<?= $e($cellClass($pallet, 'ph')) ?>"><?= $e($compactNumber($pallet['ph'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'conductividad')) ?>"><?= $e($compactNumber($pallet['conductividad'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'humedad')) ?>"><?= $e($compactNumber($pallet['humedad'] ?? null)) ?></td><td class="<?= $e($cellClass($pallet, 'cenizas')) ?>"><?= $e($compactNumber($pallet['cenizas'] ?? null)) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </section>

      <div class="pending-grid" aria-label="Apartados pendientes de definir">
        <section class="section mix-section monitor-panel<?= $initialView === 'revolturas' ? ' is-active' : '' ?>" data-view="revolturas">
          <header class="section-head"><div class="section-title"><span class="section-number">02</span><div><h2>Revolturas en proceso de análisis</h2><p>Estatus 2 sin Bloom o calidad válida</p></div></div><div class="section-summary"><span class="summary-chip"><?= $e($number(count($mixes))) ?> revolturas</span></div></header>
          <?php if ($mixes === []): ?><div class="mix-empty">No hay revolturas con estatus 2 pendientes de análisis.</div><?php else: ?>
          <div class="mix-table-wrap"><table class="mix-table"><colgroup><col style="width:9%"><col style="width:9%"><col style="width:6%"><col style="width:7%"><col style="width:6%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:5%"><col style="width:5%"><col style="width:6%"><col style="width:5%"><col style="width:7%"><col style="width:6%"><col style="width:6%"></colgroup><thead><tr><th>Folio</th><th>Calidad</th><th>Bloom</th><th>Viscosidad</th><th>Malla 30</th><th>Malla 45</th><th>Transp.</th><th>% Transm.</th><th>Color</th><th>Olor</th><th>Redox</th><th>pH</th><th>Conduct.</th><th>Humedad</th><th>Cenizas</th></tr></thead><tbody>
            <?php foreach ($mixes as $mix): ?><tr><td><?= $e($mix['folio'] ?? '—') ?></td><td class="quality" style="<?= $e($qualityStyle($mix)) ?>"><?= $e($mix['calidad'] ?? 'Pendiente') ?></td><td class="<?= $e($cellClass($mix, 'bloom')) ?>"><?= $e($compactNumber($mix['bloom'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'viscosidad')) ?>"><?= $e($compactNumber($mix['viscosidad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'malla_30')) ?>"><?= $e($compactNumber($mix['malla_30'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'malla_45')) ?>"><?= $e($compactNumber($mix['malla_45'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'transparencia')) ?>"><?= $e($compactNumber($mix['transparencia'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'transmitancia')) ?>"><?= $e($compactNumber($mix['transmitancia'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'color')) ?>"><?= $e($compactNumber($mix['color'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'olor')) ?>"><?= $e($compactNumber($mix['olor'] ?? null, 0)) ?></td><td class="<?= $e($cellClass($mix, 'redox')) ?>"><?= $e($compactNumber($mix['redox'] ?? null, 3)) ?></td><td class="<?= $e($cellClass($mix, 'ph')) ?>"><?= $e($compactNumber($mix['ph'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'conductividad')) ?>"><?= $e($compactNumber($mix['conductividad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'humedad')) ?>"><?= $e($compactNumber($mix['humedad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'cenizas')) ?>"><?= $e($compactNumber($mix['cenizas'] ?? null)) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </section>
        <section class="section completed-section monitor-panel<?= $initialView === 'terminadas' ? ' is-active' : '' ?>" data-view="terminadas">
          <header class="section-head"><div class="section-title"><span class="section-number">03</span><div><h2>Revolturas terminadas</h2><p>Estatus 2 con Bloom y calidad válidos</p></div></div><div class="section-summary"><span class="summary-chip"><?= $e($number(count($completedMixes))) ?></span></div></header>
          <?php if ($completedMixes === []): ?><div class="mix-empty">No hay revolturas terminadas.</div><?php else: ?>
          <div class="mix-table-wrap"><table class="mix-table"><colgroup><col style="width:9%"><col style="width:9%"><col style="width:6%"><col style="width:7%"><col style="width:6%"><col style="width:6%"><col style="width:7%"><col style="width:7%"><col style="width:5%"><col style="width:5%"><col style="width:6%"><col style="width:5%"><col style="width:7%"><col style="width:6%"><col style="width:6%"></colgroup><thead><tr><th>Folio</th><th>Calidad</th><th>Bloom</th><th>Viscosidad</th><th>Malla 30</th><th>Malla 45</th><th>Transp.</th><th>% Transm.</th><th>Color</th><th>Olor</th><th>Redox</th><th>pH</th><th>Conduct.</th><th>Humedad</th><th>Cenizas</th></tr></thead><tbody>
            <?php foreach ($completedMixes as $mix): ?><tr><td><?= $e($mix['folio'] ?? '—') ?></td><td class="quality" style="<?= $e($qualityStyle($mix)) ?>"><?= $e($mix['calidad'] ?? 'Pendiente') ?></td><td class="<?= $e($cellClass($mix, 'bloom')) ?>"><?= $e($compactNumber($mix['bloom'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'viscosidad')) ?>"><?= $e($compactNumber($mix['viscosidad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'malla_30')) ?>"><?= $e($compactNumber($mix['malla_30'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'malla_45')) ?>"><?= $e($compactNumber($mix['malla_45'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'transparencia')) ?>"><?= $e($compactNumber($mix['transparencia'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'transmitancia')) ?>"><?= $e($compactNumber($mix['transmitancia'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'color')) ?>"><?= $e($compactNumber($mix['color'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'olor')) ?>"><?= $e($compactNumber($mix['olor'] ?? null, 0)) ?></td><td class="<?= $e($cellClass($mix, 'redox')) ?>"><?= $e($compactNumber($mix['redox'] ?? null, 3)) ?></td><td class="<?= $e($cellClass($mix, 'ph')) ?>"><?= $e($compactNumber($mix['ph'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'conductividad')) ?>"><?= $e($compactNumber($mix['conductividad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'humedad')) ?>"><?= $e($compactNumber($mix['humedad'] ?? null)) ?></td><td class="<?= $e($cellClass($mix, 'cenizas')) ?>"><?= $e($compactNumber($mix['cenizas'] ?? null)) ?></td></tr><?php endforeach; ?>
          </tbody></table></div>
          <?php endif; ?>
        </section>
        <section class="section shipments-section monitor-panel<?= $initialView === 'embarques' ? ' is-active' : '' ?>" data-view="embarques">
          <header class="section-head"><div class="section-title"><span class="section-number">04</span><div><h2>Embarques del día</h2><p>Órdenes registradas hoy</p></div></div><div class="section-summary"><span class="summary-chip"><?= $e($number(count($shipments))) ?></span></div></header>
          <?php if ($shipments === []): ?><div class="mix-empty">No hay embarques registrados hoy.</div><?php else: ?>
          <div class="shipment-list">
            <?php foreach ($shipments as $shipment): $shipmentClass = $shipmentStatusClass($shipment['estado'] ?? ''); ?><article class="shipment-card"><span class="shipment-indicator <?= $e($shipmentClass) ?>" aria-hidden="true"></span><div class="shipment-main"><div class="shipment-topline"><span class="shipment-order">ORDEN #<?= $e((int)($shipment['id'] ?? 0)) ?></span><div class="shipment-state"><span class="<?= $e($shipmentClass) ?>"><?= $e($shipment['estado'] ?? '—') ?></span></div></div><span class="shipment-name" title="<?= $e($shipment['cliente'] ?? 'Sin cliente') ?>"><?= $e($shipment['cliente'] ?? 'Sin cliente') ?></span></div></article><?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </main>
  <script>
    (() => {
      const panels = Array.from(document.querySelectorAll('.monitor-panel'));
      let activeIndex = Math.max(0, panels.findIndex(panel => panel.classList.contains('is-active')));
      <?php if ($autoRotate): ?>
      window.setInterval(() => {
        panels[activeIndex]?.classList.remove('is-active');
        activeIndex = (activeIndex + 1) % panels.length;
        panels[activeIndex]?.classList.add('is-active');
      }, 10000);
      <?php endif; ?>
      window.setTimeout(() => window.location.reload(), <?= (int)($meta['intervalo_actualizacion_ms'] ?? 120000) ?>);
    })();
  </script>
</body>
</html>
