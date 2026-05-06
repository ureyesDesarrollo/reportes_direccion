<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';

date_default_timezone_set((string)($config['timezone'] ?? 'America/Mazatlan'));

$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$bioUser = (string)($config['username'] ?? '');
$bioPass = (string)($config['password'] ?? '');
$timezone = (string)($config['timezone'] ?? 'America/Mazatlan');
$horaEntrada = (string)($config['hora_entrada'] ?? '08:00:00');
$horaSalida = (string)($config['hora_salida'] ?? '17:30:00');
$toleranciaRetardoMin = (int)($config['tolerancia_retardo_min'] ?? 10);
$minutosComida = (int)($config['minutos_comida'] ?? 60);
$calcularHorasExtraDespuesDeSalida = (bool)($config['calcular_horas_extra'] ?? true);
$horariosConfig = (array)($config['horarios'] ?? []);
$filasPorPagina = (int)($config['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($config['intervalo_actualizacion_ms'] ?? 1800000);
$departamentosAdministrativos = array_values(array_filter((array)($config['departamentos_administrativos'] ?? []), 'strlen'));
$empleadosPermitidos = array_values(array_filter(array_map('strval', (array)($config['empleados_permitidos'] ?? [])), 'strlen'));
$diasFestivos = array_values(array_filter(array_map('strval', (array)($config['dias_festivos'] ?? [])), 'strlen'));

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

$empleadosPermitidos = array_values(array_unique(array_filter(array_map($normalizarEmpCode, $empleadosPermitidos), 'strlen')));
$diasFestivosMap = array_fill_keys($diasFestivos, true);

$fechaInicio = isset($_GET['inicio']) && is_string($_GET['inicio']) && $_GET['inicio'] !== ''
  ? $_GET['inicio']
  : date('Y-m-01');
$fechaFin = isset($_GET['fin']) && is_string($_GET['fin']) && $_GET['fin'] !== ''
  ? $_GET['fin']
  : date('Y-m-d');
$empCodeFiltro = trim((string)($_GET['emp_code'] ?? ''));
$departamentoFiltro = trim((string)($_GET['departamento'] ?? ''));
$detalleEmp = trim((string)($_GET['detalle_emp'] ?? ''));

$inicioApi = $fechaInicio . ' 00:00:00';
$finApi = $fechaFin . ' 23:59:59';

$warnings = [];

$biotimeRequest = static function ($method, $url, $token = null, $body = null, $authType = 'Token') {
  $ch = curl_init($url);
  $headers = [
    'Content-Type: application/json',
    'Accept: application/json',
  ];

  if ($token) {
    $headers[] = 'Authorization: ' . $authType . ' ' . $token;
  }

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 60,
  ]);

  if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
  }

  $response = curl_exec($ch);
  if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    throw new Exception('Error cURL: ' . $error);
  }

  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $data = json_decode($response, true);
  if ($httpCode < 200 || $httpCode >= 300) {
    throw new Exception('Error HTTP ' . $httpCode . ': ' . $response);
  }
  if (!is_array($data)) {
    throw new Exception('Respuesta no JSON: ' . $response);
  }

  return $data;
};

$obtenerTokenBioTime = static function ($baseUrl, $user, $pass) use ($biotimeRequest) {
  try {
    $data = $biotimeRequest('POST', $baseUrl . '/api-token-auth/', null, [
      'username' => $user,
      'password' => $pass,
    ]);

    if (isset($data['token'])) {
      return ['token' => $data['token'], 'auth_type' => 'Token'];
    }
  } catch (Exception $e) {
  }

  $data = $biotimeRequest('POST', $baseUrl . '/jwt-api-token-auth/', null, [
    'username' => $user,
    'password' => $pass,
  ]);

  if (isset($data['token'])) {
    return ['token' => $data['token'], 'auth_type' => 'JWT'];
  }
  if (isset($data['access'])) {
    return ['token' => $data['access'], 'auth_type' => 'Bearer'];
  }

  throw new Exception('No se encontró token en la respuesta.');
};

$extraerItemsPaginados = static function (array $data): array {
  if (isset($data['results']) && is_array($data['results'])) {
    return $data['results'];
  }
  if (isset($data['data']) && is_array($data['data'])) {
    return $data['data'];
  }
  if (isset($data['items']) && is_array($data['items'])) {
    return $data['items'];
  }
  if (array_is_list($data)) {
    return $data;
  }
  return [];
};

$obtenerTransaccionesBioTime = static function ($baseUrl, $token, $authType, $inicio, $fin, $empCodeFiltro = '') use ($biotimeRequest, $extraerItemsPaginados): array {
  $transacciones = [];
  $page = 1;
  $pageSize = 200;

  do {
    $params = [
      'start_time' => $inicio,
      'end_time' => $fin,
      'page' => $page,
      'page_size' => $pageSize,
    ];

    if ($empCodeFiltro !== '') {
      $params['emp_code'] = $empCodeFiltro;
    }

    $url = $baseUrl . '/iclock/api/transactions/?' . http_build_query($params);
    $data = $biotimeRequest('GET', $url, $token, null, $authType);
    $items = $extraerItemsPaginados($data);

    foreach ($items as $item) {
      $transacciones[] = $item;
    }

    $count = (int)($data['count'] ?? count($transacciones));
    $page++;
  } while (!empty($items) && count($transacciones) < $count && $page <= 1000);

  return $transacciones;
};

$empCodeFiltro = $normalizarEmpCode($empCodeFiltro);
$detalleEmp = $normalizarEmpCode($detalleEmp);
$empCodeConsulta = $empCodeFiltro !== '' ? $empCodeFiltro : $detalleEmp;

$obtenerEmpleadosBioTime = static function ($baseUrl, $token, $authType) use ($biotimeRequest, $extraerItemsPaginados, $normalizarEmpCode): array {
  $empleados = [];
  $page = 1;
  $pageSize = 200;

  do {
    $url = $baseUrl . '/personnel/api/employees/?' . http_build_query([
      'page' => $page,
      'page_size' => $pageSize,
    ]);

    try {
      $data = $biotimeRequest('GET', $url, $token, null, $authType);
    } catch (Exception $e) {
      return [];
    }

    $items = $extraerItemsPaginados($data);
    foreach ($items as $emp) {
      $codigo = $normalizarEmpCode((string)($emp['emp_code'] ?? ''));
      if ($codigo === '') {
        continue;
      }

      $nombre = trim(
        (string)($emp['first_name'] ?? '') . ' ' .
        (string)($emp['last_name'] ?? '')
      );
      if ($nombre === '') {
        $nombre = (string)($emp['nickname'] ?? '');
      }
      if ($nombre === '') {
        $nombre = (string)($emp['name'] ?? '');
      }

      $departamento = '';
      if (isset($emp['department']['dept_name'])) {
        $departamento = (string)$emp['department']['dept_name'];
      } elseif (isset($emp['dept_name'])) {
        $departamento = (string)$emp['dept_name'];
      } elseif (isset($emp['department_name'])) {
        $departamento = (string)$emp['department_name'];
      }

      $rol = '';
      if (isset($emp['position']['position_name'])) {
        $rol = trim((string)$emp['position']['position_name']);
      } elseif (isset($emp['position_name'])) {
        $rol = trim((string)$emp['position_name']);
      } elseif (isset($emp['job_title'])) {
        $rol = trim((string)$emp['job_title']);
      } elseif (isset($emp['title'])) {
        $rol = trim((string)$emp['title']);
      }

      $turno = '';
      if (isset($emp['shift']['shift_name'])) {
        $turno = trim((string)$emp['shift']['shift_name']);
      } elseif (isset($emp['shift_name'])) {
        $turno = trim((string)$emp['shift_name']);
      } elseif (isset($emp['att_group']['group_name'])) {
        $turno = trim((string)$emp['att_group']['group_name']);
      } elseif (isset($emp['att_group_name'])) {
        $turno = trim((string)$emp['att_group_name']);
      }

      $empleados[$codigo] = [
        'nombre' => $nombre,
        'departamento' => $departamento,
        'rol' => $rol,
        'turno' => $turno,
      ];
    }

    $count = (int)($data['count'] ?? count($empleados));
    $page++;
  } while (!empty($items) && count($empleados) < $count && $page <= 1000);

  return $empleados;
};

$minutosEntre = static function (DateTime $inicio, DateTime $fin): int {
  return max(0, (int)(($fin->getTimestamp() - $inicio->getTimestamp()) / 60));
};

$minutosAHoras = static function (int $minutos): string {
  $horas = floor($minutos / 60);
  $mins = $minutos % 60;
  return sprintf('%02d:%02d', $horas, $mins);
};

$valorTransaccion = static function (array $trx, array $keys, string $default = ''): string {
  foreach ($keys as $key) {
    if (isset($trx[$key]) && trim((string)$trx[$key]) !== '') {
      return trim((string)$trx[$key]);
    }
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

$detalle = [];
$resumen = [];
$transacciones = [];
$empleados = [];
$error = null;

if ($baseUrl === '' || $bioUser === '' || $bioPass === '') {
  $warnings[] = 'Configura base_url, username y password en reports/biotime-administrativos/config.php.';
} elseif (!function_exists('curl_init')) {
  $warnings[] = 'PHP no tiene habilitada la extensión cURL para consultar BioTime.';
} else {
  try {
    $tokenInfo = $obtenerTokenBioTime($baseUrl, $bioUser, $bioPass);
    $empleados = $obtenerEmpleadosBioTime($baseUrl, $tokenInfo['token'], $tokenInfo['auth_type']);

    if ($empCodeConsulta !== '') {
      $transacciones = $obtenerTransaccionesBioTime($baseUrl, $tokenInfo['token'], $tokenInfo['auth_type'], $inicioApi, $finApi, $empCodeConsulta);
    } elseif (!empty($empleadosPermitidos)) {
      $transacciones = [];
      foreach ($empleadosPermitidos as $empleadoPermitido) {
        $transacciones = array_merge(
          $transacciones,
          $obtenerTransaccionesBioTime($baseUrl, $tokenInfo['token'], $tokenInfo['auth_type'], $inicioApi, $finApi, $empleadoPermitido)
        );
      }
    } else {
      $transacciones = $obtenerTransaccionesBioTime($baseUrl, $tokenInfo['token'], $tokenInfo['auth_type'], $inicioApi, $finApi, '');
    }

    $tz = new DateTimeZone($timezone);
    $grupos = [];

    foreach ($transacciones as $trx) {
      $empCode = $normalizarEmpCode($valorTransaccion($trx, ['emp_code', 'employee_code', 'employeeNo', 'emp']));
      if ($empCode === '') {
        continue;
      }
      if (!empty($empleadosPermitidos) && !in_array($empCode, $empleadosPermitidos, true)) {
        continue;
      }

      $punchTime = $valorTransaccion($trx, ['punch_time', 'punchTime', 'time', 'att_time']);
      if ($punchTime === '') {
        continue;
      }

      try {
        $dt = new DateTime($punchTime, $tz);
      } catch (Exception $e) {
        continue;
      }

      $fecha = $dt->format('Y-m-d');
      $key = $fecha . '|' . $empCode;

      if (!isset($grupos[$key])) {
        $nombreTrx = $valorTransaccion($trx, ['emp_name', 'employee_name', 'first_name', 'name']);
        $grupos[$key] = [
          'fecha' => $fecha,
          'emp_code' => $empCode,
          'nombre' => $empleados[$empCode]['nombre'] ?? $nombreTrx,
          'departamento' => $empleados[$empCode]['departamento'] ?? '',
          'rol' => $empleados[$empCode]['rol'] ?? '',
          'turno' => $empleados[$empCode]['turno'] ?? '',
          'terminales' => [],
          'eventos' => [],
        ];
      }

      $terminal = $valorTransaccion($trx, ['terminal_alias', 'terminal_sn', 'terminal_name', 'device_name']);
      if ($terminal !== '') {
        $grupos[$key]['terminales'][$terminal] = true;
      }

      $grupos[$key]['eventos'][] = [
        'datetime' => $dt,
        'hora' => $dt->format('H:i:s'),
        'terminal' => $terminal,
      ];
    }

    foreach ($grupos as $grupo) {
      usort($grupo['eventos'], static function ($a, $b) {
        return $a['datetime'] <=> $b['datetime'];
      });

      $departamento = (string)$grupo['departamento'];
      if (!empty($departamentosAdministrativos) && !in_array($departamento, $departamentosAdministrativos, true)) {
        continue;
      }
      if ($departamentoFiltro !== '' && $departamento !== $departamentoFiltro) {
        continue;
      }

      $primero = $grupo['eventos'][0];
      $ultimo = $grupo['eventos'][count($grupo['eventos']) - 1];
      $fecha = $grupo['fecha'];
      $dayNumber = (int)$primero['datetime']->format('N');
      $esFinDeSemana = $dayNumber >= 6;
      $esFestivo = isset($diasFestivosMap[$fecha]);
      $esDiaFlexible = $esFinDeSemana || $esFestivo;
      $empleadoInfo = $empleados[$grupo['emp_code']] ?? [
        'departamento' => $grupo['departamento'],
        'rol' => $grupo['rol'],
        'turno' => $grupo['turno'],
      ];
      $horarioEmpleado = $resolverHorario($grupo['emp_code'], $empleadoInfo, $primero['datetime']);

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
      $minutosTrabajadosNetos = max(0, $minutosTrabajadosBrutos - (int)$horarioEmpleado['comida']);
      $minutosJornada = max(0, $minutosEntre($dtEntradaEfectiva, $dtSalidaProgramada) - (int)$horarioEmpleado['comida']);
      if ($esDiaFlexible) {
        $minutosJornada = 0;
      }

      $minutosRetardo = 0;
      $entradaConTolerancia = clone $dtEntradaProgramada;
      $entradaConTolerancia->modify('+' . (int)$horarioEmpleado['tolerancia'] . ' minutes');
      if (!$esDiaFlexible && !$horarioEmpleado['ignorar_retardo'] && $dtPrimera > $entradaConTolerancia) {
        $minutosRetardo = $minutosEntre($dtEntradaProgramada, $dtPrimera);
      }

      $minutosSalidaAnticipada = 0;
      if (!$esDiaFlexible && $totalEventos > 1 && $dtUltima < $dtSalidaProgramada) {
        $minutosSalidaAnticipada = $minutosEntre($dtUltima, $dtSalidaProgramada);
      }

      $minutosExtra = 0;
      if ($calcularHorasExtraDespuesDeSalida && $totalEventos > 1 && $dtUltima > $dtSalidaProgramada) {
        $minutosExtra = $minutosEntre($dtSalidaProgramada, $dtUltima);
      }

      $minutosFaltantes = max(0, $minutosJornada - $minutosTrabajadosNetos);
      $estatus = 'OK';
      $semaforoKey = 'verde';
      $semaforoLabel = 'Completo';
      $semaforoColor = '#10b981';

      if ($totalEventos === 1) {
        $estatus = 'Incompleto';
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Incompleto';
        $semaforoColor = '#ef4444';
      } elseif ($minutosRetardo > 0 && $minutosSalidaAnticipada > 0) {
        $estatus = 'Salida antes';
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Salida antes';
        $semaforoColor = '#ef4444';
      } elseif ($minutosSalidaAnticipada > 0) {
        $estatus = 'Salida antes';
        $semaforoKey = 'rojo';
        $semaforoLabel = 'Salida antes';
        $semaforoColor = '#ef4444';
      } elseif ($minutosRetardo > 0) {
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
        'primera' => $primero['hora'],
        'ultima' => $totalEventos > 1 ? $ultimo['hora'] : '',
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
      ];

      $detalle[] = $row;

      $emp = $grupo['emp_code'];
      if (!isset($resumen[$emp])) {
        $resumen[$emp] = [
          'emp_code' => $emp,
          'nombre' => $grupo['nombre'],
          'departamento' => $grupo['departamento'],
          'rol' => $grupo['rol'],
          'turno' => $grupo['turno'],
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
      $resumen[$emp]['minutos_trabajados'] += $minutosTrabajadosNetos;
      $resumen[$emp]['minutos_retardo'] += $minutosRetardo;
      $resumen[$emp]['minutos_salida_anticipada'] += $minutosSalidaAnticipada;
      $resumen[$emp]['minutos_extra'] += $minutosExtra;
      $resumen[$emp]['minutos_faltantes'] += $minutosFaltantes;
      if ($minutosRetardo > 0) {
        $resumen[$emp]['dias_con_retardo']++;
      }

      if ($estatus === 'OK') {
        $resumen[$emp]['dias_ok']++;
      } elseif ($estatus === 'Retardo') {
        $resumen[$emp]['dias_retardo']++;
        $resumen[$emp]['incidencias']++;
      } elseif ($estatus === 'Salida antes') {
        $resumen[$emp]['dias_salida_antes']++;
        $resumen[$emp]['incidencias']++;
      } else {
        $resumen[$emp]['dias_incompletos']++;
        $resumen[$emp]['incidencias']++;
      }
    }

    usort($detalle, static function ($a, $b) {
      return [$a['fecha'], $a['departamento'], $a['nombre'], $a['emp_code']]
        <=>
        [$b['fecha'], $b['departamento'], $b['nombre'], $b['emp_code']];
    });

    $resumen = array_values($resumen);
    usort($resumen, static function ($a, $b) {
      return [$a['departamento'], $a['nombre'], $a['emp_code']]
        <=>
        [$b['departamento'], $b['nombre'], $b['emp_code']];
    });

    foreach ($resumen as &$r) {
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
        $semaforoLabel = 'Incompleto';
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
      $r['horario_resumen'] = !empty($r['horario_variable'])
        ? 'Variable'
        : ((string)($r['horario_entrada'] ?? '') . ' - ' . (string)($r['horario_salida'] ?? ''));

      $comportamiento = [];
      $comportamiento[] = (string)((int)($r['dias_ok'] ?? 0)) . ' de ' . (string)((int)($r['dias'] ?? 0)) . ' dias completos';

      if ((int)($r['dias_retardo'] ?? 0) > 0) {
        $comportamiento[] = (string)((int)$r['dias_retardo']) . ' con retardo';
      }
      if ((int)($r['dias_salida_antes'] ?? 0) > 0) {
        $comportamiento[] = (string)((int)$r['dias_salida_antes']) . ' con salida antes';
      }
      if ((int)($r['dias_incompletos'] ?? 0) > 0) {
        $comportamiento[] = (string)((int)$r['dias_incompletos']) . ' incompletos';
      }
      if ((int)($r['dias_retardo'] ?? 0) === 0 && (int)($r['dias_salida_antes'] ?? 0) === 0 && (int)($r['dias_incompletos'] ?? 0) === 0) {
        $comportamiento[] = 'Sin incidencias';
      }
      if ((int)($r['minutos_faltantes'] ?? 0) > 0) {
        $comportamiento[] = 'Faltantes ' . $r['horas_faltantes'];
      }

      $r['comportamiento_resumen'] = implode(' | ', $comportamiento);
    }
    unset($r);
  } catch (Exception $e) {
    $error = $e->getMessage();
    $warnings[] = $error;
  }
}

$departamentosDisponibles = [];
foreach ($resumen as $r) {
  $departamento = (string)($r['departamento'] ?? '');
  if ($departamento !== '') {
    $departamentosDisponibles[$departamento] = $departamento;
  }
}
ksort($departamentosDisponibles);

$totales = [
  'empleados' => count($resumen),
  'dias' => count($detalle),
  'dias_ok' => 0,
  'retardos' => 0,
  'salidas_anticipadas' => 0,
  'horas_faltantes_min' => 0,
];

foreach ($detalle as $row) {
  if (($row['estatus'] ?? '') === 'OK') {
    $totales['dias_ok']++;
  }
  if ((int)($row['minutos_retardo'] ?? 0) > 0) {
    $totales['retardos']++;
  }
  if ((int)($row['minutos_salida_anticipada'] ?? 0) > 0) {
    $totales['salidas_anticipadas']++;
  }
  $totales['horas_faltantes_min'] += (int)($row['minutos_faltantes'] ?? 0);
}

$detalleEmpleado = null;
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

$meta = [
  'filasPorPagina' => $filasPorPagina,
  'intervaloActualizacion' => $intervaloActualizacion,
  'fechaInicio' => $fechaInicio,
  'fechaFin' => $fechaFin,
  'empCodeFiltro' => $empCodeFiltro,
  'departamentoFiltro' => $departamentoFiltro,
  'departamentosDisponibles' => array_values($departamentosDisponibles),
  'warnings' => $warnings,
];

return [
  'titulo' => (string)($config['titulo'] ?? 'BioTime / Administrativos'),
  'resumen' => $resumen,
  'detalle' => $detalle,
  'detalleEmpleado' => $detalleEmpleado,
  'totales' => $totales,
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
