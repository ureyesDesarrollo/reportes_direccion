<?php

return [
  'titulo'                    => 'Refacciones Críticas',
  'fecha_desde'               => '2024-01-01',
  'campo_fecha_movs'          => 'F_MOV',
  'productos'                 => [],
  'tolerancia_pct'            => 10,
  'cve_mov'                   => 17,
  'cve_mov_compra'            => 1,
  'usar_todos_los_productos'  => true,
  'anio_pivot'                => (int)date('Y'),
  'lugar'                     => 'CRITICOS',
  'movimientos_api'           => [
    'url' => 'http://192.168.1.104:5000/api/movimientos-salida',
    'query' => ['lugar' => 'CRITICOS'],
    'lugar' => 'CRITICOS',
    'cve_mov' => ['17'],
    'cache_ttl' => 3600,
    'cache_version' => 1,
  ],
  'productos_a_ignorar'       => [],
];
