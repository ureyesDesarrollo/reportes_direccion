<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
$today = $now->format('Y-m-d');
$requestedDate = trim((string)($_GET['fecha'] ?? ''));
$records = equipmentLoadRecords();
$selectedDate = equipmentValidDate($requestedDate) ? $requestedDate : $today;
$saved = equipmentLoadData($selectedDate);
if ($requestedDate === '' && $saved === []) {
  $latestDate = equipmentLatestDate($records, $today);
  if ($latestDate !== null) {
    $selectedDate = $latestDate;
    $saved = equipmentLoadData($selectedDate);
  }
}
$report = equipmentMergeData($config, $saved);
$dualEquipment = ['total' => null, 'disponibles' => null, 'porcentaje' => null, 'ultima_captura' => ''];
try {
  $dualDb = (array)($config['equipos_duales_database'] ?? []);
  $dualTable = trim((string)($dualDb['tabla'] ?? 'equipos_duales'));
  $dualHistoryTable = trim((string)($dualDb['tabla_historial'] ?? 'historial_duales'));
  if (!preg_match('/^[A-Za-z0-9_]+$/', $dualTable) || !preg_match('/^[A-Za-z0-9_]+$/', $dualHistoryTable)) {
    throw new RuntimeException('Tabla de equipos duales inválida.');
  }
  $dualDsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string)($dualDb['host'] ?? ''),
    (int)($dualDb['port'] ?? 3306),
    (string)($dualDb['dbname'] ?? ''),
    (string)($dualDb['charset'] ?? 'utf8mb4')
  );
  $dualPdo = new PDO($dualDsn, (string)($dualDb['user'] ?? ''), (string)($dualDb['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => max(1, (int)($dualDb['timeout'] ?? 3)),
  ]);
  $dualRow = $dualPdo->query(
    'SELECT COUNT(*) AS total,'
    . " SUM(CASE WHEN LOWER(TRIM(estado_a)) IN ('verde', 'ok')"
    . " AND LOWER(TRIM(estado_b)) IN ('verde', 'ok') THEN 1 ELSE 0 END) AS disponibles"
    . ' FROM `' . $dualTable . '`'
  )->fetch() ?: [];
  $dualEquipment['total'] = is_numeric($dualRow['total'] ?? null) ? (int)$dualRow['total'] : null;
  $dualEquipment['disponibles'] = is_numeric($dualRow['disponibles'] ?? null) ? (int)$dualRow['disponibles'] : null;
  if (($dualEquipment['total'] ?? 0) > 0 && is_int($dualEquipment['disponibles'])) {
    $dualEquipment['porcentaje'] = ($dualEquipment['disponibles'] / $dualEquipment['total']) * 100;
  }
  $dualLastCapture = $dualPdo->query('SELECT MAX(fecha_registro) FROM `' . $dualHistoryTable . '`')->fetchColumn();
  if (is_string($dualLastCapture) && trim($dualLastCapture) !== '') {
    $dualLastCaptureDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', trim($dualLastCapture), new DateTimeZone('America/Mexico_City'));
    if ($dualLastCaptureDate instanceof DateTimeImmutable) {
      $dualEquipment['ultima_captura'] = $dualLastCaptureDate->format('d/m/Y H:i');
    }
  }
} catch (Throwable $exception) {
  $dualEquipment = ['total' => null, 'disponibles' => null, 'porcentaje' => null, 'ultima_captura' => ''];
}
$registeredDates = array_slice(equipmentRegisteredDates($records), 0, 10);
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$number = static fn($value): string => is_numeric($value) ? number_format((float)$value, 0, '.', ',') : '—';
$money = static fn($value): string => is_numeric($value) ? '$' . number_format((float)$value, 0, '.', ',') : '—';
$equipment = (array)($report['equipos'] ?? []);
$totals = ['total' => 0, 'ok' => 0, 'no_ok' => 0];
$hasData = false;
foreach ($equipment as $item) {
  foreach (array_keys($totals) as $key) $totals[$key] += is_numeric($item[$key] ?? null) ? (int)$item[$key] : 0;
  if (is_numeric($item['total'] ?? null)) $hasData = true;
}
$availability = $totals['total'] > 0 ? ($totals['ok'] / $totals['total']) * 100 : null;
$dateLabel = (new DateTimeImmutable($selectedDate, new DateTimeZone('America/Mexico_City')))->format('d/m/Y');
$capturedAt = trim((string)($saved['capturado_en'] ?? ''));
$capturedAtLabel = '';
if ($capturedAt !== '') {
  $capturedAtDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $capturedAt, new DateTimeZone('America/Mexico_City'));
  if ($capturedAtDate instanceof DateTimeImmutable) $capturedAtLabel = $capturedAtDate->format('d/m/Y H:i:s');
}
if ($capturedAtLabel === '' && $saved !== []) $capturedAtLabel = trim((string)($saved['actualizado'] ?? ''));
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($report['titulo'] ?? 'Estado de Equipos') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--navy:#1e3a5f;--blue:#2563eb;--soft:#f4f7fb;--green:#15803d;--green-soft:#dcfce7;--red:#c2413b;--red-soft:#fee2e2;}
    *{box-sizing:border-box} body{margin:0;color:var(--ink);background:#f1f5f9;font-family:Inter,system-ui,sans-serif}.report{width:min(1120px,calc(100% - 28px));margin:18px auto 38px}.top-actions{display:flex;justify-content:space-between;gap:10px;margin-bottom:11px}.pill{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid var(--line);border-radius:999px;color:#334155;background:#fff;font-size:12px;font-weight:800;text-decoration:none}.capture-link{font-size:11px;opacity:.78}.capture-link:hover{opacity:1;border-color:#93c5fd;background:#eff6ff}.header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:19px 22px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}.title-wrap{display:flex;align-items:center;gap:14px}.title-icon{display:grid;place-items:center;width:54px;height:54px;border-radius:16px;color:#fff;background:linear-gradient(145deg,var(--blue),var(--navy));font-size:23px}h1{margin:0;font-size:clamp(27px,4vw,40px);font-weight:900}.subtitle{margin:5px 0 0;color:var(--muted);font-size:13px;font-weight:700}.meta{text-align:right}.meta strong{display:block;font-size:14px}.meta span{display:block;margin-top:4px;color:var(--muted);font-size:11px;font-weight:700}.picker{display:flex;gap:6px;margin-top:8px}.picker input{height:30px;padding:4px 7px;border:1px solid var(--line);border-radius:8px;font:inherit;font-size:11px;font-weight:800}.picker button{border:0;border-radius:8px;color:#fff;background:var(--navy);font:inherit;font-size:11px;font-weight:900}.history{display:flex;align-items:center;gap:6px;margin-top:8px;padding:8px 10px;overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:#fff}.history>span{color:var(--muted);font-size:10px;font-weight:900;text-transform:uppercase;white-space:nowrap}.history a{padding:5px 8px;border:1px solid var(--line);border-radius:999px;color:#475569;background:#f8fafc;font-size:10px;font-weight:900;text-decoration:none;white-space:nowrap}.history a.active{color:#fff;border-color:var(--blue);background:var(--blue)}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:14px}.summary-card{padding:15px 17px;border:1px solid var(--line);border-radius:16px;background:#fff}.summary-card span{display:block;color:var(--muted);font-size:11px;font-weight:900;text-transform:uppercase}.summary-card strong{display:block;margin-top:7px;font-size:31px;line-height:1;font-weight:900}.summary-card.ok{border-color:#86efac}.summary-card.ok strong{color:var(--green)}.summary-card.bad{border-color:#fca5a5}.summary-card.bad strong{color:var(--red)}.panel{margin-top:13px;overflow:hidden;border:1px solid var(--line);border-radius:18px;background:#fff}.panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 17px;color:#fff;background:linear-gradient(145deg,var(--navy),#334e72)}.panel-title h2{margin:0;font-size:16px;font-weight:900;text-transform:uppercase}.panel-title span{font-size:11px;font-weight:800;opacity:.85}.equipment-table{width:100%;border-collapse:collapse}.equipment-table th,.equipment-table td{padding:10px 14px;border-bottom:1px solid #e7edf5;text-align:center}.equipment-table th{color:#475569;background:var(--soft);font-size:11px;font-weight:900;text-transform:uppercase}.equipment-table th:first-child,.equipment-table td:first-child{text-align:left}.equipment-name{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:900}.equipment-name i{display:grid;place-items:center;width:30px;height:30px;border-radius:9px;color:var(--blue);background:#eff6ff}.equipment-table td{font-size:16px;font-weight:900}.equipment-table tbody tr:last-child td{border-bottom:0}.state{display:inline-flex;min-width:70px;justify-content:center;padding:6px 9px;border-radius:999px;font-size:10px;font-weight:900;text-transform:uppercase}.state.ok{color:#166534;background:var(--green-soft)}.state.bad{color:#991b1b;background:var(--red-soft)}.state.empty{color:#64748b;background:#e2e8f0}.footer-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:13px}.large-stat{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:18px 20px;border:1px solid var(--line);border-radius:17px;background:#fff}.large-stat span{color:#475569;font-size:13px;font-weight:900;text-transform:uppercase}.large-stat strong{font-size:32px;font-weight:900;white-space:nowrap}.large-stat i{margin-right:8px;color:var(--blue)}
    .large-stat-label{display:flex;flex-direction:column;gap:4px}.large-stat-label small{color:var(--muted);font-size:10px;font-weight:800}
    @media(max-width:760px){.summary{grid-template-columns:1fr 1fr}.footer-stats{grid-template-columns:1fr}.meta{display:none}.equipment-table th,.equipment-table td{padding:9px 7px}.equipment-name span{font-size:11px}}
    <?php if ($captureMode): ?>body{background:#fff}.report{width:1060px;margin:7px auto}.top-actions,.history,.picker{display:none}.header{padding:13px 17px;box-shadow:none}.summary{margin-top:9px}.summary-card{padding:11px 14px}.summary-card strong{font-size:26px}.panel{margin-top:9px}.equipment-table th,.equipment-table td{padding:6px 10px}.footer-stats{margin-top:9px}.large-stat{padding:12px 16px}<?php endif; ?>
  </style>
</head>
<body><main class="report">
  <div class="top-actions"><a class="pill" href="../index.php">Centro de reportes</a><a class="pill capture-link" href="captura.php?fecha=<?= $e($selectedDate) ?>">Capturar</a></div>
  <header class="header"><div class="title-wrap"><div><h1><?= $e($report['titulo'] ?? 'Estado de Equipos') ?></h1><p class="subtitle"><?= $e($report['subtitulo'] ?? '') ?></p></div></div><div class="meta"><strong>Corte <?= $e($dateLabel) ?></strong><span><?= $saved === [] ? 'Sin captura registrada' : 'Capturado ' . $e($capturedAtLabel) ?></span><form class="picker"><input type="date" name="fecha" value="<?= $e($selectedDate) ?>"><button>Consultar</button></form></div></header>
  <?php if ($registeredDates !== []): ?><nav class="history"><span>Cortes registrados</span><?php foreach ($registeredDates as $date): ?><a class="<?= $date === $selectedDate ? 'active' : '' ?>" href="?fecha=<?= $e($date) ?>"><?= $e((new DateTimeImmutable($date))->format('d/m/Y')) ?></a><?php endforeach; ?></nav><?php endif; ?>
  <section class="summary"><article class="summary-card"><span>Total de equipos</span><strong><?= $number($hasData ? $totals['total'] : null) ?></strong></article><article class="summary-card ok"><span>Equipos OK</span><strong><?= $number($hasData ? $totals['ok'] : null) ?></strong></article><article class="summary-card bad"><span>Equipos No OK</span><strong><?= $number($hasData ? $totals['no_ok'] : null) ?></strong></article><article class="summary-card"><span>Disponibilidad</span><strong><?= $availability !== null ? number_format($availability, 1) . '%' : '—' ?></strong></article></section>
  <section class="panel"><header class="panel-title"><h2>Estado por equipo</h2><span>OK + No OK = Total</span></header><table class="equipment-table"><thead><tr><th>Equipo</th><th># equipos</th><th>OK</th><th>No OK</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($equipment as $item): $total=is_numeric($item['total']??null)?(int)$item['total']:null;$ok=is_numeric($item['ok']??null)?(int)$item['ok']:null;$noOk=is_numeric($item['no_ok']??null)?(int)$item['no_ok']:null;$state=$total===null?'empty':($noOk>0?'bad':'ok'); ?>
    <tr><td><span class="equipment-name"><span><?= $e($item['label'] ?? '') ?></span></span></td><td><?= $number($total) ?></td><td><?= $number($ok) ?></td><td><?= $number($noOk) ?></td><td><span class="state <?= $state ?>"><?= $state==='ok'?'OK':($state==='bad'?'No OK':'Sin dato') ?></span></td></tr>
  <?php endforeach; ?>
  </tbody></table></section>
  <section class="footer-stats"><article class="large-stat"><div class="large-stat-label"><span>Equipos duales<?= is_int($dualEquipment['disponibles']) && is_int($dualEquipment['total']) ? ' · ' . $e($dualEquipment['disponibles']) . ' de ' . $e($dualEquipment['total']) : '' ?></span><small><?= ($dualEquipment['ultima_captura'] ?? '') !== '' ? 'Última captura: ' . $e($dualEquipment['ultima_captura']) : 'Sin fecha de captura' ?></small></div><strong><?= is_numeric($dualEquipment['porcentaje'] ?? null) ? number_format((float)$dualEquipment['porcentaje'], 2) . '%' : '—' ?></strong></article><article class="large-stat"><span>Costo de mantenimiento</span><strong><?= $money($report['costo_mantenimiento'] ?? null) ?></strong></article></section>
</main></body></html>
