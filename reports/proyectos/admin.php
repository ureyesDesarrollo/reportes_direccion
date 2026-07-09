<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';

$tables = (array)($config['tablas'] ?? []);
$areasTable = (string)($tables['areas'] ?? 'proyectos_areas');
$sectionsTable = (string)($tables['secciones'] ?? 'proyectos_secciones');
$itemsTable = (string)($tables['items'] ?? 'proyectos_items');

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$quoteIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador SQL invalido: ' . $name);
  }

  return '`' . $name . '`';
};
$safeInt = static fn($value): int => is_numeric($value) ? max(0, (int)$value) : 0;
$clean = static fn($value, int $limit = 255): string => substr(trim((string)$value), 0, $limit);
$safePercent = static fn($value, float $fallback = 0.0): float => is_numeric($value) ? max(0.0, min(100.0, (float)$value)) : $fallback;

$priorityKeyOptions = [
  '' => 'Sin prioridad',
  'very-high' => 'Muy Alta',
  'high' => 'Alta',
  'medium' => 'Media',
  'low' => 'Baja',
];

$statusKeyOptions = [
  'blank' => 'Pendiente',
  'progress' => 'Proceso',
  'not-started' => 'Sin iniciar',
  'waiting' => 'En espera',
  'done' => 'Terminado',
  'percent' => 'Porcentaje',
];

$defaultPriorityLabel = static function (string $key): string {
  return [
    'very-high' => 'Muy Alta',
    'high' => 'Alta',
    'medium' => 'Media',
    'low' => 'Baja',
  ][$key] ?? '';
};

$defaultStatusLabel = static function (string $key): string {
  return [
    'blank' => 'Pendiente',
    'progress' => 'Proceso',
    'not-started' => 'Sin iniciar',
    'waiting' => 'En espera',
    'done' => 'Terminado',
    'percent' => '80%',
  ][$key] ?? 'Pendiente';
};

$message = '';
$error = '';
$sectionRows = [];
$itemRows = [];
$groupedSections = [];

try {
  $pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'hoshin')] ?? []));
  require __DIR__ . '/build_report.php';

  $areasSql = $quoteIdentifier($areasTable);
  $sectionsSql = $quoteIdentifier($sectionsTable);
  $itemsSql = $quoteIdentifier($itemsTable);

  $renumberSection = static function (PDO $pdo, string $itemsSql, int $sectionId): void {
    $rows = $pdo->prepare("SELECT id FROM {$itemsSql} WHERE section_id = :section_id AND activo = 1 ORDER BY orden, id");
    $rows->execute(['section_id' => $sectionId]);
    $ids = array_column($rows->fetchAll() ?: [], 'id');
    $stmt = $pdo->prepare("UPDATE {$itemsSql} SET orden = :orden WHERE id = :id");

    foreach ($ids as $index => $id) {
      $stmt->execute([
        'orden' => $index + 1,
        'id' => (int)$id,
      ]);
    }
  };

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'move_item') {
      $id = $safeInt($_POST['id'] ?? 0);
      $direction = (string)($_POST['direction'] ?? '');
      if ($id <= 0 || !in_array($direction, ['up', 'down'], true)) {
        throw new RuntimeException('Movimiento invalido.');
      }

      $currentStmt = $pdo->prepare("SELECT id, section_id, orden FROM {$itemsSql} WHERE id = :id AND activo = 1");
      $currentStmt->execute(['id' => $id]);
      $current = $currentStmt->fetch();
      if (!$current) {
        throw new RuntimeException('Proyecto no encontrado.');
      }

      $renumberSection($pdo, $itemsSql, (int)$current['section_id']);
      $currentStmt->execute(['id' => $id]);
      $current = $currentStmt->fetch();

      $operator = $direction === 'up' ? '<' : '>';
      $sort = $direction === 'up' ? 'DESC' : 'ASC';
      $neighborStmt = $pdo->prepare("
        SELECT id, orden
        FROM {$itemsSql}
        WHERE section_id = :section_id
          AND activo = 1
          AND orden {$operator} :orden
        ORDER BY orden {$sort}, id {$sort}
        LIMIT 1
      ");
      $neighborStmt->execute([
        'section_id' => (int)$current['section_id'],
        'orden' => (int)$current['orden'],
      ]);
      $neighbor = $neighborStmt->fetch();

      if ($neighbor) {
        $stmt = $pdo->prepare("UPDATE {$itemsSql} SET orden = :orden WHERE id = :id");
        $stmt->execute(['orden' => (int)$neighbor['orden'], 'id' => (int)$current['id']]);
        $stmt->execute(['orden' => (int)$current['orden'], 'id' => (int)$neighbor['id']]);
        $message = 'Orden actualizado.';
      }
    }

    if ($action === 'save_item') {
      $id = $safeInt($_POST['id'] ?? 0);
      $sectionId = $safeInt($_POST['section_id'] ?? 0);
      $priorityKey = $clean($_POST['prioridad_key'] ?? '', 30);
      $priorityLabel = $clean($_POST['prioridad_label'] ?? '', 40);
      $statusKey = $clean($_POST['status_key'] ?? 'blank', 40);
      $statusLabel = $clean($_POST['status_label'] ?? '', 60);

      if ($priorityKey !== '' && $priorityLabel === '') {
        $priorityLabel = $defaultPriorityLabel($priorityKey);
      }
      if ($statusLabel === '') {
        $statusLabel = $defaultStatusLabel($statusKey);
      }

      $payload = [
        'section_id' => $sectionId,
        'nombre' => $clean($_POST['nombre'] ?? ''),
        'prioridad_key' => $priorityKey,
        'prioridad_label' => $priorityLabel,
        'responsable' => $clean($_POST['responsable'] ?? '', 120),
        'inicio' => $clean($_POST['inicio'] ?? '', 40),
        'cierre' => $clean($_POST['cierre'] ?? '', 40),
        'avance_planeado' => $safePercent($_POST['avance_planeado'] ?? 0),
        'avance_real' => $safePercent($_POST['avance_real'] ?? 0),
        'indice_diamante' => $safePercent($_POST['indice_diamante'] ?? 100, 100.0),
        'beneficio_principal' => $clean($_POST['beneficio_principal'] ?? '', 1200),
        'status_key' => $statusKey,
        'status_label' => $statusLabel,
        'orden' => $safeInt($_POST['orden'] ?? 0),
      ];

      if ($payload['section_id'] <= 0 || $payload['nombre'] === '') {
        throw new RuntimeException('Selecciona una seccion y escribe el nombre del proyecto.');
      }

      if ($payload['orden'] <= 0) {
        $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(orden), 0) + 1 FROM {$itemsSql} WHERE section_id = :section_id AND activo = 1");
        $maxStmt->execute(['section_id' => $payload['section_id']]);
        $payload['orden'] = (int)$maxStmt->fetchColumn();
      }

      if ($id > 0) {
        $stmt = $pdo->prepare("
          UPDATE {$itemsSql}
          SET section_id = :section_id,
              nombre = :nombre,
              prioridad_key = :prioridad_key,
              prioridad_label = :prioridad_label,
              responsable = :responsable,
              inicio = :inicio,
              cierre = :cierre,
              avance_planeado = :avance_planeado,
              avance_real = :avance_real,
              indice_diamante = :indice_diamante,
              beneficio_principal = :beneficio_principal,
              status_key = :status_key,
              status_label = :status_label,
              orden = :orden
          WHERE id = :id
        ");
        $payload['id'] = $id;
        $stmt->execute($payload);
        $message = 'Proyecto actualizado.';
      } else {
        $stmt = $pdo->prepare("
          INSERT INTO {$itemsSql}
            (section_id, nombre, prioridad_key, prioridad_label, responsable, inicio, cierre, avance_planeado, avance_real, indice_diamante, beneficio_principal, status_key, status_label, orden)
          VALUES
            (:section_id, :nombre, :prioridad_key, :prioridad_label, :responsable, :inicio, :cierre, :avance_planeado, :avance_real, :indice_diamante, :beneficio_principal, :status_key, :status_label, :orden)
        ");
        $stmt->execute($payload);
        $message = 'Proyecto creado.';
      }

      $renumberSection($pdo, $itemsSql, $payload['section_id']);
    }

    if ($action === 'delete_item') {
      $id = $safeInt($_POST['id'] ?? 0);
      if ($id <= 0) {
        throw new RuntimeException('Proyecto invalido.');
      }

      $sectionStmt = $pdo->prepare("SELECT section_id FROM {$itemsSql} WHERE id = :id");
      $sectionStmt->execute(['id' => $id]);
      $sectionId = (int)$sectionStmt->fetchColumn();

      $stmt = $pdo->prepare("UPDATE {$itemsSql} SET activo = 0 WHERE id = :id");
      $stmt->execute(['id' => $id]);
      if ($sectionId > 0) {
        $renumberSection($pdo, $itemsSql, $sectionId);
      }
      $message = 'Proyecto ocultado.';
    }
  }

  $sectionRows = $pdo->query("
    SELECT s.id, s.nombre, s.orden, a.nombre AS area_nombre, a.orden AS area_orden
    FROM {$sectionsSql} s
    INNER JOIN {$areasSql} a ON a.id = s.area_id
    WHERE s.activo = 1 AND a.activo = 1
    ORDER BY a.orden, s.orden, s.id
  ")->fetchAll() ?: [];

  $itemRows = $pdo->query("
    SELECT i.*, s.nombre AS section_nombre, a.nombre AS area_nombre, a.orden AS area_orden, s.orden AS section_orden
    FROM {$itemsSql} i
    INNER JOIN {$sectionsSql} s ON s.id = i.section_id
    INNER JOIN {$areasSql} a ON a.id = s.area_id
    WHERE i.activo = 1
    ORDER BY a.orden, s.orden, i.orden, i.id
  ")->fetchAll() ?: [];

  foreach ($sectionRows as $section) {
    $groupedSections[(int)$section['id']] = [
      'section' => $section,
      'items' => [],
    ];
  }
  foreach ($itemRows as $item) {
    $sectionId = (int)$item['section_id'];
    if (!isset($groupedSections[$sectionId])) {
      continue;
    }
    $groupedSections[$sectionId]['items'][] = $item;
  }
} catch (Throwable $exception) {
  $error = 'No se pudo conectar o guardar en la base de proyectos: ' . $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Administrar proyectos</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      color: #172033;
      background: #eef2f6;
      font-family: Inter, Arial, sans-serif;
    }

    .shell {
      width: min(1540px, calc(100vw - 32px));
      margin: 0 auto;
      padding: 24px 0 40px;
    }

    .top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 18px;
    }

    h1,
    h2,
    h3 {
      margin: 0;
    }

    h1 {
      margin-bottom: 6px;
      font-size: 30px;
    }

    .sub {
      margin: 0;
      color: #667085;
      font-size: 13px;
      font-weight: 700;
    }

    a,
    button,
    input,
    select,
    textarea {
      font: inherit;
    }

    .actions,
    .row-actions,
    .move-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 36px;
      padding: 8px 12px;
      border: 1px solid #bfdbfe;
      border-radius: 8px;
      color: #075985;
      background: #eff6ff;
      font-size: 13px;
      font-weight: 800;
      text-decoration: none;
      cursor: pointer;
    }

    .btn.primary {
      color: #ffffff;
      background: #2563eb;
      border-color: #1d4ed8;
    }

    .btn.danger {
      color: #991b1b;
      background: #fee2e2;
      border-color: #fecaca;
    }

    .btn.icon {
      width: 36px;
      padding: 0;
      color: #334155;
      background: #f8fafc;
      border-color: #cbd5e1;
    }

    .notice,
    .error {
      margin-bottom: 14px;
      padding: 10px 12px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 800;
    }

    .notice {
      color: #065f46;
      background: #d1fae5;
      border: 1px solid #a7f3d0;
    }

    .toast {
      position: fixed;
      right: 22px;
      bottom: 22px;
      z-index: 50;
      display: flex;
      align-items: center;
      gap: 10px;
      max-width: min(420px, calc(100vw - 32px));
      padding: 12px 14px;
      border: 1px solid #a7f3d0;
      border-radius: 12px;
      color: #064e3b;
      background: #d1fae5;
      box-shadow: 0 18px 44px rgba(15, 23, 42, .18);
      font-size: 13px;
      font-weight: 900;
      transform: translateY(14px);
      opacity: 0;
      pointer-events: none;
      transition: opacity .18s ease, transform .18s ease;
    }

    .toast.is-visible {
      transform: translateY(0);
      opacity: 1;
    }

    .toast::before {
      content: "✓";
      display: inline-grid;
      place-items: center;
      width: 22px;
      height: 22px;
      border-radius: 999px;
      color: #ffffff;
      background: #059669;
      flex: 0 0 auto;
    }

    .error {
      color: #7f1d1d;
      background: #fee2e2;
      border: 1px solid #fecaca;
    }

    .panel,
    .section-panel {
      margin-bottom: 16px;
      padding: 16px;
      border: 1px solid #d9e0ea;
      border-radius: 10px;
      background: #ffffff;
      box-shadow: 0 14px 34px rgba(23, 32, 51, 0.08);
    }

    .panel h2 {
      margin-bottom: 12px;
      font-size: 18px;
    }

    .form-grid,
    .project-grid {
      display: grid;
      grid-template-columns: minmax(420px, 1.5fr) minmax(260px, .8fr) minmax(300px, 1fr) minmax(300px, 1fr);
      gap: 10px;
      align-items: start;
    }

    .project-grid {
      grid-template-columns: minmax(420px, 1.5fr) minmax(260px, .8fr) minmax(300px, 1fr) minmax(300px, 1fr);
    }

    label {
      display: grid;
      gap: 5px;
      min-width: 0;
      color: #475569;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
    }

    input,
    select,
    textarea {
      width: 100%;
      min-height: 36px;
      padding: 8px 10px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      color: #172033;
      background: #f8fafc;
      font-size: 13px;
      font-weight: 700;
    }

    textarea {
      min-height: 62px;
      resize: vertical;
      line-height: 1.35;
    }

    .section-list {
      display: grid;
      gap: 16px;
    }

    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
      padding-bottom: 10px;
      border-bottom: 1px solid #e2e8f0;
    }

    .section-head h3 {
      font-size: 16px;
    }

    .section-head span {
      color: #64748b;
      font-size: 12px;
      font-weight: 800;
    }

    .project-card {
      display: grid;
      grid-template-columns: 48px minmax(0, 1fr);
      gap: 10px;
      padding: 12px 0;
      border-bottom: 1px solid #e2e8f0;
    }

    .project-card:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .move-actions {
      align-content: start;
      justify-content: center;
    }

    .meta-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(120px, 1fr));
      gap: 10px;
      min-width: 0;
    }

    .row-actions {
      justify-content: flex-end;
      margin-top: 10px;
    }

    .delete-form {
      justify-content: flex-end;
      margin-top: 10px;
    }

    .btn:disabled {
      opacity: 0.42;
      cursor: not-allowed;
    }

    .empty {
      padding: 12px;
      border-radius: 8px;
      color: #64748b;
      background: #f8fafc;
      font-size: 13px;
      font-weight: 800;
      text-align: center;
    }

    @media (max-width: 1180px) {
      .form-grid,
      .project-grid {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 720px) {
      .shell {
        width: min(100%, calc(100vw - 20px));
      }

      .top,
      .section-head {
        flex-direction: column;
        align-items: flex-start;
      }

      .form-grid,
      .project-grid,
      .meta-grid,
      .project-card {
        grid-template-columns: 1fr;
      }

      .move-actions {
        flex-direction: row;
        justify-content: flex-start;
      }

      .toast {
        right: 10px;
        bottom: 10px;
        left: 10px;
        max-width: none;
      }
    }
  </style>
</head>

<body>
  <main class="shell">
    <div class="top">
      <div>
        <h1>Administrar proyectos</h1>
        <p class="sub">Edicion agrupada por area y seccion. El orden se cambia con flechas.</p>
      </div>
      <div class="actions">
        <a class="btn" href="index.php">Ver tablero</a>
        <a class="btn" href="../index.php" data-smart-back="reports-index">Inicio</a>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="error"><?= $e($error) ?></div>
    <?php endif; ?>

    <?php if ($message !== ''): ?>
      <div class="toast" role="status" aria-live="polite" data-toast><?= $e($message) ?></div>
    <?php endif; ?>

    <?php if ($error === ''): ?>
      <section class="panel">
        <h2>Nuevo proyecto</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="action" value="save_item">
          <input type="hidden" name="id" value="0">
          <label>Proyecto
            <textarea name="nombre" required></textarea>
          </label>
          <label>Seccion
            <select name="section_id" required>
              <option value="">Seleccionar</option>
              <?php foreach ($sectionRows as $section): ?>
                <option value="<?= $e($section['id']) ?>"><?= $e($section['area_nombre'] . ' / ' . $section['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <div class="meta-grid">
            <label>Prioridad tipo
              <select name="prioridad_key">
                <?php foreach ($priorityKeyOptions as $value => $label): ?>
                  <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Prioridad etiqueta
              <input name="prioridad_label" placeholder="Alta">
            </label>
          </div>
          <div class="meta-grid">
            <label>Estatus tipo
              <select name="status_key">
                <?php foreach ($statusKeyOptions as $value => $label): ?>
                  <option value="<?= $e($value) ?>"><?= $e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Estatus etiqueta
              <input name="status_label" placeholder="Pendiente">
            </label>
          </div>
          <label>Responsable
            <input name="responsable">
          </label>
          <label>Inicio
            <input name="inicio" placeholder="11-Jun">
          </label>
          <label>Cierre
            <input name="cierre" placeholder="30-Jul">
          </label>
          <div class="meta-grid">
            <label>Avance planeado %
              <input name="avance_planeado" type="number" min="0" max="100" step="0.01" value="0">
            </label>
            <label>Avance real %
              <input name="avance_real" type="number" min="0" max="100" step="0.01" value="0">
            </label>
          </div>
          <label>Índice diamante
            <input name="indice_diamante" type="number" min="0" max="100" step="0.01" value="100">
          </label>
          <label>Beneficio principal
            <textarea name="beneficio_principal" placeholder="Impacto principal esperado del proyecto"></textarea>
          </label>
          <input type="hidden" name="orden" value="0">
          <div class="actions">
            <button class="btn primary" type="submit">Guardar proyecto</button>
          </div>
        </form>
      </section>

      <div class="section-list">
        <?php foreach ($groupedSections as $group): ?>
          <?php
          $section = (array)$group['section'];
          $items = (array)$group['items'];
          ?>
          <section class="section-panel">
            <div class="section-head">
              <h3><?= $e($section['area_nombre'] . ' / ' . $section['nombre']) ?></h3>
              <span><?= count($items) ?> proyecto(s)</span>
            </div>

            <?php if (empty($items)): ?>
              <div class="empty">Sin proyectos en esta seccion.</div>
            <?php endif; ?>

            <?php foreach ($items as $index => $item): ?>
              <article class="project-card">
                <div class="move-actions">
                  <form method="post">
                    <input type="hidden" name="action" value="move_item">
                    <input type="hidden" name="id" value="<?= $e($item['id']) ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn icon" type="submit" title="Subir" <?= $index === 0 ? 'disabled' : '' ?>>↑</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="action" value="move_item">
                    <input type="hidden" name="id" value="<?= $e($item['id']) ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn icon" type="submit" title="Bajar" <?= $index === count($items) - 1 ? 'disabled' : '' ?>>↓</button>
                  </form>
                </div>

                <div>
                  <form method="post" class="project-grid">
                    <input type="hidden" name="action" value="save_item">
                    <input type="hidden" name="id" value="<?= $e($item['id']) ?>">
                    <input type="hidden" name="orden" value="<?= $e($item['orden']) ?>">
                    <label>Proyecto
                      <textarea name="nombre" required><?= $e($item['nombre']) ?></textarea>
                    </label>
                    <label>Seccion
                      <select name="section_id" required>
                        <?php foreach ($sectionRows as $sectionOption): ?>
                          <option value="<?= $e($sectionOption['id']) ?>" <?= (int)$sectionOption['id'] === (int)$item['section_id'] ? 'selected' : '' ?>>
                            <?= $e($sectionOption['area_nombre'] . ' / ' . $sectionOption['nombre']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <div class="meta-grid">
                      <label>Prioridad tipo
                        <select name="prioridad_key">
                          <?php foreach ($priorityKeyOptions as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= $value === (string)$item['prioridad_key'] ? 'selected' : '' ?>><?= $e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Prioridad etiqueta
                        <input name="prioridad_label" value="<?= $e($item['prioridad_label']) ?>" placeholder="Alta">
                      </label>
                    </div>
                    <div class="meta-grid">
                      <label>Estatus tipo
                        <select name="status_key">
                          <?php foreach ($statusKeyOptions as $value => $label): ?>
                            <option value="<?= $e($value) ?>" <?= $value === (string)$item['status_key'] ? 'selected' : '' ?>><?= $e($label) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                      <label>Estatus etiqueta
                        <input name="status_label" value="<?= $e($item['status_label']) ?>" placeholder="Pendiente">
                      </label>
                    </div>
                    <label>Responsable
                      <input name="responsable" value="<?= $e($item['responsable']) ?>">
                    </label>
                    <label>Inicio
                      <input name="inicio" value="<?= $e($item['inicio']) ?>">
                    </label>
                    <label>Cierre
                      <input name="cierre" value="<?= $e($item['cierre']) ?>">
                    </label>
                    <div class="meta-grid">
                      <label>Avance planeado %
                        <input name="avance_planeado" type="number" min="0" max="100" step="0.01" value="<?= $e($item['avance_planeado'] ?? 0) ?>">
                      </label>
                      <label>Avance real %
                        <input name="avance_real" type="number" min="0" max="100" step="0.01" value="<?= $e($item['avance_real'] ?? 0) ?>">
                      </label>
                    </div>
                    <label>Índice diamante
                      <input name="indice_diamante" type="number" min="0" max="100" step="0.01" value="<?= $e($item['indice_diamante'] ?? 100) ?>">
                    </label>
                    <label>Beneficio principal
                      <textarea name="beneficio_principal" placeholder="Impacto principal esperado del proyecto"><?= $e($item['beneficio_principal'] ?? '') ?></textarea>
                    </label>
                    <div class="row-actions">
                      <button class="btn primary" type="submit">Guardar</button>
                    </div>
                  </form>
                  <form method="post" class="row-actions delete-form" onsubmit="return confirm('Ocultar este proyecto?');">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="id" value="<?= $e($item['id']) ?>">
                    <button class="btn danger" type="submit">Ocultar</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </main>
  <script>
    (() => {
      const defaults = {
        prioridad_key: {
          'very-high': 'Muy Alta',
          high: 'Alta',
          medium: 'Media',
          low: 'Baja',
          '': '',
        },
        status_key: {
          blank: 'Pendiente',
          progress: 'Proceso',
          'not-started': 'Sin iniciar',
          waiting: 'En espera',
          done: 'Terminado',
          percent: '80%',
        },
      };

      const bindDefaultLabel = (selectName, inputName) => {
        document.querySelectorAll(`select[name="${selectName}"]`).forEach((select) => {
          const container = select.closest('.meta-grid');
          const input = container ? container.querySelector(`input[name="${inputName}"]`) : null;
          if (!input) return;

          input.dataset.lastDefault = defaults[selectName][select.value] || '';
          select.addEventListener('change', () => {
            const previousDefault = input.dataset.lastDefault || '';
            const nextDefault = defaults[selectName][select.value] || '';
            if (input.value.trim() === '' || input.value.trim() === previousDefault) {
              input.value = nextDefault;
            }
            input.dataset.lastDefault = nextDefault;
          });
        });
      };

      bindDefaultLabel('prioridad_key', 'prioridad_label');
      bindDefaultLabel('status_key', 'status_label');

      const scrollKey = 'proyectos_admin_scroll_y';
      document.querySelectorAll('form[method="post"]').forEach((form) => {
        form.addEventListener('submit', () => {
          sessionStorage.setItem(scrollKey, String(window.scrollY || 0));
        });
      });

      const savedScroll = sessionStorage.getItem(scrollKey);
      if (savedScroll !== null) {
        sessionStorage.removeItem(scrollKey);
        const y = Number(savedScroll);
        if (Number.isFinite(y) && y > 0) {
          requestAnimationFrame(() => window.scrollTo({ top: y, behavior: 'auto' }));
        }
      }

      const toast = document.querySelector('[data-toast]');
      if (toast) {
        requestAnimationFrame(() => toast.classList.add('is-visible'));
        window.setTimeout(() => {
          toast.classList.remove('is-visible');
        }, 2800);
      }
    })();
  </script>
</body>

</html>
