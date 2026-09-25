<?php

declare(strict_types=1);

$monitoringConfig = require __DIR__ . '/../produccion-monitoreo/config.php';
$secadoresConfig = require __DIR__ . '/../secadores/config.php';
$secadoresConfig = array_replace_recursive($secadoresConfig, [
  'votators_por_tunel' => (array)($monitoringConfig['secadores']['votator_campos_overlay'] ?? []),
]);

return [
  'titulo' => 'Votators',
  'subtitulo' => 'Monitoreo en tiempo real',
  'intervalo_actualizacion_ms' => 1000,
  'votators' => ['votator_1', 'votator_2', 'votator_3', 'votator_4'],
  'campos_principales' => ['flujo', 'presion_cuajado', 'temperatura_nariz', 'solidos'],
  'campos_secundarios' => ['amperaje_bomba', 'amperaje_reductor', 'corriente_votator', 'tiempo_fuera'],
  'temperatura_alimentacion_votators_3_4' => [
    'conexion' => (array)($secadoresConfig['sqlserver'] ?? []),
    'tabla' => (string)($secadoresConfig['tabla'] ?? 'TREND001'),
    'campo_fecha' => (string)($secadoresConfig['campo_fecha'] ?? 'Time_Stamp'),
    'sensor' => 'TEMPERATURA_ENTRADA_TANQUE_ALIMENTACION_VOTATORS_3_Y_4',
    'unidad' => '°C',
    'semaforo' => [
      'modo' => 'bandas',
      'bandas' => [
        ['max' => 42.999999, 'estado' => 'rojo', 'leyenda' => '<43'],
        ['min' => 43, 'max' => 48, 'estado' => 'verde', 'leyenda' => '43–48'],
        ['min' => 48.000001, 'max' => 50, 'estado' => 'amarillo', 'leyenda' => '48.1–50'],
        ['min' => 50.000001, 'estado' => 'rojo', 'leyenda' => '≥50.1'],
      ],
    ],
  ],
  'secadores_config' => $secadoresConfig,
];
