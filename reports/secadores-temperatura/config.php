<?php

return [
  'titulo' => 'Secadores / Temperatura Detalle',
  'fecha_desde' => date('Y-m-d'),
  'intervalo_actualizacion_ms' => 1800000,
  'limite_registros' => 120,
  'intervalo_muestreo_minutos' => 30,
  'tunel_default' => 'tunel_1',
  'timezone' => 'America/Mexico_City',
  'formato_fecha' => 'Y-m-d H:i:s',
  'formato_fecha_grafica' => 'H:i:s',

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
        'TEMPERATURA_RECAMARA_2_TUNEL_1' => [
          'label' => 'Recámara 2',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 34.4,
            'verde_max' => 37.6,
            'amarillo_min' => 34,
            'amarillo_max' => 38
          ],
        ],
        'TEMPERATURA_RECAMARA_3_TUNEL_1' => [
          'label' => 'Recámara 3',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 43.4,
            'verde_max' => 46.6,
            'amarillo_min' => 43,
            'amarillo_max' => 47
          ],
        ],
        'TEMPERATURA_RECAMARA_4_TUNEL_1' => [
          'label' => 'Recámara 4',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 41.4,
            'verde_max' => 44.6,
            'amarillo_min' => 41,
            'amarillo_max' => 45
          ],
        ],
        'TEMPERATURA_RECAMARA_5_TUNEL_1' => [
          'label' => 'Recámara 5',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 48.4,
            'verde_max' => 51.6,
            'amarillo_min' => 48,
            'amarillo_max' => 52
          ],
        ],
        'TEMPERATURA_RECAMARA_6_TUNEL_1' => [
          'label' => 'Recámara 6',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 48.4,
            'verde_max' => 51.6,
            'amarillo_min' => 48,
            'amarillo_max' => 52
          ],
        ],
        'TEMPERATURA_RECAMARA_7_TUNEL_1' => [
          'label' => 'Recámara 7',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 56.4,
            'verde_max' => 59.6,
            'amarillo_min' => 56,
            'amarillo_max' => 60
          ],
        ],
        'TEMPERATURA_RECAMARA_8_TUNEL_1' => [
          'label' => 'Recámara 8',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 59.3,
            'verde_max' => 61.7,
            'amarillo_min' => 59,
            'amarillo_max' => 62
          ],
        ],
      ],
    ],

    'tunel_2' => [
      'titulo' => 'Túnel 2',
      'campos' => [
        'TEMPERATURA_ZONA_1' => [
          'label' => 'Zona 1',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 31.8,
            'verde_max' => 46.2,
            'amarillo_min' => 30,
            'amarillo_max' => 48
          ],
        ],
        'TEMPERATURA_ZONA_2' => [
          'label' => 'Zona 2',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 26.6,
            'verde_max' => 39.4,
            'amarillo_min' => 25,
            'amarillo_max' => 41
          ],
        ],
        'TEMPERATURA_ZONA_3' => [
          'label' => 'Zona 3',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 30.3,
            'verde_max' => 48.7,
            'amarillo_min' => 28,
            'amarillo_max' => 51
          ],
        ],
        'TEMPERATURA_ZONA_4' => [
          'label' => 'Zona 4',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 38.2,
            'verde_max' => 47.8,
            'amarillo_min' => 37,
            'amarillo_max' => 49
          ],
        ],
        'TEMPERATURA_ZONA_5' => [
          'label' => 'Zona 5',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 45.0,
            'verde_max' => 53.0,
            'amarillo_min' => 44,
            'amarillo_max' => 54
          ],
        ],
        'TEMPERATURA_ZONA_6' => [
          'label' => 'Zona 6',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 49.0,
            'verde_max' => 57.0,
            'amarillo_min' => 48,
            'amarillo_max' => 58
          ],
        ],
        'TEMPERATURA_ZONA_7' => [
          'label' => 'Zona 7',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 58.7,
            'verde_max' => 64.3,
            'amarillo_min' => 58,
            'amarillo_max' => 65
          ],
        ],
        'TEMPERATURA_ZONA_8' => [
          'label' => 'Zona 8',
          'semaforo' => [
            'modo' => 'rango',
            'verde_min' => 60.7,
            'verde_max' => 66.3,
            'amarillo_min' => 60,
            'amarillo_max' => 67
          ],
        ],
      ],
    ],
  ],
];
