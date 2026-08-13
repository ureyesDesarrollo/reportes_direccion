<?php

declare(strict_types=1);

return [
  'titulo' => 'Estado de Equipos',
  'subtitulo' => 'Disponibilidad general para mantenimiento',
  'equipos' => [
    'lavadores' => ['label' => 'Lavadores', 'icon' => 'fa-soap'],
    'bombas_cuero' => ['label' => 'Bombas de cuero', 'icon' => 'fa-water'],
    'grua' => ['label' => 'Grúa', 'icon' => 'fa-truck-ramp-box'],
    'molinos' => ['label' => 'Molinos', 'icon' => 'fa-gears'],
    'preparadores' => ['label' => 'Preparadores', 'icon' => 'fa-blender'],
    'receptores' => ['label' => 'Receptores', 'icon' => 'fa-box-open'],
    'cocedores' => ['label' => 'Cocedores', 'icon' => 'fa-temperature-high'],
    'concentradores' => ['label' => 'Concentradores', 'icon' => 'fa-industry'],
    'membranas' => ['label' => 'Membranas', 'icon' => 'fa-filter'],
    'clarificadores' => ['label' => 'Clarificadores', 'icon' => 'fa-droplet'],
    'intercambio_ionico' => ['label' => 'Intercambio iónico', 'icon' => 'fa-arrows-rotate'],
    'votators' => ['label' => 'Votators', 'icon' => 'fa-fan'],
    'secadores' => ['label' => 'Secadores', 'icon' => 'fa-wind'],
    'filtros_prensa' => ['label' => 'Filtros prensa', 'icon' => 'fa-compress'],
  ],
  'equipos_duales_pct' => null,
  'equipos_duales_database' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'sistema_calderas',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
    'timeout' => 3,
    'tabla' => 'equipos_duales',
    'tabla_historial' => 'historial_duales',
  ],
  'costo_mantenimiento' => null,
  'actualizado' => 'Sin captura',
];
