<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
$config = require __DIR__ . '/config.php';
$clienteSeleccionado = trim((string)($_GET['cliente'] ?? ''));
if ($clienteSeleccionado !== '') {
  $config['cliente_seleccionado'] = $clienteSeleccionado;
}
require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';

try {
  $reportData = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

extract($reportData, EXTR_SKIP);

$titulo = $titulo ?? ($config['titulo'] ?? 'Ventas por Cliente');
$version = $version ?? time();
$fechaDesde = $meta['fechaDesde'] ?? ($config['fecha_desde'] ?? '');
$intervaloActualizacion = (int)($meta['intervaloActualizacion'] ?? ($appConfig['intervalo_actualizacion'] ?? 300000));
$filasPorPagina = (int)($meta['filasPorPagina'] ?? ($appConfig['filas_por_pagina'] ?? 15));
$toleranciaPct = (float)($meta['toleranciaPct'] ?? ($config['tolerancia_pct'] ?? 10));
$statusCanceladoValores = $status_cancelado_valores ?? ($meta['statusCanceladoValores'] ?? []);
$detalleCliente = $detalle_cliente ?? null;
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">

  <title><?= htmlspecialchars($titulo) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <?php if ($detalleCliente): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <?php endif; ?>
</head>

<body>
  <div class="loading-overlay" id="loadingOverlay">
    <div class="loading-spinner">
      <i class="fas fa-sync-alt"></i>
      <p>Actualizando datos...</p>
    </div>
  </div>

  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a
            href="../index.php"
            class="back-btn"
            style="
              display:inline-flex;
              align-items:center;
              gap:8px;
              padding:10px 14px;
              border-radius:999px;
              text-decoration:none;
              font-weight:700;
              border:1px solid #e2e8f0;
              background:#ffffff;
              color:#334155;
            ">
            <i class="fas fa-arrow-left"></i>
            Regresar al inicio
          </a>
        </div>

        <h1>
          <i class="fas fa-cart-shopping" style="margin-right: 12px;"></i>
          <?= htmlspecialchars($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            <i class="far fa-calendar-alt"></i>
            Desde: <?= htmlspecialchars((string)$fechaDesde) ?>
          </span>

          <span>
            <i class="fas fa-receipt"></i>
            Facturas de venta no canceladas
          </span>

          <span class="badge">
            <i class="fas fa-chart-simple"></i>
            <?= $detalleCliente ? 'Detalle de cliente' : 'Ventas por cliente' ?>
          </span>

          <span class="year-badge">
            <i class="fas fa-calendar"></i>
            Año base: <?= htmlspecialchars((string)$anio_anterior) ?>
          </span>
        </div>
      </div>

      <div class="header-right">
        <div class="update-panel">
          <div class="update-status">
            <span class="update-indicator" id="updateIndicator"></span>
            <span id="updateStatusText">Conectado</span>
          </div>

          <div class="update-timer" id="updateTimer">05:00</div>

          <button class="update-btn" id="manualUpdateBtn" onclick="manualUpdate()">
            <i class="fas fa-sync-alt"></i>
            Actualizar
          </button>

          <div class="auto-update-toggle">
            <span>
              <i class="fas fa-clock"></i>
              Auto
            </span>
            <label class="toggle-switch-small">
              <input type="checkbox" id="autoUpdateToggle" checked>
              <span class="slider-small"></span>
            </label>
          </div>

          <div class="last-update" id="lastUpdateTime">
            <i class="far fa-clock"></i>
            <span id="lastUpdateText"><?= date('H:i:s') ?></span>
          </div>
        </div>
      </div>
    </div>

    <?php if ($detalleCliente): ?>
      <?php require __DIR__ . '/partials/detail.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/partials/kpis.php'; ?>
      <?php require __DIR__ . '/partials/table.php'; ?>
    <?php endif; ?>

    <?php if (!$detalleCliente): ?>
      <div class="nota">
        <strong><i class="fas fa-info-circle"></i> Lógica del Semáforo:</strong><br>
        1. <strong>Base:</strong> número anual de facturas de venta no canceladas en <?= htmlspecialchars((string)$anio_anterior) ?>.<br>
        2. <strong>Verde:</strong> frecuencia anual de venta igual, mayor o caída dentro del <?= htmlspecialchars((string)$toleranciaPct) ?>%.<br>
        3. <strong>Amarillo:</strong> caída moderada contra el año base.<br>
        4. <strong>Rojo:</strong> caída fuerte o cliente inactivo en <?= htmlspecialchars((string)$anio_actual) ?>.<br>
        5. <strong>Facturas excluidas:</strong> <?= htmlspecialchars(implode(', ', $statusCanceladoValores)) ?>.
      </div>
    <?php endif; ?>
  </div>

  <script>
    window.reportConfig = {
      titulo: <?= json_encode($titulo, JSON_UNESCAPED_UNICODE) ?>,
      anioAnterior: <?= json_encode($anio_anterior) ?>,
      anioActual: <?= json_encode($anio_actual) ?>,
      mesCorte: <?= json_encode($mes_corte) ?>,
      mesCorteLabel: <?= json_encode($mes_corte_label, JSON_UNESCAPED_UNICODE) ?>,
      toleranciaPct: <?= json_encode($toleranciaPct) ?>,
      filasPorPagina: <?= json_encode($filasPorPagina) ?>,
      intervaloActualizacion: <?= json_encode($intervaloActualizacion) ?>,
      version: <?= json_encode($version) ?>
    };
  </script>
  <script src="../../assets/js/dashboard.js?v=<?= urlencode((string)$version) ?>"></script>
  <script>
    (function() {
      const intervalo = Number(window.reportConfig.intervaloActualizacion || 300000);
      let autoUpdateEnabled = true;
      let updateTimer = Math.floor(intervalo / 1000);
      let isUpdating = false;

      function pad(value) {
        return String(value).padStart(2, '0');
      }

      function updateTimerDisplay() {
        const el = document.getElementById('updateTimer');
        if (!el) return;
        const minutes = Math.floor(updateTimer / 60);
        const seconds = updateTimer % 60;
        el.textContent = pad(minutes) + ':' + pad(seconds);
      }

      window.manualUpdate = function() {
        if (isUpdating) return;
        isUpdating = true;

        const overlay = document.getElementById('loadingOverlay');
        const indicator = document.getElementById('updateIndicator');
        const status = document.getElementById('updateStatusText');
        const button = document.getElementById('manualUpdateBtn');

        if (overlay) overlay.classList.add('active');
        if (indicator) indicator.classList.add('updating');
        if (status) status.textContent = 'Actualizando';
        if (button) button.disabled = true;

        window.location.reload();
      };

      const autoToggle = document.getElementById('autoUpdateToggle');
      if (autoToggle) {
        autoToggle.addEventListener('change', function(event) {
          autoUpdateEnabled = event.target.checked;
          const status = document.getElementById('updateStatusText');
          if (status) status.textContent = autoUpdateEnabled ? 'Conectado' : 'Manual';
        });
      }

      updateTimerDisplay();

      if (intervalo > 0) {
        setInterval(function() {
          if (!autoUpdateEnabled || isUpdating) return;

          if (updateTimer <= 0) {
            window.manualUpdate();
            return;
          }

          updateTimer--;
          updateTimerDisplay();
        }, 1000);
      }
    })();
  </script>
</body>

</html>
