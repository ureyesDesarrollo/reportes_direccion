<?php

declare(strict_types=1);

$productionConfig = require __DIR__ . '/../avance-produccion-hora/config.php';

return [
  'titulo' => 'Calidad Monitoreo',
  // UTC-6 fijo para conservar el corte operativo correcto también en PHP 7.
  'timezone' => 'Etc/GMT+6',
  'hora_corte' => 7,
  'intervalo_actualizacion_ms' => 120000,
  'database' => (array)($productionConfig['database'] ?? []),
];
