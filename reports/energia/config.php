<?php

declare(strict_types=1);

$productionSourceConfig = require __DIR__ . '/../avance-produccion-hora/config.php';

return [
  'titulo' => 'Reporte de Energía',
  'subtitulo' => 'Recibos, consumo y eficiencia energética por año',
  'actualizado' => 'Sin captura',
  'timezone' => 'America/Mexico_City',
  'database_key' => 'prod',
  'production_database' => (array)($productionSourceConfig['database'] ?? []),
  'hora_corte' => '07:00:00',
  'acceso_agua' => [
    'password_hash_env' => 'ENERGIA_AGUA_CLAVE_HASH',
    'timeout_seconds' => 2400,
  ],
  'consumos' => [
    'electricidad' => [
      'label' => 'Energía eléctrica',
      'description' => 'Por kg de grenetina producida',
      'unit' => 'kW/kg',
      'total_unit' => 'kW',
      'color' => '#475569',
      'value' => null,
      'icon' => 'fa-bolt',
    ],
    'gas_natural' => [
      'label' => 'Gas natural',
      'description' => 'Por kg de grenetina producida',
      'unit' => 'm³/kg',
      'total_unit' => 'm³',
      'color' => '#78716c',
      'value' => null,
      'icon' => 'fa-fire-flame-simple',
    ],
    'agua' => [
      'label' => 'Agua',
      'description' => 'Por kg de grenetina producida',
      'unit' => 'm³/kg',
      'total_unit' => 'm³',
      'color' => '#2563eb',
      'value' => null,
      'icon' => 'fa-droplet',
    ],
  ],
  'recuperaciones' => [
    'recuperacion_grasa' => [
      'label' => 'Recuperación de grasa',
      'm3' => null,
      'valor' => null,
      'icon' => 'fa-oil-can',
      'unit' => 'm³',
      'ratio_unit' => 'm³/kg',
      'color' => '#64748b',
    ],
    'ollas' => [
      'label' => 'Ollas',
      'm3' => null,
      'valor' => null,
      'icon' => 'fa-fire-burner',
      'unit' => 'm³',
      'ratio_unit' => 'm³/kg',
      'color' => '#a16207',
    ],
    'polimeros' => [
      'label' => 'Polímeros',
      'm3' => null,
      'valor' => null,
      'icon' => 'fa-flask',
      'unit' => 'm³',
      'ratio_unit' => 'm³/kg',
      'color' => '#6b7280',
    ],
  ],
  'generacion' => [
    'panel_solar' => [
      'label' => 'Producción panel solar',
      'kw' => null,
      'valor' => null,
      'icon' => 'fa-solar-panel',
      'unit' => 'kW',
      'ratio_unit' => 'kW/kg',
      'color' => '#15803d',
    ],
    'cogenerador' => [
      'label' => 'Producción cogenerador',
      'kw' => null,
      'valor' => null,
      'icon' => 'fa-industry',
      'unit' => 'kW',
      'ratio_unit' => 'kW/kg',
      'color' => '#0f766e',
    ],
  ],
];
