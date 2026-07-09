<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';

$seedAreas = require __DIR__ . '/seed.php';
$tables = (array)($config['tablas'] ?? []);
$areasTable = (string)($tables['areas'] ?? 'proyectos_areas');
$sectionsTable = (string)($tables['secciones'] ?? 'proyectos_secciones');
$itemsTable = (string)($tables['items'] ?? 'proyectos_items');

$quoteIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador SQL invalido: ' . $name);
  }

  return '`' . $name . '`';
};

$normalizeItem = static function (array $item, int $order = 0): array {
  $statusKey = (string)($item['status_key'] ?? 'waiting');
  $statusLabel = (string)($item['status_label'] ?? ($statusKey === 'waiting' ? 'En espera' : 'Pendiente'));
  $avancePlaneado = is_numeric($item['avance_planeado'] ?? null) ? (float)$item['avance_planeado'] : 0.0;
  $avanceReal = is_numeric($item['avance_real'] ?? null) ? (float)$item['avance_real'] : 0.0;
  $indiceDiamante = is_numeric($item['indice_diamante'] ?? null) ? (float)$item['indice_diamante'] : 100.0;

  return [
    'id' => isset($item['id']) ? (int)$item['id'] : null,
    'nombre' => (string)($item['nombre'] ?? ''),
    'prioridad_key' => (string)($item['prioridad_key'] ?? ''),
    'prioridad_label' => (string)($item['prioridad_label'] ?? ''),
    'responsable' => (string)($item['responsable'] ?? ''),
    'inicio' => (string)($item['inicio'] ?? ''),
    'cierre' => (string)($item['cierre'] ?? ''),
    'avance_planeado' => max(0.0, min(100.0, $avancePlaneado)),
    'avance_real' => max(0.0, min(100.0, $avanceReal)),
    'indice_diamante' => max(0.0, min(100.0, $indiceDiamante)),
    'beneficio_principal' => (string)($item['beneficio_principal'] ?? ''),
    'status_key' => $statusKey,
    'status_label' => $statusLabel,
    'orden' => (int)($item['orden'] ?? $order),
  ];
};

$buildFromSeed = static function () use ($seedAreas, $normalizeItem): array {
  $areas = [];
  $totalProjects = 0;

  foreach ($seedAreas as $areaIndex => $area) {
    $sections = [];
    foreach ((array)($area['secciones'] ?? []) as $sectionIndex => $section) {
      $items = [];
      foreach ((array)($section['items'] ?? []) as $itemIndex => $item) {
        $items[] = $normalizeItem((array)$item, $itemIndex + 1);
      }
      $totalProjects += count($items);
      $sections[] = [
        'id' => null,
        'slug' => (string)($section['slug'] ?? ''),
        'nombre' => (string)($section['nombre'] ?? ''),
        'orden' => $sectionIndex + 1,
        'items' => $items,
      ];
    }

    $areas[] = [
      'id' => null,
      'slug' => (string)($area['slug'] ?? ''),
      'nombre' => (string)($area['nombre'] ?? ''),
      'accent' => (string)($area['accent'] ?? 'blue'),
      'orden' => $areaIndex + 1,
      'secciones' => $sections,
    ];
  }

  return [$areas, $totalProjects];
};

$priorityRank = static function (array $item): int {
  $key = strtolower(trim((string)($item['prioridad_key'] ?? '')));
  $label = mb_strtolower(trim((string)($item['prioridad_label'] ?? '')), 'UTF-8');

  if (in_array($key, ['very-high', 'very_high', 'critical', 'muy-alta'], true) || mb_strpos($label, 'muy alta') !== false) {
    return 0;
  }
  if (in_array($key, ['high', 'alta', 'alto'], true) || in_array($label, ['alta', 'alto'], true)) {
    return 1;
  }
  if (in_array($key, ['medium', 'media', 'medio'], true) || in_array($label, ['media', 'medio'], true)) {
    return 2;
  }
  if (in_array($key, ['low', 'baja'], true) || $label === 'baja') {
    return 3;
  }

  return 4;
};

$isDoneProject = static function (array $item): bool {
  $key = strtolower(trim((string)($item['status_key'] ?? '')));
  $label = mb_strtolower(trim((string)($item['status_label'] ?? '')), 'UTF-8');

  return $key === 'done' || $label === 'terminado';
};

$statusRank = static function (array $item): int {
  $key = strtolower(trim((string)($item['status_key'] ?? '')));
  $label = mb_strtolower(trim((string)($item['status_label'] ?? '')), 'UTF-8');

  if ($key === 'progress' || $label === 'proceso') {
    return 0;
  }
  if ($key === 'percent' || preg_match('/^\d+(\.\d+)?%$/', $label) === 1) {
    return 1;
  }
  if ($key === 'not-started' || $label === 'sin iniciar') {
    return 2;
  }
  if ($key === 'blank' || $label === 'pendiente') {
    return 3;
  }
  if ($key === 'waiting' || $label === 'en espera') {
    return 4;
  }

  return 5;
};

$sortProjectItems = static function (array $items) use ($priorityRank, $isDoneProject, $statusRank): array {
  usort($items, static function (array $a, array $b) use ($priorityRank, $isDoneProject, $statusRank): int {
    $doneCompare = (int)$isDoneProject($a) <=> (int)$isDoneProject($b);
    if ($doneCompare !== 0) {
      return $doneCompare;
    }

    $rankCompare = $priorityRank($a) <=> $priorityRank($b);
    if ($rankCompare !== 0) {
      return $rankCompare;
    }

    $statusCompare = $statusRank($a) <=> $statusRank($b);
    if ($statusCompare !== 0) {
      return $statusCompare;
    }

    return strnatcasecmp((string)($a['nombre'] ?? ''), (string)($b['nombre'] ?? ''));
  });

  return $items;
};

$attachAreaProjects = static function (array $areas) use ($sortProjectItems): array {
  foreach ($areas as &$area) {
    $items = [];
    foreach ((array)($area['secciones'] ?? []) as $section) {
      foreach ((array)($section['items'] ?? []) as $item) {
        $item['seccion'] = (string)($section['nombre'] ?? '');
        $items[] = $item;
      }
    }
    $area['items'] = $sortProjectItems($items);
  }
  unset($area);

  return $areas;
};

$ensureSchema = static function (PDO $pdo) use ($quoteIdentifier, $areasTable, $sectionsTable, $itemsTable): void {
  $areas = $quoteIdentifier($areasTable);
  $sections = $quoteIdentifier($sectionsTable);
  $items = $quoteIdentifier($itemsTable);

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS {$areas} (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      slug VARCHAR(80) NOT NULL UNIQUE,
      nombre VARCHAR(140) NOT NULL,
      accent VARCHAR(30) NOT NULL DEFAULT 'blue',
      orden INT NOT NULL DEFAULT 0,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS {$sections} (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      area_id INT UNSIGNED NOT NULL,
      slug VARCHAR(80) NOT NULL,
      nombre VARCHAR(140) NOT NULL,
      orden INT NOT NULL DEFAULT 0,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_area_slug (area_id, slug),
      CONSTRAINT fk_proyectos_secciones_area
        FOREIGN KEY (area_id) REFERENCES {$areas} (id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS {$items} (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      section_id INT UNSIGNED NOT NULL,
      nombre VARCHAR(255) NOT NULL,
      prioridad_key VARCHAR(30) NOT NULL DEFAULT '',
      prioridad_label VARCHAR(40) NOT NULL DEFAULT '',
      responsable VARCHAR(120) NOT NULL DEFAULT '',
      inicio VARCHAR(40) NOT NULL DEFAULT '',
      cierre VARCHAR(40) NOT NULL DEFAULT '',
      avance_planeado DECIMAL(5,2) NOT NULL DEFAULT 0,
      avance_real DECIMAL(5,2) NOT NULL DEFAULT 0,
      indice_diamante DECIMAL(5,2) NOT NULL DEFAULT 100,
      beneficio_principal TEXT NULL,
      status_key VARCHAR(40) NOT NULL DEFAULT 'blank',
      status_label VARCHAR(60) NOT NULL DEFAULT 'Pendiente',
      orden INT NOT NULL DEFAULT 0,
      activo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_section_order (section_id, orden),
      CONSTRAINT fk_proyectos_items_section
        FOREIGN KEY (section_id) REFERENCES {$sections} (id)
        ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");

  $existingColumns = [];
  foreach ($pdo->query("SHOW COLUMNS FROM {$items}") ?: [] as $column) {
    $existingColumns[(string)($column['Field'] ?? '')] = true;
  }

  $columnDefinitions = [
    'avance_planeado' => "DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER cierre",
    'avance_real' => "DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER avance_planeado",
    'indice_diamante' => "DECIMAL(5,2) NOT NULL DEFAULT 100 AFTER avance_real",
    'beneficio_principal' => "TEXT NULL AFTER indice_diamante",
  ];

  foreach ($columnDefinitions as $column => $definition) {
    if (!isset($existingColumns[$column])) {
      $pdo->exec("ALTER TABLE {$items} ADD COLUMN {$column} {$definition}");
    }
  }
};

$seedDatabase = static function (PDO $pdo) use ($seedAreas, $quoteIdentifier, $areasTable, $sectionsTable, $itemsTable): void {
  $areas = $quoteIdentifier($areasTable);
  $sections = $quoteIdentifier($sectionsTable);
  $items = $quoteIdentifier($itemsTable);

  $count = (int)$pdo->query("SELECT COUNT(*) FROM {$areas}")->fetchColumn();
  if ($count > 0) {
    return;
  }

  $pdo->beginTransaction();
  try {
    $areaStmt = $pdo->prepare("INSERT INTO {$areas} (slug, nombre, accent, orden) VALUES (:slug, :nombre, :accent, :orden)");
    $sectionStmt = $pdo->prepare("INSERT INTO {$sections} (area_id, slug, nombre, orden) VALUES (:area_id, :slug, :nombre, :orden)");
    $itemStmt = $pdo->prepare("
      INSERT INTO {$items}
        (section_id, nombre, prioridad_key, prioridad_label, responsable, inicio, cierre, avance_planeado, avance_real, indice_diamante, beneficio_principal, status_key, status_label, orden)
      VALUES
        (:section_id, :nombre, :prioridad_key, :prioridad_label, :responsable, :inicio, :cierre, :avance_planeado, :avance_real, :indice_diamante, :beneficio_principal, :status_key, :status_label, :orden)
    ");

    foreach ($seedAreas as $areaIndex => $area) {
      $areaStmt->execute([
        'slug' => (string)$area['slug'],
        'nombre' => (string)$area['nombre'],
        'accent' => (string)($area['accent'] ?? 'blue'),
        'orden' => $areaIndex + 1,
      ]);
      $areaId = (int)$pdo->lastInsertId();

      foreach ((array)($area['secciones'] ?? []) as $sectionIndex => $section) {
        $sectionStmt->execute([
          'area_id' => $areaId,
          'slug' => (string)$section['slug'],
          'nombre' => (string)$section['nombre'],
          'orden' => $sectionIndex + 1,
        ]);
        $sectionId = (int)$pdo->lastInsertId();

        foreach ((array)($section['items'] ?? []) as $itemIndex => $item) {
          $statusKey = (string)($item['status_key'] ?? 'waiting');
          $itemStmt->execute([
            'section_id' => $sectionId,
            'nombre' => (string)($item['nombre'] ?? ''),
            'prioridad_key' => (string)($item['prioridad_key'] ?? ''),
            'prioridad_label' => (string)($item['prioridad_label'] ?? ''),
            'responsable' => (string)($item['responsable'] ?? ''),
            'inicio' => (string)($item['inicio'] ?? ''),
            'cierre' => (string)($item['cierre'] ?? ''),
            'avance_planeado' => is_numeric($item['avance_planeado'] ?? null) ? (float)$item['avance_planeado'] : 0,
            'avance_real' => is_numeric($item['avance_real'] ?? null) ? (float)$item['avance_real'] : 0,
            'indice_diamante' => is_numeric($item['indice_diamante'] ?? null) ? (float)$item['indice_diamante'] : 100,
            'beneficio_principal' => (string)($item['beneficio_principal'] ?? ''),
            'status_key' => $statusKey,
            'status_label' => (string)($item['status_label'] ?? ($statusKey === 'waiting' ? 'En espera' : 'Pendiente')),
            'orden' => $itemIndex + 1,
          ]);
        }
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
};

$syncSeedStructure = static function (PDO $pdo) use ($seedAreas, $quoteIdentifier, $areasTable, $sectionsTable): void {
  $areas = $quoteIdentifier($areasTable);
  $sections = $quoteIdentifier($sectionsTable);

  $areaSelect = $pdo->prepare("SELECT id FROM {$areas} WHERE slug = :slug LIMIT 1");
  $areaInsert = $pdo->prepare("INSERT INTO {$areas} (slug, nombre, accent, orden) VALUES (:slug, :nombre, :accent, :orden)");
  $areaUpdate = $pdo->prepare("UPDATE {$areas} SET nombre = :nombre, accent = :accent, orden = :orden, activo = 1 WHERE id = :id");
  $sectionSelect = $pdo->prepare("SELECT id FROM {$sections} WHERE area_id = :area_id AND slug = :slug LIMIT 1");
  $sectionInsert = $pdo->prepare("INSERT INTO {$sections} (area_id, slug, nombre, orden) VALUES (:area_id, :slug, :nombre, :orden)");

  foreach ($seedAreas as $areaIndex => $area) {
    $slug = (string)($area['slug'] ?? '');
    if ($slug === '') {
      continue;
    }

    $areaSelect->execute(['slug' => $slug]);
    $areaId = (int)$areaSelect->fetchColumn();

    if ($areaId <= 0) {
      $areaInsert->execute([
        'slug' => $slug,
        'nombre' => (string)($area['nombre'] ?? $slug),
        'accent' => (string)($area['accent'] ?? 'blue'),
        'orden' => $areaIndex + 1,
      ]);
      $areaId = (int)$pdo->lastInsertId();
    } else {
      $areaUpdate->execute([
        'id' => $areaId,
        'nombre' => (string)($area['nombre'] ?? $slug),
        'accent' => (string)($area['accent'] ?? 'blue'),
        'orden' => $areaIndex + 1,
      ]);
    }

    foreach ((array)($area['secciones'] ?? []) as $sectionIndex => $section) {
      $sectionSlug = (string)($section['slug'] ?? '');
      if ($sectionSlug === '') {
        continue;
      }

      $sectionSelect->execute([
        'area_id' => $areaId,
        'slug' => $sectionSlug,
      ]);

      if ((int)$sectionSelect->fetchColumn() > 0) {
        continue;
      }

      $sectionInsert->execute([
        'area_id' => $areaId,
        'slug' => $sectionSlug,
        'nombre' => (string)($section['nombre'] ?? $sectionSlug),
        'orden' => $sectionIndex + 1,
      ]);
    }
  }
};

$normalizeProjectSections = static function (PDO $pdo) use ($quoteIdentifier, $areasTable, $sectionsTable, $itemsTable): void {
  $areas = $quoteIdentifier($areasTable);
  $sections = $quoteIdentifier($sectionsTable);
  $items = $quoteIdentifier($itemsTable);

  $areaRows = $pdo->query("SELECT id FROM {$areas} WHERE activo = 1 ORDER BY orden, id")->fetchAll() ?: [];
  $sectionSelect = $pdo->prepare("SELECT id FROM {$sections} WHERE area_id = :area_id AND slug = :slug LIMIT 1");
  $sectionInsert = $pdo->prepare("INSERT INTO {$sections} (area_id, slug, nombre, orden) VALUES (:area_id, :slug, :nombre, :orden)");
  $sectionActivate = $pdo->prepare("UPDATE {$sections} SET nombre = :nombre, orden = :orden, activo = 1 WHERE id = :id");
  $sectionRowsStmt = $pdo->prepare("SELECT id FROM {$sections} WHERE area_id = :area_id");
  $deactivateStmt = $pdo->prepare("UPDATE {$sections} SET activo = 0 WHERE area_id = :area_id AND id NOT IN (:active_id, :done_id)");

  foreach ($areaRows as $area) {
    $areaId = (int)$area['id'];
    $targetIds = [];

    foreach ([
      'proyectos' => ['nombre' => 'Proyectos', 'orden' => 1],
      'terminados' => ['nombre' => 'Terminados', 'orden' => 2],
    ] as $slug => $sectionConfig) {
      $sectionSelect->execute([
        'area_id' => $areaId,
        'slug' => $slug,
      ]);
      $sectionId = (int)$sectionSelect->fetchColumn();

      if ($sectionId <= 0) {
        $sectionInsert->execute([
          'area_id' => $areaId,
          'slug' => $slug,
          'nombre' => $sectionConfig['nombre'],
          'orden' => $sectionConfig['orden'],
        ]);
        $sectionId = (int)$pdo->lastInsertId();
      } else {
        $sectionActivate->execute([
          'id' => $sectionId,
          'nombre' => $sectionConfig['nombre'],
          'orden' => $sectionConfig['orden'],
        ]);
      }

      $targetIds[$slug] = $sectionId;
    }

    $sectionRowsStmt->execute(['area_id' => $areaId]);
    $sectionIds = array_map('intval', array_column($sectionRowsStmt->fetchAll() ?: [], 'id'));
    if ($sectionIds === []) {
      continue;
    }

    $placeholders = implode(',', array_fill(0, count($sectionIds), '?'));
    $doneSql = "
      UPDATE {$items}
      SET section_id = ?
      WHERE section_id IN ({$placeholders})
        AND activo = 1
        AND (LOWER(TRIM(status_key)) = 'done' OR LOWER(TRIM(status_label)) = 'terminado')
    ";
    $activeSql = "
      UPDATE {$items}
      SET section_id = ?
      WHERE section_id IN ({$placeholders})
        AND activo = 1
        AND NOT (LOWER(TRIM(status_key)) = 'done' OR LOWER(TRIM(status_label)) = 'terminado')
    ";

    $pdo->prepare($doneSql)->execute(array_merge([$targetIds['terminados']], $sectionIds));
    $pdo->prepare($activeSql)->execute(array_merge([$targetIds['proyectos']], $sectionIds));

    $deactivateStmt->execute([
      'area_id' => $areaId,
      'active_id' => $targetIds['proyectos'],
      'done_id' => $targetIds['terminados'],
    ]);
  }
};

$fetchFromDatabase = static function (PDO $pdo) use ($quoteIdentifier, $areasTable, $sectionsTable, $itemsTable, $normalizeItem, $sortProjectItems, $attachAreaProjects): array {
  $areas = $quoteIdentifier($areasTable);
  $sections = $quoteIdentifier($sectionsTable);
  $items = $quoteIdentifier($itemsTable);

  $areaRows = $pdo->query("SELECT * FROM {$areas} WHERE activo = 1 ORDER BY orden, id")->fetchAll() ?: [];
  $sectionRows = $pdo->query("SELECT * FROM {$sections} WHERE activo = 1 ORDER BY orden, id")->fetchAll() ?: [];
  $itemRows = $pdo->query("SELECT * FROM {$items} WHERE activo = 1 ORDER BY orden, id")->fetchAll() ?: [];

  $sectionsByArea = [];
  foreach ($sectionRows as $section) {
    $section['items'] = [];
    $sectionsByArea[(int)$section['area_id']][] = $section;
  }

  $itemsBySection = [];
  foreach ($itemRows as $item) {
    $itemsBySection[(int)$item['section_id']][] = $normalizeItem([
      'id' => $item['id'] ?? null,
      'nombre' => $item['nombre'] ?? '',
      'prioridad_key' => $item['prioridad_key'] ?? '',
      'prioridad_label' => $item['prioridad_label'] ?? '',
      'responsable' => $item['responsable'] ?? '',
      'inicio' => $item['inicio'] ?? '',
      'cierre' => $item['cierre'] ?? '',
      'avance_planeado' => $item['avance_planeado'] ?? 0,
      'avance_real' => $item['avance_real'] ?? 0,
      'indice_diamante' => $item['indice_diamante'] ?? 100,
      'beneficio_principal' => $item['beneficio_principal'] ?? '',
      'status_key' => $item['status_key'] ?? 'blank',
      'status_label' => $item['status_label'] ?? 'Pendiente',
      'orden' => $item['orden'] ?? 0,
    ]);
  }

  $areasOut = [];
  $totalProjects = 0;
  foreach ($areaRows as $area) {
    $areaSections = [];
    foreach ((array)($sectionsByArea[(int)$area['id']] ?? []) as $section) {
      $sectionItems = $sortProjectItems((array)($itemsBySection[(int)$section['id']] ?? []));
      $totalProjects += count($sectionItems);
      $areaSections[] = [
        'id' => (int)$section['id'],
        'slug' => (string)$section['slug'],
        'nombre' => (string)$section['nombre'],
        'orden' => (int)$section['orden'],
        'items' => $sectionItems,
      ];
    }

    $areasOut[] = [
      'id' => (int)$area['id'],
      'slug' => (string)$area['slug'],
      'nombre' => (string)$area['nombre'],
      'accent' => (string)$area['accent'],
      'orden' => (int)$area['orden'],
      'secciones' => $areaSections,
    ];
  }

  return [$attachAreaProjects($areasOut), $totalProjects];
};

$warnings = [];
$source = 'seed';
$pdo = null;

try {
  $pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'hoshin')] ?? []));
  $ensureSchema($pdo);
  $seedDatabase($pdo);
  $syncSeedStructure($pdo);
  $normalizeProjectSections($pdo);
  [$areas, $totalProjects] = $fetchFromDatabase($pdo);
  $source = 'database';
} catch (Throwable $e) {
  [$areas, $totalProjects] = $buildFromSeed();
  $areas = $attachAreaProjects($areas);
  $warnings[] = 'No se pudo conectar a la base de proyectos; se muestra el respaldo local.';
}

$sectionNames = [];
foreach ($areas as $area) {
  foreach ((array)($area['secciones'] ?? []) as $section) {
    $sectionNames[(string)$section['slug']] = (string)$section['nombre'];
  }
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Tablero Directivo de Proyectos'),
  'areas' => $areas,
  'meta' => [
    'source' => $source,
    'areaCount' => count($areas),
    'sectionCount' => count($sectionNames),
    'projectCount' => $totalProjects,
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? 300000),
    'warnings' => $warnings,
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/seed.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
