<?php

return [
  'titulo' => 'Proyectos',
  'database_key' => 'hoshin',
  'empresa_id' => 1,
  'cards_por_pagina' => 12,
  'intervalo_actualizacion' => 300000,
  'dias_alerta_vencimiento' => 7,
  'milestone_estatus' => [
    1 => ['label' => 'Activo', 'color' => '#2563eb'],
    2 => ['label' => 'Cerrado', 'color' => '#10b981'],
    3 => ['label' => 'Inactivo', 'color' => '#94a3b8'],
    4 => ['label' => 'Pausado', 'color' => '#f59e0b'],
    5 => ['label' => 'Descontinuado', 'color' => '#64748b'],
  ],
];
