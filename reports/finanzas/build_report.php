<?php

declare(strict_types=1);

function financeValidPeriod(int $year, int $month): bool
{
  return $year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12;
}

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

function financePeriodLabel(int $year, int $month): string
{
  $months = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  return ($months[$month] ?? '') . ' ' . $year;
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
      if (!is_numeric($amounts['total'] ?? null)) continue;
      $exchangeRate = is_numeric($row['moneda']['tipo_cambio'] ?? null) ? (float)$row['moneda']['tipo_cambio'] : 1.0;
      if ($exchangeRate <= 0) $exchangeRate = 1.0;
      $currency = (string)($row['moneda']['clave'] ?? 'sin-clave');
      $total += (float)$amounts['total'] * $exchangeRate;
      $subtotal += (is_numeric($amounts['subtotal'] ?? null) ? (float)$amounts['subtotal'] : 0.0) * $exchangeRate;
      $invoiceCount++;
      $countsByKey[$statisticalKey]++;
      $currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + 1;
      $invoiceNumber = trim((string)($row['numero_factura'] ?? $row['identificador']['no_facc'] ?? ''));
      $statisticalData = [];
      foreach ((array)($row['datos_estadisticos'] ?? []) as $statistic) {
        if (!is_array($statistic)) continue;
        $statisticalData[] = [
          'campo' => trim((string)($statistic['campo'] ?? '')),
          'clave' => is_numeric($statistic['clave'] ?? null) ? (int)$statistic['clave'] : null,
          'nombre' => trim((string)($statistic['nombre'] ?? '')),
        ];
      }
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
        'total_convertido' => round((float)$amounts['total'] * $exchangeRate, 2),
        'importes' => [
          'subtotal' => is_numeric($amounts['subtotal'] ?? null) ? (float)$amounts['subtotal'] : 0.0,
          'descuento' => is_numeric($amounts['descuento'] ?? null) ? (float)$amounts['descuento'] : 0.0,
          'iva' => is_numeric($amounts['iva'] ?? null) ? (float)$amounts['iva'] : 0.0,
          'ieps' => is_numeric($amounts['ieps'] ?? null) ? (float)$amounts['ieps'] : 0.0,
          'impuesto_1' => is_numeric($amounts['impuesto_1'] ?? null) ? (float)$amounts['impuesto_1'] : 0.0,
          'impuesto_2' => is_numeric($amounts['impuesto_2'] ?? null) ? (float)$amounts['impuesto_2'] : 0.0,
          'retencion' => is_numeric($amounts['retencion'] ?? null) ? (float)$amounts['retencion'] : 0.0,
          'retencion_iva' => is_numeric($amounts['retencion_iva'] ?? null) ? (float)$amounts['retencion_iva'] : 0.0,
          'total' => (float)$amounts['total'],
          'saldo' => is_numeric($amounts['saldo'] ?? null) ? (float)$amounts['saldo'] : 0.0,
        ],
        'datos_estadisticos' => $statisticalData,
      ];
    }
  }

  usort($invoices, static function (array $left, array $right): int {
    return strcmp((string)($right['fecha'] ?? ''), (string)($left['fecha'] ?? ''));
  });

  $result = [
    'value' => round($total, 2),
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

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'America/Mexico_City'));
$now = new DateTimeImmutable('now', $timezone);
$requestedYear = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$requestedMonth = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT);
$selectedYear = is_int($requestedYear) ? $requestedYear : (int)$now->format('Y');
$selectedMonth = is_int($requestedMonth) ? $requestedMonth : (int)$now->format('n');
if (!financeValidPeriod($selectedYear, $selectedMonth)) {
  $selectedYear = (int)$now->format('Y');
  $selectedMonth = (int)$now->format('n');
}

$report = $config;
$warnings = [];
try {
  $productionKeys = (array)($config['api_facturas_compra']['clasificaciones']['produccion'] ?? []);
  $productionExpense = financeLoadPurchaseExpense((array)($config['api_facturas_compra'] ?? []), $selectedYear, $selectedMonth, 'produccion', $productionKeys);
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
  $financialExpense = financeLoadPurchaseExpense((array)($config['api_facturas_compra'] ?? []), $selectedYear, $selectedMonth, 'financiero', $financialKeys);
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
    $materialExpense = financeLoadPurchaseExpense(
      (array)($config['api_facturas_compra'] ?? []),
      $selectedYear,
      $selectedMonth,
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
      $departmentExpense = financeLoadPurchaseExpense(
        (array)($config['api_facturas_compra'] ?? []),
        $selectedYear,
        $selectedMonth,
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
