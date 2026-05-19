<?php

$bioTimeConfig = require __DIR__ . '/../biotime-administrativos/config.php';

return [
  'titulo' => 'Hikvision / Administrativos',
  'base_url' => 'https://192.168.3.139',
  'endpoint' => '/ISAPI/AccessControl/AcsEvent?format=json',
  'username' => 'admin',
  'password' => 'Progel2026!',
  'timezone' => 'America/Mazatlan',
  'verify_ssl' => false,
  'curl_timeout' => 60,
  'page_size' => 200,
  'cache_ttl_segundos' => 300,
  'cache_dir' => __DIR__ . '/cache',

  // Alternativa si minor=75 no devuelve registros:
  // 'fallback_cond' => ['major' => 5, 'minor' => 0],
  'major' => 5,
  'minor' => 75,

  'hora_entrada' => (string)($bioTimeConfig['hora_entrada'] ?? '08:00:00'),
  'hora_salida' => (string)($bioTimeConfig['hora_salida'] ?? '17:30:00'),
  'tolerancia_retardo_min' => (int)($bioTimeConfig['tolerancia_retardo_min'] ?? 10),
  'minutos_comida' => (int)($bioTimeConfig['minutos_comida'] ?? 60),
  'calcular_horas_extra' => (bool)($bioTimeConfig['calcular_horas_extra'] ?? true),
  'horarios' => (array)($bioTimeConfig['horarios'] ?? []),
  'intervalo_actualizacion_ms' => (int)($bioTimeConfig['intervalo_actualizacion_ms'] ?? 1800000),
  'filas_por_pagina' => (int)($bioTimeConfig['filas_por_pagina'] ?? 15),
  'empleados_permitidos' => (array)($bioTimeConfig['empleados_permitidos'] ?? []),
  'dias_festivos' => (array)($bioTimeConfig['dias_festivos'] ?? []),
];
