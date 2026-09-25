<?php

declare(strict_types=1);

$secadoresConfig = require __DIR__ . '/../secadores/config.php';
$parameterCatalog = require __DIR__ . '/../../config/parameter_catalog.php';

return [
  'titulo' => 'Humedad Churro',
  'subtitulo' => 'Monitoreo de humedad de churro por secador',
  'timezone' => 'Etc/GMT+6',
  'intervalo_actualizacion_ms' => 120000,
  'secadores' => [1, 2, 3, 4],
  'conexion' => (array)($secadoresConfig['mysql_verificacion_secado'] ?? []),
  'tabla' => 'verificacion_secado',
  'campo_valor' => 'hum_ultima',
  'campo_fuera_operacion' => 'estado_fo',
  'semaforo' => ['modo' => 'minimo', 'verde_min' => 12, 'amarillo_min' => 10],
];
