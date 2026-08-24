<?php

declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function energyWaterInvoiceCacheFile(array $sourceConfig): string
{
  $provider = max(0, (int)($sourceConfig['provider'] ?? 0));
  $version = max(1, (int)($sourceConfig['cache_version'] ?? 1));
  return sys_get_temp_dir() . '/reportes-direccion-energia/agua-' . $provider . '-v' . $version . '.json';
}

function energyWaterStoredMonths(array $receipts, string $company): array
{
  $months = [];
  foreach ($receipts as $receipt) {
    if (!is_array($receipt) || (string)($receipt['service_key'] ?? '') !== 'agua') continue;
    if (strcasecmp(trim((string)($receipt['company'] ?? '')), $company) !== 0) continue;
    $month = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) $months[$month] = true;
  }
  return $months;
}

function energyLoadWaterInvoices(array $sourceConfig, DateTimeZone $timezone): array
{
  $result = ['receipts' => [], 'warning' => '', 'cached' => false, 'consulted_at' => null, 'processed' => 0];
  $cacheFile = energyWaterInvoiceCacheFile($sourceConfig);
  $cacheTtl = max(60, (int)($sourceConfig['cache_ttl'] ?? 3600));
  if (is_file($cacheFile) && time() - (int)@filemtime($cacheFile) <= $cacheTtl) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && is_array($cached['receipts'] ?? null)) {
      $cached['cached'] = true;
      return array_merge($result, $cached);
    }
  }

  $baseUrl = rtrim(trim((string)($sourceConfig['url'] ?? '')), '/');
  $provider = max(0, (int)($sourceConfig['provider'] ?? 0));
  $company = trim((string)($sourceConfig['company'] ?? 'Progel')) ?: 'Progel';
  if ($baseUrl === '' || $provider < 1) {
    $result['warning'] = 'La fuente automática de Agua no está configurada.';
    return $result;
  }
  if (!function_exists('curl_init')) {
    $result['warning'] = 'La extensión cURL no está disponible para consultar las facturas de Agua.';
    return $result;
  }

  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') {
    $result['warning'] = 'No está configurada la autorización del API de Agua.';
    return $result;
  }

  $normalize = static function (string $value): string {
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return strtoupper(trim($ascii !== false ? $ascii : $value));
  };

  try {
    $query = array_merge((array)($sourceConfig['query'] ?? []), ['cve_prov' => $provider]);
    $ch = curl_init($baseUrl . '?' . http_build_query($query));
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => max(5, (int)($sourceConfig['timeout'] ?? 20)),
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response)) throw new RuntimeException($error !== '' ? $error : 'El API no respondió.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('El API respondió con HTTP ' . $status . '.');
    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true) throw new RuntimeException('El API devolvió una estructura inesperada.');

    $receiptsByMonth = [];
    foreach ((array)($payload['data'] ?? []) as $invoice) {
      if (!is_array($invoice)) continue;
      $invoiceProvider = is_numeric($invoice['proveedor']['clave'] ?? null) ? (int)$invoice['proveedor']['clave'] : 0;
      $statusLabel = $normalize((string)($invoice['estatus'] ?? ''));
      $invoiceDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($invoice['fecha'] ?? ''), 0, 10), $timezone);
      $subtotal = $invoice['importes']['subtotal'] ?? null;
      if ($invoiceProvider !== $provider || strpos($statusLabel, 'PAGAD') === false || !$invoiceDate instanceof DateTimeImmutable || !is_numeric($subtotal)) continue;

      $amount = (float)$subtotal;
      $currency = (string)($invoice['moneda']['clave'] ?? '1');
      $exchangeRate = is_numeric($invoice['moneda']['tipo_cambio'] ?? null) ? (float)$invoice['moneda']['tipo_cambio'] : 1.0;
      if ($currency !== '1' && $exchangeRate > 0) $amount *= $exchangeRate;
      if ($amount < 0) continue;

      $monthStart = $invoiceDate->modify('first day of this month');
      $monthKey = $monthStart->format('Y-m');
      if (!isset($receiptsByMonth[$monthKey])) {
        $receiptsByMonth[$monthKey] = [
          'service_key' => 'agua',
          'company' => $company,
          'receipt_date' => $invoiceDate->format('Y-m-d'),
          'period_start' => $monthStart->format('Y-m-d'),
          'period_end' => $monthStart->modify('last day of this month')->format('Y-m-d'),
          'reference' => '',
          'quantity' => null,
          'amount' => 0.0,
          'invoice_numbers' => [],
        ];
      }
      if ($invoiceDate->format('Y-m-d') > $receiptsByMonth[$monthKey]['receipt_date']) {
        $receiptsByMonth[$monthKey]['receipt_date'] = $invoiceDate->format('Y-m-d');
      }
      $receiptsByMonth[$monthKey]['amount'] += $amount;
      $invoiceNumber = trim((string)($invoice['numero_factura'] ?? $invoice['identificador']['no_facc'] ?? ''));
      if ($invoiceNumber !== '') $receiptsByMonth[$monthKey]['invoice_numbers'][] = $invoiceNumber;
      $result['processed']++;
    }

    foreach ($receiptsByMonth as &$receipt) {
      $numbers = array_values(array_unique((array)$receipt['invoice_numbers']));
      $receipt['amount'] = round((float)$receipt['amount'], 2);
      $receipt['reference'] = ($numbers === [] ? 'Factura de Agua' : 'Factura' . (count($numbers) > 1 ? 's ' : ' ') . implode(', ', $numbers)) . ' · Consumo pendiente';
      unset($receipt['invoice_numbers']);
    }
    unset($receipt);
    ksort($receiptsByMonth);
    $result['receipts'] = array_values($receiptsByMonth);
    $result['consulted_at'] = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');

    $directory = dirname($cacheFile);
    if (is_dir($directory) || @mkdir($directory, 0777, true)) @chmod($directory, 0777);
    if (is_dir($directory) && is_writable($directory)) {
      $temporary = @tempnam($directory, 'water_');
      $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($temporary !== false && $json !== false && @file_put_contents($temporary, $json, LOCK_EX) !== false && @rename($temporary, $cacheFile)) {
        @chmod($cacheFile, 0666);
      } elseif ($temporary !== false) {
        @unlink($temporary);
      }
    }
  } catch (Throwable $exception) {
    $result['warning'] = 'No fue posible consultar las facturas de Agua; se muestran los registros guardados.';
  }

  return $result;
}

function energySyncWaterInvoices(array $sourceConfig, DateTimeZone $timezone): array
{
  $company = trim((string)($sourceConfig['company'] ?? 'Progel')) ?: 'Progel';
  $storedMonths = energyWaterStoredMonths(energyLoadReceipts(), $company);
  $result = energyLoadWaterInvoices($sourceConfig, $timezone);
  $result['inserted'] = 0;
  $capturedNow = new DateTimeImmutable('now', $timezone);

  foreach ((array)($result['receipts'] ?? []) as $receipt) {
    if (!is_array($receipt)) continue;
    $month = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if ($month === '' || isset($storedMonths[$month])) continue;
    try {
      energySaveReceipt([
        'service_key' => 'agua',
        'company' => $company,
        'receipt_date' => (string)$receipt['receipt_date'],
        'period_start' => (string)$receipt['period_start'],
        'period_end' => (string)$receipt['period_end'],
        'reference' => (string)$receipt['reference'],
        'quantity' => null,
        'amount' => (float)$receipt['amount'],
        'production_kg' => null,
        'production_start' => '',
        'production_end' => '',
        'registered_at' => $capturedNow->format('Y-m-d H:i:s'),
        'updated_at' => null,
      ]);
      $storedMonths[$month] = true;
      $result['inserted']++;
    } catch (RuntimeException $exception) {
      if (strpos($exception->getMessage(), 'Ya existe un recibo') === false) throw $exception;
      $storedMonths[$month] = true;
    }
  }

  $result['receipts'] = [];
  return $result;
}
