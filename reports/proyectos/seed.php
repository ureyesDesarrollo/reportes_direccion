<?php

declare(strict_types=1);

return [
  [
    'slug' => 'mejora-continua',
    'nombre' => 'Mejora continua',
    'accent' => 'green',
    'secciones' => [
      [
        'slug' => 'top-5',
        'nombre' => 'Top 5',
        'items' => [
          ['nombre' => 'Cadena secador 1', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Santiago', 'inicio' => '11-Jun', 'cierre' => '19-Jun', 'status_key' => 'progress', 'status_label' => 'Proceso'],
          ['nombre' => 'Preparador 12 Inox', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Rafa', 'inicio' => '20-Apr', 'cierre' => '30-Jul', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Extractores e inyectores', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Santiago', 'inicio' => '', 'cierre' => '30-Jul', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
        ],
      ],
      [
        'slug' => 'espera',
        'nombre' => 'En espera',
        'items' => [
          ['nombre' => 'Eficientar recuperacion de grasa en cocedores', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Uriel', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Habilitar 4 lavadores', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Manuel Castaneda', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Recuperacion de condensados', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Pedro', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Automatizar cocedores', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Uriel', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Recuperacion de Segundo acido', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => '', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Proyecto de calentamiento de agua para calderas con cogenerador', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Pedro', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Recuperacion de agua acida y alcalina en intercambiador ionico', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Upgrade tanque quimicos', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Santiago', 'status_key' => 'waiting', 'status_label' => 'En espera'],
        ],
      ],
      [
        'slug' => 'finalizados',
        'nombre' => 'Finalizados',
        'items' => [],
      ],
    ],
  ],
  [
    'slug' => 'sistemas',
    'nombre' => 'Sistemas',
    'accent' => 'blue',
    'secciones' => [
      [
        'slug' => 'top-5',
        'nombre' => 'Top 5',
        'items' => [
          ['nombre' => 'Tableros direccion general (Licenciado)', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Ulises', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Digitalizacion de bitacoras (Mantenimiento)', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'Angel', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Desarrollo Fractal Casero', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'Ulises', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
        ],
      ],
      [
        'slug' => 'espera',
        'nombre' => 'En espera',
        'items' => [
          ['nombre' => 'Desarrollo sistema de extraccion'],
          ['nombre' => 'Desarrollo sistema de produccion nova'],
          ['nombre' => 'Desarrollo sistema de pelambre'],
          ['nombre' => 'Desarrollo Help Desk'],
          ['nombre' => 'Desarrollo Seguimiento RH'],
          ['nombre' => 'Mejoras para el sistema de produccion'],
          ['nombre' => 'Proyecto Semaforos (Reportes)'],
          ['nombre' => 'IA Propia'],
          ['nombre' => 'Automatizacion Cocedores'],
          ['nombre' => 'Mejorar la Ciberseguridad'],
          ['nombre' => 'Cobertura completa red inalambrica'],
          ['nombre' => 'Redundancia fibra optica'],
          ['nombre' => 'Automatizacion proceso de compras'],
          ['nombre' => 'Configuracion de nueva empresa en SAI'],
          ['nombre' => 'Desarrollo o compra de portal de proveedores'],
          ['nombre' => 'Intranet Corporativa'],
          ['nombre' => 'CRM'],
          ['nombre' => 'Exportaciones'],
          ['nombre' => 'Seguimiento transporte'],
          ['nombre' => 'VEN F005'],
          ['nombre' => 'Muestras'],
          ['nombre' => 'Programacion de embarques'],
          ['nombre' => 'Etiquetas SENASICA'],
          ['nombre' => 'Quejas'],
          ['nombre' => 'Sistema Sanidad'],
        ],
      ],
    ],
  ],
  [
    'slug' => 'obra-civil',
    'nombre' => 'Obra civil',
    'accent' => 'orange',
    'secciones' => [
      [
        'slug' => 'top-5',
        'nombre' => 'Top 5',
        'items' => [
          ['nombre' => 'Eliminar goteras zona blanca y gris', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'German', 'status_key' => 'not-started', 'status_label' => 'Sin iniciar'],
          ['nombre' => 'Laboratorio de calidad', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'German', 'status_key' => 'progress', 'status_label' => 'Proceso'],
          ['nombre' => 'Cisterna detras de talleres', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'German', 'status_key' => 'not-started', 'status_label' => 'Sin iniciar'],
          ['nombre' => 'Pavimentacion de vialidad frente a bascula', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'percent', 'status_label' => '80%'],
          ['nombre' => 'Nivel 3 corporativo', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'German', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Nivel 4 corporativo', 'prioridad_key' => 'high', 'prioridad_label' => 'Alta', 'responsable' => 'German', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Rehabilitacion de techumbre en Pelambre', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'progress', 'status_label' => 'Proceso'],
          ['nombre' => 'Cisterna frente a Noria', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'not-started', 'status_label' => 'Sin iniciar'],
        ],
      ],
      [
        'slug' => 'espera',
        'nombre' => 'En espera',
        'items' => [
          ['nombre' => 'Banos nuevos', 'prioridad_key' => 'low', 'prioridad_label' => 'Baja', 'responsable' => 'German', 'status_key' => 'not-started', 'status_label' => 'Sin iniciar'],
          ['nombre' => 'Rehabilitacion de granja', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'progress', 'status_label' => 'Proceso'],
          ['nombre' => 'Aduana en zona gris', 'prioridad_key' => 'medium', 'prioridad_label' => 'Media', 'responsable' => 'German', 'status_key' => 'not-started', 'status_label' => 'Sin iniciar'],
        ],
      ],
      [
        'slug' => 'finalizados',
        'nombre' => 'Finalizados',
        'items' => [
          ['nombre' => 'Tuberias de puente', 'responsable' => 'German', 'status_key' => 'done', 'status_label' => 'Terminado'],
        ],
      ],
    ],
  ],
  [
    'slug' => 'nuevos-proyectos',
    'nombre' => 'Nuevos proyectos',
    'accent' => 'red',
    'secciones' => [
      [
        'slug' => 'top-5',
        'nombre' => 'Top 5',
        'items' => [
          ['nombre' => 'Torre de molienda', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Santiago', 'inicio' => '12-Jun', 'cierre' => '30-Aug', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Filtros de celulosa', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Santiago', 'inicio' => '12-Jun', 'cierre' => '15-Jul', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Mezcladora de 5 ton para colageno', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Santiago', 'inicio' => '1-Jun', 'cierre' => '15-Jul', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Cambio de secador de colageno a progel', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Santiago', 'inicio' => '12-Jun', 'cierre' => '30-Sep', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
          ['nombre' => 'Clarificador 2', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Uriel', 'status_key' => 'blank', 'status_label' => 'Pendiente'],
        ],
      ],
      [
        'slug' => 'espera',
        'nombre' => 'En espera',
        'items' => [
          ['nombre' => 'CIP', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Esterilizador', 'prioridad_key' => 'medium', 'prioridad_label' => 'Medio', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Intercambiador Ionico', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Bernal', 'status_key' => 'waiting', 'status_label' => 'En espera'],
          ['nombre' => 'Paneles solares', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'responsable' => 'Pedro', 'status_key' => 'waiting', 'status_label' => 'En espera'],
        ],
      ],
      [
        'slug' => 'finalizados',
        'nombre' => 'Finalizados',
        'items' => [
          ['nombre' => 'Votators secador 1', 'status_key' => 'done', 'status_label' => 'Terminado'],
          ['nombre' => 'Concentrador 4', 'status_key' => 'done', 'status_label' => 'Terminado'],
          ['nombre' => 'Molino de cuero 3', 'status_key' => 'done', 'status_label' => 'Terminado'],
          ['nombre' => 'Polipasto v1 y v2', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'status_key' => 'done', 'status_label' => 'Terminado'],
          ['nombre' => 'Cocedores 8 y 9', 'prioridad_key' => 'high', 'prioridad_label' => 'Alto', 'status_key' => 'done', 'status_label' => 'Terminado'],
        ],
      ],
    ],
  ],
  [
    'slug' => 'ventas',
    'nombre' => 'Ventas',
    'accent' => 'indigo',
    'secciones' => [
      [
        'slug' => 'proyectos',
        'nombre' => 'Proyectos',
        'items' => [],
      ],
      [
        'slug' => 'terminados',
        'nombre' => 'Terminados',
        'items' => [],
      ],
    ],
  ],
];
