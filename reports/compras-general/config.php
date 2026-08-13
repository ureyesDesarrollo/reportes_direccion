<?php

declare(strict_types=1);

return [
  'titulo' => 'Compras General',
  'intervalo_actualizacion_ms' => 300000,
  'fuentes' => [
    'quimicos_costo' => [
      'titulo' => 'Químicos · Costo',
      'directorio' => 'quimicos-produccion',
      'url' => '../quimicos-produccion/',
      'color' => '#0f766e',
      'icono' => 'fa-file-invoice-dollar',
      'tipo' => 'quimicos',
      'modo_fijo' => 'costo_produccion',
    ],
    'quimicos_consumo' => [
      'titulo' => 'Químicos · Consumo',
      'directorio' => 'quimicos-produccion',
      'url' => '../quimicos-produccion/',
      'color' => '#0d9488',
      'icono' => 'fa-flask',
      'tipo' => 'quimicos',
      'modo_fijo' => 'consumo',
    ],
    'empaques' => [
      'titulo' => 'Empaques',
      'directorio' => 'empaques-produccion',
      'url' => '../empaques-produccion/',
      'color' => '#2563eb',
      'icono' => 'fa-box',
      'tipo' => 'empaques',
    ],
    'refacciones_criticas' => [
      'titulo' => 'Refacciones críticas',
      'directorio' => 'refacciones-criticas',
      'url' => '../refacciones-criticas/',
      'color' => '#dc2626',
      'icono' => 'fa-wrench',
      'tipo' => 'refacciones',
    ],
  ],
];
