<?php

return [
  'titulo' => 'Secadores / Temperatura Detalle',
  'fecha_desde' => date('Y-m-d'),
  'intervalo_actualizacion_ms' => 600000,
  'limite_registros' => 120,
  'intervalo_muestreo_minutos' => 10,
  'tunel_default' => 'tunel_1',
  'timezone' => 'America/Mexico_City',
  'formato_fecha' => 'd/m/Y H:i',
  'formato_fecha_grafica' => 'H:i',

  // Ajusta esta conexión con los datos reales del SQL Server.
  'sqlserver' => [
    'server' => '192.168.1.105',
    'port' => 1433,
    'database' => 'AVEVA_TAGS',
    'user' => 'pbi',
    'pass' => 'Fer_Zam@2025',
    'encrypt' => false,
    'trust_server_certificate' => true,
    'login_timeout' => 5,
  ],

  // Ajusta este campo al nombre real de fecha/hora en TREND001.
  'tabla' => 'TREND001',
  'campo_fecha' => 'Time_Stamp',

  'tuneles' => [
    'tunel_1' => [
      'titulo' => 'Túnel 1',
      'campos' => [
        'TEMPERATURA_ZONA_1' => [
          'label' => 'Recámara 1',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 32,
            'verde_max' => 34,
            'amarillo_min' => 31,
            'amarillo_max' => 35
          ],
        ],

        'TEMPERATURA_ZONA_2' => [
          'label' => 'Recámara 2',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 37,
            'verde_max' => 39,
            'amarillo_min' => 36,
            'amarillo_max' => 40
          ],
        ],

        'TEMPERATURA_ZONA_3' => [
          'label' => 'Recámara 3',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 41,
            'verde_max' => 42,
            'amarillo_min' => 39,
            'amarillo_max' => 43
          ],
        ],

        'TEMPERATURA_ZONA_4' => [
          'label' => 'Recámara 4',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 46,
            'verde_max' => 47,
            'amarillo_min' => 45,
            'amarillo_max' => 48
          ],
        ],

        'TEMPERATURA_ZONA_5' => [
          'label' => 'Recámara 5',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 48,
            'verde_max' => 49,
            'amarillo_min' => 47,
            'amarillo_max' => 50
          ],
        ],

        'TEMPERATURA_ZONA_6' => [
          'label' => 'Recámara 6',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 51,
            'verde_max' => 52,
            'amarillo_min' => 50,
            'amarillo_max' => 53
          ],
        ],

        'TEMPERATURA_ZONA_7' => [
          'label' => 'Recámara 7',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 57,
            'verde_max' => 58,
            'amarillo_min' => 56,
            'amarillo_max' => 59
          ],
        ],

        'TEMPERATURA_ZONA_8' => [
          'label' => 'Recámara 8',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 61,
            'verde_max' => 62,
            'amarillo_min' => 60,
            'amarillo_max' => 63
          ],
        ],
      ],
    ],

    'tunel_2' => [
      'titulo' => 'Túnel 2',
      'campos' => [
        'TEMPERATURA_RECAMARA_2_TUNEL_1' => [
          'label' => 'Recámara 2',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 37,
            'verde_max' => 39,
            'amarillo_min' => 36,
            'amarillo_max' => 40
          ],
        ],

        'TEMPERATURA_RECAMARA_3_TUNEL_1' => [
          'label' => 'Recámara 3',
          'leyenda' => 'Recirculación',
          'estado_fijo' => [
            'label' => 'Recirculación',
            'key' => 'gris',
            'color' => '#94a3b8',
          ],
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 41,
            'verde_max' => 42,
            'amarillo_min' => 39,
            'amarillo_max' => 43
          ],
        ],

        'TEMPERATURA_RECAMARA_4_TUNEL_1' => [
          'label' => 'Recámara 4',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 46,
            'verde_max' => 47,
            'amarillo_min' => 45,
            'amarillo_max' => 48
          ],
        ],

        'TEMPERATURA_RECAMARA_5_TUNEL_1' => [
          'label' => 'Recámara 5',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 48,
            'verde_max' => 49,
            'amarillo_min' => 47,
            'amarillo_max' => 50
          ],
        ],

        'TEMPERATURA_RECAMARA_6_TUNEL_1' => [
          'label' => 'Recámara 6',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 51,
            'verde_max' => 52,
            'amarillo_min' => 50,
            'amarillo_max' => 53
          ],
        ],

        'TEMPERATURA_RECAMARA_7_TUNEL_1' => [
          'label' => 'Recámara 7',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 57,
            'verde_max' => 58,
            'amarillo_min' => 56,
            'amarillo_max' => 59
          ],
        ],

        'TEMPERATURA_RECAMARA_8_TUNEL_1' => [
          'label' => 'Recámara 8',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 61,
            'verde_max' => 62,
            'amarillo_min' => 60,
            'amarillo_max' => 63
          ],
        ],
      ],
    ],
  ],
];
