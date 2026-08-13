<?php

$parameterCatalog = require __DIR__ . '/../../config/parameter_catalog.php';
$productionParameters = (array)($parameterCatalog['produccion'] ?? []);

return [
  'titulo' => 'Avance Producción',
  'database_key' => 'prod',
  'timezone' => 'America/Mazatlan',
  'hora_corte' => '07:00:00',
  'objetivo_diario_toneladas' => (float)($productionParameters['objetivo_diario_toneladas'] ?? 24.0),
  'objetivo_mensual_toneladas' => 670.0,
  'produccion_amarillo_min_diario' => (float)($productionParameters['produccion_amarillo_min_diario'] ?? 21.0),
  'produccion_verde_min_diario' => (float)($productionParameters['produccion_verde_min_diario'] ?? 24.0),
  'objetivo_diario_tarimas' => (float)($productionParameters['objetivo_diario_tarimas'] ?? 24.0),
  'tarimas_amarillo_min_diario' => (float)($productionParameters['tarimas_amarillo_min_diario'] ?? 21.0),
  'barredura_pro_id' => 2,
  'intervalo_actualizacion_ms' => 1800000,
];
