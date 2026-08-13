<?php

declare(strict_types=1);

$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));

return [
  'titulo' => 'Reporte General de RH',
  'subtitulo' => 'Resumen de personal e indicadores de Recursos Humanos',
  'periodo' => 'Periodo actual',
  'actualizado' => $now->format('d/m/Y H:i'),
  'personal' => [
    'total' => ['label' => 'Personal total', 'value' => null, 'icon' => 'fa-users'],
    'administrativo' => [
      'label' => 'Personal administrativo',
      'value' => null,
      'hombres' => null,
      'mujeres' => null,
      'icon' => 'fa-user-tie',
    ],
    'operativo_directo' => [
      'label' => 'Operativo directo',
      'value' => null,
      'hombres' => null,
      'mujeres' => null,
      'icon' => 'fa-helmet-safety',
    ],
    'operativo_indirecto' => [
      'label' => 'Operativo indirecto',
      'value' => null,
      'hombres' => null,
      'mujeres' => null,
      'icon' => 'fa-gears',
    ],
  ],
  'genero_total' => [
    'hombres' => null,
    'mujeres' => null,
  ],
  'vacantes' => [
    'total' => null,
    'criticas' => null,
  ],
  'indicadores' => [
    'tiempo_extra_horas' => null,
    'tiempo_extra_costo' => null,
    'ausentismo_faltas' => null,
    'ausentismo' => null,
    'rotacion' => null,
  ],
  'lista_negra' => [],
];
