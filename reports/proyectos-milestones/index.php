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

$titulo = (string)($report['titulo'] ?? 'Proyectos / Milestones');
$milestones = (array)($report['milestones'] ?? []);
$detailMilestone = $report['detailMilestone'] ?? null;
$meta = (array)($report['meta'] ?? []);
$statusFilter = (string)($meta['statusFilter'] ?? 'all');
$search = (string)($meta['search'] ?? '');
$counters = (array)($meta['counters'] ?? []);
$version = (int)($report['version'] ?? time());
$baseQuery = [];
if ($statusFilter !== '' && $statusFilter !== 'all') {
  $baseQuery['estatus'] = $statusFilter;
}
if ($search !== '') {
  $baseQuery['q'] = $search;
}
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
    .projects-shell {
      display: grid;
      gap: 20px;
    }
    .projects-toolbar,
    .projects-detail,
    .projects-empty {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }
    .projects-toolbar {
      padding: 18px;
    }
    .projects-toolbar-top {
      display: flex;
      justify-content: space-between;
      gap: 14px;
      flex-wrap: wrap;
      align-items: center;
    }
    .projects-search {
      position: relative;
      min-width: 260px;
      flex: 1;
      max-width: 420px;
    }
    .projects-search input {
      width: 100%;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      padding: 10px 14px 10px 38px;
      font: inherit;
    }
    .projects-search i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
    }
    .projects-filters {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 14px;
    }
    .projects-filter-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border-radius: 999px;
      border: 1px solid #dbe2ea;
      padding: 9px 14px;
      text-decoration: none;
      color: #334155;
      font-weight: 700;
      background: #fff;
      white-space: nowrap;
      transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }
    .projects-filter-pill:hover {
      border-color: #cbd5e1;
      background: #f8fafc;
    }
    .projects-filter-pill.active {
      border-color: #93c5fd;
      background: #eff6ff;
      color: #1d4ed8;
      box-shadow: 0 8px 16px rgba(37, 99, 235, 0.12);
      transform: translateY(-1px);
    }
    .projects-filter-pill-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 28px;
      height: 24px;
      padding: 0 8px;
      border-radius: 999px;
      background: #f1f5f9;
      color: #64748b;
      font-weight: 800;
      font-size: 0.78rem;
    }
    .projects-filter-pill.active .projects-filter-pill-count {
      background: #ffffff;
      color: #1d4ed8;
    }
    .projects-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 18px;
    }
    .project-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
      padding: 18px;
      display: grid;
      gap: 14px;
    }
    .project-card-link {
      color: inherit;
      text-decoration: none;
      display: block;
    }
    .project-card-top {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-start;
    }
    .project-card-title {
      font-size: 1rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.35;
    }
    .project-card-sub {
      margin-top: 6px;
      color: #64748b;
      font-size: 0.84rem;
      line-height: 1.4;
    }
    .project-percent {
      font-size: 2.2rem;
      font-weight: 800;
      line-height: 1;
      color: #0f172a;
      text-align: right;
      white-space: nowrap;
    }
    .project-progress {
      height: 12px;
      border-radius: 999px;
      background: #e2e8f0;
      overflow: hidden;
    }
    .project-progress-bar {
      height: 100%;
      border-radius: 999px;
    }
    .project-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      color: #475569;
      font-size: 0.84rem;
    }
    .project-chip {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.78rem;
      font-weight: 800;
      white-space: nowrap;
    }
    .project-stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }
    .project-stat {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 12px;
      background: #f8fafc;
    }
    .project-stat-label {
      color: #64748b;
      font-size: 0.76rem;
      font-weight: 700;
    }
    .project-stat-value {
      display: block;
      margin-top: 6px;
      font-size: 1.2rem;
      font-weight: 800;
      color: #0f172a;
    }
    .project-detail-head {
      padding: 20px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
      align-items: flex-start;
    }
    .project-back {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: #334155;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      padding: 10px 14px;
      font-weight: 700;
      background: #fff;
    }
    .project-detail-body {
      padding: 20px;
      display: grid;
      gap: 20px;
    }
    .project-summary-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
    }
    .project-summary-box {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 16px;
      background: #f8fafc;
    }
    .project-summary-box strong {
      display: block;
      font-size: 1.55rem;
      color: #0f172a;
      margin-top: 6px;
    }
    .project-summary-box span {
      color: #64748b;
      font-size: 0.82rem;
      font-weight: 700;
    }
    .project-task-table {
      width: 100%;
      border-collapse: collapse;
    }
    .project-task-table th,
    .project-task-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      vertical-align: top;
    }
    .project-task-table th {
      color: #475569;
      font-size: 0.8rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.02em;
      background: #f8fafc;
    }
    .projects-empty {
      padding: 28px 20px;
      text-align: center;
      color: #64748b;
    }
    @media (max-width: 1180px) {
      .projects-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .project-summary-grid,
      .project-stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
    @media (max-width: 760px) {
      .projects-grid,
      .project-summary-grid,
      .project-stats {
        grid-template-columns: 1fr;
      }
      .project-card-top {
        flex-direction: column;
      }
      .project-percent {
        text-align: left;
      }
      .project-task-wrap {
        overflow-x: auto;
      }
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a href="../index.php" class="back-btn" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
            <i class="fas fa-arrow-left"></i>
            Regresar al inicio
          </a>
        </div>
        <h1><i class="fas fa-diagram-project" style="margin-right:12px;"></i><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-flag-checkered"></i> Avance por milestone con tareas aprobadas vs totales</span>
          <span class="badge"><i class="fas fa-filter"></i> Filtros por estatus del proyecto</span>
        </div>
      </div>
    </div>

    <?php foreach (($meta['warnings'] ?? []) as $warning): ?>
      <div class="projects-empty" style="background:#fffbeb; border:1px solid #fcd34d; border-radius:16px; color:#92400e;">
        <i class="fas fa-triangle-exclamation"></i>
        <?= htmlspecialchars((string)$warning) ?>
      </div>
    <?php endforeach; ?>

    <?php if (is_array($detailMilestone)): ?>
      <div class="projects-detail">
        <div class="project-detail-head">
          <div>
            <div class="project-card-title"><?= htmlspecialchars((string)($detailMilestone['titulo'] ?? '')) ?></div>
            <div class="project-card-sub">
              <?= htmlspecialchars((string)($detailMilestone['objetivo'] ?? '')) ?> | <?= htmlspecialchars((string)($detailMilestone['estrategia'] ?? '')) ?><br>
              Responsable: <?= htmlspecialchars((string)($detailMilestone['responsable'] ?? 'Sin asignar')) ?>
            </div>
          </div>
          <a href="?<?= htmlspecialchars(http_build_query($baseQuery)) ?>" class="project-back">
            <i class="fas fa-arrow-left"></i>
            Volver al resumen
          </a>
        </div>
        <div class="project-detail-body">
          <div class="project-summary-grid">
            <div class="project-summary-box">
              <span>Avance</span>
              <strong><?= htmlspecialchars((string)($detailMilestone['avance_label'] ?? '0%')) ?></strong>
            </div>
            <div class="project-summary-box">
              <span>Tareas</span>
              <strong><?= htmlspecialchars((string)($detailMilestone['task_summary']['total'] ?? 0)) ?></strong>
            </div>
            <div class="project-summary-box">
              <span>Aprobadas</span>
              <strong><?= htmlspecialchars((string)($detailMilestone['task_summary']['aprobadas'] ?? 0)) ?></strong>
            </div>
            <div class="project-summary-box">
              <span>Vencidas</span>
              <strong><?= htmlspecialchars((string)($detailMilestone['task_summary']['vencidas'] ?? 0)) ?></strong>
            </div>
          </div>

          <div class="project-task-wrap">
            <table class="project-task-table">
              <thead>
                <tr>
                  <th>Tarea</th>
                  <th>Responsable</th>
                  <th>Fechas</th>
                  <th>Estatus</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (($detailMilestone['tasks'] ?? []) as $task): ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars((string)($task['titulo'] ?? '')) ?></strong>
                      <?php if (($task['descripcion'] ?? '') !== ''): ?>
                        <div style="color:#64748b; font-size:0.84rem; margin-top:4px;"><?= htmlspecialchars((string)$task['descripcion']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)($task['responsable'] ?? 'Sin asignar')) ?></td>
                    <td>
                      Inicio: <?= htmlspecialchars((string)($task['fecha_inicio'] ?? '-')) ?><br>
                      Fin: <?= htmlspecialchars((string)($task['fecha_fin'] ?? '-')) ?>
                    </td>
                    <td>
                      <span class="project-chip" style="background: <?= htmlspecialchars((string)($task['visual']['bg'] ?? 'rgba(148, 163, 184, 0.12)')) ?>; color: <?= htmlspecialchars((string)($task['visual']['color'] ?? '#94a3b8')) ?>;">
                        <i class="fas fa-circle"></i>
                        <?= htmlspecialchars((string)($task['visual']['label'] ?? ($task['estatus_label'] ?? '-'))) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="projects-shell">
        <form method="get" class="projects-toolbar">
          <div class="projects-toolbar-top">
            <div>
              <strong style="display:block; margin-bottom:4px;">Resumen de proyectos</strong>
              <span style="color:#64748b; font-size:0.9rem;">Milestones con porcentaje de avance basado en tareas aprobadas.</span>
            </div>
            <div class="projects-search">
              <i class="fas fa-search"></i>
              <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar proyecto, objetivo o responsable">
            </div>
          </div>
          <div class="projects-filters">
            <?php
            $filters = [
              'all' => ['label' => 'Todos', 'count' => $counters['todos'] ?? 0],
              '1' => ['label' => 'Activo', 'count' => $counters['1'] ?? 0],
              '2' => ['label' => 'Cerrado', 'count' => $counters['2'] ?? 0],
              '3' => ['label' => 'Inactivo', 'count' => $counters['3'] ?? 0],
              '4' => ['label' => 'Pausado', 'count' => $counters['4'] ?? 0],
              '5' => ['label' => 'Descontinuado', 'count' => $counters['5'] ?? 0],
            ];
            foreach ($filters as $key => $filter):
              $key = (string)$key;
              $query = [];
              if ($key !== 'all') {
                $query['estatus'] = $key;
              }
              if ($search !== '') {
                $query['q'] = $search;
              }
              $active = ($statusFilter === $key) || ($statusFilter === '' && $key === 'all');
            ?>
              <a href="?<?= htmlspecialchars(http_build_query($query)) ?>" class="projects-filter-pill<?= $active ? ' active' : '' ?>">
                <?= htmlspecialchars($filter['label']) ?>
                <span class="projects-filter-pill-count"><?= htmlspecialchars((string)$filter['count']) ?></span>
              </a>
            <?php endforeach; ?>
            <button type="submit" style="display:none;">Buscar</button>
          </div>
        </form>

        <?php if (empty($milestones)): ?>
          <div class="projects-empty">
            <i class="fas fa-inbox" style="font-size:2rem; margin-bottom:10px;"></i>
            <div>No hay proyectos para este filtro.</div>
          </div>
        <?php else: ?>
          <div class="projects-grid">
            <?php foreach ($milestones as $milestone): ?>
              <?php
              $query = $baseQuery;
              $query['milestone_id'] = $milestone['milestone_id'];
              $detailUrl = '?' . http_build_query($query);
              $visual = (array)($milestone['visual'] ?? []);
              ?>
              <a href="<?= htmlspecialchars($detailUrl) ?>" class="project-card-link">
                <article class="project-card">
                  <div class="project-card-top">
                    <div>
                      <div class="project-card-title"><?= htmlspecialchars((string)($milestone['titulo'] ?? '')) ?></div>
                      <div class="project-card-sub">
                        <?= htmlspecialchars((string)($milestone['objetivo'] ?? '')) ?><br>
                        <?= htmlspecialchars((string)($milestone['responsable'] ?? 'Sin asignar')) ?>
                      </div>
                    </div>
                    <div class="project-percent"><?= htmlspecialchars((string)($milestone['avance_label'] ?? '0%')) ?></div>
                  </div>

                  <div class="project-progress">
                    <div class="project-progress-bar" style="width: <?= htmlspecialchars((string)($milestone['avance'] ?? 0)) ?>%; background: <?= htmlspecialchars((string)($visual['color'] ?? '#3b82f6')) ?>;"></div>
                  </div>

                  <div class="project-meta">
                    <span class="project-chip" style="background: <?= htmlspecialchars((string)($visual['bg'] ?? 'rgba(148, 163, 184, 0.12)')) ?>; color: <?= htmlspecialchars((string)($visual['color'] ?? '#94a3b8')) ?>;">
                      <i class="fas fa-circle"></i>
                      <?= htmlspecialchars((string)($visual['label'] ?? '-')) ?>
                    </span>
                    <span class="project-chip" style="background: rgba(59, 130, 246, 0.08); color:#2563eb;">
                      <i class="fas fa-user-circle"></i>
                      <?= htmlspecialchars((string)($milestone['estatus_label'] ?? '-')) ?>
                    </span>
                  </div>

                  <div class="project-stats">
                    <div class="project-stat">
                      <div class="project-stat-label">Tareas</div>
                      <span class="project-stat-value"><?= htmlspecialchars((string)($milestone['total_tareas'] ?? 0)) ?></span>
                    </div>
                    <div class="project-stat">
                      <div class="project-stat-label">Aprobadas</div>
                      <span class="project-stat-value"><?= htmlspecialchars((string)($milestone['finalizadas'] ?? 0)) ?></span>
                    </div>
                    <div class="project-stat">
                      <div class="project-stat-label">Vencidas</div>
                      <span class="project-stat-value"><?= htmlspecialchars((string)($milestone['vencidas'] ?? 0)) ?></span>
                    </div>
                    <div class="project-stat">
                      <div class="project-stat-label">En revisión</div>
                      <span class="project-stat-value"><?= htmlspecialchars((string)($milestone['revision'] ?? 0)) ?></span>
                    </div>
                  </div>
                </article>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
