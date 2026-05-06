<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  exit;
}

$titulo = (string)($report['titulo'] ?? 'Secadores');
$version = (int)($report['version'] ?? time());
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <style>
    .secadores-summary-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      margin-top: 22px;
    }

    .secadores-panel {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
      overflow: hidden;
    }

    .secadores-panel-head {
      padding: 18px 20px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
    }

    .secadores-panel-title {
      margin: 0 0 6px;
      font-size: 1.1rem;
      font-weight: 800;
      color: #0f172a;
    }

    .secadores-panel-sub {
      color: #64748b;
      font-size: 0.92rem;
      line-height: 1.45;
    }

    .secadores-stamp {
      color: #64748b;
      font-size: 0.82rem;
      text-align: right;
      white-space: nowrap;
    }

    .secadores-detail-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid #dbe2ea;
      background: #fff;
      color: #334155;
      border-radius: 999px;
      text-decoration: none;
      padding: 10px 14px;
      font-weight: 700;
      margin-top: 10px;
    }

    .secadores-card-link {
      color: inherit;
      text-decoration: none;
      display: block;
    }

    .secadores-body {
      padding: 20px;
      display: grid;
      gap: 20px;
    }

    .secadores-kpi-row {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 16px;
    }

    .secadores-kpi-box {
      border-radius: 18px;
      padding: 20px;
      border: 1px solid #e2e8f0;
      min-height: 140px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .secadores-kpi-label {
      font-size: 0.85rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #334155;
    }

    .secadores-kpi-value {
      font-size: clamp(2rem, 4vw, 3.2rem);
      line-height: 1;
      font-weight: 800;
      color: #0f172a;
    }

    .secadores-kpi-meta {
      color: #475569;
      font-size: 0.92rem;
    }

    .secadores-status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.78rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .secadores-section-title {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.96rem;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 14px;
    }

    .secadores-zones-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }

    .secadores-zone-card,
    .secadores-maint-card {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px;
      min-height: 122px;
    }

    .secadores-zone-label,
    .secadores-maint-title {
      font-size: 0.88rem;
      font-weight: 800;
      color: #334155;
      margin-bottom: 10px;
    }

    .secadores-zone-value,
    .secadores-maint-value {
      font-size: 1.55rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.05;
    }

    .secadores-zone-meta,
    .secadores-maint-meta {
      margin-top: 8px;
      color: #64748b;
      font-size: 0.84rem;
      line-height: 1.4;
    }

    .secadores-maint-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .secadores-warning {
      margin-top: 18px;
      padding: 14px 16px;
      border-radius: 14px;
      background: #fffbeb;
      border: 1px solid #fcd34d;
      color: #92400e;
    }

    @media (max-width: 1180px) {
      .secadores-summary-grid,
      .secadores-kpi-row,
      .secadores-zones-grid,
      .secadores-maint-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      .secadores-summary-grid,
      .secadores-kpi-row,
      .secadores-zones-grid,
      .secadores-maint-grid {
        grid-template-columns: 1fr;
      }

      .secadores-panel-head {
        flex-direction: column;
      }

      .secadores-stamp {
        text-align: left;
        white-space: normal;
      }
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
        <h1><i class="fas fa-fan" style="margin-right:12px;"></i><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-gauge-high"></i> Resumen operativo de los dos tuneles</span>
          <span class="badge"><i class="fas fa-temperature-three-quarters"></i> Temperatura por zona con semaforo</span>
          <span class="year-badge" id="secadoresLastRefresh"><i class="fas fa-rotate"></i> Esperando actualizacion</span>
        </div>
      </div>
    </div>

    <div id="secadoresWarnings"></div>
    <div class="secadores-summary-grid" id="secadoresSummaryGrid"></div>
  </div>

  <script>
    window.secadoresResumenBootstrap = <?= json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script>
    (() => {
      const state = {
        payload: window.secadoresResumenBootstrap || {},
        timer: null,
      };

      const renderWarnings = () => {
        const wrap = document.getElementById('secadoresWarnings');
        if (!wrap) return;
        const warnings = state.payload.meta?.warnings || [];
        wrap.innerHTML = warnings.map((warning) =>
          '<div class="secadores-warning"><i class="fas fa-triangle-exclamation"></i> ' + warning + '</div>'
        ).join('');
      };

      const renderHeader = () => {
        const lastRefresh = document.getElementById('secadoresLastRefresh');
        if (!lastRefresh) return;
        lastRefresh.innerHTML = '<i class="fas fa-rotate"></i> Actualizacion: ' + new Date().toLocaleString('es-MX');
      };

      const renderTunnels = () => {
        const grid = document.getElementById('secadoresSummaryGrid');
        if (!grid) return;

        const tunnels = Object.values(state.payload.tuneles || {});
        grid.innerHTML = tunnels.map((tunnel) => {
          const operation = tunnel.operacion || {};
          const maintenance = tunnel.mantenimiento || {};

          const continuous = (tunnel.parametrosContinuos || []).map((item) => (
            '<div class="secadores-zone-card" style="background:' + item.statusBg + '; border-color:' + item.statusColor + '33;">' +
              '<div class="secadores-zone-label">' + item.titulo + '</div>' +
              '<div class="secadores-zone-value">' + item.valor + '</div>' +
              '<div class="secadores-zone-meta">' + item.detalle + '<br>' +
                '<span class="secadores-status" style="background:' + item.statusBg + '; color:' + item.statusColor + '; margin-top:8px;">' +
                  '<i class="fas fa-circle"></i>' + item.statusLabel +
                '</span>' +
              '</div>' +
            '</div>'
          )).join('');

          const zones = (tunnel.zonas || []).map((zone) => (
            '<a class="secadores-card-link" href="' + tunnel.detalleUrl + '">' +
              '<div class="secadores-zone-card" style="background:' + zone.statusBg + '; border-color:' + zone.statusColor + '33;">' +
                '<div class="secadores-zone-label">' + zone.label + '</div>' +
                '<div class="secadores-zone-value">' + zone.value + '</div>' +
                '<div class="secadores-zone-meta">' +
                  '<span class="secadores-status" style="background:' + zone.statusBg + '; color:' + zone.statusColor + ';">' +
                    '<i class="fas fa-circle"></i>' + zone.statusLabel +
                  '</span>' +
                '</div>' +
              '</div>' +
            '</a>'
          )).join('');

          const maints = (tunnel.mantenimientos || []).map((item) => (
            '<div class="secadores-maint-card" style="background:' + item.statusBg + '; border-color:' + item.statusColor + '33;">' +
              '<div class="secadores-maint-title">' + item.titulo + '</div>' +
              '<div class="secadores-maint-value">' + item.valor + '</div>' +
              '<div class="secadores-maint-meta">' + item.detalle + '<br><span class="secadores-status" style="background:' + item.statusBg + '; color:' + item.statusColor + '; margin-top:8px;">' +
              '<i class="fas fa-circle"></i>' + item.statusLabel + '</span></div>' +
            '</div>'
          )).join('');

          return (
            '<section class="secadores-panel">' +
              '<div class="secadores-panel-head">' +
                '<div>' +
                  '<h2 class="secadores-panel-title">' + tunnel.titulo + '</h2>' +
                  '<div class="secadores-panel-sub">' + (tunnel.subtitulo || '') + '</div>' +
                '</div>' +
                '<div class="secadores-stamp">' +
                  '<strong>Ultima lectura</strong><br>' + (tunnel.ultimaLectura || '-') +
                '</div>' +
              '</div>' +
              '<div class="secadores-body">' +
                '<div class="secadores-kpi-row">' +
                  '<div class="secadores-kpi-box" style="background:' + operation.statusBg + '; border-color:' + operation.statusColor + '33;">' +
                    '<div class="secadores-kpi-label">Cumplimiento de operacion</div>' +
                    '<div class="secadores-kpi-value">' + (operation.formatted || '-') + '</div>' +
                    '<div class="secadores-kpi-meta">' + (operation.trend || '') + '<br>' +
                      '<span class="secadores-status" style="background:' + operation.statusBg + '; color:' + operation.statusColor + '; margin-top:10px;">' +
                        '<i class="fas fa-circle"></i>' + (operation.statusLabel || '-') +
                      '</span>' +
                    '</div>' +
                  '</div>' +
                  '<div class="secadores-kpi-box" style="background:' + maintenance.statusBg + '; border-color:' + maintenance.statusColor + '33;">' +
                    '<div class="secadores-kpi-label">Cumplimiento de mtto y revision</div>' +
                    '<div class="secadores-kpi-value">' + (maintenance.formatted || '-') + '</div>' +
                    '<div class="secadores-kpi-meta">' + (maintenance.trend || '') + '<br>' +
                      '<span class="secadores-status" style="background:' + maintenance.statusBg + '; color:' + maintenance.statusColor + '; margin-top:10px;">' +
                        '<i class="fas fa-circle"></i>' + (maintenance.statusLabel || '-') +
                      '</span>' +
                    '</div>' +
                  '</div>' +
                '</div>' +
                '<div>' +
                  '<div class="secadores-section-title"><i class="fas fa-wind"></i> Parametros continuos</div>' +
                  '<div class="secadores-zones-grid">' + continuous + '</div>' +
                '</div>' +
                '<div>' +
                  '<div class="secadores-section-title"><i class="fas fa-temperature-three-quarters"></i> Temperatura de zonas</div>' +
                  '<div class="secadores-zones-grid">' + zones + '</div>' +
                '</div>' +
                '<div>' +
                  '<div class="secadores-section-title"><i class="fas fa-screwdriver-wrench"></i> Mantenimientos vitales</div>' +
                  '<div class="secadores-maint-grid">' + maints + '</div>' +
                '</div>' +
              '</div>' +
            '</section>'
          );
        }).join('');
      };

      const renderAll = () => {
        renderHeader();
        renderWarnings();
        renderTunnels();
      };

      const scheduleRefresh = () => {
        if (state.timer) {
          window.clearInterval(state.timer);
        }

        const interval = Number(state.payload.meta?.intervaloActualizacion || 1800000);
        state.timer = window.setInterval(async () => {
          try {
            const response = await fetch('./data.php', { cache: 'no-store' });
            if (!response.ok) {
              throw new Error('No fue posible actualizar el tablero');
            }
            state.payload = await response.json();
            renderAll();
          } catch (error) {
          }
        }, interval);
      };

      renderAll();
      scheduleRefresh();
    })();
  </script>
</body>
</html>
