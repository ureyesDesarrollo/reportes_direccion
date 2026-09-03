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
$ventasPorTipo = [
  'comercial' => ['kilos' => 0.0, 'monto' => 0.0, 'facturas_kilos' => 0.0, 'remisiones_kilos' => 0.0],
  'industrial' => ['kilos' => 0.0, 'monto' => 0.0, 'facturas_kilos' => 0.0, 'remisiones_kilos' => 0.0],
  'sin_clasificar' => ['kilos' => 0.0, 'monto' => 0.0, 'facturas_kilos' => 0.0, 'remisiones_kilos' => 0.0],
];
$dailyRows = [];
$daysInMonth = (int)$periodStart->format('t');
$salesTrafficLights = (array)($config['semaforos_ventas'] ?? []);
$objetivoMensualToneladas = (float)($salesTrafficLights['total']['verde_desde'] ?? 600.0);
$objetivoDiarioToneladas = $daysInMonth > 0 ? $objetivoMensualToneladas / $daysInMonth : 0.0;
$selectedMonthNumber = ($anio * 100) + $mes;
$currentMonthNumber = ((int)$now->format('Y') * 100) + (int)$now->format('n');
$elapsedDays = $selectedMonthNumber < $currentMonthNumber
  ? $daysInMonth
  : ($selectedMonthNumber === $currentMonthNumber ? min($daysInMonth, (int)$now->format('j')) : 0);
$monthProgress = $daysInMonth > 0 ? $elapsedDays / $daysInMonth : 0.0;
$evaluateSalesTrafficLight = static function (float $tons, array $limits) use ($monthProgress, $elapsedDays, $daysInMonth): array {
  $yellowMonthly = max(0.0, (float)($limits['amarillo_desde'] ?? 0.0));
  $greenMonthly = max($yellowMonthly, (float)($limits['verde_desde'] ?? $yellowMonthly));
  $yellowAtDate = $yellowMonthly * $monthProgress;
  $greenAtDate = $greenMonthly * $monthProgress;
  $greenInclusive = !empty($limits['verde_inclusivo']);
  $status = 'sin_dato';
  if ($elapsedDays > 0) {
    if (($greenInclusive && $tons >= $greenAtDate) || (!$greenInclusive && $tons > $greenAtDate)) {
      $status = 'verde';
    } elseif ($tons >= $yellowAtDate) {
      $status = 'amarillo';
    } else {
      $status = 'rojo';
    }
  }

  $statusLabels = ['verde' => 'En objetivo', 'amarillo' => 'En seguimiento', 'rojo' => 'Por debajo', 'sin_dato' => 'Pendiente'];

  return [
    'estado' => $status,
    'estado_label' => $statusLabels[$status] ?? 'Pendiente',
    'clase' => $status === 'sin_dato' ? '' : 'avance-semaforo-' . $status,
    'dias_transcurridos' => $elapsedDays,
    'dias_mes' => $daysInMonth,
    'progreso' => $monthProgress,
    'amarillo_desde' => $yellowAtDate,
    'verde_desde' => $greenAtDate,
    'amarillo_mensual' => $yellowMonthly,
    'verde_mensual' => $greenMonthly,
    'verde_inclusivo' => $greenInclusive,
    'detalle' => $elapsedDays > 0
      ? sprintf('Día %d/%d · Amarillo %.1f–%.1f · Verde %s%.1f TON', $elapsedDays, $daysInMonth, $yellowAtDate, $greenAtDate, $greenInclusive ? '≥ ' : '> ', $greenAtDate)
      : 'Mes futuro: semáforo pendiente',
  ];
};
$pedidosSaldoTotal = 0.0;
$pedidosCantidad = 0;
$backorderPartidas = [];
$backorderPorTipo = [
  'comercial' => ['kilos' => 0.0, 'pedidos' => [], 'partidas' => 0],
  'industrial' => ['kilos' => 0.0, 'pedidos' => [], 'partidas' => 0],
  'sin_clasificar' => ['kilos' => 0.0, 'pedidos' => [], 'partidas' => 0],
];
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

$firstScalarValue = static function (array $row, array $keys, string $fallback = ''): string {
  foreach ($keys as $key) {
    $value = $row[$key] ?? null;
    if (is_scalar($value) && trim((string)$value) !== '') {
      return trim((string)$value);
    }
  }

  return $fallback;
};

$normalizeCustomerName = static function (string $value): string {
  $normalized = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
  $normalized = strtoupper($normalized !== false ? $normalized : $value);
  return trim((string)preg_replace('/[^A-Z0-9]+/', ' ', $normalized));
};

$normalizeSaleType = static function ($value): string {
  $type = strtoupper(trim((string)$value));
  if ($type === 'COMERCIAL') {
    return 'comercial';
  }
  if ($type === 'INDUSTRIAL') {
    return 'industrial';
  }

  return 'sin_clasificar';
};

$pdo105 = null;
$customerTypesByName = [];
$facturasSaiFiltraInclusion = false;
try {
  $pdo105 = $connectMysql((array)($config['mysql_105'] ?? []));
  $facturasSaiFiltraInclusion = (bool)$pdo105
    ->query("SHOW COLUMNS FROM `facturas_sai` LIKE 'incluir_en_ventas'")
    ->fetch();
  foreach ($pdo105->query("
    SELECT cte_id, cte_nombre, cte_razon_social, cte_tipo
    FROM rev_clientes
    WHERE cte_estatus = 'A'
  ")->fetchAll() ?: [] as $customerRow) {
    $type = $normalizeSaleType($customerRow['cte_tipo'] ?? '');
    if ($type === 'sin_clasificar') {
      continue;
    }
    foreach (['cte_nombre', 'cte_razon_social'] as $nameField) {
      $customerKey = $normalizeCustomerName((string)($customerRow[$nameField] ?? ''));
      if ($customerKey !== '') {
        $customerTypesByName[$customerKey] = $type;
      }
    }
  }

  $filtroFacturasSai = $facturasSaiFiltraInclusion ? ' WHERE incluir_en_ventas = 1' : '';
  foreach ($pdo105->query("
    SELECT cliente_nombre, tipo_venta
    FROM (
      SELECT cliente_nombre, tipo_venta, fecha_factura AS fecha FROM facturas_sai{$filtroFacturasSai}
      UNION ALL
      SELECT cliente_nombre, tipo_venta, fecha_remision AS fecha FROM remisiones
    ) ventas_tipo
    WHERE UPPER(TRIM(tipo_venta)) IN ('COMERCIAL', 'INDUSTRIAL')
    ORDER BY fecha
  ")->fetchAll() ?: [] as $saleTypeRow) {
    $customerKey = $normalizeCustomerName((string)($saleTypeRow['cliente_nombre'] ?? ''));
    if ($customerKey !== '') {
      $customerTypesByName[$customerKey] = $normalizeSaleType($saleTypeRow['tipo_venta'] ?? '');
    }
  }
} catch (Throwable $e) {
  $warnings[] = 'No se pudo cargar la clasificación Comercial/Industrial del Back Order.';
}

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
      $pedidoData = is_array($pedidoDetalle['pedido'] ?? null) ? (array)$pedidoDetalle['pedido'] : [];
      $pedidoIdentificador = is_array($pedidoData['identificador'] ?? null)
        ? (array)$pedidoData['identificador']
        : [];
      $clienteRaw = $pedidoData['cliente'] ?? ($pedidoDetalle['cliente'] ?? null);
      $clienteData = is_array($clienteRaw) ? (array)$clienteRaw : [];
      $estatusRaw = $pedidoData['estatus'] ?? ($pedidoDetalle['estatus'] ?? null);
      $estatusData = is_array($estatusRaw) ? (array)$estatusRaw : [];
      $cliente = $firstScalarValue(
        (array)$pedidoDetalle,
        ['cliente_nombre', 'nombre_cliente', 'razon_social', 'cliente'],
        $firstScalarValue(
          $pedidoData,
          ['cliente_nombre', 'nombre_cliente', 'razon_social'],
          $firstScalarValue($clienteData, ['nombre', 'razon_social', 'cliente_nombre'], 'Cliente sin identificar')
        )
      );
      $clienteKey = $normalizeCustomerName($cliente);
      $tipoVentaPedido = $customerTypesByName[$clienteKey]
        ?? 'sin_clasificar';
      $pedidoReferencia = $firstScalarValue(
        (array)$pedidoDetalle,
        ['numero_pedido', 'pedido_folio', 'folio', 'id'],
        $firstScalarValue(
          $pedidoData,
          ['numero_pedido', 'pedido_folio'],
          $firstScalarValue($pedidoIdentificador, ['no_ped', 'numero_pedido', 'id'], $firstScalarValue($pedidoData, ['folio', 'id'], '-'))
        )
      );
      $pedidoEstatus = $firstScalarValue(
        (array)$pedidoDetalle,
        ['estatus', 'status', 'estatus_pedido'],
        $firstScalarValue(
          $pedidoData,
          ['status', 'estatus_pedido'],
          $firstScalarValue($estatusData, ['nombre', 'valor_alphaerp', 'codigo'], 'Sin estatus')
        )
      );
      $partidas = (array)($pedidoDetalle['partidas'] ?? []);
      foreach ($partidas as $partidaIndex => $partida) {
        $clave = strtoupper(trim((string)($partida['clave_producto'] ?? '')));
        $cantidadSolicitada = $partida['cantidad'] ?? ($partida['peso_total'] ?? ($partida['saldo_pendiente'] ?? 0));
        $cantidadPendiente = $partida['saldo_pendiente'] ?? $cantidadSolicitada;
        $calidad = isset($calidadIndex[$clave])
          ? (string)$calidadIndex[$clave]
          : $firstScalarValue((array)$partida, ['calidad', 'bloom'], 'Sin clasificar');
        $partidaReferencia = $firstScalarValue(
          (array)$partida,
          ['partida', 'numero_partida', 'renglon', 'partida_id', 'id'],
          (string)($partidaIndex + 1)
        );

        $backorderPartidas[] = [
          'pedido' => $pedidoReferencia,
          'estatus' => $pedidoEstatus,
          'partida' => $partidaReferencia,
          'cliente' => $cliente,
          'tipo_venta' => $tipoVentaPedido,
          'clave_producto' => $clave !== '' ? $clave : '-',
          'calidad' => $calidad,
          'toneladas_solicitadas' => is_numeric($cantidadSolicitada) ? ((float)$cantidadSolicitada) / 1000 : 0.0,
          'toneladas_pendientes' => is_numeric($cantidadPendiente) ? ((float)$cantidadPendiente) / 1000 : 0.0,
        ];

        if (is_numeric($cantidadPendiente)) {
          $backorderPorTipo[$tipoVentaPedido]['kilos'] += (float)$cantidadPendiente;
          $backorderPorTipo[$tipoVentaPedido]['pedidos'][(string)$pedidoReferencia] = true;
          $backorderPorTipo[$tipoVentaPedido]['partidas']++;
        }

        if ($clave === '' || !isset($calidadIndex[$clave]) || !is_numeric($cantidadPendiente)) {
          continue;
        }

        $grupo = $calidadIndex[$clave];
        $calidadPorProducir[$grupo]['cantidad'] += (float)$cantidadPendiente;
        $calidadPorProducir[$grupo]['toneladas'] += ((float)$cantidadPendiente) / 1000;
        $calidadPorProducir[$grupo]['partidas']++;
        $calidadPorProducir[$grupo]['pedidos']++;
      }
    }
  } catch (Throwable $e) {
    $warnings[] = 'No se pudo consultar el detalle de pedidos: ' . $e->getMessage();
  }
}

usort($backorderPartidas, static function (array $a, array $b): int {
  $clienteOrder = strcasecmp((string)($a['cliente'] ?? ''), (string)($b['cliente'] ?? ''));
  if ($clienteOrder !== 0) {
    return $clienteOrder;
  }

  $pedidoOrder = strnatcasecmp((string)($a['pedido'] ?? ''), (string)($b['pedido'] ?? ''));
  return $pedidoOrder !== 0
    ? $pedidoOrder
    : strnatcasecmp((string)($a['partida'] ?? ''), (string)($b['partida'] ?? ''));
});

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
    'toneladas_pedido' => (float)($row['toneladas'] ?? 0),
    'toneladas_inventario_cliente' => 0.0,
    'toneladas_inventario_libre' => 0.0,
    'toneladas_inventario_aplicado' => 0.0,
    'toneladas_inventario' => 0.0,
    'inventario_detalle' => [],
    'toneladas' => (float)($row['toneladas'] ?? 0),
    'partidas' => (int)($row['partidas'] ?? 0),
    'pedidos' => (int)($row['pedidos'] ?? 0),
  ];
}, array_values($calidadPorProducir));

usort($calidadPorProducir, static function (array $a, array $b): int {
  return (int)($b['orden'] ?? 0) <=> (int)($a['orden'] ?? 0);
});

try {
  $pdo = $pdo105 ?? $connectMysql((array)($config['mysql_105'] ?? []));

  $qualityMap = [];
  foreach ((array)($config['inventario_calidad_bloom'] ?? []) as $quality => $group) {
    $qualityMap[strtoupper(trim((string)$quality))] = (string)$group;
  }
  $specialCustomerMap = array_map('strval', (array)($config['inventario_cliente_bloom'] ?? []));
  $qualityPosition = [];
  $remainingByGroupCustomer = [];
  foreach ($calidadPorProducir as $position => $qualityRow) {
    $group = (string)($qualityRow['grupo'] ?? '');
    $qualityPosition[$group] = $position;
    $remainingByGroupCustomer[$group] = [];
  }
  foreach ($backorderPartidas as $partida) {
    $group = (string)($partida['calidad'] ?? '');
    if (!isset($qualityPosition[$group])) {
      continue;
    }
    $customerKey = $normalizeCustomerName((string)($partida['cliente'] ?? ''));
    $pending = max(0.0, (float)($partida['toneladas_pendientes'] ?? 0));
    $remainingByGroupCustomer[$group][$customerKey] = ($remainingByGroupCustomer[$group][$customerKey] ?? 0.0) + $pending;
  }

  $stockSql = "
    SELECT 'libre' AS tipo, NULL AS cte_id, NULL AS cliente,
      UPPER(TRIM(c.cal_descripcion)) AS calidad,
      SUM(COALESCE(pt.rr_ext_real, 0) * COALESCE(p.pres_kg, 0)) / 1000 AS toneladas
    FROM rev_revolturas_pt pt
    INNER JOIN rev_revolturas r ON r.rev_id = pt.rev_id
    INNER JOIN rev_calidad c ON c.cal_id = r.cal_id
    INNER JOIN rev_presentacion p ON p.pres_id = pt.pres_id
    WHERE pt.rr_ext_real > 0
      AND COALESCE(r.rev_count_etiquetado, 0) > 0
    GROUP BY UPPER(TRIM(c.cal_descripcion))
    UNION ALL
    SELECT 'cliente' AS tipo, pt.cte_id, COALESCE(cl.cte_nombre, cl.cte_razon_social, '') AS cliente,
      UPPER(TRIM(c.cal_descripcion)) AS calidad,
      SUM(COALESCE(pt.rrc_ext_real, 0) * COALESCE(p.pres_kg, 0)) / 1000 AS toneladas
    FROM rev_revolturas_pt_cliente pt
    INNER JOIN rev_revolturas r ON r.rev_id = pt.rev_id
    INNER JOIN rev_calidad c ON c.cal_id = r.cal_id
    INNER JOIN rev_presentacion p ON p.pres_id = pt.pres_id
    LEFT JOIN rev_clientes cl ON cl.cte_id = pt.cte_id
    WHERE pt.rrc_ext_real > 0
      AND COALESCE(r.rev_count_etiquetado, 0) > 0
    GROUP BY pt.cte_id, COALESCE(cl.cte_nombre, cl.cte_razon_social, ''), UPPER(TRIM(c.cal_descripcion))
  ";
  $freeStockByGroup = [];
  $totalStockByGroup = [];
  foreach ($pdo->query($stockSql)->fetchAll() ?: [] as $stockRow) {
    $customerId = isset($stockRow['cte_id']) ? (int)$stockRow['cte_id'] : null;
    $group = $customerId !== null && isset($specialCustomerMap[$customerId])
      ? $specialCustomerMap[$customerId]
      : ($qualityMap[(string)($stockRow['calidad'] ?? '')] ?? '');
    if ($group === '' || !isset($qualityPosition[$group])) {
      continue;
    }
    $stock = max(0.0, (float)($stockRow['toneladas'] ?? 0));
    $totalStockByGroup[$group] = ($totalStockByGroup[$group] ?? 0.0) + $stock;
    if (($stockRow['tipo'] ?? '') === 'libre') {
      $freeStockByGroup[$group] = ($freeStockByGroup[$group] ?? 0.0) + $stock;
      continue;
    }

    $position = $qualityPosition[$group];
    if ($customerId !== null && isset($specialCustomerMap[$customerId])) {
      $availableDemand = array_sum($remainingByGroupCustomer[$group]);
      $applied = min($stock, $availableDemand);
      foreach ($remainingByGroupCustomer[$group] as $customerKey => $customerDemand) {
        if ($applied <= 0) break;
        $discount = min($customerDemand, $applied);
        $remainingByGroupCustomer[$group][$customerKey] -= $discount;
        $applied -= $discount;
      }
      $calidadPorProducir[$position]['toneladas_inventario_cliente'] += min($stock, $availableDemand);
      continue;
    }

    $customerKey = $normalizeCustomerName((string)($stockRow['cliente'] ?? ''));
    $customerDemand = (float)($remainingByGroupCustomer[$group][$customerKey] ?? 0.0);
    $applied = min($stock, $customerDemand);
    $remainingByGroupCustomer[$group][$customerKey] = max(0.0, $customerDemand - $applied);
    $calidadPorProducir[$position]['toneladas_inventario_cliente'] += $applied;
  }

  foreach ($qualityPosition as $group => $position) {
    $remaining = array_sum($remainingByGroupCustomer[$group]);
    $freeApplied = min((float)($freeStockByGroup[$group] ?? 0.0), $remaining);
    $customerApplied = (float)$calidadPorProducir[$position]['toneladas_inventario_cliente'];
    $calidadPorProducir[$position]['toneladas_inventario_libre'] = $freeApplied;
    $calidadPorProducir[$position]['toneladas_inventario_aplicado'] = $customerApplied + $freeApplied;
    $calidadPorProducir[$position]['toneladas_inventario'] = (float)($totalStockByGroup[$group] ?? 0.0);
    $calidadPorProducir[$position]['toneladas'] = max(0.0, (float)$calidadPorProducir[$position]['toneladas_pedido'] - $customerApplied - $freeApplied);
  }

  $stockDetailSql = "
    SELECT 'Sin asignar' AS origen, NULL AS cte_id, NULL AS cliente, r.rev_folio,
      UPPER(TRIM(c.cal_descripcion)) AS calidad, p.pres_descrip AS presentacion,
      p.pres_kg, pt.rr_ext_real AS unidades,
      COALESCE(pt.rr_ext_real, 0) * COALESCE(p.pres_kg, 0) AS kilos
    FROM rev_revolturas_pt pt
    INNER JOIN rev_revolturas r ON r.rev_id = pt.rev_id
    INNER JOIN rev_calidad c ON c.cal_id = r.cal_id
    INNER JOIN rev_presentacion p ON p.pres_id = pt.pres_id
    WHERE pt.rr_ext_real > 0
      AND COALESCE(r.rev_count_etiquetado, 0) > 0
    UNION ALL
    SELECT 'Asignado' AS origen, pt.cte_id, COALESCE(cl.cte_nombre, cl.cte_razon_social, 'Cliente sin identificar') AS cliente,
      r.rev_folio, UPPER(TRIM(c.cal_descripcion)) AS calidad, p.pres_descrip AS presentacion,
      p.pres_kg, pt.rrc_ext_real AS unidades,
      COALESCE(pt.rrc_ext_real, 0) * COALESCE(p.pres_kg, 0) AS kilos
    FROM rev_revolturas_pt_cliente pt
    INNER JOIN rev_revolturas r ON r.rev_id = pt.rev_id
    INNER JOIN rev_calidad c ON c.cal_id = r.cal_id
    INNER JOIN rev_presentacion p ON p.pres_id = pt.pres_id
    LEFT JOIN rev_clientes cl ON cl.cte_id = pt.cte_id
    WHERE pt.rrc_ext_real > 0
      AND COALESCE(r.rev_count_etiquetado, 0) > 0
    ORDER BY calidad, origen, cliente, rev_folio
  ";
  foreach ($pdo->query($stockDetailSql)->fetchAll() ?: [] as $detailRow) {
    $customerId = isset($detailRow['cte_id']) ? (int)$detailRow['cte_id'] : null;
    $group = $customerId !== null && isset($specialCustomerMap[$customerId])
      ? $specialCustomerMap[$customerId]
      : ($qualityMap[(string)($detailRow['calidad'] ?? '')] ?? '');
    if ($group === '' || !isset($qualityPosition[$group])) {
      continue;
    }
    $calidadPorProducir[$qualityPosition[$group]]['inventario_detalle'][] = [
      'folio' => (string)($detailRow['rev_folio'] ?? '-'),
      'origen' => (string)($detailRow['origen'] ?? 'Sin asignar'),
      'cliente_id' => $customerId,
      'cliente' => $customerId !== null ? (string)($detailRow['cliente'] ?? 'Cliente sin identificar') : null,
      'calidad' => (string)($detailRow['calidad'] ?? '-'),
      'presentacion' => (string)($detailRow['presentacion'] ?? '-'),
      'kg_presentacion' => (float)($detailRow['pres_kg'] ?? 0),
      'unidades' => (float)($detailRow['unidades'] ?? 0),
      'kilos' => (float)($detailRow['kilos'] ?? 0),
    ];
  }

  $tables = (array)($config['tablas'] ?? []);
  $facturas = $quoteIdentifier((string)($tables['facturas'] ?? 'facturas_sai'));
  $facturaDetalle = $quoteIdentifier((string)($tables['factura_detalle'] ?? 'factura_sai_detalle'));
  $remisiones = $quoteIdentifier((string)($tables['remisiones'] ?? 'remisiones'));
  $remisionDetalle = $quoteIdentifier((string)($tables['remision_detalle'] ?? 'remision_detalle'));
  $notasCredito = $quoteIdentifier((string)($tables['notas_credito'] ?? 'notas_credito'));
  $filtroFacturaIncluida = $facturasSaiFiltraInclusion ? ' AND incluir_en_ventas = 1' : '';
  $filtroFacturaIncluidaF = $facturasSaiFiltraInclusion ? ' AND f.incluir_en_ventas = 1' : '';
  $whereFacturaIncluidaF = $facturasSaiFiltraInclusion ? ' WHERE f.incluir_en_ventas = 1' : '';

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
      WHERE fecha_factura BETWEEN ? AND ?{$filtroFacturaIncluida}
      GROUP BY DATE_FORMAT(fecha_factura, '%Y-%m-01')
    ),
    facturas_kilos AS (
      SELECT
        DATE_FORMAT(f.fecha_factura, '%Y-%m-01') AS mes,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_fiscal
      FROM {$facturas} f
      INNER JOIN {$facturaDetalle} d ON d.factura_id = f.id
      WHERE f.fecha_factura BETWEEN ? AND ?{$filtroFacturaIncluidaF}
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
      WHERE fecha_factura BETWEEN ? AND ?{$filtroFacturaIncluida}
      GROUP BY fecha_factura
    ),
    facturas_kilos AS (
      SELECT
        f.fecha_factura AS dia,
        SUM(COALESCE(d.cantidad, 0)) AS kilos_fiscal
      FROM {$facturas} f
      INNER JOIN {$facturaDetalle} d ON d.factura_id = f.id
      WHERE f.fecha_factura BETWEEN ? AND ?{$filtroFacturaIncluidaF}
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

  $tipoVentaSql = static fn(string $alias): string => "CASE
    WHEN UPPER(TRIM(COALESCE({$alias}.tipo_venta, ''))) = 'COMERCIAL' THEN 'comercial'
    WHEN UPPER(TRIM(COALESCE({$alias}.tipo_venta, ''))) = 'INDUSTRIAL' THEN 'industrial'
    ELSE 'sin_clasificar'
  END";
  $tipoFactura = $tipoVentaSql('f');
  $tipoRemision = $tipoVentaSql('r');

  $sqlDiarioPorTipo = "
    WITH RECURSIVE dias AS (
      SELECT CAST(? AS DATE) AS dia
      UNION ALL
      SELECT DATE_ADD(dia, INTERVAL 1 DAY)
      FROM dias
      WHERE dia < CAST(? AS DATE)
    ),
    facturas_por_tipo AS (
      SELECT
        f.fecha_factura AS dia,
        {$tipoFactura} AS tipo,
        SUM(COALESCE(fd.kilos, 0)) AS kilos,
        SUM(COALESCE(f.total_real, 0)) AS monto
      FROM {$facturas} f
      LEFT JOIN (
        SELECT factura_id, SUM(COALESCE(cantidad, 0)) AS kilos
        FROM {$facturaDetalle}
        GROUP BY factura_id
      ) fd ON fd.factura_id = f.id
      WHERE f.fecha_factura BETWEEN ? AND ?{$filtroFacturaIncluidaF}
      GROUP BY f.fecha_factura, {$tipoFactura}
    ),
    remisiones_por_tipo AS (
      SELECT
        r.fecha_remision AS dia,
        {$tipoRemision} AS tipo,
        SUM(COALESCE(rd.kilos, 0)) AS kilos,
        SUM(COALESCE(r.total_real, 0)) AS monto
      FROM {$remisiones} r
      LEFT JOIN (
        SELECT remision_id, SUM(COALESCE(cantidad, 0)) AS kilos
        FROM {$remisionDetalle}
        GROUP BY remision_id
      ) rd ON rd.remision_id = r.id
      WHERE r.fecha_remision BETWEEN ? AND ?
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%'
        AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS ARBAIZA%'
      GROUP BY r.fecha_remision, {$tipoRemision}
    ),
    factura_tipos AS (
      SELECT factura, MAX({$tipoFactura}) AS tipo
      FROM {$facturas} f
      {$whereFacturaIncluidaF}
      GROUP BY factura
    ),
    creditos AS (
      SELECT
        nc.fecha AS dia,
        COALESCE(ft.tipo, 'sin_clasificar') AS tipo,
        SUM(COALESCE(nc.total, 0)) AS monto,
        SUM(CASE WHEN nc.tipo = 'DEVOLUCION' THEN COALESCE(nc.cantidad, 0) ELSE 0 END) AS kilos
      FROM {$notasCredito} nc
      LEFT JOIN factura_tipos ft ON ft.factura = nc.factura
      WHERE nc.fecha BETWEEN ? AND ?
      GROUP BY nc.fecha, COALESCE(ft.tipo, 'sin_clasificar')
    )
    SELECT
      d.dia,
      tipo.tipo,
      COALESCE(f.kilos, 0) - COALESCE(c.kilos, 0) AS facturas_kilos,
      COALESCE(r.kilos, 0) AS remisiones_kilos,
      COALESCE(f.monto, 0) - COALESCE(c.monto, 0) + COALESCE(r.monto, 0) AS monto
    FROM dias d
    CROSS JOIN (
      SELECT 'comercial' AS tipo
      UNION ALL SELECT 'industrial'
      UNION ALL SELECT 'sin_clasificar'
    ) tipo
    LEFT JOIN facturas_por_tipo f ON f.dia = d.dia AND f.tipo = tipo.tipo
    LEFT JOIN remisiones_por_tipo r ON r.dia = d.dia AND r.tipo = tipo.tipo
    LEFT JOIN creditos c ON c.dia = d.dia AND c.tipo = tipo.tipo
    ORDER BY d.dia, tipo.tipo
  ";

  $stmtDiarioPorTipo = $pdo->prepare($sqlDiarioPorTipo);
  $stmtDiarioPorTipo->execute([$desde, $hasta, $desde, $hasta, $desde, $hasta, $desde, $hasta]);
  $dailyIndex = [];
  foreach ($dailyRows as $index => $dailyRow) {
    $dailyIndex[(string)($dailyRow['dia'] ?? '')] = $index;
    $dailyRows[$index]['comercial_toneladas'] = 0.0;
    $dailyRows[$index]['industrial_toneladas'] = 0.0;
    $dailyRows[$index]['sin_clasificar_toneladas'] = 0.0;
  }

  foreach ($stmtDiarioPorTipo->fetchAll() ?: [] as $tipoRow) {
    $tipo = (string)($tipoRow['tipo'] ?? 'sin_clasificar');
    if (!isset($ventasPorTipo[$tipo])) {
      $tipo = 'sin_clasificar';
    }
    $facturasKilosTipo = (float)($tipoRow['facturas_kilos'] ?? 0);
    $remisionesKilosTipo = (float)($tipoRow['remisiones_kilos'] ?? 0);
    $kilosTipo = $facturasKilosTipo + $remisionesKilosTipo;
    $ventasPorTipo[$tipo]['facturas_kilos'] += $facturasKilosTipo;
    $ventasPorTipo[$tipo]['remisiones_kilos'] += $remisionesKilosTipo;
    $ventasPorTipo[$tipo]['kilos'] += $kilosTipo;
    $ventasPorTipo[$tipo]['monto'] += (float)($tipoRow['monto'] ?? 0);

    $diaTipo = (string)($tipoRow['dia'] ?? '');
    if (isset($dailyIndex[$diaTipo])) {
      $dailyRows[$dailyIndex[$diaTipo]][$tipo . '_toneladas'] = $kilosTipo / 1000;
    }
  }
} catch (Throwable $e) {
  $warnings[] = 'No se pudo consultar ventas en MySQL 105: ' . $e->getMessage();
}

$kilosTotales = $kilosFacturas + $kilosRemisiones;
$montoVentasTotal = $montoFacturas + $montoRemisiones;
$precioPromedioVenta = $kilosTotales > 0 ? $montoVentasTotal / $kilosTotales : null;
$precioPromedioLimits = (array)($salesTrafficLights['precio_promedio'] ?? []);
$precioAmarilloDesde = (float)($precioPromedioLimits['amarillo_desde'] ?? 110.0);
$precioVerdeArribaDe = max($precioAmarilloDesde, (float)($precioPromedioLimits['verde_arriba_de'] ?? 112.0));
$precioPromedioEstado = 'sin_dato';
if ($precioPromedioVenta !== null) {
  $precioPromedioEstado = $precioPromedioVenta > $precioVerdeArribaDe
    ? 'verde'
    : ($precioPromedioVenta >= $precioAmarilloDesde ? 'amarillo' : 'rojo');
}
$precioPromedioEstadoLabels = [
  'verde' => 'En objetivo',
  'amarillo' => 'En seguimiento',
  'rojo' => 'Por debajo',
  'sin_dato' => 'Sin dato',
];
$semaforoPrecioPromedio = [
  'estado' => $precioPromedioEstado,
  'estado_label' => $precioPromedioEstadoLabels[$precioPromedioEstado] ?? 'Sin dato',
  'clase' => $precioPromedioEstado === 'sin_dato' ? '' : 'avance-semaforo-' . $precioPromedioEstado,
  'amarillo_desde' => $precioAmarilloDesde,
  'verde_arriba_de' => $precioVerdeArribaDe,
  'detalle' => sprintf('Rojo < $%.0f · Amarillo $%.0f–$%.0f · Verde > $%.0f', $precioAmarilloDesde, $precioAmarilloDesde, $precioVerdeArribaDe, $precioVerdeArribaDe),
];
$evaluateAveragePrice = static function (?float $value) use (
  $precioAmarilloDesde,
  $precioVerdeArribaDe,
  $precioPromedioEstadoLabels
): array {
  $estado = 'sin_dato';
  if ($value !== null) {
    $estado = $value > $precioVerdeArribaDe
      ? 'verde'
      : ($value >= $precioAmarilloDesde ? 'amarillo' : 'rojo');
  }
  return [
    'estado' => $estado,
    'estado_label' => $precioPromedioEstadoLabels[$estado] ?? 'Sin dato',
    'clase' => $estado === 'sin_dato' ? '' : 'avance-semaforo-' . $estado,
    'amarillo_desde' => $precioAmarilloDesde,
    'verde_arriba_de' => $precioVerdeArribaDe,
    'detalle' => sprintf('Rojo < $%.0f · Amarillo $%.0f–$%.0f · Verde > $%.0f', $precioAmarilloDesde, $precioAmarilloDesde, $precioVerdeArribaDe, $precioVerdeArribaDe),
  ];
};
$precioPromedioComercial = $ventasPorTipo['comercial']['kilos'] > 0
  ? $ventasPorTipo['comercial']['monto'] / $ventasPorTipo['comercial']['kilos']
  : null;
$precioPromedioIndustrial = $ventasPorTipo['industrial']['kilos'] > 0
  ? $ventasPorTipo['industrial']['monto'] / $ventasPorTipo['industrial']['kilos']
  : null;
$semaforoPrecioPromedioComercial = $evaluateAveragePrice($precioPromedioComercial);
$semaforoPrecioPromedioIndustrial = $evaluateAveragePrice($precioPromedioIndustrial);
$ventasTotalToneladas = $kilosTotales / 1000;
$ventasComercialToneladas = $ventasPorTipo['comercial']['kilos'] / 1000;
$ventasIndustrialToneladas = $ventasPorTipo['industrial']['kilos'] / 1000;
$semaforoVentasTotal = $evaluateSalesTrafficLight($ventasTotalToneladas, (array)($salesTrafficLights['total'] ?? []));
$semaforoVentasComercial = $evaluateSalesTrafficLight($ventasComercialToneladas, (array)($salesTrafficLights['comercial'] ?? []));
$semaforoVentasIndustrial = $evaluateSalesTrafficLight($ventasIndustrialToneladas, (array)($salesTrafficLights['industrial'] ?? []));
$backorderTrafficLightConfig = (array)($config['semaforo_backorder'] ?? []);
$backorderYellowTotal = (float)($backorderTrafficLightConfig['amarillo_desde'] ?? 420.0);
$backorderGreenTotal = max($backorderYellowTotal, (float)($backorderTrafficLightConfig['verde_arriba_de'] ?? 450.0));
$commercialSalesTarget = max(0.0, (float)($salesTrafficLights['comercial']['verde_desde'] ?? 70.0));
$industrialSalesTarget = max(0.0, (float)($salesTrafficLights['industrial']['verde_desde'] ?? 530.0));
$salesTargetsTotal = $commercialSalesTarget + $industrialSalesTarget;
$distributeBackorder = !empty($backorderTrafficLightConfig['distribuir_segun_objetivo_ventas']);
$commercialBackorderShare = $distributeBackorder && $salesTargetsTotal > 0 ? $commercialSalesTarget / $salesTargetsTotal : 1.0;
$industrialBackorderShare = $distributeBackorder && $salesTargetsTotal > 0 ? $industrialSalesTarget / $salesTargetsTotal : 1.0;
$semaforoBackorderTotal = $evaluateSalesTrafficLight($pedidosSaldoTotal / 1000, [
  'amarillo_desde' => $backorderYellowTotal,
  'verde_desde' => $backorderGreenTotal,
  'verde_inclusivo' => false,
]);
$semaforoBackorderComercial = $evaluateSalesTrafficLight($backorderPorTipo['comercial']['kilos'] / 1000, [
  'amarillo_desde' => $backorderYellowTotal * $commercialBackorderShare,
  'verde_desde' => $backorderGreenTotal * $commercialBackorderShare,
  'verde_inclusivo' => false,
]);
$semaforoBackorderIndustrial = $evaluateSalesTrafficLight($backorderPorTipo['industrial']['kilos'] / 1000, [
  'amarillo_desde' => $backorderYellowTotal * $industrialBackorderShare,
  'verde_desde' => $backorderGreenTotal * $industrialBackorderShare,
  'verde_inclusivo' => false,
]);
$esMesActual = $anio === (int)$now->format('Y') && $mes === (int)$now->format('n');
$totalBackOrderVentaKilos = $kilosTotales + ($esMesActual ? $pedidosSaldoTotal : 0.0);
$totalBackOrderVentaToneladas = $totalBackOrderVentaKilos / 1000;

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
    'dias_transcurridos' => $elapsedDays,
    'progreso_mes' => $monthProgress,
    'semaforos' => $salesTrafficLights,
  ],
  'kpis' => [
    'pedidos' => [
      'label' => 'Pedidos por surtir',
      'saldo_total' => $pedidosSaldoTotal,
      'toneladas' => $pedidosSaldoTotal / 1000,
      'cantidad' => $pedidosCantidad,
      'partidas' => $backorderPartidas,
      'semaforo' => $semaforoBackorderTotal,
      'por_tipo' => [
        'comercial' => [
          'label' => 'Comercial',
          'toneladas' => $backorderPorTipo['comercial']['kilos'] / 1000,
          'kilos' => $backorderPorTipo['comercial']['kilos'],
          'cantidad' => count($backorderPorTipo['comercial']['pedidos']),
          'partidas' => $backorderPorTipo['comercial']['partidas'],
          'semaforo' => $semaforoBackorderComercial,
        ],
        'industrial' => [
          'label' => 'Industrial',
          'toneladas' => $backorderPorTipo['industrial']['kilos'] / 1000,
          'kilos' => $backorderPorTipo['industrial']['kilos'],
          'cantidad' => count($backorderPorTipo['industrial']['pedidos']),
          'partidas' => $backorderPorTipo['industrial']['partidas'],
          'semaforo' => $semaforoBackorderIndustrial,
        ],
        'sin_clasificar' => [
          'label' => 'Sin clasificar',
          'toneladas' => $backorderPorTipo['sin_clasificar']['kilos'] / 1000,
          'kilos' => $backorderPorTipo['sin_clasificar']['kilos'],
          'cantidad' => count($backorderPorTipo['sin_clasificar']['pedidos']),
          'partidas' => $backorderPorTipo['sin_clasificar']['partidas'],
        ],
      ],
    ],
    'backorder_venta' => [
      'label' => $esMesActual ? 'Back Order + venta' : 'Venta del periodo',
      'toneladas' => $totalBackOrderVentaToneladas,
      'kilos' => $totalBackOrderVentaKilos,
      'incluye_backorder' => $esMesActual,
      'detalle' => $esMesActual
        ? 'Pedidos pendientes más venta del periodo'
        : 'Mes cerrado: solamente ventas del periodo',
    ],
    'ventas' => [
      'label' => 'Ventas',
      'toneladas' => $kilosTotales / 1000,
      'kilos' => $kilosTotales,
      'facturas_toneladas' => $kilosFacturas / 1000,
      'remisiones_toneladas' => $kilosRemisiones / 1000,
      'monto_facturas' => $montoFacturas,
      'monto_remisiones' => $montoRemisiones,
      'monto_total' => $montoVentasTotal,
      'precio_promedio' => $precioPromedioVenta,
      'precio_promedio_semaforo' => $semaforoPrecioPromedio,
      'registros_facturas' => $registrosFacturas,
      'registros_remisiones' => $registrosRemisiones,
      'semaforo' => $semaforoVentasTotal,
      'por_tipo' => [
        'comercial' => [
          'label' => 'Comercial',
          'toneladas' => $ventasComercialToneladas,
          'kilos' => $ventasPorTipo['comercial']['kilos'],
          'facturas_toneladas' => $ventasPorTipo['comercial']['facturas_kilos'] / 1000,
          'remisiones_toneladas' => $ventasPorTipo['comercial']['remisiones_kilos'] / 1000,
          'monto' => $ventasPorTipo['comercial']['monto'],
          'precio_promedio' => $precioPromedioComercial,
          'precio_promedio_semaforo' => $semaforoPrecioPromedioComercial,
          'semaforo' => $semaforoVentasComercial,
        ],
        'industrial' => [
          'label' => 'Industrial',
          'toneladas' => $ventasIndustrialToneladas,
          'kilos' => $ventasPorTipo['industrial']['kilos'],
          'facturas_toneladas' => $ventasPorTipo['industrial']['facturas_kilos'] / 1000,
          'remisiones_toneladas' => $ventasPorTipo['industrial']['remisiones_kilos'] / 1000,
          'monto' => $ventasPorTipo['industrial']['monto'],
          'precio_promedio' => $precioPromedioIndustrial,
          'precio_promedio_semaforo' => $semaforoPrecioPromedioIndustrial,
          'semaforo' => $semaforoVentasIndustrial,
        ],
        'sin_clasificar' => [
          'label' => 'Sin clasificar',
          'toneladas' => $ventasPorTipo['sin_clasificar']['kilos'] / 1000,
          'kilos' => $ventasPorTipo['sin_clasificar']['kilos'],
          'monto' => $ventasPorTipo['sin_clasificar']['monto'],
        ],
      ],
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
