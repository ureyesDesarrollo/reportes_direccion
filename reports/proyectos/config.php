<?php

declare(strict_types=1);

return [
  'titulo' => 'Tablero Directivo de Proyectos',
  'database_key' => 'hoshin',
  'milestones_database_key' => 'hoshin_kanri',
  'empresa_id' => 1,
  'milestone_detail_path' => '../proyectos-milestones/detail.php',
  'intervalo_actualizacion_ms' => 300000,
  'tablas' => [
    'areas' => 'proyectos_areas',
    'secciones' => 'proyectos_secciones',
    'items' => 'proyectos_items',
  ],
];
