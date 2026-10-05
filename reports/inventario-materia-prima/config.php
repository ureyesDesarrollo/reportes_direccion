<?php

declare(strict_types=1);

$catalog = require __DIR__ . '/../../config/parameter_catalog.php';

return [
  'titulo' => 'Inventario de Materia Prima',
  'subtitulo' => 'Resultados de entrada y criterios de aceptación',
  'database_key' => 'prod',
  'timezone' => 'Etc/GMT+6',
  'intervalo_actualizacion_ms' => 120000,
  'parametros' => (array)($catalog['materia_prima']['inventario'] ?? []),
  'materiales_cuero_entero_pedacera' => [2, 5, 6, 7, 8, 9, 12, 14],
  'materiales_compra_cuero_americano' => [2, 5, 6, 7],
  'materiales_cuero_entero' => [5, 7, 9, 12],
  'materiales_cuero_entero_cp' => [5, 7],
  'materiales_cuero_entero_depilado' => [9, 12],
];
