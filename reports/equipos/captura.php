<?php

declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
$selectedDate = trim((string)($_GET['fecha'] ?? $now->format('Y-m-d')));
if (!equipmentValidDate($selectedDate)) $selectedDate = $now->format('Y-m-d');
$records = equipmentLoadRecords();
$saved = equipmentLoadData($selectedDate);
$values = equipmentMergeData($config, $saved);
$carriedTotals = [];
if ($saved === []) {
  $carriedTotals = equipmentLatestTotals($records, $selectedDate);
  foreach ($carriedTotals as $equipmentKey => $total) {
    if (isset($values['equipos'][$equipmentKey]) && is_array($values['equipos'][$equipmentKey])) {
      $values['equipos'][$equipmentKey]['total'] = $total;
    }
  }
}
$error = '';
$success = isset($_GET['guardado']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$field = static fn(array $item, string $key): string => is_numeric($item[$key] ?? null) ? (string)$item[$key] : '';
if (!isset($_SESSION['equipment_csrf']) || !is_string($_SESSION['equipment_csrf'])) $_SESSION['equipment_csrf'] = bin2hex(random_bytes(24));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals((string)$_SESSION['equipment_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('La sesión expiró. Recarga la página e intenta nuevamente.');
    $selectedDate = trim((string)($_POST['fecha'] ?? ''));
    if (!equipmentValidDate($selectedDate)) throw new InvalidArgumentException('La fecha seleccionada no es válida.');
    $equipmentData = [];
    foreach ((array)$config['equipos'] as $key => $item) {
      $valuesForItem = [];
      foreach (['total', 'ok', 'no_ok'] as $column) {
        $raw = trim((string)($_POST['equipos'][$key][$column] ?? ''));
        if ($raw === '') { $valuesForItem[$column] = null; continue; }
        $parsed = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($parsed)) throw new InvalidArgumentException('Las cantidades de equipos deben ser números enteros iguales o mayores que cero.');
        $valuesForItem[$column] = $parsed;
      }
      $hasAny = count(array_filter($valuesForItem, static fn($value): bool => $value !== null)) > 0;
      if ($hasAny && ($valuesForItem['total'] === null || $valuesForItem['ok'] === null || $valuesForItem['no_ok'] === null)) throw new InvalidArgumentException('Completa Total, OK y No OK para ' . $item['label'] . '.');
      if ($hasAny && $valuesForItem['ok'] + $valuesForItem['no_ok'] !== $valuesForItem['total']) throw new InvalidArgumentException('En ' . $item['label'] . ', OK + No OK debe ser igual al total.');
      $equipmentData[$key] = $valuesForItem;
    }
    $costRaw = str_replace(',', '', trim((string)($_POST['costo_mantenimiento'] ?? '')));
    $cost = $costRaw === '' ? null : filter_var($costRaw, FILTER_VALIDATE_FLOAT);
    if ($cost !== null && ($cost === false || $cost < 0)) throw new InvalidArgumentException('El costo de mantenimiento debe ser igual o mayor que cero.');
    $capturedNow = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
    $captureTimestamp = $capturedNow->format('Y-m-d H:i:s');
    $data = [
      'fecha' => $selectedDate,
      'capturado_en' => $captureTimestamp,
      'fecha_captura' => $capturedNow->format('Y-m-d'),
      'hora_captura' => $capturedNow->format('H:i:s'),
      'zona_horaria' => 'America/Mexico_City',
      'actualizado' => $capturedNow->format('d/m/Y H:i:s'),
      'equipos' => $equipmentData,
      'costo_mantenimiento' => $cost,
    ];
    equipmentSaveData($selectedDate, $data);
    $_SESSION['equipment_csrf'] = bin2hex(random_bytes(24));
    header('Location: captura.php?' . http_build_query(['fecha' => $selectedDate, 'guardado' => 1]));
    exit;
  } catch (Throwable $exception) {
    $error = $exception->getMessage();
    if (isset($data) && is_array($data)) $values = equipmentMergeData($config, $data);
  }
}
$equipment = (array)($values['equipos'] ?? []);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Captura | Estado de Equipos</title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"><style>
:root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--blue:#2563eb;--navy:#1e3a5f;--soft:#f5f8fc;--green:#15803d}*{box-sizing:border-box}body{margin:0;color:var(--ink);background:#f1f5f9;font-family:Inter,system-ui,sans-serif}.page{width:min(980px,calc(100% - 28px));margin:20px auto 42px}.topbar{display:flex;justify-content:space-between;gap:10px;margin-bottom:11px}.link{display:inline-flex;align-items:center;gap:7px;padding:9px 13px;border:1px solid var(--line);border-radius:999px;color:#334155;background:#fff;font-size:12px;font-weight:800;text-decoration:none}.header{display:flex;align-items:center;gap:14px;padding:19px 21px;border:1px solid var(--line);border-radius:19px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}.header-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:15px;color:#fff;background:linear-gradient(145deg,var(--blue),var(--navy));font-size:22px}h1{margin:0;font-size:clamp(25px,4vw,37px);font-weight:900}.header p{margin:5px 0 0;color:var(--muted);font-size:13px;font-weight:700}.date-box{display:flex;align-items:end;gap:10px;margin-top:12px;padding:13px 15px;border:1px solid var(--line);border-radius:14px;background:#fff}.field{display:flex;flex-direction:column;gap:6px}.field label{color:#475569;font-size:11px;font-weight:900;text-transform:uppercase}input{width:100%;min-height:42px;padding:9px 10px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit;font-size:14px;font-weight:700;outline:none}input:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.12)}.load{min-height:42px;padding:9px 13px;border:0;border-radius:9px;color:#fff;background:var(--navy);font:inherit;font-size:12px;font-weight:900}.notice{margin-top:11px;padding:12px 14px;border-radius:11px;font-size:12px;font-weight:800}.notice.info{color:#1e3a5f;border:1px solid #bfdbfe;background:#eff6ff}.notice.success{color:#166534;border:1px solid #86efac;background:#dcfce7}.notice.error{color:#991b1b;border:1px solid #fca5a5;background:#fee2e2}.panel{margin-top:12px;overflow:hidden;border:1px solid var(--line);border-radius:17px;background:#fff}.panel h2{margin:0;padding:14px 17px;color:#fff;background:linear-gradient(145deg,var(--navy),#334e72);font-size:16px;font-weight:900;text-transform:uppercase}.capture-table{width:100%;border-collapse:collapse}.capture-table th,.capture-table td{padding:9px 12px;border-bottom:1px solid #e7edf5}.capture-table th{color:#475569;background:var(--soft);font-size:10px;font-weight:900;text-transform:uppercase;text-align:left}.capture-table th:not(:first-child),.capture-table td:not(:first-child){text-align:center}.capture-table td:first-child{font-size:12px;font-weight:900}.capture-table input{min-height:37px;max-width:110px;margin:auto;text-align:center}.capture-table input.carried{border-color:#bfdbfe;background:#eff6ff}.extras{display:grid;grid-template-columns:1fr 1fr;gap:11px;margin-top:12px}.extra{padding:15px;border:1px solid var(--line);border-radius:14px;background:#fff}.actions{position:sticky;bottom:10px;display:flex;justify-content:flex-end;gap:10px;margin-top:13px;padding:12px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.96);box-shadow:0 10px 28px rgba(15,23,42,.12)}.save{padding:11px 18px;border:0;border-radius:999px;color:#fff;background:linear-gradient(145deg,var(--blue),var(--navy));font:inherit;font-size:13px;font-weight:900}@media(max-width:620px){.capture-table th,.capture-table td{padding:7px 4px}.capture-table input{max-width:75px}.extras{grid-template-columns:1fr}.topbar{flex-direction:column}}
</style></head><body><main class="page"><div class="topbar"><a class="link" href="../index.php">Centro de reportes</a><a class="link" href="index.php?fecha=<?= $e($selectedDate) ?>" target="_blank">Ver reporte</a></div><header class="header"><div><h1>Captura del Estado de Equipos</h1><p>Registra el total y la condición actual por familia de equipos.</p></div></header>
<form class="date-box" method="get"><div class="field"><label>Fecha del corte</label><input type="date" name="fecha" value="<?= $e($selectedDate) ?>" required></div><button class="load">Cargar fecha</button></form><?php if($carriedTotals!==[]):?><div class="notice info">Se conservaron automáticamente los totales del último corte. Modifícalos únicamente si cambió el número de equipos.</div><?php endif;?><?php if($success):?><div class="notice success">Corte guardado correctamente.</div><?php endif;?><?php if($error!==''):?><div class="notice error"><?= $e($error) ?></div><?php endif;?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $e($_SESSION['equipment_csrf']) ?>"><input type="hidden" name="fecha" value="<?= $e($selectedDate) ?>"><section class="panel"><h2>Estado por equipo</h2><table class="capture-table"><thead><tr><th>Equipo</th><th># equipos</th><th>OK</th><th>No OK</th></tr></thead><tbody><?php foreach($equipment as $key=>$item):?><tr><td><?= $e($item['label']??'') ?></td><?php foreach(['total','ok','no_ok'] as $column):?><td><input class="<?= $column==='total' && isset($carriedTotals[$key]) ? 'carried' : '' ?>" type="number" min="0" step="1" inputmode="numeric" name="equipos[<?= $e($key) ?>][<?= $column ?>]" value="<?= $e($field($item,$column)) ?>"></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></section><section class="extras" style="grid-template-columns:1fr"><div class="extra field"><label>Costo de mantenimiento</label><input type="number" min="0" step="0.01" name="costo_mantenimiento" value="<?= $e(is_numeric($values['costo_mantenimiento']??null)?$values['costo_mantenimiento']:'') ?>" placeholder="$"></div></section><div class="actions"><a class="link" href="index.php?fecha=<?= $e($selectedDate) ?>" target="_blank">Vista previa</a><button class="save">Guardar corte</button></div></form></main></body></html>
