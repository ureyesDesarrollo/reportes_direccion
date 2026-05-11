<?php

return [
  'titulo' => 'Secadores',
  'intervalo_actualizacion_ms' => 1800000,
  'detalle_url_base' => '../secadores-temperatura/index.php',
  'tuneles' => [
    'tunel_1' => [
      'titulo' => 'Secador 1',
      'subtitulo' => 'Resumen general del secador.',
      'parametros_continuos' => [
        [
          'titulo' => 'Flujo de aire',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente de origen',
          'estado' => 'gris',
          'source' => [
            'type' => 'mysql_caudal',
            'tunel_key' => 'tunel_1',
            'thresholds' => [
              'red_below' => 89000,
              'green_above' => 90000,
            ],
          ],
        ],
        [
          'titulo' => 'Humedad de entrada',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente de origen',
          'estado' => 'gris',
          'source' => [
            'type' => 'sqlserver_field',
            'field' => 'HUMEDAD_DE_ENTRADA_TUNEL_1',
            'unit' => '%',
            'decimals' => 1,
          ],
        ],
      ],
      'mantenimientos' => [
        [
          'titulo' => 'Cambio de filtros HVAC',
          'valor' => 'Pendiente',
          'detalle' => 'Sin captura',
          'estado' => 'amarillo',
        ],
        [
          'titulo' => 'Niveles de aceite',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente',
          'estado' => 'gris',
        ],
        [
          'titulo' => 'Limpieza de radiadores',
          'valor' => 'Pendiente',
          'detalle' => 'Sin captura',
          'estado' => 'amarillo',
        ],
        [
          'titulo' => 'Revision general',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente',
          'estado' => 'gris',
        ],
      ],
    ],
    'tunel_2' => [
      'titulo' => 'Secador 2',
      'subtitulo' => 'Resumen general del secador.',
      'parametros_continuos' => [
        [
          'titulo' => 'Flujo de aire',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente de origen',
          'estado' => 'gris',
          'source' => [
            'type' => 'mysql_caudal',
            'tunel_key' => 'tunel_2',
            'thresholds' => [
              'red_below' => 91000,
              'green_above' => 92000,
            ],
          ],
        ],
        /* [
          'titulo' => 'Humedad de aire',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente de origen',
          'estado' => 'gris',
        ],
        [
          'titulo' => 'Humedad de salida',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente de origen',
          'estado' => 'gris',
        ], */
      ],
      'mantenimientos' => [
        [
          'titulo' => 'Cambio de filtros HVAC',
          'valor' => 'Pendiente',
          'detalle' => 'Sin captura',
          'estado' => 'amarillo',
        ],
        [
          'titulo' => 'Niveles de aceite',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente',
          'estado' => 'gris',
        ],
        [
          'titulo' => 'Limpieza de radiadores',
          'valor' => 'Pendiente',
          'detalle' => 'Sin captura',
          'estado' => 'amarillo',
        ],
        [
          'titulo' => 'Revision general',
          'valor' => 'Sin captura',
          'detalle' => 'Pendiente',
          'estado' => 'gris',
        ],
      ],
    ],
  ],
];
