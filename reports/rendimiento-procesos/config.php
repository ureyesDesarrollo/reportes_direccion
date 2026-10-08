<?php

$catalog = require __DIR__ . '/../../config/parameter_catalog.php';

return [
  'titulo' => 'Rendimiento por Proceso',
  'database_key' => 'prod',
  'timezone' => 'Etc/GMT+6',
  'timezone_label' => 'UTC-6',
  'hora_corte' => '07:00:00',
  'intervalo_actualizacion_ms' => 900000,
  'semaforo_costo_kg' => [
    'verde_menor_que' => 43.0,
    'amarillo_desde' => 43.0,
    'amarillo_hasta' => 50.0,
    'rojo_mayor_que' => 50.0,
    'leyenda' => 'Verde < $43 · Amarillo $43–$50 · Rojo > $50',
  ],
  'semaforo_rendimiento' => (array)($catalog['produccion']['rendimiento_global'] ?? []),
  'parametros_lab' => (array)($catalog['materia_prima']['inventario'] ?? []),
  'parametros_proceso' => (array)($catalog['materia_prima']['rendimiento_procesos'] ?? []),
];
