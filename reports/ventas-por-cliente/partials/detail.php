<?php

/** @var array $detalleCliente */
/** @var int $anio_anterior */
/** @var int $anio_actual */

$resumen = (array)($detalleCliente['resumen'] ?? []);
$vendedoresDetalle = implode(', ', array_values((array)($detalleCliente['vendedores'] ?? [])));
$origenesDetalle = array_map(static function (string $origen): string {
  return $origen === 'facturado'
    ? 'Facturado'
    : ($origen === 'remisionado' ? 'Remisionado' : ucfirst($origen));
}, array_values((array)($detalleCliente['origenes'] ?? [])));
$origenesDetalleLabel = implode(' + ', $origenesDetalle);

$formatPct = static function (?float $value): string {
  if ($value === null) {
    return '-';
  }

  return ($value > 0 ? '+' : '') . n($value, 1) . '%';
};

$variationClass = static function (?float $value): string {
  if ($value === null || $value == 0.0) {
    return 'ventas-var-neutral';
  }

  return $value < 0 ? 'ventas-var-negative' : 'ventas-var-positive';
};

$chartMeses = [];
$chartVentasAnterior = [];
$chartVentasActual = [];
$chartKgAnterior = [];
$chartKgActual = [];
$chartConsumoAnterior = [];
$chartConsumoActual = [];
$chartCostoAnterior = [];
$chartCostoActual = [];

foreach ((array)($detalleCliente['meses'] ?? []) as $mes) {
  $chartMeses[] = (string)$mes['label'];
  $chartVentasAnterior[] = (float)$mes['ventas_anterior'];
  $chartVentasActual[] = (float)$mes['ventas_actual'];
  $chartKgAnterior[] = (float)$mes['kg_anterior'];
  $chartKgActual[] = (float)$mes['kg_actual'];
  $chartConsumoAnterior[] = $mes['consumo_promedio_anterior'] !== null ? (float)$mes['consumo_promedio_anterior'] : null;
  $chartConsumoActual[] = $mes['consumo_promedio_actual'] !== null ? (float)$mes['consumo_promedio_actual'] : null;
  $chartCostoAnterior[] = $mes['costo_promedio_anterior'] !== null ? (float)$mes['costo_promedio_anterior'] : null;
  $chartCostoActual[] = $mes['costo_promedio_actual'] !== null ? (float)$mes['costo_promedio_actual'] : null;
}
?>
<div class="cards-section">
  <div class="pivot-header">
    <div>
      <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px;">
        <a href="./index.php" class="back-btn" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
          <i class="fas fa-arrow-left"></i>
          Volver al reporte
        </a>
        <span class="badge">
          <?= htmlspecialchars((string)($detalleCliente['tipo_cliente'] ?? 'Desconocido')) ?>
        </span>
        <?php if ($origenesDetalleLabel !== ''): ?>
          <span class="badge"><?= htmlspecialchars($origenesDetalleLabel) ?></span>
        <?php endif; ?>
      </div>

      <h2>
        <i class="fas fa-user"></i>
        <?= htmlspecialchars((string)($detalleCliente['nombre'] ?? 'Cliente')) ?>
      </h2>
      <div class="cards-sub">
        Comparativo mensual contra <?= htmlspecialchars((string)$anio_anterior) ?> con costo promedio, consumo promedio y totales.
        <?php if ($vendedoresDetalle !== ''): ?>
          Vendedor(es): <?= htmlspecialchars($vendedoresDetalle) ?>.
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="kpi-grid ventas-detail-kpis">
    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-receipt" style="color:#10b981;"></i></div>
      <div class="kpi-label">Ventas anuales</div>
      <div class="kpi-value"><?= n((float)($resumen['compras_anio_actual'] ?? 0), 0) ?></div>
      <div class="kpi-trend">
        <?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)($resumen['compras_anio_anterior'] ?? 0), 0) ?> | Var: <?= htmlspecialchars($formatPct($resumen['variacion_compras'] ?? null)) ?>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-dollar-sign" style="color:#3b82f6;"></i></div>
      <div class="kpi-label">Total vendido</div>
      <div class="kpi-value">$<?= n((float)($resumen['ventas_anio_actual'] ?? 0), 0) ?></div>
      <div class="kpi-trend">
        <?= htmlspecialchars((string)$anio_anterior) ?>: $<?= n((float)($resumen['ventas_anio_anterior'] ?? 0), 0) ?> | Var: <?= htmlspecialchars($formatPct($resumen['variacion_ventas'] ?? null)) ?>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-weight-hanging" style="color:#f59e0b;"></i></div>
      <div class="kpi-label">Consumo total</div>
      <div class="kpi-value"><?= n((float)($resumen['kg_anio_actual'] ?? 0), 0) ?> kg</div>
      <div class="kpi-trend">
        <?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)($resumen['kg_anio_anterior'] ?? 0), 0) ?> kg | Var: <?= htmlspecialchars($formatPct($resumen['variacion_kg'] ?? null)) ?>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-scale-balanced" style="color:#8b5cf6;"></i></div>
      <div class="kpi-label">Consumo promedio</div>
      <div class="kpi-value"><?= ($resumen['consumo_promedio_anio_actual'] ?? null) !== null ? n((float)$resumen['consumo_promedio_anio_actual'], 1) . ' kg' : '-' ?></div>
      <div class="kpi-trend">
        <?= htmlspecialchars((string)$anio_anterior) ?>: <?= ($resumen['consumo_promedio_anio_anterior'] ?? null) !== null ? n((float)$resumen['consumo_promedio_anio_anterior'], 1) . ' kg' : '-' ?>
      </div>
    </div>

    <div class="kpi-card">
      <div class="kpi-icon"><i class="fas fa-tags" style="color:#ef4444;"></i></div>
      <div class="kpi-label">Precio promedio</div>
      <div class="kpi-value"><?= ($resumen['costo_promedio_anio_actual'] ?? null) !== null ? '$' . n((float)$resumen['costo_promedio_anio_actual'], 2) : '-' ?></div>
      <div class="kpi-trend">
        <?= htmlspecialchars((string)$anio_anterior) ?>: <?= ($resumen['costo_promedio_anio_anterior'] ?? null) !== null ? '$' . n((float)$resumen['costo_promedio_anio_anterior'], 2) : '-' ?>
      </div>
    </div>
  </div>

  <div class="chart-container">
    <div class="chart-header">
      <h3>
        <i class="fas fa-chart-line"></i>
        Comportamiento mensual <?= htmlspecialchars((string)$anio_anterior) ?> vs <?= htmlspecialchars((string)$anio_actual) ?>
      </h3>
      <div class="legend">
        <div class="legend-item">
          <div class="legend-color" style="background:#3b82f6;"></div>
          <span><?= htmlspecialchars((string)$anio_anterior) ?></span>
        </div>
        <div class="legend-item">
          <div class="legend-color" style="background:#10b981;"></div>
          <span><?= htmlspecialchars((string)$anio_actual) ?></span>
        </div>
      </div>
    </div>

    <div class="filters ventas-detail-chart-filters">
      <div class="filter-buttons">
        <button type="button" class="filter-btn active ventas-detail-metric-btn" data-metric="ventas">Total $</button>
        <button type="button" class="filter-btn ventas-detail-metric-btn" data-metric="kg">Total KG</button>
        <button type="button" class="filter-btn ventas-detail-metric-btn" data-metric="consumo">Consumo prom.</button>
        <button type="button" class="filter-btn ventas-detail-metric-btn" data-metric="costo">Costo prom.</button>
      </div>
    </div>

    <canvas id="ventasClienteDetailChart"></canvas>
  </div>

  <div class="table-wrapper ventas-clientes-table">
    <table>
      <colgroup>
        <col class="ventas-detail-col-mes">
        <col class="ventas-detail-col-metric">
        <col class="ventas-detail-col-metric">
        <col class="ventas-detail-col-metric">
        <col class="ventas-detail-col-metric">
        <col class="ventas-detail-col-metric">
      </colgroup>
      <thead>
        <tr>
          <th>Mes</th>
          <th>Ventas</th>
          <th>Total $</th>
          <th>Total KG</th>
          <th>Consumo prom.</th>
          <th>Costo prom.</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ((array)($detalleCliente['meses'] ?? []) as $mes): ?>
          <tr>
            <td>
              <div class="ventas-metric-stack">
                <strong><?= htmlspecialchars((string)$mes['label']) ?></strong>
              </div>
            </td>
            <td>
              <div class="ventas-metric-stack">
                <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)$mes['compras_anterior'], 0) ?></span>
                <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= n((float)$mes['compras_actual'], 0) ?></strong>
                <span class="<?= htmlspecialchars($variationClass($mes['variacion_compras'])) ?>"><?= htmlspecialchars($formatPct($mes['variacion_compras'])) ?></span>
              </div>
            </td>
            <td>
              <div class="ventas-metric-stack ventas-metric-stack-right">
                <span><?= htmlspecialchars((string)$anio_anterior) ?>: $<?= n((float)$mes['ventas_anterior'], 0) ?></span>
                <strong><?= htmlspecialchars((string)$anio_actual) ?>: $<?= n((float)$mes['ventas_actual'], 0) ?></strong>
                <span class="<?= htmlspecialchars($variationClass($mes['variacion_ventas'])) ?>"><?= htmlspecialchars($formatPct($mes['variacion_ventas'])) ?></span>
              </div>
            </td>
            <td>
              <div class="ventas-metric-stack ventas-metric-stack-right">
                <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)$mes['kg_anterior'], 0) ?> kg</span>
                <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= n((float)$mes['kg_actual'], 0) ?> kg</strong>
                <span class="<?= htmlspecialchars($variationClass($mes['variacion_kg'])) ?>"><?= htmlspecialchars($formatPct($mes['variacion_kg'])) ?></span>
              </div>
            </td>
            <td>
              <div class="ventas-metric-stack ventas-metric-stack-right">
                <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= $mes['consumo_promedio_anterior'] !== null ? n((float)$mes['consumo_promedio_anterior'], 1) . ' kg' : '-' ?></span>
                <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= $mes['consumo_promedio_actual'] !== null ? n((float)$mes['consumo_promedio_actual'], 1) . ' kg' : '-' ?></strong>
                <span class="<?= htmlspecialchars($variationClass($mes['variacion_consumo_promedio'])) ?>"><?= htmlspecialchars($formatPct($mes['variacion_consumo_promedio'])) ?></span>
              </div>
            </td>
            <td>
              <div class="ventas-metric-stack ventas-metric-stack-right">
                <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= $mes['costo_promedio_anterior'] !== null ? '$' . n((float)$mes['costo_promedio_anterior'], 2) : '-' ?></span>
                <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= $mes['costo_promedio_actual'] !== null ? '$' . n((float)$mes['costo_promedio_actual'], 2) : '-' ?></strong>
                <span class="<?= htmlspecialchars($variationClass($mes['variacion_costo_promedio'])) ?>"><?= htmlspecialchars($formatPct($mes['variacion_costo_promedio'])) ?></span>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const canvas = document.getElementById('ventasClienteDetailChart');
  if (!canvas || typeof Chart === 'undefined') {
    return;
  }

  const labels = <?= json_encode($chartMeses, JSON_UNESCAPED_UNICODE) ?>;
  const datasetsByMetric = {
    ventas: {
      label: 'Total vendido',
      suffix: '',
      prefix: '$',
      decimals: 0,
      previous: <?= json_encode($chartVentasAnterior) ?>,
      current: <?= json_encode($chartVentasActual) ?>
    },
    kg: {
      label: 'Total KG',
      suffix: ' kg',
      prefix: '',
      decimals: 0,
      previous: <?= json_encode($chartKgAnterior) ?>,
      current: <?= json_encode($chartKgActual) ?>
    },
    consumo: {
      label: 'Consumo promedio',
      suffix: ' kg',
      prefix: '',
      decimals: 1,
      previous: <?= json_encode($chartConsumoAnterior) ?>,
      current: <?= json_encode($chartConsumoActual) ?>
    },
    costo: {
      label: 'Costo promedio',
      suffix: '',
      prefix: '$',
      decimals: 2,
      previous: <?= json_encode($chartCostoAnterior) ?>,
      current: <?= json_encode($chartCostoActual) ?>
    }
  };

  const buttons = Array.from(document.querySelectorAll('.ventas-detail-metric-btn'));
  let activeMetric = 'ventas';

  const chart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: labels,
      datasets: []
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: {
        mode: 'index',
        intersect: false
      },
      plugins: {
        legend: {
          display: false
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              const metric = datasetsByMetric[activeMetric];
              const value = Number(context.parsed.y || 0).toLocaleString('es-MX', {
                minimumFractionDigits: metric.decimals,
                maximumFractionDigits: metric.decimals
              });
              return context.dataset.label + ': ' + metric.prefix + value + metric.suffix;
            }
          }
        }
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              const metric = datasetsByMetric[activeMetric];
              const formatted = Number(value || 0).toLocaleString('es-MX', {
                minimumFractionDigits: 0,
                maximumFractionDigits: metric.decimals
              });
              return metric.prefix + formatted + metric.suffix;
            }
          }
        }
      }
    }
  });

  function updateChart(metricKey) {
    activeMetric = metricKey;
    const metric = datasetsByMetric[metricKey];
    chart.data.datasets = [
      {
        label: String(<?= json_encode((string)$anio_anterior) ?>),
        data: metric.previous,
        borderColor: '#3b82f6',
        backgroundColor: 'rgba(59, 130, 246, 0.12)',
        tension: 0.35,
        borderWidth: 3,
        pointRadius: 4,
        pointHoverRadius: 5,
        fill: false
      },
      {
        label: String(<?= json_encode((string)$anio_actual) ?>),
        data: metric.current,
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.12)',
        tension: 0.35,
        borderWidth: 3,
        pointRadius: 4,
        pointHoverRadius: 5,
        fill: false
      }
    ];
    chart.options.plugins.title = {
      display: false,
      text: metric.label
    };
    chart.update();

    buttons.forEach(function(button) {
      button.classList.toggle('active', button.dataset.metric === metricKey);
    });
  }

  buttons.forEach(function(button) {
    button.addEventListener('click', function() {
      updateChart(this.dataset.metric || 'ventas');
    });
  });

  updateChart(activeMetric);
});
</script>
