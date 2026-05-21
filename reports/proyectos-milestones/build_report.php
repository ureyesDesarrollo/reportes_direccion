<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$databaseConfig = require __DIR__ . '/../../config/database.php';

$connectMysql = static function (array $cfg): PDO {
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

$milestoneStatuses = (array)($config['milestone_estatus'] ?? []);
$dueSoonDays = max(1, (int)($config['dias_alerta_vencimiento'] ?? 7));
$statusFilter = trim((string)($_GET['estatus'] ?? ''));
$responsableFilter = trim((string)($_GET['responsable'] ?? ''));
$zonaFilter = trim((string)($_GET['zona'] ?? ''));
$areaFilter = trim((string)($_GET['area'] ?? ''));
$tipoProyectoFilter = trim((string)($_GET['tipo_proyecto'] ?? ''));
$departamentoResponsableFilter = trim((string)($_GET['departamento_responsable'] ?? ''));
$prioridadFilter = trim((string)($_GET['prioridad'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$milestoneSelected = (int)($_GET['milestone'] ?? 0);
$empresaId = (int)($config['empresa_id'] ?? 0);

$resolveMilestoneStatus = static function (int $statusId) use ($milestoneStatuses): array {
  return $milestoneStatuses[$statusId] ?? ['label' => 'Sin estatus', 'color' => '#94a3b8'];
};

$resolveProjectHealth = static function (array $project) use ($dueSoonDays): array {
  $statusId = (int)($project['milestone_estatus'] ?? 0);
  $dueDate = trim((string)($project['fecha_fin'] ?? ''));
  $today = new DateTimeImmutable('today');

  if ($statusId === 2) {
    return ['label' => 'Cerrado', 'key' => 'verde', 'color' => '#10b981'];
  }

  if ($statusId === 5) {
    return ['label' => 'Descontinuado', 'key' => 'gris', 'color' => '#64748b'];
  }

  if ($statusId === 3) {
    return ['label' => 'Inactivo', 'key' => 'gris', 'color' => '#94a3b8'];
  }

  if ($statusId === 4) {
    return ['label' => 'Pausado', 'key' => 'amarillo', 'color' => '#f59e0b'];
  }

  if ((int)($project['tareas_vencidas'] ?? 0) > 0) {
    return ['label' => 'Con atraso', 'key' => 'rojo', 'color' => '#ef4444'];
  }

  if ((int)($project['tareas_completadas_tarde'] ?? 0) > 0) {
    return ['label' => 'Atención', 'key' => 'amarillo', 'color' => '#f59e0b'];
  }

  if ($dueDate !== '') {
    try {
      $due = new DateTimeImmutable($dueDate);
      $daysLeft = (int)$today->diff($due)->format('%r%a');
      if ($daysLeft <= $dueSoonDays && (float)($project['avance_real'] ?? 0) < 100.0) {
        return ['label' => 'Por vencer', 'key' => 'amarillo', 'color' => '#f59e0b'];
      }
    } catch (Throwable $e) {
    }
  }

  if ((float)($project['avance_real'] ?? 0) >= 100.0) {
    return ['label' => 'Completo', 'key' => 'verde', 'color' => '#10b981'];
  }

  return ['label' => 'En curso', 'key' => 'verde', 'color' => '#10b981'];
};

$normalizeTaskStatus = static function (array $task): array {
  $statusId = (int)($task['estatus'] ?? 0);
  $completed = (int)($task['completada'] ?? 0) === 1;
  $completedAt = trim((string)($task['completada_en'] ?? ''));
  $dueDate = trim((string)($task['fecha_fin'] ?? ''));
  $today = date('Y-m-d');
  $completedLate = $statusId === 6 || ($completed && $completedAt !== '' && $dueDate !== '' && substr($completedAt, 0, 10) > $dueDate);
  $finishedOnTime = $statusId === 4 || ($completed && !$completedLate);
  $overdue = !$completed && !in_array($statusId, [4, 6], true) && $dueDate !== '' && $dueDate < $today;

  if ($completedLate) {
    return ['label' => 'Completada tarde', 'color' => '#ef4444'];
  }

  if ($finishedOnTime) {
    return ['label' => 'Terminada', 'color' => '#10b981'];
  }

  if ($overdue) {
    return ['label' => 'Vencida', 'color' => '#ef4444'];
  }

  if ($statusId === 3) {
    return ['label' => 'En revisión', 'color' => '#f59e0b'];
  }

  if ($statusId === 5) {
    return ['label' => 'Rechazada', 'color' => '#ef4444'];
  }

  if ($statusId === 2) {
    return ['label' => 'En progreso', 'color' => '#2563eb'];
  }

  return ['label' => 'Abierta', 'color' => '#94a3b8'];
};

$pdo = $connectMysql((array)($databaseConfig[$config['database_key']] ?? []));

$summarySql = "
SELECT
  v.proyecto_directivo_id,
  v.milestone_id,
  v.estrategia_id,
  v.milestone,
  m.descripcion AS milestone_descripcion,
  v.milestone_estatus,
  m.prioridad,
  m.responsable_usuario_id AS responsable_id,
  COALESCE(v.responsable, 'Sin responsable') AS responsable,
  COALESCE(v.zona, 'Sin zona') AS zona,
  COALESCE(v.area, 'Sin área') AS area,
  COALESCE(v.tipo_proyecto, 'Sin tipo') AS tipo_proyecto,
  COALESCE(v.responsable_area, 'Sin departamento') AS responsable_area,
  v.estado_directivo,
  v.prioridad_directiva,
  v.visible_en_direccion,
  v.requiere_reporte_direccion,
  v.fecha_inicio_operativa AS fecha_inicio,
  v.fecha_fin_operativa AS fecha_fin,
  v.total_tareas,
  v.tareas_finalizadas,
  v.tareas_vencidas,
  v.tareas_completadas_tarde,
  v.avance_real
FROM vista_proyectos_directivos v
JOIN milestones m
  ON m.milestone_id = v.milestone_id
JOIN estrategias e
  ON e.estrategia_id = v.estrategia_id
WHERE 1 = 1
";

$summaryParams = [];
if ($empresaId > 0) {
  $summarySql .= " AND v.empresa_id = ? ";
  $summaryParams[] = $empresaId;
}
$summarySql .= " AND (COALESCE(v.visible_en_direccion, 0) = 1 OR COALESCE(v.requiere_reporte_direccion, 0) = 1) ";
if ($responsableFilter !== '') {
  $summarySql .= " AND m.responsable_usuario_id = ? ";
  $summaryParams[] = (int)$responsableFilter;
}
if ($zonaFilter !== '') {
  $summarySql .= " AND COALESCE(v.zona, 'Sin zona') = ? ";
  $summaryParams[] = $zonaFilter;
}
if ($areaFilter !== '') {
  $summarySql .= " AND COALESCE(v.area, 'Sin área') = ? ";
  $summaryParams[] = $areaFilter;
}
if ($tipoProyectoFilter !== '') {
  $summarySql .= " AND COALESCE(v.tipo_proyecto, 'Sin tipo') = ? ";
  $summaryParams[] = $tipoProyectoFilter;
}
if ($departamentoResponsableFilter !== '') {
  $summarySql .= " AND COALESCE(v.responsable_area, 'Sin departamento') = ? ";
  $summaryParams[] = $departamentoResponsableFilter;
}
if ($searchTerm !== '') {
  $summarySql .= " AND (
    v.milestone LIKE ?
    OR COALESCE(m.descripcion, '') LIKE ?
    OR COALESCE(v.responsable, '') LIKE ?
    OR COALESCE(v.zona, '') LIKE ?
    OR COALESCE(v.area, '') LIKE ?
  ) ";
  $searchLike = '%' . $searchTerm . '%';
  $summaryParams[] = $searchLike;
  $summaryParams[] = $searchLike;
  $summaryParams[] = $searchLike;
  $summaryParams[] = $searchLike;
  $summaryParams[] = $searchLike;
}

$stmt = $pdo->prepare($summarySql);
$stmt->execute($summaryParams);
$projectsAll = [];
$responsables = [];
$zonas = [];
$areas = [];
$tiposProyecto = [];
$departamentosResponsables = [];
$prioridades = [];
$countsByStatus = ['all' => 0];

foreach (($stmt->fetchAll() ?: []) as $row) {
  $row['milestone_estatus'] = (int)($row['milestone_estatus'] ?? 0);
  $row['total_tareas'] = (int)($row['total_tareas'] ?? 0);
  $row['tareas_finalizadas'] = (int)($row['tareas_finalizadas'] ?? 0);
  $row['tareas_vencidas'] = (int)($row['tareas_vencidas'] ?? 0);
  $row['tareas_completadas_tarde'] = (int)($row['tareas_completadas_tarde'] ?? 0);
  $row['avance_real'] = (float)($row['avance_real'] ?? 0);
  $row['milestone_status'] = $resolveMilestoneStatus($row['milestone_estatus']);
  $row['health'] = $resolveProjectHealth($row);
  $row['pendientes'] = max(0, $row['total_tareas'] - $row['tareas_finalizadas']);
  $prioridadNombre = trim((string)($row['prioridad_directiva'] ?? ''));
  if ($prioridadNombre === '') {
    $prioridadNombre = trim((string)($row['prioridad'] ?? ''));
  }
  $row['prioridad_display'] = $prioridadNombre !== '' ? $prioridadNombre : 'Sin prioridad';

  $projectsAll[] = $row;
  $countsByStatus['all']++;
  $statusKey = (string)$row['milestone_estatus'];
  $countsByStatus[$statusKey] = (int)($countsByStatus[$statusKey] ?? 0) + 1;
  $responsableId = trim((string)($row['responsable_id'] ?? ''));
  $responsableNombre = trim((string)($row['responsable'] ?? 'Sin responsable'));
  if ($responsableId !== '') {
    $responsables[$responsableId] = $responsableNombre !== '' ? $responsableNombre : 'Sin responsable';
  }

  $zonaNombre = trim((string)($row['zona'] ?? ''));
  if ($zonaNombre !== '') {
    $zonas[$zonaNombre] = $zonaNombre;
  }

  $areaNombre = trim((string)($row['area'] ?? ''));
  if ($areaNombre !== '') {
    $areas[$areaNombre] = $areaNombre;
  }

  $tipoNombre = trim((string)($row['tipo_proyecto'] ?? ''));
  if ($tipoNombre !== '') {
    $tiposProyecto[$tipoNombre] = $tipoNombre;
  }

  $deptoNombre = trim((string)($row['responsable_area'] ?? ''));
  if ($deptoNombre !== '') {
    $departamentosResponsables[$deptoNombre] = $deptoNombre;
  }

  $prioridadNombre = trim((string)($row['prioridad_display'] ?? ''));
  if ($prioridadNombre !== '') {
    $prioridades[$prioridadNombre] = $prioridadNombre;
  }
}

usort($projectsAll, static function (array $a, array $b): int {
  $priorityOrder = [
    'alta' => 10,
    'urg' => 10,
    'crit' => 10,
    'cr' => 10,
    'media' => 20,
    'normal' => 20,
    'baja' => 30,
  ];

  $determinePriority = static function (string $value) use ($priorityOrder): int {
    $normalized = strtolower(trim($value));
    foreach ($priorityOrder as $token => $order) {
      if ($normalized !== '' && stripos($normalized, $token) !== false) {
        return $order;
      }
    }
    return 40;
  };

  $priorityA = $determinePriority((string)($a['prioridad_display'] ?? ''));
  $priorityB = $determinePriority((string)($b['prioridad_display'] ?? ''));
  if ($priorityA !== $priorityB) {
    return $priorityA <=> $priorityB;
  }

  $statusOrder = [
    1 => 10,
    4 => 20,
    3 => 30,
    5 => 40,
    2 => 50,
  ];
  $statusA = $statusOrder[(int)($a['milestone_estatus'] ?? 0)] ?? 99;
  $statusB = $statusOrder[(int)($b['milestone_estatus'] ?? 0)] ?? 99;
  if ($statusA !== $statusB) {
    return $statusA <=> $statusB;
  }

  $dateA = (string)($a['fecha_fin'] ?? '');
  $dateB = (string)($b['fecha_fin'] ?? '');
  if ($dateA === $dateB) {
    return strcasecmp((string)($a['milestone'] ?? ''), (string)($b['milestone'] ?? ''));
  }

  if ($dateA === '') {
    return 1;
  }
  if ($dateB === '') {
    return -1;
  }

  return strcmp($dateA, $dateB);
});

$projects = array_values(array_filter($projectsAll, static function (array $project) use ($statusFilter, $prioridadFilter): bool {
  if ($statusFilter === '' || $statusFilter === 'all') {
    $matchesStatus = true;
  } else {
    $matchesStatus = (string)($project['milestone_estatus'] ?? '') === $statusFilter;
  }

  if (!$matchesStatus) {
    return false;
  }

  if ($prioridadFilter === '') {
    return true;
  }

  return (string)($project['prioridad_display'] ?? 'Sin prioridad') === $prioridadFilter;
}));

$totalProjects = count($projectsAll);
$activeProjects = 0;
$closedProjects = 0;
$lateProjects = 0;
$averageProgress = 0.0;
foreach ($projectsAll as $project) {
  if ((int)($project['milestone_estatus'] ?? 0) === 1) {
    $activeProjects++;
  }
  if ((int)($project['milestone_estatus'] ?? 0) === 2) {
    $closedProjects++;
  }
  if ((int)($project['tareas_vencidas'] ?? 0) > 0) {
    $lateProjects++;
  }
  $averageProgress += (float)($project['avance_real'] ?? 0);
}
$averageProgress = $totalProjects > 0 ? round($averageProgress / $totalProjects, 1) : 0.0;

$detailProject = null;
$detailTasks = [];
if ($milestoneSelected > 0) {
  foreach ($projectsAll as $project) {
    if ((int)($project['milestone_id'] ?? 0) === $milestoneSelected) {
      $detailProject = $project;
      break;
    }
  }

  if ($detailProject === null) {
    throw new RuntimeException('No se encontró el milestone solicitado.');
  }

  $tasksSql = "
  SELECT
    t.tarea_id,
    t.titulo,
    t.descripcion,
    t.fecha_inicio,
    t.fecha_fin,
    t.completada,
    t.completada_en,
    t.estatus,
    t.prioridad,
    COALESCE(u.nombre_completo, 'Sin responsable') AS responsable
  FROM tareas t
  LEFT JOIN usuarios u
    ON u.usuario_id = t.responsable_usuario_id
  WHERE t.milestone_id = ?
  ORDER BY
    CASE WHEN t.fecha_fin IS NULL THEN 1 ELSE 0 END,
    t.fecha_fin ASC,
    t.tarea_id ASC
  ";
  $taskStmt = $pdo->prepare($tasksSql);
  $taskStmt->execute([$milestoneSelected]);
  foreach (($taskStmt->fetchAll() ?: []) as $task) {
    $task['status_visual'] = $normalizeTaskStatus($task);
    $detailTasks[] = $task;
  }
}

ksort($responsables, SORT_NATURAL);
ksort($zonas, SORT_NATURAL);
ksort($areas, SORT_NATURAL);
ksort($tiposProyecto, SORT_NATURAL);
ksort($departamentosResponsables, SORT_NATURAL);
ksort($prioridades, SORT_NATURAL);

$statusOptions = ['all' => ['label' => 'Todos', 'color' => '#0f172a']];
foreach ($milestoneStatuses as $statusId => $statusMeta) {
  $statusOptions[(string)$statusId] = [
    'label' => (string)($statusMeta['label'] ?? ('Estatus ' . $statusId)),
    'color' => (string)($statusMeta['color'] ?? '#94a3b8'),
  ];
}

$meta = [
  'statusFilter' => $statusFilter === '' ? 'all' : $statusFilter,
  'responsableFilter' => $responsableFilter,
  'zonaFilter' => $zonaFilter,
  'areaFilter' => $areaFilter,
  'tipoProyectoFilter' => $tipoProyectoFilter,
  'departamentoResponsableFilter' => $departamentoResponsableFilter,
  'prioridadFilter' => $prioridadFilter,
  'searchTerm' => $searchTerm,
  'cardsPorPagina' => (int)($config['cards_por_pagina'] ?? 12),
  'intervaloActualizacion' => (int)($config['intervalo_actualizacion'] ?? 300000),
  'countsByStatus' => $countsByStatus,
  'statusOptions' => $statusOptions,
  'responsables' => $responsables,
  'zonas' => $zonas,
  'areas' => $areas,
  'tiposProyecto' => $tiposProyecto,
  'departamentosResponsables' => $departamentosResponsables,
  'prioridades' => $prioridades,
  'selectedMilestone' => $milestoneSelected,
];

return [
  'titulo' => (string)($config['titulo'] ?? 'Proyectos / Milestones'),
  'proyectos' => $projects,
  'proyectos_total' => $totalProjects,
  'proyectos_activos' => $activeProjects,
  'proyectos_cerrados' => $closedProjects,
  'proyectos_con_atraso' => $lateProjects,
  'avance_promedio' => $averageProgress,
  'detalle_proyecto' => $detailProject,
  'detalle_tareas' => $detailTasks,
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time()
  ),
];
