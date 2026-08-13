<?php

declare(strict_types=1);

$parameterCatalog = require __DIR__ . '/../../config/parameter_catalog.php';
$clarifierRules = (array)($parameterCatalog['produccion_monitoreo']['clarificador']['solidos'] ?? []);
$concentratorRules = (array)($parameterCatalog['concentradores']['solidos_salida'] ?? []);
$membranesRules = (array)($parameterCatalog['produccion_monitoreo']['membranas']['solidos'] ?? []);
$productionRules = (array)($parameterCatalog['produccion'] ?? []);
$flowS1S2Rules = (array)($productionRules['flujos_s1_s2'] ?? []);
$flowS3S4Rules = (array)($productionRules['flujos_s3_s4'] ?? []);
$secadoresSensorConfig = require __DIR__ . '/../secadores-temperatura/config.php';

return [
  'titulo' => 'Avance de Producción Hora por Hora',
  // UTC-6 fijo: evita que PHP 7 aplique el horario de verano histórico de México.
  'timezone' => 'Etc/GMT+6',
  'database_key' => 'prod',
  'database' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'bd_sis_preparacion',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
  ],
  'clarificador_database' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'progel_procesos',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
  ],
  'progel_core_database' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'progel_core',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
  ],
  'sensor_database' => (array)($secadoresSensorConfig['sqlserver'] ?? []),
  'sensor_table' => (string)($secadoresSensorConfig['tabla'] ?? 'TREND001'),
  'sensor_timestamp' => (string)($secadoresSensorConfig['campo_fecha'] ?? 'Time_Stamp'),
  'flujo_sensores' => [
    'flujo_s1' => ['FLUJO_VOTATOR_1_SA', 'FLUJO_VOTATOR_2_SA'],
    'flujo_s2' => ['FLUJO_VOTATOR_1', 'FLUJO_VOTATOR_2'],
    'flujo_s3' => ['FLUJO_VOTATOR_3'],
    'flujo_s4' => ['FLUJO_VOTATOR_4'],
  ],
  'intervalo_actualizacion_ms' => 300000,
  'turnos' => [
    1 => ['inicio' => '07:00', 'fin' => '18:59'],
    2 => ['inicio' => '19:00', 'fin' => '06:59'],
  ],
  'supervisor' => null,
  'metricas' => [
    'solidos_clarificador' => [
      'label' => 'Clarificador',
      'unit' => '%',
      'source' => 'mysql_105',
      'value' => null,
      'semaforo' => $clarifierRules,
    ],
    'solidos_membranas' => [
      'label' => 'Membranas',
      'unit' => '%',
      'source' => 'mysql_105',
      'value' => null,
      'semaforo' => $membranesRules,
    ],
    'solidos_concentradores' => [
      'label' => 'Concentradores',
      'unit' => '%',
      'source' => 'mysql_105',
      'value' => null,
      'semaforo' => $concentratorRules,
    ],
    'flujo_s1' => [
      'label' => 'S1',
      'unit' => '',
      'source' => 'sqlserver',
      'value' => null,
      'semaforo' => $flowS1S2Rules,
    ],
    'flujo_s2' => [
      'label' => 'S2',
      'unit' => '',
      'source' => 'sqlserver',
      'value' => null,
      'semaforo' => $flowS1S2Rules,
    ],
    'flujo_s3' => [
      'label' => 'S3',
      'unit' => '',
      'source' => 'sqlserver',
      'value' => null,
      'semaforo' => $flowS3S4Rules,
    ],
    'flujo_s4' => [
      'label' => 'S4',
      'unit' => '',
      'source' => 'sqlserver',
      'value' => null,
      'semaforo' => $flowS3S4Rules,
    ],
    'flujo_total' => [
      'label' => 'Total',
      'unit' => '',
      'source' => 'sqlserver',
      'value' => null,
      'amarillo_min' => null,
      'verde_min' => null,
    ],
    'kg_hora' => [
      'label' => 'Kg / hr',
      'unit' => 'kg/hr',
      'source' => 'mysql_105',
      'value' => null,
      'semaforo' => (array)($productionRules['kg_hora'] ?? []),
    ],
    'acumulado' => [
      'label' => 'Acumulado',
      'unit' => 'kg',
      'source' => 'mysql_105',
      'value' => null,
      'amarillo_min' => null,
      'verde_min' => null,
    ],
    'tarimas' => [
      'label' => 'Tarimas',
      'unit' => '',
      'source' => 'mysql_105',
      'value' => null,
      'amarillo_min' => (float)($productionRules['tarimas_amarillo_min_turno'] ?? 11),
      'verde_min' => (float)($productionRules['objetivo_turno_tarimas'] ?? 12),
    ],
  ],
];
