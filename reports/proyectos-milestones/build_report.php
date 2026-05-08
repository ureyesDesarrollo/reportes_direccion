<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$databaseConfig = require __DIR__ . '/../../config/database.php';

$connectMysql = static function (array $cfg): PDO {
  $host = (string)($cfg['host'] ?? 'localhost:3306');
  $parts = explode(':', $host, 2);
  $server = $parts[0] ?? 'localhost';
  $port = $parts[1] ?? '3306';
  $dbname = (string)($cfg['dbname'] ?? '');
  $charset = (string)($cfg['charset'] ?? 'utf8mb4');
  $dsn = "mysql:host={$server};port={$port};dbname={$dbname};charset={$charset}";

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$milestoneStatusText = static function (int $status): string {
  if ($status === 1) {
    return 'Activo';
  }
  if ($status === 2) {
    return 'Cerrado';
  }
  if ($status === 3) {
    return 'Inactivo';
  }
  if ($status === 4) {
    return 'Pausado';
  }
  if ($status === 5) {
    return 'Descontinuado';
  }
  return 'Sin estatus';
};

$taskStatusText = static function (int $status, int $completed): string {
  if ($completed === 1 || $status === 4) {
    return 'Aprobada';
  }
  if ($status === 3) {
    return 'En revisión';
  }
  if ($status === 5) {
    return 'Rechazada';
  }
  if ($status === 6) {
    return 'Completada fuera de tiempo';
  }
  if ($status === 2) {
    return 'En progreso';
  }
  return 'Abierta';
};

$taskStatusVisual = static function (int $status, int $completed, bool $overdue): array {
  if ($completed === 1 || $status === 4) {
    return ['label' => 'Aprobada', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.10)'];
  }
  if ($status === 3) {
    return ['label' => 'En revisión', 'color' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.10)'];
  }
  if ($status === 5) {
    return ['label' => 'Rechazada', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.10)'];
  }
  if ($status === 6) {
    return ['label' => 'Fuera de tiempo', 'color' => '#f97316', 'bg' => 'rgba(249, 115, 22, 0.10)'];
  }
  if ($overdue) {
    return ['label' => 'Vencida', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.10)'];
  }
  if ($status === 2) {
    return ['label' => 'En progreso', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.10)'];
  }
  return ['label' => 'Abierta', 'color' => '#64748b', 'bg' => 'rgba(100, 116, 139, 0.10)'];
};

$milestoneVisual = static function (int $status, float $progress, int $overdueCount): array {
  if ($status === 5) {
    return ['key' => 'descontinuado', 'label' => 'Descontinuado', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.10)'];
  }
  if ($status === 4) {
    return ['key' => 'pausado', 'label' => 'Pausado', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.10)'];
  }
  if ($status === 3) {
    return ['key' => 'inactivo', 'label' => 'Inactivo', 'color' => '#94a3b8', 'bg' => 'rgba(148, 163, 184, 0.12)'];
  }
  if ($status === 2 || $progress >= 100.0) {
    return ['key' => 'cerrado', 'label' => 'Cerrado', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.10)'];
  }
  if ($overdueCount > 0) {
    return ['key' => 'activo-riesgo', 'label' => 'Activo con riesgo', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.10)'];
  }
  if ($progress >= 50.0) {
    return ['key' => 'activo-avance', 'label' => 'Activo en avance', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.10)'];
  }
  return ['key' => 'activo', 'label' => 'Activo', 'color' => '#3b82f6', 'bg' => 'rgba(59, 130, 246, 0.10)'];
};

$formatDate = static function (?string $value): string {
  if ($value === null || $value === '' || $value === '0000-00-00') {
    return '-';
  }

  try {
    return (new DateTimeImmutable($value))->format('Y-m-d');
  } catch (Throwable $e) {
    return $value;
  }
};

$detailMilestoneId = (int)($_GET['milestone_id'] ?? 0);
$statusFilter = trim((string)($_GET['estatus'] ?? 'all'));
$search = trim((string)($_GET['q'] ?? ''));
$warnings = [];

$pdo = $connectMysql((array)($databaseConfig['hoshin'] ?? []));

$sqlMilestones = "
SELECT
  COALESCE(e.estrategia_id, 0) AS estrategia_id,
  COALESCE(e.titulo, 'Sin estrategia') AS estrategia,
  m.milestone_id,
  m.estrategia_id,
  m.titulo AS milestone,
  m.descripcion,
  m.estatus AS milestone_estatus,
  m.prioridad,
  u.nombre_completo AS responsable,
  u.usuario_id AS responsable_id,
  COALESCE(NULLIF(GROUP_CONCAT(DISTINCT o.titulo ORDER BY o.titulo SEPARATOR ' | '), ''), 'Sin objetivo') AS objetivo,
  MIN(COALESCE(t.fecha_inicio, DATE(t.creado_en), t.fecha_fin)) AS fecha_inicio,
  MAX(t.fecha_fin) AS fecha_fin,
  COUNT(DISTINCT t.tarea_id) AS total_tareas,
  COUNT(DISTINCT CASE WHEN t.completada = 1 OR t.estatus = 4 THEN t.tarea_id END) AS finalizadas,
  COUNT(DISTINCT CASE WHEN (t.completada = 0 OR t.completada IS NULL) AND t.estatus IN (1,2,3,5) THEN t.tarea_id END) AS activas,
  COUNT(DISTINCT CASE WHEN (t.completada = 0 OR t.completada IS NULL) AND t.fecha_fin < CURDATE() AND t.estatus IN (1,2,3,5) THEN t.tarea_id END) AS vencidas,
  COUNT(DISTINCT CASE WHEN t.estatus = 3 THEN t.tarea_id END) AS revision,
  COUNT(DISTINCT CASE WHEN t.estatus = 5 THEN t.tarea_id END) AS rechazadas
FROM milestones m
LEFT JOIN tareas t ON t.milestone_id = m.milestone_id
LEFT JOIN usuarios u ON u.usuario_id = m.responsable_usuario_id
LEFT JOIN estrategias e ON e.estrategia_id = m.estrategia_id
LEFT JOIN objetivo_estrategia oe ON oe.estrategia_id = e.estrategia_id
LEFT JOIN objetivos o ON o.objetivo_id = oe.objetivo_id
GROUP BY
  e.estrategia_id, e.titulo,
  m.milestone_id, m.estrategia_id, m.titulo, m.descripcion, m.estatus, m.prioridad,
  u.nombre_completo, u.usuario_id
ORDER BY m.creado_en DESC, m.milestone_id DESC
";

$milestones = [];
$stmtMilestones = $pdo->query($sqlMilestones);
foreach (($stmtMilestones->fetchAll() ?: []) as $row) {
  $totalTasks = (int)($row['total_tareas'] ?? 0);
  $completedTasks = (int)($row['finalizadas'] ?? 0);
  $progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0.0;
  $milestoneStatus = (int)($row['milestone_estatus'] ?? 0);
  $visual = $milestoneVisual($milestoneStatus, $progress, (int)($row['vencidas'] ?? 0));

  $milestones[] = [
    'milestone_id' => (int)$row['milestone_id'],
    'titulo' => (string)($row['milestone'] ?? 'Sin título'),
    'descripcion' => (string)($row['descripcion'] ?? ''),
    'objetivo' => (string)($row['objetivo'] ?? 'Sin objetivo'),
    'estrategia' => (string)($row['estrategia'] ?? 'Sin estrategia'),
    'responsable' => (string)($row['responsable'] ?? 'Sin asignar'),
    'responsable_id' => (int)($row['responsable_id'] ?? 0),
    'estatus' => $milestoneStatus,
    'estatus_label' => $milestoneStatusText($milestoneStatus),
    'prioridad' => (int)($row['prioridad'] ?? 0),
    'fecha_inicio' => $formatDate($row['fecha_inicio'] ?? null),
    'fecha_fin' => $formatDate($row['fecha_fin'] ?? null),
    'total_tareas' => $totalTasks,
    'finalizadas' => $completedTasks,
    'activas' => (int)($row['activas'] ?? 0),
    'vencidas' => (int)($row['vencidas'] ?? 0),
    'revision' => (int)($row['revision'] ?? 0),
    'rechazadas' => (int)($row['rechazadas'] ?? 0),
    'avance' => $progress,
    'avance_label' => number_format($progress, 0) . '%',
    'visual' => $visual,
  ];
}

$validStatusFilters = ['1', '2', '3', '4', '5'];

$filteredMilestones = array_values(array_filter($milestones, static function (array $item) use ($statusFilter, $search, $validStatusFilters): bool {
  $matchesStatus = true;
  if (in_array($statusFilter, $validStatusFilters, true)) {
    $matchesStatus = (int)$item['estatus'] === (int)$statusFilter;
  }

  if (!$matchesStatus) {
    return false;
  }

  if ($search === '') {
    return true;
  }

  $haystack = mb_strtolower(implode(' ', [
    $item['titulo'] ?? '',
    $item['objetivo'] ?? '',
    $item['estrategia'] ?? '',
    $item['responsable'] ?? '',
  ]));

  return strpos($haystack, mb_strtolower($search)) !== false;
}));

$counters = [
  'todos' => count($milestones),
  '1' => count(array_filter($milestones, static fn(array $item): bool => (int)$item['estatus'] === 1)),
  '2' => count(array_filter($milestones, static fn(array $item): bool => (int)$item['estatus'] === 2)),
  '3' => count(array_filter($milestones, static fn(array $item): bool => (int)$item['estatus'] === 3)),
  '4' => count(array_filter($milestones, static fn(array $item): bool => (int)$item['estatus'] === 4)),
  '5' => count(array_filter($milestones, static fn(array $item): bool => (int)$item['estatus'] === 5)),
];

$detailMilestone = null;
if ($detailMilestoneId > 0) {
  foreach ($milestones as $milestone) {
    if ((int)$milestone['milestone_id'] === $detailMilestoneId) {
      $detailMilestone = $milestone;
      break;
    }
  }

  if ($detailMilestone !== null) {
    $sqlTasks = "
    SELECT
      t.tarea_id,
      t.titulo,
      t.descripcion,
      t.fecha_inicio,
      t.fecha_fin,
      t.estatus,
      t.completada,
      t.completada_en,
      ru.nombre_completo AS responsable,
      t.prioridad
    FROM tareas t
    LEFT JOIN usuarios ru ON ru.usuario_id = t.responsable_usuario_id
    WHERE t.milestone_id = ?
    ORDER BY
      CASE WHEN t.fecha_fin IS NULL THEN 1 ELSE 0 END,
      t.fecha_fin ASC,
      t.tarea_id ASC
    ";

    $stmtTasks = $pdo->prepare($sqlTasks);
    $stmtTasks->execute([$detailMilestoneId]);
    $taskRows = $stmtTasks->fetchAll() ?: [];
    $tasks = [];
    $summary = [
      'total' => 0,
      'aprobadas' => 0,
      'revision' => 0,
      'rechazadas' => 0,
      'vencidas' => 0,
    ];

    foreach ($taskRows as $task) {
      $summary['total']++;
      $completed = (int)($task['completada'] ?? 0);
      $status = (int)($task['estatus'] ?? 0);
      $dueDate = (string)($task['fecha_fin'] ?? '');
      $overdue = $completed !== 1 && $dueDate !== '' && $dueDate < date('Y-m-d') && in_array($status, [1, 2, 3, 5], true);
      if ($completed === 1 || $status === 4) {
        $summary['aprobadas']++;
      }
      if ($status === 3) {
        $summary['revision']++;
      }
      if ($status === 5) {
        $summary['rechazadas']++;
      }
      if ($overdue) {
        $summary['vencidas']++;
      }

      $visual = $taskStatusVisual($status, $completed, $overdue);
      $tasks[] = [
        'tarea_id' => (int)$task['tarea_id'],
        'titulo' => (string)($task['titulo'] ?? 'Sin tarea'),
        'descripcion' => (string)($task['descripcion'] ?? ''),
        'responsable' => (string)($task['responsable'] ?? 'Sin asignar'),
        'fecha_inicio' => $formatDate($task['fecha_inicio'] ?? null),
        'fecha_fin' => $formatDate($task['fecha_fin'] ?? null),
        'estatus' => $status,
        'estatus_label' => $taskStatusText($status, $completed),
        'completada' => $completed,
        'completada_en' => $formatDate($task['completada_en'] ?? null),
        'prioridad' => (int)($task['prioridad'] ?? 0),
        'visual' => $visual,
      ];
    }

    $detailMilestone['tasks'] = $tasks;
    $detailMilestone['task_summary'] = $summary;
  } else {
    $warnings[] = 'No se encontró el milestone solicitado.';
  }
}

$meta = [
  'statusFilter' => $statusFilter,
  'search' => $search,
  'counters' => $counters,
  'filasPorPaginaTareas' => (int)($config['filas_por_pagina_tareas'] ?? 20),
  'hostApp' => (string)($config['host_app'] ?? '/hoshin_kanri'),
  'warnings' => $warnings,
];

return [
  'titulo' => (string)($config['titulo'] ?? 'Proyectos / Milestones'),
  'milestones' => $filteredMilestones,
  'allMilestones' => $milestones,
  'detailMilestone' => $detailMilestone,
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
