<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$databaseConfig = require __DIR__ . '/../../config/database.php';

$detailReport = (static function (): array {
  return require __DIR__ . '/../secadores-temperatura/build_report.php';
})();

$titulo = (string)($config['titulo'] ?? 'Secadores');
$intervaloActualizacion = (int)($config['intervalo_actualizacion_ms'] ?? 1800000);
$detalleUrlBase = (string)($config['detalle_url_base'] ?? '../secadores-temperatura/index.php');
$tunelesConfig = (array)($config['tuneles'] ?? []);

$statePalette = [
  'verde' => ['label' => 'Bien', 'color' => '#10b981', 'bg' => 'rgba(16, 185, 129, 0.10)'],
  'amarillo' => ['label' => 'Atencion', 'color' => '#f59e0b', 'bg' => 'rgba(245, 158, 11, 0.10)'],
  'rojo' => ['label' => 'Critico', 'color' => '#ef4444', 'bg' => 'rgba(239, 68, 68, 0.10)'],
  'gris' => ['label' => 'Sin dato', 'color' => '#94a3b8', 'bg' => 'rgba(148, 163, 184, 0.12)'],
];

$formatPct = static function (?float $value): string {
  if ($value === null) {
    return '-';
  }

  return number_format($value, 0) . '%';
};

$resolveStatusVisual = static function (string $state) use ($statePalette): array {
  return $statePalette[$state] ?? $statePalette['gris'];
};

$connectMysql = static function (array $cfg): PDO {
  $host = (string)($cfg['host'] ?? 'localhost:3306');
  $dbname = (string)($cfg['dbname'] ?? '');
  $charset = (string)($cfg['charset'] ?? 'utf8mb4');
  $dsn = 'mysql:host=' . explode(':', $host)[0] . ';port=' . (explode(':', $host)[1] ?? '3306') . ';dbname=' . $dbname . ';charset=' . $charset;

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$buildPercentStatus = static function (?float $value, int $criticalCount = 0, int $alertCount = 0) use ($resolveStatusVisual): array {
  if ($value === null) {
    return ['key' => 'gris'] + $resolveStatusVisual('gris');
  }

  if ($criticalCount > 0) {
    return ['key' => 'rojo'] + $resolveStatusVisual('rojo');
  }

  if ($alertCount > 0) {
    return ['key' => 'amarillo'] + $resolveStatusVisual('amarillo');
  }

  return ['key' => 'verde'] + $resolveStatusVisual('verde');
};

$buildMaintenanceSummary = static function (array $items) use ($resolveStatusVisual): array {
  $total = count($items);
  if ($total === 0) {
    return [
      'percent' => null,
      'status' => ['key' => 'gris'] + $resolveStatusVisual('gris'),
    ];
  }

  $green = 0;
  $yellow = 0;
  $red = 0;
  foreach ($items as $item) {
    $state = (string)($item['estado'] ?? 'gris');
    if ($state === 'verde') {
      $green++;
    } elseif ($state === 'amarillo') {
      $yellow++;
    } elseif ($state === 'rojo') {
      $red++;
    }
  }

  $percent = ($green / $total) * 100;
  if ($red > 0) {
    $status = ['key' => 'rojo'] + $resolveStatusVisual('rojo');
  } elseif ($yellow > 0 || $green < $total) {
    $status = ['key' => 'amarillo'] + $resolveStatusVisual('amarillo');
  } else {
    $status = ['key' => 'verde'] + $resolveStatusVisual('verde');
  }

  return [
    'percent' => $percent,
    'status' => $status,
  ];
};

$decorateStatusItems = static function (array $items) use ($resolveStatusVisual): array {
  $decorated = [];
  foreach ($items as $item) {
    $state = (string)($item['estado'] ?? 'gris');
    $visual = $resolveStatusVisual($state);
    $decorated[] = [
      'titulo' => (string)($item['titulo'] ?? 'Parametro'),
      'valor' => (string)($item['valor'] ?? '-'),
      'detalle' => (string)($item['detalle'] ?? ''),
      'estado' => $state,
      'statusLabel' => $visual['label'],
      'statusColor' => $visual['color'],
      'statusBg' => $visual['bg'],
    ];
  }

  return $decorated;
};

$caudalByTunnel = [];
$warnings = array_values((array)($detailReport['meta']['warnings'] ?? []));

try {
  $secadoresDb = (array)($databaseConfig['secadores'] ?? []);
  if (!empty($secadoresDb)) {
    $pdoSecadores = $connectMysql($secadoresDb);
    $stmt = $pdoSecadores->query(
      'SELECT r.tunel_key, r.caudal, r.fecha_hora
       FROM secadores_caudal_registros r
       INNER JOIN (
         SELECT tunel_key, MAX(fecha_hora) AS max_fecha
         FROM secadores_caudal_registros
         GROUP BY tunel_key
       ) latest
         ON latest.tunel_key = r.tunel_key
        AND latest.max_fecha = r.fecha_hora'
    );

    foreach (($stmt->fetchAll() ?: []) as $row) {
      $caudalByTunnel[(string)$row['tunel_key']] = [
        'caudal' => isset($row['caudal']) ? (float)$row['caudal'] : null,
        'fecha_hora' => (string)($row['fecha_hora'] ?? ''),
      ];
    }
  }
} catch (Throwable $e) {
  $warnings[] = 'No fue posible consultar el flujo de aire en bd_secadores: ' . $e->getMessage();
}

$tunelesResumen = [];
$resumenesDetalle = (array)($detailReport['resumenes'] ?? []);
$tablasDetalle = (array)($detailReport['tablas'] ?? []);

foreach ($tunelesConfig as $tunelKey => $tunelConfig) {
  $resumen = (array)($resumenesDetalle[$tunelKey] ?? []);
  $latestRow = (array)(($tablasDetalle[$tunelKey] ?? [])[0] ?? []);
  $latestCells = array_values((array)($latestRow['cells'] ?? []));

  $variables = (int)($resumen['variablesMonitoreadas'] ?? count($latestCells));
  $verdes = (int)($resumen['enVerde'] ?? 0);
  $alertas = (int)($resumen['enAlerta'] ?? 0);
  $criticos = (int)($resumen['enCritico'] ?? 0);
  $operacionPercent = $variables > 0 ? ($verdes / $variables) * 100 : null;
  $operacionStatus = $buildPercentStatus($operacionPercent, $criticos, $alertas);

  $zonas = [];
  foreach ($latestCells as $cell) {
    $state = (string)($cell['statusKey'] ?? 'gris');
    $visual = $resolveStatusVisual($state);
    $zonas[] = [
      'label' => (string)($cell['label'] ?? $cell['field'] ?? 'Variable'),
      'value' => (string)($cell['formatted'] ?? '-'),
      'statusKey' => $state,
      'statusLabel' => (string)($cell['statusLabel'] ?? $visual['label']),
      'statusColor' => $visual['color'],
      'statusBg' => $visual['bg'],
    ];
  }

  $continuousItems = $decorateStatusItems((array)($tunelConfig['parametros_continuos'] ?? []));
  foreach ($continuousItems as $index => $item) {
    $source = (array)(($tunelConfig['parametros_continuos'][$index]['source'] ?? []));
    if (($source['type'] ?? '') === 'mysql_caudal') {
      $sourceTunnelKey = (string)($source['tunel_key'] ?? $tunelKey);
      $caudalInfo = $caudalByTunnel[$sourceTunnelKey] ?? null;
      if ($caudalInfo !== null && isset($caudalInfo['caudal'])) {
        $continuousItems[$index]['valor'] = number_format((float)$caudalInfo['caudal'], 1) . ' m3/h';
        $continuousItems[$index]['detalle'] = 'Ultima captura: ' . ($caudalInfo['fecha_hora'] !== '' ? $caudalInfo['fecha_hora'] : '-');
        $continuousItems[$index]['estado'] = 'verde';
        $continuousItems[$index]['statusLabel'] = 'Dato actual';
        $continuousItems[$index]['statusColor'] = '#3b82f6';
        $continuousItems[$index]['statusBg'] = 'rgba(59, 130, 246, 0.10)';
      }
    }
  }
  $maintenanceItems = $decorateStatusItems((array)($tunelConfig['mantenimientos'] ?? []));

  $maintenanceSummary = $buildMaintenanceSummary($maintenanceItems);

  $tunelesResumen[$tunelKey] = [
    'key' => $tunelKey,
    'titulo' => (string)($tunelConfig['titulo'] ?? $tunelKey),
    'subtitulo' => (string)($tunelConfig['subtitulo'] ?? ''),
    'ultimaLectura' => (string)($resumen['ultimaLectura'] ?? '-'),
    'detalleUrl' => $detalleUrlBase . '?tunel=' . urlencode($tunelKey),
    'operacion' => [
      'percent' => $operacionPercent,
      'formatted' => $formatPct($operacionPercent),
      'statusKey' => $operacionStatus['key'],
      'statusLabel' => $operacionStatus['label'],
      'statusColor' => $operacionStatus['color'],
      'statusBg' => $operacionStatus['bg'],
      'trend' => $variables . ' zonas | ' . $verdes . ' en verde',
    ],
    'mantenimiento' => [
      'percent' => $maintenanceSummary['percent'],
      'formatted' => $formatPct($maintenanceSummary['percent']),
      'statusKey' => $maintenanceSummary['status']['key'],
      'statusLabel' => $maintenanceSummary['status']['label'],
      'statusColor' => $maintenanceSummary['status']['color'],
      'statusBg' => $maintenanceSummary['status']['bg'],
      'trend' => count($maintenanceItems) . ' revisiones configuradas',
    ],
    'parametrosContinuos' => $continuousItems,
    'zonas' => $zonas,
    'mantenimientos' => $maintenanceItems,
  ];
}

$meta = [
  'intervaloActualizacion' => $intervaloActualizacion,
  'warnings' => $warnings,
];

return [
  'titulo' => $titulo,
  'tuneles' => $tunelesResumen,
  'meta' => $meta,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time(),
    @filemtime(__DIR__ . '/../secadores-temperatura/build_report.php') ?: time()
  ),
];
