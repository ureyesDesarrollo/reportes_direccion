<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

$titulo = (string)($report['titulo'] ?? 'Ventas');
$filtros = (array)($report['filtros'] ?? []);
$kpis = (array)($report['kpis'] ?? []);
$pedidos = (array)($kpis['pedidos'] ?? []);
$backorderVenta = (array)($kpis['backorder_venta'] ?? []);
$ventas = (array)($kpis['ventas'] ?? []);
$calidadPorProducir = (array)($kpis['calidad_por_producir'] ?? []);
$objetivos = (array)($report['objetivos'] ?? []);
$series = (array)($report['series'] ?? []);
$dailyRows = (array)($series['diaria'] ?? []);
$meta = (array)($report['meta'] ?? []);
$version = (int)($report['version'] ?? time());
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 1): string => is_numeric($value) ? n((float)$value, $decimals) : '-';
$pedidosToneladas = is_numeric($pedidos['toneladas'] ?? null) ? (float)$pedidos['toneladas'] : null;
$pedidosStatusClass = '';
if ($pedidosToneladas !== null) {
  if ($pedidosToneladas < 400) {
    $pedidosStatusClass = 'avance-semaforo-rojo';
  } elseif ($pedidosToneladas <= 450) {
    $pedidosStatusClass = 'avance-semaforo-amarillo';
  } else {
    $pedidosStatusClass = 'avance-semaforo-verde';
  }
}
$ventasToneladas = is_numeric($ventas['toneladas'] ?? null) ? (float)$ventas['toneladas'] : null;
$ventasStatusClass = '';
if ($ventasToneladas !== null) {
  if ($ventasToneladas < 600) {
    $ventasStatusClass = 'avance-semaforo-rojo';
  } elseif ($ventasToneladas <= 650) {
    $ventasStatusClass = 'avance-semaforo-amarillo';
  } else {
    $ventasStatusClass = 'avance-semaforo-verde';
  }
}
$meses = [
  1 => 'Enero',
  2 => 'Febrero',
  3 => 'Marzo',
  4 => 'Abril',
  5 => 'Mayo',
  6 => 'Junio',
  7 => 'Julio',
  8 => 'Agosto',
  9 => 'Septiembre',
  10 => 'Octubre',
  11 => 'Noviembre',
  12 => 'Diciembre',
];
$anioActual = (int)date('Y');
$chartLabels = array_map(static fn(array $row): string => (string)($row['day'] ?? ''), $dailyRows);
$chartTons = array_map(static fn(array $row): float => round((float)($row['toneladas'] ?? 0), 2), $dailyRows);
$objetivoDiario = (float)($objetivos['diario_toneladas'] ?? 0.0);
$chartTarget = array_fill(0, count($chartLabels), round($objetivoDiario, 2));
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/css/dashboard.css') ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      background: #f4f7fb;
    }

    .ventas-dashboard {
      max-width: 1600px;
      padding: 18px 28px 22px;
    }

    .ventas-header {
      margin-bottom: 12px;
    }

    .ventas-header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }

    .ventas-header h1 {
      font-size: clamp(1.85rem, 2.7vw, 2.45rem);
    }

    .ventas-filter-form {
      margin-bottom: 14px;
      border-radius: 20px;
      padding: 10px 16px;
    }

    .ventas-filter-field {
      display: grid;
      gap: 6px;
      min-width: 150px;
    }

    .ventas-filter-field label {
      color: #64748b;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .ventas-filter-field .filter-select {
      min-width: 150px;
      min-height: 40px;
      padding-top: 9px;
      padding-bottom: 9px;
    }

    .ventas-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(250px, 1fr));
      gap: 12px;
      margin-bottom: 14px;
    }

    .ventas-kpi-card {
      min-height: 112px;
      padding: 14px 16px;
    }

    .ventas-kpi-card .kpi-icon {
      margin-bottom: 7px;
      font-size: 1.18rem;
    }

    .ventas-kpi-card .kpi-value {
      margin-bottom: 0;
      font-size: clamp(1.7rem, 2.4vw, 2.25rem);
      line-height: 1.05;
      white-space: nowrap;
    }

    .ventas-kpi-card .kpi-label {
      margin-bottom: 4px;
    }

    .ventas-kpi-card.avance-semaforo-verde,
    .ventas-kpi-card.avance-semaforo-amarillo,
    .ventas-kpi-card.avance-semaforo-rojo {
      color: #ffffff;
    }

    .ventas-kpi-card.avance-semaforo-verde::before,
    .ventas-kpi-card.avance-semaforo-amarillo::before,
    .ventas-kpi-card.avance-semaforo-rojo::before {
      opacity: 0;
    }

    .ventas-kpi-card.avance-semaforo-verde .kpi-icon,
    .ventas-kpi-card.avance-semaforo-verde .kpi-label,
    .ventas-kpi-card.avance-semaforo-verde .kpi-value,
    .ventas-kpi-card.avance-semaforo-verde .kpi-trend,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-icon,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-label,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-value,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-trend,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-icon,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-label,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-value,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-trend {
      color: #ffffff !important;
    }

    .ventas-kpi-card.avance-semaforo-verde {
      background: #2e8b57;
      border-color: #257447;
    }

    .ventas-kpi-card.avance-semaforo-amarillo {
      background: #facc15;
      color: #111827;
      border-color: #eab308;
    }

    .ventas-kpi-card.avance-semaforo-rojo {
      background: #c94436;
      border-color: #a9362c;
    }

    .ventas-chart-container {
      margin-bottom: 14px;
      border-radius: 20px;
      padding: 16px;
    }

    .ventas-quality-section {
      margin-bottom: 14px;
      border-radius: 20px;
      padding: 16px;
    }

    .ventas-quality-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }

    .ventas-quality-header h3 {
      margin: 0;
      color: #0f172a;
      font-size: 1.15rem;
      font-weight: 800;
      letter-spacing: 0;
    }

    .ventas-quality-header span {
      color: #64748b;
      font-size: 0.82rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .ventas-quality-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 180px));
      gap: 10px;
      justify-content: start;
    }

    .ventas-quality-card {
      display: grid;
      gap: 16px;
      min-height: 90px;
      padding-right: 10px;
      padding-left: 10px;
      border: 1px solid #dbe7f5;
      border-radius: 14px;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
    }

    .ventas-quality-card[data-quality-card="315"] {
      color: #ffffff;
      background: #2563eb;
      border-color: #1d4ed8;
    }

    .ventas-quality-card[data-quality-card="300"] {
      color: #ffffff;
      background: #b8860b;
      border-color: #9a6b08;
    }

    .ventas-quality-card[data-quality-card="Dorada"] {
      color: #ffffff;
      background: #d4a017;
      border-color: #b8860b;
    }

    .ventas-quality-card[data-quality-card="280"] {
      color: #ffffff;
      background: #7f1d3a;
      border-color: #67162f;
    }

    .ventas-quality-card[data-quality-card="265"]{
      color: #ffffff;
      background: #2e8b57;
      border-color: #257447;
    }

    .ventas-quality-card[data-quality-card="250"] {
      color: #ffffff;
      background: #6f6565;
      border-color: #6f6565;
    }

    .ventas-quality-card[data-quality-card="230"] {
      color: #ffffff;
      background: #6d28d9;
      border-color: #5b21b6;
    }

    .ventas-quality-card[data-quality-card="Económica"] {
      color: #ffffff;
      background: #475569;
      border-color: #334155;
    }

    .ventas-quality-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .ventas-quality-key {
      color: #0f172a;
      font-size: 0.92rem;
      font-weight: 900;
      line-height: 1;
    }

    .ventas-quality-card[data-quality-card] .ventas-quality-key,
    .ventas-quality-card[data-quality-card] .ventas-quality-value,
    .ventas-quality-card[data-quality-card] .ventas-quality-meta {
      color: inherit;
    }

    .ventas-quality-value {
      color: #0f172a;
      font-size: clamp(1.35rem, 2vw, 1.9rem);
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
    }

    .ventas-quality-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      color: #64748b;
      font-size: 0.74rem;
      font-weight: 800;
    }

    .ventas-chart-box {
      height: 360px;
      min-height: 0;
    }

    .ventas-chart-box canvas {
      width: 100% !important;
      height: 100% !important;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border: 1px solid #dbe7f5;
      border-radius: 999px;
      color: #334155;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
      font-weight: 700;
      text-decoration: none;
      transition: all 0.2s;
    }

    .back-btn:hover {
      background: #eff6ff;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
      transform: translateX(-2px);
    }

    .ventas-warning {
      margin-bottom: 14px;
      padding: 10px 12px;
      border: 1px solid #f59e0b;
      border-radius: 8px;
      color: #78350f;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 800;
    }
  </style>
</head>

<body>
  <main class="dashboard ventas-dashboard">
    <header class="ventas-header">
      <div class="ventas-header-actions">
        <a class="back-btn" href="../index.php" data-smart-back="reports-index">
          <i class="fas fa-arrow-left"></i>
          Regresar al inicio
        </a>
      </div>
      <h1><?= $e($titulo) ?></h1>
      <p><?= $e((string)($filtros['desde'] ?? '')) ?> al <?= $e((string)($filtros['hasta'] ?? '')) ?>
    </header>

    <form class="filters ventas-filter-form" method="get" id="ventasFilters">
      <div class="ventas-filter-field">
        <label>Año</label>
          <select class="filter-select" name="anio">
            <?php for ($year = $anioActual + 1; $year >= $anioActual - 4; $year--): ?>
              <option value="<?= $e($year) ?>" <?= (int)($filtros['anio'] ?? 0) === $year ? 'selected' : '' ?>><?= $e($year) ?></option>
            <?php endfor; ?>
          </select>
      </div>
      <div class="ventas-filter-field">
        <label>Mes</label>
          <select class="filter-select" name="mes">
            <?php foreach ($meses as $monthNumber => $monthName): ?>
              <option value="<?= $e($monthNumber) ?>" <?= (int)($filtros['mes'] ?? 0) === $monthNumber ? 'selected' : '' ?>><?= $e($monthName) ?></option>
            <?php endforeach; ?>
          </select>
      </div>
    </form>

    <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
      <div class="ventas-warning"><?= $e($warning) ?></div>
    <?php endforeach; ?>

    <section class="kpi-grid ventas-summary-grid" aria-label="Indicadores de ventas">
      <article class="kpi-card ventas-kpi-card <?= $e($pedidosStatusClass) ?>" id="ventasKpiPedidosCard">
        <div class="kpi-icon" style="color:#0ea5e9;"><i class="fa-solid fa-truck-ramp-box"></i></div>
        <div class="kpi-label">Back Order</div>
        <div class="kpi-value" id="ventasKpiPedidos"><?= $e($fmt($pedidos['toneladas'] ?? null, 1)) ?> TON</div>
        <div class="kpi-trend" id="ventasKpiPedidosDetalle">
          <?= $e($fmt($pedidos['cantidad'] ?? null, 0)) ?> pedidos · saldo_total
        </div>
      </article>

      <article class="kpi-card ventas-kpi-card <?= $e($ventasStatusClass) ?>" id="ventasKpiCard">
        <div class="kpi-icon"><i class="fa-solid fa-scale-balanced"></i></div>
        <div class="kpi-label">Ventas</div>
        <div class="kpi-value" id="ventasKpiToneladas"><?= $e($fmt($ventas['toneladas'] ?? null, 1)) ?> TON</div>
        <div class="kpi-trend" id="ventasKpiDetalle">
          Facturas: <?= $e($fmt($ventas['facturas_toneladas'] ?? null, 1)) ?> TON · Remisiones: <?= $e($fmt($ventas['remisiones_toneladas'] ?? null, 1)) ?> TON
        </div>
      </article>

      <article class="kpi-card ventas-kpi-card" id="ventasKpiBackorderVentaCard">
        <div class="kpi-icon" style="color:#6366f1;"><i class="fa-solid fa-layer-group"></i></div>
        <div class="kpi-label">Back order + venta</div>
        <div class="kpi-value" id="ventasKpiBackorderVenta"><?= $e($fmt($backorderVenta['toneladas'] ?? null, 1)) ?> TON</div>
        <div class="kpi-trend">Pedidos pendientes más venta del periodo</div>
      </article>
    </section>

    <section class="chart-container ventas-chart-container">
      <div class="chart-header">
        <h3>Toneladas vendidas por día</h3>
        <div class="legend">
          <span class="legend-item"><i class="legend-color" style="background:#2563eb;"></i> Ventas</span>
          <span class="legend-item"><i class="legend-color" style="background:#0f172a;"></i> Meta diaria</span>
        </div>
      </div>
      <div class="ventas-chart-box">
        <canvas id="ventasDailyChart"></canvas>
      </div>
    </section>

    <section class="chart-container ventas-quality-section" aria-label="Calidad por producir">
      <div class="ventas-quality-header">
        <h3>Calidad por producir</h3>
        <span>Agrupado por Bloom</span>
      </div>
      <div class="ventas-quality-grid" id="ventasQualityGrid">
        <?php foreach ($calidadPorProducir as $producto): ?>
          <article class="ventas-quality-card" data-quality-card="<?= $e($producto['grupo'] ?? '') ?>">
            <div class="ventas-quality-top">
              <div class="ventas-quality-key"><?= is_numeric($producto['grupo'] ?? null) ? 'Bloom ' : '' ?><?= $e($producto['grupo'] ?? '-') ?></div>
            </div>
            <div class="ventas-quality-value" data-quality-value><?= $e($fmt($producto['toneladas'] ?? null, 1)) ?> TON</div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
  <script>
    const ventasRefreshMs = <?= (int)($meta['intervaloActualizacion'] ?? 600000) ?>;
    const ventasSemaforoClasses = ['avance-semaforo-verde', 'avance-semaforo-amarillo', 'avance-semaforo-rojo'];
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = document.documentElement.classList.contains('executive-display') ? 12 : 11;
    Chart.defaults.color = '#475569';

    function ventasFormatNumber(value, decimals = 1) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '-';
      return number.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char]));
    }

    function ventasSemaforo(value) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '';
      if (number < 600) return 'avance-semaforo-rojo';
      if (number <= 650) return 'avance-semaforo-amarillo';
      return 'avance-semaforo-verde';
    }

    function pedidosSemaforo(value) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '';
      if (number < 400) return 'avance-semaforo-rojo';
      if (number <= 450) return 'avance-semaforo-amarillo';
      return 'avance-semaforo-verde';
    }

    function renderCalidadPorProducir(rows) {
      const grid = document.getElementById('ventasQualityGrid');
      if (!grid) return;

      grid.innerHTML = (rows || []).map((row) => `
        <article class="ventas-quality-card" data-quality-card="${escapeHtml(row.grupo || '')}">
          <div class="ventas-quality-top">
            <div class="ventas-quality-key">${Number.isFinite(Number(row.grupo)) ? 'Bloom ' : ''}${escapeHtml(row.grupo || '-')}</div>
          </div>
          <div class="ventas-quality-value" data-quality-value>${ventasFormatNumber(row.toneladas, 1)} TON</div>
        </article>
      `).join('');
    }

    const ventasValueLabelPlugin = {
      id: 'ventasValueLabels',
      afterDatasetsDraw(chart) {
        const { ctx } = chart;
        ctx.save();
        ctx.font = '10px Inter, sans-serif';
        ctx.fillStyle = '#475569';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'bottom';
        chart.data.datasets.forEach((dataset, datasetIndex) => {
          if (dataset.type === 'line' || dataset.showLabels === false) return;
          const meta = chart.getDatasetMeta(datasetIndex);
          meta.data.forEach((bar, index) => {
            const value = Number(dataset.data[index] || 0);
            if (value <= 0) return;
            ctx.fillText(value.toFixed(1), bar.x, bar.y - 5);
          });
        });
        ctx.restore();
      }
    };

    let ventasDailyChart = null;
    const ventasDailyCanvas = document.getElementById('ventasDailyChart');
    if (ventasDailyCanvas) {
      ventasDailyChart = new Chart(ventasDailyCanvas, {
        data: {
          labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
          datasets: [{
            type: 'bar',
            label: 'Ventas',
            data: <?= json_encode($chartTons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            backgroundColor: '#2563eb',
            borderColor: '#2563eb',
            borderWidth: 0,
            borderRadius: 8,
            maxBarThickness: 34,
            yAxisID: 'y',
          }, {
            type: 'line',
            label: 'Meta diaria',
            data: <?= json_encode($chartTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            borderColor: '#0f172a',
            backgroundColor: '#0f172a',
            pointRadius: 0,
            borderWidth: 3,
            tension: 0,
            yAxisID: 'y',
            showLabels: false,
          }]
        },
        options: {
          animation: false,
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { mode: 'index', intersect: false },
          },
          scales: {
            x: {
              title: { display: true, text: 'Día', color: '#1f2937', font: { weight: '700' } },
              grid: { display: false },
            },
            y: {
              title: { display: true, text: 'Toneladas', color: '#1f2937', font: { weight: '700' } },
              beginAtZero: true,
              suggestedMax: <?= json_encode(max($objetivoDiario + 5, max($chartTons ?: [0]) + 4)) ?>,
              grid: { color: 'rgba(148, 163, 184, 0.24)' },
            }
          }
        },
        plugins: [ventasValueLabelPlugin]
      });
    }

    function updateVentasChart(report) {
      if (!ventasDailyChart) return;
      const rows = report?.series?.diaria || [];
      const labels = rows.map((row) => String(row.day ?? ''));
      const toneladas = rows.map((row) => Number(row.toneladas || 0));
      const objetivoDiario = Number(report?.objetivos?.diario_toneladas || 0);

      ventasDailyChart.data.labels = labels;
      ventasDailyChart.data.datasets[0].data = toneladas;
      ventasDailyChart.data.datasets[1].data = labels.map(() => objetivoDiario);
      ventasDailyChart.options.scales.y.suggestedMax = Math.max(objetivoDiario + 5, Math.max(0, ...toneladas) + 4);
      ventasDailyChart.update('none');
    }

    function updateVentasReport(report) {
      const ventas = report?.kpis?.ventas || {};
      const pedidos = report?.kpis?.pedidos || {};
      const backorderVenta = report?.kpis?.backorder_venta || {};
      const calidadPorProducir = report?.kpis?.calidad_por_producir || [];
      const card = document.getElementById('ventasKpiCard');
      const toneladas = document.getElementById('ventasKpiToneladas');
      const detalle = document.getElementById('ventasKpiDetalle');
      const pedidosCard = document.getElementById('ventasKpiPedidosCard');
      const pedidosValue = document.getElementById('ventasKpiPedidos');
      const pedidosDetalle = document.getElementById('ventasKpiPedidosDetalle');
      const backorderVentaValue = document.getElementById('ventasKpiBackorderVenta');

      if (pedidosValue) {
        pedidosValue.textContent = `${ventasFormatNumber(pedidos.toneladas, 1)} TON`;
      }

      if (pedidosDetalle) {
        pedidosDetalle.textContent = `${ventasFormatNumber(pedidos.cantidad, 0)} pedidos · saldo_total`;
      }

      if (backorderVentaValue) {
        backorderVentaValue.textContent = `${ventasFormatNumber(backorderVenta.toneladas, 1)} TON`;
      }

      if (pedidosCard) {
        pedidosCard.classList.remove(...ventasSemaforoClasses);
        const statusClass = pedidosSemaforo(pedidos.toneladas);
        if (statusClass !== '') {
          pedidosCard.classList.add(statusClass);
        }
      }

      if (toneladas) {
        toneladas.textContent = `${ventasFormatNumber(ventas.toneladas, 1)} TON`;
      }

      if (detalle) {
        detalle.textContent = `Facturas: ${ventasFormatNumber(ventas.facturas_toneladas, 1)} TON · Remisiones: ${ventasFormatNumber(ventas.remisiones_toneladas, 1)} TON`;
      }

      if (card) {
        card.classList.remove(...ventasSemaforoClasses);
        const statusClass = ventasSemaforo(ventas.toneladas);
        if (statusClass !== '') {
          card.classList.add(statusClass);
        }
      }

      renderCalidadPorProducir(calidadPorProducir);
      updateVentasChart(report);
    }

    async function refreshVentasReport() {
      const params = new URLSearchParams(window.location.search);
      params.set('_', String(Date.now()));
      const response = await fetch(`data.php?${params.toString()}`, { cache: 'no-store' });
      if (!response.ok) return;
      const report = await response.json();
      if (!report?.error) {
        updateVentasReport(report);
      }
    }

    document.querySelectorAll('#ventasFilters select').forEach((select) => {
      select.addEventListener('change', () => {
        select.form.submit();
      });
    });

    setInterval(() => {
      refreshVentasReport().catch(() => {});
    }, ventasRefreshMs);
  </script>
</body>

</html>
