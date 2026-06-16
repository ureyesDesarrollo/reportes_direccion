<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = $version ?? time();
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/css/dashboard.css") ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/js/display-mode.js") ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .secadores-toolbar {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      align-items: center;
      margin-top: 16px;
    }

    .secadores-tabs {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .secadores-tab {
      border: 1px solid #e2e8f0;
      background: #fff;
      color: #334155;
      padding: 10px 14px;
      border-radius: 999px;
      font-weight: 700;
      cursor: pointer;
      transition: all .2s ease;
    }

    .secadores-tab.active {
      border-color: #0f766e;
      background: rgba(15, 118, 110, 0.10);
      color: #0f766e;
    }

    .secadores-note {
      margin-top: 20px;
      padding: 16px 18px;
      border-radius: 14px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      color: #475569;
      line-height: 1.55;
    }

    .secadores-warning {
      margin-top: 16px;
      padding: 14px 16px;
      border-radius: 12px;
      border: 1px solid #fcd34d;
      background: #fffbeb;
      color: #92400e;
    }

    .secadores-cell {
      min-width: 92px;
      text-align: center;
      font-weight: 600;
      color: #0f172a;
    }

    .secadores-cell small {
      display: block;
      margin-top: 4px;
      font-size: 11px;
      font-weight: 600;
      color: inherit;
      opacity: .9;
    }

    .secadores-empty {
      padding: 28px 16px;
      text-align: center;
      color: #64748b;
    }
  </style>
</head>

<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a
            href="../index.php"
            class="back-btn"
            style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
            <i class="fas fa-arrow-left"></i>
            Regresar al inicio
          </a>
        </div>

        <h1>
          <i class="fas fa-fan" style="margin-right: 12px;"></i>
          <?= htmlspecialchars($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            <i class="fas fa-database"></i>
            Base: <?= htmlspecialchars((string)($meta['baseDatos'] ?? 'AVEVA_TAGS')) ?>
          </span>
          <span>
            <i class="fas fa-table"></i>
            Tabla: <?= htmlspecialchars((string)($meta['tabla'] ?? 'TREND001')) ?>
          </span>
          <span>
            <i class="fas fa-clock"></i>
            Refresco: <?= n(((float)($meta['intervaloActualizacion'] ?? 5000)) / 1000, 0) ?> s
          </span>
          <span class="badge">
            <i class="fas fa-sliders"></i>
            Semáforos configurables por variable
          </span>
        </div>

        <div class="secadores-toolbar">
          <div class="secadores-tabs" id="secadoresTabs"></div>
          <div class="year-badge" id="secadoresLastRefresh">
            <i class="fas fa-rotate"></i>
            Esperando actualización
          </div>
        </div>

        <?php foreach (($meta['warnings'] ?? []) as $warning): ?>
          <div class="secadores-warning">
            <i class="fas fa-triangle-exclamation"></i>
            <?= htmlspecialchars((string)$warning) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php require __DIR__ . '/partials/kpis.php'; ?>
    <?php require __DIR__ . '/partials/chart.php'; ?>
    <?php require __DIR__ . '/partials/table.php'; ?>

    <div class="secadores-note">
      <strong><i class="fas fa-info-circle"></i> Configuración del semáforo:</strong><br>
      Edita <strong>reports/secadores/config.php</strong> para ajustar por variable los límites de color con <code>modo</code> (<code>rango</code>, <code>minimo</code>, <code>maximo</code>) y sus valores <code>verde_*</code> / <code>amarillo_*</code>.<br>
      El campo de tiempo actual está configurado como <strong><?= htmlspecialchars((string)($meta['campoFecha'] ?? 'DateTime')) ?></strong>. Si en `TREND001` usa otro nombre, cámbialo ahí mismo.
    </div>
  </div>

  <script>
    window.secadoresBootstrap = <?= json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script>
    (() => {
      const secadoresBandsPlugin = {
        id: 'secadoresBands',
        beforeDraw(chart, args, pluginOptions) {
          const bands = pluginOptions || {};
          const yScale = chart.scales?.y;
          const chartArea = chart.chartArea;

          if (!yScale || !chartArea || !bands.hasBands) {
            return;
          }

          const { ctx } = chart;
          const topLimit = yScale.max;
          const bottomLimit = yScale.min;
          const greenMin = bands.greenMin;
          const greenMax = bands.greenMax;
          const yellowMin = bands.yellowMin;
          const yellowMax = bands.yellowMax;
          const mode = bands.mode || 'rango';

          const clampRange = (start, end) => {
            if (start === null || start === undefined || end === null || end === undefined) {
              return null;
            }

            const safeStart = Math.max(Math.min(start, topLimit), bottomLimit);
            const safeEnd = Math.max(Math.min(end, topLimit), bottomLimit);
            if (safeStart === safeEnd) {
              return null;
            }

            return [safeStart, safeEnd];
          };

          const paintBand = (start, end, color) => {
            const range = clampRange(start, end);
            if (!range) {
              return;
            }

            const y1 = yScale.getPixelForValue(range[0]);
            const y2 = yScale.getPixelForValue(range[1]);
            const top = Math.min(y1, y2);
            const height = Math.abs(y2 - y1);
            if (height <= 0) {
              return;
            }

            ctx.save();
            ctx.fillStyle = color;
            ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, height);
            ctx.restore();
          };

          if (mode === 'minimo') {
            paintBand(bottomLimit, yellowMin ?? greenMin ?? topLimit, 'rgba(239, 68, 68, 0.08)');
            paintBand(yellowMin ?? bottomLimit, greenMin ?? topLimit, 'rgba(245, 158, 11, 0.10)');
            paintBand(greenMin ?? bottomLimit, topLimit, 'rgba(16, 185, 129, 0.08)');
            return;
          }

          if (mode === 'maximo') {
            paintBand(bottomLimit, greenMax ?? topLimit, 'rgba(16, 185, 129, 0.08)');
            paintBand(greenMax ?? bottomLimit, yellowMax ?? topLimit, 'rgba(245, 158, 11, 0.10)');
            paintBand(yellowMax ?? bottomLimit, topLimit, 'rgba(239, 68, 68, 0.08)');
            return;
          }

          paintBand(bottomLimit, yellowMin ?? greenMin ?? bottomLimit, 'rgba(239, 68, 68, 0.08)');
          paintBand(yellowMin ?? bottomLimit, greenMin ?? yellowMax ?? topLimit, 'rgba(245, 158, 11, 0.10)');
          paintBand(greenMin ?? bottomLimit, greenMax ?? topLimit, 'rgba(16, 185, 129, 0.08)');
          paintBand(greenMax ?? bottomLimit, yellowMax ?? topLimit, 'rgba(245, 158, 11, 0.10)');
          paintBand(yellowMax ?? greenMax ?? topLimit, topLimit, 'rgba(239, 68, 68, 0.08)');
        }
      };

      if (window.Chart && !Chart.registry.plugins.get('secadoresBands')) {
        Chart.register(secadoresBandsPlugin);
      }

      const state = {
        payload: window.secadoresBootstrap || {},
        activeTunnel: (window.secadoresBootstrap && window.secadoresBootstrap.tunelSeleccionado) || 'tunel_1',
        chart: null,
        timer: null,
      };

      const formatNumber = (value, decimals = 2) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
          return '-';
        }

        return Number(value).toLocaleString('en-US', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        });
      };

      const getTunnelTitle = (tunnelKey) => {
        const tunnel = state.payload.tuneles?.[tunnelKey];
        return tunnel ? tunnel.titulo : tunnelKey;
      };

      const renderTabs = () => {
        const tabs = document.getElementById('secadoresTabs');
        if (!tabs) return;

        tabs.innerHTML = '';
        Object.entries(state.payload.tuneles || {}).forEach(([key, tunnel]) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'secadores-tab' + (key === state.activeTunnel ? ' active' : '');
          button.textContent = tunnel.titulo;
          button.addEventListener('click', () => {
            state.activeTunnel = key;
            renderAll();
          });
          tabs.appendChild(button);
        });
      };

      const renderKpis = () => {
        const summary = state.payload.resumenes?.[state.activeTunnel] || {};
        document.getElementById('secadoresUltimaLectura').textContent = summary.ultimaLectura || '-';
        document.getElementById('secadoresVerdes').textContent = summary.enVerde ?? 0;
        document.getElementById('secadoresAlertas').textContent = summary.enAlerta ?? 0;
        document.getElementById('secadoresCriticas').textContent = summary.enCritico ?? 0;
        document.getElementById('secadoresPromedio').textContent = summary.promedioActual !== null && summary.promedioActual !== undefined
          ? formatNumber(summary.promedioActual, 2)
          : '-';
        document.getElementById('secadoresPromedioTrend').textContent =
          'Máx: ' + (summary.maximoActual !== null && summary.maximoActual !== undefined ? formatNumber(summary.maximoActual, 2) : '-') +
          ' | Mín: ' + (summary.minimoActual !== null && summary.minimoActual !== undefined ? formatNumber(summary.minimoActual, 2) : '-');
      };

      const renderChart = () => {
        const chartWrap = state.payload.charts?.[state.activeTunnel] || { labels: [], datasets: [] };
        const subtitle = document.getElementById('secadoresChartSub');
        if (subtitle) {
          const bands = chartWrap.bands || {};
          const parts = ['Histórico reciente de ' + getTunnelTitle(state.activeTunnel)];
          if (bands.hasBands) {
            const fmt = (value) => value === null || value === undefined ? '-' : formatNumber(value, 1);
            if ((bands.mode || 'rango') === 'rango') {
              parts.push('Verde ' + fmt(bands.greenMin) + ' a ' + fmt(bands.greenMax));
            }
          }
          subtitle.textContent = parts.join(' | ');
        }

        const ctx = document.getElementById('secadoresChart');
        if (!ctx) return;

        if (state.chart) {
          state.chart.destroy();
        }

        state.chart = new Chart(ctx, {
          type: 'line',
          data: {
            labels: chartWrap.labels || [],
            datasets: chartWrap.datasets || [],
          },
          options: {
            animation: false,
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
              mode: 'index',
              intersect: false,
            },
            plugins: {
              secadoresBands: chartWrap.bands || {},
              legend: {
                position: 'bottom',
              },
            },
            scales: {
              x: {
                ticks: {
                  maxRotation: 0,
                  autoSkip: true,
                  maxTicksLimit: 10,
                },
              },
              y: {
                beginAtZero: false,
              },
            },
          },
        });
      };

      const renderTable = () => {
        const tunnel = state.payload.tuneles?.[state.activeTunnel] || { campos: [] };
        const rows = state.payload.tablas?.[state.activeTunnel] || [];
        const tableHead = document.getElementById('secadoresTableHead');
        const tableBody = document.getElementById('secadoresTableBody');
        const subtitle = document.getElementById('secadoresTableSub');

        if (subtitle) {
          subtitle.textContent = 'Últimos ' + (state.payload.meta?.limiteRegistros ?? rows.length) + ' registros de ' + getTunnelTitle(state.activeTunnel);
        }

        if (tableHead) {
          const headerCells = ['<tr><th>Fecha / Hora</th>'];
          (tunnel.campos || []).forEach((field) => {
            headerCells.push('<th>' + field.label + '</th>');
          });
          headerCells.push('</tr>');
          tableHead.innerHTML = headerCells.join('');
        }

        if (!tableBody) return;

        if (!rows.length) {
          tableBody.innerHTML = '<tr><td colspan="' + ((tunnel.campos || []).length + 1) + '" class="secadores-empty">Sin lecturas disponibles para este túnel.</td></tr>';
          return;
        }

        tableBody.innerHTML = rows.map((row) => {
          const cells = row.cells.map((cell) => {
            const background = cell.statusColor || '#f8fafc';
            return '<td class="secadores-cell" style="background:' + background + '1A; border-left: 4px solid ' + background + ';">'
              + (cell.formatted || '-')
              + '<small>' + (cell.statusLabel || 'Sin dato') + '</small>'
              + '</td>';
          }).join('');

          return '<tr><td style="white-space: nowrap; font-weight: 600;">' + row.timestamp + '</td>' + cells + '</tr>';
        }).join('');
      };

      const renderAll = () => {
        renderTabs();
        renderKpis();
        renderChart();
        renderTable();
      };

      const updateRefreshBadge = (ok, message) => {
        const badge = document.getElementById('secadoresLastRefresh');
        if (!badge) return;

        if (ok) {
          badge.innerHTML = '<i class="fas fa-rotate"></i> Actualizado: ' + new Date().toLocaleTimeString();
          return;
        }

        badge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + message;
      };

      const fetchData = async () => {
        try {
          const response = await fetch('data.php?t=' + Date.now(), { cache: 'no-store' });
          if (!response.ok) {
            throw new Error('HTTP ' + response.status);
          }

          const payload = await response.json();
          if (payload && !payload.error) {
            state.payload = payload;
            if (!state.payload.tuneles?.[state.activeTunnel]) {
              state.activeTunnel = state.payload.tunelSeleccionado || Object.keys(state.payload.tuneles || {})[0] || state.activeTunnel;
            }
            renderAll();
            updateRefreshBadge(true, '');
            return;
          }

          throw new Error(payload?.message || 'No se pudo cargar la actualización.');
        } catch (error) {
          updateRefreshBadge(false, 'Sin actualizar');
        }
      };

      renderAll();
      updateRefreshBadge(true, '');

      const interval = Number(state.payload.meta?.intervaloActualizacion || 5000);
      state.timer = window.setInterval(fetchData, interval);
    })();
  </script>
</body>

</html>
