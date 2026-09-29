<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';

$loadError = null;
try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  $loadError = 'No fue posible consultar el inventario del servidor 105.';
  $report = [
    'titulo' => (string)($config['titulo'] ?? 'Inventario de Materia Prima'),
    'subtitulo' => (string)($config['subtitulo'] ?? ''),
    'filtros' => ['anio' => (int)date('Y'), 'mes' => (int)date('n'), 'anios' => [(int)date('Y')], 'meses' => [], 'material' => null, 'proveedor' => null],
    'opciones' => ['materiales' => [], 'proveedores' => []],
    'filas' => [], 'compras_cuero_americano' => [], 'criterios' => (array)($config['parametros'] ?? []),
    'meta' => ['periodo_label' => date('m/Y'), 'generado_en' => '—'], 'version' => time(),
  ];
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 2): string => is_numeric($value) ? n((float)$value, $decimals) : '—';
$capture = isset($_GET['capture']) && (string)$_GET['capture'] === '1';
$filters = (array)$report['filtros'];
$options = (array)$report['opciones'];
$rows = (array)$report['filas'];
$americanPurchases = (array)($report['compras_cuero_americano'] ?? []);
$americanSections = [
  'cuero_entero' => [
    'titulo' => 'Cuero entero',
    'descripcion' => 'Todos los tipos de cuero entero, incluidos los depilados, reunidos en esta sección.',
    'filas' => $americanPurchases,
  ],
];
$criteria = (array)$report['criterios'];
$meta = (array)$report['meta'];
$version = (int)$report['version'];
$exportParams = array_filter([
  'anio' => $filters['anio'] ?? null,
  'mes' => $filters['mes'] ?? null,
  'material' => $filters['material'] ?? null,
  'proveedor' => $filters['proveedor'] ?? null,
], static fn($value): bool => $value !== null && $value !== '');
$exportUrl = 'exportar.php?' . http_build_query($exportParams);

$cell = static function (array $metric) use ($e, $fmt): string {
  $status = (array)($metric['status'] ?? []);
  $key = in_array((string)($status['key'] ?? ''), ['verde', 'amarillo', 'rojo'], true) ? (string)$status['key'] : 'gris';
  $value = $metric['value'] ?? null;
  $range = (string)($status['range'] ?? '');
  $title = trim((string)($status['label'] ?? '') . ($range !== '' ? ' · Objetivo ' . $range : ''));
  return '<td class="metric-cell state-' . $e($key) . '" title="' . $e($title) . '"><strong>' . $fmt($value) . '</strong></td>';
};

$rangeLabel = static fn(string $key): string => (string)($criteria[$key]['leyenda'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($report['titulo']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= $version ?>">
  <script src="../../assets/js/display-mode.js?v=<?= $version ?>"></script>
  <style>
    :root{--navy:#102a43;--navy2:#174d6b;--teal:#0f766e;--line:#dbe5ef;--muted:#64748b;--green:#2e8b57;--yellow:#facc15;--red:#c94436;--gray:#94a3b8}
    *{box-sizing:border-box}body{margin:0;background:#eef3f8;color:#172033;font-family:Inter,sans-serif}.mp-page{width:min(1880px,calc(100% - 28px));margin:0 auto;padding:16px 0 26px}.mp-header{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:12px}.mp-heading{min-width:0}.mp-back{display:inline-flex;align-items:center;gap:7px;margin-bottom:6px;color:#31516f;text-decoration:none;font-size:.78rem;font-weight:800}.mp-title{margin:0;color:var(--navy);font-size:clamp(1.55rem,2.2vw,2.25rem);line-height:1.08}.mp-subtitle{margin:5px 0 0;color:var(--muted);font-size:.86rem;font-weight:600}.mp-meta{text-align:right;color:var(--muted);font-size:.75rem;font-weight:700;white-space:nowrap}.panel{background:#fff;border:1px solid var(--line);border-radius:16px;box-shadow:0 7px 22px rgba(15,23,42,.05)}.alert{margin-bottom:12px;padding:12px 15px;border:1px solid #fecdd3;border-radius:12px;background:#fff1f2;color:#9f1239;font-weight:800}.filters{display:grid;grid-template-columns:120px 150px minmax(190px,1fr) minmax(220px,1.3fr) auto auto;gap:10px;align-items:end;padding:11px;margin-bottom:12px}.field label{display:block;margin:0 0 5px;color:#52657a;font-size:.68rem;font-weight:900;text-transform:uppercase;letter-spacing:.045em}.field input,.field select{width:100%;height:40px;padding:8px 10px;border:1px solid #cbd8e6;border-radius:10px;background:#fff;color:#172033;font:inherit;font-size:.8rem}.download{color:#fff!important;background:var(--teal)!important}.clear{display:inline-flex;align-items:center;justify-content:center;gap:7px;height:40px;padding:0 14px;border-radius:10px;background:#edf3f8;color:#31516f;text-decoration:none;font-size:.78rem;font-weight:800}.legend{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:9px 12px;margin-bottom:12px}.legend-title{color:#52657a;font-size:.7rem;font-weight:900;text-transform:uppercase}.legend-items{display:flex;align-items:center;flex-wrap:wrap;gap:13px;color:#52657a;font-size:.7rem;font-weight:700}.legend-item{display:inline-flex;align-items:center;gap:5px}.dot{width:9px;height:9px;border-radius:50%}.dot.green,.dot.verde{background:var(--green)}.dot.yellow,.dot.amarillo{background:var(--yellow)}.dot.red,.dot.rojo{background:var(--red)}.dot.gray,.dot.gris{background:var(--gray)}.table-panel{overflow:hidden;margin-bottom:14px}.table-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #e4ebf3}.table-head h2{margin:0;color:#17324d;font-size:.98rem}.table-head p{margin:3px 0 0;color:#718096;font-size:.68rem}.count{padding:5px 9px;border-radius:999px;background:#e7f3f1;color:var(--teal);font-size:.68rem;font-weight:900}.table-wrap{overflow:auto;max-height:720px}table{width:100%;min-width:1450px;border-collapse:separate;border-spacing:0;font-size:.7rem}th{position:sticky;top:0;z-index:2;padding:8px 7px;border-right:1px solid rgba(255,255,255,.18);background:var(--navy2);color:#fff;text-align:center;white-space:nowrap}th small{display:block;margin-top:3px;color:#dbeafe;font-size:.56rem;font-weight:600}td{padding:8px 7px;border-bottom:1px solid #e5edf4;background:#fff;color:#24364b;text-align:center;white-space:nowrap}tbody tr:nth-child(even) td:not(.metric-cell){background:#f7fafc}.text-left{text-align:left}.kilos{font-weight:800}.metric-cell{font-variant-numeric:tabular-nums}.metric-cell strong{font-size:.76rem}.state-verde{background:var(--green)!important;color:#fff!important}.state-amarillo{background:var(--yellow)!important;color:#111827!important}.state-rojo{background:var(--red)!important;color:#fff!important}.state-gris{background:var(--gray)!important;color:#fff!important}.status{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border-radius:999px;font-size:.63rem;font-weight:900}.status .dot{background:currentColor;opacity:.82}.obs{max-width:250px;overflow:hidden;text-overflow:ellipsis}.empty{padding:38px;text-align:center;color:var(--muted);font-weight:700}.purchase-table{width:100%;min-width:1760px;table-layout:fixed}.purchase-table th{white-space:normal;line-height:1.08}.purchase-table td{overflow:hidden;text-overflow:ellipsis}.purchase-row td{font-size:.69rem}.detail-toggle{display:inline-flex;align-items:center;gap:5px;border:0;background:transparent;color:var(--teal);font:inherit;font-weight:900;cursor:pointer}.detail-toggle i{transition:transform .2s}.detail-toggle[aria-expanded="true"] i{transform:rotate(90deg)}.delivery-row[hidden]{display:none}.delivery-row td{padding:10px 18px;background:#edf3f8!important}.delivery-box{overflow:auto;border:1px solid #d7e2ec;border-radius:10px;background:#fff}.delivery-box table{min-width:900px;font-size:.66rem}.delivery-box th{position:static;background:#31516f}.delivery-box td{padding:7px;background:#fff!important}.section-note{font-weight:700;color:#64748b}
    @media(max-width:760px){.mp-page{width:calc(100% - 18px);padding-top:10px}.mp-header{align-items:flex-start}.mp-meta{display:none}.filters{grid-template-columns:1fr 1fr}.filters .field:nth-child(3),.filters .field:nth-child(4){grid-column:1/-1}.clear{width:100%}.legend{align-items:flex-start;flex-direction:column}}
    body.capture-mode{background:#fff}body.capture-mode .mp-page{width:1880px;max-width:1880px;padding:8px 12px}body.capture-mode .mp-back,body.capture-mode .filters{display:none}body.capture-mode .mp-header{margin-bottom:7px}body.capture-mode .legend{padding:6px 10px;margin-bottom:7px}body.capture-mode .table-wrap{max-height:none}body.capture-mode table{min-width:0;font-size:.62rem}body.capture-mode th,body.capture-mode td{padding:5px 4px}
  </style>
</head>
<body class="<?= $capture ? 'capture-mode' : '' ?>">
<main class="mp-page">
  <header class="mp-header">
    <div class="mp-heading">
      <a class="mp-back" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Reportes</a>
      <h1 class="mp-title"><?= $e($report['titulo']) ?></h1>
      <p class="mp-subtitle"><?= $e($report['subtitulo']) ?> · <?= $e($meta['periodo_label'] ?? '') ?></p>
    </div>
    <div class="mp-meta">Actualizado<br><strong><?= $e($meta['generado_en'] ?? '—') ?></strong></div>
  </header>

  <?php if ($loadError !== null): ?><div class="alert"><?= $e($loadError) ?></div><?php endif; ?>

  <form class="panel filters" method="get" data-filter-form>
    <div class="field"><label for="anio">Año</label><select id="anio" name="anio"><?php foreach ((array)($filters['anios'] ?? []) as $year): ?><option value="<?= (int)$year ?>" <?= (int)($filters['anio'] ?? 0)===(int)$year?'selected':'' ?>><?= (int)$year ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="mes">Mes</label><select id="mes" name="mes"><?php foreach ((array)($filters['meses'] ?? []) as $monthNumber=>$monthName): ?><option value="<?= (int)$monthNumber ?>" <?= (int)($filters['mes'] ?? 0)===(int)$monthNumber?'selected':'' ?>><?= $e(ucfirst((string)$monthName)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="material">Material</label><select id="material" name="material"><option value="">Todos</option><?php foreach ((array)($options['materiales'] ?? []) as $item): ?><option value="<?= (int)$item['mat_id'] ?>" <?= (int)($filters['material'] ?? 0)===(int)$item['mat_id']?'selected':'' ?>><?= $e($item['mat_nombre']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="proveedor">Proveedor</label><select id="proveedor" name="proveedor"><option value="">Todos</option><?php foreach ((array)($options['proveedores'] ?? []) as $item): ?><option value="<?= (int)$item['prv_id'] ?>" <?= (int)($filters['proveedor'] ?? 0)===(int)$item['prv_id']?'selected':'' ?>><?= $e($item['prv_nombre']) ?></option><?php endforeach; ?></select></div>
    <a class="clear download" href="<?= $e($exportUrl) ?>"><i class="fa-solid fa-file-excel"></i> Excel</a>
    <a class="clear" href="./"><i class="fa-solid fa-rotate-left"></i> Limpiar</a>
  </form>

  <section class="panel legend">
    <div class="legend-title">Semáforo por parámetro</div>
    <div class="legend-items"><span class="legend-item"><i class="dot green"></i>Verde (objetivo)</span><span class="legend-item"><i class="dot yellow"></i>Amarillo</span><span class="legend-item"><i class="dot red"></i>Rojo</span><span class="legend-item"><i class="dot gray"></i>Sin dato o sin rango</span></div>
  </section>

  <section class="panel table-panel">
    <div class="table-head"><div><h2>Resultados de entrada</h2><p>Una fila por registro de inventario. El estado general corresponde al parámetro más crítico.</p></div><span class="count"><?= count($rows) ?> registros</span></div>
    <div class="table-wrap">
      <?php if ($rows === []): ?><div class="empty">No hay entradas de inventario para los filtros seleccionados.</div><?php else: ?>
      <table>
        <thead><tr>
          <th>No.</th><th>Ticket</th><th>Fecha</th><th>Kilos</th><th>Tipo de material</th><th>Proveedor</th>
          <th>Humedad<small><?= $e($rangeLabel('humedad')) ?></small></th>
          <th>Conductividad<small>Objetivo según material</small></th>
          <th>pH<small><?= $e($rangeLabel('ph')) ?></small></th>
          <th>Sólidos<small><?= $e($rangeLabel('solidos')) ?></small></th>
          <th>Extractibilidad<small><?= $e($rangeLabel('extractibilidad')) ?></small></th>
          <th>Rendimiento<small><?= $e($rangeLabel('rendimiento')) ?></small></th>
          <th>Semáforo</th><th>Observaciones</th>
        </tr></thead>
        <tbody><?php foreach ($rows as $row): $status=(array)$row['semaforo']; $key=in_array((string)($status['key']??''),['verde','amarillo','rojo'],true)?(string)$status['key']:'gris'; ?>
          <tr>
            <td><?= (int)$row['numero'] ?></td><td><strong><?= (int)$row['ticket'] ?></strong></td><td><?= $e($row['fecha']) ?></td><td class="kilos"><?= $fmt($row['kilos']) ?></td>
            <td class="text-left"><?= $e($row['material']) ?></td><td class="text-left"><?= $e($row['proveedor']) ?></td>
            <?= $cell((array)$row['metricas']['humedad']) ?>
            <?= $cell((array)$row['metricas']['conductividad']) ?>
            <?= $cell((array)$row['metricas']['ph']) ?>
            <?= $cell((array)$row['metricas']['solidos']) ?>
            <?= $cell((array)$row['metricas']['extractibilidad']) ?>
            <?= $cell((array)$row['metricas']['rendimiento']) ?>
            <td><span class="status state-<?= $e($key) ?>"><i class="dot <?= $e($key) ?>"></i><?= $e($status['label'] ?? 'Pendiente') ?></span></td>
            <td class="text-left obs" title="<?= $e($row['observaciones']) ?>"><?= $e($row['observaciones'] !== '' ? $row['observaciones'] : '—') ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table>
      <?php endif; ?>
    </div>
  </section>

  <?php foreach ($americanSections as $sectionKey => $americanSection): $sectionRows=(array)$americanSection['filas']; ?>
  <section class="panel table-panel">
    <div class="table-head">
      <div>
        <h2><?= $e($americanSection['titulo']) ?></h2>
        <p><?= $e($americanSection['descripcion']) ?></p>
      </div>
      <span class="count"><?= count($sectionRows) ?> tickets</span>
    </div>
    <div class="table-wrap">
      <?php if ($sectionRows === []): ?>
        <div class="empty">No hay registros de <?= $e(strtolower((string)$americanSection['titulo'])) ?> para los filtros seleccionados.</div>
      <?php else: ?>
        <table class="purchase-table">
          <colgroup>
            <col style="width:82px"><col style="width:70px"><col style="width:170px"><col style="width:190px">
            <col style="width:94px"><col style="width:104px"><col style="width:94px"><col style="width:96px"><col style="width:90px">
            <col span="6" style="width:92px"><col style="width:130px">
          </colgroup>
          <thead><tr>
            <th>Fecha</th><th>Ticket</th><th>Proveedor</th><th>Material</th>
            <th>Kg compra</th><th>Humedad origen<small>Objetivo 40–48</small></th>
            <th>Entregas</th><th>Kg granja</th><th>Rend. granja</th>
            <th>Prom. humedad</th><th>Prom. conduct.</th><th>Prom. pH</th>
            <th>Prom. sólidos</th><th>Prom. extract.</th><th>Prom. rendimiento</th><th>Observaciones</th>
          </tr></thead>
          <tbody>
          <?php foreach ($sectionRows as $purchase): $detailId='delivery-' . $sectionKey . '-' . (int)$purchase['ticket'] . '-' . md5((string)$purchase['fecha']); ?>
            <tr class="purchase-row">
              <td><?= $e($purchase['fecha']) ?></td>
              <td><strong><?= (int)$purchase['ticket'] ?></strong></td>
              <td class="text-left" title="<?= $e($purchase['proveedor']) ?>"><?= $e($purchase['proveedor']) ?></td>
              <td class="text-left" title="<?= $e($purchase['material']) ?>"><?= $e($purchase['material']) ?></td>
              <td class="kilos"><?= $fmt($purchase['kilos_compra']) ?></td>
              <?= $cell((array)$purchase['humedad_origen']) ?>
              <td>
                <?php if ((int)$purchase['numero_entregas'] > 0): ?>
                  <button class="detail-toggle" type="button" aria-expanded="false" aria-controls="<?= $e($detailId) ?>" data-detail-toggle="<?= $e($detailId) ?>">
                    <i class="fa-solid fa-chevron-right"></i><?= (int)$purchase['numero_entregas'] ?> <?= (int)$purchase['numero_entregas'] === 1 ? 'entrega' : 'entregas' ?>
                  </button>
                <?php else: ?><span class="section-note">Pendiente</span><?php endif; ?>
              </td>
              <td class="kilos"><?= $fmt($purchase['kilos_granja']) ?></td>
              <td class="kilos"><?= $purchase['rendimiento_granja'] === null ? '—' : $fmt($purchase['rendimiento_granja']) . '%' ?></td>
              <?= $cell((array)$purchase['promedios']['humedad']) ?>
              <?= $cell((array)$purchase['promedios']['conductividad']) ?>
              <?= $cell((array)$purchase['promedios']['ph']) ?>
              <?= $cell((array)$purchase['promedios']['solidos']) ?>
              <?= $cell((array)$purchase['promedios']['extractibilidad']) ?>
              <?= $cell((array)$purchase['promedios']['rendimiento']) ?>
              <td class="text-left obs" title="<?= $e($purchase['observaciones']) ?>"><?= $e($purchase['observaciones'] !== '' ? $purchase['observaciones'] : '—') ?></td>
            </tr>
            <?php if ((array)$purchase['entregas'] !== []): ?>
            <tr class="delivery-row" id="<?= $e($detailId) ?>" hidden><td colspan="16">
              <div class="delivery-box">
                <table>
                  <thead><tr><th>Recepción</th><th>Kg granja</th><th>Humedad</th><th>Conductividad</th><th>pH</th><th>Sólidos</th><th>Extractibilidad</th><th>Rendimiento</th></tr></thead>
                  <tbody><?php foreach ((array)$purchase['entregas'] as $delivery): ?><tr>
                    <td><?= $e($delivery['fecha']) ?></td><td class="kilos"><?= $fmt($delivery['kilos']) ?></td>
                    <td><?= $fmt($delivery['humedad']) ?></td><td><?= $fmt($delivery['conductividad']) ?></td><td><?= $fmt($delivery['ph']) ?></td>
                    <td><?= $fmt($delivery['solidos']) ?></td><td><?= $fmt($delivery['extractibilidad']) ?></td><td><?= $fmt($delivery['rendimiento']) ?></td>
                  </tr><?php endforeach; ?></tbody>
                </table>
              </div>
            </td></tr>
            <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
  <?php endforeach; ?>
</main>
<script>
document.querySelectorAll('[data-filter-form] input,[data-filter-form] select').forEach(control=>control.addEventListener('change',()=>control.form.requestSubmit()));
document.querySelectorAll('[data-detail-toggle]').forEach(button=>button.addEventListener('click',()=>{
  const row=document.getElementById(button.dataset.detailToggle);
  if(!row)return;
  const open=button.getAttribute('aria-expanded')==='true';
  button.setAttribute('aria-expanded',open?'false':'true');
  row.hidden=open;
}));
setTimeout(()=>location.reload(),<?= max(30000, (int)($config['intervalo_actualizacion_ms'] ?? 120000)) ?>);
</script>
</body>
</html>
