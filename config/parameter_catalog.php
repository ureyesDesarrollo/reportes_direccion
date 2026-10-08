<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catálogo maestro de parámetros operativos
|--------------------------------------------------------------------------
|
| Este archivo es la fuente única para objetivos y reglas compartidas.
| Los reportes conservan localmente conexiones, tablas, campos y etiquetas.
|
*/

$range = static fn(float $greenMin, float $greenMax, float $yellowMin, float $yellowMax): array => [
  'modo' => 'rango',
  'verde_min' => $greenMin,
  'verde_max' => $greenMax,
  'amarillo_min' => $yellowMin,
  'amarillo_max' => $yellowMax,
];

$maximum = static fn(float $greenMax, float $yellowMax): array => [
  'modo' => 'maximo',
  'verde_max' => $greenMax,
  'amarillo_max' => $yellowMax,
];

$enzymeBelow80 = [
  'modo' => 'bandas',
  'leyenda' => '<80 / 80–84 / >84',
  'bandas' => [
    ['max' => 79.999999, 'estado' => 'verde', 'leyenda' => '<80'],
    ['min' => 80, 'max' => 84, 'estado' => 'amarillo', 'leyenda' => '80–84'],
    ['min' => 84.000001, 'estado' => 'rojo', 'leyenda' => '>84'],
  ],
];

$enzymeBelow90 = [
  'modo' => 'bandas',
  'leyenda' => '<90 / 90–94 / >94',
  'bandas' => [
    ['max' => 89.999999, 'estado' => 'verde', 'leyenda' => '<90'],
    ['min' => 90, 'max' => 94, 'estado' => 'amarillo', 'leyenda' => '90–94'],
    ['min' => 94.000001, 'estado' => 'rojo', 'leyenda' => '>94'],
  ],
];

$rendimientoPtAbove19 = [
  'modo' => 'bandas',
  'leyenda' => '>19 / 18–19 / <18',
  'bandas' => [
    ['min' => 19.000001, 'estado' => 'verde', 'leyenda' => '>19'],
    ['min' => 18, 'max' => 19, 'estado' => 'amarillo', 'leyenda' => '18–19'],
    ['max' => 17.999999, 'estado' => 'rojo', 'leyenda' => '<18'],
  ],
];

return [
  'produccion' => [
    'objetivo_diario_toneladas' => 24.0,
    'produccion_amarillo_min_diario' => 21.0,
    'produccion_verde_min_diario' => 24.0,
    'objetivo_diario_tarimas' => 24.0,
    'tarimas_amarillo_min_diario' => 21.0,
    'objetivo_turno_tarimas' => 12.0,
    'tarimas_amarillo_min_turno' => 11.0,
    'rendimiento_global' => [
      'modo' => 'bandas',
      'leyenda' => 'Verde >17 · Amarillo 16–17 · Rojo <16',
      'bandas' => [
        ['max' => 15.999999, 'estado' => 'rojo', 'leyenda' => '<16'],
        ['min' => 16, 'max' => 17, 'estado' => 'amarillo', 'leyenda' => '16–17'],
        ['min' => 17.000001, 'estado' => 'verde', 'leyenda' => '>17'],
      ],
    ],
    'kg_hora' => [
      'modo' => 'bandas',
      'bandas' => [
        ['max' => 874.999999, 'estado' => 'rojo', 'leyenda' => '< 875'],
        ['min' => 875, 'max' => 999.999999, 'estado' => 'amarillo', 'leyenda' => '875–999'],
        ['min' => 1000, 'estado' => 'verde', 'leyenda' => '≥ 1,000'],
      ],
    ],
    'flujos_s1_s2' => [
      'modo' => 'bandas',
      'bandas' => [
        ['max' => 18.99, 'estado' => 'rojo', 'leyenda' => '≤ 18.99'],
        ['min' => 19.00, 'max' => 21.99, 'estado' => 'amarillo', 'leyenda' => '19.00–21.99'],
        ['min' => 22.00, 'max' => 24.00, 'estado' => 'verde', 'leyenda' => '22.00–24.00'],
        ['min' => 24.01, 'max' => 25.98, 'estado' => 'amarillo', 'leyenda' => '24.01–25.98'],
        ['min' => 25.99, 'estado' => 'rojo', 'leyenda' => '≥ 25.99'],
      ],
    ],
    'flujos_s3_s4' => [
      'modo' => 'bandas',
      'bandas' => [
        ['max' => 3.99, 'estado' => 'rojo', 'leyenda' => '≤ 3.99'],
        ['min' => 4.00, 'max' => 4.99, 'estado' => 'amarillo', 'leyenda' => '4.00–4.99'],
        ['min' => 5.00, 'max' => 5.40, 'estado' => 'verde', 'leyenda' => '5.00–5.40'],
        ['min' => 5.41, 'estado' => 'rojo', 'leyenda' => '≥ 5.41'],
      ],
    ],
  ],
  'materia_prima' => [
    'inventario' => [
      'humedad' => [
        'modo' => 'bandas',
        'leyenda' => '70–74',
        'bandas' => [
          ['min' => 70, 'max' => 74, 'estado' => 'verde', 'leyenda' => '70–74'],
          ['min' => 67, 'max' => 70, 'estado' => 'amarillo', 'leyenda' => '67–<70'],
          ['min' => 74, 'max' => 76, 'estado' => 'amarillo', 'leyenda' => '>74–76'],
          ['max' => 66.999999, 'estado' => 'rojo', 'leyenda' => '<67'],
          ['min' => 76.000001, 'estado' => 'rojo', 'leyenda' => '>76'],
        ],
      ],
      'solidos' => [
        'modo' => 'bandas',
        'leyenda' => '9–14 · máx. 18.0',
        'bandas' => [
          ['min' => 9, 'max' => 14, 'estado' => 'verde', 'leyenda' => '9–14'],
          ['min' => 6, 'max' => 9, 'estado' => 'amarillo', 'leyenda' => '6–<9'],
          ['min' => 14, 'max' => 18, 'estado' => 'amarillo', 'leyenda' => '>14–18.0'],
          ['max' => 5.999999, 'estado' => 'rojo', 'leyenda' => '<6'],
          ['min' => 18.000001, 'estado' => 'rojo', 'leyenda' => '>18.0'],
        ],
      ],
      'extractibilidad' => [
        'modo' => 'bandas',
        'leyenda' => '95–98',
        'bandas' => [
          ['min' => 95, 'max' => 98, 'estado' => 'verde', 'leyenda' => '95–98'],
          ['min' => 90, 'max' => 95, 'estado' => 'amarillo', 'leyenda' => '90–<95'],
          ['min' => 98, 'max' => 100, 'estado' => 'amarillo', 'leyenda' => '>98–100'],
          ['max' => 89.999999, 'estado' => 'rojo', 'leyenda' => '<90'],
          ['min' => 100.000001, 'estado' => 'rojo', 'leyenda' => '>100'],
        ],
      ],
      'ph' => [
        'modo' => 'bandas',
        'leyenda' => '11.3–12.2 · máx. 13.0',
        'bandas' => [
          ['min' => 11.3, 'max' => 12.2, 'estado' => 'verde', 'leyenda' => '11.3–12.2'],
          ['min' => 11, 'max' => 11.3, 'estado' => 'amarillo', 'leyenda' => '11–<11.3'],
          ['min' => 12.2, 'max' => 13, 'estado' => 'amarillo', 'leyenda' => '>12.2–13.0'],
          ['max' => 10.999999, 'estado' => 'rojo', 'leyenda' => '<11'],
          ['min' => 13.000001, 'estado' => 'rojo', 'leyenda' => '>13.0'],
        ],
      ],
      'rendimiento' => [
        'modo' => 'bandas',
        'leyenda' => '26.0–35.0',
        'bandas' => [
          ['min' => 26, 'max' => 35, 'estado' => 'verde', 'leyenda' => '26.0–35.0'],
          ['min' => 23, 'max' => 25.999999, 'estado' => 'amarillo', 'leyenda' => '23–<26.0'],
          ['min' => 35.000001, 'max' => 38, 'estado' => 'amarillo', 'leyenda' => '>35.0–38.0'],
          ['max' => 22.999999, 'estado' => 'rojo', 'leyenda' => '<23'],
          ['min' => 38.000001, 'estado' => 'rojo', 'leyenda' => '>38.0'],
        ],
      ],
      'conductividad' => [
        'CARNAZA' => ['verde_min' => 5, 'verde_max' => 9.43, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'DESBARBE' => ['verde_min' => 5, 'verde_max' => 9.52, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'DESORILLE' => ['verde_min' => 5, 'verde_max' => 8.92, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'DESPALME' => ['verde_min' => 5, 'verde_max' => 7.43, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'GARRA' => ['verde_min' => 5, 'verde_max' => 10.32, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'RECORTE' => ['verde_min' => 5, 'verde_max' => 15.27, 'amarillo_min' => 2, 'amarillo_max' => 20],
        'CUERO_ENTERO_PEDACERA' => ['verde_min' => 5, 'verde_max' => 20, 'amarillo_min' => 2, 'amarillo_max' => 20],
      ],
    ],
    'rendimiento_procesos' => [
      'rendimiento_pt' => [
        'CARNAZA' => [
          'modo' => 'bandas',
          'leyenda' => '>16 / 15.5–16 / <15.5',
          'bandas' => [
            ['min' => 16.000001, 'estado' => 'verde', 'leyenda' => '>16'],
            ['min' => 15.5, 'max' => 16, 'estado' => 'amarillo', 'leyenda' => '15.5–16'],
            ['max' => 15.499999, 'estado' => 'rojo', 'leyenda' => '<15.5'],
          ],
        ],
        'CUERO_ENTERO' => $rendimientoPtAbove19,
        'PEDACERA' => $rendimientoPtAbove19,
        'RECORTE' => [
          'modo' => 'bandas',
          'leyenda' => '>13.5 / 13–13.5 / <13',
          'bandas' => [
            ['min' => 13.500001, 'estado' => 'verde', 'leyenda' => '>13.5'],
            ['min' => 13, 'max' => 13.5, 'estado' => 'amarillo', 'leyenda' => '13–13.5'],
            ['max' => 12.999999, 'estado' => 'rojo', 'leyenda' => '<13'],
          ],
        ],
      ],
      'extractibilidad_enzima' => [
        'CARNAZA' => [
          'modo' => 'bandas',
          'leyenda' => '<95 / 95–97 / >97',
          'bandas' => [
            ['max' => 94.999999, 'estado' => 'verde', 'leyenda' => '<95'],
            ['min' => 95, 'max' => 97, 'estado' => 'amarillo', 'leyenda' => '95–97'],
            ['min' => 97.000001, 'estado' => 'rojo', 'leyenda' => '>97'],
          ],
        ],
        'DESBARBE' => $enzymeBelow80,
        'GARRA' => $enzymeBelow80,
        'DESORILLE' => $enzymeBelow80,
        'CUERO_ENTERO' => $enzymeBelow90,
        'PEDACERA_AMERICANA' => $enzymeBelow90,
      ],
    ],
  ],
  'secadores' => [
    'indicadores_superiores' => [
      'viscosidad_churro' => [
        'modo' => 'bandas',
        'leyenda' => '≤40',
        'bandas' => [
          ['max' => 40, 'estado' => 'verde', 'leyenda' => '≤40'],
          ['min' => 40, 'max' => 43, 'estado' => 'amarillo', 'leyenda' => '>40–43'],
          ['min' => 43, 'estado' => 'rojo', 'leyenda' => '>43'],
        ],
      ],
      'solido_entrada' => [
        'modo' => 'bandas',
        'leyenda' => '≥37',
        'bandas' => [
          ['min' => 37, 'estado' => 'verde', 'leyenda' => '≥37'],
          ['min' => 35, 'max' => 37, 'estado' => 'amarillo', 'leyenda' => '35–<37'],
          ['max' => 35, 'estado' => 'rojo', 'leyenda' => '<35'],
        ],
      ],
    ],
    'temperatura_recamaras' => [
      'tunel_1' => [
        1 => $range(32, 36, 31, 38),
        2 => $range(37, 41, 36, 43),
        3 => $range(41, 45, 39, 47),
        4 => $range(45, 49, 43, 51),
        5 => $range(48, 51, 47, 53),
        6 => $range(51, 56, 50, 58),
        7 => $range(57, 58, 56, 59),
        8 => $range(58, 65, 56, 67),
        9 => $range(61, 70, 60, 72),
      ],
      'tunel_2' => [
        2 => $range(37, 41, 36, 43),
        3 => $range(41, 45, 39, 47),
        4 => $range(45, 49, 43, 51),
        5 => $range(48, 51, 47, 53),
        6 => $range(51, 56, 50, 58),
        7 => $range(57, 58, 56, 59),
        8 => $range(58, 65, 56, 67),
      ],
    ],
    'humedad_recamaras' => [
      1 => ['verde_lt' => 90.000001, 'amarillo_min' => 90, 'amarillo_max' => 93, 'label' => '≤90'],
      2 => ['verde_lt' => 90.000001, 'amarillo_min' => 90, 'amarillo_max' => 93, 'label' => '≤90'],
      3 => ['verde_lt' => 90.000001, 'amarillo_min' => 90, 'amarillo_max' => 93, 'label' => '≤90'],
      4 => ['verde_lt' => 60.000001, 'amarillo_min' => 60, 'amarillo_max' => 65, 'label' => '≤60'],
      5 => ['verde_lt' => 60.000001, 'amarillo_min' => 60, 'amarillo_max' => 65, 'label' => '≤60'],
      6 => ['verde_lt' => 49.000001, 'amarillo_min' => 49, 'amarillo_max' => 54, 'label' => '≤49'],
      7 => ['verde_lt' => 37.000001, 'amarillo_min' => 37, 'amarillo_max' => 42, 'label' => '≤37'],
      8 => ['verde_lt' => 30.000001, 'amarillo_min' => 30, 'amarillo_max' => 35, 'label' => '≤30'],
      9 => ['verde_lt' => 30.000001, 'amarillo_min' => 30, 'amarillo_max' => 35, 'label' => '≤30'],
    ],
    'metricas_compartidas' => [
      'velocidad_banda' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 14.5, 'max' => 15, 'estado' => 'verde', 'leyenda' => '14.5–15'],
          ['min' => 13, 'max' => 14.499999, 'estado' => 'amarillo', 'leyenda' => '13–<14.5'],
          ['max' => 12.999999, 'estado' => 'rojo', 'leyenda' => '<13'],
          ['min' => 15.000001, 'estado' => 'rojo', 'leyenda' => '>15'],
        ],
      ],
      'caudal_aire' => [],
      'agua_caliente_suministro' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 90.000001, 'estado' => 'verde', 'leyenda' => '>90'],
          ['min' => 85, 'max' => 90, 'estado' => 'amarillo', 'leyenda' => '85–90'],
          ['max' => 84.999999, 'estado' => 'rojo', 'leyenda' => '<85'],
        ],
      ],
      'agua_caliente_retorno' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 80, 'estado' => 'verde', 'leyenda' => '≥80'],
          ['min' => 75, 'max' => 79.999999, 'estado' => 'amarillo', 'leyenda' => '75–<80'],
          ['max' => 74.999999, 'estado' => 'rojo', 'leyenda' => '<75'],
        ],
      ],
      'presion_vapor' => $range(3.0, 3.2, 2.8, 2.999999),
      'humedad_suministro_aire' => [],
      'verificacion_altura_galleta' => $range(6.5, 7.5, 6.0, 6.499999),
      'verificacion_hum_penultima' => $maximum(12, 15),
      'verificacion_hum_ultima' => $maximum(12, 15),
      'verificacion_rc9' => $maximum(10, 12),
      'verificacion_hum_relativa_cam5' => $maximum(36, 37),
      'verificacion_textura' => [
        'modo' => 'texto',
        'verde' => ['Firme'],
        'amarillo' => ['Plástica', 'Plastica'],
        'rojo' => ['Húmeda', 'Humeda'],
      ],
    ],
    'caudal_aire' => [
      'tunel_1' => ['modo' => 'minimo', 'verde_min' => 90000, 'amarillo_min' => 89000],
      'tunel_2' => $range(74000, 82500, 70000, 83999.99),
    ],
  ],
  'concentradores' => [
    'invertido' => [
      'flujo' => ['modo' => 'minimo', 'verde_min' => 60, 'amarillo_min' => 50],
    ],
    'flujo' => [
      'concentrador_1' => [
        'modo' => 'bandas',
        'leyenda' => '34',
        'bandas' => [
          ['min' => 34, 'max' => 34, 'estado' => 'verde', 'leyenda' => '34'],
          ['min' => 30, 'max' => 34, 'estado' => 'amarillo', 'leyenda' => '30–<34'],
          ['max' => 30, 'estado' => 'rojo', 'leyenda' => '<30'],
          ['min' => 34, 'estado' => 'rojo', 'leyenda' => '>34'],
        ],
      ],
      'concentrador_2' => [
        'modo' => 'bandas',
        'leyenda' => '35',
        'bandas' => [
          ['min' => 35, 'max' => 35, 'estado' => 'verde', 'leyenda' => '35'],
          ['min' => 31, 'max' => 35, 'estado' => 'amarillo', 'leyenda' => '31–<35'],
          ['max' => 31, 'estado' => 'rojo', 'leyenda' => '<31'],
          ['min' => 35, 'estado' => 'rojo', 'leyenda' => '>35'],
        ],
      ],
      'concentrador_3' => [
        'modo' => 'bandas',
        'leyenda' => '35',
        'bandas' => [
          ['min' => 35, 'max' => 35, 'estado' => 'verde', 'leyenda' => '35'],
          ['min' => 31, 'max' => 35, 'estado' => 'amarillo', 'leyenda' => '31–<35'],
          ['max' => 31, 'estado' => 'rojo', 'leyenda' => '<31'],
          ['min' => 35, 'estado' => 'rojo', 'leyenda' => '>35'],
        ],
      ],
      'concentrador_4' => [
        'modo' => 'bandas',
        'leyenda' => '38',
        'bandas' => [
          ['min' => 38, 'max' => 38, 'estado' => 'verde', 'leyenda' => '38'],
          ['min' => 31, 'max' => 38, 'estado' => 'amarillo', 'leyenda' => '31–<38'],
          ['max' => 31, 'estado' => 'rojo', 'leyenda' => '<31'],
          ['min' => 38, 'estado' => 'rojo', 'leyenda' => '>38'],
        ],
      ],
    ],
    'vacio' => [
      'modo' => 'bandas',
      'leyenda' => '>525',
      'bandas' => [
        ['min' => 525.000001, 'estado' => 'verde', 'leyenda' => '>525'],
        ['min' => 490, 'max' => 525, 'estado' => 'amarillo', 'leyenda' => '490–525'],
        ['max' => 490, 'estado' => 'rojo', 'leyenda' => '<490'],
      ],
    ],
    'temperatura' => [
      'modo' => 'bandas',
      'leyenda' => '<50',
      'bandas' => [
        ['max' => 49.999999, 'estado' => 'verde', 'leyenda' => '<50'],
        ['min' => 50, 'max' => 54, 'estado' => 'amarillo', 'leyenda' => '50–54'],
        ['min' => 54, 'estado' => 'rojo', 'leyenda' => '>54'],
      ],
    ],
    'solidos_entrada' => [
      'modo' => 'bandas',
      'leyenda' => '21-22',
      'bandas' => [
        ['min' => 21, 'max' => 22, 'estado' => 'verde', 'leyenda' => '21–22'],
        ['min' => 19, 'max' => 21, 'estado' => 'amarillo', 'leyenda' => '19–<21'],
        ['max' => 19, 'estado' => 'rojo', 'leyenda' => '<19'],
        ['min' => 22, 'estado' => 'rojo', 'leyenda' => '>22'],
      ],
    ],
    'solidos_salida' => [
      'modo' => 'bandas',
      'leyenda' => '≥37',
      'bandas' => [
        ['min' => 37, 'estado' => 'verde', 'leyenda' => '≥37'],
        ['min' => 34, 'max' => 36.999999, 'estado' => 'amarillo', 'leyenda' => '34–<37'],
        ['max' => 33.999999, 'estado' => 'rojo', 'leyenda' => '<34'],
      ],
    ],
    'corriente' => [
      'concentrador_1' => [
        'modo' => 'bandas',
        'leyenda' => '<85',
        'bandas' => [
          ['max' => 84.999999, 'estado' => 'verde', 'leyenda' => '<85'],
          ['min' => 85, 'max' => 86.999999, 'estado' => 'amarillo', 'leyenda' => '85–<87'],
          ['min' => 87, 'estado' => 'rojo', 'leyenda' => '≥87'],
        ],
      ],
      'concentrador_2' => [
        'modo' => 'bandas',
        'leyenda' => '<60',
        'bandas' => [
          ['max' => 59.999999, 'estado' => 'verde', 'leyenda' => '<60'],
          ['min' => 60, 'max' => 60.999999, 'estado' => 'amarillo', 'leyenda' => '60–<61'],
          ['min' => 61, 'estado' => 'rojo', 'leyenda' => '≥61'],
        ],
      ],
      'concentrador_3' => [
        'modo' => 'bandas',
        'leyenda' => '<60',
        'bandas' => [
          ['max' => 59.999999, 'estado' => 'verde', 'leyenda' => '<60'],
          ['min' => 60, 'max' => 61.999999, 'estado' => 'amarillo', 'leyenda' => '60–<62'],
          ['min' => 62, 'estado' => 'rojo', 'leyenda' => '≥62'],
        ],
      ],
      'concentrador_4' => [
        'modo' => 'bandas',
        'leyenda' => '<60',
        'bandas' => [
          ['max' => 59.999999, 'estado' => 'verde', 'leyenda' => '<60'],
          ['min' => 60, 'max' => 61.999999, 'estado' => 'amarillo', 'leyenda' => '60–<62'],
          ['min' => 62, 'estado' => 'rojo', 'leyenda' => '≥62'],
        ],
      ],
    ],
    'presion_vapor' => [
      'modo' => 'bandas',
      'leyenda' => '<32',
      'bandas' => [
        ['max' => 31.999999, 'estado' => 'verde', 'leyenda' => '<32'],
        ['min' => 32, 'max' => 32, 'estado' => 'amarillo', 'leyenda' => '32'],
        ['min' => 32.000001, 'estado' => 'rojo', 'leyenda' => '>32'],
      ],
    ],
    'sin_semaforo' => [
      'frecuencia_moyno',
      'corriente_moyno',
      'setpoint_presion_vapor',
      'apertura_valvula_vapor',
      'flujo_entrada_caldo',
      'temperatura_interna',
      'nivel_tanque_condensados',
    ],
  ],
  'produccion_monitoreo' => [
    'membranas' => [
      'solidos' => [
        'modo' => 'bandas',
        'bandas' => [
          ['max' => 17.999999, 'estado' => 'rojo', 'leyenda' => '< 18'],
          ['min' => 18, 'max' => 20, 'estado' => 'amarillo', 'leyenda' => '18–20'],
          ['min' => 20.000001, 'estado' => 'verde', 'leyenda' => '> 20'],
        ],
      ],
    ],
    'votators' => [
      'temperatura_nariz' => [
        'modo' => 'bandas',
        'leyenda' => '14–19',
        'bandas' => [
          ['max' => 11.999999, 'estado' => 'rojo', 'leyenda' => '<12'],
          ['min' => 12, 'max' => 13.999999, 'estado' => 'amarillo', 'leyenda' => '12–13.9'],
          ['min' => 14, 'max' => 19, 'estado' => 'verde', 'leyenda' => '14–19'],
          ['min' => 19.000001, 'max' => 23, 'estado' => 'amarillo', 'leyenda' => '19.1–23'],
          ['min' => 23.000001, 'estado' => 'rojo', 'leyenda' => '≥23.1'],
        ],
      ],
      'flujo' => [
        'modo' => 'bandas',
        'leyenda' => '11.5-12.5',
        'bandas' => [
          ['max' => 10, 'estado' => 'rojo', 'leyenda' => '<10'],
          ['min' => 10.5, 'max' => 11.499999, 'estado' => 'amarillo', 'leyenda' => '10.5–<11.5'],
          ['min' => 11.5, 'max' => 12.5, 'estado' => 'verde', 'leyenda' => '11.5–12.5'],
          ['min' => 12.500001, 'max' => 12.999999, 'estado' => 'amarillo', 'leyenda' => '>12.5–<13'],
          ['min' => 13, 'estado' => 'rojo', 'leyenda' => '≥13'],
        ],
      ],
      'presion_cuajado' => [
        'modo' => 'bandas',
        'leyenda' => '24-26',
        'bandas' => [
          ['min' => 24, 'max' => 26, 'estado' => 'verde', 'leyenda' => '24–26'],
          ['min' => 23, 'max' => 24, 'estado' => 'amarillo', 'leyenda' => '23–<24'],
          ['min' => 26, 'max' => 27, 'estado' => 'amarillo', 'leyenda' => '>26–27'],
          ['max' => 23, 'estado' => 'rojo', 'leyenda' => '<23'],
          ['min' => 27, 'estado' => 'rojo', 'leyenda' => '>27'],
        ],
      ],
      'solidos' => [
        'modo' => 'bandas',
        'leyenda' => '>37',
        'bandas' => [
          ['max' => 34.999999, 'estado' => 'rojo', 'leyenda' => '<35'],
          ['min' => 35, 'max' => 37, 'estado' => 'amarillo', 'leyenda' => '35–37'],
          ['min' => 37.000001, 'estado' => 'verde', 'leyenda' => '>37'],
        ],
      ],
      'sin_semaforo' => ['amperaje_bomba', 'amperaje_reductor'],
    ],
    'cocedores' => [
      'flujo_chicos' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 140, 'max' => 155, 'estado' => 'verde', 'leyenda' => '140–155'],
          ['min' => 130, 'max' => 140, 'estado' => 'amarillo', 'leyenda' => '130–<140'],
          ['min' => 155, 'max' => 165, 'estado' => 'amarillo', 'leyenda' => '>155–165'],
          ['max' => 130, 'estado' => 'rojo', 'leyenda' => '<130'],
          ['min' => 165, 'estado' => 'rojo', 'leyenda' => '>165'],
        ],
      ],
      'flujo_grandes' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 160, 'max' => 175, 'estado' => 'verde', 'leyenda' => '160–175'],
          ['min' => 150, 'max' => 160, 'estado' => 'amarillo', 'leyenda' => '150–<160'],
          ['min' => 175, 'max' => 185, 'estado' => 'amarillo', 'leyenda' => '>175–185'],
          ['max' => 150, 'estado' => 'rojo', 'leyenda' => '<150'],
          ['min' => 185, 'estado' => 'rojo', 'leyenda' => '>185'],
        ],
      ],
      'temperatura_entrada' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 60, 'max' => 68, 'estado' => 'verde', 'leyenda' => '60–68'],
          ['min' => 59, 'max' => 60, 'estado' => 'amarillo', 'leyenda' => '59–<60'],
          ['min' => 68, 'max' => 70, 'estado' => 'amarillo', 'leyenda' => '>68–70'],
          ['max' => 59, 'estado' => 'rojo', 'leyenda' => '<59'],
          ['min' => 70, 'estado' => 'rojo', 'leyenda' => '>70'],
        ],
      ],
      'temperatura_salida' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 55, 'max' => 63, 'estado' => 'verde', 'leyenda' => '55–63'],
          ['min' => 52, 'max' => 55, 'estado' => 'amarillo', 'leyenda' => '52–<55'],
          ['min' => 63, 'max' => 64, 'estado' => 'amarillo', 'leyenda' => '>63–64'],
          ['max' => 52, 'estado' => 'rojo', 'leyenda' => '<52'],
          ['min' => 64, 'estado' => 'rojo', 'leyenda' => '>64'],
        ],
      ],
      'ntu' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 60, 'max' => 600, 'estado' => 'verde', 'leyenda' => '60–600'],
          ['min' => 600, 'max' => 850, 'estado' => 'amarillo', 'leyenda' => '>600–850'],
          ['min' => 850, 'estado' => 'rojo', 'leyenda' => '>850'],
        ],
      ],
      'solidos' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 2.5, 'max' => 3.5, 'estado' => 'verde', 'leyenda' => '2.5–3.5'],
          ['min' => 2.3, 'max' => 2.5, 'estado' => 'amarillo', 'leyenda' => '2.3–<2.5'],
          ['min' => 3.5, 'max' => 3.8, 'estado' => 'amarillo', 'leyenda' => '>3.5–3.8'],
          ['max' => 2.3, 'estado' => 'rojo', 'leyenda' => '<2.3'],
          ['min' => 3.8, 'estado' => 'rojo', 'leyenda' => '>3.8'],
        ],
      ],
      'ph' => [
        'modo' => 'bandas',
        'bandas' => [
          ['min' => 3, 'max' => 3.8, 'estado' => 'verde', 'leyenda' => '3–3.8'],
          ['min' => 2.8, 'max' => 3, 'estado' => 'amarillo', 'leyenda' => '2.8–<3'],
          ['min' => 3.8, 'max' => 4, 'estado' => 'amarillo', 'leyenda' => '>3.8–4'],
          ['max' => 2.8, 'estado' => 'rojo', 'leyenda' => '<2.8'],
          ['min' => 4, 'estado' => 'rojo', 'leyenda' => '>4'],
        ],
      ],
    ],
    'clarificador' => [
      'solidos' => [
        'modo' => 'bandas',
        'leyenda' => '2.5-3.5',
        'bandas' => [
          ['min' => 2.5, 'max' => 3.5, 'estado' => 'verde', 'leyenda' => '2.5–3.5'],
          ['min' => 2.3, 'max' => 2.5, 'estado' => 'amarillo', 'leyenda' => '2.3–<2.5'],
          ['min' => 3.5, 'max' => 3.8, 'estado' => 'amarillo', 'leyenda' => '>3.5–3.8'],
          ['max' => 2.3, 'estado' => 'rojo', 'leyenda' => '<2.3'],
          ['min' => 3.8, 'estado' => 'rojo', 'leyenda' => '>3.8'],
        ],
      ],
      'temperatura' => [
        'modo' => 'bandas',
        'leyenda' => '56-63',
        'bandas' => [
          ['min' => 56, 'max' => 63, 'estado' => 'verde'],
          ['min' => 54, 'max' => 56, 'estado' => 'amarillo'],
          ['min' => 63, 'max' => 68, 'estado' => 'amarillo'],
          ['max' => 54, 'estado' => 'rojo'],
          ['min' => 68, 'estado' => 'rojo'],
        ],
      ],
      'flujo' => [
        'modo' => 'bandas',
        'leyenda' => '900-1300',
        'bandas' => [
          ['min' => 900, 'max' => 1300, 'estado' => 'verde'],
          ['min' => 750, 'max' => 900, 'estado' => 'amarillo'],
          ['max' => 750, 'estado' => 'rojo'],
          ['min' => 1300, 'estado' => 'rojo'],
        ],
      ],
      'flujo_polimero' => [
        'modo' => 'bandas',
        'leyenda' => '11-13',
        'bandas' => [
          ['min' => 11, 'max' => 13, 'estado' => 'verde'],
          ['min' => 10, 'max' => 11, 'estado' => 'amarillo'],
          ['max' => 10, 'estado' => 'rojo'],
          ['min' => 13, 'estado' => 'rojo'],
        ],
      ],
      'ntu_entrada' => [
        'modo' => 'bandas',
        'leyenda' => '60-600',
        'bandas' => [
          ['min' => 60, 'max' => 600, 'estado' => 'verde'],
          ['min' => 600, 'max' => 850, 'estado' => 'amarillo'],
          ['max' => 60, 'estado' => 'rojo'],
          ['min' => 850, 'estado' => 'rojo'],
        ],
      ],
      'ntu_salida' => [
        'modo' => 'bandas',
        'leyenda' => '5-<10',
        'bandas' => [
          ['min' => 5, 'max' => 9.999999, 'estado' => 'verde'],
          ['min' => 4, 'max' => 5, 'estado' => 'amarillo'],
          ['min' => 10, 'max' => 12, 'estado' => 'amarillo'],
          ['max' => 4, 'estado' => 'rojo'],
          ['min' => 12, 'estado' => 'rojo'],
        ],
      ],
      'ph_entrada' => [
        'modo' => 'bandas',
        'leyenda' => '3-3.8',
        'bandas' => [
          ['min' => 3, 'max' => 3.8, 'estado' => 'verde'],
          ['min' => 2.8, 'max' => 3, 'estado' => 'amarillo'],
          ['min' => 3.8, 'max' => 4, 'estado' => 'amarillo'],
          ['max' => 2.8, 'estado' => 'rojo'],
          ['min' => 4, 'estado' => 'rojo'],
        ],
      ],
      'ph_electrodo' => [
        'modo' => 'bandas',
        'leyenda' => '5.5-6.5',
        'bandas' => [
          ['min' => 5.5, 'max' => 6.5, 'estado' => 'verde'],
          ['min' => 4.9, 'max' => 5.5, 'estado' => 'amarillo'],
          ['min' => 6.5, 'max' => 7, 'estado' => 'amarillo'],
          ['max' => 4.9, 'estado' => 'rojo'],
          ['min' => 7, 'estado' => 'rojo'],
        ],
      ],
      'ph_salida' => [
        'modo' => 'bandas',
        'leyenda' => '5.5-6.5',
        'bandas' => [
          ['min' => 5.5, 'max' => 6.5, 'estado' => 'verde'],
          ['min' => 4.9, 'max' => 5.5, 'estado' => 'amarillo'],
          ['min' => 6.5, 'max' => 7, 'estado' => 'amarillo'],
          ['max' => 4.9, 'estado' => 'rojo'],
          ['min' => 7, 'estado' => 'rojo'],
        ],
      ],
      'ce_salida' => [
        'modo' => 'bandas',
        'leyenda' => '<6.5',
        'bandas' => [
          ['max' => 6.499999, 'estado' => 'verde'],
          ['min' => 6.5, 'max' => 7, 'estado' => 'amarillo'],
          ['min' => 7, 'estado' => 'rojo'],
        ],
      ],
      'tanq_balance' => [
        'modo' => 'bandas',
        'leyenda' => '50-80',
        'bandas' => [
          ['min' => 50, 'max' => 80, 'estado' => 'verde'],
          ['min' => 30, 'max' => 50, 'estado' => 'amarillo'],
          ['min' => 80, 'max' => 90, 'estado' => 'amarillo'],
          ['max' => 30, 'estado' => 'rojo'],
          ['min' => 90, 'estado' => 'rojo'],
        ],
      ],
    ],
    'integracion' => [
      'visc_integracion' => ['modo' => 'minimo', 'verde_min' => 25.01],
      'flujo_integracion' => $range(2, 5, 1.5, 5.5),
    ],
  ],
];
