<?php

declare(strict_types=1);

session_start();
$config = require __DIR__ . '/config.php';
$databaseConfig = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/storage.php';
$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$defaultWeekDate = $now->modify('-7 days');
$selectedYear = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$selectedWeek = filter_input(INPUT_GET, 'semana', FILTER_VALIDATE_INT);
$selectedYear = is_int($selectedYear) ? $selectedYear : (int)$defaultWeekDate->format('o');
$selectedWeek = is_int($selectedWeek) ? $selectedWeek : (int)$defaultWeekDate->format('W');
if (!energyValidWeek($selectedYear, $selectedWeek)) {
  $selectedYear = (int)$defaultWeekDate->format('o');
  $selectedWeek = (int)$defaultWeekDate->format('W');
}
$productionDatabase = (array)($config['production_database'] ?? ($databaseConfig[(string)($config['database_key'] ?? 'prod')] ?? $databaseConfig['prod'] ?? []));
require_once __DIR__ . '/production.php';
$production = energyLoadProductionKg($selectedYear, $selectedWeek, $productionDatabase, $timezone);
$saved = energyLoadData($selectedYear, $selectedWeek);
$recordExists = $saved !== [];
$values = energyMergeData($config, $saved);
$registeredWeeks = array_slice(energyRegisteredWeeks(energyLoadRecords()), 0, 12);
$receiptId = filter_input(INPUT_GET, 'editar_recibo', FILTER_VALIDATE_INT);
$editingReceipt = is_int($receiptId) ? energyLoadReceipt($receiptId) : [];
$recentReceipts = array_slice(energyLoadReceipts(), 0, 15);
$rangeLabel = energyWeekRangeLabel($selectedYear, $selectedWeek);
$error = '';
$success = isset($_GET['guardado']) ? 'Registro operativo guardado correctamente.' : (isset($_GET['recibo_guardado']) ? 'Recibo guardado correctamente.' : '');
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$field = static fn(array $source, string $key): string => is_numeric($source[$key] ?? null) ? (string)$source[$key] : '';
$totalField = static fn(array $source): string => is_numeric($source['total'] ?? null) ? (string)$source['total'] : '';
if (!isset($_SESSION['energy_csrf']) || !is_string($_SESSION['energy_csrf'])) $_SESSION['energy_csrf'] = bin2hex(random_bytes(24));

$cleanNumber = static function ($value): ?float {
  if ($value === null || trim((string)$value) === '') return null;
  $normalized = str_replace(',', '', trim((string)$value));
  if (!is_numeric($normalized) || (float)$normalized < 0) throw new InvalidArgumentException('Los valores deben ser números iguales o mayores que cero.');
  return (float)$normalized;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!hash_equals((string)$_SESSION['energy_csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('La sesión expiró. Recarga la página e intenta nuevamente.');
    $action = (string)($_POST['action'] ?? 'save_weekly');
    if ($action === 'save_receipt') {
      $serviceKey = trim((string)($_POST['service_key'] ?? ''));
      if (!isset($config['consumos'][$serviceKey])) throw new InvalidArgumentException('El servicio seleccionado no es válido.');
      $company = preg_replace('/\s+/u', ' ', trim((string)($_POST['company'] ?? '')));
      $companyLength = function_exists('mb_strlen') ? mb_strlen((string)$company, 'UTF-8') : strlen((string)$company);
      if ($company === '' || $companyLength > 80) throw new InvalidArgumentException('Captura una empresa válida de hasta 80 caracteres.');
      $receiptDate = trim((string)($_POST['receipt_date'] ?? ''));
      $periodStart = trim((string)($_POST['period_start'] ?? ''));
      $periodEnd = trim((string)($_POST['period_end'] ?? ''));
      $validDate = static fn(string $date): bool => DateTimeImmutable::createFromFormat('!Y-m-d', $date) instanceof DateTimeImmutable
        && DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') === $date;
      if (!$validDate($receiptDate) || !$validDate($periodStart) || !$validDate($periodEnd) || $periodEnd < $periodStart) {
        throw new InvalidArgumentException('Revisa la fecha del recibo y el periodo capturado.');
      }
      $quantity = $cleanNumber($_POST['receipt_quantity'] ?? null);
      $postedReceiptId = filter_var($_POST['receipt_id'] ?? null, FILTER_VALIDATE_INT);
      $previousReceipt = is_int($postedReceiptId) ? energyLoadReceipt($postedReceiptId) : [];
      if (is_int($postedReceiptId) && $previousReceipt === []) throw new RuntimeException('El recibo que intentas editar ya no existe.');
      $amount = $cleanNumber($_POST['receipt_amount'] ?? null);
      if ($serviceKey === 'electricidad') {
        $amount = is_numeric($previousReceipt['amount'] ?? null) ? (float)$previousReceipt['amount'] : 0.0;
      }
      if ($quantity === null || $amount === null) throw new InvalidArgumentException('El consumo y el importe del recibo son obligatorios.');
      $receiptProduction = energyLoadProductionKgDates($periodStart, $periodEnd, $productionDatabase, $timezone);
      $capturedNow = new DateTimeImmutable('now', $timezone);
      $registeredAt = trim((string)($previousReceipt['registered_at'] ?? '')) ?: $capturedNow->format('Y-m-d H:i:s');
      $savedReceiptId = energySaveReceipt([
        'id' => is_int($postedReceiptId) ? $postedReceiptId : 0,
        'service_key' => $serviceKey,
        'company' => $company,
        'receipt_date' => $receiptDate,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
        'reference' => trim((string)($_POST['reference'] ?? '')),
        'quantity' => $quantity,
        'amount' => $amount,
        'production_kg' => $receiptProduction['kg'] ?? null,
        'production_start' => $receiptProduction['inicio'] ?? '',
        'production_end' => $receiptProduction['fin'] ?? '',
        'registered_at' => $registeredAt,
        'updated_at' => $previousReceipt !== [] ? $capturedNow->format('Y-m-d H:i:s') : null,
      ]);
      $_SESSION['energy_csrf'] = bin2hex(random_bytes(24));
      header('Location: captura.php?' . http_build_query(['recibo_guardado' => 1, 'editar_recibo' => $savedReceiptId]));
      exit;
    }

    $postYear = filter_var($_POST['anio'] ?? null, FILTER_VALIDATE_INT);
    $postWeek = filter_var($_POST['semana'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($postYear) || !is_int($postWeek) || !energyValidWeek($postYear, $postWeek)) throw new InvalidArgumentException('La semana seleccionada no es válida.');
    $selectedYear = $postYear;
    $selectedWeek = $postWeek;
    $production = energyLoadProductionKg($selectedYear, $selectedWeek, $productionDatabase, $timezone);
    $productionKg = is_numeric($production['kg'] ?? null) ? (float)$production['kg'] : null;
    $existingRecord = energyLoadData($selectedYear, $selectedWeek);
    $capturedNow = new DateTimeImmutable('now', $timezone);
    $registeredAt = trim((string)($existingRecord['registrado_en'] ?? ''));
    if ($registeredAt === '' && $existingRecord !== []) {
      $legacyRegisteredAt = DateTimeImmutable::createFromFormat(
        'd/m/Y H:i',
        trim((string)($existingRecord['actualizado'] ?? '')),
        $timezone
      );
      if ($legacyRegisteredAt instanceof DateTimeImmutable) $registeredAt = $legacyRegisteredAt->format('Y-m-d H:i:s');
    }
    if ($registeredAt === '') $registeredAt = $capturedNow->format('Y-m-d H:i:s');
    $registeredAtDate = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $registeredAt, $timezone);
    if (!$registeredAtDate instanceof DateTimeImmutable) {
      $registeredAtDate = $capturedNow;
      $registeredAt = $capturedNow->format('Y-m-d H:i:s');
    }
    $updatedAt = $existingRecord !== [] ? $capturedNow->format('Y-m-d H:i:s') : null;
    $data = energyMergeData($existingRecord, [
      'anio' => $selectedYear,
      'semana' => $selectedWeek,
      'registrado_en' => $registeredAt,
      'fecha_registro' => $registeredAtDate->format('Y-m-d'),
      'hora_registro' => $registeredAtDate->format('H:i:s'),
      'actualizado_en' => $updatedAt,
      'fecha_actualizacion' => $updatedAt !== null ? $capturedNow->format('Y-m-d') : null,
      'hora_actualizacion' => $updatedAt !== null ? $capturedNow->format('H:i:s') : null,
      'zona_horaria' => 'America/Mexico_City',
      'actualizado' => $capturedNow->format('d/m/Y H:i:s'),
      'produccion' => [
        'kg' => $productionKg,
        'inicio' => (string)($production['inicio'] ?? ''),
        'fin' => (string)($production['fin'] ?? ''),
        'corte_consultado_en' => $capturedNow->format('Y-m-d H:i:s'),
      ],
      'recuperaciones' => [
        'recuperacion_grasa' => ['m3' => $cleanNumber($_POST['recuperacion_grasa_m3'] ?? null), 'valor' => $cleanNumber($_POST['recuperacion_grasa_valor'] ?? null)],
        'ollas' => ['m3' => $cleanNumber($_POST['ollas_m3'] ?? null), 'valor' => $cleanNumber($_POST['ollas_valor'] ?? null)],
        'polimeros' => ['m3' => $cleanNumber($_POST['polimeros_m3'] ?? null), 'valor' => $cleanNumber($_POST['polimeros_valor'] ?? null)],
      ],
      'generacion' => [
        'panel_solar' => ['kw' => $cleanNumber($_POST['panel_solar_kw'] ?? null), 'valor' => $cleanNumber($_POST['panel_solar_valor'] ?? null)],
        'cogenerador' => ['kw' => $cleanNumber($_POST['cogenerador_kw'] ?? null), 'valor' => $cleanNumber($_POST['cogenerador_valor'] ?? null)],
      ],
    ]);
    energySaveData($selectedYear, $selectedWeek, $data);
    $_SESSION['energy_csrf'] = bin2hex(random_bytes(24));
    header('Location: captura.php?' . http_build_query(['anio' => $selectedYear, 'semana' => $selectedWeek, 'guardado' => 1]));
    exit;
  } catch (Throwable $exception) {
    $error = $exception->getMessage();
    if (isset($data) && is_array($data)) $values = energyMergeData($config, $data);
  }
}
$recoveries = (array)($values['recuperaciones'] ?? []);
$generation = (array)($values['generacion'] ?? []);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Captura | Reporte de Energía</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root { --ink:#0f172a; --muted:#64748b; --line:#dbe3ee; --amber:#d97706; --amber-dark:#b45309; --soft:#f8fafc; --green:#15803d; }
    * { box-sizing:border-box; }
    body { margin:0; color:var(--ink); background:#f2f5f8; font-family:Inter,system-ui,sans-serif; }
    .page { width:min(980px,calc(100% - 28px)); margin:20px auto 44px; }
    .topbar { display:flex; justify-content:space-between; gap:12px; margin-bottom:12px; }
    .link { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:10px 14px; border:1px solid var(--line); border-radius:999px; color:#334155; background:#fff; font-size:13px; font-weight:800; text-decoration:none; }
    .header { display:flex; align-items:center; gap:15px; padding:20px 22px; border:1px solid var(--line); border-radius:19px; background:#fff; box-shadow:0 12px 30px rgba(15,23,42,.07); }
    .header-icon { display:grid; place-items:center; width:52px; height:52px; border-radius:15px; color:#fff; background:linear-gradient(145deg,var(--amber),var(--amber-dark)); font-size:23px; }
    h1 { margin:0; font-size:clamp(25px,4vw,38px); font-weight:900; }
    .header p { margin:5px 0 0; color:var(--muted); font-size:13px; font-weight:700; }
    .week-selector { display:flex; align-items:end; gap:10px; margin-top:12px; padding:14px 16px; border:1px solid var(--line); border-radius:15px; background:#fff; }
    .week-selector .field { width:145px; }
    .week-selector .registered { width:190px; }
    .week-context { margin-left:auto; color:var(--muted); font-size:12px; font-weight:800; text-align:right; }
    .week-context strong { color:var(--amber-dark); }
    .load { min-height:43px; padding:10px 14px; border:0; border-radius:10px; color:#fff; background:#334155; font:inherit; font-size:13px; font-weight:900; cursor:pointer; }
    .notice { margin-top:12px; padding:13px 15px; border-radius:12px; font-size:13px; font-weight:800; }
    .notice.success { color:#166534; border:1px solid #86efac; background:#dcfce7; }
    .notice.error { color:#991b1b; border:1px solid #fca5a5; background:#fee2e2; }
    .production-cut { display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:14px; margin-top:12px; padding:15px 17px; border:1px solid #bfdbfe; border-radius:15px; background:#eff6ff; }
    .production-cut i { color:#1d4ed8; font-size:24px; }
    .production-cut span,.production-cut small { display:block; }
    .production-cut span { color:#475569; font-size:11px; font-weight:900; text-transform:uppercase; }
    .production-cut strong { color:#1e3a8a; font-size:27px; font-weight:900; white-space:nowrap; }
    .production-cut small { margin-top:3px; color:#64748b; font-size:10px; font-weight:700; }
    .production-cut.error { border-color:#fecaca; background:#fef2f2; }.production-cut.error i,.production-cut.error strong { color:#b91c1c; }
    .panel { margin-top:12px; padding:18px; border:1px solid var(--line); border-radius:17px; background:#fff; }
    .section-label { margin:18px 2px 0; color:#334155; font-size:15px; font-weight:900; text-transform:uppercase; }
    .panel h2 { display:flex; align-items:center; gap:9px; margin:0 0 14px; color:#334155; font-size:17px; font-weight:900; text-transform:uppercase; }
    .panel h2 i { color:var(--amber); }
    .grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
    .grid.two { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .entry-card { padding:15px; border:1px solid var(--line); border-radius:14px; background:var(--soft); }
    .entry-card h3 { display:flex; align-items:center; gap:8px; margin:0 0 12px; font-size:13px; font-weight:900; text-transform:uppercase; }
    .entry-card h3 i { color:var(--amber); }
    .generation-entry h3 i { color:var(--green); }
    .recovery-recuperacion_grasa h3 i { color:#475569; }
    .recovery-ollas h3 i { color:#78716c; }
    .recovery-polimeros h3 i { color:#71717a; }
    .fields { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    label { color:#475569; font-size:12px; font-weight:800; }
    input,select { width:100%; min-height:43px; padding:10px 11px; border:1px solid #cbd5e1; border-radius:10px; color:var(--ink); background:#fff; font:inherit; font-size:14px; outline:none; }
    input:focus,select:focus { border-color:var(--amber); box-shadow:0 0 0 3px rgba(217,119,6,.12); }
    .suffix { position:relative; }
    .suffix input { padding-right:54px; }
    .suffix span { position:absolute; right:11px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:11px; font-weight:900; pointer-events:none; }
    .calculated { margin-top:8px; color:#1e3a8a; font-size:11px; font-weight:900; }.calculated strong{font-size:14px}
    .receipt-fields { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; align-items:end; }
    .receipt-actions { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:13px; }
    .receipt-result { color:#1e3a8a; font-size:11px; font-weight:800; }
    .receipt-result strong { font-size:15px; }
    .receipt-list-table { width:100%; margin-top:14px; border-collapse:collapse; font-size:11px; }
    .receipt-list-table th,.receipt-list-table td { padding:9px 8px; border-top:1px solid #e2e8f0; text-align:left; }
    .receipt-list-table th { color:#64748b; font-size:9px; text-transform:uppercase; }
    .receipt-list-table td:nth-last-child(-n+3),.receipt-list-table th:nth-last-child(-n+3) { text-align:right; }
    .edit-link { color:#1d4ed8; font-weight:900; text-decoration:none; }
    .actions { position:sticky; bottom:10px; display:flex; justify-content:flex-end; gap:10px; margin-top:14px; padding:13px; border:1px solid var(--line); border-radius:15px; background:rgba(255,255,255,.96); box-shadow:0 10px 28px rgba(15,23,42,.12); }
    .save { padding:12px 19px; border:0; border-radius:999px; color:#fff; background:linear-gradient(145deg,var(--amber),var(--amber-dark)); font:inherit; font-size:14px; font-weight:900; cursor:pointer; }
    @media(max-width:900px) { .receipt-fields { grid-template-columns:repeat(2,minmax(0,1fr)); } .receipt-list-table { display:block; overflow-x:auto; white-space:nowrap; } }
    @media(max-width:760px) { .grid,.grid.two { grid-template-columns:1fr; } .week-selector { align-items:stretch; flex-direction:column; } .week-selector .field,.week-selector .registered { width:100%; } .week-context { margin-left:0; text-align:left; } .production-cut{grid-template-columns:auto 1fr}.production-cut strong{grid-column:2} }
    @media(max-width:480px) { .topbar { flex-direction:column; } .header { padding:16px; } .fields { grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <main class="page">
    <div class="topbar"><a class="link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Centro de reportes</a><a class="link" href="index.php?anio=<?= $e($selectedYear) ?>&amp;semana=<?= $e($selectedWeek) ?>" target="_blank"><i class="fa-solid fa-chart-column"></i> Ver reporte</a></div>
    <header class="header"><span class="header-icon"><i class="fa-solid fa-bolt"></i></span><div><h1>Captura del Reporte de Energía</h1><p>Recibos de consumo por periodo y registro operativo semanal.</p></div></header>
    <?php if ($success !== ''): ?><div class="notice success"><?= $e($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?= $e($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['energy_csrf']) ?>"><input type="hidden" name="action" value="save_receipt"><input type="hidden" name="receipt_id" value="<?= $e($editingReceipt['id'] ?? '') ?>">
      <section class="panel"><h2><i class="fa-regular fa-file-lines"></i> <?= $editingReceipt !== [] ? 'Editar recibo de consumo' : 'Nuevo recibo de consumo' ?></h2>
        <div class="receipt-fields">
          <div class="field"><label>Servicio</label><select name="service_key" id="receiptService" required><?php foreach ((array)$config['consumos'] as $key => $metric): ?><option value="<?= $e($key) ?>" <?= ($editingReceipt['service_key'] ?? 'gas_natural') === $key ? 'selected' : '' ?>><?= $e($metric['label'] ?? $key) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Empresa</label><input type="text" name="company" maxlength="80" required placeholder="Nombre de la empresa" value="<?= $e($editingReceipt['company'] ?? '') ?>"></div>
          <div class="field"><label>Fecha del recibo</label><input type="date" name="receipt_date" required value="<?= $e($editingReceipt['receipt_date'] ?? $now->format('Y-m-d')) ?>"></div>
          <div class="field"><label>Inicio del periodo</label><input type="date" name="period_start" required value="<?= $e($editingReceipt['period_start'] ?? $now->modify('first day of last month')->format('Y-m-d')) ?>"></div>
          <div class="field"><label>Fin del periodo</label><input type="date" name="period_end" required value="<?= $e($editingReceipt['period_end'] ?? $now->modify('last day of last month')->format('Y-m-d')) ?>"></div>
          <div class="field"><label>Consumo</label><input type="number" min="0" step="0.001" name="receipt_quantity" required value="<?= $e($editingReceipt['quantity'] ?? '') ?>"></div>
          <div class="field"><label id="receiptAmountLabel">Importe</label><div class="suffix"><input type="number" min="0" step="0.01" name="receipt_amount" id="receiptAmount" required value="<?= $e($editingReceipt['amount'] ?? '') ?>"><span>$</span></div></div>
        </div>
        <div class="receipt-actions"><div class="field" style="max-width:270px;flex:1"><label>Referencia del recibo (opcional)</label><input type="text" maxlength="80" name="reference" value="<?= $e($editingReceipt['reference'] ?? '') ?>"></div><div class="receipt-result">Producción del periodo: <strong><?= is_numeric($editingReceipt['production_kg'] ?? null) ? number_format((float)$editingReceipt['production_kg'], 0, '.', ',') . ' kg' : 'se calculará al guardar' ?></strong></div><div><?php if ($editingReceipt !== []): ?><a class="link" href="captura.php">Cancelar edición</a><?php endif; ?> <button class="save" type="submit"><?= $editingReceipt !== [] ? 'Actualizar recibo' : 'Guardar recibo' ?></button></div></div>
        <?php if ($recentReceipts !== []): ?><table class="receipt-list-table"><thead><tr><th>Servicio</th><th>Empresa</th><th>Fecha</th><th>Periodo</th><th>Consumo</th><th>Importe</th><th></th></tr></thead><tbody><?php foreach ($recentReceipts as $receipt): $receiptMetric = (array)($config['consumos'][$receipt['service_key']] ?? []); ?><tr><td><?= $e($receiptMetric['label'] ?? $receipt['service_key']) ?></td><td><?= $e($receipt['company'] ?? 'Progel') ?></td><td><?= $e($receipt['receipt_date']) ?></td><td><?= $e($receipt['period_start']) ?> al <?= $e($receipt['period_end']) ?></td><td><?= number_format((float)$receipt['quantity'], 2, '.', ',') ?> <?= $e($receiptMetric['total_unit'] ?? '') ?></td><td><?= ($receipt['service_key'] ?? '') === 'electricidad' && (float)($receipt['amount'] ?? 0) <= 0 ? 'Automático en reporte' : '$' . number_format((float)$receipt['amount'], 2, '.', ',') ?></td><td><a class="edit-link" href="?editar_recibo=<?= $e($receipt['id']) ?>">Editar</a></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
      </section>
    </form>

    <div class="section-label">Registro operativo semanal</div>
    <form class="week-selector" method="get"><div class="field"><label>Semana del año</label><input type="number" name="semana" min="1" max="53" required value="<?= $e($selectedWeek) ?>"></div><div class="field"><label>Año</label><input type="number" name="anio" min="2020" max="2100" required value="<?= $e($selectedYear) ?>"></div><button class="load" type="submit">Cargar semana</button><?php if ($registeredWeeks !== []): ?><div class="field registered"><label>Semanas registradas</label><select onchange="if(this.value) window.location.href=this.value"><option value="">Seleccionar</option><?php foreach ($registeredWeeks as $week): ?><option value="?anio=<?= $e($week['anio']) ?>&amp;semana=<?= $e($week['semana']) ?>" <?= (int)$week['anio'] === $selectedYear && (int)$week['semana'] === $selectedWeek ? 'selected' : '' ?>>Semana <?= $e($week['semana']) ?> · <?= $e($week['anio']) ?></option><?php endforeach; ?></select></div><?php endif; ?><div class="week-context"><?= $recordExists ? 'Editando registro existente' : 'Nuevo registro' ?>: <strong>semana <?= $e($selectedWeek) ?> de <?= $e($selectedYear) ?></strong><br><?= $e($rangeLabel) ?></div></form>
    <section class="production-cut <?= !is_numeric($production['kg'] ?? null) ? 'error' : '' ?>"><i class="fa-solid fa-industry"></i><div><span>Producción del corte semanal</span><small>Lunes 07:00 a lunes 07:00 · sólo tarimas con etiquetado válido</small></div><strong><?= is_numeric($production['kg'] ?? null) ? number_format((float)$production['kg'], 0, '.', ',') . ' kg' : 'No disponible' ?></strong></section>

    <form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= $e($_SESSION['energy_csrf']) ?>"><input type="hidden" name="action" value="save_weekly"><input type="hidden" name="anio" value="<?= $e($selectedYear) ?>"><input type="hidden" name="semana" value="<?= $e($selectedWeek) ?>">
      <section class="panel"><h2><i class="fa-solid fa-recycle"></i> Recuperación</h2><div class="grid">
        <?php foreach (['recuperacion_grasa' => ['Recuperación de grasa','fa-oil-can'], 'ollas' => ['Ollas','fa-fire-burner'], 'polimeros' => ['Polímeros','fa-flask']] as $key => $meta): $metric = (array)($recoveries[$key] ?? []); ?>
          <div class="entry-card recovery-<?= $e($key) ?>"><h3><i class="fa-solid <?= $e($meta[1]) ?>"></i> <?= $e($meta[0]) ?></h3><div class="fields"><div class="field"><label>Recuperación</label><div class="suffix"><input type="number" min="0" step="0.001" name="<?= $e($key) ?>_m3" value="<?= $e($field($metric, 'm3')) ?>"><span>m³</span></div></div><div class="field"><label>Valor económico</label><div class="suffix"><input type="number" min="0" step="0.01" name="<?= $e($key) ?>_valor" value="<?= $e($field($metric, 'valor')) ?>"><span>$</span></div></div></div></div>
        <?php endforeach; ?>
      </div></section>
      <section class="panel"><h2><i class="fa-solid fa-leaf"></i> Generación</h2><div class="grid two">
        <?php foreach (['panel_solar' => ['Panel solar','fa-solar-panel'], 'cogenerador' => ['Cogenerador','fa-industry']] as $key => $meta): $metric = (array)($generation[$key] ?? []); ?>
          <div class="entry-card generation-entry"><h3><i class="fa-solid <?= $e($meta[1]) ?>"></i> <?= $e($meta[0]) ?></h3><div class="fields"><div class="field"><label>Producción</label><div class="suffix"><input type="number" min="0" step="0.01" name="<?= $e($key) ?>_kw" value="<?= $e($field($metric, 'kw')) ?>"><span>kW</span></div></div><div class="field"><label>Valor económico</label><div class="suffix"><input type="number" min="0" step="0.01" name="<?= $e($key) ?>_valor" value="<?= $e($field($metric, 'valor')) ?>"><span>$</span></div></div></div></div>
        <?php endforeach; ?>
      </div></section>
      <div class="actions"><a class="link" href="index.php?anio=<?= $e($selectedYear) ?>&amp;semana=<?= $e($selectedWeek) ?>" target="_blank">Vista previa</a><button class="save" type="submit"><?= $recordExists ? 'Actualizar' : 'Guardar' ?> semana <?= $e($selectedWeek) ?></button></div>
    </form>
  </main>
  <script>
    (() => {
      const productionKg = <?= is_numeric($production['kg'] ?? null) ? json_encode((float)$production['kg']) : 'null' ?>;
      const updateRatio = (input) => {
        const output = document.getElementById(input.dataset.ratioOutput || '');
        if (!output) return;
        if (String(input.value || '').trim() === '') {
          output.textContent = '—';
          return;
        }
        const total = Number(input.value);
        output.textContent = Number.isFinite(total) && Number.isFinite(productionKg) && productionKg > 0
          ? (total / productionKg).toLocaleString('es-MX', { maximumFractionDigits: 2 })
          : '—';
      };
      document.querySelectorAll('[data-ratio-output]').forEach((input) => {
        input.addEventListener('input', () => updateRatio(input));
        updateRatio(input);
      });
      const receiptService = document.getElementById('receiptService');
      const receiptAmount = document.getElementById('receiptAmount');
      const receiptAmountLabel = document.getElementById('receiptAmountLabel');
      const syncReceiptAmount = () => {
        if (!receiptService || !receiptAmount || !receiptAmountLabel) return;
        const automatic = receiptService.value === 'electricidad';
        receiptAmount.readOnly = automatic;
        receiptAmount.required = !automatic;
        receiptAmount.placeholder = automatic ? 'Automático desde API' : '';
        receiptAmountLabel.textContent = automatic ? 'Importe automático' : 'Importe';
      };
      receiptService?.addEventListener('change', syncReceiptAmount);
      syncReceiptAmount();
    })();
  </script>
</body>
</html>
