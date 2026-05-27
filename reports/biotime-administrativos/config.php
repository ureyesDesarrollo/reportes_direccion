<?php

return [
  'titulo' => 'BioTime / Administrativos',
  'base_url' => 'http://192.168.1.107:8080/',
  'username' => 'AnalistaRH',
  'password' => '$analista25',
  'timezone' => 'America/Mazatlan',

  // Horario default cuando no hay regla específica por empleado/rol/turno/departamento.
  'hora_entrada' => '08:00:00',
  'hora_salida' => '17:30:00',
  'tolerancia_retardo_min' => 10,
  'minutos_comida' => 60,
  'calcular_horas_extra' => true,

  'horarios' => [
    'empleados' => [
      '262' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '2996' => [
        'entrada' => '08:30:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3691' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3507' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3400' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3122' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3359' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '99973' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          2 => ['salida' => '15:30:00'],
          4 => ['entrada' => '12:00:00'],
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '9999' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          2 => ['salida' => '15:30:00'],
          4 => ['entrada' => '12:00:00'],
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '1212' => [
        'entrada' => '08:30:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
      '3379' => [
        'entrada' => '08:00:00',
        'salida' => '17:40:00',
        'comida' => 60,
        'tolerancia' => 10,
        'por_dia' => [
          5 => ['salida' => '16:00:00'],
        ],
      ],
    ],
    'roles' => [
      // 'Analista RH' => ['entrada' => '08:00:00', 'salida' => '17:30:00', 'comida' => 60, 'tolerancia' => 10],
    ],
    'turnos' => [
      // 'Matutino' => ['entrada' => '08:00:00', 'salida' => '17:30:00', 'comida' => 60, 'tolerancia' => 10],
    ],
    'departamentos' => [
      // 'RECURSOS HUMANOS' => ['entrada' => '08:00:00', 'salida' => '17:30:00', 'comida' => 60, 'tolerancia' => 10],
    ],
  ],

  'intervalo_actualizacion_ms' => 1800000,
  'filas_por_pagina' => 15,

  // Si se deja vacio, muestra todos los departamentos disponibles.
  // Agrega aqui los nombres exactos de los departamentos administrativos si quieres restringirlo.
  'departamentos_administrativos' => [],

  'empleados_permitidos' => [
    '262',
    '2996',
    '3691',
    '3507',
    '3400',
    '3122',
    '3359',
    '99973',
    '9999',
    '1212',
    '3379',
  ],

  // Fechas YYYY-MM-DD que no deben penalizar retardo o salida antes.
  'dias_festivos' => [
    '2026-01-01',
    '2026-02-02',
    '2026-03-16',
    '2026-05-01',
    '2026-09-16',
    '2026-11-16',
    '2026-12-25',
  ],
];
