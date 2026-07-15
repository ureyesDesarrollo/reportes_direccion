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

$titulo = (string)($report['titulo'] ?? 'Concentradores');
$concentradores = (array)($report['concentradores'] ?? []);
$meta = (array)($report['meta'] ?? []);
$version = (int)($report['version'] ?? time());
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$metricClass = static function (array $metric): string {
  $class = (string)($metric['status']['class'] ?? 'unavailable');
  return preg_match('/^[a-z0-9-]+$/', $class) === 1 ? $class : 'unavailable';
};
$metricIcon = static function (string $metricKey): string {
  return [
    'flujo' => 'fa-water',
    'temperatura' => 'fa-temperature-half',
    'vacio' => 'fa-gauge-high',
    'solidos_entrada' => 'fa-arrow-right-to-bracket',
    'solidos_salida' => 'fa-arrow-right-from-bracket',
  ][$metricKey] ?? 'fa-flask';
};
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <style>
    :root {
      --bg: #f4f7fb;
      --panel: #ffffff;
      --ink: #172033;
      --muted: #64748b;
      --line: #dbe7f5;
      --blue: #0ea5e9;
      --green: #2e8b57;
      --yellow: #e49a32;
      --red: #c94436;
      --gray: #94a3b8;
      --shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
    }

    * {
      box-sizing: border-box;
    }

    body {
      background: var(--bg);
      min-height: 100vh;
    }

    .dashboard {
      background: transparent;
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

    .concentradores-exec-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 14px;
      align-items: start;
    }

    .concentradores-exec-panel {
      display: flex;
      flex-direction: column;
      gap: 10px;
      min-width: 0;
      min-height: 410px;
      padding: 14px;
      border: 1px solid #dbe7f5;
      border-radius: 16px;
      background: #ffffff;
      box-shadow:
        0 10px 22px rgba(37, 99, 235, 0.08),
        0 2px 6px rgba(15, 23, 42, 0.05);
      transition: all .3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .concentradores-exec-panel:hover {
      border-color: #93c5fd;
      box-shadow:
        0 16px 34px rgba(37, 99, 235, 0.12),
        0 3px 10px rgba(15, 23, 42, 0.06);
      transform: translateY(-2px);
    }

    .concentradores-exec-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      padding: 12px 14px;
      border: 1px solid #cfe0fb;
      border-radius: 12px;
      background: #eef6ff;
    }

    .concentradores-exec-head h2 {
      margin: 0;
      color: #0f172a;
      font-size: 20px;
      font-weight: 700;
      line-height: 1.1;
    }

    .concentradores-exec-head span {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 38px;
      min-height: 24px;
      padding: 4px 8px;
      border-radius: 999px;
      color: #1d4ed8;
      background: #dbeafe;
      font-size: 12px;
      font-weight: 800;
    }

    .concentradores-exec-head span.is-offline {
      color: #ffffff;
      background: #64748b;
    }

    .concentradores-exec-head span.is-empty {
      visibility: hidden;
    }

    .concentradores-exec-metrics {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      grid-auto-rows: minmax(132px, 1fr);
      gap: 8px;
      flex: 1;
    }

    .concentradores-exec-metric {
      display: flex;
      grid-column: span 3;
      align-items: flex-start;
      gap: 10px;
      min-width: 0;
      min-height: 132px;
      padding: 10px 11px;
      border: 1px solid #0284c7;
      border-radius: 12px;
      color: #ffffff;
      background: var(--blue);
      box-shadow: 0 10px 24px rgba(14, 165, 233, 0.2);
    }

    .concentradores-exec-metric.ok {
      border-color: #257447;
      background: var(--green);
      box-shadow: 0 10px 24px rgba(46, 139, 87, 0.2);
    }

    .concentradores-exec-metric.warning {
      border-color: #c47b1c;
      background: var(--yellow);
      box-shadow: 0 10px 24px rgba(228, 154, 50, 0.2);
    }

    .concentradores-exec-metric.danger {
      border-color: #a9362c;
      background: var(--red);
      box-shadow: 0 10px 24px rgba(201, 68, 54, 0.2);
    }

    .concentradores-exec-metric.unavailable {
      border-color: #64748b;
      background: var(--gray);
      box-shadow: none;
    }

    .concentradores-exec-metric[data-metric="solidos_entrada"],
    .concentradores-exec-metric[data-metric="solidos_salida"] {
      grid-column: span 3;
    }

    .concentradores-exec-metric:last-child:nth-child(odd) {
      grid-column: 2 / span 4;
    }

    .concentradores-exec-metric i {
      width: 18px;
      margin-top: 2px;
      font-size: 16px;
      opacity: 0.95;
    }

    .concentradores-exec-metric-body {
      display: flex;
      flex-direction: column;
      gap: 5px;
      min-width: 0;
      width: 100%;
    }

    .concentradores-exec-metric-label {
      min-height: 24px;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: 0;
      text-transform: uppercase;
      opacity: 0.9;
    }

    .concentradores-exec-metric-value {
      font-size: 25px;
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
    }

    .concentradores-exec-metric-status {
      font-size: 10px;
      font-weight: 800;
      line-height: 1.25;
      opacity: 0.92;
    }

    .concentradores-exec-warning {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 12px;
      padding: 12px 14px;
      border: 1px solid #f59e0b;
      border-radius: 12px;
      color: #78350f;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 800;
    }

    @media (max-width: 1320px) {
      .concentradores-exec-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 720px) {
      .concentradores-exec-grid {
        grid-template-columns: 1fr;
      }

      .concentradores-exec-metrics {
        grid-template-columns: 1fr;
      }

      .concentradores-exec-metric,
      .concentradores-exec-metric[data-metric="solidos_entrada"],
      .concentradores-exec-metric[data-metric="solidos_salida"],
      .concentradores-exec-metric:last-child:nth-child(odd) {
        grid-column: auto;
      }
    }
  </style>
</head>

<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a href="../index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Regresar al inicio
          </a>
        </div>

        <h1>
          <i class="fas fa-industry" style="margin-right: 12px;"></i>
          <?= $e($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            <i class="fas fa-water"></i>
            Flujo desde AVEVA
          </span>
          <span>
            <i class="fas fa-database"></i>
            Variables desde 105
          </span>
          <span>
            <i class="fas fa-clock"></i>
            Refresco: <?= $e((int)ceil(((int)($meta['intervaloActualizacion'] ?? 60000)) / 1000)) ?>s
          </span>
          <span class="badge">
            <i class="fas fa-industry"></i>
            <?= count($concentradores) ?> concentradores
          </span>
        </div>
      </div>
    </div>

    <div data-warnings>
      <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
        <div class="concentradores-exec-warning"><i class="fas fa-triangle-exclamation"></i><?= $e($warning) ?></div>
      <?php endforeach; ?>
    </div>

    <section class="concentradores-exec-grid" aria-label="Lecturas por concentrador">
      <?php foreach ($concentradores as $concentrador): ?>
        <article class="concentradores-exec-panel" data-concentrator="<?= $e($concentrador['key'] ?? '') ?>">
          <header class="concentradores-exec-head">
            <h2><?= $e($concentrador['nombre'] ?? 'Concentrador') ?></h2>
            <span data-field="operation-status" class="<?= !empty($concentrador['fuera_operacion']) ? 'is-offline' : 'is-empty' ?>">
              <?= !empty($concentrador['fuera_operacion']) ? 'FO' : 'FO' ?>
            </span>
          </header>
          <div class="concentradores-exec-metrics">
            <?php foreach ((array)($concentrador['metricas'] ?? []) as $metric): ?>
              <?php $metricKey = (string)($metric['key'] ?? ''); ?>
              <div class="concentradores-exec-metric <?= $e($metricClass((array)$metric)) ?>" data-metric="<?= $e($metricKey) ?>">
                <i class="fa-solid <?= $e($metricIcon($metricKey)) ?>"></i>
                <div class="concentradores-exec-metric-body">
                  <div class="concentradores-exec-metric-label"><?= $e($metric['label'] ?? $metricKey) ?></div>
                  <div class="concentradores-exec-metric-value" data-field="value"><?= $e($metric['formatted'] ?? '-') ?></div>
                  <div class="concentradores-exec-metric-status" data-field="status"><?= $e($metric['status']['label'] ?? 'Sin dato') ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </div>

  <script>
    const refreshMs = <?= (int)($meta['intervaloActualizacion'] ?? 60000) ?>;

    function metricClass(metric) {
      const statusClass = metric?.status?.class || 'unavailable';
      return ['ok', 'warning', 'danger', 'info', 'unavailable'].includes(statusClass) ? statusClass : 'unavailable';
    }

    function updateWarnings(warnings) {
      const container = document.querySelector('[data-warnings]');
      if (!container) return;
      container.innerHTML = '';
      (warnings || []).forEach((warning) => {
        const item = document.createElement('div');
        item.className = 'concentradores-exec-warning';
        item.textContent = warning;
        container.appendChild(item);
      });
    }

    function updateReport(report) {
      updateWarnings(report?.meta?.warnings || []);
      const concentradores = report?.concentradores || {};

      Object.values(concentradores).forEach((concentrador) => {
        const panel = document.querySelector(`[data-concentrator="${CSS.escape(concentrador.key)}"]`);
        if (!panel) return;

        const operationStatus = panel.querySelector('[data-field="operation-status"]');
        if (operationStatus) {
          operationStatus.textContent = 'FO';
          operationStatus.classList.toggle('is-offline', Boolean(concentrador.fuera_operacion));
          operationStatus.classList.toggle('is-empty', !concentrador.fuera_operacion);
        }

        Object.values(concentrador.metricas || {}).forEach((metric) => {
          const card = panel.querySelector(`[data-metric="${CSS.escape(metric.key)}"]`);
          if (!card) return;

          card.className = `concentradores-exec-metric ${metricClass(metric)}`;
          const value = card.querySelector('[data-field="value"]');
          const status = card.querySelector('[data-field="status"]');
          if (value) value.textContent = metric.formatted || '-';
          if (status) status.textContent = metric?.status?.label || 'Sin dato';
        });
      });
    }

    async function refreshReport() {
      try {
        const response = await fetch(`data.php?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        updateReport(await response.json());
      } catch (error) {
        updateWarnings([`No se pudo actualizar concentradores: ${error.message}`]);
      }
    }

    window.setInterval(refreshReport, refreshMs);
  </script>
</body>

</html>
