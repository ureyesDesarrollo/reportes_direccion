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
require_once __DIR__ . '/electricity_costs.php';
require_once __DIR__ . '/gas_natural_invoices.php';
require_once __DIR__ . '/water_invoices.php';
$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$records = energyLoadRecords();
$monthlyRecords = energyLoadMonthlyRecords();
$sourceWarnings = [];
$gasNaturalSource = (array)($config['gas_natural_invoice_api'] ?? []);
if ($gasNaturalSource !== []) {
  $gasNaturalSync = energySyncGasNaturalInvoices(
    $gasNaturalSource,
    (array)($config['production_database'] ?? []),
    $timezone
  );
  if (trim((string)($gasNaturalSync['warning'] ?? '')) !== '') $sourceWarnings[] = (string)$gasNaturalSync['warning'];
}
$waterSource = (array)($config['water_invoice_api'] ?? []);
if ($waterSource !== []) {
  $waterSync = energySyncWaterInvoices($waterSource, $timezone);
  if (trim((string)($waterSync['warning'] ?? '')) !== '') $sourceWarnings[] = (string)$waterSync['warning'];
}
$receipts = energyLoadReceipts();
$electricityCostSources = (array)($config['electricity_cost_apis'] ?? []);
if ($electricityCostSources === [] && isset($config['electricity_cost_api'])) {
  $electricityCostSources[] = (array)$config['electricity_cost_api'];
}
foreach ($electricityCostSources as $electricityCostSource) {
  $electricityCostSource = (array)$electricityCostSource;
  $electricityCosts = energyLoadElectricityCosts($electricityCostSource, $timezone);
  if (trim((string)($electricityCosts['warning'] ?? '')) !== '') $sourceWarnings[] = (string)$electricityCosts['warning'];
  $receipts = energyMergeElectricityCosts(
    $receipts,
    $electricityCosts,
    $timezone,
    trim((string)($electricityCostSource['company'] ?? ''))
  );
}
$sourceWarnings = array_values(array_unique(array_filter($sourceWarnings)));
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
foreach ($monthlyRecords as $key => $record) {
  if (preg_match('/^(\d{4})-(\d{2})$/', (string)$key, $matches) === 1) {
    $availableYears[(int)$matches[1]] = true;
  }
}
foreach ($receipts as $receipt) {
  if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', (string)($receipt['receipt_date'] ?? ''), $matches) === 1) {
    $availableYears[(int)$matches[1]] = true;
  }
}
$availableYears[$selectedYear] = true;
$availableYears = array_keys($availableYears);
rsort($availableYears);

$monthNames = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$monthShortNames = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$currentYear = (int)$now->format('Y');
$comparisonCutoffMonth = $selectedYear === $currentYear ? max(0, (int)$now->format('n') - 1) : 12;
$sumMoneyThroughMonth = static function (array $annual, int $cutoffMonth): ?float {
  if ($cutoffMonth < 1) return null;
  $total = 0.0;
  $hasMoney = false;
  foreach ((array)($annual['months'] ?? []) as $monthNumber => $month) {
    if ((int)$monthNumber > $cutoffMonth || empty($month['has_money'])) continue;
    $total += (float)($month['money'] ?? 0.0);
    $hasMoney = true;
  }
  return $hasMoney ? $total : null;
};
$sumConsumptionThroughMonth = static function (array $annual, int $cutoffMonth): array {
  if ($cutoffMonth < 1) return ['quantity' => null, 'production' => null, 'ratio' => null];
  $quantity = 0.0;
  $production = 0.0;
  $hasQuantity = false;
  foreach ((array)($annual['months'] ?? []) as $monthNumber => $month) {
    if ((int)$monthNumber > $cutoffMonth || empty($month['has_quantity'])) continue;
    $quantity += (float)($month['quantity'] ?? 0.0);
    $production += (float)($month['production'] ?? 0.0);
    $hasQuantity = true;
  }
  return [
    'quantity' => $hasQuantity ? $quantity : null,
    'production' => $production > 0 ? $production : null,
    'ratio' => $hasQuantity && $production > 0 ? $quantity / $production : null,
  ];
};
$annualMetrics = [];
$chartPayload = [];
foreach ($catalog as $key => $metric) {
  $metricRecords = $key === 'agua' && !$waterUnlocked ? [] : $records;
  $metricReceipts = $key === 'agua' && !$waterUnlocked ? [] : $receipts;
  $annual = energyBuildAnnualMetric($metricRecords, $metric, $selectedYear, $timezone, $metricReceipts, $monthlyRecords);
  $previousAnnual = energyBuildAnnualMetric($metricRecords, $metric, $selectedYear - 1, $timezone, $metricReceipts, $monthlyRecords);
  $previousMoney = $previousAnnual['money'];
  $moneyVariation = is_numeric($annual['money']) && is_numeric($previousMoney) && (float)$previousMoney != 0.0
    ? (((float)$annual['money'] - (float)$previousMoney) / abs((float)$previousMoney)) * 100
    : null;
  $accumulatedMoney = $sumMoneyThroughMonth($annual, $comparisonCutoffMonth);
  $previousAccumulatedMoney = $sumMoneyThroughMonth($previousAnnual, $comparisonCutoffMonth);
  $accumulatedMoneyVariation = is_numeric($accumulatedMoney) && is_numeric($previousAccumulatedMoney) && (float)$previousAccumulatedMoney != 0.0
    ? (((float)$accumulatedMoney - (float)$previousAccumulatedMoney) / abs((float)$previousAccumulatedMoney)) * 100
    : null;
  $accumulatedConsumption = $sumConsumptionThroughMonth($annual, $comparisonCutoffMonth);
  $previousAccumulatedConsumption = $sumConsumptionThroughMonth($previousAnnual, $comparisonCutoffMonth);
  $accumulatedMoneyRatio = is_numeric($accumulatedMoney)
    && is_numeric($accumulatedConsumption['production'])
    && (float)$accumulatedConsumption['production'] > 0
      ? (float)$accumulatedMoney / (float)$accumulatedConsumption['production']
      : null;
  $previousAccumulatedMoneyRatio = is_numeric($previousAccumulatedMoney)
    && is_numeric($previousAccumulatedConsumption['production'])
    && (float)$previousAccumulatedConsumption['production'] > 0
      ? (float)$previousAccumulatedMoney / (float)$previousAccumulatedConsumption['production']
      : null;
  $accumulatedConsumptionVariation = is_numeric($accumulatedConsumption['quantity'])
    && is_numeric($previousAccumulatedConsumption['quantity'])
    && (float)$previousAccumulatedConsumption['quantity'] != 0.0
      ? (((float)$accumulatedConsumption['quantity'] - (float)$previousAccumulatedConsumption['quantity']) / abs((float)$previousAccumulatedConsumption['quantity'])) * 100
      : null;
  $previousMonth = $comparisonCutoffMonth > 0 ? (array)($annual['months'][$comparisonCutoffMonth] ?? []) : [];
  $hasRecords = false;
  $monthsWithRecords = 0;
  $chartValues = [];
  foreach ($annual['months'] as $month) {
    $chartValues[] = $month['has_quantity'] ? round((float)$month['quantity'], 2) : null;
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
    'accumulated_money' => $accumulatedMoney,
    'previous_accumulated_money' => $previousAccumulatedMoney,
    'accumulated_money_variation' => $accumulatedMoneyVariation,
    'previous_month_money' => !empty($previousMonth['has_money']) ? (float)($previousMonth['money'] ?? 0.0) : null,
    'accumulated_money_ratio' => $accumulatedMoneyRatio,
    'previous_accumulated_money_ratio' => $previousAccumulatedMoneyRatio,
    'accumulated_comparison_label' => $comparisonCutoffMonth > 0
      ? 'Ene–' . $monthShortNames[$comparisonCutoffMonth] . ' ' . ($selectedYear - 1)
      : 'Sin periodo cerrado',
    'previous_month_label' => $comparisonCutoffMonth > 0 ? $monthNames[$comparisonCutoffMonth] : 'Sin mes cerrado',
    'previous_month_quantity' => !empty($previousMonth['has_quantity']) ? (float)($previousMonth['quantity'] ?? 0.0) : null,
    'accumulated_consumption' => $accumulatedConsumption['quantity'],
    'previous_accumulated_consumption' => $previousAccumulatedConsumption['quantity'],
    'accumulated_consumption_variation' => $accumulatedConsumptionVariation,
    'accumulated_ratio' => $accumulatedConsumption['ratio'],
    'previous_accumulated_ratio' => $previousAccumulatedConsumption['ratio'],
    'consumption_current_label' => $comparisonCutoffMonth > 0 ? 'Ene–' . $monthShortNames[$comparisonCutoffMonth] . ' ' . $selectedYear : 'Sin periodo cerrado',
    'consumption_previous_label' => $comparisonCutoffMonth > 0 ? 'Ene–' . $monthShortNames[$comparisonCutoffMonth] . ' ' . ($selectedYear - 1) : 'Sin periodo cerrado',
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
  $decimals = max(0, min(2, $decimals));
  return rtrim(rtrim(number_format((float)$value, $decimals, '.', ','), '0'), '.');
};
$money = static fn($value): string => is_numeric($value) ? '$' . number_format((float)$value, 2, '.', ',') : '—';
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
  <?php if (!$captureMode): ?>
  <script src="../../assets/js/display-mode.js"></script>
  <script>
    (() => {
      const largeScreen = window.matchMedia('(min-width: 1700px)');
      const syncEnergyDisplay = () => document.documentElement.classList.toggle('energy-tv-display', largeScreen.matches);
      syncEnergyDisplay();
      largeScreen.addEventListener?.('change', syncEnergyDisplay);
    })();
  </script>
  <?php endif; ?>
  <style>
    :root{--ink:#0f172a;--muted:#64748b;--line:#dbe3ee;--surface:#fff}*{box-sizing:border-box}html{scroll-behavior:smooth;scroll-padding-top:62px}body{margin:0;color:var(--ink);background:#f2f5f8;font-family:Inter,system-ui,sans-serif}.report{width:min(1180px,calc(100% - 28px));margin:18px auto 38px}.top-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px}.link{display:inline-flex;align-items:center;gap:8px;padding:9px 13px;border:1px solid var(--line);border-radius:999px;color:#334155;background:#fff;font-size:12px;font-weight:800;text-decoration:none}.capture-link{font-size:11px;opacity:.82}.header{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:18px 21px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 12px 30px rgba(15,23,42,.07)}.title-wrap{display:flex;align-items:center;gap:14px}.title-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:15px;color:#fff;background:#475569;font-size:23px}h1{margin:0;font-size:clamp(27px,4vw,38px);font-weight:900}.subtitle{margin:5px 0 0;color:var(--muted);font-size:12px;font-weight:700}.year-form{display:flex;align-items:end;gap:7px}.year-form label{display:flex;flex-direction:column;gap:4px;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.year-form select{height:34px;padding:5px 28px 5px 9px;border:1px solid var(--line);border-radius:9px;color:#334155;background:#fff;font:inherit;font-size:12px;font-weight:900}.year-form button{height:34px;padding:0 11px;border:0;border-radius:9px;color:#fff;background:#475569;font:inherit;font-size:10px;font-weight:900}.metric-nav{position:sticky;top:6px;z-index:10;display:flex;gap:7px;margin-top:11px;padding:8px;overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.96);box-shadow:0 5px 14px rgba(15,23,42,.06);scrollbar-width:thin}.metric-nav a{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid transparent;border-radius:10px;color:#64748b;background:#f8fafc;font-size:10px;font-weight:900;text-decoration:none;white-space:nowrap;text-transform:uppercase}.metric-nav a.active{color:#fff;background:var(--metric-color);box-shadow:0 5px 12px color-mix(in srgb,var(--metric-color) 25%,transparent)}.scroll-hint{display:flex;align-items:center;justify-content:flex-end;gap:7px;margin:7px 5px 0;color:#64748b;font-size:9px;font-weight:900;text-transform:uppercase}.metric-scroll{display:flex;flex-direction:column;gap:14px;margin-top:7px;padding:2px 2px 12px;overflow:visible}.metric-panel{--accent:#475569;width:100%;min-width:0;padding:17px;border:1px solid var(--line);border-radius:19px;background:#fff;scroll-margin-top:62px}.metric-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.metric-title{display:flex;align-items:center;gap:12px}.metric-symbol{display:grid;place-items:center;width:45px;height:45px;border-radius:13px;color:#fff;background:var(--accent);font-size:20px}.metric-title h2{margin:0;font-size:23px;font-weight:900}.metric-title p{margin:3px 0 0;color:var(--muted);font-size:10px;font-weight:800}.annual-total{text-align:right}.annual-total span{display:block;color:var(--muted);font-size:9px;font-weight:900;text-transform:uppercase}.annual-total strong{display:block;margin-top:3px;color:var(--accent);font-size:25px;font-weight:900;white-space:nowrap}.content-grid{display:grid;grid-template-columns:330px minmax(0,1fr);gap:13px}.receipts,.chart-card{min-width:0;border:1px solid var(--line);border-radius:15px;background:#f8fafc}.receipts{height:350px;padding:12px;overflow:auto}.receipts-title{display:flex;align-items:center;justify-content:space-between;margin:0 0 9px;color:#334155;font-size:11px;font-weight:900;text-transform:uppercase}.month-group{margin-bottom:7px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;overflow:hidden}.month-group summary{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 10px;cursor:pointer;list-style:none}.month-group summary::-webkit-details-marker{display:none}.month-name{font-size:11px;font-weight:900}.month-total{color:var(--accent);font-size:10px;font-weight:900;white-space:nowrap}.receipt-list{border-top:1px solid #edf2f7}.receipt-row{display:grid;grid-template-columns:1fr auto;gap:3px 8px;padding:8px 10px}.receipt-row+.receipt-row{border-top:1px solid #edf2f7}.receipt-row span{color:#475569;font-size:9px;font-weight:800}.receipt-row strong{font-size:10px;font-weight:900;text-align:right}.receipt-row small{color:#94a3b8;font-size:8px;font-weight:800}.receipt-money{color:#475569!important;text-align:right}.empty{display:grid;place-items:center;height:280px;padding:20px;color:#94a3b8;font-size:11px;font-weight:800;text-align:center}.chart-card{display:flex;height:350px;flex-direction:column;padding:13px 15px}.chart-card h3{margin:0;color:#334155;font-size:11px;font-weight:900;text-transform:uppercase}.chart-wrap{position:relative;min-height:0;flex:1;margin-top:8px}.summary-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:13px}.summary-card{display:grid;grid-template-columns:68px minmax(0,1fr);min-height:105px;overflow:hidden;border:1px solid var(--line);border-radius:15px;background:#fff}.summary-icon{display:grid;place-items:center;color:#fff;background:var(--accent);font-size:22px}.summary-content{display:flex;min-width:0;flex-direction:column;justify-content:center;padding:13px 16px}.summary-content span{color:#64748b;font-size:10px;font-weight:900;text-transform:uppercase}.summary-content strong{margin-top:6px;color:#172b45;font-size:clamp(25px,4vw,36px);line-height:1;font-weight:900;white-space:nowrap}.summary-content small{margin-top:5px;color:var(--muted);font-size:9px;font-weight:700}.capture-note{margin-top:10px;color:#94a3b8;font-size:9px;font-weight:700;text-align:right}@media(max-width:820px){.content-grid{grid-template-columns:1fr}.receipts{height:auto;max-height:320px}.chart-card{height:310px}}@media(max-width:560px){.report{width:calc(100% - 16px);margin-top:8px}.header{align-items:flex-start;padding:15px}.year-form{flex-direction:column;align-items:stretch}.year-form button{display:none}.title-icon{width:44px;height:44px}.metric-panel{padding:12px}.metric-head{align-items:flex-start}.annual-total strong{font-size:19px}.summary-grid{grid-template-columns:1fr}.content-grid{gap:9px}}
    .report{width:min(1480px,calc(100% - 28px))}.content-grid{grid-template-columns:250px minmax(430px,1fr) 195px 215px;align-items:stretch}.receipts,.chart-card{height:310px}.summary-grid{display:contents}.summary-card{grid-template-columns:1fr;grid-template-rows:58px minmax(0,1fr);height:310px;min-height:0}.summary-icon{font-size:20px}.summary-content{justify-content:flex-start;padding:14px 12px;text-align:center}.summary-content strong{font-size:clamp(20px,2vw,28px);white-space:normal;overflow-wrap:anywhere}.summary-content small{margin-top:7px;line-height:1.25}.comparison{margin-top:9px;padding-top:8px;border-top:1px solid var(--line)}.comparison span{display:block;color:#64748b;font-size:8px;font-weight:900;text-transform:uppercase}.comparison b{display:block;margin-top:3px;color:#334155;font-size:13px;font-weight:900}.comparison em{display:inline-flex;margin-top:3px;padding:3px 6px;border-radius:999px;color:#475569;background:#f1f5f9;font-size:9px;font-style:normal;font-weight:900}.comparison em.up{color:#9a3412;background:#ffedd5}.comparison em.down{color:#166534;background:#dcfce7}.water-locked{grid-column:1/-1;display:flex;height:310px;flex-direction:column;align-items:center;justify-content:center;border:1px dashed #93c5fd;border-radius:15px;background:#eff6ff;text-align:center}.water-locked i{color:#2563eb;font-size:31px}.water-locked h3{margin:13px 0 5px;font-size:18px;font-weight:900}.water-locked p{margin:0;color:#64748b;font-size:11px;font-weight:700}.view-water{margin-top:16px;padding:10px 22px;border:0;border-radius:999px;color:#fff;background:#2563eb;font:inherit;font-size:12px;font-weight:900;cursor:pointer}.water-dialog{width:min(410px,calc(100% - 28px));padding:0;border:0;border-radius:18px;color:var(--ink);box-shadow:0 24px 70px rgba(15,23,42,.28)}.water-dialog::backdrop{background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}.water-dialog form{padding:22px}.dialog-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.dialog-head div{display:flex;gap:11px}.dialog-head i{display:grid;place-items:center;width:39px;height:39px;border-radius:11px;color:#fff;background:#2563eb}.dialog-head h2{margin:0;font-size:20px;font-weight:900}.dialog-head p{margin:4px 0 0;color:#64748b;font-size:11px;font-weight:700}.dialog-close{padding:4px;border:0;color:#64748b;background:transparent;font-size:20px;cursor:pointer}.dialog-field{display:flex;flex-direction:column;gap:6px;margin-top:18px}.dialog-field label{color:#475569;font-size:11px;font-weight:900;text-transform:uppercase}.dialog-field input{width:100%;height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font:inherit;font-size:16px;outline:none}.dialog-field input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12)}.dialog-error{margin-top:10px;padding:9px 11px;border-radius:9px;color:#991b1b;background:#fee2e2;font-size:11px;font-weight:800}.dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}.dialog-actions button{padding:9px 14px;border-radius:999px;font:inherit;font-size:11px;font-weight:900;cursor:pointer}.dialog-cancel{border:1px solid var(--line);color:#475569;background:#fff}.dialog-submit{border:0;color:#fff;background:#2563eb}@media(max-width:1120px){.content-grid{grid-template-columns:230px minmax(360px,1fr) 175px 190px;gap:9px}.summary-content{padding:12px 9px}.summary-content strong{font-size:20px}}@media(max-width:900px){.content-grid{grid-template-columns:1fr 1fr}.receipts,.chart-card{height:310px}.summary-card{height:auto;min-height:190px;grid-template-columns:58px 1fr;grid-template-rows:1fr}.summary-content{justify-content:center;text-align:left}.summary-content strong{white-space:nowrap}.comparison{margin-top:8px;padding-top:7px}}@media(max-width:560px){.content-grid{grid-template-columns:1fr}.summary-card{min-height:190px}}
    .summary-card.is-money .summary-content strong{font-size:clamp(18px,1.65vw,25px);letter-spacing:-.04em;white-space:nowrap}
    :is(html.executive-display,html.energy-tv-display) .report{width:min(1880px,calc(100% - 32px));margin:22px auto 46px}
    :is(html.executive-display,html.energy-tv-display) .top-actions{margin-bottom:15px}
    :is(html.executive-display,html.energy-tv-display) .link{padding:12px 17px;font-size:15px}
    :is(html.executive-display,html.energy-tv-display) .capture-link{font-size:14px}
    :is(html.executive-display,html.energy-tv-display) .header{padding:23px 27px}
    :is(html.executive-display,html.energy-tv-display) .title-icon{width:66px;height:66px;font-size:30px}
    :is(html.executive-display,html.energy-tv-display) h1{font-size:44px}
    :is(html.executive-display,html.energy-tv-display) .subtitle{font-size:16px}
    :is(html.executive-display,html.energy-tv-display) .year-form label{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .year-form select{height:44px;padding-left:13px;font-size:16px}
    :is(html.executive-display,html.energy-tv-display) .year-form button{height:44px;padding:0 16px;font-size:14px}
    :is(html.executive-display,html.energy-tv-display) .metric-nav{gap:9px;margin-top:14px;padding:10px}
    :is(html.executive-display,html.energy-tv-display) .metric-nav a{padding:11px 15px;font-size:13px}
    :is(html.executive-display,html.energy-tv-display) .scroll-hint{margin-top:9px;font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .metric-scroll{gap:18px;margin-top:9px}
    :is(html.executive-display,html.energy-tv-display) .metric-panel{padding:22px;border-radius:23px;scroll-margin-top:78px}
    :is(html.executive-display,html.energy-tv-display) .metric-head{margin-bottom:18px}
    :is(html.executive-display,html.energy-tv-display) .metric-symbol{width:58px;height:58px;font-size:27px}
    :is(html.executive-display,html.energy-tv-display) .metric-title h2{font-size:31px}
    :is(html.executive-display,html.energy-tv-display) .metric-title p{font-size:14px}
    :is(html.executive-display,html.energy-tv-display) .annual-total span{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .annual-total strong{font-size:34px}
    :is(html.executive-display,html.energy-tv-display) .content-grid{grid-template-columns:minmax(260px,1fr) minmax(400px,2.35fr) minmax(205px,.82fr) minmax(220px,.92fr);gap:15px}
    :is(html.executive-display,html.energy-tv-display) .receipts,
    :is(html.executive-display,html.energy-tv-display) .chart-card,
    :is(html.executive-display,html.energy-tv-display) .summary-card{height:390px}
    :is(html.executive-display,html.energy-tv-display) .receipts{padding:16px}
    :is(html.executive-display,html.energy-tv-display) .receipts-title,
    :is(html.executive-display,html.energy-tv-display) .chart-card h3{font-size:14px}
    :is(html.executive-display,html.energy-tv-display) .month-group summary{padding:12px 13px}
    :is(html.executive-display,html.energy-tv-display) .month-name{font-size:14px}
    :is(html.executive-display,html.energy-tv-display) .month-total{font-size:13px}
    :is(html.executive-display,html.energy-tv-display) .receipt-row{padding:11px 13px}
    :is(html.executive-display,html.energy-tv-display) .receipt-row span{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .receipt-row strong{font-size:13px}
    :is(html.executive-display,html.energy-tv-display) .receipt-row small{font-size:11px}
    :is(html.executive-display,html.energy-tv-display) .empty{height:315px;font-size:15px}
    :is(html.executive-display,html.energy-tv-display) .chart-card{padding:17px 19px}
    :is(html.executive-display,html.energy-tv-display) .summary-card{grid-template-rows:72px minmax(0,1fr)}
    :is(html.executive-display,html.energy-tv-display) .summary-icon{font-size:27px}
    :is(html.executive-display,html.energy-tv-display) .summary-content{padding:22px 17px}
    :is(html.executive-display,html.energy-tv-display) .summary-content span{font-size:13px}
    :is(html.executive-display,html.energy-tv-display) .summary-content strong{font-size:32px}
    :is(html.executive-display,html.energy-tv-display) .summary-card.is-money .summary-content strong{font-size:clamp(24px,1.65vw,31px)}
    :is(html.executive-display,html.energy-tv-display) .summary-content small{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .comparison span{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .comparison b{font-size:18px}
    :is(html.executive-display,html.energy-tv-display) .comparison em{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .capture-note{font-size:12px}
    :is(html.executive-display,html.energy-tv-display) .water-locked{height:390px}
    :is(html.executive-display,html.energy-tv-display) .water-locked i{font-size:40px}
    :is(html.executive-display,html.energy-tv-display) .water-locked h3{font-size:24px}
    :is(html.executive-display,html.energy-tv-display) .water-locked p{font-size:15px}
    :is(html.executive-display,html.energy-tv-display) .view-water{padding:13px 28px;font-size:15px}
    .summary-card.is-consumption .summary-content{justify-content:flex-start;padding:11px 10px;text-align:left}.summary-card.is-consumption .consumption-label{padding-right:0;font-size:9px}.summary-card.is-consumption .consumption-main{margin-top:4px;font-size:clamp(18px,1.55vw,23px);letter-spacing:-.035em;white-space:nowrap}.summary-card.is-consumption .consumption-unit{margin-top:2px}.consumption-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:3px 6px;margin-top:7px;padding-top:6px;border-top:1px solid var(--line)}.consumption-row span{align-self:center;color:#64748b;font-size:7.5px;font-weight:900;line-height:1.15;text-transform:uppercase}.consumption-row b{align-self:center;color:#334155;font-size:11px;font-weight:900;text-align:right;white-space:nowrap}.consumption-row em{grid-column:2;justify-self:end;padding:2px 5px;border-radius:999px;color:#475569;background:#f1f5f9;font-size:8px;font-style:normal;font-weight:900}.consumption-row em.up{color:#9a3412;background:#ffedd5}.consumption-row em.down{color:#166534;background:#dcfce7}:is(html.executive-display,html.energy-tv-display) .summary-card.is-consumption .summary-content{padding:15px 13px}:is(html.executive-display,html.energy-tv-display) .summary-card.is-consumption .consumption-label{font-size:12px}:is(html.executive-display,html.energy-tv-display) .summary-card.is-consumption .consumption-main{font-size:27px}:is(html.executive-display,html.energy-tv-display) .consumption-row span{font-size:10px}:is(html.executive-display,html.energy-tv-display) .consumption-row b{font-size:15px}:is(html.executive-display,html.energy-tv-display) .consumption-row em{font-size:10px}
    .source-warning{display:flex;align-items:flex-start;gap:9px;margin-top:10px;padding:10px 13px;border:1px solid #fcd34d;border-radius:11px;color:#854d0e;background:#fffbeb;font-size:10px;font-weight:800}.source-warning i{margin-top:1px}.source-warning span{display:block}.source-warning span+span{margin-top:3px}
    <?php if ($captureMode): ?>body{background:#fff}.report{width:1460px;margin:7px auto}.top-actions,.year-form,.metric-nav,.scroll-hint,.capture-note,.source-warning{display:none}.header{padding:12px 16px;box-shadow:none}.metric-scroll{margin-top:8px;padding-bottom:0}.metric-panel{padding:13px}.content-grid{grid-template-columns:250px minmax(430px,1fr) 195px 215px}.receipts,.chart-card,.summary-card{height:290px}<?php endif; ?>
  </style>
</head>
<body>
<main class="report">
  <div class="top-actions"><a class="link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Regresar al inicio</a><a class="link capture-link" href="captura.php"><i class="fa-regular fa-pen-to-square"></i> Capturar registro</a></div>
  <header class="header"><div class="title-wrap"><span class="title-icon"><i class="fa-solid fa-bolt"></i></span><div><h1><?= $e($config['titulo'] ?? 'Reporte de Energía') ?></h1><p class="subtitle"><?= $e($config['subtitulo'] ?? '') ?></p></div></div><form class="year-form" method="get"><input type="hidden" name="metrica" id="selectedMetricInput" value="<?= $e($initialMetricKey) ?>"><label>Año<select name="anio" onchange="this.form.submit()"><?php foreach ($availableYears as $year): ?><option value="<?= $e($year) ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= $e($year) ?></option><?php endforeach; ?></select></label><button>Consultar</button></form></header>
  <?php if ($sourceWarnings !== []): ?><div class="source-warning"><i class="fa-solid fa-triangle-exclamation"></i><div><?php foreach ($sourceWarnings as $sourceWarning): ?><span><?= $e($sourceWarning) ?></span><?php endforeach; ?></div></div><?php endif; ?>
  <nav class="metric-nav" aria-label="Indicadores de energía"><?php foreach ($catalog as $key => $metric): ?><a class="<?= $key === $initialMetricKey ? 'active' : '' ?>" data-metric-link="<?= $e($key) ?>" style="--metric-color:<?= $e($metric['color'] ?? '#475569') ?>" href="#energia-<?= $e($key) ?>"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-chart-line') ?>"></i><?= $e($metric['label'] ?? '') ?></a><?php endforeach; ?></nav>
  <div class="scroll-hint"><span>Desplázate hacia abajo para ver todos los indicadores</span><i class="fa-solid fa-arrow-down-long"></i></div>
  <div class="metric-scroll" id="metricScroll">
    <?php foreach ($annualMetrics as $key => $item): $metric = $item['metric']; $annual = $item['annual']; $moneyVariation = $item['money_variation']; $consumptionVariation = $item['accumulated_consumption_variation']; ?>
      <section class="metric-panel" id="energia-<?= $e($key) ?>" data-metric-panel="<?= $e($key) ?>" style="--accent:<?= $e($metric['color'] ?? '#475569') ?>">
        <div class="metric-head"><div class="metric-title"><span class="metric-symbol"><i class="fa-solid <?= $e($metric['icon'] ?? 'fa-chart-line') ?>"></i></span><div><h2><?= $e($metric['label'] ?? '') ?></h2><p><?= $e($metric['group'] ?? '') ?> · comportamiento <?= $e($selectedYear) ?></p></div></div><?php if (!$item['locked']): ?><div class="annual-total"><span>Total del año</span><strong><?= $decimal($annual['quantity'], 2) ?> <?= $e($metric['unit'] ?? '') ?></strong></div><?php endif; ?></div>
        <div class="content-grid">
          <?php if ($item['locked']): ?>
          <div class="water-locked"><i class="fa-solid fa-lock"></i><h3>Información de Agua protegida</h3><p>Ingresa la clave autorizada para consultar registros, gráfica e importes.</p><button class="view-water" type="button" data-open-water>Ver</button></div>
          <?php else: ?>
          <aside class="receipts"><h3 class="receipts-title"><span>Registros por mes</span><i class="fa-regular fa-folder-open"></i></h3><?php if (!$item['has_records']): ?><div class="empty">No hay registros para <?= $e($metric['label'] ?? '') ?> en <?= $e($selectedYear) ?>.</div><?php else: ?><?php foreach ($annual['months'] as $monthNumber => $month): if ($month['records'] === []) continue; ?><details class="month-group" <?= $monthNumber === (int)$now->format('n') || $item['months_with_records'] === 1 ? 'open' : '' ?>><summary><span class="month-name"><?= $e($monthNames[$monthNumber]) ?></span><span class="month-total">Total <?= $month['has_quantity'] ? $decimal($month['quantity'], 2) . ' ' . $e($metric['unit'] ?? '') : '—' ?></span></summary><div class="receipt-list"><?php foreach ($month['records'] as $receipt): ?><div class="receipt-row"><?php if (($receipt['source'] ?? '') === 'receipt'): ?><span><?= $e($receipt['company'] ?? 'Progel') ?> · <?= $e($receipt['date']->format('d/m')) ?></span><strong><?= $decimal($receipt['quantity'], 2) ?> <?= $e($metric['unit'] ?? '') ?></strong><small><?= $e($receipt['period_start']) ?> al <?= $e($receipt['period_end']) ?></small><small class="receipt-money"><?= $money($receipt['money']) ?><?= ($receipt['cost_source'] ?? '') === 'api' ? ' · API' : '' ?></small><?php elseif (($receipt['source'] ?? '') === 'monthly'): ?><span>Captura mensual · <?= $e($receipt['date']->format('m/Y')) ?></span><strong><?= $decimal($receipt['quantity'], 2) ?> <?= $e($metric['unit'] ?? '') ?></strong><small>Registro operativo mensual</small><small class="receipt-money"><?= $money($receipt['money']) ?></small><?php else: ?><span>Semana <?= $e($receipt['week']) ?> · <?= $e($receipt['date']->format('d/m')) ?></span><strong><?= $decimal($receipt['quantity'], 2) ?> <?= $e($metric['unit'] ?? '') ?></strong><small>Registro semanal histórico</small><small class="receipt-money"><?= $money($receipt['money']) ?></small><?php endif; ?></div><?php endforeach; ?></div></details><?php endforeach; ?><?php endif; ?></aside>
          <article class="chart-card"><h3>Comportamiento del consumo · <?= $e($metric['unit'] ?? '') ?></h3><div class="chart-wrap"><canvas id="energy-chart-<?= $e($key) ?>"></canvas></div></article>
          <div class="summary-grid">
            <article class="summary-card is-money is-consumption">
              <span class="summary-icon"><i class="fa-solid fa-dollar-sign"></i></span>
              <div class="summary-content">
                <span class="consumption-label">Costo del mes anterior · <?= $e($item['previous_month_label']) ?></span>
                <strong class="consumption-main"><?= $money($item['previous_month_money']) ?></strong>
                <small class="consumption-unit">Subtotal sin impuestos</small>
                <div class="consumption-row"><span><?= $e($item['consumption_current_label']) ?></span><b><?= $money($item['accumulated_money']) ?></b></div>
                <div class="consumption-row"><span><?= $e($item['consumption_previous_label']) ?></span><b><?= $money($item['previous_accumulated_money']) ?></b><em class="<?= is_numeric($item['accumulated_money_variation']) ? ((float)$item['accumulated_money_variation'] > 0 ? 'up' : ((float)$item['accumulated_money_variation'] < 0 ? 'down' : '')) : '' ?>"><?= is_numeric($item['accumulated_money_variation']) ? (((float)$item['accumulated_money_variation'] > 0 ? '+' : '') . $decimal($item['accumulated_money_variation'], 2) . '%') : 'Sin base' ?></em></div>
                <div class="consumption-row"><span>$/kg · <?= $e($selectedYear) ?></span><b><?= $money($item['accumulated_money_ratio']) ?></b></div>
                <div class="consumption-row"><span>$/kg · <?= $e($selectedYear - 1) ?></span><b><?= $money($item['previous_accumulated_money_ratio']) ?></b></div>
              </div>
            </article>
            <article class="summary-card is-consumption">
              <span class="summary-icon"><i class="fa-solid fa-scale-balanced"></i></span>
              <div class="summary-content">
                <span class="consumption-label">Consumo del mes anterior · <?= $e($item['previous_month_label']) ?></span>
                <strong class="consumption-main"><?= $decimal($item['previous_month_quantity'], 2) ?></strong>
                <small class="consumption-unit"><?= $e($metric['unit'] ?? '') ?></small>
                <div class="consumption-row"><span><?= $e($item['consumption_current_label']) ?></span><b><?= $decimal($item['accumulated_consumption'], 2) ?> <?= $e($metric['unit'] ?? '') ?></b></div>
                <div class="consumption-row"><span><?= $e($item['consumption_previous_label']) ?></span><b><?= $decimal($item['previous_accumulated_consumption'], 2) ?> <?= $e($metric['unit'] ?? '') ?></b><em class="<?= is_numeric($consumptionVariation) ? ((float)$consumptionVariation > 0 ? 'up' : ((float)$consumptionVariation < 0 ? 'down' : '')) : '' ?>"><?= is_numeric($consumptionVariation) ? (((float)$consumptionVariation > 0 ? '+' : '') . $decimal($consumptionVariation, 2) . '%') : 'Sin base' ?></em></div>
                <div class="consumption-row"><span><?= $e($metric['ratio_unit'] ?? '') ?> · <?= $e($selectedYear) ?></span><b><?= $decimal($item['accumulated_ratio'], 2) ?></b></div>
                <div class="consumption-row"><span><?= $e($metric['ratio_unit'] ?? '') ?> · <?= $e($selectedYear - 1) ?></span><b><?= $decimal($item['previous_accumulated_ratio'], 2) ?></b></div>
              </div>
            </article>
          </div>
          <?php endif; ?>
        </div>
        <div class="capture-note"><?= ($metric['group'] ?? '') === 'Consumos' ? 'Los recibos se agrupan por su fecha de emisión; los registros anteriores permanecen como histórico semanal.' : 'La captura mensual sustituye las semanas del mismo mes; los meses anteriores conservan su histórico semanal.' ?></div>
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
  const largePresentation = document.documentElement.classList.contains('executive-display') ||
    document.documentElement.classList.contains('energy-tv-display');
  const chartFontSize = largePresentation ? 12 : 9;
  if (typeof Chart !== 'undefined') Object.entries(chartData).forEach(([key, metric]) => {
    const canvas = document.getElementById(`energy-chart-${key}`);
    if (!canvas) return;
    new Chart(canvas, { type: 'line', data: { labels: metric.labels, datasets: [{ data: metric.values, borderColor: metric.color, backgroundColor: `${metric.color}20`, pointBackgroundColor: metric.color, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: largePresentation ? 5 : 4, pointHoverRadius: largePresentation ? 7 : 6, borderWidth: largePresentation ? 4 : 3, tension: .3, fill: true, spanGaps: true }] }, options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, plugins: { legend: { display: false }, tooltip: { bodyFont: { size: largePresentation ? 14 : 12 }, titleFont: { size: largePresentation ? 14 : 12 }, callbacks: { label: (context) => `${Number(context.raw || 0).toLocaleString('es-MX', {maximumFractionDigits: 2})} ${metric.unit}` } } }, scales: { x: { grid: { display: false }, ticks: { color: '#64748b', font: { size: chartFontSize, weight: '700' } } }, y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b', font: { size: chartFontSize, weight: '700' }, callback: (value) => Number(value).toLocaleString('es-MX', { maximumFractionDigits: 2 }) } } } } });
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
