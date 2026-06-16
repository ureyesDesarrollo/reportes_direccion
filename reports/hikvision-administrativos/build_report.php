<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';

date_default_timezone_set((string)($config['timezone'] ?? 'America/Mazatlan'));

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$endpoint = (string)($config['endpoint'] ?? '/ISAPI/AccessControl/AcsEvent?format=json');
$hikUser = (string)($config['username'] ?? '');
$hikPass = (string)($config['password'] ?? '');
$timezone = (string)($config['timezone'] ?? 'America/Mazatlan');
$horaEntrada = (string)($config['hora_entrada'] ?? '08:00:00');
$horaSalida = (string)($config['hora_salida'] ?? '17:30:00');
$toleranciaRetardoMin = (int)($config['tolerancia_retardo_min'] ?? 10);
$minutosComida = (int)($config['minutos_comida'] ?? 60);
$calcularHorasExtraDespuesDeSalida = (bool)($config['calcular_horas_extra'] ?? true);
$horariosConfig = (array)($config['horarios'] ?? []);
$filasPorPagina = (int)($config['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($config['intervalo_actualizacion_ms'] ?? 1800000);
$empleadosPermitidos = array_values(array_filter(array_map('strval', (array)($config['empleados_permitidos'] ?? [])), 'strlen'));
$diasFestivos = array_values(array_filter(array_map('strval', (array)($config['dias_festivos'] ?? [])), 'strlen'));
$vacacionesConfig = (array)($config['vacaciones'] ?? []);
$verifySsl = (bool)($config['verify_ssl'] ?? false);
$curlTimeout = (int)($config['curl_timeout'] ?? 60);
$pageSize = max(1, (int)($config['page_size'] ?? 30));
$cacheTtlSegundos = max(0, (int)($config['cache_ttl_segundos'] ?? 300));
$cacheDir = (string)($config['cache_dir'] ?? (__DIR__ . '/cache'));
$majorDefault = (int)($config['major'] ?? 5);
$minorDefault = (int)($config['minor'] ?? 75);

$normalizarEmpCode = static function (string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  if (preg_match('/^\d+$/', $value) === 1) {
    $value = ltrim($value, '0');
    return $value === '' ? '0' : $value;
  }

  return $value;
};

$parsearFechaEventoLocal = static function (string $value, DateTimeZone $tz): DateTime {
  $value = trim($value);
  if (preg_match('/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}:\d{2})/', $value, $matches) === 1) {
    return new DateTime($matches[1] . ' ' . $matches[2], $tz);
  }

  return new DateTime($value, $tz);
};

$empleadosPermitidos = array_values(array_unique(array_filter(array_map($normalizarEmpCode, $empleadosPermitidos), 'strlen')));
$diasFestivosMap = array_fill_keys($diasFestivos, true);
$vacaciones = [];
foreach ($vacacionesConfig as $periodo) {
  if (!is_array($periodo)) {
    continue;
  }

  $empCodeVac = $normalizarEmpCode((string)($periodo['emp_code'] ?? ''));
  $inicioVac = trim((string)($periodo['inicio'] ?? ''));
  $finVac = trim((string)($periodo['fin'] ?? ''));
  if ($inicioVac === '' || $finVac === '') {
    continue;
  }

  $vacaciones[] = [
    'emp_code' => $empCodeVac,
    'inicio' => $inicioVac,
    'fin' => $finVac,
    'label' => trim((string)($periodo['label'] ?? 'Vacaciones')),
  ];
}

$fechaInicio = isset($_GET['inicio']) && is_string($_GET['inicio']) && $_GET['inicio'] !== ''
  ? $_GET['inicio']
  : date('Y-m-01');
$fechaFin = isset($_GET['fin']) && is_string($_GET['fin']) && $_GET['fin'] !== ''
  ? $_GET['fin']
  : date('Y-m-d');
$empCodeFiltro = $normalizarEmpCode(trim((string)($_GET['emp_code'] ?? '')));
$detalleEmp = $normalizarEmpCode(trim((string)($_GET['detalle_emp'] ?? '')));

$warnings = [];

$buildAcsEventBody = static function (DateTimeImmutable $inicio, DateTimeImmutable $fin, int $posicion, int $limite, int $major, int $minor): array {
  return [
    'AcsEventCond' => [
      'searchID' => uniqid('reporte_', true),
      'searchResultPosition' => $posicion,
      'maxResults' => $limite,
      'major' => $major,
      'minor' => $minor,
      'startTime' => $inicio->format('Y-m-d\TH:i:sP'),
      'endTime' => $fin->format('Y-m-d\TH:i:sP'),
    ],
  ];
};

$normalizarTextoEvento = static function (?string $value): string {
  $value = strtolower(trim((string)$value));
  if ($value === '') {
    return '';
  }

  $value = str_replace(['-', '_'], ' ', $value);
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  return trim($value);
};

$esEntradaOSalida = static function (array $evento) use ($normalizarTextoEvento): bool {
  $validTexts = [
    'checkin',
    'check in',
    'entrada',
    'in',
    'checkout',
    'check out',
    'salida',
    'out',
  ];

  foreach (['attendanceStatus', 'label', 'eventType'] as $field) {
    if (!isset($evento[$field])) {
      continue;
    }

    $normalized = $normalizarTextoEvento((string)$evento[$field]);
    if ($normalized === '') {
      continue;
    }

    foreach ($validTexts as $validText) {
      if ($normalized === $validText || strpos($normalized, $validText) !== false) {
        return true;
      }
    }
  }

  return (int)($evento['major'] ?? 0) === 5 && (int)($evento['minor'] ?? 0) === 75;
};

$tipoChecada = static function (array $evento) use ($normalizarTextoEvento): string {
  foreach (['attendanceStatus', 'label', 'eventType'] as $field) {
    if (!isset($evento[$field])) {
      continue;
    }

    $normalized = $normalizarTextoEvento((string)$evento[$field]);
    if ($normalized === '') {
      continue;
    }

    if (strpos($normalized, 'out') !== false || strpos($normalized, 'salida') !== false) {
      return 'Salida';
    }

    if (strpos($normalized, 'in') !== false || strpos($normalized, 'entrada') !== false) {
      return 'Entrada';
    }
  }

  if ((int)($evento['major'] ?? 0) === 5 && (int)($evento['minor'] ?? 0) === 75) {
    return 'Checada';
  }

  return 'Checada';
};

$hikvisionRequest = static function (string $url, string $user, string $pass, array $body, bool $verifySsl, int $timeout): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPAUTH => CURLAUTH_DIGEST,
    CURLOPT_USERPWD => $user . ':' . $pass,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_SSL_VERIFYPEER => $verifySsl,
    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
  ]);

  $response = curl_exec($ch);
  if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    throw new Exception('Error cURL: ' . $error);
  }

  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode < 200 || $httpCode >= 300) {
    throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
  }

  $data = json_decode($response, true);
  if (!is_array($data)) {
    throw new Exception('Respuesta no JSON: ' . $response);
  }

  return $data;
};

$ensureCacheDir = static function (string $dir): bool {
  if ($dir === '') {
    return false;
  }

  if (is_dir($dir)) {
    return true;
  }

  return @mkdir($dir, 0777, true);
};

$buildCacheKey = static function (
  string $baseUrl,
  string $endpoint,
  DateTimeImmutable $inicio,
  DateTimeImmutable $fin,
  int $major,
  int $minor,
  array $empleadosPermitidos,
  string $empCodeFiltro,
  string $detalleEmp,
  array $vacaciones,
  array $diasFestivos
): string {
  return sha1(json_encode([
    'base' => $baseUrl,
    'endpoint' => $endpoint,
    'inicio' => $inicio->format(DATE_ATOM),
    'fin' => $fin->format(DATE_ATOM),
    'major' => $major,
    'minor' => $minor,
    'empleados' => array_values($empleadosPermitidos),
    'emp' => $empCodeFiltro,
    'detalle' => $detalleEmp,
    'vacaciones' => $vacaciones,
    'dias_festivos' => array_values($diasFestivos),
    'build_version' => @filemtime(__FILE__) ?: 0,
    'config_version' => @filemtime(__DIR__ . '/config.php') ?: 0,
  ], JSON_UNESCAPED_SLASHES));
};

$loadCachedEventos = static function (string $cacheFile, int $ttl): ?array {
  if ($ttl <= 0 || !is_file($cacheFile)) {
    return null;
  }

  $modified = @filemtime($cacheFile);
  if ($modified === false || ($modified + $ttl) < time()) {
    return null;
  }

  $raw = @file_get_contents($cacheFile);
  if (!is_string($raw) || $raw === '') {
    return null;
  }

  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
};

$saveCachedEventos = static function (string $cacheFile, array $eventos): void {
  @file_put_contents($cacheFile, json_encode($eventos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
};

$obtenerEventosHikvision = static function (
  string $baseUrl,
  string $endpoint,
  string $user,
  string $pass,
  DateTimeImmutable $inicio,
  DateTimeImmutable $fin,
  int $major,
  int $minor,
  int $pageSize,
  bool $verifySsl,
  int $timeout
) use ($hikvisionRequest, $buildAcsEventBody): array {
  $posicion = 0;
  $eventos = [];

  do {
    $body = $buildAcsEventBody($inicio, $fin, $posicion, $pageSize, $major, $minor);
    $data = $hikvisionRequest($baseUrl . $endpoint, $user, $pass, $body, $verifySsl, $timeout);
    $acsEvent = (array)($data['AcsEvent'] ?? []);
    $items = (array)($acsEvent['InfoList'] ?? []);
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $eventos[] = [
        'major' => (int)($item['major'] ?? 0),
        'minor' => (int)($item['minor'] ?? 0),
        'time' => (string)($item['time'] ?? ''),
        'name' => (string)($item['name'] ?? ''),
        'employeeNoString' => (string)($item['employeeNoString'] ?? ''),
        'attendanceStatus' => (string)($item['attendanceStatus'] ?? ''),
        'label' => (string)($item['label'] ?? ''),
        'eventType' => (string)($item['eventType'] ?? ''),
        'pictureURL' => (string)($item['pictureURL'] ?? ''),
        'doorNo' => (string)($item['doorNo'] ?? ''),
        'cardReaderNo' => (string)($item['cardReaderNo'] ?? ''),
        'serialNo' => (string)($item['serialNo'] ?? ''),
      ];
    }

    $numMatches = (int)($acsEvent['numOfMatches'] ?? count($items));
    $status = strtoupper((string)($acsEvent['responseStatusStrg'] ?? 'OK'));
    $posicion += $numMatches;
  } while ($numMatches > 0 && $status === 'MORE');

  return $eventos;
};

$minutosEntre = static function (DateTime $inicio, DateTime $fin): int {
  return max(0, (int)(($fin->getTimestamp() - $inicio->getTimestamp()) / 60));
};

$minutosAHoras = static function (int $minutos): string {
  $horas = floor($minutos / 60);
  $mins = $minutos % 60;
  return sprintf('%02d:%02d', $horas, $mins);
};

$resolverVacacion = static function (string $empCode, string $fecha, array $vacaciones): ?array {
  foreach ($vacaciones as $periodo) {
    $periodoEmp = (string)($periodo['emp_code'] ?? '');
    if ($periodoEmp !== '' && $periodoEmp !== $empCode) {
      continue;
    }

    $inicio = (string)($periodo['inicio'] ?? '');
    $fin = (string)($periodo['fin'] ?? '');
    if ($inicio === '' || $fin === '') {
      continue;
    }

    if ($fecha >= $inicio && $fecha <= $fin) {
      return $periodo;
    }
  }

  return null;
};

$resolverHorario = static function (string $empCode, array $empleado, ?DateTime $fecha = null) use ($horaEntrada, $horaSalida, $minutosComida, $toleranciaRetardoMin, $horariosConfig): array {
  $default = [
    'entrada' => $horaEntrada,
    'salida' => $horaSalida,
    'comida' => $minutosComida,
    'tolerancia' => $toleranciaRetardoMin,
    'ignorar_retardo' => false,
    'origen' => 'Default',
    'tiene_excepciones_por_dia' => false,
  ];

  $reglas = [
    ['bucket' => 'empleados', 'key' => trim($empCode), 'label' => 'Empleado'],
    ['bucket' => 'roles', 'key' => trim((string)($empleado['rol'] ?? '')), 'label' => 'Rol'],
    ['bucket' => 'turnos', 'key' => trim((string)($empleado['turno'] ?? '')), 'label' => 'Turno'],
    ['bucket' => 'departamentos', 'key' => trim((string)($empleado['departamento'] ?? '')), 'label' => 'Departamento'],
  ];

  foreach ($reglas as $regla) {
    $bucket = (array)($horariosConfig[$regla['bucket']] ?? []);
    $key = $regla['key'];
    if ($key === '' || !isset($bucket[$key]) || !is_array($bucket[$key])) {
      continue;
    }

    $resolved = [
      'entrada' => (string)($bucket[$key]['entrada'] ?? $default['entrada']),
      'salida' => (string)($bucket[$key]['salida'] ?? $default['salida']),
      'comida' => (int)($bucket[$key]['comida'] ?? $default['comida']),
      'tolerancia' => (int)($bucket[$key]['tolerancia'] ?? $default['tolerancia']),
      'ignorar_retardo' => (bool)($bucket[$key]['ignorar_retardo'] ?? $default['ignorar_retardo']),
      'origen' => $regla['label'] . ': ' . $key,
      'tiene_excepciones_por_dia' => isset($bucket[$key]['por_dia']) && is_array($bucket[$key]['por_dia']) && !empty($bucket[$key]['por_dia']),
    ];

    if ($fecha instanceof DateTime && isset($bucket[$key]['por_dia']) && is_array($bucket[$key]['por_dia'])) {
      $dayNumber = (int)$fecha->format('N');
      $dayRule = $bucket[$key]['por_dia'][$dayNumber] ?? null;
      if (is_array($dayRule)) {
        $resolved['entrada'] = (string)($dayRule['entrada'] ?? $resolved['entrada']);
        $resolved['salida'] = (string)($dayRule['salida'] ?? $resolved['salida']);
        $resolved['comida'] = (int)($dayRule['comida'] ?? $resolved['comida']);
        $resolved['tolerancia'] = (int)($dayRule['tolerancia'] ?? $resolved['tolerancia']);
        $resolved['ignorar_retardo'] = (bool)($dayRule['ignorar_retardo'] ?? $resolved['ignorar_retardo']);
        $resolved['origen'] .= ' | Día ' . $dayNumber;
      }
    }

    return $resolved;
  }

  return $default;
};

$diasSemanaEs = [
  'Monday' => 'Lunes',
  'Tuesday' => 'Martes',
  'Wednesday' => 'Miercoles',
  'Thursday' => 'Jueves',
  'Friday' => 'Viernes',
  'Saturday' => 'Sabado',
  'Sunday' => 'Domingo',
];

$detalle = [];
$resumen = [];
$detalleEmpleado = null;
$departamentosDisponibles = [];
$warnings = [];

if ($baseUrl === '' || $hikUser === '' || $hikPass === '') {
  $warnings[] = 'Configura base_url, username y password en reports/hikvision-administrativos/config.php.';
} elseif (!function_exists('curl_init')) {
  $warnings[] = 'PHP no tiene habilitada la extensión cURL para consultar Hikvision.';
} else {
  try {
    $tz = new DateTimeZone($timezone);
    $inicio = new DateTimeImmutable($fechaInicio . ' 00:00:00', $tz);
    $fin = new DateTimeImmutable($fechaFin . ' 23:59:59', $tz);

    $eventos = null;
    if ($cacheDir !== '' && $ensureCacheDir($cacheDir)) {
      $cacheKey = $buildCacheKey(
        $baseUrl,
        $endpoint,
        $inicio,
        $fin,
        $majorDefault,
        $minorDefault,
        $empleadosPermitidos,
        $empCodeFiltro,
        $detalleEmp,
        $vacaciones,
        $diasFestivos
      );
      $cacheFile = rtrim($cacheDir, '\\/') . DIRECTORY_SEPARATOR . $cacheKey . '.json';
      $eventos = $loadCachedEventos($cacheFile, $cacheTtlSegundos);
    }

    if (!is_array($eventos)) {
      $eventos = $obtenerEventosHikvision(
        $baseUrl,
        $endpoint,
        $hikUser,
        $hikPass,
        $inicio,
        $fin,
        $majorDefault,
        $minorDefault,
        $pageSize,
        $verifySsl,
        $curlTimeout
      );

      if (isset($cacheFile) && $cacheFile !== '') {
        $saveCachedEventos($cacheFile, $eventos);
      }
    }

    $grupos = [];
    foreach ($eventos as $evento) {
      if (!$esEntradaOSalida($evento)) {
        continue;
      }

      $empCode = $normalizarEmpCode((string)($evento['employeeNoString'] ?? ''));
      if ($empCode === '') {
        continue;
      }
      if (!empty($empleadosPermitidos) && !in_array($empCode, $empleadosPermitidos, true)) {
        continue;
      }
      if ($empCodeFiltro !== '' && $empCode !== $empCodeFiltro) {
        continue;
      }
      if ($detalleEmp !== '' && $empCode !== $detalleEmp && $empCodeFiltro === '') {
        continue;
      }

      $timeValue = trim((string)($evento['time'] ?? ''));
      if ($timeValue === '') {
        continue;
      }

      try {
        $dt = $parsearFechaEventoLocal($timeValue, $tz);
      } catch (Throwable $e) {
        continue;
      }

      $fecha = $dt->format('Y-m-d');
      $key = $fecha . '|' . $empCode;
      if (!isset($grupos[$key])) {
        $grupos[$key] = [
          'fecha' => $fecha,
          'emp_code' => $empCode,
          'nombre' => trim((string)($evento['name'] ?? '')),
          'departamento' => '',
          'rol' => '',
          'turno' => '',
          'eventos' => [],
        ];
      }

      $grupos[$key]['eventos'][] = [
        'datetime' => $dt,
        'hora' => $dt->format('H:i:s'),
        'tipo' => $tipoChecada($evento),
        'pictureURL' => trim((string)($evento['pictureURL'] ?? '')),
        'doorNo' => (string)($evento['doorNo'] ?? ''),
        'cardReaderNo' => (string)($evento['cardReaderNo'] ?? ''),
        'serialNo' => (string)($evento['serialNo'] ?? ''),
        'raw' => $evento,
      ];
    }

    foreach ($grupos as $grupo) {
      usort($grupo['eventos'], static function (array $a, array $b): int {
        return $a['datetime'] <=> $b['datetime'];
      });

      $primero = $grupo['eventos'][0];
      $ultimo = $grupo['eventos'][count($grupo['eventos']) - 1];
      $fecha = $grupo['fecha'];
      $dayNumber = (int)$primero['datetime']->format('N');
      $esFinDeSemana = $dayNumber >= 6;
      $esFestivo = isset($diasFestivosMap[$fecha]);
      $periodoVacacion = $resolverVacacion($grupo['emp_code'], $fecha, $vacaciones);
      $esVacacion = $periodoVacacion !== null;
      $esDiaFlexible = $esFinDeSemana || $esFestivo || $esVacacion;
      $empleadoInfo = [
        'departamento' => '',
        'rol' => '',
        'turno' => '',
      ];
      $fechaHorario = $primero['datetime'];
      if ($grupo['emp_code'] === '9999' && $dayNumber === 4) {
        $horaPrimera = (string)($primero['hora'] ?? '');
        if ($horaPrimera >= '07:30:00' && $horaPrimera <= '08:30:00') {
          $fechaHorario = clone $primero['datetime'];
          $fechaHorario->modify('-2 days');
        }
      }
      $horarioEmpleado = $resolverHorario($grupo['emp_code'], $empleadoInfo, $fechaHorario);

      $dtEntradaProgramada = new DateTime($fecha . ' ' . $horarioEmpleado['entrada'], $tz);
      $dtSalidaProgramada = new DateTime($fecha . ' ' . $horarioEmpleado['salida'], $tz);
      $dtPrimera = $primero['datetime'];
      $dtUltima = $ultimo['datetime'];
      $totalEventos = count($grupo['eventos']);

      $dtEntradaEfectiva = clone $dtEntradaProgramada;
      if (!empty($horarioEmpleado['ignorar_retardo']) && $dtPrimera > $dtEntradaProgramada) {
        $dtEntradaEfectiva = clone $dtPrimera;
      }

      $minutosTrabajadosBrutos = $totalEventos > 1 ? $minutosEntre($dtPrimera, $dtUltima) : 0;
      $minutosTrabajadosNetos = $minutosTrabajadosBrutos;
      $minutosJornada = max(0, $minutosEntre($dtEntradaEfectiva, $dtSalidaProgramada));
      if ($esDiaFlexible) {
        $minutosJornada = 0;
      }
 
      $minutosRetardo = 0;
      $entradaConTolerancia = clone $dtEntradaProgramada;
      $entradaConTolerancia->modify('+' . (int)$horarioEmpleado['tolerancia'] . ' minutes');
      if (!$esDiaFlexible && !$horarioEmpleado['ignorar_retardo'] && $dtPrimera > $entradaConTolerancia) {
        $minutosRetardo = $minutosEntre($entradaConTolerancia, $dtPrimera);
      }
      $tuvoRetardoReal = $minutosRetardo > 0;

      $minutosSalidaAnticipada = 0;
      if (!$esDiaFlexible && $totalEventos > 1 && $dtUltima < $dtSalidaProgramada) {
        $minutosSalidaAnticipada = $minutosEntre($dtUltima, $dtSalidaProgramada);
      }

      $minutosExtra = 0;
      $minutosExtraSalidaTarde = 0;
      if (($esFinDeSemana || $esFestivo) && $totalEventos > 1) {
        $minutosExtra = $minutosTrabajadosNetos;
      } elseif (!$esVacacion) {
        $minutosExtraAntes = 0;
        $minutosExtraDespues = 0;

        if ($dtPrimera < $dtEntradaProgramada) {
          $minutosExtraAntes = $minutosEntre($dtPrimera, $dtEntradaProgramada);
        }

        if ($calcularHorasExtraDespuesDeSalida && $totalEventos > 1 && $dtUltima > $dtSalidaProgramada) {
          $minutosExtraDespues = $minutosEntre($dtSalidaProgramada, $dtUltima);
          $minutosExtraSalidaTarde = $minutosExtraDespues;
        }

        $minutosExtra = $minutosExtraAntes + $minutosExtraDespues;
      }

      $minutosFaltantes = max(0, $minutosJornada - $minutosTrabajadosNetos);
      if ($esDiaFlexible) {
        $minutosFaltantes = 0;
      }
      if (!$esDiaFlexible && $minutosExtraSalidaTarde > 0) {
        $minutosCompensacionSalidaTarde = $minutosExtraSalidaTarde;
        $minutosExtraConsumidos = 0;

        if ($minutosFaltantes > 0) {
          $minutosCompensados = min($minutosFaltantes, $minutosCompensacionSalidaTarde);
          $minutosFaltantes -= $minutosCompensados;
          $minutosCompensacionSalidaTarde -= $minutosCompensados;
          $minutosExtraConsumidos += $minutosCompensados;
        }

        if ($minutosCompensacionSalidaTarde > 0 && $minutosRetardo > 0) {
          $minutosCompensados = min($minutosRetardo, $minutosCompensacionSalidaTarde);
          $minutosCompensacionSalidaTarde -= $minutosCompensados;
          $minutosExtraConsumidos += $minutosCompensados;
        }

        $minutosExtra = max(0, $minutosExtra - $minutosExtraConsumidos);
      }
      $estatus = 'OK';
      $semaforoKey = 'verde';
      $semaforoLabel = 'Completo';
      $semaforoColor = '#10b981';

      if ($totalEventos === 1) {
        $estatus = 'Incompleto';
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Sin segunda checada';
        $semaforoColor = '#ef4444';
      } elseif ($minutosSalidaAnticipada > 0) {
        $estatus = 'Salida antes';
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Salida antes';
        $semaforoColor = '#ef4444';
      } elseif ($tuvoRetardoReal) {
        $estatus = 'Retardo';
        $semaforoKey = 'amarillo';
        $semaforoLabel = 'Retardo';
        $semaforoColor = '#f59e0b';
      }

      $row = [
        'fecha' => $fecha,
        'dia_semana' => $diasSemanaEs[$dtPrimera->format('l')] ?? $dtPrimera->format('l'),
        'emp_code' => $grupo['emp_code'],
        'nombre' => $grupo['nombre'],
        'departamento' => $grupo['departamento'],
        'rol' => $grupo['rol'],
        'turno' => $grupo['turno'],
        'horario_entrada' => $horarioEmpleado['entrada'],
        'horario_salida' => $horarioEmpleado['salida'],
        'horario_origen' => $horarioEmpleado['origen'],
        'es_dia_flexible' => $esDiaFlexible,
        'es_vacacion' => $esVacacion,
        'vacacion_label' => $esVacacion ? (string)($periodoVacacion['label'] ?? 'Vacaciones') : '',
        'primera' => $primero['hora'],
        'ultima' => $totalEventos > 1 ? $ultimo['hora'] : 'Sin segunda checada',
        'primera_tipo' => (string)($primero['tipo'] ?? 'Checada'),
        'ultima_tipo' => $totalEventos > 1 ? (string)($ultimo['tipo'] ?? 'Checada') : '',
        'eventos' => $totalEventos,
        'horas_netas' => $minutosAHoras($minutosTrabajadosNetos),
        'retardo' => $minutosAHoras($minutosRetardo),
        'salida_anticipada' => $minutosAHoras($minutosSalidaAnticipada),
        'horas_extra' => $minutosAHoras($minutosExtra),
        'horas_faltantes' => $minutosAHoras($minutosFaltantes),
        'minutos_trabajados' => $minutosTrabajadosNetos,
        'minutos_retardo' => $minutosRetardo,
        'minutos_salida_anticipada' => $minutosSalidaAnticipada,
        'minutos_extra' => $minutosExtra,
        'minutos_faltantes' => $minutosFaltantes,
        'estatus' => $estatus,
        'semaforo_key' => $semaforoKey,
        'semaforo_label' => $semaforoLabel,
        'semaforo_color' => $semaforoColor,
        'picture_primera' => (string)($primero['pictureURL'] ?? ''),
        'picture_ultima' => $totalEventos > 1 ? (string)($ultimo['pictureURL'] ?? '') : '',
      ];

      $detalle[] = $row;

      $emp = $grupo['emp_code'];
      if (!isset($resumen[$emp])) {
        $resumen[$emp] = [
          'emp_code' => $emp,
          'nombre' => $grupo['nombre'],
          'departamento' => '',
          'rol' => '',
          'turno' => '',
          'horario_entrada' => $horarioEmpleado['entrada'],
          'horario_salida' => $horarioEmpleado['salida'],
          'horario_origen' => $horarioEmpleado['origen'],
          'horario_variable' => (bool)($horarioEmpleado['tiene_excepciones_por_dia'] ?? false),
          'dias' => 0,
          'dias_ok' => 0,
          'dias_retardo' => 0,
          'dias_salida_antes' => 0,
          'dias_incompletos' => 0,
          'minutos_trabajados' => 0,
          'minutos_retardo' => 0,
          'minutos_salida_anticipada' => 0,
          'minutos_extra' => 0,
          'minutos_faltantes' => 0,
          'incidencias' => 0,
          'dias_con_retardo' => 0,
        ];
      }

      $resumen[$emp]['dias']++;
      $resumen[$emp]['minutos_trabajados'] += (int)$row['minutos_trabajados'];
      $resumen[$emp]['minutos_retardo'] += (int)$row['minutos_retardo'];
      $resumen[$emp]['minutos_salida_anticipada'] += (int)$row['minutos_salida_anticipada'];
      $resumen[$emp]['minutos_extra'] += (int)$row['minutos_extra'];
      $resumen[$emp]['minutos_faltantes'] += (int)$row['minutos_faltantes'];
      if ((int)$row['minutos_retardo'] > 0) {
        $resumen[$emp]['dias_con_retardo']++;
      }

      $estatusResumen = (string)$row['estatus'];
      if ($estatusResumen === 'OK') {
        $resumen[$emp]['dias_ok']++;
      } elseif ($estatusResumen === 'Retardo') {
        $resumen[$emp]['dias_retardo']++;
        $resumen[$emp]['incidencias']++;
      } elseif ($estatusResumen === 'Salida antes') {
        $resumen[$emp]['dias_salida_antes']++;
        $resumen[$emp]['incidencias']++;
      } else {
        $resumen[$emp]['dias_incompletos']++;
        $resumen[$emp]['incidencias']++;
      }
    }

    usort($detalle, static function ($a, $b) {
      return [$a['fecha'], $a['nombre'], $a['emp_code']] <=> [$b['fecha'], $b['nombre'], $b['emp_code']];
    });

    $resumen = array_values($resumen);
    usort($resumen, static function ($a, $b) {
      return [$a['nombre'], $a['emp_code']] <=> [$b['nombre'], $b['emp_code']];
    });

    foreach ($resumen as &$r) {
      $minutosExtraResumen = (int)$r['minutos_extra'];
      $minutosFaltantesResumen = (int)$r['minutos_faltantes'];
      if ($minutosExtraResumen > 0 && $minutosFaltantesResumen > 0) {
        $minutosCompensadosResumen = min($minutosExtraResumen, $minutosFaltantesResumen);
        $r['minutos_extra'] = $minutosExtraResumen - $minutosCompensadosResumen;
        $r['minutos_faltantes'] = $minutosFaltantesResumen - $minutosCompensadosResumen;
      }

      $r['horas_trabajadas'] = $minutosAHoras((int)$r['minutos_trabajados']);
      $r['retardo'] = $minutosAHoras((int)$r['minutos_retardo']);
      $r['salida_anticipada'] = $minutosAHoras((int)$r['minutos_salida_anticipada']);
      $r['horas_extra'] = $minutosAHoras((int)$r['minutos_extra']);
      $r['horas_faltantes'] = $minutosAHoras((int)$r['minutos_faltantes']);

      $semaforoKey = 'verde';
      $semaforoLabel = 'Completo';
      $semaforoColor = '#10b981';

      if ((int)($r['dias_incompletos'] ?? 0) > 0) {
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Sin segunda checada';
        $semaforoColor = '#ef4444';
      } elseif ((int)($r['dias_salida_antes'] ?? 0) > 0) {
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Salida antes';
        $semaforoColor = '#ef4444';
      } elseif ((int)($r['dias_retardo'] ?? 0) > 0) {
        $semaforoKey = 'amarillo';
        $semaforoLabel = 'Retardo';
        $semaforoColor = '#f59e0b';
      }

      $r['semaforo_key'] = $semaforoKey;
      $r['semaforo_label'] = $semaforoLabel;
      $r['semaforo_color'] = $semaforoColor;
    }
    unset($r);

    if ($detalleEmp !== '') {
      $resumenSeleccionado = null;
      foreach ($resumen as $r) {
        if ((string)$r['emp_code'] === $detalleEmp) {
          $resumenSeleccionado = $r;
          break;
        }
      }

      if ($resumenSeleccionado !== null) {
        $filasDetalle = [];
        foreach ($detalle as $r) {
          if ((string)$r['emp_code'] === $detalleEmp) {
            $filasDetalle[] = $r;
          }
        }

        $detalleEmpleado = [
          'resumen' => $resumenSeleccionado,
          'filas' => $filasDetalle,
        ];
      }
    }
  } catch (Throwable $e) {
    $warnings[] = $e->getMessage();
  }
}

$meta = [
  'filasPorPagina' => $filasPorPagina,
  'intervaloActualizacion' => $intervaloActualizacion,
  'fechaInicio' => $fechaInicio,
  'fechaFin' => $fechaFin,
  'empCodeFiltro' => $empCodeFiltro,
  'departamentoFiltro' => '',
  'departamentosDisponibles' => $departamentosDisponibles,
  'warnings' => $warnings,
];

return [
  'titulo' => (string)($config['titulo'] ?? 'Hikvision / Administrativos'),
  'resumen' => $resumen,
  'detalle' => $detalle,
  'detalleEmpleado' => $detalleEmpleado,
  'totales' => [
    'empleados' => count($resumen),
    'dias' => count($detalle),
  ],
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
