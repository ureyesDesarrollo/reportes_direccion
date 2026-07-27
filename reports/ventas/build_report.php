<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$quoteIdentifier = static function (string $name): string {
  if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
    throw new InvalidArgumentException('Identificador SQL invalido: ' . $name);
  }

  return '`' . $name . '`';
};

$connectMysql = static function (array $cfg): PDO {
  $host = trim((string)($cfg['host'] ?? ''));
  $port = (int)($cfg['port'] ?? 3306);
  $dbname = trim((string)($cfg['dbname'] ?? ''));
  $charset = trim((string)($cfg['charset'] ?? 'utf8mb4'));
  $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

  return new PDO($dsn, (string)($cfg['user'] ?? ''), (string)($cfg['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
};

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$anio = isset($_GET['anio']) && is_numeric($_GET['anio'])
  ? max(2000, min(2100, (int)$_GET['anio']))
  : (int)$now->format('Y');
$mes = isset($_GET['mes']) && is_numeric($_GET['mes'])
  ? max(1, min(12, (int)$_GET['mes']))
  : (int)$now->format('n');

$periodStart = (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes), $timezone))->setTime(0, 0, 0);
$periodEnd = $periodStart->modify('first day of next month');
$periodEndInclusive = $periodEnd->modify('-1 day');
$warnings = [];
$kilosFacturas = 0.0;
$kilosRemisiones = 0.0;
$montoFacturas = 0.0;
$montoRemisiones = 0.0;
$registrosFacturas = 0;
$registrosRemisiones = 0;
$dailyRows = [];
$daysInMonth = (int)$periodStart->format('t');
$objetivoMensualToneladas = 600.0;
$objetivoDiarioToneladas = $daysInMonth > 0 ? $objetivoMensualToneladas / $daysInMonth : 0.0;
$pedidosSaldoTotal = 0.0;
$pedidosCantidad = 0;
$productosCalidadConfig = (array)($config['productos_calidad'] ?? []);
$calidadPorProducir = [];
$calidadIndex = [];
foreach ($productosCalidadConfig as $producto) {
  $clave = strtoupper(trim((string)($producto['clave'] ?? '')));
  $grupo = trim((string)($producto['grupo'] ?? ($producto['bloom'] ?? '')));
  if ($clave === '' || $grupo === '') {
    continue;
  }

  $orden = is_numeric($producto['orden'] ?? null)
    ? (int)$producto['orden']
    : (is_numeric($grupo) ? (int)$grupo : 0);
  if (!isset($calidadPorProducir[$grupo])) {
    $calidadPorProducir[$grupo] = [
      'grupo' => $grupo,
      'bloom' => is_numeric($grupo) ? (int)$grupo : null,
      'orden' => $orden,
      'claves' => [],
      'cantidad' => 0.0,
      'toneladas' => 0.0,
      'partidas' => 0,
      'pedidos' => 0,
    ];
  }

  $calidadPorProducir[$grupo]['claves'][] = $clave;
  $calidadIndex[$clave] = $grupo;
}
uasort($calidadPorProducir, static fn(array $a, array $b): int => (int)($b['orden'] ?? 0) <=> (int)($a['orden'] ?? 0));

$pedidosApiConfig = (array)($config['pedidos_api'] ?? []);
$pedidosApiUrl = trim((string)($pedidosApiConfig['url'] ?? ''));
$pedidosDetalleApiUrl = trim((string)($pedidosApiConfig['detalle_url'] ?? ''));
$pedidosApiKey = trim((string)($pedidosApiConfig['api_key'] ?? ''));
if ($pedidosApiUrl !== '' && $pedidosApiKey !== '') {
  try {
    $query = http_build_query([
      'desde' => sprintf('%04d-01-01', $anio),
      'hasta' => sprintf('%04d-12-31', $anio),
      'status' => (string)($pedidosApiConfig['status'] ?? 'Por Surtir,Parcial'),
      'status2' => (string)($pedidosApiConfig['status2'] ?? 'Confirmado'),
    ]);
    $context = stream_context_create([
      'http' => [
        'header' => "X-API-Key: {$pedidosApiKey}\r\nAccept: application/json\r\n",
        'timeout' => max(1, (int)($pedidosApiConfig['timeout'] ?? 8)),
      ],
    ]);
    $response = @file_get_contents($pedidosApiUrl . '?' . $query, false, $context);
    if ($response === false) {
      throw new RuntimeException((string)(error_get_last()['message'] ?? 'sin respuesta'));
    }

    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    $pedidos = (array)($payload['data']['pedidos'] ?? []);
    $pedidosCantidad = (int)($payload['data']['cantidad'] ?? count($pedidos));
    foreach ($pedidos as $pedido) {
      $saldo = $pedido['saldo_total'] ?? 0;
      if (is_numeric($saldo)) {
        $pedidosSaldoTotal += (float)$saldo;
      }
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudo consultar pedidos por surtir: ' . $e->getMessage();
  }
}

if ($pedidosDetalleApiUrl !== '' && $pedidosApiKey !== '') {
  try {
    $query = http_build_query([
      'desde' => sprintf('%04d-01-01', $anio),
      'hasta' => sprintf('%04d-12-31', $anio),
      'status' => (string)($pedidosApiConfig['status'] ?? 'Por Surtir,Parcial'),
      'status2' => (string)($pedidosApiConfig['status2'] ?? 'Confirmado'),
    ]);
    $context = stream_context_create([
      'http' => [
        'header' => "X-API-Key: {$pedidosApiKey}\r\nAccept: application/json\r\n",
        'timeout' => max(1, (int)($pedidosApiConfig['timeout'] ?? 8)),
      ],
    ]);
    $response = @file_get_contents($pedidosDetalleApiUrl . '?' . $query, false, $context);
    if ($response === false) {
      throw new RuntimeException((string)(error_get_last()['message'] ?? 'sin respuesta'));
    }

    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    $pedidosDetalle = (array)($payload['data']['pedidos'] ?? []);
    foreach ($pedidosDetalle as $pedidoDetalle) {
      $partidas = (array)($pedidoDetalle['partidas'] ?? []);
      foreach ($partidas as $partida) {
        $clave = strtoupper(trim((string)($partida['clave_producto'] ?? '')));
        if ($clave === '' || !isset($calidadIndex[$clave])) {
          continue;
        }

        $cantidad = $partida['saldo_pendiente'] ?? ($partida['cantidad'] ?? ($partida['peso_total'] ?? 0));
        if (!is_numeric($cantidad)) {
          continue;
        }

        $grupo = $calidadIndex[$clave];
        $calidadPorProducir[$grupo]['cantidad'] += (float)$cantidad;
        $calidadPorProducir[$grupo]['toneladas'] += ((float)$cantidad) / 1000;
        $calidadPorProducir[$grupo]['partidas']++;
        $calidadPorProducir[$grupo]['pedidos']++;
      }
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudo consultar el detalle de pedidos: ' . $e->getMessage();
  }
}

$calidadPorProducir = array_map(static function (array $row): array {
  $claves = array_values(array_unique(array_map('strval', (array)($row['claves'] ?? []))));
  sort($claves, SORT_NATURAL);

  return [
    'grupo' => (string)($row['grupo'] ?? ''),
    'bloom' => $row['bloom'] ?? null,
    'orden' => (int)($row['orden'] ?? 0),
    'claves' => $claves,
    'claves_label' => implode(', ', $claves),
    'cantidad' => (float)($row['cantidad'] ?? 0),
    'toneladas' => (float)($row['toneladas'] ?? 0),
    'partidas' => (int)($row['partidas'] ?? 0),
    'pedidos' => (int)($row['pedidos'] ?? 0),
  ];
}, array_values($calidadPorProducir));

usort($calidadPorProducir, static function (array $a, array $b): int {
  return (int)($b['orden'] ?? 0) <=> (int)($a['orden'] ?? 0);
});

try {
  $pdo = $connectMysql((array)($config['mysql_105'] ?? []));
  $tables = (array)($config['tablas'] ?? []);
  $facturas = $quoteIdentifier((string)($tables['facturas'] ?? 'facturas_sai'));
  $facturaDetalle = $quoteIdentifier((string)($tables['factura_detalle'] ?? 'factura_sai_detalle'));
  $remisiones = $quoteIdentifier((string)($tables['remisiones'] ?? 'remisiones'));
  $remisionDetalle = $quoteIdentifier((string)($tables['remision_detalle'] ?? 'remision_detalle'));
  $notasCredito = $quoteIdentifier((string)($tables['notas_credito'] ?? 'notas_credito'));

  $sql = "
    WITH RECURSIVE meses AS (
      SELECT CAST(DATE_FORMAT(?, '%Y-%m-01') AS DATE) AS mes
      UNION ALL
      SELECT DATE_ADD(mes, INTERVAL 1 MONTH)
      FROM meses
      WHERE mes < CAST(DATE_FORMAT(?, '%Y-%m-01') AS DATE)
    ),
    facturas_totales AS (
      SELECT
        DATE_FORMAT(fecha_factura, '%Y-%m-01') AS mes,
        SUM(COALESCE(total_real, 0)) AS monto_fiscal,
        COUNT(DISTINCT id) AS registros_facturas
      FROM {$facturas}
      WHERE fecha_factura BETWEEN ? AND ?
      GROUP BY DATE_FORMAT(fecha_factura, '%Y-%m-01')
    ),
    facturas_kilos AS (
      SELECT
        DATE_FORMAT(f.fecha_factura, '%Y-%m-01') AS mes,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_fiscal
      FROM {$facturas} f
      INNER JOIN {$facturaDetalle} d ON d.factura_id = f.id
      WHERE f.fecha_factura BETWEEN ? AND ?
      GROUP BY DATE_FORMAT(f.fecha_factura, '%Y-%m-01')
    ),
    remisiones_totales AS (
      SELECT
        DATE_FORMAT(fecha_remision, '%Y-%m-01') AS mes,
        SUM(COALESCE(total_real, 0)) AS monto_remision,
        COUNT(DISTINCT id) AS registros_remisiones
      FROM {$remisiones}
      WHERE fecha_remision BETWEEN ? AND ?
        AND UPPER(cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%'
        AND UPPER(cliente_nombre) NOT LIKE '%LUIS ARBAIZA%'
      GROUP BY DATE_FORMAT(fecha_remision, '%Y-%m-01')
    ),
    remisiones_kilos AS (
      SELECT
        DATE_FORMAT(r.fecha_remision, '%Y-%m-01') AS mes,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_remision
      FROM {$remisiones} r
      INNER JOIN {$remisionDetalle} d ON d.remision_id = r.id
      WHERE r.fecha_remision BETWEEN ? AND ?
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%'
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS ARBAIZA%'
      GROUP BY DATE_FORMAT(r.fecha_remision, '%Y-%m-01')
    ),
    notas_credito_agg AS (
      SELECT
        DATE_FORMAT(fecha, '%Y-%m-01') AS mes,
        SUM(COALESCE(total, 0)) AS total_nc,
        SUM(
          CASE
            WHEN tipo = 'DEVOLUCION' THEN COALESCE(cantidad, 0)
            ELSE 0
          END
        ) AS cantidad_devuelta
      FROM {$notasCredito}
      WHERE fecha BETWEEN ? AND ?
      GROUP BY DATE_FORMAT(fecha, '%Y-%m-01')
    )
    SELECT
      YEAR(m.mes) AS anio,
      MONTH(m.mes) AS mes_num,
      COALESCE(fk.kilos_fiscal, 0) - COALESCE(nc.cantidad_devuelta, 0) AS kilos_fiscal,
      COALESCE(ft.monto_fiscal, 0) - COALESCE(nc.total_nc, 0) AS monto_fiscal,
      COALESCE(rk.kilos_remision, 0) AS kilos_remision,
      COALESCE(rt.monto_remision, 0) AS monto_remision,
      COALESCE(ft.registros_facturas, 0) AS registros_facturas,
      COALESCE(rt.registros_remisiones, 0) AS registros_remisiones,
      (COALESCE(fk.kilos_fiscal, 0) - COALESCE(nc.cantidad_devuelta, 0)) + COALESCE(rk.kilos_remision, 0) AS venta_total_kg,
      (COALESCE(ft.monto_fiscal, 0) - COALESCE(nc.total_nc, 0)) + COALESCE(rt.monto_remision, 0) AS venta_total_monto
    FROM meses m
    LEFT JOIN facturas_totales ft   ON ft.mes = DATE_FORMAT(m.mes, '%Y-%m-01')
    LEFT JOIN facturas_kilos fk     ON fk.mes = DATE_FORMAT(m.mes, '%Y-%m-01')
    LEFT JOIN remisiones_totales rt ON rt.mes = DATE_FORMAT(m.mes, '%Y-%m-01')
    LEFT JOIN remisiones_kilos rk   ON rk.mes = DATE_FORMAT(m.mes, '%Y-%m-01')
    LEFT JOIN notas_credito_agg nc  ON nc.mes = DATE_FORMAT(m.mes, '%Y-%m-01')
    ORDER BY m.mes
  ";

  $desde = $periodStart->format('Y-m-d');
  $hasta = $periodEndInclusive->format('Y-m-d');
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
  ]);
  $row = $stmt->fetch() ?: [];
  $kilosFacturas = (float)($row['kilos_fiscal'] ?? 0);
  $kilosRemisiones = (float)($row['kilos_remision'] ?? 0);
  $montoFacturas = (float)($row['monto_fiscal'] ?? 0);
  $montoRemisiones = (float)($row['monto_remision'] ?? 0);
  $registrosFacturas = (int)($row['registros_facturas'] ?? 0);
  $registrosRemisiones = (int)($row['registros_remisiones'] ?? 0);

  $sqlDiario = "
    WITH RECURSIVE dias AS (
      SELECT CAST(? AS DATE) AS dia
      UNION ALL
      SELECT DATE_ADD(dia, INTERVAL 1 DAY)
      FROM dias
      WHERE dia < CAST(? AS DATE)
    ),
    facturas_totales AS (
      SELECT
        fecha_factura AS dia,
        SUM(COALESCE(total_real, 0)) AS monto_fiscal
      FROM {$facturas}
      WHERE fecha_factura BETWEEN ? AND ?
      GROUP BY fecha_factura
    ),
    facturas_kilos AS (
      SELECT
        f.fecha_factura AS dia,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_fiscal
      FROM {$facturas} f
      INNER JOIN {$facturaDetalle} d ON d.factura_id = f.id
      WHERE f.fecha_factura BETWEEN ? AND ?
      GROUP BY f.fecha_factura
    ),
    remisiones_totales AS (
      SELECT
        fecha_remision AS dia,
        SUM(COALESCE(total_real, 0)) AS monto_remision
      FROM {$remisiones}
      WHERE fecha_remision BETWEEN ? AND ?
        AND UPPER(cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%'
        AND UPPER(cliente_nombre) NOT LIKE '%LUIS ARBAIZA%'
      GROUP BY fecha_remision
    ),
    remisiones_kilos AS (
      SELECT
        r.fecha_remision AS dia,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_remision
      FROM {$remisiones} r
      INNER JOIN {$remisionDetalle} d ON d.remision_id = r.id
      WHERE r.fecha_remision BETWEEN ? AND ?
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%'
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS ARBAIZA%'
      GROUP BY r.fecha_remision
    ),
    notas_credito_agg AS (
      SELECT
        fecha AS dia,
        SUM(COALESCE(total, 0)) AS total_nc,
        SUM(
          CASE
            WHEN tipo = 'DEVOLUCION' THEN COALESCE(cantidad, 0)
            ELSE 0
          END
        ) AS cantidad_devuelta
      FROM {$notasCredito}
      WHERE fecha BETWEEN ? AND ?
      GROUP BY fecha
    )
    SELECT
      d.dia,
      DAY(d.dia) AS dia_num,
      COALESCE(fk.kilos_fiscal, 0) - COALESCE(nc.cantidad_devuelta, 0) AS kilos_fiscal,
      COALESCE(rk.kilos_remision, 0) AS kilos_remision,
      (COALESCE(fk.kilos_fiscal, 0) - COALESCE(nc.cantidad_devuelta, 0)) + COALESCE(rk.kilos_remision, 0) AS venta_total_kg,
      (COALESCE(ft.monto_fiscal, 0) - COALESCE(nc.total_nc, 0)) + COALESCE(rt.monto_remision, 0) AS venta_total_monto
    FROM dias d
    LEFT JOIN facturas_totales ft   ON ft.dia = d.dia
    LEFT JOIN facturas_kilos fk     ON fk.dia = d.dia
    LEFT JOIN remisiones_totales rt ON rt.dia = d.dia
    LEFT JOIN remisiones_kilos rk   ON rk.dia = d.dia
    LEFT JOIN notas_credito_agg nc  ON nc.dia = d.dia
    ORDER BY d.dia
  ";

  $stmtDiario = $pdo->prepare($sqlDiario);
  $stmtDiario->execute([
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
    $desde,
    $hasta,
  ]);

  foreach ($stmtDiario->fetchAll() ?: [] as $dailyRow) {
    $dailyKilos = (float)($dailyRow['venta_total_kg'] ?? 0);
    $dailyRows[] = [
      'dia' => (string)($dailyRow['dia'] ?? ''),
      'day' => (int)($dailyRow['dia_num'] ?? 0),
      'toneladas' => $dailyKilos / 1000,
      'facturas_toneladas' => ((float)($dailyRow['kilos_fiscal'] ?? 0)) / 1000,
      'remisiones_toneladas' => ((float)($dailyRow['kilos_remision'] ?? 0)) / 1000,
      'monto' => (float)($dailyRow['venta_total_monto'] ?? 0),
      'objetivo' => $objetivoDiarioToneladas,
    ];
  }
} catch (Throwable $e) {
  $warnings[] = 'No se pudo consultar ventas en MySQL 105: ' . $e->getMessage();
}

$kilosTotales = $kilosFacturas + $kilosRemisiones;
$totalBackOrderVentaToneladas = ($pedidosSaldoTotal + $kilosTotales) / 1000;

return [
  'titulo' => (string)($config['titulo'] ?? 'Ventas'),
  'filtros' => [
    'anio' => $anio,
    'mes' => $mes,
    'desde' => $periodStart->format('Y-m-d'),
    'hasta' => $periodEnd->modify('-1 day')->format('Y-m-d'),
  ],
  'objetivos' => [
    'mensual_toneladas' => $objetivoMensualToneladas,
    'diario_toneladas' => $objetivoDiarioToneladas,
    'dias_mes' => $daysInMonth,
  ],
  'kpis' => [
    'pedidos' => [
      'label' => 'Pedidos por surtir',
      'saldo_total' => $pedidosSaldoTotal,
      'toneladas' => $pedidosSaldoTotal / 1000,
      'cantidad' => $pedidosCantidad,
    ],
    'backorder_venta' => [
      'label' => 'Back order + venta',
      'toneladas' => $totalBackOrderVentaToneladas,
      'kilos' => $pedidosSaldoTotal + $kilosTotales,
    ],
    'ventas' => [
      'label' => 'Ventas',
      'toneladas' => $kilosTotales / 1000,
      'kilos' => $kilosTotales,
      'facturas_toneladas' => $kilosFacturas / 1000,
      'remisiones_toneladas' => $kilosRemisiones / 1000,
      'monto_facturas' => $montoFacturas,
      'monto_remisiones' => $montoRemisiones,
      'registros_facturas' => $registrosFacturas,
      'registros_remisiones' => $registrosRemisiones,
    ],
    'calidad_por_producir' => $calidadPorProducir,
  ],
  'series' => [
    'diaria' => $dailyRows,
  ],
  'meta' => [
    'warnings' => $warnings,
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? 300000),
    'fuente' => 'bd_sis_preparacion',
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
