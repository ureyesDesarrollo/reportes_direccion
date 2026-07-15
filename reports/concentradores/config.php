<?php

declare(strict_types=1);

$avevaConfig = require __DIR__ . '/../secadores-temperatura/config.php';

return [
  'titulo' => 'Concentradores',
  'timezone' => 'America/Mexico_City',
  'intervalo_actualizacion_ms' => 60000,

  'sqlserver' => (array)($avevaConfig['sqlserver'] ?? []),
  'tabla_aveva' => (string)($avevaConfig['tabla'] ?? 'TREND001'),
  'campo_fecha_aveva' => (string)($avevaConfig['campo_fecha'] ?? 'Time_Stamp'),

  'mysql_105' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'progel_procesos',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
    'timeout' => 3,
  ],
  'tabla_datos' => 'datos_concentradores',
  'columna_tipo' => 'tipo',
  'columnas_orden' => ['id_datos_hora', 'fecha_hora', 'fecha', 'created_at', 'updated_at', 'id'],

  'concentradores' => [
    'concentrador_1' => [
      'nombre' => 'Concentrador 1',
      'tipo' => 'Conc1',
      'flujo_field' => 'FLUJO_CONCENTRADOR_1',
    ],
    'concentrador_2' => [
      'nombre' => 'Concentrador 2',
      'tipo' => 'Conc2',
      'flujo_field' => 'FLUJO_CONCENTRADOR_2',
    ],
    'concentrador_3' => [
      'nombre' => 'Concentrador 3',
      'tipo' => 'Conc3',
      'flujo_field' => 'FLUJO_CONCENTRADOR_3',
    ],
    'concentrador_4' => [
      'nombre' => 'Concentrador 4',
      'tipo' => 'Conc4',
      'flujo_field' => 'FLUJO_CONCENTRADOR_4',
    ],
    'invertido' => [
      'nombre' => 'Invertido',
      'tipo' => 'Invertido',
      'flujo_field' => 'FLUJO_CONCENTRADOR_5',
    ],
  ],

  'metricas' => [
    'flujo' => [
      'label' => 'Flujo',
      'unit' => '',
      'source' => 'sqlserver',
      'decimals' => 2,
    ],
    'temperatura' => [
      'label' => 'Temperatura',
      'unit' => 'C',
      'source' => 'mysql_105',
      'decimals' => 2,
      'columns' => ['temperatura'],
    ],
    'vacio' => [
      'label' => 'Vacio',
      'unit' => '',
      'source' => 'mysql_105',
      'decimals' => 2,
      'columns' => ['vacio'],
    ],
    'solidos_entrada' => [
      'label' => 'Solidos Entrada',
      'unit' => '%',
      'source' => 'mysql_105',
      'decimals' => 2,
      'columns' => ['sol_ent'],
    ],
    'solidos_salida' => [
      'label' => 'Solidos Salida',
      'unit' => '%',
      'source' => 'mysql_105',
      'decimals' => 2,
      'columns' => ['sol_sal'],
    ],
  ],

  // Agrega rangos por metrica cuando esten definidos.
  // Ejemplo:
  // 'semaforos' => [
  //   'temperatura' => ['modo' => 'rango', 'verde_min' => 70, 'verde_max' => 75, 'amarillo_min' => 68, 'amarillo_max' => 77],
  // ],
  'semaforos' => [
    'solidos_entrada' => [
      'modo' => 'rango',
      'verde_min' => 38.000001,
      'amarillo_min' => 36.1,
      'amarillo_max' => 38,
    ],
    'solidos_salida' => [
      'modo' => 'rango',
      'verde_min' => 38.000001,
      'amarillo_min' => 36.1,
      'amarillo_max' => 38,
    ],
  ],
];
