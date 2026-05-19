<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

$milestoneSelected = (int)($_GET['milestone'] ?? 0);
if ($milestoneSelected <= 0) {
  header('Location: ./index.php');
  exit;
}

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el detalle</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

$detalle = $report['detalle_proyecto'] ?? null;
$tareas = (array)($report['detalle_tareas'] ?? []);
if (!is_array($detalle) || empty($detalle)) {
  http_response_code(404);
  echo '<h1>Proyecto no encontrado</h1>';
  exit;
}

$version = (int)($report['version'] ?? time());
$statusFilter = trim((string)($_GET['estatus'] ?? ''));
$responsableFilter = trim((string)($_GET['responsable'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$backQuery = [];
if ($statusFilter !== '') {
  $backQuery['estatus'] = $statusFilter;
}
if ($responsableFilter !== '') {
  $backQuery['responsable'] = $responsableFilter;
}
if ($searchTerm !== '') {
  $backQuery['q'] = $searchTerm;
}
$backHref = './index.php' . (!empty($backQuery) ? ('?' . http_build_query($backQuery)) : '');

$projectStatus = (array)($detalle['milestone_status'] ?? ['label' => 'Sin estatus', 'color' => '#94a3b8']);
$projectHealth = (array)($detalle['health'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']);

$completadas = [];
$pendientes = [];
foreach ($tareas as $task) {
  $visual = (array)($task['status_visual'] ?? []);
  $label = (string)($visual['label'] ?? '');
  if (in_array($label, ['Terminada', 'Completada tarde'], true)) {
    $completadas[] = $task;
  } else {
    $pendientes[] = $task;
  }
}

usort($completadas, static function (array $a, array $b): int {
  return strcmp((string)($b['completada_en'] ?? ''), (string)($a['completada_en'] ?? ''));
});
usort($pendientes, static function (array $a, array $b): int {
  $dateA = (string)($a['fecha_fin'] ?? '');
  $dateB = (string)($b['fecha_fin'] ?? '');
  if ($dateA === $dateB) {
    return strcmp((string)($a['titulo'] ?? ''), (string)($b['titulo'] ?? ''));
  }
  if ($dateA === '') {
    return 1;
  }
  if ($dateB === '') {
    return -1;
  }
  return strcmp($dateA, $dateB);
});

$actividadReciente = array_slice($completadas, 0, 3);
$planTrabajo = array_slice($pendientes, 0, 6);
$listaTrabajo = array_slice($completadas, 0, 6);

$parseDate = static function (?string $value): ?DateTimeImmutable {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }

  try {
    return new DateTimeImmutable(substr($value, 0, 10));
  } catch (Throwable $e) {
    return null;
  }
};

$timelineStart = $parseDate((string)($detalle['fecha_inicio'] ?? ''));
$timelineEnd = $parseDate((string)($detalle['fecha_fin'] ?? ''));

foreach ($tareas as $task) {
  $taskStart = $parseDate((string)($task['fecha_inicio'] ?? ''));
  $taskEnd = $parseDate((string)($task['fecha_fin'] ?? ''));
  if ($timelineStart === null || ($taskStart !== null && $taskStart < $timelineStart)) {
    $timelineStart = $taskStart;
  }
  if ($timelineEnd === null || ($taskEnd !== null && $taskEnd > $timelineEnd)) {
    $timelineEnd = $taskEnd;
  }
}

if ($timelineStart === null) {
  $timelineStart = new DateTimeImmutable('today');
}
if ($timelineEnd === null || $timelineEnd < $timelineStart) {
  $timelineEnd = $timelineStart;
}

$timelineDays = [];
$timelineMonths = [];
$cursor = $timelineStart;
$monthNames = [
  1 => 'Ene',
  2 => 'Feb',
  3 => 'Mar',
  4 => 'Abr',
  5 => 'May',
  6 => 'Jun',
  7 => 'Jul',
  8 => 'Ago',
  9 => 'Sep',
  10 => 'Oct',
  11 => 'Nov',
  12 => 'Dic',
];
$weekdayNames = [
  1 => 'L',
  2 => 'M',
  3 => 'M',
  4 => 'J',
  5 => 'V',
  6 => 'S',
  7 => 'D',
];

while ($cursor <= $timelineEnd) {
  $monthKey = $cursor->format('Y-m');
  if (!isset($timelineMonths[$monthKey])) {
    $timelineMonths[$monthKey] = [
      'label' => $monthNames[(int)$cursor->format('n')] . ' ' . $cursor->format('Y'),
      'span' => 0,
    ];
  }
  $timelineMonths[$monthKey]['span']++;
  $timelineDays[] = [
    'date' => $cursor->format('Y-m-d'),
    'day' => $cursor->format('d'),
    'weekday' => $weekdayNames[(int)$cursor->format('N')] ?? '',
    'isToday' => $cursor->format('Y-m-d') === date('Y-m-d'),
  ];
  $cursor = $cursor->modify('+1 day');
}

$timelineTotalDays = max(1, (int)$timelineStart->diff($timelineEnd)->format('%a') + 1);
$timelineMonthBands = [];
$monthCursor = $timelineStart->modify('first day of this month');
while ($monthCursor <= $timelineEnd) {
  $bandStart = $monthCursor < $timelineStart ? $timelineStart : $monthCursor;
  $monthEnd = $monthCursor->modify('last day of this month');
  $bandEnd = $monthEnd > $timelineEnd ? $timelineEnd : $monthEnd;
  $startOffset = (int)$timelineStart->diff($bandStart)->format('%a');
  $spanDays = max(1, (int)$bandStart->diff($bandEnd)->format('%a') + 1);
  $timelineMonthBands[] = [
    'label' => ($monthNames[(int)$monthCursor->format('n')] ?? $monthCursor->format('M')) . ' ' . $monthCursor->format('Y'),
    'left' => ($startOffset / $timelineTotalDays) * 100,
    'width' => ($spanDays / $timelineTotalDays) * 100,
  ];
  $monthCursor = $monthCursor->modify('first day of next month');
}

$timelineWeekMarkers = [];
$weekCursor = $timelineStart;
while ($weekCursor <= $timelineEnd) {
  $offsetDays = (int)$timelineStart->diff($weekCursor)->format('%a');
  $timelineWeekMarkers[] = [
    'label' => $weekCursor->format('d M'),
    'left' => ($offsetDays / $timelineTotalDays) * 100,
  ];
  $weekCursor = $weekCursor->modify('+7 days');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars((string)$detalle['milestone']) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <style>
    .project-detail-page,
    .project-detail-panel,
    .project-detail-card,
    .project-detail-list {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }

    .project-detail-page {
      padding: 22px;
    }

    .project-detail-layout {
      display: grid;
      gap: 20px;
      margin-top: 22px;
    }

    .project-detail-top {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
      gap: 20px;
      align-items: start;
    }

    .project-detail-lower {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 20px;
      align-items: start;
    }

    .project-detail-panel {
      padding: 22px;
    }

    .project-detail-title {
      font-size: 2rem;
      line-height: 1.1;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 8px;
    }

    .project-detail-sub {
      color: #64748b;
      font-size: 0.96rem;
      line-height: 1.6;
    }

    .project-detail-pill-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 14px;
    }

    .project-detail-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 0.8rem;
      font-weight: 800;
    }

    .project-percent-wrap {
      display: grid;
      grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
      gap: 20px;
      align-items: stretch;
    }

    .project-percent-box {
      border-radius: 20px;
      border: 1px solid #e2e8f0;
      padding: 24px 20px;
      background: #f8fafc;
      text-align: center;
    }

    .project-percent-label {
      color: #475569;
      font-size: 0.82rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      margin-bottom: 8px;
    }

    .project-percent-value {
      font-size: clamp(3rem, 8vw, 5.2rem);
      line-height: 1;
      font-weight: 800;
      color: #0f172a;
    }

    .project-percent-meta {
      margin-top: 10px;
      color: #64748b;
      font-size: 0.9rem;
    }

    .project-info-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .project-info-card {
      border-radius: 16px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      padding: 14px;
    }

    .project-info-label {
      color: #64748b;
      font-size: 0.78rem;
      font-weight: 700;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .project-info-value {
      color: #0f172a;
      font-size: 1rem;
      font-weight: 800;
      line-height: 1.45;
    }

    .project-list-title {
      font-size: 1rem;
      font-weight: 800;
      color: #0f172a;
      margin-bottom: 14px;
    }

    .project-list-stack {
      display: grid;
      gap: 12px;
    }

    .project-task-item {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px;
      background: #fff;
    }

    .project-task-head {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: flex-start;
    }

    .project-task-name {
      color: #0f172a;
      font-size: 0.96rem;
      font-weight: 800;
      line-height: 1.35;
    }

    .project-task-meta {
      margin-top: 8px;
      color: #64748b;
      font-size: 0.84rem;
      line-height: 1.55;
    }

    .project-task-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.76rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .project-detail-table-wrap {
      overflow-x: auto;
    }

    .project-detail-table {
      width: 100%;
      min-width: 860px;
      border-collapse: collapse;
    }

    .project-detail-table th,
    .project-detail-table td {
      padding: 12px 14px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      vertical-align: top;
      font-size: 0.92rem;
    }

    .project-detail-table th {
      color: #475569;
      font-size: 0.78rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .project-detail-actions {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .project-detail-panel.is-compact .project-task-item {
      padding: 14px;
    }

    .project-gantt-wrap {
      overflow-x: auto;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      background: #fff;
    }

    .project-gantt-board {
      min-width: 980px;
    }

    .project-gantt-header {
      display: grid;
      grid-template-columns: 280px minmax(680px, 1fr);
      border-bottom: 1px solid #e2e8f0;
      background: #f8fafc;
    }

    .project-gantt-header-left {
      padding: 16px 18px;
      border-right: 1px solid #e2e8f0;
    }

    .project-gantt-header-left strong {
      display: block;
      color: #0f172a;
      font-size: 0.92rem;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .project-gantt-header-left span {
      color: #64748b;
      font-size: 0.8rem;
      line-height: 1.45;
    }

    .project-gantt-header-right {
      padding: 12px 16px 14px;
    }

    .project-gantt-months {
      position: relative;
      height: 30px;
      margin-bottom: 10px;
    }

    .project-gantt-month-band {
      position: absolute;
      top: 0;
      bottom: 0;
      border-right: 1px solid #dbe2ea;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.76rem;
      font-weight: 800;
      color: #334155;
      background: rgba(255, 255, 255, 0.65);
    }

    .project-gantt-scale {
      position: relative;
      height: 28px;
      border-radius: 999px;
      background:
        repeating-linear-gradient(
          to right,
          rgba(148, 163, 184, 0.18) 0,
          rgba(148, 163, 184, 0.18) 1px,
          transparent 1px,
          transparent calc(14.285% - 1px)
        );
    }

    .project-gantt-marker {
      position: absolute;
      top: 0;
      transform: translateX(-50%);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 2px;
      color: #64748b;
      font-size: 0.66rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .project-gantt-marker::before {
      content: '';
      width: 1px;
      height: 10px;
      background: #cbd5e1;
    }

    .project-gantt-row {
      display: grid;
      grid-template-columns: 280px minmax(680px, 1fr);
      border-bottom: 1px solid #e2e8f0;
    }

    .project-gantt-row:last-child {
      border-bottom: 0;
    }

    .project-gantt-info {
      padding: 14px 18px;
      border-right: 1px solid #e2e8f0;
      background: #fff;
    }

    .project-gantt-task {
      display: block;
      color: #0f172a;
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1.35;
      margin-bottom: 4px;
    }

    .project-gantt-meta {
      display: block;
      color: #64748b;
      font-size: 0.78rem;
      line-height: 1.45;
    }

    .project-gantt-timeline {
      position: relative;
      min-height: 72px;
      padding: 14px 16px;
      background:
        repeating-linear-gradient(
          to right,
          rgba(148, 163, 184, 0.14) 0,
          rgba(148, 163, 184, 0.14) 1px,
          transparent 1px,
          transparent calc(14.285% - 1px)
        );
    }

    .project-gantt-track {
      position: absolute;
      left: 16px;
      right: 16px;
      top: 50%;
      height: 12px;
      transform: translateY(-50%);
      border-radius: 999px;
      background: #e2e8f0;
    }

    .project-gantt-bar {
      position: absolute;
      top: 50%;
      height: 18px;
      transform: translateY(-50%);
      border-radius: 999px;
      min-width: 10px;
      box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
    }

    .project-gantt-bar::after {
      content: attr(data-label);
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      color: #fff;
      font-size: 0.72rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .project-gantt-bar.complete {
      background: linear-gradient(90deg, #10b981 0%, #059669 100%);
    }

    .project-gantt-bar.late {
      background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
    }

    .project-gantt-bar.active {
      background: linear-gradient(90deg, #2563eb 0%, #1d4ed8 100%);
    }

    .project-gantt-bar.pending {
      background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
    }

    @media (max-width: 1180px) {
      .project-detail-top,
      .project-detail-lower,
      .project-percent-wrap,
      .project-info-grid {
        grid-template-columns: 1fr;
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
            href="<?= htmlspecialchars($backHref) ?>"
            class="back-btn"
            style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
            <i class="fas fa-arrow-left"></i>
            Volver a proyectos
          </a>
        </div>
        <h1><i class="fas fa-diagram-project" style="margin-right:12px;"></i><?= htmlspecialchars((string)$detalle['milestone']) ?></h1>
        <div class="sub">
          <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars((string)($detalle['zona'] ?? 'Sin zona')) ?></span>
          <span><i class="fas fa-sitemap"></i> <?= htmlspecialchars((string)($detalle['area'] ?? 'Sin área')) ?></span>
          <span class="badge"><i class="fas fa-user"></i> <?= htmlspecialchars((string)($detalle['responsable'] ?? 'Sin responsable')) ?></span>
        </div>
      </div>
    </div>

    <div class="project-detail-layout">
      <div class="project-detail-top">
        <div style="display:grid; gap:20px;">
          <section class="project-detail-panel">
          <div class="project-detail-title"><?= htmlspecialchars((string)$detalle['milestone']) ?></div>
          <div class="project-detail-sub">
            <?= trim((string)($detalle['milestone_descripcion'] ?? '')) !== '' ? htmlspecialchars((string)$detalle['milestone_descripcion']) : 'Ficha ejecutiva del milestone con su avance, actividades recientes y plan de trabajo.' ?>
          </div>
          <div class="project-detail-pill-row">
            <span class="project-detail-pill" style="background:<?= htmlspecialchars($projectStatus['color']) ?>1A; color:<?= htmlspecialchars($projectStatus['color']) ?>;">
              <i class="fas fa-circle"></i><?= htmlspecialchars((string)$projectStatus['label']) ?>
            </span>
            <span class="project-detail-pill" style="background:<?= htmlspecialchars($projectHealth['color']) ?>1A; color:<?= htmlspecialchars($projectHealth['color']) ?>;">
              <i class="fas fa-signal"></i><?= htmlspecialchars((string)$projectHealth['label']) ?>
            </span>
          </div>
          </section>

          <section class="project-detail-panel">
          <div class="project-percent-wrap">
            <div class="project-percent-box">
              <div class="project-percent-label">Completado</div>
              <div class="project-percent-value"><?= n((float)($detalle['avance_real'] ?? 0), 0) ?>%</div>
              <div class="project-percent-meta">
                <?= n((float)($detalle['tareas_finalizadas'] ?? 0), 0) ?> de <?= n((float)($detalle['total_tareas'] ?? 0), 0) ?> tareas
              </div>
            </div>
            <div class="project-info-grid">
              <div class="project-info-card">
                <div class="project-info-label">Responsable</div>
                <div class="project-info-value"><?= htmlspecialchars((string)($detalle['responsable'] ?? 'Sin responsable')) ?></div>
              </div>
              <div class="project-info-card">
                <div class="project-info-label">Fecha estimada de cierre</div>
                <div class="project-info-value"><?= htmlspecialchars((string)($detalle['fecha_fin'] ?: '-')) ?></div>
              </div>
              <div class="project-info-card">
                <div class="project-info-label">Urgencia / mora</div>
                <div class="project-info-value"><?= n((float)($detalle['tareas_vencidas'] ?? 0), 0) ?> vencidas</div>
              </div>
              <div class="project-info-card">
                <div class="project-info-label">Estado actual</div>
                <div class="project-info-value"><?= htmlspecialchars((string)$projectHealth['label']) ?></div>
              </div>
              <div class="project-info-card">
                <div class="project-info-label">Zona</div>
                <div class="project-info-value"><?= htmlspecialchars((string)($detalle['zona'] ?? 'Sin zona')) ?></div>
              </div>
              <div class="project-info-card">
                <div class="project-info-label">Área</div>
                <div class="project-info-value"><?= htmlspecialchars((string)($detalle['area'] ?? 'Sin área')) ?></div>
              </div>
            </div>
          </div>
          </section>
        </div>

        <section class="project-detail-panel is-compact">
          <div class="project-list-title">Actividad reciente</div>
          <div class="project-list-stack">
            <?php if (!empty($actividadReciente)): ?>
              <?php foreach ($actividadReciente as $task): ?>
                <?php $visual = (array)($task['status_visual'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']); ?>
                <div class="project-task-item">
                  <div class="project-task-head">
                    <div class="project-task-name"><?= htmlspecialchars((string)($task['titulo'] ?? 'Actividad')) ?></div>
                    <span class="project-task-tag" style="background:<?= htmlspecialchars($visual['color']) ?>1A; color:<?= htmlspecialchars($visual['color']) ?>;">
                      <i class="fas fa-circle"></i><?= htmlspecialchars((string)$visual['label']) ?>
                    </span>
                  </div>
                  <div class="project-task-meta">
                    Cierre: <?= htmlspecialchars((string)($task['completada_en'] ?: '-')) ?><br>
                    Responsable: <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="project-task-item">
                <div class="project-task-name">Todavía no hay actividades cerradas.</div>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <section class="project-detail-panel">
        <div class="project-list-title">Gantt en calendario</div>
        <div class="project-gantt-wrap">
          <div class="project-gantt-board">
            <div class="project-gantt-header">
              <div class="project-gantt-header-left">
                <strong>Línea de tiempo del proyecto</strong>
                <span><?= htmlspecialchars($timelineStart->format('d/m/Y')) ?> a <?= htmlspecialchars($timelineEnd->format('d/m/Y')) ?></span>
              </div>
              <div class="project-gantt-header-right">
                <div class="project-gantt-months">
                  <?php foreach ($timelineMonthBands as $band): ?>
                    <div class="project-gantt-month-band" style="left: <?= n((float)$band['left'], 4) ?>%; width: <?= n((float)$band['width'], 4) ?>%;">
                      <?= htmlspecialchars((string)$band['label']) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="project-gantt-scale">
                  <?php foreach ($timelineWeekMarkers as $marker): ?>
                    <div class="project-gantt-marker" style="left: <?= n((float)$marker['left'], 4) ?>%;">
                      <span><?= htmlspecialchars((string)$marker['label']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <?php foreach ($tareas as $task): ?>
              <?php
              $taskStart = $parseDate((string)($task['fecha_inicio'] ?? ''));
              $taskEnd = $parseDate((string)($task['fecha_fin'] ?? ''));
              if ($taskStart === null && $taskEnd !== null) {
                $taskStart = $taskEnd;
              }
              if ($taskEnd === null && $taskStart !== null) {
                $taskEnd = $taskStart;
              }

              $startOffsetDays = $taskStart !== null ? (int)$timelineStart->diff($taskStart < $timelineStart ? $timelineStart : $taskStart)->format('%a') : 0;
              $endBase = $taskEnd !== null ? ($taskEnd > $timelineEnd ? $timelineEnd : $taskEnd) : $timelineStart;
              $barSpanDays = $taskStart !== null && $taskEnd !== null
                ? max(1, (int)($taskStart < $timelineStart ? $timelineStart : $taskStart)->diff($endBase)->format('%a') + 1)
                : 1;
              $barLeft = ($startOffsetDays / $timelineTotalDays) * 100;
              $barWidth = ($barSpanDays / $timelineTotalDays) * 100;

              $taskVisual = (array)($task['status_visual'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']);
              $visualLabel = (string)($taskVisual['label'] ?? 'Sin dato');
              $barClass = 'active';
              if ($visualLabel === 'Terminada') {
                $barClass = 'complete';
              } elseif ($visualLabel === 'Completada tarde' || $visualLabel === 'Vencida' || $visualLabel === 'Rechazada') {
                $barClass = 'late';
              } elseif ($visualLabel === 'Abierta' || $visualLabel === 'En revisión') {
                $barClass = 'pending';
              }
              ?>
              <div class="project-gantt-row">
                <div class="project-gantt-info">
                  <span class="project-gantt-task"><?= htmlspecialchars((string)($task['titulo'] ?? 'Tarea')) ?></span>
                  <span class="project-gantt-meta">
                    <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?><br>
                    <?= htmlspecialchars((string)($task['fecha_inicio'] ?: '-')) ?> a <?= htmlspecialchars((string)($task['fecha_fin'] ?: '-')) ?>
                  </span>
                </div>
                <div class="project-gantt-timeline">
                  <div class="project-gantt-track"></div>
                  <div
                    class="project-gantt-bar <?= htmlspecialchars($barClass) ?>"
                    data-label="<?= htmlspecialchars($visualLabel) ?>"
                    style="left: <?= n((float)$barLeft, 4) ?>%; width: <?= n((float)$barWidth, 4) ?>%;">
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </section>

      <div class="project-detail-lower">
        <section class="project-detail-panel">
          <div class="project-list-title">Plan de trabajo</div>
          <div class="project-list-stack">
            <?php if (!empty($planTrabajo)): ?>
              <?php foreach ($planTrabajo as $task): ?>
                <?php $visual = (array)($task['status_visual'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']); ?>
                <div class="project-task-item">
                  <div class="project-task-head">
                    <div class="project-task-name"><?= htmlspecialchars((string)($task['titulo'] ?? 'Tarea')) ?></div>
                    <span class="project-task-tag" style="background:<?= htmlspecialchars($visual['color']) ?>1A; color:<?= htmlspecialchars($visual['color']) ?>;">
                      <i class="fas fa-circle"></i><?= htmlspecialchars((string)$visual['label']) ?>
                    </span>
                  </div>
                  <div class="project-task-meta">
                    Responsable: <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?><br>
                    Fecha compromiso: <?= htmlspecialchars((string)($task['fecha_fin'] ?: '-')) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="project-task-item">
                <div class="project-task-name">No hay tareas pendientes registradas.</div>
              </div>
            <?php endif; ?>
          </div>
        </section>

        <section class="project-detail-panel">
          <div class="project-list-title">Lista de trabajo completado</div>
          <div class="project-list-stack">
            <?php if (!empty($listaTrabajo)): ?>
              <?php foreach ($listaTrabajo as $task): ?>
                <?php $visual = (array)($task['status_visual'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']); ?>
                <div class="project-task-item">
                  <div class="project-task-head">
                    <div class="project-task-name"><?= htmlspecialchars((string)($task['titulo'] ?? 'Actividad')) ?></div>
                    <span class="project-task-tag" style="background:<?= htmlspecialchars($visual['color']) ?>1A; color:<?= htmlspecialchars($visual['color']) ?>;">
                      <i class="fas fa-circle"></i><?= htmlspecialchars((string)$visual['label']) ?>
                    </span>
                  </div>
                  <div class="project-task-meta">
                    Terminado: <?= htmlspecialchars((string)($task['completada_en'] ?: '-')) ?><br>
                    Responsable: <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="project-task-item">
                <div class="project-task-name">No hay trabajo completado todavía.</div>
              </div>
            <?php endif; ?>
          </div>
        </section>
      </div>
    </div>

    <div class="project-detail-panel" style="margin-top:20px;">
      <div class="project-detail-actions">
        <div class="project-list-title" style="margin-bottom:0;">Detalle completo de tareas</div>
        <a href="<?= htmlspecialchars($backHref) ?>" class="project-card-link">
          Ver más proyectos
          <i class="fas fa-arrow-right"></i>
        </a>
      </div>
      <div class="project-detail-table-wrap">
        <table class="project-detail-table">
          <thead>
            <tr>
              <th>Tarea</th>
              <th>Responsable</th>
              <th>Inicio</th>
              <th>Fin</th>
              <th>Completada</th>
              <th>Prioridad</th>
              <th>Semáforo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tareas as $task): ?>
              <?php $visual = (array)($task['status_visual'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']); ?>
              <tr>
                <td><strong><?= htmlspecialchars((string)($task['titulo'] ?? 'Tarea')) ?></strong></td>
                <td><?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?></td>
                <td><?= htmlspecialchars((string)($task['fecha_inicio'] ?: '-')) ?></td>
                <td><?= htmlspecialchars((string)($task['fecha_fin'] ?: '-')) ?></td>
                <td><?= htmlspecialchars((string)($task['completada_en'] ?: '-')) ?></td>
                <td><?= htmlspecialchars((string)($task['prioridad'] ?? '-')) ?></td>
                <td>
                  <span class="project-task-tag" style="background:<?= htmlspecialchars($visual['color']) ?>1A; color:<?= htmlspecialchars($visual['color']) ?>;">
                    <i class="fas fa-circle"></i><?= htmlspecialchars((string)$visual['label']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</body>
</html>
