<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
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

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = (int)($version ?? time());
$cardsPorPagina = (int)($meta['cardsPorPagina'] ?? ($appConfig['cards_por_pagina'] ?? 12));
$intervaloActualizacion = (int)($meta['intervaloActualizacion'] ?? ($config['intervalo_actualizacion'] ?? 300000));
$statusFilter = (string)($meta['statusFilter'] ?? 'all');
$responsableFilter = (string)($meta['responsableFilter'] ?? '');
$zonaFilter = (string)($meta['zonaFilter'] ?? '');
$areaFilter = (string)($meta['areaFilter'] ?? '');
$tipoProyectoFilter = (string)($meta['tipoProyectoFilter'] ?? '');
$departamentoResponsableFilter = (string)($meta['departamentoResponsableFilter'] ?? '');
$prioridadFilter = (string)($meta['prioridadFilter'] ?? '');
$searchTerm = (string)($meta['searchTerm'] ?? '');
$countsByStatus = (array)($meta['countsByStatus'] ?? []);
$statusOptions = (array)($meta['statusOptions'] ?? []);
$responsables = (array)($meta['responsables'] ?? []);
$zonas = (array)($meta['zonas'] ?? []);
$areas = (array)($meta['areas'] ?? []);
$tiposProyecto = (array)($meta['tiposProyecto'] ?? []);
$departamentosResponsables = (array)($meta['departamentosResponsables'] ?? []);
$prioridades = (array)($meta['prioridades'] ?? []);
$selectedMilestone = (int)($meta['selectedMilestone'] ?? 0);
$hasDetail = false;
$currentStatusFilter = $statusFilter !== '' ? (string)$statusFilter : 'all';
$proyectosDestacados = (array)($proyectos_destacados ?? []);
$destacadosCount = count($proyectosDestacados);

$formatDateLabel = static function (?string $value): string {
  $value = trim((string)$value);
  if ($value === '') {
    return '-';
  }

  try {
    return (new DateTimeImmutable(substr($value, 0, 10)))->format('d/m/Y');
  } catch (Throwable $e) {
    return $value;
  }
};

$baseQuery = [];
if ($statusFilter !== '' && $statusFilter !== 'all') {
  $baseQuery['estatus'] = $statusFilter;
}
if ($responsableFilter !== '') {
  $baseQuery['responsable'] = $responsableFilter;
}
if ($zonaFilter !== '') {
  $baseQuery['zona'] = $zonaFilter;
}
if ($areaFilter !== '') {
  $baseQuery['area'] = $areaFilter;
}
if ($tipoProyectoFilter !== '') {
  $baseQuery['tipo_proyecto'] = $tipoProyectoFilter;
}
if ($departamentoResponsableFilter !== '') {
  $baseQuery['departamento_responsable'] = $departamentoResponsableFilter;
}
if ($prioridadFilter !== '') {
  $baseQuery['prioridad'] = $prioridadFilter;
}
if ($searchTerm !== '') {
  $baseQuery['q'] = $searchTerm;
}

$topBackHref = '../index.php';
$topBackLabel = 'Regresar al inicio';
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
    .projects-shell,
    .projects-filters,
    .projects-detail {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }

    .projects-shell,
    .projects-filters,
    .projects-detail {
      margin-bottom: 22px;
    }

    .projects-filters,
    .projects-detail {
      padding: 18px;
    }

    .projects-kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-bottom: 22px;
    }

    .projects-kpi {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
      padding: 18px;
    }

    .projects-kpi-label {
      color: #475569;
      font-size: 0.85rem;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .projects-kpi-value {
      font-size: clamp(1.9rem, 3vw, 2.8rem);
      font-weight: 800;
      color: #0f172a;
      line-height: 1;
    }

    .projects-kpi-sub {
      margin-top: 10px;
      color: #64748b;
      font-size: 0.9rem;
    }

    .projects-highlights {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
      padding: 20px;
      margin-bottom: 22px;
    }

    .projects-highlight-head {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: flex-end;
      flex-wrap: wrap;
      margin-bottom: 16px;
    }

    .projects-highlight-title {
      color: #0f172a;
      font-size: 1.05rem;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .projects-highlight-sub {
      color: #64748b;
      font-size: 0.9rem;
      line-height: 1.45;
    }

    .projects-highlight-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 14px;
    }

    .projects-highlight-card {
      display: grid;
      gap: 14px;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      background: #f8fafc;
      padding: 16px;
      min-height: 230px;
    }

    .projects-highlight-top {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: flex-start;
    }

    .projects-highlight-rank {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border-radius: 999px;
      background: #0f172a;
      color: #fff;
      font-size: 0.8rem;
      font-weight: 800;
      flex: 0 0 auto;
    }

    .projects-highlight-name {
      color: #0f172a;
      font-size: 1rem;
      font-weight: 800;
      line-height: 1.35;
      margin-bottom: 6px;
    }

    .projects-highlight-meta {
      color: #64748b;
      font-size: 0.82rem;
      line-height: 1.5;
    }

    .projects-highlight-pills {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .projects-highlight-foot {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      align-self: end;
      flex-wrap: wrap;
    }

    .projects-highlight-date {
      color: #475569;
      font-size: 0.82rem;
      font-weight: 700;
    }

    .projects-highlight-actions,
    .projects-inline-actions {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .projects-star-btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      background: #fff;
      color: #334155;
      cursor: pointer;
      font: inherit;
      font-size: 0.78rem;
      font-weight: 800;
      padding: 7px 10px;
      text-decoration: none;
      transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease;
    }

    .projects-star-btn:hover {
      background: #fffbeb;
      border-color: #fde68a;
      color: #b45309;
    }

    .projects-star-btn.is-active {
      background: #fffbeb;
      border-color: #facc15;
      color: #a16207;
    }

    .projects-star-btn:disabled {
      cursor: not-allowed;
      opacity: 0.52;
    }

    .projects-filter-form {
      display: flex;
      gap: 12px;
      align-items: end;
      flex-wrap: wrap;
    }

    .projects-field {
      min-width: 200px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .projects-field label {
      font-size: 0.82rem;
      font-weight: 700;
      color: #475569;
    }

    .projects-field input,
    .projects-field select {
      border: 1px solid #dbe2ea;
      border-radius: 12px;
      padding: 10px 12px;
      font: inherit;
      color: #0f172a;
      background: #fff;
    }

    .projects-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .projects-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 11px 16px;
      font: inherit;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      border: 0;
    }

    .projects-btn-primary {
      background: #2563eb;
      color: #fff;
    }

    .projects-btn-muted {
      background: #fff;
      color: #334155;
      border: 1px solid #dbe2ea;
    }

    .projects-status-pills {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 18px;
    }

    .projects-status-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      border-radius: 999px;
      padding: 10px 14px;
      border: 1px solid #dbe2ea;
      color: #334155;
      background: #fff;
      font-weight: 700;
      transition: all 0.2s ease;
    }

    .projects-status-pill.active {
      color: #fff;
      border-color: transparent;
      box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
    }

    .projects-status-count {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 28px;
      height: 28px;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.20);
      font-size: 0.82rem;
      padding: 0 8px;
    }

    .projects-shell-head {
      padding: 18px 20px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .projects-shell-sub {
      color: #64748b;
      font-size: 0.92rem;
    }

    .project-card-title {
      font-size: 1rem;
      font-weight: 800;
      color: #0f172a;
      line-height: 1.35;
      margin-bottom: 6px;
    }

    .project-card-objective {
      color: #64748b;
      font-size: 0.86rem;
      line-height: 1.4;
    }

    .project-status,
    .project-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.78rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .project-priority {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: 0.78rem;
      font-weight: 800;
      white-space: nowrap;
      background: #f8fafc;
      color: #475569;
      border: 1px solid #e2e8f0;
    }

    .project-progress-wrap {
      padding: 14px;
      border-radius: 16px;
      background: #f8fafc;
      border: 1px solid #e2e8f0;
    }

    .project-progress-head {
      display: flex;
      justify-content: space-between;
      gap: 10px;
      align-items: center;
      margin-bottom: 10px;
      font-size: 0.84rem;
      font-weight: 700;
      color: #334155;
    }

    .project-progress-bar {
      width: 100%;
      height: 12px;
      border-radius: 999px;
      overflow: hidden;
      background: #e2e8f0;
    }

    .project-progress-fill {
      height: 100%;
      border-radius: 999px;
    }

    .project-card-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: #2563eb;
      font-weight: 800;
    }

    .projects-empty {
      padding: 36px 24px;
      text-align: center;
      color: #64748b;
      border-top: 1px solid #e2e8f0;
    }

    .projects-detail-head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
      flex-wrap: wrap;
      margin-bottom: 18px;
    }

    .projects-detail-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 20px;
    }

    .projects-detail-card {
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      background: #f8fafc;
      padding: 14px;
    }

    .projects-detail-label {
      color: #64748b;
      font-size: 0.8rem;
      font-weight: 700;
      margin-bottom: 6px;
    }

    .projects-detail-value {
      color: #0f172a;
      font-weight: 800;
      font-size: 1rem;
      line-height: 1.4;
    }

    .projects-table-wrap {
      overflow-x: auto;
    }

    .projects-table {
      width: 100%;
      min-width: 1120px;
      border-collapse: collapse;
    }

    .projects-table th,
    .projects-table td {
      padding: 13px 14px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      vertical-align: top;
      font-size: 0.92rem;
    }

    .projects-table th {
      color: #475569;
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .projects-table td small {
      display: block;
      color: #64748b;
      margin-top: 4px;
      line-height: 1.45;
    }

    .projects-summary-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #2563eb;
      text-decoration: none;
      font-weight: 800;
    }

    .projects-summary-link:hover {
      text-decoration: underline;
    }

    .projects-table-progress {
      min-width: 180px;
    }

    .projects-table-progress .project-progress-wrap {
      padding: 10px 12px;
      border-radius: 14px;
    }

    @media (max-width: 1200px) {
      .projects-kpis,
      .projects-highlight-grid,
      .projects-detail-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 760px) {
      .projects-kpis,
      .projects-highlight-grid,
      .projects-detail-grid {
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
            href="<?= htmlspecialchars($topBackHref) ?>"
            class="back-btn"
            style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
            <i class="fas fa-arrow-left"></i>
            <?= htmlspecialchars($topBackLabel) ?>
          </a>
        </div>

        <h1><i class="fas fa-diagram-project" style="margin-right:12px;"></i><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-layer-group"></i> Vista ejecutiva por milestone</span>
          <span class="badge"><i class="fas fa-list-check"></i> Avance por tareas completadas</span>
          <span class="year-badge"><i class="fas fa-rotate"></i> Actualización: cada <?= n($intervaloActualizacion / 60000, 0) ?> min</span>
        </div>
      </div>
    </div>

    <?php if (!$hasDetail): ?>
      <div class="projects-kpis">
        <div class="projects-kpi">
          <div class="projects-kpi-label">Total de proyectos</div>
          <div class="projects-kpi-value"><?= n((float)$proyectos_total, 0) ?></div>
          <div class="projects-kpi-sub">Milestones visibles en el reporte</div>
        </div>
        <div class="projects-kpi">
          <div class="projects-kpi-label">Activos</div>
          <div class="projects-kpi-value"><?= n((float)$proyectos_activos, 0) ?></div>
          <div class="projects-kpi-sub">Con estatus operativo vigente</div>
        </div>
        <div class="projects-kpi">
          <div class="projects-kpi-label">Con atraso</div>
          <div class="projects-kpi-value"><?= n((float)$proyectos_con_atraso, 0) ?></div>
          <div class="projects-kpi-sub">Tienen tareas vencidas abiertas</div>
        </div>
        <div class="projects-kpi">
          <div class="projects-kpi-label">Avance promedio</div>
          <div class="projects-kpi-value"><?= n((float)$avance_promedio, 0) ?>%</div>
          <div class="projects-kpi-sub">Promedio general del portafolio</div>
        </div>
      </div>
    <?php endif; ?>

    <?php if (!$hasDetail): ?>
      <section class="projects-highlights">
        <div class="projects-highlight-head">
          <div>
            <div class="projects-highlight-title">Proyectos destacados</div>
            <div class="projects-highlight-sub">Marca hasta 3 proyectos desde la tabla para tenerlos siempre a la vista.</div>
          </div>
          <div class="projects-shell-sub"><?= n((float)$destacadosCount, 0) ?> de 3 destacado(s)</div>
        </div>
        <?php if (!empty($proyectosDestacados)): ?>
          <div class="projects-highlight-grid">
            <?php foreach ($proyectosDestacados as $highlightIndex => $project): ?>
              <?php
              $projectStatus = (array)($project['milestone_status'] ?? ['label' => 'Sin estatus', 'color' => '#94a3b8']);
              $projectHealth = (array)($project['health'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']);
              $projectPriority = trim((string)($project['prioridad_display'] ?? 'Sin prioridad'));
              $priorityColor = '#64748b';
              if (stripos($projectPriority, 'alta') !== false || stripos($projectPriority, 'urg') !== false || stripos($projectPriority, 'cr') !== false) {
                $priorityColor = '#ef4444';
              } elseif (stripos($projectPriority, 'media') !== false || stripos($projectPriority, 'normal') !== false) {
                $priorityColor = '#f59e0b';
              } elseif (stripos($projectPriority, 'baja') !== false) {
                $priorityColor = '#10b981';
              }
              $detailUrl = './detail.php?' . http_build_query(array_merge($baseQuery, ['milestone' => $project['milestone_id']]));
              ?>
              <article class="projects-highlight-card">
                <div class="projects-highlight-top">
                  <div>
                    <div class="projects-highlight-name"><?= htmlspecialchars((string)($project['milestone'] ?? 'Proyecto')) ?></div>
                    <div class="projects-highlight-meta">
                      <?= htmlspecialchars((string)($project['zona'] ?? 'Sin zona')) ?> ·
                      <?= htmlspecialchars((string)($project['area'] ?? 'Sin área')) ?><br>
                      Responsable: <?= htmlspecialchars((string)($project['responsable'] ?? 'Sin responsable')) ?>
                    </div>
                  </div>
                  <span class="projects-highlight-rank"><?= (int)$highlightIndex + 1 ?></span>
                </div>

                <div class="projects-highlight-pills">
                  <span class="project-priority" style="background:<?= htmlspecialchars($priorityColor) ?>1A; color:<?= htmlspecialchars($priorityColor) ?>; border-color:<?= htmlspecialchars($priorityColor) ?>33;">
                    <i class="fas fa-flag"></i><?= htmlspecialchars($projectPriority !== '' ? $projectPriority : 'Sin prioridad') ?>
                  </span>
                  <span class="project-status" style="background:<?= htmlspecialchars($projectHealth['color']) ?>1A; color:<?= htmlspecialchars($projectHealth['color']) ?>;">
                    <i class="fas fa-signal"></i><?= htmlspecialchars((string)($projectHealth['label'] ?? '')) ?>
                  </span>
                </div>

                <div class="project-progress-wrap">
                  <div class="project-progress-head">
                    <span>Avance</span>
                    <strong><?= n((float)($project['avance_real'] ?? 0), 0) ?>%</strong>
                  </div>
                  <div class="project-progress-bar">
                    <div
                      class="project-progress-fill"
                      style="width:<?= max(0, min(100, (float)($project['avance_real'] ?? 0))) ?>%; background:<?= htmlspecialchars((string)($projectHealth['color'] ?? '#2563eb')) ?>;">
                    </div>
                  </div>
                </div>

                <div class="projects-highlight-foot">
                  <div class="projects-highlight-date">
                    <i class="fas fa-calendar-day"></i>
                    <?= htmlspecialchars($formatDateLabel((string)($project['fecha_fin'] ?? ''))) ?>
                  </div>
                  <div class="projects-highlight-actions">
                    <a href="<?= htmlspecialchars($detailUrl) ?>" class="project-card-link">
                      Ver detalle
                      <i class="fas fa-arrow-right"></i>
                    </a>
                    <form method="post">
                      <input type="hidden" name="highlight_action" value="remove">
                      <input type="hidden" name="milestone_id" value="<?= (int)($project['milestone_id'] ?? 0) ?>">
                      <button type="submit" class="projects-star-btn is-active">
                        <i class="fas fa-star"></i>
                        Quitar
                      </button>
                    </form>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="projects-empty" style="border-top:0; padding:18px;">
            <i class="fas fa-star" style="color:#facc15; margin-right:8px;"></i>
            Todavía no hay proyectos destacados. Usa el botón “Destacar” en la tabla.
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <div class="projects-filters">
      <form method="get" class="projects-filter-form">
        <div class="projects-field">
          <label for="q">Buscar proyecto</label>
          <input type="search" id="q" name="q" value="<?= htmlspecialchars($searchTerm) ?>" placeholder="Milestone, responsable, zona o área">
        </div>
        <div class="projects-field">
          <label for="responsable">Responsable</label>
          <select id="responsable" name="responsable">
            <option value="">Todos</option>
            <?php foreach ($responsables as $responsableId => $responsableNombre): ?>
              <option value="<?= htmlspecialchars((string)$responsableId) ?>" <?= $responsableFilter === (string)$responsableId ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$responsableNombre) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="projects-field">
          <label for="zona">Zona</label>
          <select id="zona" name="zona">
            <option value="">Todas</option>
            <?php foreach ($zonas as $zona): ?>
              <option value="<?= htmlspecialchars((string)$zona) ?>" <?= $zonaFilter === (string)$zona ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$zona) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="projects-field">
          <label for="area">Área</label>
          <select id="area" name="area">
            <option value="">Todas</option>
            <?php foreach ($areas as $area): ?>
              <option value="<?= htmlspecialchars((string)$area) ?>" <?= $areaFilter === (string)$area ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$area) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="projects-field">
          <label for="tipo_proyecto">Tipo de proyecto</label>
          <select id="tipo_proyecto" name="tipo_proyecto">
            <option value="">Todos</option>
            <?php foreach ($tiposProyecto as $tipoProyecto): ?>
              <option value="<?= htmlspecialchars((string)$tipoProyecto) ?>" <?= $tipoProyectoFilter === (string)$tipoProyecto ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$tipoProyecto) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="projects-field">
          <label for="departamento_responsable">Depto. responsable</label>
          <select id="departamento_responsable" name="departamento_responsable">
            <option value="">Todos</option>
            <?php foreach ($departamentosResponsables as $departamentoResponsable): ?>
              <option value="<?= htmlspecialchars((string)$departamentoResponsable) ?>" <?= $departamentoResponsableFilter === (string)$departamentoResponsable ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$departamentoResponsable) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="projects-field">
          <label for="prioridad">Prioridad</label>
          <select id="prioridad" name="prioridad">
            <option value="">Todas</option>
            <?php foreach ($prioridades as $prioridad): ?>
              <option value="<?= htmlspecialchars((string)$prioridad) ?>" <?= $prioridadFilter === (string)$prioridad ? 'selected' : '' ?>>
                <?= htmlspecialchars((string)$prioridad) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($currentStatusFilter !== 'all'): ?>
          <input type="hidden" name="estatus" value="<?= htmlspecialchars($currentStatusFilter) ?>">
        <?php endif; ?>
        <div class="projects-actions">
          <button type="submit" class="projects-btn projects-btn-primary"><i class="fas fa-filter"></i> Filtrar</button>
          <a href="?" class="projects-btn projects-btn-muted"><i class="fas fa-rotate-left"></i> Limpiar</a>
        </div>
      </form>

      <div class="projects-status-pills">
        <?php foreach ($statusOptions as $statusKey => $statusMeta): ?>
          <?php
          $statusKeyString = (string)$statusKey;
          $query = $baseQuery;
          if ($statusKeyString !== 'all') {
            $query['estatus'] = $statusKeyString;
          } else {
            unset($query['estatus']);
          }
          $href = '?' . http_build_query($query);
          $isActive = $currentStatusFilter === $statusKeyString;
          $pillColor = (string)($statusMeta['color'] ?? '#0f172a');
          ?>
          <a
            href="<?= htmlspecialchars($href) ?>"
            class="projects-status-pill<?= $isActive ? ' active' : '' ?>"
            style="<?= $isActive ? 'background:' . htmlspecialchars($pillColor) . ';' : '' ?>">
            <span><?= htmlspecialchars((string)($statusMeta['label'] ?? 'Todos')) ?></span>
            <span class="projects-status-count"><?= n((float)($countsByStatus[$statusKey] ?? 0), 0) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="projects-shell">
      <div class="projects-shell-head">
        <div>
          <strong style="display:block; margin-bottom:4px;">Resumen de proyectos</strong>
          <span class="projects-shell-sub">Milestones con su porcentaje de avance, responsable y comportamiento operativo.</span>
        </div>
        <div class="projects-shell-sub"><?= n((float)count($proyectos), 0) ?> proyecto(s) en la vista actual</div>
      </div>

      <?php if (!empty($proyectos)): ?>
        <div class="projects-table-wrap">
          <table class="projects-table">
            <thead>
              <tr>
                <th>Proyecto</th>
                <th>Zona</th>
                <th>Área</th>
                <th>Responsable</th>
                <th>Prioridad</th>
                <th>Estatus</th>
                <th>Semáforo</th>
                <th>Avance</th>
                <th>Fecha fin</th>
                <th>Detalle</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($proyectos as $project): ?>
                <?php
                $projectStatus = (array)($project['milestone_status'] ?? ['label' => 'Sin estatus', 'color' => '#94a3b8']);
                $projectHealth = (array)($project['health'] ?? ['label' => 'Sin dato', 'color' => '#94a3b8']);
                $projectPriority = trim((string)($project['prioridad_display'] ?? 'Sin prioridad'));
                $priorityColor = '#64748b';
                if (stripos($projectPriority, 'alta') !== false || stripos($projectPriority, 'urg') !== false || stripos($projectPriority, 'cr') !== false) {
                  $priorityColor = '#ef4444';
                } elseif (stripos($projectPriority, 'media') !== false || stripos($projectPriority, 'normal') !== false) {
                  $priorityColor = '#f59e0b';
                } elseif (stripos($projectPriority, 'baja') !== false) {
                  $priorityColor = '#10b981';
                }
                $detailUrl = './detail.php?' . http_build_query(array_merge($baseQuery, ['milestone' => $project['milestone_id']]));
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string)($project['milestone'] ?? 'Proyecto')) ?></strong>
                    <?php if (trim((string)($project['milestone_descripcion'] ?? '')) !== ''): ?>
                      <small><?= htmlspecialchars((string)$project['milestone_descripcion']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars((string)($project['zona'] ?? 'Sin zona')) ?></td>
                  <td><?= htmlspecialchars((string)($project['area'] ?? 'Sin área')) ?></td>
                  <td><?= htmlspecialchars((string)($project['responsable'] ?? 'Sin responsable')) ?></td>
                  <td>
                    <span class="project-priority" style="background:<?= htmlspecialchars($priorityColor) ?>1A; color:<?= htmlspecialchars($priorityColor) ?>; border-color:<?= htmlspecialchars($priorityColor) ?>33;">
                      <i class="fas fa-flag"></i><?= htmlspecialchars($projectPriority !== '' ? $projectPriority : 'Sin prioridad') ?>
                    </span>
                  </td>
                  <td>
                    <span class="project-badge" style="background:<?= htmlspecialchars($projectStatus['color']) ?>1A; color:<?= htmlspecialchars($projectStatus['color']) ?>;">
                      <i class="fas fa-circle"></i><?= htmlspecialchars((string)($projectStatus['label'] ?? '')) ?>
                    </span>
                  </td>
                  <td>
                    <span class="project-status" style="background:<?= htmlspecialchars($projectHealth['color']) ?>1A; color:<?= htmlspecialchars($projectHealth['color']) ?>;">
                      <i class="fas fa-signal"></i><?= htmlspecialchars((string)($projectHealth['label'] ?? '')) ?>
                    </span>
                  </td>
                  <td><?= n((float)($project['avance_real'] ?? 0), 0) ?>%</td>
                  <td><?= htmlspecialchars($formatDateLabel((string)($project['fecha_fin'] ?? ''))) ?></td>
                  <td>
                    <div class="projects-inline-actions">
                      <a href="<?= htmlspecialchars($detailUrl) ?>" class="projects-summary-link">
                        Ver detalle
                        <i class="fas fa-arrow-up-right-from-square"></i>
                      </a>
                      <form method="post">
                        <?php $isFeatured = (int)($project['destacado_orden'] ?? 0) > 0; ?>
                        <input type="hidden" name="highlight_action" value="<?= $isFeatured ? 'remove' : 'add' ?>">
                        <input type="hidden" name="milestone_id" value="<?= (int)($project['milestone_id'] ?? 0) ?>">
                        <button
                          type="submit"
                          class="projects-star-btn<?= $isFeatured ? ' is-active' : '' ?>"
                          <?= (!$isFeatured && $destacadosCount >= 3) ? 'disabled' : '' ?>>
                          <i class="<?= $isFeatured ? 'fas' : 'far' ?> fa-star"></i>
                          <?= $isFeatured ? 'Quitar destacado' : 'Destacar' ?>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div class="projects-empty">
          <i class="fas fa-folder-open" style="font-size:2rem; margin-bottom:10px; color:#cbd5e1;"></i>
          <div>No encontramos proyectos con ese filtro.</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function() {
      const scrollKey = 'proyectosMilestonesScrollY';

      if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
      }

      const saveScroll = function() {
        sessionStorage.setItem(scrollKey, String(window.scrollY || document.documentElement.scrollTop || 0));
      };

      const restoreScroll = function() {
        const storedScroll = sessionStorage.getItem(scrollKey);
        if (storedScroll === null) {
          return;
        }

        sessionStorage.removeItem(scrollKey);
        const scrollY = Math.max(0, parseInt(storedScroll, 10) || 0);
        requestAnimationFrame(function() {
          window.scrollTo({ top: scrollY, left: 0, behavior: 'auto' });
          setTimeout(function() {
            window.scrollTo({ top: scrollY, left: 0, behavior: 'auto' });
          }, 80);
        });
      };

      document.addEventListener('DOMContentLoaded', function() {
        restoreScroll();

        const filterForm = document.querySelector('.projects-filter-form');
        if (filterForm) {
          filterForm.addEventListener('submit', saveScroll);
        }

        document.querySelectorAll('.projects-status-pill, .projects-btn-muted').forEach(function(link) {
          link.addEventListener('click', saveScroll);
        });
      });
    })();
  </script>
</body>

</html>
