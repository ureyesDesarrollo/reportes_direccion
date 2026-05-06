<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';
require_once __DIR__ . '/../../shared/ReportHelpers.php';

/*
|--------------------------------------------------------------------------
| build_report.php - Ventas por Cliente
|--------------------------------------------------------------------------
| Compara el comportamiento mensual de venta del anio actual contra el
| mismo periodo del anio anterior. La metrica principal del semaforo es el
| numero de facturas de venta no canceladas.
|--------------------------------------------------------------------------
*/

/** @var array $appConfig */
/** @var array $dbConfig */
/** @var array $config */

$quoteIdentifier = static function (string $identifier, string $label): string {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
    throw new RuntimeException("Identificador invalido para {$label}.");
  }

  return "`{$identifier}`";
};

$upper = static function (string $value): string {
  return function_exists('mb_strtoupper')
    ? mb_strtoupper($value, 'UTF-8')
    : strtoupper($value);
};

$normalizarNombre = static function (?string $value) use ($upper): string {
  $value = trim((string)$value);
  $value = preg_replace('/\s+/', ' ', $value) ?? $value;
  return $upper($value);
};

$normalizarLlaveCliente = static function (?string $value) use ($normalizarNombre): string {
  $value = $normalizarNombre($value);
  $value = strtr($value, [
    'Á' => 'A',
    'É' => 'E',
    'Í' => 'I',
    'Ó' => 'O',
    'Ú' => 'U',
    'Ü' => 'U',
    'Ñ' => 'N',
  ]);
  $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;
  return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
};

$normalizarTipoCliente = static function (?string $value) use ($upper): string {
  $value = trim((string)$value);
  if ($value === '') {
    return 'Desconocido';
  }

  $upperValue = $upper($value);
  if ($upperValue === 'INDUSTRIAL') {
    return 'Industrial';
  }
  if ($upperValue === 'COMERCIAL') {
    return 'Comercial';
  }
  if ($upperValue === 'AMBOS') {
    return 'Ambos';
  }

  return $value;
};

$calcVariation = static function ($actual, $anterior): ?float {
  if ((float)$anterior === 0.0) {
    return null;
  }

  return (((float)$actual - (float)$anterior) / abs((float)$anterior)) * 100;
};

$resolverSemaforoCompras = static function (int $actual, int $anterior, float $toleranciaPct) use ($calcVariation): array {
  if ($anterior <= 0 && $actual <= 0) {
    return ['Sin dato', '#94a3b8', null];
  }

  if ($anterior <= 0 && $actual > 0) {
    return ['Nuevo', '#f59e0b', null];
  }

  $variacion = $calcVariation($actual, $anterior);

  if ($actual <= 0) {
    return ['Inactivo', '#ef4444', $variacion];
  }

  if ($variacion !== null && $variacion > $toleranciaPct) {
    return ['Crecimiento', '#10b981', $variacion];
  }

  if ($variacion !== null && $variacion >= -$toleranciaPct) {
    return ['Estable', '#10b981', $variacion];
  }

  if ($variacion !== null && $variacion >= -($toleranciaPct * 2)) {
    return ['Atencion', '#f59e0b', $variacion];
  }

  return ['Declive', '#ef4444', $variacion];
};

$emptyMetric = static fn(): array => [
  'num_compras' => 0,
  'total_ventas' => 0.0,
  'total_kg' => 0.0,
  'ticket_promedio' => 0.0,
];

$fechaDesde = (string)$config['fecha_desde'];
$tablaFacturaCabecera = (string)$config['tabla_factura_cabecera'];
$tablaFacturaDetalle = (string)$config['tabla_factura_detalle'];
$tablaCreditos = (string)($config['tabla_creditos'] ?? 'creditos');
$tablaClientes = (string)$config['tabla_clientes'];
$tablaAgentes = (string)($config['tabla_agentes'] ?? 'agentes');
$tablaRemisiones = (string)($config['tabla_remisiones'] ?? 'remisiones');
$tablaRemisionDetalle = (string)($config['tabla_remision_detalle'] ?? 'remision_detalle');
$tablaRevClientes = (string)$config['tabla_rev_clientes'];

$campoNumeroFactura = (string)($config['campo_numero_factura'] ?? 'NO_FAC');
$campoCreditoFactura = (string)($config['campo_credito_factura'] ?? $campoNumeroFactura);
$campoCreditoMonto = (string)($config['campo_credito_monto'] ?? 'TOT_NOTA');
$campoCreditoMontoAlt = trim((string)($config['campo_credito_monto_alt'] ?? 'TOT_IMP'));
$campoCreditoStatus = (string)($config['campo_credito_status'] ?? 'NO_ESTADO');
$campoFechaFactura = (string)$config['campo_fecha_factura'];
$campoStatusFactura = (string)($config['campo_status_factura'] ?? 'STATUS_FAC');
$campoCliente = (string)$config['campo_cliente'];
$campoVendedor = (string)($config['campo_vendedor'] ?? 'CVE_AGE');
$campoAgenteVendedor = (string)($config['campo_agente_vendedor'] ?? $campoVendedor);
$campoNombreVendedor = (string)($config['campo_nombre_vendedor'] ?? 'DIR_AGE');
$campoRemisionId = (string)($config['campo_remision_id'] ?? 'id');
$campoRemisionFolio = (string)($config['campo_remision_folio'] ?? 'remision');
$campoRemisionFecha = (string)($config['campo_remision_fecha'] ?? 'fecha_remision');
$campoRemisionCliente = (string)($config['campo_remision_cliente'] ?? 'cliente_nombre');
$campoRemisionVendedor = (string)($config['campo_remision_vendedor'] ?? 'vendedor_nombre');
$campoRemisionTipoVenta = (string)($config['campo_remision_tipo_venta'] ?? 'tipo_venta');
$campoRemisionTotal = (string)($config['campo_remision_total'] ?? 'total_real');
$campoRemisionDetalleFk = (string)($config['campo_remision_detalle_fk'] ?? 'remision_id');
$campoRemisionCantidad = (string)($config['campo_remision_cantidad'] ?? 'cantidad');
$campoRemisionPrecioKg = (string)($config['campo_remision_precio_kg'] ?? 'precio_kg');
$campoMonto = (string)$config['campo_monto'];
$campoSaldoFactura = (string)($config['campo_saldo_factura'] ?? 'SALDO_FAC');
$campoCantidadItems = (string)$config['campo_cantidad_items'];
$campoUnidadDetalle = (string)($config['campo_unidad_detalle'] ?? 'UNIDAD');
$campoNombreCliente = (string)$config['campo_nombre_cliente'];
$campoDiasCredito = (string)($config['campo_dias_credito'] ?? 'DIA_CRE');

$campoRevClienteNombre = (string)($config['campo_rev_cliente_nombre'] ?? 'cte_nombre');
$campoRevClienteRazon = (string)($config['campo_rev_cliente_razon'] ?? 'cte_razon_social');
$campoRevClienteTipo = (string)($config['campo_rev_cliente_tipo'] ?? 'cte_tipo');
$campoRevClienteEstatus = (string)($config['campo_rev_cliente_estatus'] ?? 'cte_estatus');
$valorRevClienteActivo = (string)($config['valor_rev_cliente_activo'] ?? 'A');

$statusCanceladoValores = array_values(array_filter(
  (array)($config['status_cancelado_valores'] ?? ['Cancelada']),
  static fn($status): bool => trim((string)$status) !== ''
));
$statusCanceladoSql = array_map(static fn($status): string => $upper(trim((string)$status)), $statusCanceladoValores);
$creditosCanceladoValores = array_values(array_filter(
  (array)($config['creditos_cancelado_valores'] ?? ['Cancelada']),
  static fn($status): bool => trim((string)$status) !== ''
));
$creditosCanceladoSql = array_map(static fn($status): string => $upper(trim((string)$status)), $creditosCanceladoValores);
$remisionesExcluirExactos = array_values(array_filter(
  (array)($config['remisiones_clientes_excluir_exactos'] ?? ['LABORATORIO']),
  static fn($cliente): bool => trim((string)$cliente) !== ''
));
$remisionesExcluirContiene = array_values(array_filter(
  (array)($config['remisiones_clientes_excluir_contiene'] ?? ['LUIS ARBAIZA', 'LUI FRANCISCO ARBAIZA', 'LUIS FRANCISCO ARBAIZA']),
  static fn($cliente): bool => trim((string)$cliente) !== ''
));
$remisionesExcluirExactosSql = array_map(static fn($cliente): string => $upper(trim((string)$cliente)), $remisionesExcluirExactos);
$remisionesExcluirContieneSql = array_map(static fn($cliente): string => '%' . $upper(trim((string)$cliente)) . '%', $remisionesExcluirContiene);

$toleranciaPct = (float)($config['tolerancia_pct'] ?? 10);
$usarTodosLosClientes = (bool)($config['usar_todos_los_clientes'] ?? true);
$clientesAIgnorar = array_values(array_filter(
  (array)($config['clientes_a_ignorar'] ?? []),
  static fn($cliente): bool => trim((string)$cliente) !== ''
));
$clienteSeleccionado = trim((string)($config['cliente_seleccionado'] ?? ''));

$cardsPorPagina = (int)($appConfig['cards_por_pagina'] ?? 9);
$filasPorPagina = (int)($appConfig['filas_por_pagina'] ?? 15);
$intervaloActualizacion = (int)($appConfig['intervalo_actualizacion'] ?? 300000);
$ventanaProximaVentaDias = max(1, (int)($config['ventana_proxima_venta_dias'] ?? 30));

$anioActual = (int)date('Y');
$anioAnterior = $anioActual - 1;
$mesCorte = max(1, min(12, (int)($config['mes_corte'] ?? date('n'))));

$meses = [
  1 => 'Ene',
  2 => 'Feb',
  3 => 'Mar',
  4 => 'Abr',
  5 => 'May',
  6 => 'Jun',
  7 => 'Jul',
  8 => 'Ago',
  9 => 'Sep',
  10 => 'Oct',
  11 => 'Nov',
  12 => 'Dic',
];

$tablaFacturaCabeceraSql = $quoteIdentifier($tablaFacturaCabecera, 'tabla_factura_cabecera');
$tablaFacturaDetalleSql = $quoteIdentifier($tablaFacturaDetalle, 'tabla_factura_detalle');
$tablaCreditosSql = $quoteIdentifier($tablaCreditos, 'tabla_creditos');
$tablaClientesSql = $quoteIdentifier($tablaClientes, 'tabla_clientes');
$tablaAgentesSql = $quoteIdentifier($tablaAgentes, 'tabla_agentes');
$tablaRemisionesSql = $quoteIdentifier($tablaRemisiones, 'tabla_remisiones');
$tablaRemisionDetalleSql = $quoteIdentifier($tablaRemisionDetalle, 'tabla_remision_detalle');
$tablaRevClientesSql = $quoteIdentifier($tablaRevClientes, 'tabla_rev_clientes');

$campoNumeroFacturaSql = $quoteIdentifier($campoNumeroFactura, 'campo_numero_factura');
$campoCreditoFacturaSql = $quoteIdentifier($campoCreditoFactura, 'campo_credito_factura');
$campoCreditoMontoSql = $quoteIdentifier($campoCreditoMonto, 'campo_credito_monto');
$campoCreditoMontoAltSql = $campoCreditoMontoAlt !== ''
  ? $quoteIdentifier($campoCreditoMontoAlt, 'campo_credito_monto_alt')
  : null;
$campoCreditoStatusSql = $quoteIdentifier($campoCreditoStatus, 'campo_credito_status');
$campoFechaFacturaSql = $quoteIdentifier($campoFechaFactura, 'campo_fecha_factura');
$campoStatusFacturaSql = $quoteIdentifier($campoStatusFactura, 'campo_status_factura');
$campoClienteSql = $quoteIdentifier($campoCliente, 'campo_cliente');
$campoVendedorSql = $quoteIdentifier($campoVendedor, 'campo_vendedor');
$campoAgenteVendedorSql = $quoteIdentifier($campoAgenteVendedor, 'campo_agente_vendedor');
$campoNombreVendedorSql = $quoteIdentifier($campoNombreVendedor, 'campo_nombre_vendedor');
$campoRemisionIdSql = $quoteIdentifier($campoRemisionId, 'campo_remision_id');
$campoRemisionFolioSql = $quoteIdentifier($campoRemisionFolio, 'campo_remision_folio');
$campoRemisionFechaSql = $quoteIdentifier($campoRemisionFecha, 'campo_remision_fecha');
$campoRemisionClienteSql = $quoteIdentifier($campoRemisionCliente, 'campo_remision_cliente');
$campoRemisionVendedorSql = $quoteIdentifier($campoRemisionVendedor, 'campo_remision_vendedor');
$campoRemisionTipoVentaSql = $quoteIdentifier($campoRemisionTipoVenta, 'campo_remision_tipo_venta');
$campoRemisionTotalSql = $quoteIdentifier($campoRemisionTotal, 'campo_remision_total');
$campoRemisionDetalleFkSql = $quoteIdentifier($campoRemisionDetalleFk, 'campo_remision_detalle_fk');
$campoRemisionCantidadSql = $quoteIdentifier($campoRemisionCantidad, 'campo_remision_cantidad');
$campoRemisionPrecioKgSql = $quoteIdentifier($campoRemisionPrecioKg, 'campo_remision_precio_kg');
$campoMontoSql = $quoteIdentifier($campoMonto, 'campo_monto');
$campoSaldoFacturaSql = $quoteIdentifier($campoSaldoFactura, 'campo_saldo_factura');
$campoCantidadItemsSql = $quoteIdentifier($campoCantidadItems, 'campo_cantidad_items');
$campoUnidadDetalleSql = $quoteIdentifier($campoUnidadDetalle, 'campo_unidad_detalle');
$campoNombreClienteSql = $quoteIdentifier($campoNombreCliente, 'campo_nombre_cliente');
$campoDiasCreditoSql = $quoteIdentifier($campoDiasCredito, 'campo_dias_credito');

$campoRevClienteNombreSql = $quoteIdentifier($campoRevClienteNombre, 'campo_rev_cliente_nombre');
$campoRevClienteRazonSql = $quoteIdentifier($campoRevClienteRazon, 'campo_rev_cliente_razon');
$campoRevClienteTipoSql = $quoteIdentifier($campoRevClienteTipo, 'campo_rev_cliente_tipo');
$campoRevClienteEstatusSql = $quoteIdentifier($campoRevClienteEstatus, 'campo_rev_cliente_estatus');

$cacheKey = 'report_ventas_cliente_' . md5(serialize([
  $config,
  $cardsPorPagina,
  $filasPorPagina,
  filemtime(__FILE__),
  date('Y-m-d'),
]));
$cached = getCache($cacheKey);
if ($cached !== null) {
  return $cached;
}

$pdoVentas = conectar($dbConfig['movs']);
$pdoProd = conectar($dbConfig['prod']);

/*
|--------------------------------------------------------------------------
| 1) Tipos de cliente desde produccion
|--------------------------------------------------------------------------
*/
$tiposClienteProd = [];

try {
  $sqlRevClientes = "
    SELECT
      {$campoRevClienteNombreSql} AS nombre,
      {$campoRevClienteRazonSql} AS razon_social,
      {$campoRevClienteTipoSql} AS tipo_cliente
    FROM {$tablaRevClientesSql}
    WHERE {$campoRevClienteEstatusSql} = ?
  ";

  $stmtRevClientes = $pdoProd->prepare($sqlRevClientes);
  $stmtRevClientes->execute([$valorRevClienteActivo]);

  while ($row = $stmtRevClientes->fetch(PDO::FETCH_ASSOC)) {
    $tipo = $normalizarTipoCliente($row['tipo_cliente'] ?? '');

    foreach (['nombre', 'razon_social'] as $field) {
      $key = $normalizarLlaveCliente($row[$field] ?? '');
      if ($key !== '') {
        $tiposClienteProd[$key] = $tipo;
      }
    }
  }
} catch (Throwable $e) {
  error_log('Error consultando rev_clientes: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| 2) Ventas mensuales por cliente
|--------------------------------------------------------------------------
*/
$where = [
  "YEAR(fc.{$campoFechaFacturaSql}) IN (?, ?)",
  "fc.{$campoFechaFacturaSql} >= ?",
  "TRIM(CAST(fc.{$campoClienteSql} AS CHAR)) <> ''",
  "CAST(fc.{$campoMontoSql} AS DECIMAL(18,2)) > 0",
];
$params = [$anioAnterior, $anioActual, $fechaDesde];

if (!empty($statusCanceladoSql)) {
  $where[] = 'UPPER(TRIM(COALESCE(fc.' . $campoStatusFacturaSql . ", ''))) NOT IN (" . createPlaceholders($statusCanceladoSql) . ')';
  $params = array_merge($params, $statusCanceladoSql);
}

if (!$usarTodosLosClientes && !empty($clientesAIgnorar)) {
  $where[] = 'TRIM(CAST(fc.' . $campoClienteSql . ' AS CHAR)) NOT IN (' . createPlaceholders($clientesAIgnorar) . ')';
  $params = array_merge($params, array_map('strval', $clientesAIgnorar));
}

$creditoMontoExpr = $campoCreditoMontoAltSql !== null
  ? "COALESCE(NULLIF(CAST({$campoCreditoMontoSql} AS DECIMAL(18,2)), 0), CAST({$campoCreditoMontoAltSql} AS DECIMAL(18,2)), 0)"
  : "COALESCE(CAST({$campoCreditoMontoSql} AS DECIMAL(18,2)), 0)";
$creditoWhere = [
  "TRIM(CAST({$campoCreditoFacturaSql} AS CHAR)) <> ''",
];
$creditoParams = [];

if (!empty($creditosCanceladoSql)) {
  $creditoWhere[] = "UPPER(TRIM(COALESCE({$campoCreditoStatusSql}, ''))) NOT IN (" . createPlaceholders($creditosCanceladoSql) . ')';
  $creditoParams = array_merge($creditoParams, $creditosCanceladoSql);
}

$ventaNetaExpr = "GREATEST(CAST(fc.{$campoMontoSql} AS DECIMAL(18,2)) - COALESCE(cr.total_creditos, 0), 0)";

$sqlVentasMensuales = "
  SELECT
    YEAR(fc.{$campoFechaFacturaSql}) AS anio,
    MONTH(fc.{$campoFechaFacturaSql}) AS mes,
    TRIM(CAST(fc.{$campoClienteSql} AS CHAR)) AS cve_cliente,
    COALESCE(NULLIF(TRIM(c.{$campoNombreClienteSql}), ''), TRIM(CAST(fc.{$campoClienteSql} AS CHAR))) AS nombre_cliente,
    GROUP_CONCAT(
      DISTINCT COALESCE(
        NULLIF(TRIM(a.{$campoNombreVendedorSql}), ''),
        NULLIF(CONCAT('Vendedor ', TRIM(CAST(fc.{$campoVendedorSql} AS CHAR))), 'Vendedor ')
      )
      ORDER BY COALESCE(NULLIF(TRIM(a.{$campoNombreVendedorSql}), ''), TRIM(CAST(fc.{$campoVendedorSql} AS CHAR)))
      SEPARATOR '||'
    ) AS vendedores,
    COUNT(DISTINCT TRIM(CAST(fc.{$campoNumeroFacturaSql} AS CHAR))) AS num_compras,
    SUM({$ventaNetaExpr}) AS total_ventas,
    SUM(COALESCE(fd.total_kg, 0)) AS total_kg,
    AVG({$ventaNetaExpr}) AS ticket_promedio
  FROM {$tablaFacturaCabeceraSql} fc
  LEFT JOIN (
    SELECT
      TRIM(CAST({$campoNumeroFacturaSql} AS CHAR)) AS no_factura,
      SUM(
        CASE
          WHEN UPPER(TRIM({$campoUnidadDetalleSql})) IN ('KG','KGS','KILO','KILOS') THEN CAST({$campoCantidadItemsSql} AS DECIMAL(18,4))
          WHEN UPPER(TRIM({$campoUnidadDetalleSql})) IN ('G','GR','GRAMO','GRAMOS') THEN CAST({$campoCantidadItemsSql} AS DECIMAL(18,4)) / 1000
          ELSE 0
        END
      ) AS total_kg
    FROM {$tablaFacturaDetalleSql}
    GROUP BY TRIM(CAST({$campoNumeroFacturaSql} AS CHAR))
  ) fd
    ON fd.no_factura = TRIM(CAST(fc.{$campoNumeroFacturaSql} AS CHAR))
  LEFT JOIN (
    SELECT
      TRIM(CAST({$campoCreditoFacturaSql} AS CHAR)) AS no_factura,
      SUM(GREATEST({$creditoMontoExpr}, 0)) AS total_creditos
    FROM {$tablaCreditosSql}
    WHERE " . implode("\n      AND ", $creditoWhere) . "
    GROUP BY TRIM(CAST({$campoCreditoFacturaSql} AS CHAR))
  ) cr
    ON cr.no_factura = TRIM(CAST(fc.{$campoNumeroFacturaSql} AS CHAR))
  LEFT JOIN {$tablaClientesSql} c
    ON TRIM(CAST(c.{$campoClienteSql} AS CHAR)) = TRIM(CAST(fc.{$campoClienteSql} AS CHAR))
  LEFT JOIN {$tablaAgentesSql} a
    ON TRIM(CAST(a.{$campoAgenteVendedorSql} AS CHAR)) = TRIM(CAST(fc.{$campoVendedorSql} AS CHAR))
  WHERE " . implode("\n    AND ", $where) . "
  GROUP BY
    YEAR(fc.{$campoFechaFacturaSql}),
    MONTH(fc.{$campoFechaFacturaSql}),
    TRIM(CAST(fc.{$campoClienteSql} AS CHAR)),
    COALESCE(NULLIF(TRIM(c.{$campoNombreClienteSql}), ''), TRIM(CAST(fc.{$campoClienteSql} AS CHAR)))
  ORDER BY nombre_cliente, anio, mes
";

try {
  $stmtVentas = $pdoVentas->prepare($sqlVentasMensuales);
  $stmtVentas->execute(array_merge($creditoParams, $params));
  $ventasMensuales = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Error consultando ventas mensuales por cliente: ' . $e->getMessage());
  $ventasMensuales = [];
}

$remisionImporteExpr = "GREATEST(COALESCE(NULLIF(CAST(r.{$campoRemisionTotalSql} AS DECIMAL(18,2)), 0), rd.total_detalle, 0), 0)";
$whereRemisiones = [
  "YEAR(r.{$campoRemisionFechaSql}) IN (?, ?)",
  "r.{$campoRemisionFechaSql} >= ?",
  "TRIM(COALESCE(r.{$campoRemisionClienteSql}, '')) <> ''",
];
$paramsRemisiones = [$anioAnterior, $anioActual, $fechaDesde];

if (!empty($remisionesExcluirExactosSql)) {
  $whereRemisiones[] = "UPPER(TRIM(COALESCE(r.{$campoRemisionClienteSql}, ''))) NOT IN (" . createPlaceholders($remisionesExcluirExactosSql) . ')';
  $paramsRemisiones = array_merge($paramsRemisiones, $remisionesExcluirExactosSql);
}

foreach ($remisionesExcluirContieneSql as $clienteExcluido) {
  $whereRemisiones[] = "UPPER(TRIM(COALESCE(r.{$campoRemisionClienteSql}, ''))) NOT LIKE ?";
  $paramsRemisiones[] = $clienteExcluido;
}

$sqlRemisionesMensuales = "
  SELECT
    YEAR(r.{$campoRemisionFechaSql}) AS anio,
    MONTH(r.{$campoRemisionFechaSql}) AS mes,
    '' AS cve_cliente,
    TRIM(r.{$campoRemisionClienteSql}) AS nombre_cliente,
    GROUP_CONCAT(
      DISTINCT NULLIF(TRIM(r.{$campoRemisionVendedorSql}), '')
      ORDER BY NULLIF(TRIM(r.{$campoRemisionVendedorSql}), '')
      SEPARATOR '||'
    ) AS vendedores,
    GROUP_CONCAT(
      DISTINCT NULLIF(TRIM(r.{$campoRemisionTipoVentaSql}), '')
      ORDER BY NULLIF(TRIM(r.{$campoRemisionTipoVentaSql}), '')
      SEPARATOR '||'
    ) AS tipos_venta,
    COUNT(DISTINCT CASE WHEN {$remisionImporteExpr} > 0 THEN r.{$campoRemisionIdSql} END) AS num_compras,
    SUM({$remisionImporteExpr}) AS total_ventas,
    SUM(CASE WHEN {$remisionImporteExpr} > 0 THEN COALESCE(rd.total_kg, 0) ELSE 0 END) AS total_kg,
    AVG(NULLIF({$remisionImporteExpr}, 0)) AS ticket_promedio,
    'remision' AS origen
  FROM {$tablaRemisionesSql} r
  LEFT JOIN (
    SELECT
      {$campoRemisionDetalleFkSql} AS remision_id,
      SUM(COALESCE(CAST({$campoRemisionCantidadSql} AS DECIMAL(18,4)), 0)) AS total_kg,
      SUM(
        COALESCE(CAST({$campoRemisionCantidadSql} AS DECIMAL(18,4)), 0)
        * COALESCE(CAST({$campoRemisionPrecioKgSql} AS DECIMAL(18,4)), 0)
      ) AS total_detalle
    FROM {$tablaRemisionDetalleSql}
    GROUP BY {$campoRemisionDetalleFkSql}
  ) rd
    ON rd.remision_id = r.{$campoRemisionIdSql}
  WHERE " . implode("\n    AND ", $whereRemisiones) . "
  GROUP BY
    YEAR(r.{$campoRemisionFechaSql}),
    MONTH(r.{$campoRemisionFechaSql}),
    TRIM(r.{$campoRemisionClienteSql})
  HAVING total_ventas > 0 AND num_compras > 0
  ORDER BY nombre_cliente, anio, mes
";

try {
  $stmtRemisiones = $pdoProd->prepare($sqlRemisionesMensuales);
  $stmtRemisiones->execute($paramsRemisiones);
  $remisionesMensuales = $stmtRemisiones->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Error consultando ventas remisionadas por cliente: ' . $e->getMessage());
  $remisionesMensuales = [];
}

/*
|--------------------------------------------------------------------------
| 2.1) Cartera y morosidad por cliente
|--------------------------------------------------------------------------
*/
$whereCartera = [
  "TRIM(CAST(fc.{$campoClienteSql} AS CHAR)) <> ''",
  "CAST(fc.{$campoMontoSql} AS DECIMAL(18,2)) > 0",
];
$paramsCartera = [];

if (!empty($statusCanceladoSql)) {
  $whereCartera[] = 'UPPER(TRIM(COALESCE(fc.' . $campoStatusFacturaSql . ", ''))) NOT IN (" . createPlaceholders($statusCanceladoSql) . ')';
  $paramsCartera = array_merge($paramsCartera, $statusCanceladoSql);
}

if (!$usarTodosLosClientes && !empty($clientesAIgnorar)) {
  $whereCartera[] = 'TRIM(CAST(fc.' . $campoClienteSql . ' AS CHAR)) NOT IN (' . createPlaceholders($clientesAIgnorar) . ')';
  $paramsCartera = array_merge($paramsCartera, array_map('strval', $clientesAIgnorar));
}

$saldoFacturaExpr = "CAST(COALESCE(fc.{$campoSaldoFacturaSql}, 0) AS DECIMAL(18,2))";
$saldoPendienteExpr = "CASE WHEN {$saldoFacturaExpr} < 0 THEN ABS({$saldoFacturaExpr}) ELSE {$saldoFacturaExpr} END";
$diasCreditoExpr = "GREATEST(COALESCE(CAST(c.{$campoDiasCreditoSql} AS SIGNED), 0), 0)";
$fechaVencimientoExpr = "DATE_ADD(DATE(fc.{$campoFechaFacturaSql}), INTERVAL {$diasCreditoExpr} DAY)";
$diasVencidoExpr = "DATEDIFF(CURDATE(), {$fechaVencimientoExpr})";

$sqlCarteraCliente = "
  SELECT
    cve_cliente,
    MAX(dias_credito) AS dias_credito,
    SUM(saldo_pendiente) AS saldo_pendiente,
    SUM(CASE WHEN saldo_pendiente > 0 THEN 1 ELSE 0 END) AS facturas_pendientes,
    SUM(CASE WHEN saldo_pendiente > 0 AND dias_vencido > 0 THEN 1 ELSE 0 END) AS facturas_morosas,
    SUM(CASE WHEN saldo_pendiente > 0 AND dias_vencido > 0 THEN saldo_pendiente ELSE 0 END) AS saldo_vencido,
    MAX(CASE WHEN saldo_pendiente > 0 AND dias_vencido > 0 THEN dias_vencido ELSE 0 END) AS dias_vencido_max
  FROM (
    SELECT
      TRIM(CAST(fc.{$campoClienteSql} AS CHAR)) AS cve_cliente,
      {$diasCreditoExpr} AS dias_credito,
      {$saldoPendienteExpr} AS saldo_pendiente,
      {$diasVencidoExpr} AS dias_vencido
    FROM {$tablaFacturaCabeceraSql} fc
    LEFT JOIN {$tablaClientesSql} c
      ON TRIM(CAST(c.{$campoClienteSql} AS CHAR)) = TRIM(CAST(fc.{$campoClienteSql} AS CHAR))
    WHERE " . implode("\n      AND ", $whereCartera) . "
  ) cartera
  GROUP BY cve_cliente
";

$carteraPorCliente = [];
try {
  $stmtCartera = $pdoVentas->prepare($sqlCarteraCliente);
  $stmtCartera->execute($paramsCartera);

  while ($row = $stmtCartera->fetch(PDO::FETCH_ASSOC)) {
    $cveCliente = trim((string)($row['cve_cliente'] ?? ''));
    if ($cveCliente === '') {
      continue;
    }

    $carteraPorCliente[$cveCliente] = [
      'dias_credito' => (int)($row['dias_credito'] ?? 0),
      'saldo_pendiente' => (float)($row['saldo_pendiente'] ?? 0),
      'facturas_pendientes' => (int)($row['facturas_pendientes'] ?? 0),
      'facturas_morosas' => (int)($row['facturas_morosas'] ?? 0),
      'saldo_vencido' => (float)($row['saldo_vencido'] ?? 0),
      'dias_vencido_max' => (int)($row['dias_vencido_max'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  error_log('Error consultando cartera por cliente: ' . $e->getMessage());
}

$sqlFacturasCliente = "
  SELECT
    TRIM(CAST(fc.{$campoClienteSql} AS CHAR)) AS cve_cliente,
    TRIM(CAST(fc.{$campoNumeroFacturaSql} AS CHAR)) AS no_factura,
    DATE(fc.{$campoFechaFacturaSql}) AS fecha_factura
  FROM {$tablaFacturaCabeceraSql} fc
  WHERE " . implode("\n    AND ", $where) . "
  GROUP BY
    TRIM(CAST(fc.{$campoClienteSql} AS CHAR)),
    TRIM(CAST(fc.{$campoNumeroFacturaSql} AS CHAR)),
    DATE(fc.{$campoFechaFacturaSql})
  ORDER BY cve_cliente, fecha_factura
";

$facturasPorCliente = [];
try {
  $stmtFacturas = $pdoVentas->prepare($sqlFacturasCliente);
  $stmtFacturas->execute($params);

  while ($row = $stmtFacturas->fetch(PDO::FETCH_ASSOC)) {
    $cveCliente = trim((string)($row['cve_cliente'] ?? ''));
    $fechaFactura = trim((string)($row['fecha_factura'] ?? ''));

    if ($cveCliente === '' || $fechaFactura === '') {
      continue;
    }

    $facturasPorCliente[$cveCliente][] = $fechaFactura;
  }
} catch (Throwable $e) {
  error_log('Error consultando facturas por cliente: ' . $e->getMessage());
}

$sqlRemisionesCliente = "
  SELECT
    TRIM(r.{$campoRemisionClienteSql}) AS nombre_cliente,
    DATE(r.{$campoRemisionFechaSql}) AS fecha_factura
  FROM {$tablaRemisionesSql} r
  LEFT JOIN (
    SELECT
      {$campoRemisionDetalleFkSql} AS remision_id,
      SUM(COALESCE(CAST({$campoRemisionCantidadSql} AS DECIMAL(18,4)), 0)) AS total_kg,
      SUM(
        COALESCE(CAST({$campoRemisionCantidadSql} AS DECIMAL(18,4)), 0)
        * COALESCE(CAST({$campoRemisionPrecioKgSql} AS DECIMAL(18,4)), 0)
      ) AS total_detalle
    FROM {$tablaRemisionDetalleSql}
    GROUP BY {$campoRemisionDetalleFkSql}
  ) rd
    ON rd.remision_id = r.{$campoRemisionIdSql}
  WHERE " . implode("\n    AND ", $whereRemisiones) . "
    AND {$remisionImporteExpr} > 0
  GROUP BY
    TRIM(r.{$campoRemisionClienteSql}),
    r.{$campoRemisionIdSql},
    DATE(r.{$campoRemisionFechaSql})
  ORDER BY nombre_cliente, fecha_factura
";

$remisionesFechas = [];
try {
  $stmtRemisionesFechas = $pdoProd->prepare($sqlRemisionesCliente);
  $stmtRemisionesFechas->execute($paramsRemisiones);
  $remisionesFechas = $stmtRemisionesFechas->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('Error consultando fechas de remisiones por cliente: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| 3) Armar comparativa por cliente
|--------------------------------------------------------------------------
*/
$baseMensual = [];
for ($mes = 1; $mes <= 12; $mes++) {
  $baseMensual[$mes] = [
    'anterior' => $emptyMetric(),
    'actual' => $emptyMetric(),
  ];
}

$clientes = [];
$clienteKeyPorNombre = [];

foreach ($ventasMensuales as $row) {
  $anio = (int)$row['anio'];
  $mes = (int)$row['mes'];

  if ($anio !== $anioAnterior && $anio !== $anioActual) {
    continue;
  }

  if ($mes < 1 || $mes > 12) {
    continue;
  }

  $cveCliente = trim((string)$row['cve_cliente']);
  if ($cveCliente === '') {
    continue;
  }

  $nombreCliente = trim((string)($row['nombre_cliente'] ?? $cveCliente));
  $llaveNombreCliente = $normalizarLlaveCliente($nombreCliente);
  $tipoCliente = $tiposClienteProd[$llaveNombreCliente] ?? 'Desconocido';

  if (!isset($clientes[$cveCliente])) {
    $clientes[$cveCliente] = [
      'cve_cliente' => $cveCliente,
      'nombre' => $nombreCliente !== '' ? $nombreCliente : $cveCliente,
      'tipo_cliente' => $tipoCliente,
      'vendedores' => [],
      'origenes' => [],
      'mensual' => $baseMensual,
    ];
  }

  if ($llaveNombreCliente !== '') {
    $clienteKeyPorNombre[$llaveNombreCliente] = $cveCliente;
  }

  if ($anio === $anioActual) {
    $clientes[$cveCliente]['nombre'] = $nombreCliente !== '' ? $nombreCliente : $clientes[$cveCliente]['nombre'];
  }

  if ($tipoCliente !== 'Desconocido') {
    $clientes[$cveCliente]['tipo_cliente'] = $tipoCliente;
  }

  $vendedores = array_values(array_filter(
    array_map('trim', explode('||', (string)($row['vendedores'] ?? ''))),
    static fn(string $vendedor): bool => $vendedor !== ''
  ));
  foreach ($vendedores as $vendedor) {
    $clientes[$cveCliente]['vendedores'][$vendedor] = $vendedor;
  }
  $clientes[$cveCliente]['origenes']['facturado'] = true;

  $bucket = $anio === $anioAnterior ? 'anterior' : 'actual';
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras'] += (int)$row['num_compras'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_ventas'] += (float)$row['total_ventas'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_kg'] += (float)$row['total_kg'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['ticket_promedio'] =
    $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras'] > 0
      ? $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_ventas'] / $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras']
      : 0.0;
}

foreach ($remisionesMensuales as $row) {
  $anio = (int)$row['anio'];
  $mes = (int)$row['mes'];

  if ($anio !== $anioAnterior && $anio !== $anioActual) {
    continue;
  }

  if ($mes < 1 || $mes > 12) {
    continue;
  }

  $nombreCliente = trim((string)($row['nombre_cliente'] ?? ''));
  $llaveNombreCliente = $normalizarLlaveCliente($nombreCliente);
  if ($llaveNombreCliente === '') {
    continue;
  }

  $cveCliente = $clienteKeyPorNombre[$llaveNombreCliente] ?? ('REM-' . substr(md5($llaveNombreCliente), 0, 10));
  $tiposVenta = array_values(array_unique(array_filter(
    array_map($normalizarTipoCliente, explode('||', (string)($row['tipos_venta'] ?? ''))),
    static fn(string $tipo): bool => $tipo !== '' && $tipo !== 'Desconocido'
  )));
  $tipoRemision = 'Desconocido';
  if (in_array('Industrial', $tiposVenta, true) && in_array('Comercial', $tiposVenta, true)) {
    $tipoRemision = 'Ambos';
  } elseif (!empty($tiposVenta)) {
    $tipoRemision = $tiposVenta[0];
  }
  $tipoCliente = $tiposClienteProd[$llaveNombreCliente] ?? $tipoRemision;

  if (!isset($clientes[$cveCliente])) {
    $clientes[$cveCliente] = [
      'cve_cliente' => $cveCliente,
      'nombre' => $nombreCliente,
      'tipo_cliente' => $tipoCliente,
      'vendedores' => [],
      'origenes' => [],
      'mensual' => $baseMensual,
    ];
  }

  $clienteKeyPorNombre[$llaveNombreCliente] = $cveCliente;

  if ($anio === $anioActual && $nombreCliente !== '') {
    $clientes[$cveCliente]['nombre'] = $nombreCliente;
  }

  if ($tipoCliente !== 'Desconocido' && ($clientes[$cveCliente]['tipo_cliente'] === 'Desconocido' || isset($tiposClienteProd[$llaveNombreCliente]))) {
    $clientes[$cveCliente]['tipo_cliente'] = $tipoCliente;
  }

  $vendedores = array_values(array_filter(
    array_map('trim', explode('||', (string)($row['vendedores'] ?? ''))),
    static fn(string $vendedor): bool => $vendedor !== ''
  ));
  foreach ($vendedores as $vendedor) {
    $clientes[$cveCliente]['vendedores'][$vendedor] = $vendedor;
  }
  $clientes[$cveCliente]['origenes']['remisionado'] = true;

  $bucket = $anio === $anioAnterior ? 'anterior' : 'actual';
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras'] += (int)$row['num_compras'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_ventas'] += (float)$row['total_ventas'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_kg'] += (float)$row['total_kg'];
  $clientes[$cveCliente]['mensual'][$mes][$bucket]['ticket_promedio'] =
    $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras'] > 0
      ? $clientes[$cveCliente]['mensual'][$mes][$bucket]['total_ventas'] / $clientes[$cveCliente]['mensual'][$mes][$bucket]['num_compras']
      : 0.0;
}

foreach ($remisionesFechas as $row) {
  $nombreCliente = trim((string)($row['nombre_cliente'] ?? ''));
  $fechaFactura = trim((string)($row['fecha_factura'] ?? ''));
  $llaveNombreCliente = $normalizarLlaveCliente($nombreCliente);

  if ($llaveNombreCliente === '' || $fechaFactura === '') {
    continue;
  }

  $cveCliente = $clienteKeyPorNombre[$llaveNombreCliente] ?? ('REM-' . substr(md5($llaveNombreCliente), 0, 10));
  $facturasPorCliente[$cveCliente][] = $fechaFactura;
}

$clientesComparativa = [];
$clientesPorEstado = [
  'Crecimiento' => 0,
  'Estable' => 0,
  'Atencion' => 0,
  'Declive' => 0,
  'Inactivo' => 0,
  'Nuevo' => 0,
  'Sin dato' => 0,
];

$totalComprasAnterior = 0;
$totalComprasActual = 0;
$totalVentasAnterior = 0.0;
$totalVentasActual = 0.0;
$totalKilosAnterior = 0.0;
$totalKilosActual = 0.0;

$totalComprasAnioAnterior = 0;
$totalComprasAnioActual = 0;
$totalVentasAnioAnterior = 0.0;
$totalVentasAnioActual = 0.0;
$totalKilosAnioAnterior = 0.0;
$totalKilosAnioActual = 0.0;

$cantidadClientesAnterior = 0;
$cantidadClientesActual = 0;
$clientesParaProximaVenta = 0;
$hoy = new DateTimeImmutable('today');
$limiteProximaVenta = $hoy->modify("+{$ventanaProximaVentaDias} days");

foreach ($clientes as $cliente) {
  $comprasAnterior = 0;
  $comprasActual = 0;
  $ventasAnterior = 0.0;
  $ventasActual = 0.0;
  $kilosAnterior = 0.0;
  $kilosActual = 0.0;

  $comprasAnioAnterior = 0;
  $comprasAnioActual = 0;
  $ventasAnioAnterior = 0.0;
  $ventasAnioActual = 0.0;
  $kilosAnioAnterior = 0.0;
  $kilosAnioActual = 0.0;

  $mensualComparativo = [];
  $mesesEnRiesgo = 0;
  $mesesEnCrecimiento = 0;
  $mesesSinCompra = 0;

  for ($mes = 1; $mes <= 12; $mes++) {
    $anterior = $cliente['mensual'][$mes]['anterior'];
    $actual = $cliente['mensual'][$mes]['actual'];

    $comprasAnioAnterior += (int)$anterior['num_compras'];
    $comprasAnioActual += (int)$actual['num_compras'];
    $ventasAnioAnterior += (float)$anterior['total_ventas'];
    $ventasAnioActual += (float)$actual['total_ventas'];
    $kilosAnioAnterior += (float)$anterior['total_kg'];
    $kilosAnioActual += (float)$actual['total_kg'];

    if ($mes > $mesCorte) {
      continue;
    }

    $comprasAnterior += (int)$anterior['num_compras'];
    $comprasActual += (int)$actual['num_compras'];
    $ventasAnterior += (float)$anterior['total_ventas'];
    $ventasActual += (float)$actual['total_ventas'];
    $kilosAnterior += (float)$anterior['total_kg'];
    $kilosActual += (float)$actual['total_kg'];

    [$estadoMes, $colorMes, $variacionMes] = $resolverSemaforoCompras(
      (int)$actual['num_compras'],
      (int)$anterior['num_compras'],
      $toleranciaPct
    );

    if (in_array($estadoMes, ['Atencion', 'Declive', 'Inactivo'], true)) {
      $mesesEnRiesgo++;
    }
    if ($estadoMes === 'Crecimiento') {
      $mesesEnCrecimiento++;
    }
    if ((int)$actual['num_compras'] === 0) {
      $mesesSinCompra++;
    }

    $mensualComparativo[] = [
      'mes' => $mes,
      'label' => $meses[$mes],
      'compras_anterior' => (int)$anterior['num_compras'],
      'compras_actual' => (int)$actual['num_compras'],
      'ventas_anterior' => (float)$anterior['total_ventas'],
      'ventas_actual' => (float)$actual['total_ventas'],
      'kg_anterior' => (float)$anterior['total_kg'],
      'kg_actual' => (float)$actual['total_kg'],
      'variacion_compras' => $variacionMes,
      'estado' => $estadoMes,
      'color' => $colorMes,
    ];
  }

  $mesActualAnterior = $cliente['mensual'][$mesCorte]['anterior'];
  $mesActualActual = $cliente['mensual'][$mesCorte]['actual'];
  [$estadoMesActual, $colorMesActual, $variacionMesActual] = $resolverSemaforoCompras(
    (int)$mesActualActual['num_compras'],
    (int)$mesActualAnterior['num_compras'],
    $toleranciaPct
  );

  $variacionAcumulado = $calcVariation($comprasActual, $comprasAnterior);
  [$estado, $color, $variacionComprasAnual] = $resolverSemaforoCompras($comprasAnioActual, $comprasAnioAnterior, $toleranciaPct);

  $variacionVentas = $calcVariation($ventasActual, $ventasAnterior);
  $ticketPromedioAnterior = $comprasAnterior > 0 ? $ventasAnterior / $comprasAnterior : 0.0;
  $ticketPromedioActual = $comprasActual > 0 ? $ventasActual / $comprasActual : 0.0;
  $variacionTicket = $calcVariation($ticketPromedioActual, $ticketPromedioAnterior);
  $precioPromedioKgAnterior = $kilosAnterior > 0 ? $ventasAnterior / $kilosAnterior : null;
  $precioPromedioKgActual = $kilosActual > 0 ? $ventasActual / $kilosActual : null;
  $precioPromedioKgAnioAnterior = $kilosAnioAnterior > 0 ? $ventasAnioAnterior / $kilosAnioAnterior : null;
  $precioPromedioKgAnioActual = $kilosAnioActual > 0 ? $ventasAnioActual / $kilosAnioActual : null;

  $fechasFactura = array_values(array_unique($facturasPorCliente[$cliente['cve_cliente']] ?? []));
  sort($fechasFactura);

  $ultimaVentaFecha = end($fechasFactura) ?: null;
  $diasPromedioEntreVentas = null;
  $proximaVentaEstimada = null;
  $diasParaProximaVenta = null;
  $estaParaProximaVenta = false;

  if (count($fechasFactura) >= 2) {
    $intervalos = [];
    for ($i = 1, $totalFechas = count($fechasFactura); $i < $totalFechas; $i++) {
      $fechaAnterior = new DateTimeImmutable($fechasFactura[$i - 1]);
      $fechaActual = new DateTimeImmutable($fechasFactura[$i]);
      $intervalos[] = max(1, (int)$fechaAnterior->diff($fechaActual)->format('%a'));
    }

    $diasPromedioEntreVentas = array_sum($intervalos) / count($intervalos);
    $ultimaVenta = new DateTimeImmutable((string)$ultimaVentaFecha);
    $proximaVenta = $ultimaVenta->modify('+' . max(1, (int)round($diasPromedioEntreVentas)) . ' days');
    $proximaVentaEstimada = $proximaVenta->format('Y-m-d');
    $diasParaProximaVenta = (int)$hoy->diff($proximaVenta)->format('%r%a');
    $estaParaProximaVenta = $proximaVenta <= $limiteProximaVenta;
  }

  $carteraCliente = $carteraPorCliente[$cliente['cve_cliente']] ?? [
    'dias_credito' => 0,
    'saldo_pendiente' => 0.0,
    'facturas_pendientes' => 0,
    'facturas_morosas' => 0,
    'saldo_vencido' => 0.0,
    'dias_vencido_max' => 0,
  ];
  $diasCreditoCliente = (int)$carteraCliente['dias_credito'];
  $saldoPendienteCliente = max(0.0, (float)$carteraCliente['saldo_pendiente']);
  $saldoVencidoCliente = max(0.0, (float)$carteraCliente['saldo_vencido']);
  $facturasPendientesCliente = (int)$carteraCliente['facturas_pendientes'];
  $facturasMorosasCliente = (int)$carteraCliente['facturas_morosas'];
  $diasVencidoMaxCliente = max(0, (int)$carteraCliente['dias_vencido_max']);
  $estadoCartera = 'Pagado';
  $colorCartera = '#10b981';

  if ($facturasMorosasCliente > 0) {
    $estadoCartera = 'Moroso';
    $colorCartera = '#ef4444';
  } elseif ($saldoPendienteCliente > 0) {
    $estadoCartera = 'Por vencer';
    $colorCartera = '#3b82f6';
  }

  $clientesPorEstado[$estado] = ($clientesPorEstado[$estado] ?? 0) + 1;

  if ($comprasAnioAnterior > 0) {
    $cantidadClientesAnterior++;
  }
  if ($comprasAnioActual > 0) {
    $cantidadClientesActual++;
  }
  if ($estaParaProximaVenta) {
    $clientesParaProximaVenta++;
  }

  $totalComprasAnterior += $comprasAnterior;
  $totalComprasActual += $comprasActual;
  $totalVentasAnterior += $ventasAnterior;
  $totalVentasActual += $ventasActual;
  $totalKilosAnterior += $kilosAnterior;
  $totalKilosActual += $kilosActual;

  $totalComprasAnioAnterior += $comprasAnioAnterior;
  $totalComprasAnioActual += $comprasAnioActual;
  $totalVentasAnioAnterior += $ventasAnioAnterior;
  $totalVentasAnioActual += $ventasAnioActual;
  $totalKilosAnioAnterior += $kilosAnioAnterior;
  $totalKilosAnioActual += $kilosAnioActual;

  $clientesComparativa[] = [
    'cve_cliente' => $cliente['cve_cliente'],
    'nombre' => $cliente['nombre'],
    'tipo_cliente' => $cliente['tipo_cliente'],
    'vendedores' => array_values($cliente['vendedores'] ?? []),
    'origenes' => array_keys(array_filter((array)($cliente['origenes'] ?? []))),

    'num_compras_anterior' => $comprasAnterior,
    'num_compras_actual' => $comprasActual,
    'total_compras_anio_anterior' => $comprasAnioAnterior,
    'total_compras_anio_actual' => $comprasAnioActual,

    'compras_mes_anterior' => (int)$mesActualAnterior['num_compras'],
    'compras_mes_actual' => (int)$mesActualActual['num_compras'],
    'variacion_mes_actual' => $variacionMesActual,
    'estado_mes_actual' => $estadoMesActual,
    'color_mes_actual' => $colorMesActual,

    'total_ventas_anterior' => $ventasAnterior,
    'total_ventas_actual' => $ventasActual,
    'total_ventas_anio_anterior' => $ventasAnioAnterior,
    'total_ventas_anio_actual' => $ventasAnioActual,
    'ticket_promedio_anterior' => $ticketPromedioAnterior,
    'ticket_promedio_actual' => $ticketPromedioActual,
    'total_kg_anterior' => $kilosAnterior,
    'total_kg_actual' => $kilosActual,
    'total_kg_anio_anterior' => $kilosAnioAnterior,
    'total_kg_anio_actual' => $kilosAnioActual,
    'precio_promedio_kg_anterior' => $precioPromedioKgAnterior,
    'precio_promedio_kg_actual' => $precioPromedioKgActual,
    'precio_promedio_kg_anio_anterior' => $precioPromedioKgAnioAnterior,
    'precio_promedio_kg_anio_actual' => $precioPromedioKgAnioActual,

    'variacion_ventas' => $variacionVentas,
    'variacion_acumulado' => $variacionAcumulado,
    'variacion_compras' => $variacionComprasAnual,
    'variacion_ticket' => $variacionTicket,
    'estado' => $estado,
    'color' => $color,

    'ultima_venta_fecha' => $ultimaVentaFecha,
    'dias_promedio_entre_ventas' => $diasPromedioEntreVentas,
    'proxima_venta_estimada' => $proximaVentaEstimada,
    'dias_para_proxima_venta' => $diasParaProximaVenta,
    'esta_para_proxima_venta' => $estaParaProximaVenta,

    'dias_credito' => $diasCreditoCliente,
    'saldo_pendiente' => $saldoPendienteCliente,
    'saldo_vencido' => $saldoVencidoCliente,
    'facturas_pendientes' => $facturasPendientesCliente,
    'facturas_morosas' => $facturasMorosasCliente,
    'dias_vencido_max' => $diasVencidoMaxCliente,
    'estado_cartera' => $estadoCartera,
    'color_cartera' => $colorCartera,
    'es_moroso' => $facturasMorosasCliente > 0,

    'meses_en_riesgo' => $mesesEnRiesgo,
    'meses_en_crecimiento' => $mesesEnCrecimiento,
    'meses_sin_compra' => $mesesSinCompra,
    'mensual_comparativo' => $mensualComparativo,
  ];
}

usort($clientesComparativa, static function (array $a, array $b): int {
  return ($b['num_compras_actual'] <=> $a['num_compras_actual'])
    ?: ($b['total_ventas_actual'] <=> $a['total_ventas_actual'])
    ?: strcmp((string)$a['nombre'], (string)$b['nombre']);
});

$promedioVentasAnterior = $cantidadClientesAnterior > 0 ? $totalVentasAnterior / $cantidadClientesAnterior : 0.0;
$promedioVentasActual = $cantidadClientesActual > 0 ? $totalVentasActual / $cantidadClientesActual : 0.0;
$promedioComprasAnterior = $cantidadClientesAnterior > 0 ? $totalComprasAnterior / $cantidadClientesAnterior : 0.0;
$promedioComprasActual = $cantidadClientesActual > 0 ? $totalComprasActual / $cantidadClientesActual : 0.0;

$variacionTotalCompras = $calcVariation($totalComprasActual, $totalComprasAnterior);
$variacionTotalComprasAnio = $calcVariation($totalComprasAnioActual, $totalComprasAnioAnterior);
$variacionTotalVentas = $calcVariation($totalVentasActual, $totalVentasAnterior);
$variacionClientesActivos = $calcVariation($cantidadClientesActual, $cantidadClientesAnterior);
$precioPromedioKgGlobalAnterior = $totalKilosAnterior > 0 ? $totalVentasAnterior / $totalKilosAnterior : null;
$precioPromedioKgGlobalActual = $totalKilosActual > 0 ? $totalVentasActual / $totalKilosActual : null;
$precioPromedioKgGlobalAnioAnterior = $totalKilosAnioAnterior > 0 ? $totalVentasAnioAnterior / $totalKilosAnioAnterior : null;
$precioPromedioKgGlobalAnioActual = $totalKilosAnioActual > 0 ? $totalVentasAnioActual / $totalKilosAnioActual : null;

$clientesPorTipo = ['Industrial' => [], 'Comercial' => [], 'Ambos' => [], 'Desconocido' => []];
foreach ($clientesComparativa as $cliente) {
  $tipo = (string)$cliente['tipo_cliente'];
  if (!isset($clientesPorTipo[$tipo])) {
    $clientesPorTipo[$tipo] = [];
  }
  $clientesPorTipo[$tipo][] = $cliente;
}

$detalleCliente = null;
if ($clienteSeleccionado !== '' && isset($clientes[$clienteSeleccionado])) {
  $clienteDetalle = $clientes[$clienteSeleccionado];
  $detalleMensual = [];
  $comprasDetalleAnterior = 0;
  $comprasDetalleActual = 0;
  $ventasDetalleAnterior = 0.0;
  $ventasDetalleActual = 0.0;
  $kgDetalleAnterior = 0.0;
  $kgDetalleActual = 0.0;

  for ($mes = 1; $mes <= 12; $mes++) {
    $anterior = $clienteDetalle['mensual'][$mes]['anterior'];
    $actual = $clienteDetalle['mensual'][$mes]['actual'];

    $comprasMesAnterior = (int)$anterior['num_compras'];
    $comprasMesActual = (int)$actual['num_compras'];
    $ventasMesAnterior = (float)$anterior['total_ventas'];
    $ventasMesActual = (float)$actual['total_ventas'];
    $kgMesAnterior = (float)$anterior['total_kg'];
    $kgMesActual = (float)$actual['total_kg'];

    $consumoPromedioAnterior = $comprasMesAnterior > 0 ? $kgMesAnterior / $comprasMesAnterior : null;
    $consumoPromedioActual = $comprasMesActual > 0 ? $kgMesActual / $comprasMesActual : null;
    $costoPromedioAnterior = $kgMesAnterior > 0 ? $ventasMesAnterior / $kgMesAnterior : null;
    $costoPromedioActual = $kgMesActual > 0 ? $ventasMesActual / $kgMesActual : null;

    $comprasDetalleAnterior += $comprasMesAnterior;
    $comprasDetalleActual += $comprasMesActual;
    $ventasDetalleAnterior += $ventasMesAnterior;
    $ventasDetalleActual += $ventasMesActual;
    $kgDetalleAnterior += $kgMesAnterior;
    $kgDetalleActual += $kgMesActual;

    $detalleMensual[] = [
      'mes' => $mes,
      'label' => $meses[$mes],
      'compras_anterior' => $comprasMesAnterior,
      'compras_actual' => $comprasMesActual,
      'ventas_anterior' => $ventasMesAnterior,
      'ventas_actual' => $ventasMesActual,
      'kg_anterior' => $kgMesAnterior,
      'kg_actual' => $kgMesActual,
      'consumo_promedio_anterior' => $consumoPromedioAnterior,
      'consumo_promedio_actual' => $consumoPromedioActual,
      'costo_promedio_anterior' => $costoPromedioAnterior,
      'costo_promedio_actual' => $costoPromedioActual,
      'variacion_compras' => $calcVariation($comprasMesActual, $comprasMesAnterior),
      'variacion_ventas' => $calcVariation($ventasMesActual, $ventasMesAnterior),
      'variacion_kg' => $calcVariation($kgMesActual, $kgMesAnterior),
      'variacion_consumo_promedio' => $calcVariation($consumoPromedioActual, $consumoPromedioAnterior),
      'variacion_costo_promedio' => $calcVariation($costoPromedioActual, $costoPromedioAnterior),
    ];
  }

  $detalleCliente = [
    'cliente_key' => $clienteSeleccionado,
    'nombre' => $clienteDetalle['nombre'],
    'tipo_cliente' => $clienteDetalle['tipo_cliente'],
    'vendedores' => array_values($clienteDetalle['vendedores'] ?? []),
    'origenes' => array_keys(array_filter((array)($clienteDetalle['origenes'] ?? []))),
    'meses' => $detalleMensual,
    'resumen' => [
      'compras_anio_anterior' => $comprasDetalleAnterior,
      'compras_anio_actual' => $comprasDetalleActual,
      'ventas_anio_anterior' => $ventasDetalleAnterior,
      'ventas_anio_actual' => $ventasDetalleActual,
      'kg_anio_anterior' => $kgDetalleAnterior,
      'kg_anio_actual' => $kgDetalleActual,
      'consumo_promedio_anio_anterior' => $comprasDetalleAnterior > 0 ? $kgDetalleAnterior / $comprasDetalleAnterior : null,
      'consumo_promedio_anio_actual' => $comprasDetalleActual > 0 ? $kgDetalleActual / $comprasDetalleActual : null,
      'costo_promedio_anio_anterior' => $kgDetalleAnterior > 0 ? $ventasDetalleAnterior / $kgDetalleAnterior : null,
      'costo_promedio_anio_actual' => $kgDetalleActual > 0 ? $ventasDetalleActual / $kgDetalleActual : null,
      'variacion_compras' => $calcVariation($comprasDetalleActual, $comprasDetalleAnterior),
      'variacion_ventas' => $calcVariation($ventasDetalleActual, $ventasDetalleAnterior),
      'variacion_kg' => $calcVariation($kgDetalleActual, $kgDetalleAnterior),
    ],
  ];
}

$reportData = [
  'titulo' => $config['titulo'] ?? 'Ventas por Cliente',
  'version' => time(),

  'anio_anterior' => $anioAnterior,
  'anio_actual' => $anioActual,
  'mes_corte' => $mesCorte,
  'mes_corte_label' => $meses[$mesCorte],
  'meses_catalogo' => array_slice($meses, 0, $mesCorte, true),
  'tolerance_pct' => $toleranciaPct,
  'intervaloActualizacion' => $intervaloActualizacion,

  'total_compras_anterior' => $totalComprasAnterior,
  'total_compras_actual' => $totalComprasActual,
  'total_compras_anio_anterior' => $totalComprasAnioAnterior,
  'total_compras_anio_actual' => $totalComprasAnioActual,
  'variacion_total_compras' => $variacionTotalCompras,
  'variacion_total_compras_anio' => $variacionTotalComprasAnio,

  'total_ventas_anterior' => $totalVentasAnterior,
  'total_ventas_actual' => $totalVentasActual,
  'total_ventas_anio_anterior' => $totalVentasAnioAnterior,
  'total_ventas_anio_actual' => $totalVentasAnioActual,
  'variacion_total_ventas' => $variacionTotalVentas,
  'promedio_ventas_anterior' => $promedioVentasAnterior,
  'promedio_ventas_actual' => $promedioVentasActual,
  'promedio_compras_anterior' => $promedioComprasAnterior,
  'promedio_compras_actual' => $promedioComprasActual,
  'total_kg_anterior' => $totalKilosAnterior,
  'total_kg_actual' => $totalKilosActual,
  'total_kg_anio_anterior' => $totalKilosAnioAnterior,
  'total_kg_anio_actual' => $totalKilosAnioActual,
  'precio_promedio_kg_anterior' => $precioPromedioKgGlobalAnterior,
  'precio_promedio_kg_actual' => $precioPromedioKgGlobalActual,
  'precio_promedio_kg_anio_anterior' => $precioPromedioKgGlobalAnioAnterior,
  'precio_promedio_kg_anio_actual' => $precioPromedioKgGlobalAnioActual,

  'cantidad_clientes_anterior' => $cantidadClientesAnterior,
  'cantidad_clientes_actual' => $cantidadClientesActual,
  'variacion_clientes_activos' => $variacionClientesActivos,
  'clientes_para_proxima_venta' => $clientesParaProximaVenta,
  'ventana_proxima_venta_dias' => $ventanaProximaVentaDias,
  'clientes_por_estado' => $clientesPorEstado,
  'clientes_por_tipo' => $clientesPorTipo,
  'clientes_comparativa' => $clientesComparativa,
  'detalle_cliente' => $detalleCliente,

  'status_cancelado_valores' => $statusCanceladoValores,
  'meta' => [
    'fechaDesde' => $fechaDesde,
    'cardsPorPagina' => $cardsPorPagina,
    'filasPorPagina' => $filasPorPagina,
    'toleranciaPct' => $toleranciaPct,
    'intervaloActualizacion' => $intervaloActualizacion,
    'ventanaProximaVentaDias' => $ventanaProximaVentaDias,
    'campoStatusFactura' => $campoStatusFactura,
    'statusCanceladoValores' => $statusCanceladoValores,
    'tablaCreditos' => $tablaCreditos,
    'campoCreditoMonto' => $campoCreditoMonto,
    'creditosCanceladoValores' => $creditosCanceladoValores,
    'campoSaldoFactura' => $campoSaldoFactura,
    'campoDiasCredito' => $campoDiasCredito,
    'tablaAgentes' => $tablaAgentes,
    'campoVendedor' => $campoVendedor,
    'campoNombreVendedor' => $campoNombreVendedor,
    'tablaRemisiones' => $tablaRemisiones,
    'tablaRemisionDetalle' => $tablaRemisionDetalle,
    'campoRemisionCliente' => $campoRemisionCliente,
    'campoRemisionVendedor' => $campoRemisionVendedor,
    'campoRemisionTotal' => $campoRemisionTotal,
    'remisionesExcluirExactos' => $remisionesExcluirExactos,
    'remisionesExcluirContiene' => $remisionesExcluirContiene,
    'clienteSeleccionado' => $clienteSeleccionado,
  ],
];

setCache($cacheKey, $reportData, 3600);

return $reportData;
