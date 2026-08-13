<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Conexiones y configuración base
|--------------------------------------------------------------------------
*/

$conexionMysqlProgelProcesos = [
  'host' => '192.168.1.105',
  'port' => 3306,
  'dbname' => 'progel_procesos',
  'user' => 'user_pro',
  'pass' => 'Pr0g3l2025PR',
  'charset' => 'utf8mb4',
  'timeout' => 3,
];

$configSecadoresBase = require __DIR__ . '/../secadores/config.php';
$parameterCatalog = require __DIR__ . '/../../config/parameter_catalog.php';
$masterMonitoringRules = (array)($parameterCatalog['produccion_monitoreo'] ?? []);

$configuracionSqlServerAveva = [
  'conexion' => (array)($configSecadoresBase['sqlserver'] ?? []),
  'tabla' => (string)($configSecadoresBase['tabla'] ?? 'TREND001'),
  'campo_fecha' => (string)($configSecadoresBase['campo_fecha'] ?? 'Time_Stamp'),
];

$ordenDatosHora = ['id_datos_hora', 'id'];

/*
|--------------------------------------------------------------------------
| Helpers de reglas de semáforo
|--------------------------------------------------------------------------
*/

$crearReglaRango = static fn(float $verdeMin, float $verdeMax, float $amarilloMin, float $amarilloMax): array => [
  'modo' => 'rango',
  'verde_min' => $verdeMin,
  'verde_max' => $verdeMax,
  'amarillo_min' => $amarilloMin,
  'amarillo_max' => $amarilloMax,
];

$crearReglaMinimo = static fn(float $verdeMin): array => [
  'modo' => 'minimo',
  'verde_min' => $verdeMin,
];

$crearReglaMaximo = static fn(float $verdeMax, ?float $amarilloMax = null): array => array_filter([
  'modo' => 'maximo',
  'verde_max' => $verdeMax,
  'amarillo_max' => $amarilloMax,
], static fn($value): bool => $value !== null);

/*
|--------------------------------------------------------------------------
| Helpers de métricas
|--------------------------------------------------------------------------
*/

$crearMetrica = static function (string $label, string $field, string $unit = '', array $extra = []): array {
  return array_replace([
    'label' => $label,
    'field' => $field,
    'unit' => $unit,
  ], $extra);
};

$crearSensor = static function (string $label, string $field, string $unit = '', array $extra = []) use ($crearMetrica): array {
  return $crearMetrica($label, $field, $unit, array_replace([
    'source' => 'sqlserver',
    'available' => true,
    'empty_label' => 'Sin dato',
  ], $extra));
};

$crearCampoBitacora = static fn(string $label, string $field, string $unit = '', array $extra = []): array => $crearMetrica($label, $field, $unit, $extra);

$crearCampoBase = static fn(string $label, string $unit = '', array $extra = []): array => array_replace([
  'label' => $label,
  'unit' => $unit,
  'optimo_label' => 'Por definir',
], $extra);

/*
|--------------------------------------------------------------------------
| Secadores y votators
|--------------------------------------------------------------------------
*/

$masterVotatorRules = (array)($masterMonitoringRules['votators'] ?? []);
$reglaFlujoVotator = (array)($masterVotatorRules['flujo'] ?? []);
$reglaSolidosVotator = (array)($masterVotatorRules['solidos'] ?? []);

$camposBaseVotator = [
  'flujo' => $crearCampoBase('Flujo'),
  'presion_cuajado' => $crearCampoBase('Presión de cuajado', 'kg/cm2', [
    'rule' => [
      'verde_min' => 24,
      'verde_max' => 26,
    ],
    'optimo_label' => '24-26 kg/cm2',
  ]),
  'solidos' => $crearCampoBase('Sólidos', '%'),
  'amperaje_bomba' => $crearCampoBase('Amp bomba', 'A'),
  'amperaje_reductor' => $crearCampoBase('Amp reductor', 'A'),
];

$crearSensorVotator = static function (string $campoKey, string $sqlField, array $extra = []) use ($crearSensor, $camposBaseVotator): array {
  $base = (array)($camposBaseVotator[$campoKey] ?? []);
  return $crearSensor(
    (string)($base['label'] ?? $campoKey),
    $sqlField,
    (string)($base['unit'] ?? ''),
    array_replace([
      'semaforo' => (array)($base['semaforo'] ?? []),
      'leyenda' => (string)($base['optimo_label'] ?? ''),
    ], $extra)
  );
};

$crearEquipoVotator = static function (string $label, array $campos): array {
  $config = ['campos' => $campos];
  if ($label !== '') {
    $config['label'] = $label;
  }

  return $config;
};

$camposSqlVotator = [
  'tunel_1' => [
    'votator_1' => $crearEquipoVotator('', [
      'amperaje_bomba' => $crearSensorVotator('amperaje_bomba', 'CORRIENTE_DE_EXTRUSOR_V1_SA'),
    ]),
    'votator_2' => $crearEquipoVotator('', [
      'amperaje_bomba' => $crearSensorVotator('amperaje_bomba', 'CORRIENTE_DE_EXTRUSOR'),
    ]),
  ],
  'tunel_2' => [
    'votator_5' => $crearEquipoVotator('Votator 5', [
      'flujo' => $crearSensorVotator('flujo', 'flujo_votator_3', [
        'semaforo' => $reglaFlujoVotator,
        'leyenda' => '11.5-12.5',
      ]),
    ]),
    'votator_6' => $crearEquipoVotator('Votator 6', [
      'flujo' => $crearSensorVotator('flujo', 'FLujo_votator_4', [
        'semaforo' => $reglaFlujoVotator,
        'leyenda' => '11.5-12.5',
      ]),
    ]),
  ],
];

/*
|--------------------------------------------------------------------------
| Extracción
|--------------------------------------------------------------------------
*/

$camposExtraccion = [
  'nivel_grasa' => $crearSensor('Nivel de grasa', 'NIVEL_TANQUE_DE_GRASA_DE_EXTRACCION'),
];

$camposSecadores = [
  'velocidad_banda',
  'caudal_aire',
  'agua_caliente_suministro',
  'agua_caliente_retorno',
  'presion_vapor',
  'humedad_zona_1_inferior',
  'humedad_zona_2_inferior',
  'humedad_zona_3_inferior',
  'humedad_zona_4_inferior',
  'humedad_zona_5_inferior',
  'humedad_zona_6_inferior',
  'humedad_zona_7_inferior',
  'humedad_zona_8_inferior',
  'humedad_zona_9_inferior',
  'humedad_recamara_2',
  'humedad_recamara_4',
  'humedad_recamara_8',
  'humedad_suministro_aire',
];

$camposVotatorBitacora = [
  'solidos' => $crearCampoBitacora('Sólidos', 'churro_solidos', '%', [
    'empty_label' => 'Sin dato',
    'semaforo' => $reglaSolidosVotator,
    'optimo_label' => '> 37 %',
  ]),
];

/*
|--------------------------------------------------------------------------
| Cocedores
|--------------------------------------------------------------------------
*/

$masterCookerRules = (array)($masterMonitoringRules['cocedores'] ?? []);

$configFlujoCocedorChico = [
  'semaforo' => (array)($masterCookerRules['flujo_chicos'] ?? []),
  'leyenda' => '140-155',
];

$configFlujoCocedorGrande = [
  'semaforo' => (array)($masterCookerRules['flujo_grandes'] ?? []),
  'leyenda' => '160-175',
];

$crearCocedor = static function (int $numero, string $flujoField, array $flujoConfig): array {
  return [
    'titulo' => 'Cocedor ' . $numero,
    'flujo_field' => $flujoField,
    'flujo_semaforo' => (array)$flujoConfig['semaforo'],
    'flujo_leyenda' => (string)$flujoConfig['leyenda'],
  ];
};

$camposCocedoresCabecera = [
  'temperatura_entrada' => $crearSensor('Entrada', 'COCEDORES_TEMPERATURA_DE_ENTRADA', '°C', [
    'semaforo' => (array)($masterCookerRules['temperatura_entrada'] ?? []),
    'leyenda' => '60-68',
  ]),
  'temperatura_salida' => $crearSensor('Salida', 'COCEDORES_TEMPERATURA_DE_SALIDA', '°C', [
    'semaforo' => (array)($masterCookerRules['temperatura_salida'] ?? []),
    'leyenda' => '55-63',
  ]),
];

$camposCocedoresBitacora = [
  'ntu' => $crearCampoBitacora('NTU', 'ntu', '', [
    'semaforo' => (array)($masterCookerRules['ntu'] ?? []),
    'leyenda' => '60-600',
  ]),
  'solidos' => $crearCampoBitacora('Sólidos', 'solidos', '%', [
    'semaforo' => (array)($masterCookerRules['solidos'] ?? []),
    'leyenda' => '2.5-3.5',
  ]),
  'ph' => $crearCampoBitacora('pH', 'ph', '', [
    'semaforo' => (array)($masterCookerRules['ph'] ?? []),
    'leyenda' => '3-3.8',
  ]),
];

$equiposCocedores = [
  'cocedor_1' => $crearCocedor(1, 'Flujo_cocedor_1', $configFlujoCocedorChico),
  'cocedor_2' => $crearCocedor(2, 'Flujo_cocedor_2', $configFlujoCocedorChico),
  'cocedor_3' => $crearCocedor(3, 'Flujo_cocedor_3', $configFlujoCocedorChico),
  'cocedor_4' => $crearCocedor(4, 'Flujo_cocedor_4', $configFlujoCocedorChico),
  'cocedor_5' => $crearCocedor(5, 'Flujo_cocedor_5', $configFlujoCocedorChico),
  'cocedor_6' => $crearCocedor(6, 'Flujo_cocedor_6', $configFlujoCocedorGrande),
  'cocedor_7' => $crearCocedor(7, 'Flujo_cocedor_7', $configFlujoCocedorGrande),
  'cocedor_8' => $crearCocedor(8, 'FLUJO_COCEDOR_8', $configFlujoCocedorGrande),
  'cocedor_9' => $crearCocedor(9, 'FLUJO_COCEDOR_9', $configFlujoCocedorGrande),
];

/*
|--------------------------------------------------------------------------
| Clarificador
|--------------------------------------------------------------------------
*/

$masterClarifierRules = (array)($masterMonitoringRules['clarificador'] ?? []);

$camposClarificador = [
  'solidos' => $crearCampoBitacora('Sólidos', 'solidos', '%', [
    'semaforo' => (array)($masterClarifierRules['solidos'] ?? []),
    'leyenda' => '2.5-3.5',
  ]),
  'temperatura' => $crearCampoBitacora('Temperatura', 'temperatura', '°C', [
    'semaforo' => (array)($masterClarifierRules['temperatura'] ?? []),
    'leyenda' => '56-63',
  ]),
  'flujo' => $crearSensor('Flujo', 'FLUJO_TANQUE_DE_BALANCE', '', [
    'semaforo' => (array)($masterClarifierRules['flujo'] ?? []),
    'leyenda' => '900-1300',
  ]),
  'flujo_polimero' => $crearSensor('Flujo Polímero', 'FLUJO_POLIMERO_CLARIFICADOR', '', [
    'semaforo' => (array)($masterClarifierRules['flujo_polimero'] ?? []),
    'leyenda' => '11-13',
  ]),
  'ntu_entrada' => $crearCampoBitacora('NTU Entrada', 'ntu_entrada', '', [
    'semaforo' => (array)($masterClarifierRules['ntu_entrada'] ?? []),
    'leyenda' => '60-600',
  ]),
  'ntu_salida' => $crearCampoBitacora('NTU Salida', 'ntu_salida', '', [
    'semaforo' => (array)($masterClarifierRules['ntu_salida'] ?? []),
    'leyenda' => '5-<10',
  ]),
  'ph_entrada' => $crearCampoBitacora('pH Entrada', 'ph_entrada', '', [
    'semaforo' => (array)($masterClarifierRules['ph_entrada'] ?? []),
    'leyenda' => '3-3.8',
  ]),
  'ph_electrodo' => $crearCampoBitacora('pH Electrodo', 'ph_electrodo', '', [
    'semaforo' => (array)($masterClarifierRules['ph_electrodo'] ?? []),
    'leyenda' => '5.5-6.5',
  ]),
  'ph_salida' => $crearSensor('pH Salida', 'PH_REAL', '', [
    'semaforo' => (array)($masterClarifierRules['ph_salida'] ?? []),
    'leyenda' => '5.5-6.5',
  ]),
  'ce_salida' => $crearCampoBitacora('CE Salida', 'ce_salida', '', [
    'semaforo' => (array)($masterClarifierRules['ce_salida'] ?? []),
    'leyenda' => '<6.5',
  ]),
  'tanq_balance' => $crearSensor('Tanque Balance', 'NIVEL_TANQUE_DE_BALANCE', '', [
    'semaforo' => (array)($masterClarifierRules['tanq_balance'] ?? []),
    'leyenda' => '50-80',
  ]),
];

/*
|--------------------------------------------------------------------------
| Integración
|--------------------------------------------------------------------------
*/

$camposIntegracion = [
  'visc_integracion' => $crearCampoBitacora('VISCOSIDAD', 'integ_viscosidad', '', [
    'semaforo' => $crearReglaMinimo(25.01),
    'leyenda' => '> 25',
  ]),
  'flujo_integracion' => $crearCampoBitacora('FLUJO', 'integ_flujo', '', [
    'semaforo' => $crearReglaRango(2, 5, 1.5, 5.5),
    'leyenda' => '2-5',
  ]),
];

/*
|--------------------------------------------------------------------------
| Configuración final
|--------------------------------------------------------------------------
*/

$configuracionExtraccion = [
  'indicadores' => $camposExtraccion,
];

$configuracionSecadores = [
  'temperaturas_limite' => 0,
  'tuneles_placeholder' => [],
  'metricas' => $camposSecadores,
  'votator_campos' => array_keys($camposBaseVotator),
  'votators_placeholder' => [
    'votator_5' => 'Votator 5',
    'votator_6' => 'Votator 6',
  ],
  'votator_mysql' => [
    'mysql_105' => $conexionMysqlProgelProcesos,
    'tabla_datos' => 'datos_producto',
    'columnas_orden' => $ordenDatosHora,
    'columna_fo' => 'estado_fo_churro',
    'campos' => $camposVotatorBitacora,
  ],
  'votator_campos_overlay' => $camposSqlVotator,
  'votator_campos_extra' => $camposBaseVotator,
];

$configuracionCocedores = [
  'mysql_105' => $conexionMysqlProgelProcesos,
  'tabla_datos' => 'datos_cocedores',
  'columna_numero' => 'numero_cocedor',
  'columna_fo' => 'estado_fo',
  'columnas_orden' => $ordenDatosHora,
  'encabezado' => $camposCocedoresCabecera,
  'metricas_mysql' => $camposCocedoresBitacora,
  'equipos' => $equiposCocedores,
];

$configuracionClarificador = [
  'key' => 'clarificador',
  'titulo' => 'Clarificador',
  'mysql_105' => $conexionMysqlProgelProcesos,
  'tabla_datos' => 'datos_clarificador',
  'columna_fo' => 'estado_fo',
  'columnas_orden' => $ordenDatosHora,
  'sqlserver' => $configuracionSqlServerAveva,
  'metricas' => $camposClarificador,
];

$configuracionIntegracion = [
  'key' => 'integracion',
  'titulo' => 'Integración',
  'mysql_105' => $conexionMysqlProgelProcesos,
  'tabla_datos' => 'datos_filtracion_integracion',
  'columna_fo' => 'estado_fo_integ',
  'columnas_orden' => $ordenDatosHora,
  'metricas' => $camposIntegracion,
];

$productionMonitoringConfig = [
  'titulo' => 'Avance Producción',
  'intervalo_actualizacion_ms' => 60000,
  'sqlserver_aveva' => $configuracionSqlServerAveva,
  'extraccion' => $configuracionExtraccion,
  'secadores' => $configuracionSecadores,
  'cocedores' => $configuracionCocedores,
  'clarificadores' => $configuracionClarificador,
  'integracion' => $configuracionIntegracion,
];

$productionMonitoringConfig['secadores']['votator_campos_extra']['presion_cuajado']['rule'] = (array)($masterVotatorRules['presion_cuajado'] ?? []);
$productionMonitoringConfig['secadores']['votator_mysql']['campos']['solidos']['semaforo'] = (array)($masterVotatorRules['solidos'] ?? []);
foreach ((array)($productionMonitoringConfig['secadores']['votator_campos_overlay'] ?? []) as $tunnelKey => $votators) {
  foreach (array_keys((array)$votators) as $votatorKey) {
    if (isset($productionMonitoringConfig['secadores']['votator_campos_overlay'][$tunnelKey][$votatorKey]['campos']['flujo'])) {
      $productionMonitoringConfig['secadores']['votator_campos_overlay'][$tunnelKey][$votatorKey]['campos']['flujo']['semaforo'] = (array)($masterVotatorRules['flujo'] ?? []);
    }
  }
}

foreach ((array)($productionMonitoringConfig['cocedores']['equipos'] ?? []) as $cookerKey => $cooker) {
  $number = (int)preg_replace('/\D+/', '', (string)$cookerKey);
  $ruleKey = $number >= 6 ? 'flujo_grandes' : 'flujo_chicos';
  $productionMonitoringConfig['cocedores']['equipos'][$cookerKey]['flujo_semaforo'] = (array)($masterCookerRules[$ruleKey] ?? []);
}
foreach (['temperatura_entrada', 'temperatura_salida'] as $metricKey) {
  $productionMonitoringConfig['cocedores']['encabezado'][$metricKey]['semaforo'] = (array)($masterCookerRules[$metricKey] ?? []);
}
foreach (['ntu', 'solidos', 'ph'] as $metricKey) {
  $productionMonitoringConfig['cocedores']['metricas_mysql'][$metricKey]['semaforo'] = (array)($masterCookerRules[$metricKey] ?? []);
}

foreach ((array)($masterMonitoringRules['clarificador'] ?? []) as $metricKey => $rule) {
  if (isset($productionMonitoringConfig['clarificadores']['metricas'][$metricKey])) {
    $productionMonitoringConfig['clarificadores']['metricas'][$metricKey]['semaforo'] = $rule;
  }
}
foreach ((array)($masterMonitoringRules['integracion'] ?? []) as $metricKey => $rule) {
  if (isset($productionMonitoringConfig['integracion']['metricas'][$metricKey])) {
    $productionMonitoringConfig['integracion']['metricas'][$metricKey]['semaforo'] = $rule;
  }
}

return $productionMonitoringConfig;
