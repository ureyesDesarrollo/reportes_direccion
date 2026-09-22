<?php

return [
  'titulo' => 'Empaques en General / Producción',
  'fecha_desde' => '2025-01-01',
  'campo_fecha_movs' => 'F_MOV',
  'productos' => [],
  'tolerancia_pct' => 10,
  'cve_mov' => null,  // Los empaques no tienen CVE_MOV específico
  'usar_todos_los_productos' => true,
  'anio_pivot' => (int)date('Y'),
  'lugar' => 'EMPAQUES',
  'movimientos_api' => [
    'url' => 'http://192.168.1.104:5000/api/movimientos-salida',
    'query' => ['lugar' => 'EMPAQUES'],
    'lugar' => 'EMPAQUES',
    'cve_mov' => ['17'],
    'cache_ttl' => 3600,
    'cache_version' => 1,
  ],
  'productos_a_ignorar' => [
    'DIES01',
    'SACKRAF',
    'ETIQUETA10',
    'SUPERSACO12',
    'PISTOLA05',
    'TARIMA6',
    'CAJA4',
    'SUPERSACO13',
    'EMPAQUE15'
  ],
];
