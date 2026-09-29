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
$sourceIcon = static function (array $metric): string {
  return (string)($metric['source'] ?? '') === 'sqlserver' ? 'fa-gear' : 'fa-book-open';
};
$sourceTitle = static function (array $metric): string {
  return (string)($metric['source'] ?? '') === 'sqlserver' ? 'SQL Server / AVEVA' : 'MySQL 105';
};
$invertidoMetricGroups = [
  'General' => [
    'flujo_entrada_evaporador',
    'flujo_salida_evaporador',
    'temperatura_precalentamiento',
    'nivel_tanque_alimentacion',
  ],
  'Etapa 1' => [
    'flujo_etapa_1_2',
    'temperatura_etapa_1',
    'vacio_etapa_1',
    'presion_etapa_1',
    'nivel_etapa_1',
    'valvula_control_temperatura_etapa_1',
    'valvula_control_nivel_etapa_1',
  ],
  'Etapa 2' => [
    'flujo_etapa_2_3',
    'vacio_etapa_2',
    'presion_etapa_2',
    'nivel_etapa_2',
    'moyno_control_nivel_etapa_2',
  ],
  'Etapa 3' => [
    'vacio_etapa_3',
    'presion_etapa_3',
    'nivel_etapa_3',
    'moyno_control_nivel_etapa_3',
  ],
  'Agua / vapor' => [
    'temperatura_salida_agua',
    'presion_agua_enfriamiento',
    'presion_vapor',
  ],
  'Controles' => [
    'valvula_control_flujo_entrada',
    'valvula_control_precalentamiento',
    'valvula_control_presion',
  ],
];
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
      color-scheme: dark;
      --cc-bg: #08131b;
      --cc-panel: #13212b;
      --cc-inner: #1b2b36;
      --cc-line: #314451;
      --cc-text: #f4f7fa;
      --cc-muted: #cbd8e2;
      --cc-blue: #163354;
      --cc-green: #2e8b57;
      --cc-yellow: #facc15;
      --cc-red: #c94436;
      --cc-gray: #64748b;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; background: var(--cc-bg); }
    body { color: var(--cc-text); font-family: Inter, Arial, Helvetica, sans-serif; }
    .dashboard { width: 100%; min-height: 100vh; padding: 10px; background: var(--cc-bg); }
    .concentradores-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      margin-bottom: 10px;
    }
    .concentradores-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .concentradores-heading-icon {
      display: grid;
      flex: 0 0 auto;
      width: 44px;
      height: 44px;
      place-items: center;
      border-radius: 12px;
      color: #08131b;
      background: #f4f7fa;
      font-size: 22px;
    }
    .concentradores-heading h1 { margin: 0; font-size: clamp(28px, 2.2vw, 40px); line-height: 1; }
    .concentradores-heading p { margin: 4px 0 0; color: var(--cc-muted); font-size: clamp(13px, 1vw, 17px); }
    .concentradores-header-actions { display: flex; align-items: center; gap: 9px; }
    .concentradores-count, .back-btn {
      padding: 8px 13px;
      border: 0;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 800;
      white-space: nowrap;
    }
    .concentradores-count { color: #7dd3fc; background: #203957; }
    .back-btn { display: inline-flex; align-items: center; gap: 7px; color: var(--cc-text); background: var(--cc-inner); text-decoration: none; }
    .concentradores-exec-warning {
      display: flex;
      align-items: center;
      gap: 9px;
      margin-bottom: 9px;
      padding: 9px 12px;
      border-radius: 10px;
      color: #111827;
      background: var(--cc-yellow);
      font-size: 13px;
      font-weight: 800;
    }
    .concentradores-exec-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      align-items: start;
    }
    .concentradores-exec-panel {
      min-width: 0;
      overflow: hidden;
      padding: 9px;
      border: 1px solid var(--cc-line);
      border-radius: 15px;
      background: var(--cc-panel);
    }
    .concentradores-exec-panel[data-concentrator="invertido"] { grid-column: 1 / -1; }
    .concentradores-exec-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin: -9px -9px 9px;
      padding: 10px 12px;
      border-bottom: 1px solid var(--cc-line);
      background: #1d3442;
    }
    .concentradores-exec-head.ok { background: var(--cc-green); }
    .concentradores-exec-head.warning { color: #111827; background: var(--cc-yellow); }
    .concentradores-exec-head.danger { background: var(--cc-red); }
    .concentradores-exec-head.neutral,
    .concentradores-exec-head.info { background: var(--cc-blue); }
    .concentradores-exec-head.unavailable { background: var(--cc-gray); }
    .concentradores-exec-head h2 { margin: 0; color: inherit; font-size: clamp(25px, 1.8vw, 34px); line-height: 1; }
    .concentradores-exec-head span {
      display: inline-flex;
      min-width: 38px;
      min-height: 25px;
      align-items: center;
      justify-content: center;
      padding: 4px 9px;
      border-radius: 999px;
      color: var(--cc-text);
      background: var(--cc-gray);
      font-size: 12px;
      font-weight: 900;
    }
    .concentradores-exec-head span.is-offline { background: rgba(15,23,42,.34); }
    .concentradores-exec-metrics {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 7px;
    }
    .concentradores-exec-metric {
      display: grid;
      grid-template-columns: auto minmax(0, 1fr);
      align-items: center;
      gap: 9px;
      min-width: 0;
      min-height: 82px;
      padding: 7px 8px;
      border: 1px solid #28577e;
      border-radius: 11px;
      color: var(--cc-text);
      background: var(--cc-blue);
      transition: color .2s ease, background-color .2s ease;
    }
    .concentradores-exec-metric.ok { border-color: #43a36d; background: var(--cc-green); }
    .concentradores-exec-metric.warning { border-color: var(--cc-yellow); color: #111827; background: var(--cc-yellow); }
    .concentradores-exec-metric.danger { border-color: #e35d50; background: var(--cc-red); }
    .concentradores-exec-metric.neutral,
    .concentradores-exec-metric.info { border-color: #2563eb; background: var(--cc-blue); }
    .concentradores-exec-metric.unavailable { border-color: var(--cc-gray); background: #334653; }
    .concentradores-exec-metric > i {
      display: grid;
      width: 34px;
      height: 34px;
      place-items: center;
      border-radius: 9px;
      color: currentColor;
      background: rgba(255,255,255,.18);
      font-size: 17px;
    }
    .concentradores-exec-metric.warning > i { background: rgba(255,255,255,.45); }
    .concentradores-exec-metric-body { min-width: 0; }
    .concentradores-exec-metric-label {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 5px;
      min-width: 0;
      font-size: clamp(11px, .68vw, 13px);
      font-weight: 900;
      line-height: 1.08;
      text-transform: uppercase;
    }
    .concentradores-exec-metric-label > [data-field="label"] {
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .concentradores-exec-source {
      display: grid;
      flex: 0 0 auto;
      width: 17px;
      height: 17px;
      place-items: center;
      border-radius: 999px;
      background: rgba(255,255,255,.2);
    }
    .concentradores-exec-source i { font-size: 9px; }
    .concentradores-exec-metric-value {
      max-width: 100%;
      overflow: hidden;
      margin-top: 5px;
      font-size: clamp(20px, 1.45vw, 28px);
      font-variant-numeric: tabular-nums;
      font-weight: 900;
      line-height: 1;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .concentradores-exec-metric-status {
      display: flex;
      flex-wrap: wrap;
      gap: 3px 8px;
      margin-top: 5px;
      padding-top: 4px;
      border-top: 1px solid rgba(255,255,255,.28);
      font-size: 10px;
      font-weight: 800;
      line-height: 1.15;
    }
    .concentradores-exec-metric.warning .concentradores-exec-metric-status { border-top-color: rgba(17,24,39,.24); }
    .concentradores-exec-range { opacity: .88; }
    .concentradores-invertido-layout {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 8px;
    }
    .concentradores-stage {
      min-width: 0;
      padding: 8px;
      border: 1px solid var(--cc-line);
      border-radius: 12px;
      background: #0f1d26;
    }
    .concentradores-stage h3 {
      display: flex;
      align-items: center;
      gap: 7px;
      margin: 0 0 7px;
      font-size: 13px;
      font-weight: 900;
      text-transform: uppercase;
    }
    .concentradores-stage h3::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #38bdf8; }
    .concentradores-stage-metrics { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 7px; }
    .concentradores-exec-panel[data-concentrator="invertido"] .concentradores-exec-metric { min-height: 82px; }
    .concentradores-exec-panel[data-concentrator="invertido"] .concentradores-exec-metric-value { font-size: clamp(19px, 1.35vw, 25px); }
    body.display-mode .dashboard { padding: 8px; }
    @media (max-width: 1500px) {
      .concentradores-exec-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .concentradores-invertido-layout { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 820px) {
      .dashboard { padding: 7px; }
      .concentradores-header { align-items: flex-start; }
      .concentradores-heading-icon, .concentradores-count { display: none; }
      .concentradores-heading h1 { font-size: 28px; }
      .concentradores-heading p { font-size: 12px; }
      .back-btn { padding: 7px 10px; }
      .concentradores-exec-grid,
      .concentradores-invertido-layout { grid-template-columns: 1fr; }
    }
    @media (max-width: 520px) {
      .concentradores-exec-metrics,
      .concentradores-stage-metrics { grid-template-columns: 1fr; }
      .concentradores-exec-metric { min-height: 88px; }
      .concentradores-header-actions { align-self: center; }
      .back-btn span { display: none; }
    }
  </style>
</head>

<body>
  <div class="dashboard">
    <header class="concentradores-header">
      <div class="concentradores-heading">
        <span class="concentradores-heading-icon"><i class="fas fa-industry"></i></span>
        <div>
          <h1><?= $e($titulo) ?></h1>
          <p>Monitoreo en tiempo real · actualización cada <?= $e((int)ceil(((int)($meta['intervaloActualizacion'] ?? 60000)) / 1000)) ?> s</p>
        </div>
      </div>
      <div class="concentradores-header-actions">
        <span class="concentradores-count"><?= max(0, count($concentradores) - (isset($concentradores['invertido']) ? 1 : 0)) ?> equipos</span>
        <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i><span>Regresar</span></a>
      </div>
    </header>

    <div data-warnings>
      <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
        <div class="concentradores-exec-warning"><i class="fas fa-triangle-exclamation"></i><?= $e($warning) ?></div>
      <?php endforeach; ?>
    </div>

    <section class="concentradores-exec-grid" aria-label="Lecturas por concentrador">
      <?php foreach ($concentradores as $concentrador): ?>
        <?php $panelStatusClass = $metricClass(['status' => (array)($concentrador['status'] ?? [])]); ?>
        <article class="concentradores-exec-panel" data-concentrator="<?= $e($concentrador['key'] ?? '') ?>">
          <header class="concentradores-exec-head <?= $e($panelStatusClass) ?>">
            <h2><?= $e($concentrador['nombre'] ?? 'Concentrador') ?></h2>
            <span data-field="operation-status" class="<?= !empty($concentrador['fuera_operacion']) ? 'is-offline' : '' ?>">
              <?= $e(!empty($concentrador['fuera_operacion']) ? 'FO' : ($concentrador['status']['label'] ?? 'Lectura')) ?>
            </span>
          </header>
          <?php $metricas = (array)($concentrador['metricas'] ?? []); ?>
          <?php if ((string)($concentrador['key'] ?? '') === 'invertido'): ?>
            <div class="concentradores-invertido-layout">
              <?php $renderedMetrics = []; ?>
              <?php foreach ($invertidoMetricGroups as $groupTitle => $groupMetrics): ?>
                <section class="concentradores-stage">
                  <h3><?= $e($groupTitle) ?></h3>
                  <div class="concentradores-stage-metrics">
                    <?php foreach ($groupMetrics as $metricKey): ?>
                      <?php if (!isset($metricas[$metricKey])) continue; ?>
                      <?php $metric = (array)$metricas[$metricKey]; ?>
                      <?php $renderedMetrics[$metricKey] = true; ?>
                      <div class="concentradores-exec-metric <?= $e($metricClass($metric)) ?>" data-metric="<?= $e($metricKey) ?>">
                        <i class="fa-solid <?= $e($metricIcon($metricKey)) ?>"></i>
                        <div class="concentradores-exec-metric-body">
                          <div class="concentradores-exec-metric-label">
                            <span data-field="label"><?= $e($metric['label'] ?? $metricKey) ?></span>
                            <span class="concentradores-exec-source" data-field="source-icon" title="<?= $e($sourceTitle($metric)) ?>">
                              <i class="fa-solid <?= $e($sourceIcon($metric)) ?>"></i>
                            </span>
                          </div>
                          <div class="concentradores-exec-metric-value" data-field="value"><?= $e($metric['formatted'] ?? '-') ?></div>
                          <div class="concentradores-exec-metric-status"><span data-field="status"><?= $e($metric['status']['label'] ?? 'Sin dato') ?></span><?php if (trim((string)($metric['leyenda'] ?? '')) !== ''): ?><span class="concentradores-exec-range"><?= $e($metric['leyenda']) ?></span><?php endif; ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endforeach; ?>
              <?php $remainingMetrics = array_diff_key($metricas, $renderedMetrics); ?>
              <?php if (!empty($remainingMetrics)): ?>
                <section class="concentradores-stage">
                  <h3>Otros</h3>
                  <div class="concentradores-stage-metrics">
                    <?php foreach ($remainingMetrics as $metric): ?>
                      <?php $metric = (array)$metric; ?>
                      <?php $metricKey = (string)($metric['key'] ?? ''); ?>
                      <div class="concentradores-exec-metric <?= $e($metricClass($metric)) ?>" data-metric="<?= $e($metricKey) ?>">
                        <i class="fa-solid <?= $e($metricIcon($metricKey)) ?>"></i>
                        <div class="concentradores-exec-metric-body">
                          <div class="concentradores-exec-metric-label">
                            <span data-field="label"><?= $e($metric['label'] ?? $metricKey) ?></span>
                            <span class="concentradores-exec-source" data-field="source-icon" title="<?= $e($sourceTitle($metric)) ?>">
                              <i class="fa-solid <?= $e($sourceIcon($metric)) ?>"></i>
                            </span>
                          </div>
                          <div class="concentradores-exec-metric-value" data-field="value"><?= $e($metric['formatted'] ?? '-') ?></div>
                          <div class="concentradores-exec-metric-status"><span data-field="status"><?= $e($metric['status']['label'] ?? 'Sin dato') ?></span><?php if (trim((string)($metric['leyenda'] ?? '')) !== ''): ?><span class="concentradores-exec-range"><?= $e($metric['leyenda']) ?></span><?php endif; ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="concentradores-exec-metrics">
              <?php foreach ($metricas as $metric): ?>
                <?php $metricKey = (string)($metric['key'] ?? ''); ?>
                <div class="concentradores-exec-metric <?= $e($metricClass((array)$metric)) ?>" data-metric="<?= $e($metricKey) ?>">
                  <i class="fa-solid <?= $e($metricIcon($metricKey)) ?>"></i>
                  <div class="concentradores-exec-metric-body">
                    <div class="concentradores-exec-metric-label">
                      <span data-field="label"><?= $e($metric['label'] ?? $metricKey) ?></span>
                      <span class="concentradores-exec-source" data-field="source-icon" title="<?= $e($sourceTitle((array)$metric)) ?>">
                        <i class="fa-solid <?= $e($sourceIcon((array)$metric)) ?>"></i>
                      </span>
                    </div>
                    <div class="concentradores-exec-metric-value" data-field="value"><?= $e($metric['formatted'] ?? '-') ?></div>
                    <div class="concentradores-exec-metric-status"><span data-field="status"><?= $e($metric['status']['label'] ?? 'Sin dato') ?></span><?php if (trim((string)($metric['leyenda'] ?? '')) !== ''): ?><span class="concentradores-exec-range"><?= $e($metric['leyenda']) ?></span><?php endif; ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </section>
  </div>

  <script>
    const refreshMs = <?= (int)($meta['intervaloActualizacion'] ?? 60000) ?>;

    function metricClass(metric) {
      const statusClass = metric?.status?.class || 'unavailable';
      return ['ok', 'warning', 'danger', 'neutral', 'info', 'unavailable'].includes(statusClass) ? statusClass : 'unavailable';
    }

    function sourceIconClass(metric) {
      return metric?.source === 'sqlserver' ? 'fa-gear' : 'fa-book-open';
    }

    function sourceTitle(metric) {
      return metric?.source === 'sqlserver' ? 'SQL Server / AVEVA' : 'MySQL 105';
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

        const panelHead = panel.querySelector('.concentradores-exec-head');
        if (panelHead) panelHead.className = `concentradores-exec-head ${metricClass({ status: concentrador.status || {} })}`;
        const operationStatus = panel.querySelector('[data-field="operation-status"]');
        if (operationStatus) {
          operationStatus.textContent = concentrador.fuera_operacion ? 'FO' : (concentrador?.status?.label || 'Lectura');
          operationStatus.classList.toggle('is-offline', Boolean(concentrador.fuera_operacion));
        }

        Object.values(concentrador.metricas || {}).forEach((metric) => {
          const card = panel.querySelector(`[data-metric="${CSS.escape(metric.key)}"]`);
          if (!card) return;

          card.className = `concentradores-exec-metric ${metricClass(metric)}`;
          const label = card.querySelector('[data-field="label"]');
          const sourceIcon = card.querySelector('[data-field="source-icon"]');
          const value = card.querySelector('[data-field="value"]');
          const status = card.querySelector('[data-field="status"]');
          if (label) label.textContent = metric.label || metric.key || '';
          if (sourceIcon) {
            sourceIcon.title = sourceTitle(metric);
            sourceIcon.innerHTML = `<i class="fa-solid ${sourceIconClass(metric)}"></i>`;
          }
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
