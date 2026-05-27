<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
$databaseConfig = require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../shared/helpers.php';

$connectMysqlForHighlights = static function (array $cfg): PDO {
  $host = (string)($cfg['host'] ?? 'localhost:3306');
  $parts = explode(':', $host, 2);
  $hostname = $parts[0] !== '' ? $parts[0] : 'localhost';
  $port = $parts[1] ?? '3306';
  $dbname = (string)($cfg['dbname'] ?? '');
  $charset = (string)($cfg['charset'] ?? 'utf8mb4');
  $dsn = 'mysql:host=' . $hostname . ';port=' . $port . ';dbname=' . $dbname . ';charset=' . $charset;

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$ensureHighlightsTable = static function (PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS reportes_proyectos_destacados (
      empresa_id INT NOT NULL,
      milestone_id INT NOT NULL,
      orden TINYINT UNSIGNED NOT NULL DEFAULT 1,
      creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (empresa_id, milestone_id),
      KEY idx_reportes_proyectos_destacados_orden (empresa_id, orden)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
};

$normalizeHighlightOrders = static function (PDO $pdo, int $empresaId): void {
  $stmt = $pdo->prepare("
    SELECT milestone_id
    FROM reportes_proyectos_destacados
    WHERE empresa_id = ?
    ORDER BY orden ASC, actualizado_en DESC
    LIMIT 3
  ");
  $stmt->execute([$empresaId]);
  $rows = $stmt->fetchAll() ?: [];

  $deleteExtra = $pdo->prepare("
    DELETE FROM reportes_proyectos_destacados
    WHERE empresa_id = ? AND milestone_id NOT IN (
      SELECT milestone_id FROM (
        SELECT milestone_id
        FROM reportes_proyectos_destacados
        WHERE empresa_id = ?
        ORDER BY orden ASC, actualizado_en DESC
        LIMIT 3
      ) keepers
    )
  ");
  $deleteExtra->execute([$empresaId, $empresaId]);

  $update = $pdo->prepare("
    UPDATE reportes_proyectos_destacados
    SET orden = ?
    WHERE empresa_id = ? AND milestone_id = ?
  ");
  foreach ($rows as $index => $row) {
    $update->execute([$index + 1, $empresaId, (int)($row['milestone_id'] ?? 0)]);
  }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['highlight_action'])) {
  try {
    $empresaId = (int)($config['empresa_id'] ?? 0);
    $milestoneId = (int)($_POST['milestone_id'] ?? 0);
    $action = (string)($_POST['highlight_action'] ?? '');
    if ($empresaId > 0 && $milestoneId > 0 && in_array($action, ['add', 'remove'], true)) {
      $pdoHighlights = $connectMysqlForHighlights((array)($databaseConfig[$config['database_key']] ?? []));
      $ensureHighlightsTable($pdoHighlights);

      if ($action === 'remove') {
        $stmt = $pdoHighlights->prepare("
          DELETE FROM reportes_proyectos_destacados
          WHERE empresa_id = ? AND milestone_id = ?
        ");
        $stmt->execute([$empresaId, $milestoneId]);
        $normalizeHighlightOrders($pdoHighlights, $empresaId);
      } else {
        $existingStmt = $pdoHighlights->prepare("
          SELECT COUNT(*) AS total
          FROM reportes_proyectos_destacados
          WHERE empresa_id = ? AND milestone_id = ?
        ");
        $existingStmt->execute([$empresaId, $milestoneId]);
        $existingRow = $existingStmt->fetch() ?: [];
        $alreadyFeatured = (int)($existingRow['total'] ?? 0) > 0;

        $countStmt = $pdoHighlights->prepare("
          SELECT COUNT(*) AS total
          FROM reportes_proyectos_destacados
          WHERE empresa_id = ?
        ");
        $countStmt->execute([$empresaId]);
        $countRow = $countStmt->fetch() ?: [];
        $featuredCount = (int)($countRow['total'] ?? 0);

        if (!$alreadyFeatured && $featuredCount < 3) {
          $orderStmt = $pdoHighlights->prepare("
            SELECT orden
            FROM reportes_proyectos_destacados
            WHERE empresa_id = ?
          ");
          $orderStmt->execute([$empresaId]);
          $usedOrders = array_map('intval', array_column($orderStmt->fetchAll() ?: [], 'orden'));
          $nextOrder = 1;
          while (in_array($nextOrder, $usedOrders, true) && $nextOrder < 3) {
            $nextOrder++;
          }

          $insertStmt = $pdoHighlights->prepare("
            INSERT INTO reportes_proyectos_destacados (empresa_id, milestone_id, orden)
            VALUES (?, ?, ?)
          ");
          $insertStmt->execute([$empresaId, $milestoneId, $nextOrder]);
          $normalizeHighlightOrders($pdoHighlights, $empresaId);
        }
      }
    }
  } catch (Throwable $e) {
  }

  header('Location: ' . ($_SERVER['REQUEST_URI'] ?: './index.php'));
  exit;
}

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
$dependencias = (array)($report['detalle_dependencias'] ?? []);
$proyectosDestacados = (array)($report['proyectos_destacados'] ?? []);
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
$isProjectFeatured = (int)($detalle['destacado_orden'] ?? 0) > 0;
$featuredCount = count($proyectosDestacados);
$canFeatureProject = $isProjectFeatured || $featuredCount < 3;

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
$timelineActividad = $completadas;
usort($timelineActividad, static function (array $a, array $b): int {
  $dateA = (string)($a['completada_en'] ?? '');
  $dateB = (string)($b['completada_en'] ?? '');
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

$parseDateTime = static function (?string $value): ?DateTimeImmutable {
  $value = trim((string)$value);
  if ($value === '') {
    return null;
  }

  try {
    return new DateTimeImmutable($value);
  } catch (Throwable $e) {
    try {
      return new DateTimeImmutable(substr($value, 0, 10));
    } catch (Throwable $ignored) {
      return null;
    }
  }
};

$relativeDateLabel = static function (?string $value) use ($parseDateTime): string {
  $date = $parseDateTime($value);
  if ($date === null) {
    return '-';
  }

  $today = new DateTimeImmutable('today');
  $target = $date->setTime(0, 0);
  $days = (int)$target->diff($today)->format('%r%a');
  if ($days === 0) {
    return 'Hoy';
  }
  if ($days === 1) {
    return 'Hace 1 día';
  }
  if ($days > 1) {
    return 'Hace ' . $days . ' días';
  }
  if ($days === -1) {
    return 'Mañana';
  }

  return 'En ' . abs($days) . ' días';
};

$shortDateLabel = static function (?string $value) use ($parseDateTime): string {
  $date = $parseDateTime($value);
  return $date !== null ? $date->format('d/m/Y') : '-';
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
    'startOffset' => $startOffset,
    'spanDays' => $spanDays,
  ];
  $monthCursor = $monthCursor->modify('first day of next month');
}

$ganttDayWidth = 30;
$ganttRowHeight = 68;
$ganttChartWidth = max(760, $timelineTotalDays * $ganttDayWidth);
$todayDate = $parseDate(date('Y-m-d'));
$ganttTodayLeft = null;
if ($todayDate !== null && $todayDate >= $timelineStart && $todayDate <= $timelineEnd) {
  $ganttTodayLeft = ((int)$timelineStart->diff($todayDate)->format('%a') * $ganttDayWidth) + ($ganttDayWidth / 2);
}
$ganttRows = [];
$ganttTaskIndex = [];

foreach ($tareas as $taskIndex => $task) {
  $taskStart = $parseDate((string)($task['fecha_inicio'] ?? ''));
  $taskEnd = $parseDate((string)($task['fecha_fin'] ?? ''));
  if ($taskStart === null && $taskEnd !== null) {
    $taskStart = $taskEnd;
  }
  if ($taskEnd === null && $taskStart !== null) {
    $taskEnd = $taskStart;
  }

  $clampedStart = $taskStart !== null && $taskStart < $timelineStart ? $timelineStart : $taskStart;
  $clampedEnd = $taskEnd !== null && $taskEnd > $timelineEnd ? $timelineEnd : $taskEnd;
  if ($clampedStart === null) {
    $clampedStart = $timelineStart;
  }
  if ($clampedEnd === null || $clampedEnd < $clampedStart) {
    $clampedEnd = $clampedStart;
  }

  $startOffsetDays = (int)$timelineStart->diff($clampedStart)->format('%a');
  $endOffsetDays = (int)$timelineStart->diff($clampedEnd)->format('%a');
  $spanDays = max(1, $endOffsetDays - $startOffsetDays + 1);
  $barLeftPx = ($startOffsetDays * $ganttDayWidth) + 6;
  $barWidthPx = max(22, ($spanDays * $ganttDayWidth) - 12);

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

  $taskId = (int)($task['tarea_id'] ?? 0);
  $ganttTaskIndex[$taskId] = $taskIndex;
  $ganttRows[] = [
    'task' => $task,
    'barClass' => $barClass,
    'barLeftPx' => $barLeftPx,
    'barWidthPx' => $barWidthPx,
    'startX' => $barLeftPx,
    'endX' => $barLeftPx + $barWidthPx,
    'y' => ($taskIndex * $ganttRowHeight) + ($ganttRowHeight / 2),
  ];
}

$ganttDependencyPaths = [];
foreach ($dependencias as $dependency) {
  $fromTaskId = (int)($dependency['tarea_dependiente_id'] ?? 0);
  $toTaskId = (int)($dependency['tarea_id'] ?? 0);
  if (!isset($ganttTaskIndex[$fromTaskId], $ganttTaskIndex[$toTaskId])) {
    continue;
  }

  $relation = strtoupper((string)($dependency['tipo_relacion'] ?? 'FS'));
  if (!in_array($relation, ['FS', 'SS', 'FF', 'SF'], true)) {
    $relation = 'FS';
  }

  $fromRow = $ganttRows[$ganttTaskIndex[$fromTaskId]];
  $toRow = $ganttRows[$ganttTaskIndex[$toTaskId]];
  $fromSide = in_array($relation, ['FS', 'FF'], true) ? 'right' : 'left';
  $toSide = in_array($relation, ['FS', 'SS'], true) ? 'left' : 'right';
  $fromX = $fromSide === 'right' ? (float)$fromRow['endX'] : (float)$fromRow['startX'];
  $toX = $toSide === 'left' ? (float)$toRow['startX'] : (float)$toRow['endX'];
  $fromY = (float)$fromRow['y'];
  $toY = (float)$toRow['y'];
  $routeGap = 16.0;
  $fromDir = $fromSide === 'right' ? 1.0 : -1.0;
  $toDir = $toSide === 'left' ? -1.0 : 1.0;
  $fromLeadX = max(2.0, min($ganttChartWidth - 2.0, $fromX + ($fromDir * $routeGap)));
  $toLeadX = max(2.0, min($ganttChartWidth - 2.0, $toX + ($toDir * $routeGap)));

  if ($fromSide === $toSide) {
    $busX = $fromSide === 'right'
      ? max($fromLeadX, $toLeadX) + $routeGap
      : min($fromLeadX, $toLeadX) - $routeGap;
  } elseif ($fromLeadX <= $toLeadX) {
    $busX = ($fromLeadX + $toLeadX) / 2;
  } else {
    $busX = $fromSide === 'right'
      ? max($fromLeadX, $toLeadX) + $routeGap
      : min($fromLeadX, $toLeadX) - $routeGap;
  }

  $busX = max(2.0, min($ganttChartWidth - 2.0, $busX));
  $path = 'M ' . number_format($fromX, 2, '.', '') . ' ' . number_format($fromY, 2, '.', '')
    . ' H ' . number_format($fromLeadX, 2, '.', '')
    . ' H ' . number_format($busX, 2, '.', '')
    . ' V ' . number_format($toY, 2, '.', '')
    . ' H ' . number_format($toLeadX, 2, '.', '')
    . ' H ' . number_format($toX, 2, '.', '');

  $ganttDependencyPaths[] = [
    'path' => $path,
  ];
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

    .project-detail-panel.has-gantt {
      min-width: 0;
      overflow: hidden;
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

    .project-percent-progress {
      margin-top: 18px;
      height: 14px;
      border-radius: 999px;
      overflow: hidden;
      background: #e2e8f0;
      box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.08);
    }

    .project-percent-progress-fill {
      height: 100%;
      border-radius: 999px;
      background: #2563eb;
      min-width: 4px;
    }

    .project-date-main {
      color: #0f172a;
      font-size: 1rem;
      font-weight: 800;
      line-height: 1.35;
    }

    .project-date-sub {
      margin-top: 4px;
      color: #94a3b8;
      font-size: 0.82rem;
      font-weight: 700;
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

    .project-task-date-main {
      color: #0f172a;
      font-size: 0.86rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .project-task-date-sub {
      color: #94a3b8;
      font-size: 0.76rem;
      font-weight: 700;
    }

    .project-activity-toggle,
    .project-detail-toggle {
      width: 100%;
      border: 1px solid #dbe2ea;
      background: #f8fafc;
      color: #334155;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 0.84rem;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      cursor: pointer;
      transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .project-activity-toggle:hover,
    .project-detail-toggle:hover {
      background: #eef6ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
    }

    .project-activity-toggle[aria-expanded="true"] .fa-chevron-down,
    .project-detail-toggle[aria-expanded="true"] .fa-chevron-down {
      transform: rotate(180deg);
    }

    .project-activity-toggle .fa-chevron-down,
    .project-detail-toggle .fa-chevron-down {
      transition: transform 0.18s ease;
    }

    .project-activity-timeline-head {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: center;
      margin-top: 2px;
      padding-top: 12px;
      border-top: 1px solid #e2e8f0;
    }

    .project-activity-timeline-title {
      color: #0f172a;
      font-size: 0.86rem;
      font-weight: 800;
    }

    .project-star-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      background: #fff;
      color: #334155;
      cursor: pointer;
      font: inherit;
      font-size: 0.84rem;
      font-weight: 800;
      padding: 10px 14px;
      transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .project-star-btn:hover {
      background: #fffbeb;
      border-color: #fde68a;
      color: #b45309;
    }

    .project-star-btn.is-active {
      background: #fffbeb;
      border-color: #facc15;
      color: #a16207;
    }

    .project-star-btn:disabled {
      cursor: not-allowed;
      opacity: 0.52;
    }

    .project-feature-note {
      color: #64748b;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .project-activity-timeline-count {
      color: #64748b;
      font-size: 0.74rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .project-activity-timeline {
      display: none;
      position: relative;
      padding: 4px 0 0 14px;
      margin-top: 4px;
      gap: 12px;
      max-height: 280px;
      overflow: auto;
      scrollbar-color: #cbd5e1 #f8fafc;
      scrollbar-width: thin;
    }

    .project-activity-timeline.is-open {
      display: grid;
      max-height: 560px;
    }

    .project-detail-toggle-copy {
      color: #64748b;
      font-size: 0.86rem;
      line-height: 1.45;
    }

    .project-detail-modal {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.54);
    }

    .project-detail-modal.is-open {
      display: flex;
    }

    .project-detail-modal-card {
      width: min(1120px, 96vw);
      max-height: min(760px, 88vh);
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 24px 70px rgba(15, 23, 42, 0.28);
      display: grid;
      grid-template-rows: auto minmax(0, 1fr);
      overflow: hidden;
    }

    .project-detail-modal-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 20px;
      border-bottom: 1px solid #e2e8f0;
      background: #f8fafc;
    }

    .project-detail-modal-title {
      color: #0f172a;
      font-size: 1.05rem;
      font-weight: 800;
      line-height: 1.3;
      margin-bottom: 4px;
    }

    .project-detail-modal-close {
      width: 38px;
      height: 38px;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      background: #fff;
      color: #334155;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .project-detail-modal-close:hover {
      background: #eef6ff;
      border-color: #bfdbfe;
      color: #1d4ed8;
    }

    .project-detail-modal-body {
      min-height: 0;
      overflow: auto;
      padding: 0;
    }

    .project-detail-modal .project-detail-table-wrap {
      max-height: none;
      overflow: auto;
    }

    .project-detail-modal .project-detail-table {
      min-width: 860px;
    }

    .project-activity-timeline::before {
      content: '';
      position: absolute;
      left: 5px;
      top: 12px;
      bottom: 10px;
      width: 2px;
      background: #dbe2ea;
    }

    .project-activity-timeline::-webkit-scrollbar {
      width: 8px;
    }

    .project-activity-timeline::-webkit-scrollbar-track {
      background: #f8fafc;
    }

    .project-activity-timeline::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 999px;
    }

    .project-activity-event {
      position: relative;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 12px;
      background: #fff;
    }

    .project-activity-event::before {
      content: '';
      position: absolute;
      left: -14px;
      top: 16px;
      width: 10px;
      height: 10px;
      border-radius: 999px;
      background: #2563eb;
      border: 2px solid #fff;
      box-shadow: 0 0 0 2px #bfdbfe;
    }

    .project-activity-event-title {
      color: #0f172a;
      font-size: 0.88rem;
      font-weight: 800;
      line-height: 1.35;
      margin-bottom: 6px;
    }

    .project-activity-event-meta {
      color: #64748b;
      font-size: 0.76rem;
      line-height: 1.45;
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
      overflow: auto;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #f8fafc;
      width: 100%;
      max-width: 100%;
      max-height: min(620px, 72vh);
      position: relative;
      overscroll-behavior: contain;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
      scrollbar-color: #94a3b8 #e2e8f0;
      scrollbar-width: thin;
    }

    .project-gantt-wrap::-webkit-scrollbar {
      width: 10px;
      height: 10px;
    }

    .project-gantt-wrap::-webkit-scrollbar-track {
      background: #e2e8f0;
    }

    .project-gantt-wrap::-webkit-scrollbar-thumb {
      background: #94a3b8;
      border: 2px solid #e2e8f0;
      border-radius: 999px;
    }

    .project-gantt-board {
      --gantt-left: 320px;
      --gantt-row-height: 68px;
      --gantt-day-width: 30px;
      min-width: calc(var(--gantt-left) + var(--gantt-chart-width));
      width: calc(var(--gantt-left) + var(--gantt-chart-width));
    }

    .project-gantt-header {
      display: grid;
      grid-template-columns: var(--gantt-left) var(--gantt-chart-width);
      border-bottom: 1px solid #cbd5e1;
      background: #eef2f7;
      position: sticky;
      top: 0;
      z-index: 30;
    }

    .project-gantt-header-left {
      padding: 16px 18px;
      border-right: 1px solid #e2e8f0;
      position: sticky;
      left: 0;
      z-index: 35;
      background: #eef2f7;
      box-shadow: 8px 0 14px rgba(15, 23, 42, 0.08);
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
      padding: 12px 0 0;
    }

    .project-gantt-months {
      position: relative;
      height: 30px;
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
      background: #f8fafc;
    }

    .project-gantt-scale {
      display: grid;
      grid-template-columns: repeat(<?= (int)$timelineTotalDays ?>, var(--gantt-day-width));
      height: 46px;
      border-top: 1px solid #dbe2ea;
    }

    .project-gantt-day {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      border-right: 1px solid #e2e8f0;
      color: #64748b;
      font-size: 0.66rem;
      font-weight: 700;
      background: #fff;
    }

    .project-gantt-day strong {
      color: #0f172a;
      font-size: 0.72rem;
      line-height: 1;
    }

    .project-gantt-day.is-weekend {
      background: #f1f5f9;
    }

    .project-gantt-day.is-today {
      background: #dbeafe;
      color: #1d4ed8;
      box-shadow: inset 0 -2px 0 #2563eb;
    }

    .project-gantt-row {
      display: grid;
      grid-template-columns: var(--gantt-left) var(--gantt-chart-width);
      border-bottom: 1px solid #edf2f7;
      height: var(--gantt-row-height);
    }

    .project-gantt-row:nth-of-type(even) .project-gantt-info,
    .project-gantt-row:nth-of-type(even) .project-gantt-timeline {
      background-color: #fbfdff;
    }

    .project-gantt-row:hover .project-gantt-info,
    .project-gantt-row:hover .project-gantt-timeline {
      background-color: #f8fbff;
    }

    .project-gantt-row:last-child {
      border-bottom: 0;
    }

    .project-gantt-info {
      padding: 10px 18px;
      border-right: 1px solid #e2e8f0;
      background: #fff;
      position: sticky;
      left: 0;
      z-index: 20;
      overflow: hidden;
      box-shadow: 8px 0 14px rgba(15, 23, 42, 0.05);
    }

    .project-gantt-task {
      display: -webkit-box;
      color: #0f172a;
      font-size: 0.9rem;
      font-weight: 800;
      line-height: 1.25;
      margin-bottom: 4px;
      overflow: hidden;
      -webkit-box-orient: vertical;
      -webkit-line-clamp: 2;
    }

    .project-gantt-meta {
      display: block;
      color: #64748b;
      font-size: 0.78rem;
      line-height: 1.3;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .project-gantt-timeline {
      position: relative;
      height: var(--gantt-row-height);
      background:
        repeating-linear-gradient(to right,
          rgba(148, 163, 184, 0.18) 0,
          rgba(148, 163, 184, 0.18) 1px,
          transparent 1px,
          transparent var(--gantt-day-width)),
        linear-gradient(#fff, #fff);
    }

    .project-gantt-today-line {
      position: absolute;
      top: 0;
      bottom: 0;
      width: 2px;
      transform: translateX(-1px);
      background: rgba(37, 99, 235, 0.62);
      z-index: 9;
      pointer-events: none;
    }

    .project-gantt-body {
      position: relative;
    }

    .project-gantt-links {
      position: absolute;
      left: var(--gantt-left);
      top: 0;
      width: var(--gantt-chart-width);
      height: calc(var(--gantt-row-height) * var(--gantt-row-count));
      pointer-events: none;
      z-index: 12;
      overflow: visible;
    }

    .project-gantt-link {
      fill: none;
      stroke: #475569;
      stroke-width: 1.35;
      stroke-linecap: square;
      stroke-linejoin: miter;
      opacity: 0.7;
    }

    .project-gantt-bar {
      position: absolute;
      top: 50%;
      height: 16px;
      transform: translateY(-50%);
      border-radius: 3px;
      min-width: 10px;
      box-shadow: 0 1px 0 rgba(255, 255, 255, 0.55) inset, 0 2px 6px rgba(15, 23, 42, 0.14);
      z-index: 10;
    }

    .project-gantt-bar.complete {
      background: #0f9f6e;
    }

    .project-gantt-bar.late {
      background: #dc2626;
    }

    .project-gantt-bar.active {
      background: #2563eb;
    }

    .project-gantt-bar.pending {
      background: #d97706;
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
          <form method="post" style="display:inline-flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input type="hidden" name="highlight_action" value="<?= $isProjectFeatured ? 'remove' : 'add' ?>">
            <input type="hidden" name="milestone_id" value="<?= (int)($detalle['milestone_id'] ?? $milestoneSelected) ?>">
            <button
              type="submit"
              class="project-star-btn<?= $isProjectFeatured ? ' is-active' : '' ?>"
              <?= !$canFeatureProject ? 'disabled' : '' ?>>
              <i class="<?= $isProjectFeatured ? 'fas' : 'far' ?> fa-star"></i>
              <?= $isProjectFeatured ? 'Quitar destacado' : 'Destacar proyecto' ?>
            </button>
            <span class="project-feature-note"><?= n((float)$featuredCount, 0) ?> de 3 destacados</span>
          </form>
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
                <div class="project-percent-progress" aria-hidden="true">
                  <div
                    class="project-percent-progress-fill"
                    style="width:<?= max(0, min(100, (float)($detalle['avance_real'] ?? 0))) ?>%; background:<?= htmlspecialchars((string)($projectHealth['color'] ?? '#2563eb')) ?>;">
                  </div>
                </div>
              </div>
              <div class="project-info-grid">
                <div class="project-info-card">
                  <div class="project-info-label">Responsable</div>
                  <div class="project-info-value"><?= htmlspecialchars((string)($detalle['responsable'] ?? 'Sin responsable')) ?></div>
                </div>
                <div class="project-info-card">
                  <div class="project-info-label">Fecha estimada de cierre</div>
                  <div class="project-date-main"><?= htmlspecialchars($relativeDateLabel((string)($detalle['fecha_fin'] ?? ''))) ?></div>
                  <div class="project-date-sub"><?= htmlspecialchars($shortDateLabel((string)($detalle['fecha_fin'] ?? ''))) ?></div>
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
                    Cierre:
                    <span class="project-task-date-main"><?= htmlspecialchars($relativeDateLabel((string)($task['completada_en'] ?? ''))) ?></span>
                    <span class="project-task-date-sub"><?= htmlspecialchars($shortDateLabel((string)($task['completada_en'] ?? ''))) ?></span><br>
                    Responsable: <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="project-task-item">
                <div class="project-task-name">Todavía no hay actividades cerradas.</div>
              </div>
            <?php endif; ?>
            <?php if (!empty($tareas)): ?>
              <button
                type="button"
                class="project-detail-toggle"
                id="openProjectFullDetail"
                aria-haspopup="dialog"
                aria-controls="projectFullDetailModal">
                <i class="fas fa-table-list"></i>
                Ver detalle completo de tareas
              </button>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <section class="project-detail-panel has-gantt">
        <div class="project-list-title">Gantt en calendario</div>
        <div class="project-gantt-wrap">
          <div
            class="project-gantt-board"
            style="--gantt-chart-width: <?= (int)$ganttChartWidth ?>px; --gantt-row-count: <?= max(1, count($ganttRows)) ?>;">
            <div class="project-gantt-header">
              <div class="project-gantt-header-left">
                <strong>Línea de tiempo del proyecto</strong>
                <span><?= htmlspecialchars($timelineStart->format('d/m/Y')) ?> a <?= htmlspecialchars($timelineEnd->format('d/m/Y')) ?></span>
              </div>
              <div class="project-gantt-header-right">
                <div class="project-gantt-months">
                  <?php foreach ($timelineMonthBands as $band): ?>
                    <div class="project-gantt-month-band" style="left: <?= (int)$band['startOffset'] * (int)$ganttDayWidth ?>px; width: <?= (int)$band['spanDays'] * (int)$ganttDayWidth ?>px;">
                      <?= htmlspecialchars((string)$band['label']) ?>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="project-gantt-scale">
                  <?php foreach ($timelineDays as $day): ?>
                    <?php $weekday = (string)($day['weekday'] ?? ''); ?>
                    <div class="project-gantt-day <?= in_array($weekday, ['S', 'D'], true) ? 'is-weekend' : '' ?> <?= !empty($day['isToday']) ? 'is-today' : '' ?>">
                      <span><?= htmlspecialchars($weekday) ?></span>
                      <strong><?= htmlspecialchars((string)$day['day']) ?></strong>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <div class="project-gantt-body">
              <?php if (!empty($ganttDependencyPaths)): ?>
                <svg
                  class="project-gantt-links"
                  viewBox="0 0 <?= (int)$ganttChartWidth ?> <?= max(1, count($ganttRows) * $ganttRowHeight) ?>"
                  preserveAspectRatio="none"
                  aria-hidden="true">
                  <defs>
                    <marker id="gantt-arrow" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto" markerUnits="strokeWidth">
                      <path d="M 0 0 L 8 4 L 0 8 z" fill="#334155"></path>
                    </marker>
                  </defs>
                  <?php foreach ($ganttDependencyPaths as $dependencyPath): ?>
                    <path class="project-gantt-link" d="<?= htmlspecialchars((string)$dependencyPath['path']) ?>" marker-end="url(#gantt-arrow)"></path>
                  <?php endforeach; ?>
                </svg>
              <?php endif; ?>

              <?php foreach ($ganttRows as $row): ?>
                <?php $task = (array)$row['task']; ?>
                <div class="project-gantt-row">
                  <div class="project-gantt-info">
                    <span class="project-gantt-task"><?= htmlspecialchars((string)($task['titulo'] ?? 'Tarea')) ?></span>
                    <span class="project-gantt-meta">
                      <?= htmlspecialchars((string)($task['responsable'] ?? 'Sin responsable')) ?><br>
                      <?= htmlspecialchars($shortDateLabel((string)($task['fecha_inicio'] ?? ''))) ?> a <?= htmlspecialchars($shortDateLabel((string)($task['fecha_fin'] ?? ''))) ?>
                    </span>
                  </div>
                  <div class="project-gantt-timeline">
                    <?php if ($ganttTodayLeft !== null): ?>
                      <div class="project-gantt-today-line" style="left: <?= number_format((float)$ganttTodayLeft, 2, '.', '') ?>px;"></div>
                    <?php endif; ?>
                    <div
                      class="project-gantt-bar <?= htmlspecialchars((string)$row['barClass']) ?>"
                      style="left: <?= number_format((float)$row['barLeftPx'], 2, '.', '') ?>px; width: <?= number_format((float)$row['barWidthPx'], 2, '.', '') ?>px;">
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
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
                    Fecha compromiso: <?= htmlspecialchars($shortDateLabel((string)($task['fecha_fin'] ?? ''))) ?>
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
                    Terminado: <?= htmlspecialchars($shortDateLabel((string)($task['completada_en'] ?? ''))) ?><br>
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
  </div>
  <?php if (!empty($tareas)): ?>
    <div
      class="project-detail-modal"
      id="projectFullDetailModal"
      role="dialog"
      aria-modal="true"
      aria-labelledby="projectFullDetailTitle"
      aria-hidden="true">
      <div class="project-detail-modal-card">
        <div class="project-detail-modal-head">
          <div>
            <div class="project-detail-modal-title" id="projectFullDetailTitle">Detalle completo de tareas</div>
            <div class="project-detail-toggle-copy"><?= n((float)count($tareas), 0) ?> tareas registradas en el proyecto</div>
          </div>
          <button
            type="button"
            class="project-detail-modal-close"
            data-close-project-detail-modal
            aria-label="Cerrar detalle de tareas">
            <i class="fas fa-xmark"></i>
          </button>
        </div>
        <div class="project-detail-modal-body">
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
                    <td><?= htmlspecialchars($shortDateLabel((string)($task['fecha_inicio'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars($shortDateLabel((string)($task['fecha_fin'] ?? ''))) ?></td>
                    <td><?= htmlspecialchars((string)($task['completada_en'] ? $shortDateLabel((string)$task['completada_en']) : '-')) ?></td>
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
    </div>
  <?php endif; ?>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const openButton = document.getElementById('openProjectFullDetail');
      const modal = document.getElementById('projectFullDetailModal');
      if (!openButton || !modal) {
        return;
      }

      const closeButtons = modal.querySelectorAll('[data-close-project-detail-modal]');
      const closeModal = function() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        openButton.focus();
      };
      const openModal = function() {
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        const closeButton = modal.querySelector('.project-detail-modal-close');
        if (closeButton) {
          closeButton.focus();
        }
      };

      openButton.addEventListener('click', openModal);
      closeButtons.forEach(function(button) {
        button.addEventListener('click', closeModal);
      });
      modal.addEventListener('click', function(event) {
        if (event.target === modal) {
          closeModal();
        }
      });
      document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          closeModal();
        }
      });
    });
  </script>
</body>

</html>
