<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
extract($report, EXTR_SKIP);
$captureMode = isset($_GET['capture']);
$view = strtolower(trim((string)($_GET['vista'] ?? 'hora')));
$view = in_array($view, ['hora', 'tarimas', 'turno-anterior'], true) ? $view : 'hora';

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$formatValue = static function (array $metric): string {
  $value = $metric['value'] ?? null;
  if (!is_numeric($value)) {
    return '—';
  }
  $decimals = isset($metric['decimals'])
    ? max(0, min(2, (int)$metric['decimals']))
    : (floor((float)$value) === (float)$value ? 0 : 2);
  return number_format((float)$value, $decimals, '.', ',');
};
$tarimaSummaryStatus = static function ($value, int $turns = 1): string {
  if (!is_numeric($value)) {
    return 'neutral';
  }
  $number = (float)$value;
  if ($turns === 2) {
    if ($number >= 24) {
      return 'verde';
    }
    if ($number >= 21) {
      return 'amarillo';
    }
    return 'rojo';
  }
  if ($number >= 12 * $turns) {
    return 'verde';
  }
  if ($number >= 11 * $turns) {
    return 'amarillo';
  }
  return 'rojo';
};
$formatRangeNumber = static function ($value): string {
  if (!is_numeric($value)) return '—';
  return floor((float)$value) === (float)$value
    ? number_format((float)$value, 0, '.', ',')
    : rtrim(rtrim(number_format((float)$value, 2, '.', ','), '0'), '.');
};
$metricRangeRows = static function (array $metric) use ($formatRangeNumber): array {
  $rule = (array)($metric['semaforo'] ?? []);
  if (($rule['modo'] ?? '') === 'bandas') {
    $rangesByStatus = ['verde' => [], 'amarillo' => [], 'rojo' => []];
    foreach ((array)($rule['bandas'] ?? []) as $band) {
      $status = (string)($band['estado'] ?? '');
      if (!in_array($status, ['verde', 'amarillo', 'rojo'], true)) continue;
      $legend = trim((string)($band['leyenda'] ?? ''));
      if ($legend !== '') {
        $rangesByStatus[$status][] = $legend;
      }
    }
    $rows = [];
    foreach (['verde', 'amarillo', 'rojo'] as $status) {
      if ($rangesByStatus[$status] === []) continue;
      $rows[] = [
        $status,
        $status === 'verde' ? 'Verde (Objetivo)' : ucfirst($status),
        implode("\n", $rangesByStatus[$status]),
      ];
    }
    return $rows;
  }
  if (($rule['modo'] ?? '') === 'rango') {
    $greenMin = $rule['verde_min'] ?? null;
    $greenMax = $rule['verde_max'] ?? null;
    $yellowMin = $rule['amarillo_min'] ?? null;
    $yellowMax = $rule['amarillo_max'] ?? null;
    if (!is_numeric($greenMin) || !is_numeric($greenMax) || !is_numeric($yellowMin) || !is_numeric($yellowMax)) return [];
    return [
      ['verde', 'Verde (Objetivo)', $formatRangeNumber($greenMin) . '–' . $formatRangeNumber($greenMax)],
      ['amarillo', 'Amarillo', $formatRangeNumber($yellowMin) . '–<' . $formatRangeNumber($greenMin) . ' / >' . $formatRangeNumber($greenMax) . '–' . $formatRangeNumber($yellowMax)],
      ['rojo', 'Rojo', '<' . $formatRangeNumber($yellowMin) . ' / >' . $formatRangeNumber($yellowMax)],
    ];
  }
  $yellow = $metric['amarillo_min'] ?? null;
  $green = $metric['verde_min'] ?? null;
  if (!is_numeric($yellow) || !is_numeric($green)) return [];
  $yellowRange = (float)$green - (float)$yellow === 1.0
    ? $formatRangeNumber($yellow)
    : $formatRangeNumber($yellow) . '–' . $formatRangeNumber((float)$green - 1);
  return [
    ['verde', 'Verde (Objetivo)', '≥ ' . $formatRangeNumber($green)],
    ['amarillo', 'Amarillo', $yellowRange],
    ['rojo', 'Rojo', '< ' . $formatRangeNumber($yellow)],
  ];
};
$renderCard = static function (array $metric) use ($e, $formatValue, $metricRangeRows): string {
  $status = in_array($metric['status'] ?? '', ['verde', 'amarillo', 'rojo'], true)
    ? (string)$metric['status']
    : 'neutral';
  $unit = trim((string)($metric['unit'] ?? ''));
  $source = (string)($metric['source'] ?? '');
  $sourceConfig = [
    'sqlserver' => ['icon' => 'fa-gear', 'title' => 'SQL Server / AVEVA'],
    'mysql_105' => ['icon' => 'fa-book-open', 'title' => 'MySQL 105'],
    'mixed' => ['icon' => 'fa-code-branch', 'title' => 'AVEVA + MySQL 105'],
  ];
  $sourceHtml = isset($sourceConfig[$source])
    ? '<span class="hour-source-icon" title="' . $e($sourceConfig[$source]['title']) . '"><i class="fa-solid ' . $e($sourceConfig[$source]['icon']) . '"></i></span>'
    : '';
  $formattedValue = $formatValue($metric);
  $integerDigits = is_numeric($metric['value'] ?? null)
    ? strlen((string)(int)floor(abs((float)$metric['value'])))
    : 0;
  $valueSizeClass = $integerDigits >= 6
    ? 'value-xwide'
    : ($integerDigits >= 4 ? 'value-wide' : '');
  $cardValueClass = $valueSizeClass !== '' ? ' has-' . $valueSizeClass : '';
  $rangeRows = $metricRangeRows($metric);
  $rangesHtml = $rangeRows !== []
    ? '<div class="hour-card-ranges">' . implode('', array_map(
      static fn(array $row): string => '<div class="hour-range-row"><span class="hour-range-dot is-' . $e($row[0]) . '"></span><span>' . $e($row[1]) . '</span><strong>' . $e($row[2]) . '</strong></div>',
      $rangeRows
    )) . '</div>'
    : '';
  return sprintf(
    '<article class="hour-card status-%s%s%s"><div class="hour-card-main"><div class="hour-card-label"><span>%s</span>%s</div><div class="hour-card-value"><strong class="%s">%s</strong>%s</div></div>%s</article>',
    $e($status),
    $rangeRows !== [] ? ' has-ranges' : '',
    $e($cardValueClass),
    $e($metric['label'] ?? 'Parámetro'),
    $sourceHtml,
    $e($valueSizeClass),
    $e($formattedValue),
    $unit !== '' ? '<small>' . $e($unit) . '</small>' : '',
    $rangesHtml
  );
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($titulo) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #eef4fb; color: #0f172a; font-family: Inter, system-ui, sans-serif; }
    .hour-report { width: min(980px, calc(100% - 24px)); margin: 12px auto; }
    .hour-header { margin-bottom: 9px; }
    .hour-header-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .hour-view-nav { display: flex; gap: 7px; }
    .hour-view-nav a { padding: 7px 12px; border: 1px solid #cbd5e1; border-radius: 999px; color: #334155; background: #fff; font-size: 13px; font-weight: 900; text-decoration: none; }
    .hour-view-nav a.is-active { color: #fff; background: #2563eb; border-color: #2563eb; }
    .hour-header h1 { margin: 0; font-size: clamp(24px, 4vw, 38px); font-weight: 900; }
    .hour-updated { margin-top: 4px; color: #64748b; font-size: 13px; font-weight: 700; }
    .supervisor-card { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; align-items: center; padding: 12px 16px; border: 2px solid #94a3b8; border-radius: 14px; background: #fff; }
    .supervisor-label { color: #475569; font-size: 15px; font-weight: 900; text-transform: uppercase; }
    .supervisor-name { font-size: clamp(25px, 5vw, 42px); font-weight: 900; }
    .turno-badge { grid-column: 1 / -1; display: flex; justify-content: space-between; gap: 12px; padding-top: 7px; border-top: 1px solid #dbe3ee; font-size: 17px; font-weight: 900; }
    .hour-section { margin-top: 10px; }
    .hour-section h2 { margin: 0 0 4px; color: #334155; font-size: 19px; font-weight: 900; text-transform: uppercase; }
    .frozen-shift { padding: 7px; border: 2px solid #94a3b8; border-radius: 12px; background: #e8eef6; }
    .frozen-shift h2 { display: flex; align-items: center; justify-content: space-between; }
    .frozen-shift h2 small { color: #64748b; font-size: 11px; }
    .hour-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 7px; }
    .hour-grid.flows { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .hour-card { position: relative; min-height: 112px; padding: 10px; overflow: hidden; border: 2px solid transparent; border-radius: 14px; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .hour-card-main { display: flex; min-width: 0; flex-direction: column; align-items: center; justify-content: center; }
    .hour-card.has-ranges { display: grid; grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr); min-height: 150px; padding: 0; color: #111827; background: #fff !important; align-items: stretch; }
    .hour-card.has-ranges .hour-card-main { padding: 10px; color: #fff; }
    .hour-card.has-ranges .hour-card-label { max-width: 100%; font-size: clamp(12px, 1.4vw, 14px); line-height: 1.08; }
    .hour-card.has-ranges .hour-card-value strong { font-size: clamp(31px, 4vw, 43px); }
    .hour-card.has-ranges.status-verde .hour-card-main { background: #2e8b57; }
    .hour-card.has-ranges.status-amarillo .hour-card-main { color: #111827; background: #facc15; }
    .hour-card.has-ranges.status-rojo .hour-card-main { background: #c94436; }
    .hour-card.has-ranges.status-neutral .hour-card-main { background: #64748b; }
    .hour-grid.flows .hour-card.has-ranges { grid-template-columns: minmax(78px, .58fr) minmax(0, 1.42fr); }
    .hour-grid.flows .hour-card.has-ranges .hour-card-main { padding: 6px 4px; }
    .hour-grid.flows .hour-card.has-ranges .hour-card-label { font-size: 13px; }
    .hour-card.status-verde { background: #2e8b57; border-color: #257447; }
    .hour-card.status-amarillo { background: #facc15; border-color: #d6ad05; color: #111827; }
    .hour-card.status-rojo { background: #c94436; border-color: #a9362c; }
    .hour-card.status-neutral { background: #64748b; border-color: #475569; }
    .hour-card-label { display: inline-flex; align-items: center; justify-content: center; gap: 5px; font-size: clamp(18px, 3vw, 27px); font-weight: 900; text-transform: uppercase; }
    .hour-source-icon { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 13px; height: 13px; border-radius: 999px; color: #475569; background: #e2e8f0; font-size: 7px; }
    .hour-card-value { display: flex; max-width: 100%; min-width: 0; align-items: baseline; justify-content: center; gap: 3px; margin-top: 4px; white-space: nowrap; }
    .hour-card-value strong { max-width: 100%; font-size: clamp(31px, 5vw, 48px); font-variant-numeric: tabular-nums; line-height: 1; }
    .hour-card-value small { font-size: clamp(14px, 2vw, 22px); font-weight: 900; }
    .hour-card-value strong.value-wide { font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; }
    .hour-card-value strong.value-xwide { font-size: clamp(19px, 2.8vw, 29px); letter-spacing: -.035em; }
    .hour-card.has-ranges .hour-card-value strong.value-wide { font-size: clamp(23px, 3vw, 32px); }
    .hour-card.has-ranges .hour-card-value strong.value-xwide { font-size: clamp(18px, 2.4vw, 26px); }
    .hour-card.has-value-wide .hour-card-value small,
    .hour-card.has-value-xwide .hour-card-value small { font-size: clamp(10px, 1.3vw, 15px); }
    .hour-grid.flows .hour-card-value strong { font-size: clamp(27px, 4vw, 39px); }
    .hour-grid.flows .hour-card-value small { font-size: clamp(12px, 1.5vw, 17px); }
    .hour-grid.flows .hour-card.has-ranges .hour-card-value { gap: 2px; }
    .hour-grid.flows .hour-card.has-ranges .hour-card-value strong { font-size: 25px; }
    .hour-grid.flows .hour-card-value strong.value-wide { font-size: 22px; }
    .hour-grid.flows .hour-card-value strong.value-xwide { font-size: 18px; }
    .hour-grid.flows .hour-card.has-ranges .hour-card-value strong.value-wide { font-size: 21px; }
    .hour-grid.flows .hour-card.has-ranges .hour-card-value strong.value-xwide { font-size: 17px; }
    .hour-grid.flows .hour-card.has-ranges .hour-card-value small { font-size: 9px; }
    .hour-grid.flows .hour-card-ranges { padding: 6px 4px; gap: 5px; }
    .hour-grid.flows .hour-range-row { grid-template-columns: 7px minmax(42px, auto) minmax(0, 1fr); gap: 3px; font-size: 8px; }
    .hour-grid.flows .hour-range-row strong { font-size: 8px; }
    .hour-grid.flows .hour-range-dot { width: 7px; height: 7px; }
    .hour-card-status { margin-top: 6px; font-size: 12px; font-weight: 900; text-transform: uppercase; }
    .hour-card-ranges { width: 100%; margin: 0; padding: 10px; color: #111827; background: #fff; display: grid; align-content: center; gap: 7px; }
    .hour-range-row { display: grid; grid-template-columns: 9px minmax(78px, auto) minmax(0, 1fr); gap: 6px; align-items: center; font-size: 10px; font-weight: 800; text-align: left; }
    .hour-range-row strong { color: #111827; font-size: 10px; line-height: 1.15; text-align: right; white-space: pre-line; }
    .hour-range-dot { width: 9px; height: 9px; border: 1px solid rgba(255,255,255,.7); border-radius: 50%; }
    .hour-range-dot.is-verde { background: #22c55e; }
    .hour-range-dot.is-amarillo { background: #facc15; }
    .hour-range-dot.is-rojo { background: #ef4444; }
    .shift-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-top: 12px; }
    .shift-summary.is-single { grid-template-columns: minmax(320px, 520px); justify-content: center; }
    .shift-card { min-height: 210px; padding: 20px; border: 3px solid transparent; border-radius: 18px; color: #fff; background: #64748b; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .shift-card.status-verde { background: #2e8b57; border-color: #257447; }
    .shift-card.status-amarillo { color: #111827; background: #facc15; border-color: #d6ad05; }
    .shift-card.status-rojo { background: #c94436; border-color: #a9362c; }
    .shift-card.status-neutral { background: #64748b; border-color: #475569; }
    .shift-card-label { font-size: clamp(21px, 3vw, 30px); font-weight: 900; text-transform: uppercase; }
    .shift-card-value { margin-top: 12px; font-size: clamp(58px, 9vw, 92px); font-weight: 900; line-height: .9; }
    .shift-card-unit { margin-top: 10px; font-size: 17px; font-weight: 900; text-transform: uppercase; }
    .shift-card-range { margin-top: 12px; font-size: 13px; font-weight: 900; line-height: 1.45; }
    .shift-period { margin-top: 12px; color: #475569; font-size: 14px; font-weight: 800; text-align: center; }
    body.capture-mode { background: #fff; }
    body.capture-mode .hour-report { width: min(1100px, calc(100vw - 12px)); margin: 0 auto; padding: 4px 0; }
    body.capture-mode .hour-header { margin-bottom: 4px; }
    body.capture-mode .hour-header h1 { font-size: 25px; line-height: 1; }
    body.capture-mode .hour-updated { display: none; }
    body.capture-mode .hour-view-nav { display: none; }
    body.capture-mode .supervisor-card { gap: 3px 10px; padding: 7px 10px; border-radius: 9px; }
    body.capture-mode .supervisor-name { font-size: 25px; }
    body.capture-mode .supervisor-label { font-size: 12px; }
    body.capture-mode .turno-badge { padding-top: 4px; font-size: 14px; }
    body.capture-mode .hour-section { margin-top: 5px; }
    body.capture-mode .hour-section h2 { margin-bottom: 2px; font-size: 16px; }
    body.capture-mode .frozen-shift { padding: 4px; border-radius: 9px; }
    body.capture-mode .frozen-shift .hour-card.has-ranges { grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); }
    body.capture-mode .frozen-shift .hour-range-row { grid-template-columns: 8px minmax(70px, auto) minmax(0, 1fr); }
    body.capture-mode .frozen-shift .hour-range-row,
    body.capture-mode .frozen-shift .hour-range-row strong { font-size: 8.5px; }
    body.capture-mode .hour-grid { gap: 4px; }
    body.capture-mode .hour-card { min-height: 88px; padding: 6px; border-radius: 9px; }
    body.capture-mode .hour-card.has-ranges { min-height: 124px; grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr); }
    body.capture-mode .hour-card.has-ranges .hour-card-main { padding: 6px; }
    body.capture-mode .hour-card.has-ranges .hour-card-label { font-size: 12px; }
    body.capture-mode .hour-source-icon { width: 11px; height: 11px; font-size: 6px; }
    body.capture-mode .hour-card-label { font-size: 18px; }
    body.capture-mode .hour-card-value { margin-top: 2px; }
    body.capture-mode .hour-card-value strong { font-size: 30px; }
    body.capture-mode .hour-card-value small { font-size: 13px; }
    body.capture-mode .hour-card-value strong.value-wide { font-size: 23px; }
    body.capture-mode .hour-card-value strong.value-xwide { font-size: 18px; }
    body.capture-mode .hour-card.has-value-wide .hour-card-value small,
    body.capture-mode .hour-card.has-value-xwide .hour-card-value small { font-size: 10px; }
    body.capture-mode .hour-grid.flows .hour-card-value strong { font-size: 26px; }
    body.capture-mode .hour-grid.flows .hour-card-value strong.value-wide { font-size: 22px; }
    body.capture-mode .hour-grid.flows .hour-card-value strong.value-xwide { font-size: 18px; }
    body.capture-mode .hour-grid.flows .hour-card-value small { font-size: 11px; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges {
      min-height: 126px;
      grid-template-columns: minmax(0, 1fr);
      grid-template-rows: 43px minmax(0, 1fr);
    }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-main {
      min-width: 0;
      padding: 5px 8px;
      flex-direction: row;
      justify-content: space-between;
      gap: 5px;
    }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-label { font-size: 12px; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-value { margin-top: 0; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-value strong { font-size: 22px; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-value strong.value-wide { font-size: 19px; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-value strong.value-xwide { font-size: 16px; }
    body.capture-mode .hour-grid.flows .hour-card.has-ranges .hour-card-value small { font-size: 8px; }
    body.capture-mode .hour-grid.flows .hour-card-ranges { padding: 5px 7px; gap: 3px; }
    body.capture-mode .hour-grid.flows .hour-range-row {
      grid-template-columns: 6px minmax(54px, auto) minmax(0, 1fr);
      gap: 4px;
      font-size: 7.5px;
      line-height: 1.1;
    }
    body.capture-mode .hour-grid.flows .hour-range-row strong { font-size: 7.5px; line-height: 1.1; }
    body.capture-mode .hour-grid.flows .hour-range-dot { width: 6px; height: 6px; }
    body.capture-mode .hour-card-status { margin-top: 3px; font-size: 10px; }
    body.capture-mode .hour-card-ranges { padding: 6px 8px; gap: 5px; }
    body.capture-mode .hour-range-row { grid-template-columns: 8px minmax(78px, auto) minmax(0, 1fr); gap: 5px; }
    body.capture-mode .hour-range-row, body.capture-mode .hour-range-row strong { font-size: 9px; line-height: 1.15; }
    body.capture-mode .shift-summary { gap: 7px; margin-top: 7px; }
    body.capture-mode .shift-card { min-height: 170px; padding: 12px; border-radius: 12px; }
    body.capture-mode .shift-card-value { font-size: 68px; }
    @media (max-width: 900px) { .hour-grid.flows { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 680px) { .hour-grid, .hour-grid.flows, .shift-summary { grid-template-columns: 1fr; } .supervisor-card { grid-template-columns: 1fr; } .turno-badge { grid-column: 1; } .hour-header-row { align-items: flex-start; flex-direction: column; } }
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body class="<?= $captureMode ? 'capture-mode' : '' ?>">
  <main class="hour-report">
    <header class="hour-header">
      <div class="hour-header-row">
        <h1><?= $e($titulo) ?></h1>
        <nav class="hour-view-nav" aria-label="Vistas del reporte">
          <a href="?vista=hora" class="<?= $view === 'hora' ? 'is-active' : '' ?>">Hora por hora</a>
          <a href="?vista=tarimas" class="<?= $view === 'tarimas' ? 'is-active' : '' ?>">Tarimas por turno</a>
          <a href="?vista=turno-anterior" class="<?= $view === 'turno-anterior' ? 'is-active' : '' ?>">Turno anterior</a>
        </nav>
      </div>
      <div class="hour-updated">Actualizado <?= $e($actualizado) ?></div>
    </header>

    <?php if ($view === 'turno-anterior'): ?>
      <section class="shift-summary is-single" aria-label="Resumen del turno anterior">
        <?= $renderCard([
          'label' => 'Turno ' . ($resumen_turno_anterior['turno'] ?? '—'),
          'value' => $resumen_turno_anterior['tarimas'] ?? null,
          'unit' => 'tarimas',
          'source' => 'mysql_105',
          'status' => $tarimaSummaryStatus($resumen_turno_anterior['tarimas'] ?? null),
          'amarillo_min' => 11,
          'verde_min' => 12,
        ]) ?>
      </section>
      <div class="shift-period">
        Supervisor: <?= $e($resumen_turno_anterior['supervisor'] ?? 'Sin asignar') ?><br>
        Periodo: <?= $e($resumen_turno_anterior['inicio'] ?? '') ?> a <?= $e($resumen_turno_anterior['fin'] ?? '') ?>
      </div>
    <?php elseif ($view === 'tarimas'): ?>
      <section class="shift-summary" aria-label="Tarimas por turno">
        <?= $renderCard([
          'label' => 'Turno 1',
          'value' => $resumen_tarimas['turno_1'] ?? null,
          'unit' => 'tarimas',
          'source' => 'mysql_105',
          'status' => $tarimaSummaryStatus($resumen_tarimas['turno_1'] ?? null),
          'amarillo_min' => 11,
          'verde_min' => 12,
        ]) ?>
        <?= $renderCard([
          'label' => 'Turno 2',
          'value' => $resumen_tarimas['turno_2'] ?? null,
          'unit' => 'tarimas',
          'source' => 'mysql_105',
          'status' => $tarimaSummaryStatus($resumen_tarimas['turno_2'] ?? null),
          'amarillo_min' => 11,
          'verde_min' => 12,
        ]) ?>
        <?= $renderCard([
          'label' => 'Total',
          'value' => $resumen_tarimas['total'] ?? null,
          'unit' => 'tarimas',
          'source' => 'mysql_105',
          'status' => $tarimaSummaryStatus($resumen_tarimas['total'] ?? null, 2),
          'amarillo_min' => 21,
          'verde_min' => 24,
        ]) ?>
      </section>
      <div class="shift-period">Día operativo: <?= $e($resumen_tarimas['inicio'] ?? '') ?> a <?= $e($resumen_tarimas['fin'] ?? '') ?></div>
    <?php else: ?>
    <?php if ((int)$turno === 2): ?>
    <section class="hour-section frozen-shift" aria-label="Cierre congelado del turno anterior">
      <h2>
        <span>Cierre Turno <?= $e($resumen_turno_anterior['turno'] ?? 1) ?></span>
        <small><?= $e($resumen_turno_anterior['inicio'] ?? '') ?> a <?= $e($resumen_turno_anterior['fin'] ?? '') ?></small>
      </h2>
      <div class="hour-grid">
        <?= $renderCard([
          'label' => 'Kg / hr',
          'value' => $resumen_turno_anterior['kg_hora'] ?? null,
          'unit' => 'kg/hr',
          'source' => 'mysql_105',
          'semaforo' => (array)($config['metricas']['kg_hora']['semaforo'] ?? []),
          'status' => (function ($value): string {
            if (!is_numeric($value)) return 'neutral';
            if ((float)$value >= 1000) return 'verde';
            if ((float)$value >= 875) return 'amarillo';
            return 'rojo';
          })($resumen_turno_anterior['kg_hora'] ?? null),
        ]) ?>
        <?= $renderCard([
          'label' => 'Acumulado',
          'value' => $resumen_turno_anterior['acumulado'] ?? null,
          'unit' => 'kg',
          'source' => 'mysql_105',
          'status' => (function ($value): string {
            if (!is_numeric($value)) return 'neutral';
            if ((float)$value >= 12000) return 'verde';
            if ((float)$value >= 10500) return 'amarillo';
            return 'rojo';
          })($resumen_turno_anterior['acumulado'] ?? null),
          'semaforo' => [
            'modo' => 'bandas',
            'bandas' => [
              ['max' => 10499.999999, 'estado' => 'rojo', 'leyenda' => '< 10,500'],
              ['min' => 10500, 'max' => 11999.999999, 'estado' => 'amarillo', 'leyenda' => '10,500–11,999'],
              ['min' => 12000, 'estado' => 'verde', 'leyenda' => '≥ 12,000'],
            ],
          ],
        ]) ?>
        <?= $renderCard([
          'label' => 'Tarimas',
          'value' => $resumen_turno_anterior['tarimas'] ?? null,
          'unit' => '',
          'source' => 'mysql_105',
          'status' => $tarimaSummaryStatus($resumen_turno_anterior['tarimas'] ?? null),
          'amarillo_min' => 11,
          'verde_min' => 12,
        ]) ?>
      </div>
    </section>
    <?php endif; ?>
    <section class="supervisor-card">
      <div class="supervisor-label">Supervisor</div>
      <div class="supervisor-name"><?= $e($supervisor) ?></div>
      <div class="turno-badge"><span>Turno <?= $e($turno) ?></span><span><?= $e($turno_horario) ?></span></div>
    </section>

    <section class="hour-section">
      <h2>Sólidos</h2>
      <div class="hour-grid">
        <?= $renderCard((array)($metricas['solidos_clarificador'] ?? [])) ?>
        <?= $renderCard((array)($metricas['solidos_membranas'] ?? [])) ?>
        <?= $renderCard((array)($metricas['solidos_concentradores'] ?? [])) ?>
      </div>
    </section>

    <section class="hour-section">
      <h2>Flujos</h2>
      <div class="hour-grid flows">
        <?= $renderCard((array)($metricas['flujo_s1'] ?? [])) ?>
        <?= $renderCard((array)($metricas['flujo_s2'] ?? [])) ?>
        <?= $renderCard((array)($metricas['flujo_s3'] ?? [])) ?>
        <?= $renderCard((array)($metricas['flujo_s4'] ?? [])) ?>
        <?= $renderCard((array)($metricas['flujo_total'] ?? [])) ?>
      </div>
    </section>

    <section class="hour-section">
      <h2>Turno <?= $e($turno) ?></h2>
      <div class="hour-grid">
        <?= $renderCard((array)($metricas['kg_hora'] ?? [])) ?>
        <?= $renderCard((array)($metricas['acumulado'] ?? [])) ?>
        <?= $renderCard((array)($metricas['tarimas'] ?? [])) ?>
      </div>
    </section>
    <?php endif; ?>
  </main>
  <script>setTimeout(() => location.reload(), <?= (int)$intervalo_actualizacion_ms ?>);</script>
</body>
</html>
