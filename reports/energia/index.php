<?php

declare(strict_types=1);

ini_set('session.gc_maxlifetime', '2400');
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/build_report.php';
$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$records = energyLoadRecords();
$catalog = energyMetricCatalog($config);
$requestedYear = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$selectedYear = is_int($requestedYear) && $requestedYear >= 2020 && $requestedYear <= 2100 ? $requestedYear : (int)$now->format('Y');
$requestedMetric = trim((string)($_GET['metrica'] ?? 'gas_natural'));
$initialMetricKey = isset($catalog[$requestedMetric]) ? $requestedMetric : 'gas_natural';
if (!isset($catalog[$initialMetricKey])) $initialMetricKey = (string)array_key_first($catalog);
$captureMode = isset($_GET['capture']);
$waterAccessError = '';
$waterAccessTimeout = max(60, (int)($config['acceso_agua']['timeout_seconds'] ?? 2400));
$waterLastActivity = is_numeric($_SESSION['energy_water_last_activity'] ?? null)
  ? (int)$_SESSION['energy_water_last_activity']
  : null;
$waterUnlocked = ($_SESSION['energy_water_unlocked'] ?? false) === true
  && $waterLastActivity !== null
  && (time() - $waterLastActivity) < $waterAccessTimeout;
if (!$waterUnlocked) {
  unset($_SESSION['energy_water_unlocked'], $_SESSION['energy_water_last_activity']);
} else {
  $_SESSION['energy_water_last_activity'] = time();
}
if (!isset($_SESSION['energy_water_csrf']) || !is_string($_SESSION['energy_water_csrf'])) {
  $_SESSION['energy_water_csrf'] = bin2hex(random_bytes(24));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_water') {
  $submittedToken = (string)($_POST['csrf'] ?? '');
  $password = (string)($_POST['clave'] ?? '');
  $hashEnvironment = trim((string)($config['acceso_agua']['password_hash_env'] ?? 'ENERGIA_AGUA_CLAVE_HASH'));
  $configuredHash = $hashEnvironment !== '' ? trim((string)getenv($hashEnvironment)) : '';
  if ($submittedToken === '' || !hash_equals((string)$_SESSION['energy_water_csrf'], $submittedToken)) {
    $waterAccessError = 'La sesión expiró. Recarga la página e intenta nuevamente.';
  } elseif ($configuredHash === '') {
    $waterAccessError = 'La clave de acceso a Agua no está configurada en el servidor.';
  } elseif ($password === '' || !password_verify($password, $configuredHash)) {
    $waterAccessError = 'La clave no es correcta.';
  } else {
    session_regenerate_id(true);
    $_SESSION['energy_water_unlocked'] = true;
    $_SESSION['energy_water_last_activity'] = time();
    $_SESSION['energy_water_csrf'] = bin2hex(random_bytes(24));
    header('Location: ?' . http_build_query(['anio' => $selectedYear, 'metrica' => 'agua']));
    exit;
  }
}
$waterUnlocked = ($_SESSION['energy_water_unlocked'] ?? false) === true;

$availableYears = [(int)$now->format('Y') => true];
foreach ($records as $key => $record) {
  if (preg_match('/^(\d{4})-W(\d{2})$/', (string)$key, $matches) !== 1) continue;
  $year = (int)$matches[1];
  $week = (int)$matches[2];
  if (!energyValidWeek($year, $week)) continue;
  $calendarYear = (int)(new DateTimeImmutable('now', $timezone))->setISODate($year, $week, 1)->format('Y');
  $availableYears[$calendarYear] = true;
}
$availableYears[$selectedYear] = true;
$availableYears = array_keys($availableYears);
rsort($availableYears);

$monthNames = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$annualMetrics = [];
$chartPayload = [];
foreach ($catalog as $key => $metric) {
  $metricRecords = $key === 'agua' && !$waterUnlocked ? [] : $records;
  $annual = energyBuildAnnualMetric($metricRecords, $metric, $selectedYear, $timezone);
  $previousAnnual = energyBuildAnnualMetric($metricRecords, $metric, $selectedYear - 1, $timezone);
  $previousMoney = $previousAnnual['money'];
  $moneyVariation = is_numeric($annual['money']) && is_numeric($previousMoney) && (float)$previousMoney != 0.0
    ? (((float)$annual['money'] - (float)$previousMoney) / abs((float)$previousMoney)) * 100
    : null;
  $hasRecords = false;
  $monthsWithRecords = 0;
  $chartValues = [];
  foreach ($annual['months'] as $month) {
    $chartValues[] = $month['has_quantity'] ? round((float)$month['quantity'], 3) : null;
    if ($month['records'] !== []) {
      $hasRecords = true;
      $monthsWithRecords++;
    }
  }
  $annualMetrics[$key] = [
    'metric' => $metric,
    'annual' => $annual,
    'previous_money' => $previousMoney,
    'money_variation' => $moneyVariation,
    'has_records' => $hasRecords,
    'months_with_records' => $monthsWithRecords,
    'locked' => $key === 'agua' && !$waterUnlocked,
  ];
  $chartPayload[$key] = [
    'labels' => array_values($monthNames),
    'values' => $chartValues,
    'color' => (string)($metric['color'] ?? '#475569'),
    'unit' => (string)($metric['unit'] ?? ''),
  ];
}

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$decimal = static function ($value, int $decimals = 2): string {
  if (!is_numeric($value)) return '—';
  return rtrim(rtrim(number_format((float)$value, $decimals, '.', ','), '0'), '.');
};
$money = static fn($value): string => is_numeric($value) ? '$' . number_format((float)$value, 0, '.', ',') : '—';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($config['titulo'] ?? 'Reporte de Energía') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--surface:#fff}*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:62px}body{margin:0;color:var(--ink);background:#f2f5f8;font-family:Inter,system-ui,sans-serif}.report{width:min(1180px,calc(100% - 28px));margin:18px auto 38px}.top-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.link{display:inline-flex;align-items:center;gap:8px;padding:9px 13px;border:1px solid var(--line);border-radius:999px;color:#334155;background:#fff;font-size:12px;font-weight:800;text-decoration:none}.capture-link{font-size:11px;opacity:.82}.header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 21px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}.title-wrap{display:flex;align-items:center;gap:14px}.title-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:15px;color:#fff;background:#475569;font-size:23px}h1{margin:0;font-size:clamp(27px,4vw,38px);font-weight:900}.subtitle{margin:5px 0 0;color:var(--muted);font-size:12px;font-weight:700}.year-form{display:flex;align-items:end;gap:7px}.year-form label{display:flex;flex-direction:column;gap:4px;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.year-form select{height:34px;padding:5px 28px 5px 9px;border:1px solid var(--line);border-radius:9px;color:#334155;background:#fff;font:inherit;font-size:12px;font-weight:900}.year-form button{height:34px;padding:0 11px;border:0;border-radius:9px;color:#fff;background:#475569;font:inherit;font-size:10px;font-weight:900}.metric-nav{position:sticky;top:6px;z-index:10;display:flex;gap:7px;margin-top:11px;padding:8px;overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.96);box-shadow:0 5px 14px rgba(15,23,42,.06);scrollbar-width:thin}.metric-nav a{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid transparent;border-radius:10px;color:#64748b;background:#f8fafc;font-size:10px;font-weight:900;text-decoration:none;white-space:nowrap;text-transform:uppercase}.metric-nav a.active{color:#fff;background:var(--metric-color);box-shadow:0 5px 12px color-mix(in srgb,var(--metric-color) 25%,transparent)}.scroll-hint{display:flex;align-items:center;justify-content:flex-end;gap:7px;margin:7px 5px 0;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.metric-scroll{display:flex;flex-direction:column;gap:14px;margin-top:7px;padding:2px 2px 12px;overflow:visible}.metric-panel{--accent:#475569;width:100%;min-width:0;padding:17px;border:1px solid var(--line);border-radius:19px;background:#fff;scroll-margin-top:62px}.metric-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.metric-title{display:flex;align-items:center;gap:12px}.metric-symbol{display:grid;place-items:center;width:45px;height:45px;border-radius:13px;color:#fff;background:var(--accent);font-size:20px}.metric-title h2{margin:0;font-size:23px;font-weight:900}.metric-title p{margin:3px 0 0;color:var(--muted);font-size:10px;font-weight:800}.annual-total{text-align:right}.annual-total span{display:block;color:var(--muted);font-size:9px;font-weight:900;text-transform:uppercase}.annual-total strong{display:block;margin-top:3px;color:var(--accent);font-size:25px;font-weight:900;white-space:nowrap}.content-grid{display:grid;grid-template-columns:330px minmax(0,1fr);gap:13px}.receipts,.chart-card{min-width:0;border:1px solid var(--line);border-radius:15px;background:#f8fafc}.receipts{height:350px;padding:12px;overflow:auto}.receipts-title{display:flex;align-items:center;justify-content:space-between;margin:0 0 9px;color:#334155;font-size:11px;font-weight:900;text-transform:uppercase}.month-group{margin-bottom:7px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;overflow:hidden}.month-group summary{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px;cursor:pointer;list-style:none}.month-group summary::-webkit-details-marker{display:none}.month-name{font-size:11px;font-weight:900}.month-total{color:var(--accent);font-size:10px;font-weight:900;white-space:nowrap}.receipt-list{border-top:1px solid #edf2f7}.receipt-row{display:grid;grid-template-columns:1fr auto;gap:3px 8px;padding:8px 10px}.receipt-row+.receipt-row{border-top:1px solid #edf2f7}.receipt-row span{color:#475569;font-size:9px;font-weight:800}.receipt-row strong{font-size:10px;font-weight:900;text-align:right}.receipt-row small{color:#94a3b8;font-size:8px;font-weight:800}.receipt-money{color:#475569!important;text-align:right}.empty{display:grid;place-items:center;height:280px;padding:20px;color:#94a3b8;font-size:11px;font-weight:800;text-align:center}.chart-card{display:flex;height:350px;flex-direction:column;padding:13px 15px}.chart-card h3{margin:0;color:#334155;font-size:11px;font-weight:900;text-transform:uppercase}.chart-wrap{position:relative;min-height:0;flex:1;margin-top:8px}.summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:13px}.summary-card{display:grid;grid-template-columns:68px minmax(0,1fr);min-height:105px;overflow:hidden;border:1px solid var(--line);border-radius:15px;background:#fff}.summary-icon{display:grid;place-items:center;color:#fff;background:var(--accent);font-size:22px}.summary-content{display:flex;min-width:0;flex-direction:column;justify-content:center;padding:13px 16px}.summary-content span{color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase}.summary-content strong{margin-top:6px;color:#172b45;font-size:clamp(25px,4vw,36px);line-height:1;font-weight:900;white-space:nowrap}.summary-content small{margin-top:5px;color:var(--muted);font-size:9px;font-weight:700}.capture-note{margin-top:10px;color:#94a3b8;font-size:9px;font-weight:700;text-align:right}@media(max-width:820px){.content-grid{grid-template-columns:1fr}.receipts{height:auto;max-height:320px}.chart-card{height:310px}}@media(max-width:560px){.report{width:calc(100% - 16px);margin-top:8px}.header{align-items:flex-start;padding:15px}.year-form{flex-direction:column;align-items:stretch}.year-form button{display:none}.title-icon{width:44px;height:44px}.metric-panel{padding:12px}.metric-head{align-items:flex-start}.annual-total strong{font-size:19px}.summary-grid{grid-template-columns:1fr}.content-grid{gap:9px}}
    .report{width:min(1480px,calc(100% - 28px))}.content-grid{grid-template-columns:250px minmax(430px,1fr) 195px 215px;align-items:stretch}.receipts,.chart-card{height:310px}.summary-grid{display:contents}.summary-card{grid-template-columns:1fr;grid-template-rows:58px minmax(0,1fr);height:310px;min-height:0}.summary-icon{font-size:20px}.summary-content{justify-content:flex-start;padding:18px 14px;text-align:center}.summary-content strong{font-size:clamp(20px,2vw,28px);white-space:normal;overflow-wrap:anywhere}.summary-content small{margin-top:9px;line-height:1.35}.comparison{margin-top:13px;padding-top:12px;border-top:1px solid var(--line)}.comparison span{display:block;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.comparison b{display:block;margin-top:4px;color:#334155;font-size:14px;font-weight:900}.comparison em{display:inline-flex;margin-top:5px;padding:4px 7px;border-radius:999px;color:#475569;background:#f1f5f9;font-size:10px;font-style:normal;font-weight:900}.comparison em.up{color:#9a3412;background:#ffedd5}.comparison em.down{color:#166534;background:#dcfce7}.water-locked{grid-column:1/-1;display:flex;height:310px;flex-direction:column;align-items:center;justify-content:center;border:1px dashed #93c5fd;border-radius:15px;background:#eff6ff;text-align:center}.water-locked i{color:#2563eb;font-size:31px}.water-locked h3{margin:13px 0 5px;font-size:18px;font-weight:900}.water-locked p{margin:0;color:#64748b;font-size:11px;font-weight:700}.view-water{margin-top:16px;padding:10px 22px;border:0;border-radius:999px;color:#fff;background:#2563eb;font:inherit;font-size:12px;font-weight:900;cursor:pointer}.water-dialog{width:min(410px,calc(100% - 28px));padding:0;border:0;border-radius:18px;color:var(--ink);box-shadow:0 24px 70px rgba(15,23,42,.28)}.water-dialog::backdrop{background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}.water-dialog form{padding:22px}.dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.dialog-head div{display:flex;gap:11px}.dialog-head i{display:grid;place-items:center;width:39px;height:39px;border-radius:11px;color:#fff;background:#2563eb}.dialog-head h2{margin:0;font-size:20px;font-weight:900}.dialog-head p{margin:4px 0 0;color:#64748b;font-size:11px;font-weight:700}.dialog-close{padding:4px;border:0;color:#64748b;background:transparent;font-size:20px;cursor:pointer}.dialog-field{display:flex;flex-direction:column;gap:6px;margin-top:18px}.dialog-field label{color:#475569;font-size:11px;font-weight:900;text-transform:uppercase}.dialog-field input{width:100%;height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;font-size:16px;outline:none}.dialog-field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.dialog-error{margin-top:10px;padding:9px 11px;border-radius:9px;color:#991b1b;background:#fee2e2;font-size:11px;font-weight:800}.dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.dialog-actions button{padding:9px 14px;border-radius:999px;font:inherit;font-size:11px;font-weight:900;cursor:pointer}.dialog-cancel{border:1px solid var(--line);color:#475569;background:#fff}.dialog-submit{border:0;color:#fff;background:#2563eb}@media(max-width:1120px){.content-grid{grid-template-columns:230px minmax(360px,1fr) 175px 190px;gap:9px}.summary-content{padding:15px 10px}.summary-content strong{font-size:20px}}@media(max-width:900px){.content-grid{grid-template-columns:1fr 1fr}.receipts,.chart-card{height:310px}.summary-card{height:140px;grid-template-columns:58px 1fr;grid-template-rows:1fr}.summary-content{justify-content:center;text-align:left}.summary-content strong{white-space:nowrap}.comparison{position:absolute;margin-left:220px;margin-top:0;padding:0;border:0}}@media(max-width:560px){.content-grid{grid-template-columns:1fr}.summary-card{height:140px}.comparison{position:static;margin:10px 0 0;padding-top:9px;border-top:1px solid var(--line)}}
    <?php if ($captureMode): ?>body{background:#fff}.report{width:1460px;margin:7px auto}.top-actions,.year-form,.metric-nav,.scroll-hint,.capture-note{display:none}.header{padding:12px 16px;box-shadow:none}.metric-scroll{margin-top:8px;padding-bottom:0}.metric-panel{padding:13px}.content-grid{grid-template-columns:250px minmax(430px,1fr) 195px 215px}.receipts,.chart-card,.summary-card{height:290px}<?php endif; ?>
  </style>
</head>
<body>
<main class="report">
  <div class="top-actions"><a class="link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Regresar al inicio</a><a class="link capture-link" href="captura.php"><i class="fa-regular fa-pen-to-square"></i> Capturar registro</a></div>
  <header class="header"><div class="title-wrap"><span class="title-icon"><i class="fa-solid fa-bolt"></i></span><div><h1><?= $e($config['titulo'] ?? 'Reporte de Energía') ?></h1><p class="subtitle"><?= $e($config['subtitulo'] ?? '') ?></p></div></div><form class="year-form" method="get"><input type="hidden" name="metrica" id="selectedMetricInput" value="<?= $e($initialMetricKey) ?>"><label>Año<select name="anio" onchange="this.form.submit()"><?php foreach ($availableYears as $year): ?><option value="<?= $e($year) ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= $e($year) ?></option><?php endforeach; ?></select></label><button>Consultar</button></form></header>
  <nav class="metric-nav" aria-label="Indicadores de energía"><?php foreach ($catalog as $key => $metric): ?><a class="<?= $key === $initialMetricKey ? 'active' : '' ?>" data-metric-link="<?= $e($key) ?>" style="--metric-color:<?= $e($metric['color'] ?? '#475569') ?>" href="#energia-<?= $e($key) ?>"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-chart-line') ?>"></i><?= $e($metric['label'] ?? '') ?></a><?php endforeach; ?></nav>
  <div class="scroll-hint"><span>Desplázate hacia abajo para ver todos los indicadores</span><i class="fa-solid fa-arrow-down-long"></i></div>
  <div class="metric-scroll" id="metricScroll">
    <?php foreach ($annualMetrics as $key => $item): $metric = $item['metric']; $annual = $item['annual']; $moneyVariation = $item['money_variation']; ?>
      <section class="metric-panel" id="energia-<?= $e($key) ?>" data-metric-panel="<?= $e($key) ?>" style="--accent:<?= $e($metric['color'] ?? '#475569') ?>">
        <div class="metric-head"><div class="metric-title"><span class="metric-symbol"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-chart-line') ?>"></i></span><div><h2><?= $e($metric['label'] ?? '') ?></h2><p><?= $e($metric['group'] ?? '') ?> · comportamiento <?= $e($selectedYear) ?></p></div></div><?php if (!$item['locked']): ?><div class="annual-total"><span>Total del año</span><strong><?= $decimal($annual['quantity'], 3) ?> <?= $e($metric['unit'] ?? '') ?></strong></div><?php endif; ?></div>
        <div class="content-grid">
          <?php if ($item['locked']): ?>
          <div class="water-locked"><i class="fa-solid fa-lock"></i><h3>Información de Agua protegida</h3><p>Ingresa la clave autorizada para consultar registros, gráfica e importes.</p><button class="view-water" type="button" data-open-water>Ver</button></div>
          <?php else: ?>
          <aside class="receipts"><h3 class="receipts-title"><span>Registros por mes</span><i class="fa-regular fa-folder-open"></i></h3><?php if (!$item['has_records']): ?><div class="empty">No hay registros para <?= $e($metric['label'] ?? '') ?> en <?= $e($selectedYear) ?>.</div><?php else: ?><?php foreach ($annual['months'] as $monthNumber => $month): if ($month['records'] === []) continue; ?><details class="month-group" <?= $monthNumber === (int)$now->format('n') || $item['months_with_records'] === 1 ? 'open' : '' ?>><summary><span class="month-name"><?= $e($monthNames[$monthNumber]) ?></span><span class="month-total"><?= $decimal($month['quantity'], 3) ?> <?= $e($metric['unit'] ?? '') ?></span></summary><div class="receipt-list"><?php foreach ($month['records'] as $receipt): ?><div class="receipt-row"><span>Semana <?= $e($receipt['week']) ?> · <?= $e($receipt['date']->format('d/m')) ?></span><strong><?= $decimal($receipt['quantity'], 3) ?> <?= $e($metric['unit'] ?? '') ?></strong><small>Registro semanal</small><small class="receipt-money"><?= $money($receipt['money']) ?></small></div><?php endforeach; ?></div></details><?php endforeach; ?><?php endif; ?></aside>
          <article class="chart-card"><h3>Comportamiento del consumo · <?= $e($metric['unit'] ?? '') ?></h3><div class="chart-wrap"><canvas id="energy-chart-<?= $e($key) ?>"></canvas></div></article>
          <div class="summary-grid"><article class="summary-card"><span class="summary-icon"><i class="fa-solid fa-dollar-sign"></i></span><div class="summary-content"><span>Importe del año</span><strong><?= $money($annual['money']) ?></strong><small>Suma de los registros de <?= $e($selectedYear) ?></small><div class="comparison"><span>Comparación <?= $e($selectedYear - 1) ?></span><b><?= $money($item['previous_money']) ?></b><em class="<?= is_numeric($moneyVariation) ? ((float)$moneyVariation > 0 ? 'up' : ((float)$moneyVariation < 0 ? 'down' : '')) : '' ?>"><?= is_numeric($moneyVariation) ? (((float)$moneyVariation > 0 ? '+' : '') . $decimal($moneyVariation, 1) . '%') : 'Sin base' ?></em></div></div></article><article class="summary-card"><span class="summary-icon"><i class="fa-solid fa-scale-balanced"></i></span><div class="summary-content"><span>Consumo por kg producido</span><strong><?= $decimal($annual['ratio'], 6) ?></strong><small><?= $e($metric['ratio_unit'] ?? '') ?></small></div></article></div>
          <?php endif; ?>
        </div>
        <div class="capture-note">Los registros semanales se agrupan por el mes en que inicia cada corte.</div>
      </section>
    <?php endforeach; ?>
  </div>
  <dialog class="water-dialog" id="waterAccessDialog">
    <form method="post" action="?<?= $e(http_build_query(['anio' => $selectedYear, 'metrica' => 'agua'])) ?>">
      <input type="hidden" name="action" value="unlock_water">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['energy_water_csrf']) ?>">
      <div class="dialog-head"><div><i class="fa-solid fa-droplet"></i><section><h2>Consultar Agua</h2><p>Esta información requiere una clave de acceso.</p></section></div><button class="dialog-close" type="button" data-close-water aria-label="Cerrar">&times;</button></div>
      <div class="dialog-field"><label for="waterPassword">Clave</label><input id="waterPassword" type="password" name="clave" autocomplete="current-password" required></div>
      <?php if ($waterAccessError !== ''): ?><div class="dialog-error"><?= $e($waterAccessError) ?></div><?php endif; ?>
      <div class="dialog-actions"><button class="dialog-cancel" type="button" data-close-water>Cancelar</button><button class="dialog-submit" type="submit">Mostrar información</button></div>
    </form>
  </dialog>
</main>
<script>
(() => {
  const chartData = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  if (typeof Chart !== 'undefined') Object.entries(chartData).forEach(([key, metric]) => {
    const canvas = document.getElementById(`energy-chart-${key}`);
    if (!canvas) return;
    new Chart(canvas, { type: 'line', data: { labels: metric.labels, datasets: [{ data: metric.values, borderColor: metric.color, backgroundColor: `${metric.color}20`, pointBackgroundColor: metric.color, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6, borderWidth: 3, tension: .3, fill: true, spanGaps: true }] }, options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${Number(context.raw || 0).toLocaleString('es-MX', {maximumFractionDigits: 3})} ${metric.unit}` } } }, scales: { x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: 9, weight: '700' } } }, y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b', font: { size: 9, weight: '700' }, callback: (value) => Number(value).toLocaleString('es-MX') } } } } });
  });

  const panels = [...document.querySelectorAll('[data-metric-panel]')];
  const links = [...document.querySelectorAll('[data-metric-link]')];
  const selectedInput = document.getElementById('selectedMetricInput');
  const activate = (key) => {
    links.forEach((link) => link.classList.toggle('active', link.dataset.metricLink === key));
    if (selectedInput) selectedInput.value = key;
  };
  const goToMetric = (key, behavior = 'smooth') => {
    const panel = panels.find((item) => item.dataset.metricPanel === key);
    if (!panel) return;
    panel.scrollIntoView({ behavior, block: 'start' });
    activate(key);
  };
  links.forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); goToMetric(link.dataset.metricLink || ''); }));
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
      if (visible) activate(visible.target.dataset.metricPanel || '');
    }, { root: null, rootMargin: '-70px 0px -45% 0px', threshold: [.25, .55] });
    panels.forEach((panel) => observer.observe(panel));
  }
  if (<?= json_encode(isset($_GET['metrica'])) ?>) requestAnimationFrame(() => goToMetric(<?= json_encode($initialMetricKey) ?>, 'auto'));

  const waterDialog = document.getElementById('waterAccessDialog');
  const waterPassword = document.getElementById('waterPassword');
  document.querySelectorAll('[data-open-water]').forEach((button) => button.addEventListener('click', () => {
    if (!waterDialog) return;
    waterDialog.showModal();
    requestAnimationFrame(() => waterPassword?.focus());
  }));
  document.querySelectorAll('[data-close-water]').forEach((button) => button.addEventListener('click', () => waterDialog?.close()));
  waterDialog?.addEventListener('click', (event) => {
    if (event.target === waterDialog) waterDialog.close();
  });
  if (<?= json_encode($waterAccessError !== '') ?> && waterDialog) {
    waterDialog.showModal();
    requestAnimationFrame(() => waterPassword?.focus());
  }
})();
</script>
</body>
</html>
