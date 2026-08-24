<?php

declare(strict_types=1);

function financeGetCachedSummary(string $key, int $ttlSeconds): ?array
{
  $file = sys_get_temp_dir() . '/reportes-direccion-finanzas/' . hash('sha256', $key) . '.json';
  if (!is_file($file) || (time() - (int)@filemtime($file)) > $ttlSeconds) return null;
  $payload = json_decode((string)@file_get_contents($file), true);
  return is_array($payload) ? $payload : null;
}

function financeSetCachedSummary(string $key, array $value): void
{
  $directory = sys_get_temp_dir() . '/reportes-direccion-finanzas';
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return;
  $file = $directory . '/' . hash('sha256', $key) . '.json';
  $temporary = @tempnam($directory, 'summary_');
  if ($temporary === false) return;
  $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $file)) @unlink($temporary);
}

function financeWeekLabel(DateTimeImmutable $start, DateTimeImmutable $end): string
{
  $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  $week = $start->format('W');
  $startDay = (int)$start->format('j');
  $endDay = (int)$end->format('j');
  $startMonth = (int)$start->format('n');
  $endMonth = (int)$end->format('n');
  $startYear = (int)$start->format('Y');
  $endYear = (int)$end->format('Y');
  if ($startMonth === $endMonth && $startYear === $endYear) {
    return sprintf('Semana %s · %d al %d de %s de %d', $week, $startDay, $endDay, $months[$endMonth], $endYear);
  }
  if ($startYear === $endYear) {
    return sprintf('Semana %s · %d de %s al %d de %s de %d', $week, $startDay, $months[$startMonth], $endDay, $months[$endMonth], $endYear);
  }
  return sprintf('Semana %s · %d de %s de %d al %d de %s de %d', $week, $startDay, $months[$startMonth], $startYear, $endDay, $months[$endMonth], $endYear);
}

function financeMonthLabel(DateTimeImmutable $date): string
{
  $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  return ($months[(int)$date->format('n')] ?? '') . ' ' . $date->format('Y');
}

function financeLoadPurchaseExpense(
  array $apiConfig,
  int $year,
  int $month,
  string $scope,
  array $statisticalKeys,
  ?string $queryParameter = null,
  ?string $responseField = null
): array
{
  $statisticalKeys = array_values(array_unique(array_filter(array_map('intval', $statisticalKeys), static function (int $key): bool {
    return $key > 0;
  })));
  $cacheKey = sprintf(
    'finanzas:gasto-%s:%s:v%d:%04d-%02d:%s',
    preg_replace('/[^a-z0-9_-]+/i', '-', $scope),
    $queryParameter ?? (string)($apiConfig['query_parameter'] ?? 'cvde1'),
    max(1, (int)($apiConfig['cache_version'] ?? 1)),
    $year,
    $month,
    implode('-', $statisticalKeys)
  );
  $cacheTtl = max(60, (int)($apiConfig['cache_ttl'] ?? 900));
  $cached = financeGetCachedSummary($cacheKey, $cacheTtl);
  if (is_array($cached)) {
    $cached['cached'] = true;
    return $cached;
  }

  $url = trim((string)($apiConfig['url'] ?? ''));
  $queryParameter = trim((string)($queryParameter ?? $apiConfig['query_parameter'] ?? 'cvde1'));
  $responseField = trim((string)($responseField ?? $apiConfig['response_field'] ?? 'cvede1'));
  if ($url === '' || $statisticalKeys === [] || preg_match('/^[a-zA-Z0-9_]+$/', $queryParameter) !== 1) {
    throw new RuntimeException('La fuente de facturas de compra no está configurada.');
  }
  if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL no está disponible.');

  // La misma API del 104 ya se utiliza en Ventas. Se reutiliza su autorización
  // sin duplicar el valor sensible en este reporte.
  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') throw new RuntimeException('No está configurada la autorización del API del 104.');

  $total = 0.0;
  $subtotal = 0.0;
  $invoiceCount = 0;
  $currencyCounts = [];
  $countsByKey = [];
  $invoices = [];
  foreach ($statisticalKeys as $statisticalKey) {
    $requestUrl = $url . '?' . http_build_query([
      'anio' => $year,
      'mes' => $month,
      $queryParameter => $statisticalKey,
    ]);
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => max(5, (int)($apiConfig['timeout'] ?? 30)),
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response)) throw new RuntimeException($curlError !== '' ? $curlError : 'El API no respondió.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('El API respondió con HTTP ' . $status . '.');

    try {
      $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
      throw new RuntimeException('El API devolvió una respuesta JSON inválida.');
    }
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
      throw new RuntimeException('El API devolvió una estructura inesperada.');
    }

    $countsByKey[$statisticalKey] = 0;
    foreach ($payload['data'] as $row) {
      if (!is_array($row)) continue;
      $date = trim((string)($row['fecha'] ?? ''));
      if (preg_match('/^(\d{4})-(\d{2})-\d{2}/', $date, $matches) !== 1 || (int)$matches[1] !== $year || (int)$matches[2] !== $month) continue;

      $statistics = (array)($row['valores_estadisticos'] ?? []);
      $rowStatisticalKey = $statistics[$responseField] ?? $statistics[$queryParameter] ?? null;
      if (!is_numeric($rowStatisticalKey) || (int)$rowStatisticalKey !== $statisticalKey) continue;

      $amounts = (array)($row['importes'] ?? []);
      if (!is_numeric($amounts['subtotal'] ?? null)) continue;
      $currency = (string)($row['moneda']['clave'] ?? 'sin-clave');
      $exchangeRate = is_numeric($row['moneda']['tipo_cambio'] ?? null) ? (float)$row['moneda']['tipo_cambio'] : 1.0;
      if ($exchangeRate <= 0) $exchangeRate = 1.0;
      $conversionRate = $currency === '1' ? 1.0 : $exchangeRate;
      $invoiceSubtotal = (float)$amounts['subtotal'];
      $invoiceTotal = is_numeric($amounts['total'] ?? null) ? (float)$amounts['total'] : 0.0;
      $total += $invoiceTotal * $conversionRate;
      $subtotal += $invoiceSubtotal * $conversionRate;
      $invoiceCount++;
      $countsByKey[$statisticalKey]++;
      $currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + 1;
      $invoiceNumber = trim((string)($row['numero_factura'] ?? $row['identificador']['no_facc'] ?? ''));
      $invoices[] = [
        'numero' => $invoiceNumber !== '' ? $invoiceNumber : 'Sin número',
        'fecha' => $date,
        'fecha_vencimiento' => trim((string)($row['fecha_vencimiento'] ?? '')),
        'estatus' => trim((string)($row['estatus'] ?? '')),
        'proveedor' => trim((string)($row['proveedor']['nombre'] ?? 'Proveedor sin nombre')),
        'proveedor_clave' => is_numeric($row['proveedor']['clave'] ?? null) ? (int)$row['proveedor']['clave'] : null,
        'numero_orden' => is_numeric($row['numero_orden'] ?? null) ? (int)$row['numero_orden'] : null,
        'numero_pedido' => is_numeric($row['numero_pedido'] ?? null) ? (int)$row['numero_pedido'] : null,
        'moneda' => $currency,
        'tipo_cambio' => $exchangeRate,
        'tipo_cambio_aplicado' => $conversionRate,
        'subtotal_convertido' => round($invoiceSubtotal * $conversionRate, 2),
        'total_convertido' => round($invoiceTotal * $conversionRate, 2),
        'importes' => [
          'subtotal' => $invoiceSubtotal,
          'descuento' => is_numeric($amounts['descuento'] ?? null) ? (float)$amounts['descuento'] : 0.0,
          'iva' => is_numeric($amounts['iva'] ?? null) ? (float)$amounts['iva'] : 0.0,
          'ieps' => is_numeric($amounts['ieps'] ?? null) ? (float)$amounts['ieps'] : 0.0,
          'impuesto_1' => is_numeric($amounts['impuesto_1'] ?? null) ? (float)$amounts['impuesto_1'] : 0.0,
          'impuesto_2' => is_numeric($amounts['impuesto_2'] ?? null) ? (float)$amounts['impuesto_2'] : 0.0,
          'retencion' => is_numeric($amounts['retencion'] ?? null) ? (float)$amounts['retencion'] : 0.0,
          'retencion_iva' => is_numeric($amounts['retencion_iva'] ?? null) ? (float)$amounts['retencion_iva'] : 0.0,
          'total' => $invoiceTotal,
          'saldo' => is_numeric($amounts['saldo'] ?? null) ? (float)$amounts['saldo'] : 0.0,
        ],
      ];
    }
  }

  usort($invoices, static function (array $left, array $right): int {
    $amountComparison = ((float)($right['subtotal_convertido'] ?? 0)) <=> ((float)($left['subtotal_convertido'] ?? 0));
    if ($amountComparison !== 0) return $amountComparison;
    return strcmp((string)($right['fecha'] ?? ''), (string)($left['fecha'] ?? ''));
  });

  $result = [
    'value' => round($subtotal, 2),
    'subtotal' => round($subtotal, 2),
    'facturas' => $invoiceCount,
    'facturas_por_clave' => $countsByKey,
    'detalle_facturas' => $invoices,
    'monedas' => $currencyCounts,
    'cached' => false,
    'consultado_en' => date('Y-m-d H:i:s'),
  ];
  financeSetCachedSummary($cacheKey, $result);
  return $result;
}

function financeLoadPurchaseExpenseForRange(
  array $apiConfig,
  DateTimeImmutable $start,
  DateTimeImmutable $end,
  string $scope,
  array $statisticalKeys,
  ?string $queryParameter = null,
  ?string $responseField = null
): array {
  $month = $start->modify('first day of this month')->setTime(0, 0, 0);
  $lastMonth = $end->modify('first day of this month')->setTime(0, 0, 0);
  $invoicesByKey = [];
  $allCached = true;
  while ($month <= $lastMonth) {
    $monthly = financeLoadPurchaseExpense(
      $apiConfig,
      (int)$month->format('Y'),
      (int)$month->format('n'),
      $scope,
      $statisticalKeys,
      $queryParameter,
      $responseField
    );
    $allCached = $allCached && !empty($monthly['cached']);
    foreach ((array)($monthly['detalle_facturas'] ?? []) as $invoice) {
      if (!is_array($invoice)) continue;
      $date = substr((string)($invoice['fecha'] ?? ''), 0, 10);
      if ($date < $start->format('Y-m-d') || $date > $end->format('Y-m-d')) continue;
      $invoiceKey = implode('|', [
        (string)($invoice['proveedor_clave'] ?? ''),
        (string)($invoice['numero'] ?? ''),
        $date,
        (string)($invoice['importes']['total'] ?? ''),
      ]);
      $invoicesByKey[$invoiceKey] = $invoice;
    }
    $month = $month->modify('first day of next month');
  }

  $invoices = array_values($invoicesByKey);
  usort($invoices, static function (array $left, array $right): int {
    $amountComparison = ((float)($right['subtotal_convertido'] ?? 0)) <=> ((float)($left['subtotal_convertido'] ?? 0));
    if ($amountComparison !== 0) return $amountComparison;
    return strcmp((string)($right['fecha'] ?? ''), (string)($left['fecha'] ?? ''));
  });

  $total = 0.0;
  $subtotal = 0.0;
  $currencies = [];
  foreach ($invoices as $invoice) {
    $conversionRate = is_numeric($invoice['tipo_cambio_aplicado'] ?? null) ? (float)$invoice['tipo_cambio_aplicado'] : 1.0;
    $total += (is_numeric($invoice['importes']['total'] ?? null) ? (float)$invoice['importes']['total'] : 0.0) * $conversionRate;
    $subtotal += (is_numeric($invoice['importes']['subtotal'] ?? null) ? (float)$invoice['importes']['subtotal'] : 0.0) * $conversionRate;
    $currency = (string)($invoice['moneda'] ?? 'sin-clave');
    $currencies[$currency] = ($currencies[$currency] ?? 0) + 1;
  }

  return [
    'value' => round($subtotal, 2),
    'subtotal' => round($subtotal, 2),
    'facturas' => count($invoices),
    'facturas_por_clave' => [],
    'detalle_facturas' => $invoices,
    'monedas' => $currencies,
    'cached' => $allCached,
    'consultado_en' => date('Y-m-d H:i:s'),
  ];
}

function financeLoadAverageSalePrice(DateTimeImmutable $start, DateTimeImmutable $end, array $apiConfig): array
{
  $cacheKey = sprintf(
    'finanzas:precio-promedio-venta:v%d:%s:%s',
    max(1, (int)($apiConfig['cache_version'] ?? 1)),
    $start->format('Y-m-d'),
    $end->format('Y-m-d')
  );
  $cacheTtl = max(60, (int)($apiConfig['cache_ttl'] ?? 900));
  $cached = financeGetCachedSummary($cacheKey, $cacheTtl);
  if (is_array($cached)) {
    $cached['cached'] = true;
    return $cached;
  }

  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $dbConfig = (array)($salesConfig['mysql_105'] ?? []);
  $tables = (array)($salesConfig['tablas'] ?? []);
  $quoteIdentifier = static function (string $name): string {
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) throw new InvalidArgumentException('La tabla de ventas no es válida.');
    return '`' . $name . '`';
  };
  $facturas = $quoteIdentifier((string)($tables['facturas'] ?? 'facturas_sai'));
  $facturaDetalle = $quoteIdentifier((string)($tables['factura_detalle'] ?? 'factura_sai_detalle'));
  $remisiones = $quoteIdentifier((string)($tables['remisiones'] ?? 'remisiones'));
  $remisionDetalle = $quoteIdentifier((string)($tables['remision_detalle'] ?? 'remision_detalle'));
  $notasCredito = $quoteIdentifier((string)($tables['notas_credito'] ?? 'notas_credito'));

  $host = trim((string)($dbConfig['host'] ?? ''));
  $port = (int)($dbConfig['port'] ?? 3306);
  $dbname = trim((string)($dbConfig['dbname'] ?? ''));
  $charset = trim((string)($dbConfig['charset'] ?? 'utf8mb4'));
  if ($host === '' || $dbname === '') throw new RuntimeException('La conexión de ventas no está configurada.');
  $pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}",
    (string)($dbConfig['user'] ?? ''),
    (string)($dbConfig['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );

  $sql = "
    SELECT
      (SELECT COALESCE(SUM(total_real), 0) FROM {$facturas} WHERE fecha_factura >= ? AND fecha_factura < ?) AS monto_fiscal,
      (SELECT COALESCE(SUM(d.cantidad), 0) FROM {$facturas} f INNER JOIN {$facturaDetalle} d ON d.factura_id = f.id WHERE f.fecha_factura >= ? AND f.fecha_factura < ?) AS kilos_fiscal,
      (SELECT COALESCE(SUM(total_real), 0) FROM {$remisiones} WHERE fecha_remision >= ? AND fecha_remision < ? AND UPPER(cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%' AND UPPER(cliente_nombre) NOT LIKE '%LUIS ARBAIZA%') AS monto_remision,
      (SELECT COALESCE(SUM(d.cantidad), 0) FROM {$remisiones} r INNER JOIN {$remisionDetalle} d ON d.remision_id = r.id WHERE r.fecha_remision >= ? AND r.fecha_remision < ? AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS FRANCISCO ARBAIZA%' AND UPPER(r.cliente_nombre) NOT LIKE '%LUIS ARBAIZA%') AS kilos_remision,
      (SELECT COALESCE(SUM(total), 0) FROM {$notasCredito} WHERE fecha >= ? AND fecha < ?) AS total_notas_credito,
      (SELECT COALESCE(SUM(CASE WHEN tipo = 'DEVOLUCION' THEN cantidad ELSE 0 END), 0) FROM {$notasCredito} WHERE fecha >= ? AND fecha < ?) AS kilos_devueltos
  ";
  $dateStart = $start->format('Y-m-d');
  $dateEndExclusive = $end->modify('+1 second')->format('Y-m-d H:i:s');
  $parameters = [];
  for ($index = 0; $index < 6; $index++) {
    $parameters[] = $dateStart;
    $parameters[] = $dateEndExclusive;
  }
  $statement = $pdo->prepare($sql);
  $statement->execute($parameters);
  $row = $statement->fetch() ?: [];

  $salesAmount = ((float)($row['monto_fiscal'] ?? 0) - (float)($row['total_notas_credito'] ?? 0)) + (float)($row['monto_remision'] ?? 0);
  $salesKilos = ((float)($row['kilos_fiscal'] ?? 0) - (float)($row['kilos_devueltos'] ?? 0)) + (float)($row['kilos_remision'] ?? 0);
  $result = [
    'value' => $salesKilos > 0 ? round($salesAmount / $salesKilos, 4) : null,
    'monto' => round($salesAmount, 2),
    'kilos' => round($salesKilos, 3),
    'cached' => false,
  ];
  financeSetCachedSummary($cacheKey, $result);
  return $result;
}

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$periodMode = ($_GET['periodo'] ?? 'semana') === 'mes' ? 'mes' : 'semana';
$selectedWeek = $now->format('o-\WW');
$selectedMonth = $now->format('Y-m');

if ($periodMode === 'mes') {
  $requestedMonth = trim((string)($_GET['mes'] ?? ''));
  if (preg_match('/^(\d{4})-(\d{2})$/', $requestedMonth, $monthMatch) !== 1) {
    $requestedMonth = $selectedMonth;
    preg_match('/^(\d{4})-(\d{2})$/', $requestedMonth, $monthMatch);
  }
  $monthYear = (int)($monthMatch[1] ?? $now->format('Y'));
  $monthNumber = (int)($monthMatch[2] ?? $now->format('n'));
  if ($monthYear < 2020 || $monthYear > 2100 || $monthNumber < 1 || $monthNumber > 12) {
    $monthYear = (int)$now->format('Y');
    $monthNumber = (int)$now->format('n');
  }
  $periodStart = $now->setDate($monthYear, $monthNumber, 1)->setTime(0, 0, 0);
  $periodEnd = $periodStart->modify('last day of this month')->setTime(23, 59, 59);
  $selectedMonth = $periodStart->format('Y-m');
  $periodLabel = financeMonthLabel($periodStart);
} else {
  $requestedWeek = trim((string)($_GET['semana'] ?? ''));
  if (preg_match('/^(\d{4})-W(\d{2})$/', $requestedWeek, $weekMatch) !== 1) {
    $requestedWeek = $selectedWeek;
    preg_match('/^(\d{4})-W(\d{2})$/', $requestedWeek, $weekMatch);
  }
  $weekYear = (int)($weekMatch[1] ?? $now->format('o'));
  $weekNumber = (int)($weekMatch[2] ?? $now->format('W'));
  $periodStart = $now->setISODate($weekYear, $weekNumber, 1)->setTime(0, 0, 0);
  if ($weekYear < 2020 || $weekYear > 2100 || $weekNumber < 1 || $weekNumber > 53 || $periodStart->format('o-W') !== sprintf('%04d-%02d', $weekYear, $weekNumber)) {
    $periodStart = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), 1)->setTime(0, 0, 0);
  }
  $periodEnd = $periodStart->modify('+6 days')->setTime(23, 59, 59);
  $selectedWeek = $periodStart->format('o-\WW');
  $periodLabel = financeWeekLabel($periodStart, $periodEnd);
}

$report = $config;
$warnings = [];
try {
  $averageSalePrice = financeLoadAverageSalePrice($periodStart, $periodEnd, (array)($config['api_facturas_compra'] ?? []));
  $report['indicadores']['precio_promedio_venta']['value'] = $averageSalePrice['value'];
  $report['indicadores']['precio_promedio_venta']['meta'] = is_numeric($averageSalePrice['kilos'] ?? null)
    ? number_format((float)$averageSalePrice['kilos'], 0, '.', ',') . ' kg vendidos'
    : null;
  $report['indicadores']['precio_promedio_venta']['cached'] = $averageSalePrice['cached'];
} catch (Throwable $exception) {
  $report['indicadores']['precio_promedio_venta']['value'] = null;
  $warnings[] = 'No fue posible calcular el precio promedio de venta: ' . $exception->getMessage();
}
try {
  $productionKeys = (array)($config['api_facturas_compra']['clasificaciones']['produccion'] ?? []);
  $productionExpense = financeLoadPurchaseExpenseForRange((array)($config['api_facturas_compra'] ?? []), $periodStart, $periodEnd, 'produccion', $productionKeys);
  $report['gastos']['produccion']['value'] = $productionExpense['value'];
  $report['gastos']['produccion']['facturas'] = $productionExpense['facturas'];
  $report['gastos']['produccion']['detalle_facturas'] = $productionExpense['detalle_facturas'];
  $report['gastos']['produccion']['cached'] = $productionExpense['cached'];
} catch (Throwable $exception) {
  $report['gastos']['produccion']['value'] = null;
  $warnings[] = 'No fue posible consultar los gastos de producción: ' . $exception->getMessage();
}

try {
  $financialKeys = (array)($config['api_facturas_compra']['clasificaciones']['financiero'] ?? []);
  $financialExpense = financeLoadPurchaseExpenseForRange((array)($config['api_facturas_compra'] ?? []), $periodStart, $periodEnd, 'financiero', $financialKeys);
  $report['gastos']['financieros']['value'] = $financialExpense['value'];
  $report['gastos']['financieros']['facturas'] = $financialExpense['facturas'];
  $report['gastos']['financieros']['detalle_facturas'] = $financialExpense['detalle_facturas'];
  $report['gastos']['financieros']['cached'] = $financialExpense['cached'];
} catch (Throwable $exception) {
  $report['gastos']['financieros']['value'] = null;
  $warnings[] = 'No fue posible consultar los gastos financieros: ' . $exception->getMessage();
}

foreach (['materia_prima_nacional', 'materia_prima_internacional'] as $materialKey) {
  try {
    $materialCodes = (array)($config['api_facturas_compra']['clasificaciones'][$materialKey] ?? []);
    $materialExpense = financeLoadPurchaseExpenseForRange(
      (array)($config['api_facturas_compra'] ?? []),
      $periodStart,
      $periodEnd,
      $materialKey,
      $materialCodes,
      'cvde7',
      'cvede7'
    );
    $report['gastos'][$materialKey]['value'] = $materialExpense['value'];
    $report['gastos'][$materialKey]['facturas'] = $materialExpense['facturas'];
    $report['gastos'][$materialKey]['detalle_facturas'] = $materialExpense['detalle_facturas'];
    $report['gastos'][$materialKey]['cached'] = $materialExpense['cached'];
  } catch (Throwable $exception) {
    $report['gastos'][$materialKey]['value'] = null;
    $warnings[] = 'No fue posible consultar ' . strtolower((string)($config['gastos'][$materialKey]['label'] ?? 'materia prima')) . ': ' . $exception->getMessage();
  }
}

$report['departamentos'] = [];
$departmentSourceFailed = false;
foreach ((array)($config['departamentos'] ?? []) as $department) {
  $code = (int)($department['code'] ?? 0);
  $metric = $department;
  $metric['source'] = 'api_facturas_compra';
  $metric['value'] = null;
  if (!$departmentSourceFailed && $code > 0) {
    try {
      $departmentExpense = financeLoadPurchaseExpenseForRange(
        (array)($config['api_facturas_compra'] ?? []),
        $periodStart,
        $periodEnd,
        'departamento-' . $code,
        [$code]
      );
      $metric['value'] = $departmentExpense['value'];
      $metric['facturas'] = $departmentExpense['facturas'];
      $metric['detalle_facturas'] = $departmentExpense['detalle_facturas'];
      $metric['cached'] = $departmentExpense['cached'];
    } catch (Throwable $exception) {
      $departmentSourceFailed = true;
      $warnings[] = 'No fue posible consultar los gastos por departamento: ' . $exception->getMessage();
    }
  }
  $report['departamentos'][] = $metric;
}
