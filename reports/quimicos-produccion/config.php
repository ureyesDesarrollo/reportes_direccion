<?php

return [
  'titulo' => 'Químicos en General / Producción',
  'fecha_desde' => '2025-01-01',
  'campo_fecha_movs' => 'F_MOV',
  'productos' => [],
  'tolerancia_pct' => 10,
  'cve_mov_consumo' => ['17'],
  'cve_mov_ajuste' => ['15'],
  'conversiones_unidad_producto' => [
    'TOROHY1' => [
      'SACO' => 25,
      'SACOS' => 25,
    ],
    'SAL02' => [
      'BULTO' => 20,
      'BULTOS' => 20,
    ],
    'PASTIL1' => [
      'CUBET' => 4,
      'CUBETA' => 4,
      'CUBETAS' => 4,
    ],
  ],
  'usar_todos_los_productos' => true,
  'anio_pivot' => (int)date('Y'),
  'grupo_estructura' => [
    'enzimas_preparacion' => [
      'titulo' => 'ENZIMA Preparación / Producción',
      'productos' => ['DETERZYME1', 'COROLASE'],
    ],
    'enzimas_pelambre' => [
      'titulo' => 'ENZIMA Pelambre / Producción',
      'productos' => ['BUZ78', 'BUZ77'],
    ],
  ],
];
