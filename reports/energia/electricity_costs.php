<?php

declare(strict_types=1);

function energyElectricityCostCacheFile(array $apiConfig): string
{
  $version = max(1, (int)($apiConfig['cache_version'] ?? 1));
  $provider = max(0, (int)($apiConfig['provider'] ?? 0));
  return sys_get_temp_dir() . '/reportes-direccion-energia/electricidad-' . $provider . '-v' . $version . '.json';
}

function energyLoadElectricityCosts(array $apiConfig, DateTimeZone $timezone): array
{
  $result = ['months' => [], 'warning' => '', 'cached' => false, 'consulted_at' => null];
  $cacheFile = energyElectricityCostCacheFile($apiConfig);
  $cacheTtl = max(60, (int)($apiConfig['cache_ttl'] ?? 3600));
  if (is_file($cacheFile) && time() - (int)@filemtime($cacheFile) <= $cacheTtl) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && is_array($cached['months'] ?? null)) {
      $cached['cached'] = true;
      return array_merge($result, $cached);
    }
  }

  $baseUrl = rtrim(trim((string)($apiConfig['url'] ?? '')), '/');
  $provider = max(0, (int)($apiConfig['provider'] ?? 0));
  $detailSegment = trim((string)($apiConfig['detail_segment'] ?? 'MAT'));
  if ($baseUrl === '' || $provider < 1 || preg_match('/^[A-Za-z0-9_-]+$/', $detailSegment) !== 1) {
    $result['warning'] = 'La fuente automática del costo eléctrico no está configurada.';
    return $result;
  }
  if (!function_exists('curl_init')) {
    $result['warning'] = 'La extensión cURL no está disponible para consultar el costo eléctrico.';
    return $result;
  }

  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') {
    $result['warning'] = 'No está configurada la autorización del API de costos eléctricos.';
    return $result;
  }

  $requestJson = static function (string $url) use ($apiKey, $apiConfig): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => max(5, (int)($apiConfig['timeout'] ?? 20)),
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response)) throw new RuntimeException($error !== '' ? $error : 'El API no respondió.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('El API respondió con HTTP ' . $status . '.');
    try {
      $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
      throw new RuntimeException('El API devolvió una respuesta JSON inválida.');
    }
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
      throw new RuntimeException('El API devolvió una estructura inesperada.');
    }
    return $payload;
  };

  $normalize = static function (string $value): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return strtoupper(trim($ascii !== false ? $ascii : $value));
  };

  try {
    $summaryQuery = array_merge((array)($apiConfig['query'] ?? []), ['cve_prov' => $provider]);
    $summary = $requestJson($baseUrl . '?' . http_build_query($summaryQuery));
    $months = [];
    foreach ((array)($summary['data'] ?? []) as $invoice) {
      if (!is_array($invoice)) continue;
      $invoiceProvider = is_numeric($invoice['proveedor']['clave'] ?? null) ? (int)$invoice['proveedor']['clave'] : 0;
      $status = $normalize((string)($invoice['estatus'] ?? ''));
      $paymentDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($invoice['fecha'] ?? ''), 0, 10), $timezone);
      $invoiceNumber = trim((string)($invoice['numero_factura'] ?? $invoice['identificador']['no_facc'] ?? ''));
      if ($invoiceProvider !== $provider || strpos($status, 'PAGAD') === false || !$paymentDate instanceof DateTimeImmutable || $invoiceNumber === '') continue;

      $detailUrl = $baseUrl . '/' . rawurlencode($detailSegment) . '/' . $provider . '/' . rawurlencode($invoiceNumber);
      $detailQuery = (array)($apiConfig['detail_query'] ?? $apiConfig['query'] ?? []);
      if ($detailQuery !== []) $detailUrl .= '?' . http_build_query($detailQuery);
      $detail = $requestJson($detailUrl);
      $electricitySubtotal = 0.0;
      $hasDap = false;
      foreach ((array)($detail['data']['partidas'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $description = $normalize((string)($item['producto']['descripcion'] ?? $item['descripcion'] ?? ''));
        $subtotal = $item['importes']['subtotal'] ?? null;
        if ($description === 'DAP') $hasDap = true;
        if (strpos($description, 'ENERGIA ELECTRICA') !== false && is_numeric($subtotal)) {
          $electricitySubtotal += (float)$subtotal;
        }
      }
      if ($electricitySubtotal <= 0 || !$hasDap) continue;

      $currency = (string)($invoice['moneda']['clave'] ?? '1');
      $exchangeRate = is_numeric($invoice['moneda']['tipo_cambio'] ?? null) ? (float)$invoice['moneda']['tipo_cambio'] : 1.0;
      if ($exchangeRate <= 0) $exchangeRate = 1.0;
      if ($currency !== '1') $electricitySubtotal *= $exchangeRate;

      $minimumInvoiceAmount = max(0.0, (float)($apiConfig['minimum_invoice_amount'] ?? 0));
      if ($electricitySubtotal < $minimumInvoiceAmount) continue;

      $offset = (int)($apiConfig['payment_month_offset'] ?? -1);
      $consumptionMonth = $paymentDate->modify('first day of this month')->modify(($offset >= 0 ? '+' : '') . $offset . ' months');
      $monthKey = $consumptionMonth->format('Y-m');
      if (!isset($months[$monthKey])) {
        $months[$monthKey] = ['amount' => 0.0, 'invoices' => 0, 'payment_dates' => []];
      }
      $months[$monthKey]['amount'] += $electricitySubtotal;
      $months[$monthKey]['invoices']++;
      $months[$monthKey]['payment_dates'][] = $paymentDate->format('Y-m-d');
    }

    ksort($months);
    foreach ($months as &$month) {
      $month['amount'] = round((float)$month['amount'], 2);
      $month['payment_dates'] = array_values(array_unique((array)$month['payment_dates']));
    }
    unset($month);
    $result['months'] = $months;
    $result['consulted_at'] = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');

    $directory = dirname($cacheFile);
    if (is_dir($directory) || @mkdir($directory, 0777, true)) {
      @chmod($directory, 0777);
    }
    if (is_dir($directory) && is_writable($directory)) {
      $temporary = @tempnam($directory, 'electricity_');
      $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($temporary !== false && $json !== false && @file_put_contents($temporary, $json, LOCK_EX) !== false) {
        if (@rename($temporary, $cacheFile)) {
          @chmod($cacheFile, 0666);
        } else {
          @unlink($temporary);
        }
      } elseif ($temporary !== false) {
        @unlink($temporary);
      }
    }
  } catch (Throwable $exception) {
    $result['warning'] = 'No fue posible actualizar el costo eléctrico desde el API; se muestran los importes guardados.';
  }

  return $result;
}

function energyMergeElectricityCosts(array $receipts, array $costs, DateTimeZone $timezone, string $company = ''): array
{
  $months = (array)($costs['months'] ?? []);
  if ($months === []) return $receipts;

  $monthsWithCapturedCost = [];
  foreach ($receipts as $receipt) {
    if (!is_array($receipt) || (string)($receipt['service_key'] ?? '') !== 'electricidad') continue;
    if ($company !== '' && strcasecmp(trim((string)($receipt['company'] ?? '')), $company) !== 0) continue;
    $monthKey = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if (isset($months[$monthKey]) && is_numeric($receipt['amount'] ?? null) && (float)$receipt['amount'] > 0) {
      $monthsWithCapturedCost[$monthKey] = true;
    }
  }

  $monthsWithAutomaticCost = [];
  foreach ($receipts as &$receipt) {
    if (!is_array($receipt) || (string)($receipt['service_key'] ?? '') !== 'electricidad') continue;
    if ($company !== '' && strcasecmp(trim((string)($receipt['company'] ?? '')), $company) !== 0) continue;
    $monthKey = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if (!isset($months[$monthKey])) continue;
    if (is_numeric($receipt['amount'] ?? null) && (float)$receipt['amount'] > 0) {
      $receipt['cost_source'] = 'captured';
    } elseif (isset($monthsWithCapturedCost[$monthKey])) {
      $receipt['amount'] = null;
      $receipt['cost_source'] = 'consumption';
    } elseif (!isset($monthsWithAutomaticCost[$monthKey])) {
      $receipt['amount'] = (float)($months[$monthKey]['amount'] ?? 0.0);
      $receipt['cost_source'] = 'api';
      $receipt['cost_payment_dates'] = (array)($months[$monthKey]['payment_dates'] ?? []);
      $monthsWithAutomaticCost[$monthKey] = true;
    } else {
      $receipt['amount'] = null;
      $receipt['cost_source'] = 'consumption';
    }
  }
  unset($receipt);

  foreach ($months as $monthKey => $cost) {
    if (isset($monthsWithCapturedCost[$monthKey]) || isset($monthsWithAutomaticCost[$monthKey]) || preg_match('/^(\d{4})-(\d{2})$/', (string)$monthKey, $matches) !== 1) continue;
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $monthKey . '-01', $timezone);
    if (!$monthStart instanceof DateTimeImmutable) continue;
    $monthEnd = $monthStart->modify('last day of this month');
    $receipts[] = [
      'id' => 0,
      'service_key' => 'electricidad',
      'company' => $company !== '' ? $company : 'Costo mensual API',
      'receipt_date' => $monthEnd->format('Y-m-d'),
      'period_start' => $monthStart->format('Y-m-d'),
      'period_end' => $monthEnd->format('Y-m-d'),
      'reference' => 'Costo automático sin IVA y sin DAP',
      'quantity' => null,
      'amount' => (float)($cost['amount'] ?? 0.0),
      'production_kg' => null,
      'production_start' => null,
      'production_end' => null,
      'registered_at' => null,
      'updated_at' => null,
      'cost_source' => 'api',
      'cost_payment_dates' => (array)($cost['payment_dates'] ?? []),
    ];
  }

  return $receipts;
}
