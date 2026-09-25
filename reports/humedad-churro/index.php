<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Cache-Control: no-cache, no-store, must-revalidate');

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$verdeMin = isset($report["semaforo"]["verde_min"]) ? number_format((float)$report["semaforo"]["verde_min"], 0) : "12";
$amarilloMin = isset($report["semaforo"]["amarillo_min"]) ? number_format((float)$report["semaforo"]["amarillo_min"], 0) : "10";
$chartPayload = [];
foreach ($report['tarjetas'] as $card) {
  $chartPayload[] = ['secador' => $card['secador'], 'serie' => $card['serie']];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= $e($report['titulo']) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body { font-family: Inter, Arial, sans-serif; color: #173044; background: #f0f4f8; }
    .humidity-report { width: min(1600px, 100%); margin: 0 auto; padding: 22px 26px 28px; }
    .report-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; margin-bottom: 18px; }
    .title-wrap { display: flex; align-items: center; gap: 14px; }
    .title-icon { width: 50px; height: 50px; display: grid; place-items: center; border-radius: 15px; color: #fff; background: #0f766e; font-size: 24px; }
    h1, h2, p { margin: 0; }
    h1 { color: #0f2b3d; font-size: clamp(29px, 2.7vw, 44px); line-height: 1; }
    .subtitle { margin-top: 5px; color: #64748b; font-size: 14px; }
    .back { padding: 9px 15px; border: 1px solid #d6e0e8; border-radius: 999px; color: #35556d; background: #fff; font-weight: 700; text-decoration: none; white-space: nowrap; }
    .filters { margin-bottom: 18px; padding: 12px 15px; border-radius: 16px; background: #fff; box-shadow: 0 5px 18px rgba(15,43,61,.07); }
    .filters form { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; }
    .field { display: grid; gap: 5px; }
    .field > span { color: #64748b; font-size: 11px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .period-tabs { display: flex; gap: 5px; padding: 4px; border-radius: 12px; background: #edf2f6; }
    .period-tabs input { position: absolute; opacity: 0; pointer-events: none; }
    .period-tabs span { display: block; padding: 8px 14px; border-radius: 9px; color: #516879; font-size: 13px; font-weight: 800; cursor: pointer; }
    .period-tabs input:checked + span { color: #fff; background: #0f766e; }
    .date-input { min-height: 40px; padding: 7px 11px; border: 1px solid #d6e0e8; border-radius: 10px; color: #173044; background: #fff; font: inherit; font-weight: 700; }
    .period-label { margin-left: auto; align-self: center; color: #0f766e; font-weight: 800; }
    .warning { margin-bottom: 15px; padding: 11px 15px; border-radius: 11px; color: #111827; background: #facc15; font-weight: 800; }
    .cards { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 15px; margin-bottom: 18px; }
    .dryer-card, .chart-card { min-width: 0; overflow: hidden; border-radius: 18px; background: #fff; box-shadow: 0 8px 22px rgba(15,43,61,.08); }
    .dryer-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 13px 16px; color: #fff; background: #64748b; }
    .dryer-card.verde .dryer-head { background: #2e8b57; }
    .dryer-card.amarillo .dryer-head { color: #111827; background: #facc15; }
    .dryer-card.rojo .dryer-head { background: #c94436; }
    .dryer-head h2 { font-size: clamp(19px, 1.45vw, 25px); }
    .status { padding: 4px 8px; border-radius: 999px; color: inherit; background: rgba(255,255,255,.25); font-size: 10px; font-weight: 800; }
    .dryer-body { min-height: 195px; display: grid; place-items: center; padding: 17px; text-align: center; }
    .metric-label { color: #64748b; font-size: 12px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
    .metric-value { display: block; margin: 8px 0; color: #0f2b3d; font-size: clamp(44px, 4.5vw, 70px); font-weight: 800; font-variant-numeric: tabular-nums; line-height: 1; white-space: nowrap; }
    .metric-value small { font-size: .31em; }
    .metric-meta { display: flex; justify-content: center; flex-wrap: wrap; gap: 8px 14px; color: #64748b; font-size: 11px; }
    .charts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
    .chart-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 13px 16px 0; }
    .chart-head h2 { color: #0f2b3d; font-size: 18px; }
    .chart-average { color: #64748b; font-size: 12px; font-weight: 700; }
    .latest-label { color: #0f2b3d; }
    .chart-wrap { position: relative; height: 245px; padding: 10px 13px 13px; }
    .empty-chart { position: absolute; inset: 10px 13px 13px; display: grid; place-items: center; border-radius: 12px; color: #64748b; background: #f7fafc; font-weight: 700; }
    .ranges { display: flex; justify-content: center; flex-wrap: wrap; gap: 15px; margin-top: 18px; padding: 12px; color: #516879; font-size: 12px; font-weight: 700; }
    .range { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
    .dot { width: 8px; height: 8px; border-radius: 50%; }
    .dot.verde { background: #2e8b57; } .dot.amarillo { background: #facc15; } .dot.rojo { background: #c94436; } .dot.gris { background: #64748b; }
    body.capture-mode .humidity-report { padding: 12px; }
    body.capture-mode .back, body.capture-mode .filters { display: none; }
    body.capture-mode .chart-wrap { height: 215px; }
    @media (max-width: 1050px) { .cards { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 700px) {
      .humidity-report { padding: 13px 9px; }
      .title-icon { display: none; }
      .report-header { align-items: flex-start; }
      .filters form { align-items: stretch; }
      .period-label { width: 100%; margin-left: 0; }
      .cards, .charts { grid-template-columns: 1fr; }
      .dryer-body { min-height: 175px; }
      .chart-wrap { height: 230px; }
    }
  </style>
  <link rel="stylesheet" href="votators-theme.css?v=<?= urlencode((string)(@filemtime(__DIR__ . '/votators-theme.css') ?: time())) ?>">
</head>
<body class="<?= $captureMode ? 'capture-mode' : '' ?>">
  <main class="humidity-report">
    <header class="report-header">
      <div class="title-wrap"><span class="title-icon"><i class="fa-solid fa-droplet"></i></span><div><h1><?= $e($report['titulo']) ?></h1><p class="subtitle"><?= $e($report['subtitulo']) ?> · actualización cada 2 min</p></div></div>
      <a class="back" href="../"><i class="fa-solid fa-arrow-left"></i> Regresar</a>
    </header>

    <section class="filters">
      <form id="humidityFilters" method="get">
        <label class="field"><span>Periodo</span><span class="period-tabs">
          <label><input type="radio" name="periodo" value="dia" <?= $report['periodo'] === 'dia' ? 'checked' : '' ?>><span>Día</span></label>
          <label><input type="radio" name="periodo" value="semana" <?= $report['periodo'] === 'semana' ? 'checked' : '' ?>><span>Semana</span></label>
          <label><input type="radio" name="periodo" value="mes" <?= $report['periodo'] === 'mes' ? 'checked' : '' ?>><span>Mes</span></label>
        </span></label>
        <label class="field" data-period="dia"><span>Fecha</span><input class="date-input" type="date" name="fecha" value="<?= $e($report['fecha_seleccionada']) ?>"></label>
        <label class="field" data-period="semana"><span>Semana</span><input class="date-input" type="week" name="semana" value="<?= $e($report['semana_seleccionada']) ?>"></label>
        <label class="field" data-period="mes"><span>Mes</span><input class="date-input" type="month" name="mes" value="<?= $e($report['mes_seleccionado']) ?>"></label>
        <strong class="period-label"><?= $e($report['periodo_label']) ?></strong>
      </form>
    </section>

    <?php if ($report['warning'] !== ''): ?><div class="warning"><?= $e($report['warning']) ?></div><?php endif; ?>
    <section class="cards">
      <?php foreach ($report['tarjetas'] as $card): ?>
        <article class="dryer-card <?= $e($card['estado_key']) ?>">
          <header class="dryer-head"><h2>Secador <?= (int)$card['secador'] ?></h2><span class="status"><?= $e($card['estado_label']) ?></span></header>
          <div class="dryer-body"><div><span class="metric-label">Humedad Churro</span><strong class="metric-value"><?= $e($card['valor_formateado']) ?><?php if (!$card['fuera_operacion'] && $card['valor'] !== null): ?> <small>%</small><?php endif; ?></strong><div class="metric-meta"><span><i class="fa-regular fa-clock"></i> <?= $e($card['fecha']) ?></span><span><?= (int)$card['lecturas'] ?> lecturas válidas</span></div></div></div>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="charts">
      <?php foreach ($report['tarjetas'] as $index => $card): ?>
        <article class="chart-card"><header class="chart-head"><h2>Secador <?= (int)$card['secador'] ?></h2><span class="chart-average"><span class="latest-label">● Última captura</span> · Promedio: <?= $card['promedio'] !== null ? number_format((float)$card['promedio'], 2, '.', ',') . '%' : '—' ?></span></header><div class="chart-wrap"><canvas id="humidityChart<?= (int)$card['secador'] ?>"></canvas><?php if ($card['serie'] === []): ?><div class="empty-chart">Sin lecturas válidas en el periodo</div><?php endif; ?></div></article>
      <?php endforeach; ?>
    </section>

    <footer class="ranges"><span class="range"><i class="dot verde"></i>Verde ≥ <?= $e($verdeMin) ?></span><span class="range"><i class="dot amarillo"></i>Amarillo <?= $e($amarilloMin) ?>–&lt;<?= $e($verdeMin) ?></span><span class="range"><i class="dot rojo"></i>Rojo &lt; <?= $e($amarilloMin) ?></span><span class="range"><i class="dot gris"></i>Fuera de operación o sin dato</span></footer>
  </main>
  <script>
    (() => {
      const activePeriod = <?= json_encode($report['periodo']) ?>;
      const form = document.getElementById('humidityFilters');
      const syncFields = () => document.querySelectorAll('[data-period]').forEach((field) => {
        const active = field.dataset.period === activePeriod;
        field.style.display = active ? 'grid' : 'none';
        const input = field.querySelector('input');
        if (input) input.disabled = !active;
      });
      document.querySelectorAll('input[name="periodo"]').forEach((input) => input.addEventListener('change', () => form.submit()));
      document.querySelectorAll('[data-period] input').forEach((input) => input.addEventListener('change', () => form.submit()));
      syncFields();

      const charts = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      if (window.Chart) charts.forEach((item) => {
        if (!item.serie.length) return;
        const canvas = document.getElementById(`humidityChart${item.secador}`);
        const lastIndex = item.serie.length - 1;
        new Chart(canvas, {
          type: 'line',
          data: { labels: item.serie.map((point) => point.label), datasets: [{ data: item.serie.map((point) => point.valor), borderColor: "#0f766e", backgroundColor: "rgba(15,118,110,.12)", pointBackgroundColor: item.serie.map((point) => point.valor >= <?= (float)$verdeMin ?> ? "#2e8b57" : (point.valor >= <?= (float)$amarilloMin ?> ? "#facc15" : "#c94436")), pointBorderColor: item.serie.map((point, index) => index === lastIndex ? "#0f2b3d" : "#fff"), pointBorderWidth: item.serie.map((point, index) => index === lastIndex ? 3 : 1.5), pointRadius: item.serie.map((point, index) => index === lastIndex ? 7 : 3.5), pointHoverRadius: item.serie.map((point, index) => index === lastIndex ? 8 : 5), fill: true, tension: .28 }] },
          options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (context) => `${context.dataIndex === lastIndex ? "Última captura: " : ""}${Number(context.raw).toFixed(2)} %` } } }, scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 10, color: '#cbd8e2' } }, y: { suggestedMin: 8, suggestedMax: 16, ticks: { callback: (value) => `${value}%`, color: '#cbd8e2' }, grid: { color: 'rgba(203,216,226,.14)' } } } }
        });
      });
      window.setTimeout(() => window.location.reload(), <?= (int)$report['intervalo_actualizacion_ms'] ?>);
    })();
  </script>
</body>
</html>
