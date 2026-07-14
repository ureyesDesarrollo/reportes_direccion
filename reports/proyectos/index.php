<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

$titulo = (string)($report['titulo'] ?? 'Tablero Directivo de Proyectos');
$areas = (array)($report['areas'] ?? []);
$meta = (array)($report['meta'] ?? []);
$version = (int)($report['version'] ?? time());

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$priorityClass = static fn($key): string => in_array((string)$key, ['very-high', 'high', 'medium', 'low'], true) ? (string)$key : 'blank';
$statusClass = static fn($key): string => preg_match('/^[a-z0-9-]+$/', (string)$key) === 1 ? (string)$key : 'blank';
$fmtPercent = static fn($value): string => is_numeric($value) ? rtrim(rtrim(n((float)$value, 1), '0'), '.') . '%' : '-';
$fmtNumber = static fn($value): string => is_numeric($value) ? rtrim(rtrim(n((float)$value, 1), '0'), '.') : '-';
$isDoneProject = static function (array $item): bool {
  $key = strtolower(trim((string)($item['status_key'] ?? '')));
  $label = mb_strtolower(trim((string)($item['status_label'] ?? '')), 'UTF-8');

  return $key === 'done' || $label === 'terminado';
};
$diamondClass = static function ($value): string {
  if (!is_numeric($value)) {
    return 'unknown';
  }

  $number = (float)$value;
  if ($number >= 95) {
    return 'excellent';
  }
  if ($number >= 90) {
    return 'good';
  }
  if ($number >= 80) {
    return 'watch';
  }
  if ($number >= 70) {
    return 'risk';
  }

  return 'critical';
};

?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= $e($titulo) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap"
    rel="stylesheet" />
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <style>
    :root {
      --bg: #eef2f6;
      --panel: #ffffff;
      --ink: #172033;
      --muted: #667085;
      --line: #d9e0ea;
      --head: #f4f7fb;
      --green: #16865c;
      --blue: #276ef1;
      --orange: #cc6b2c;
      --red: #c9434d;
      --indigo: #5d4899;
      --shadow: 0 18px 45px rgba(23, 32, 51, 0.1);
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      min-width: 1180px;
      color: var(--ink);
      background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.78), rgba(255, 255, 255, 0) 270px),
        var(--bg);
      font-family: Inter, Arial, sans-serif;
    }

    .dashboard-shell {
      width: min(1880px, calc(100vw - 32px));
      margin: 0 auto;
      padding: 24px 0 36px;
    }

    .dashboard-header {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 24px;
      margin-bottom: 18px;
    }

    .top-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .back-btn,
    .admin-btn {
      display: inline-flex;
      align-items: center;
      min-height: 34px;
      padding: 8px 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      color: #334155;
      background: rgba(255, 255, 255, 0.82);
      font-size: 13px;
      font-weight: 800;
      text-decoration: none;
    }

    .admin-btn {
      color: #075985;
      border-color: #bfdbfe;
      background: #eff6ff;
    }

    .eyebrow {
      margin: 0 0 4px;
      color: #506077;
      font-size: 12px;
      font-weight: 800;
      letter-spacing: 0;
      text-transform: uppercase;
    }

    h1 {
      margin: 0;
      font-size: 34px;
      line-height: 1;
    }

    .header-summary {
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--muted);
      font-size: 13px;
      white-space: nowrap;
    }

    .header-summary span {
      display: inline-flex;
      align-items: baseline;
      gap: 6px;
      min-height: 34px;
      padding: 8px 12px;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.72);
    }

    .header-summary strong {
      color: var(--ink);
      font-size: 16px;
    }

    .warning {
      margin: 0 0 14px;
      padding: 10px 12px;
      border: 1px solid #f59e0b;
      border-radius: 8px;
      color: #78350f;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 700;
    }

    .board-grid {
      display: grid;
      gap: 14px;
      align-items: start;
    }

    .area-panel {
      --accent: var(--blue);
      display: block;
      width: 100%;
      overflow: hidden;
      border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--line));
      border-radius: 8px;
      background: var(--panel);
      box-shadow: var(--shadow);
    }

    .accent-green {
      --accent: var(--green);
    }

    .accent-blue {
      --accent: var(--blue);
    }

    .accent-orange {
      --accent: var(--orange);
    }

    .accent-red {
      --accent: var(--red);
    }

    .accent-indigo {
      --accent: var(--indigo);
    }

    .area-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 13px 14px;
      color: #fff;
      background: var(--accent);
    }

    .area-header h2 {
      margin: 0;
      font-size: 17px;
      font-weight: 800;
      line-height: 1.1;
      text-transform: uppercase;
    }

    .area-header-main {
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 0;
    }

    .area-header span {
      font-size: 12px;
      font-weight: 700;
      opacity: 0.9;
      white-space: nowrap;
    }

    .print-area-btn {
      min-height: 28px;
      padding: 6px 10px;
      border: 1px solid rgba(255, 255, 255, 0.72);
      border-radius: 8px;
      color: #ffffff;
      background: rgba(255, 255, 255, 0.14);
      font: inherit;
      font-size: 12px;
      font-weight: 900;
      cursor: pointer;
    }

    .print-area-btn:hover {
      background: rgba(255, 255, 255, 0.24);
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      min-width: 920px;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 13px;
    }

    thead th {
      padding: 8px 6px;
      border-bottom: 1px solid var(--line);
      color: #445065;
      background: var(--head);
      font-size: 14px;
      font-weight: 900;
      text-align: left;
      text-transform: uppercase;
    }

    td {
      height: 38px;
      padding: 7px 6px;
      border-bottom: 1px solid #e7ecf3;
      color: #283448;
      line-height: 1.25;
      vertical-align: middle;
      overflow-wrap: anywhere;
    }

    tbody tr:nth-child(even) td {
      background: #fbfcfe;
    }

    tbody tr:hover td {
      background: color-mix(in srgb, var(--accent) 7%, #ffffff);
    }

    .col-project {
      width: 24%;
      font-weight: 800;
    }

    .col-priority {
      width: 8%;
    }

    .col-owner {
      width: 10%;
    }

    .col-date {
      width: 5.5%;
    }

    .col-progress {
      width: 6%;
      text-align: center;
    }

    .col-diamond {
      width: 8%;
      text-align: center;
    }

    .col-benefit {
      width: 19%;
    }

    .col-status {
      width: 8%;
    }

    .priority,
    .status {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 22px;
      max-width: 100%;
      padding: 4px 7px;
      border-radius: 999px;
      font-size: 13px;
      font-weight: 800;
      line-height: 1;
      white-space: normal;
      text-align: center;
    }

    .priority.high {
      color: #9f1d2f;
      background: #ffe5e9;
    }

    .priority.medium {
      color: #8b4a0f;
      background: #fff0cc;
    }

    .priority.low {
      color: #166247;
      background: #dff7ea;
    }

    .priority.blank {
      display: none;
    }

    .priority.very-high {
      color: #ffffff;
      background: #b91c1c;
    }

    .status.progress {
      color: #075985;
      background: #dff3ff;
    }

    .status.not-started {
      color: #794011;
      background: #ffe8c7;
    }

    .status.waiting {
      color: #5b6475;
      background: #eef1f5;
    }

    .status.done {
      color: #0f6b4b;
      background: #dff8eb;
    }

    .status.percent {
      color: #5d4899;
      background: #eee7ff;
    }

    .status.blank {
      color: #7a8392;
      background: #f4f6f9;
    }

    .diamond {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
      min-width: 70px;
      min-height: 24px;
      padding: 4px 8px;
      border-radius: 999px;
      font-size: 10px;
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
    }

    .diamond.excellent {
      color: #155e75;
      background: #cffafe;
    }

    .diamond.good {
      color: #166247;
      background: #dff7ea;
    }

    .diamond.watch {
      color: #8b4a0f;
      background: #fff0cc;
    }

    .diamond.risk {
      color: #9a3412;
      background: #fed7aa;
    }

    .diamond.critical {
      color: #9f1d2f;
      background: #ffe5e9;
    }

    .diamond.unknown {
      color: #5b6475;
      background: #eef1f5;
    }

    .empty-row td {
      color: var(--muted);
      font-weight: 600;
      text-align: center;
    }

    .done-divider td {
      height: auto;
      padding: 8px 10px;
      color: #64748b;
      background: #eef2f6;
      border-top: 1px solid #d9e0ea;
      border-bottom: 1px solid #d9e0ea;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: 0;
      text-transform: uppercase;
    }

    @media print {
      @page {
        size: landscape;
        margin: 8mm;
      }

      *,
      *::before,
      *::after {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      html,
      body {
        min-width: 0 !important;
        background: #ffffff !important;
      }

      .dashboard-shell {
        width: 100% !important;
        padding: 0 !important;
      }

      body.printing-area .top-actions,
      body.printing-area .dashboard-header,
      body.printing-area .warning,
      body.printing-area .print-area-btn {
        display: none !important;
      }

      body.printing-area .board-grid {
        display: block !important;
      }

      body.printing-area .area-panel {
        display: none !important;
      }

      body.printing-area .area-panel.is-print-target {
        display: block !important;
        overflow: visible !important;
        border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--line)) !important;
        box-shadow: none !important;
      }

      body.printing-area .table-wrap {
        overflow: visible !important;
      }

      body.printing-area table {
        min-width: 0 !important;
        font-size: 10px !important;
      }

      body.printing-area thead th {
        padding: 5px 4px !important;
        font-size: 8px !important;
      }

      body.printing-area td {
        height: auto !important;
        padding: 5px 4px !important;
        font-size: 9px !important;
      }

      body.printing-area .priority,
      body.printing-area .status,
      body.printing-area .diamond {
        min-height: 18px !important;
        padding: 3px 5px !important;
        font-size: 8px !important;
      }
    }

    @media (max-width: 1480px) {
      body {
        min-width: 1000px;
      }

      .dashboard-shell {
        width: min(1360px, calc(100vw - 24px));
      }

    }

    @media (max-width: 760px) {
      body {
        min-width: 0;
      }

      .dashboard-shell {
        width: 100%;
        padding: 16px 12px 28px;
      }

      .dashboard-header {
        align-items: start;
        flex-direction: column;
      }

      h1 {
        font-size: 28px;
      }

      .header-summary {
        width: 100%;
        overflow-x: auto;
        padding-bottom: 2px;
      }

    }
  </style>
</head>

<body>
  <main class="dashboard-shell">
    <div class="top-actions">
      <a class="back-btn" href="../index.php">Regresar al inicio</a>
      <a class="admin-btn" href="admin.php">Administrar proyectos</a>
    </div>

    <header class="dashboard-header">
      <div>
        <p class="eyebrow">Direccion de proyectos</p>
        <h1>Tablero directivo</h1>
      </div>
      <div class="header-summary" aria-label="Resumen general">
        <span><strong><?= $e($meta['areaCount'] ?? count($areas)) ?></strong> areas</span>
        <span><strong><?= $e($meta['projectCount'] ?? 0) ?></strong> proyectos</span>
        <span><strong><?= $e(($meta['source'] ?? '') === 'database' ? 'DB' : 'Local') ?></strong> fuente</span>
      </div>
    </header>

    <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
      <div class="warning"><?= $e($warning) ?></div>
    <?php endforeach; ?>

    <section class="board-grid" aria-label="Tablero por area">
      <?php foreach ($areas as $areaIndex => $area): ?>
          <article id="project-area-<?= $e($areaIndex) ?>" class="area-panel accent-<?= $e($area['accent'] ?? 'indigo') ?>">
            <header class="area-header">
              <div class="area-header-main">
                <h2><?= $e($area['nombre'] ?? 'Area') ?></h2>
                <span><?= count((array)($area['items'] ?? [])) ?> proyecto(s)</span>
              </div>
              <button class="print-area-btn" type="button" data-print-target="project-area-<?= $e($areaIndex) ?>">Imprimir</button>
            </header>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th class="col-project">Proyecto</th>
                    <th class="col-priority">Prioridad</th>
                    <th class="col-owner">Responsable</th>
                    <th class="col-date">Inicio</th>
                    <th class="col-date">Compromiso</th>
                    <th class="col-progress">Plan</th>
                    <th class="col-progress">Real</th>
                    <th class="col-diamond">Diamante</th>
                    <th class="col-benefit">Beneficio</th>
                    <th class="col-status">Estatus</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($area['items'])): ?>
                    <tr class="empty-row">
                      <td colspan="10">Sin proyectos registrados</td>
                    </tr>
                  <?php else: ?>
                    <?php $doneDividerShown = false; ?>
                    <?php foreach ((array)$area['items'] as $item): ?>
                      <?php if (!$doneDividerShown && $isDoneProject((array)$item)): ?>
                        <tr class="done-divider">
                          <td colspan="10">Proyectos terminados</td>
                        </tr>
                        <?php $doneDividerShown = true; ?>
                      <?php endif; ?>
                      <tr>
                        <td class="col-project"><?= $e($item['nombre'] ?? '') ?></td>
                        <td class="col-priority">
                          <?php if (!empty($item['prioridad_label'])): ?>
                            <span class="priority <?= $e($priorityClass($item['prioridad_key'] ?? '')) ?>"><?= $e($item['prioridad_label']) ?></span>
                          <?php endif; ?>
                        </td>
                        <td class="col-owner"><?= $e($item['responsable'] ?? '') ?></td>
                        <td class="col-date"><?= $e($item['inicio'] ?? '') ?></td>
                        <td class="col-date"><?= $e($item['cierre'] ?? '') ?></td>
                        <td class="col-progress"><?= $e($fmtPercent($item['avance_planeado'] ?? null)) ?></td>
                        <td class="col-progress"><?= $e($fmtPercent($item['avance_real'] ?? null)) ?></td>
                        <td class="col-diamond">
                          <span class="diamond <?= $e($diamondClass($item['indice_diamante'] ?? null)) ?>">💎 <?= $e($fmtNumber($item['indice_diamante'] ?? null)) ?></span>
                        </td>
                        <td class="col-benefit"><?= $e($item['beneficio_principal'] ?? '') ?></td>
                        <td class="col-status"><span class="status <?= $e($statusClass($item['status_key'] ?? 'blank')) ?>"><?= $e($item['status_label'] ?? 'Pendiente') ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </article>
      <?php endforeach; ?>
    </section>
  </main>
  <script>
    document.addEventListener('click', (event) => {
      const button = event.target.closest('[data-print-target]');
      if (!button) return;

      const target = document.getElementById(button.dataset.printTarget || '');
      if (!target) return;

      const cleanup = () => {
        document.body.classList.remove('printing-area');
        target.classList.remove('is-print-target');
      };

      document.querySelectorAll('.area-panel.is-print-target').forEach((panel) => {
        panel.classList.remove('is-print-target');
      });
      target.classList.add('is-print-target');
      document.body.classList.add('printing-area');
      window.addEventListener('afterprint', cleanup, { once: true });
      window.print();
    });
  </script>
</body>

</html>
