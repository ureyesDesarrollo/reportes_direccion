<?php

declare(strict_types=1);

require_once __DIR__ . '/../../xmlreader.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/production.php';

function energyGasNaturalCacheFile(array $sourceConfig): string
{
  $provider = max(0, (int)($sourceConfig['provider'] ?? 0));
  $version = max(1, (int)($sourceConfig['cache_version'] ?? 1));
  return sys_get_temp_dir() . '/reportes-direccion-energia/gas-natural-' . $provider . '-v' . $version . '.json';
}

function energyGasNaturalXmlRoot(array $sourceConfig): string
{
  $environmentName = trim((string)($sourceConfig['xml_root_env'] ?? 'ENERGIA_GAS_XML_ROOT'));
  $environmentValue = $environmentName !== '' ? trim((string)getenv($environmentName)) : '';
  return rtrim($environmentValue !== '' ? $environmentValue : trim((string)($sourceConfig['xml_root'] ?? '')), '/\\');
}

function energyResolveGasNaturalXmlPath(string $xmlRoot, array $companyPaths, string $documentPath): ?string
{
  $root = realpath($xmlRoot);
  if ($root === false || !is_dir($root)) return null;

  $relativePath = str_replace('\\', '/', trim($documentPath));
  $relativePath = preg_replace('#^[A-Za-z]:/#', '', $relativePath) ?? $relativePath;
  $relativePath = ltrim($relativePath, '/');
  if ($relativePath === '' || strtolower((string)pathinfo($relativePath, PATHINFO_EXTENSION)) !== 'xml') return null;

  $segments = array_values(array_filter(explode('/', $relativePath), static fn(string $segment): bool => $segment !== ''));
  foreach ($segments as $segment) {
    if ($segment === '.' || $segment === '..' || strpos($segment, "\0") !== false) return null;
  }
  $safeRelativePath = implode(DIRECTORY_SEPARATOR, $segments);

  $candidateBases = [];
  foreach ($companyPaths as $companyPath) {
    $normalized = trim(str_replace('\\', '/', (string)$companyPath), '/');
    if ($normalized !== '' && strpos($normalized, '..') === false) $candidateBases[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
  }
  $candidateBases[] = $root;

  foreach (array_values(array_unique($candidateBases)) as $candidateBase) {
    $base = realpath($candidateBase);
    if ($base === false || !is_dir($base)) continue;
    $candidate = realpath($base . DIRECTORY_SEPARATOR . $safeRelativePath);
    if ($candidate === false || !is_file($candidate) || !is_readable($candidate)) continue;
    $basePrefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($candidate, $basePrefix, strlen($basePrefix)) !== 0) continue;
    return $candidate;
  }

  return null;
}

function energyGasNaturalStoredMonths(array $receipts, string $company): array
{
  $months = [];
  foreach ($receipts as $receipt) {
    if (!is_array($receipt) || (string)($receipt['service_key'] ?? '') !== 'gas_natural') continue;
    if (strcasecmp(trim((string)($receipt['company'] ?? '')), $company) !== 0) continue;
    $month = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) $months[$month] = true;
  }
  return $months;
}

function energyGasNaturalAllowedYears(array $sourceConfig): array
{
  $years = [];
  foreach ((array)($sourceConfig['years'] ?? []) as $year) {
    if (is_numeric($year) && (int)$year >= 2000 && (int)$year <= 2100) $years[(int)$year] = true;
  }
  return $years;
}

function energyFilterNewGasNaturalReceipts(array $receipts, array $existingMonths, string $latestStoredMonth = '', array $allowedYears = []): array
{
  return array_values(array_filter($receipts, static function ($receipt) use ($existingMonths, $latestStoredMonth, $allowedYears): bool {
    if (!is_array($receipt)) return false;
    $month = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    $year = (int)substr($month, 0, 4);
    return $month !== ''
      && ($allowedYears === [] || isset($allowedYears[$year]))
      && !isset($existingMonths[$month])
      && ($latestStoredMonth === '' || $month > $latestStoredMonth);
  }));
}

function energyLoadGasNaturalInvoices(array $sourceConfig, DateTimeZone $timezone, array $existingMonths = []): array
{
  $latestStoredMonth = $existingMonths !== [] ? max(array_keys($existingMonths)) : '';
  $backfill = !empty($sourceConfig['backfill']);
  $allowedYears = energyGasNaturalAllowedYears($sourceConfig);
  $result = [
    'receipts' => [],
    'warning' => '',
    'cached' => false,
    'consulted_at' => null,
    'processed' => 0,
    'missing_files' => 0,
    'invalid_files' => 0,
  ];
  $cacheFile = energyGasNaturalCacheFile($sourceConfig);
  $cacheTtl = max(60, (int)($sourceConfig['cache_ttl'] ?? 3600));
  if (is_file($cacheFile) && time() - (int)@filemtime($cacheFile) <= $cacheTtl) {
    $cached = json_decode((string)@file_get_contents($cacheFile), true);
    if (is_array($cached) && is_array($cached['receipts'] ?? null)) {
      $cached['cached'] = true;
      $cached['receipts'] = energyFilterNewGasNaturalReceipts((array)$cached['receipts'], $existingMonths, $backfill ? '' : $latestStoredMonth, $allowedYears);
      return array_merge($result, $cached);
    }
  }

  $baseUrl = rtrim(trim((string)($sourceConfig['url'] ?? '')), '/');
  $provider = max(0, (int)($sourceConfig['provider'] ?? 0));
  $company = trim((string)($sourceConfig['company'] ?? 'Progel')) ?: 'Progel';
  $calorificValue = is_numeric($sourceConfig['calorific_value_mj_m3'] ?? null)
    ? (float)$sourceConfig['calorific_value_mj_m3']
    : null;
  $xmlRoot = energyGasNaturalXmlRoot($sourceConfig);
  $companyPaths = array_values((array)($sourceConfig['company_paths'] ?? []));
  if ($baseUrl === '' || $provider < 1) {
    $result['warning'] = 'La fuente automática de gas natural no está configurada.';
    return $result;
  }
  $xmlRootAvailable = $xmlRoot !== '' && realpath($xmlRoot) !== false;
  if (!function_exists('curl_init')) {
    $result['warning'] = 'La extensión cURL no está disponible para consultar las facturas de gas natural.';
    return $result;
  }

  $salesConfig = require __DIR__ . '/../ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') {
    $result['warning'] = 'No está configurada la autorización del API de gas natural.';
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
      CURLOPT_TIMEOUT => max(5, (int)($sourceConfig['timeout'] ?? 25)),
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
    $monthOffset = (int)($sourceConfig['invoice_month_offset'] ?? 0);
    foreach ((array)($payload['data'] ?? []) as $invoice) {
      if (!is_array($invoice)) continue;
      $invoiceProvider = is_numeric($invoice['proveedor']['clave'] ?? null) ? (int)$invoice['proveedor']['clave'] : 0;
      $statusLabel = $normalize((string)($invoice['estatus'] ?? ''));
      $documentPath = trim((string)($invoice['path_doc'] ?? ''));
      $invoiceDate = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string)($invoice['fecha'] ?? ''), 0, 10), $timezone);
      if ($invoiceProvider !== $provider || strpos($statusLabel, 'PAGAD') === false || !$invoiceDate instanceof DateTimeImmutable || $documentPath === '') continue;

      $reportMonth = $invoiceDate->modify('first day of this month')->modify(($monthOffset >= 0 ? '+' : '') . $monthOffset . ' months');
      $monthKey = $reportMonth->format('Y-m');
      if ($allowedYears !== [] && !isset($allowedYears[(int)$reportMonth->format('Y')])) continue;
      if (isset($existingMonths[$monthKey]) || (!$backfill && $latestStoredMonth !== '' && $monthKey <= $latestStoredMonth)) continue;
      if (!$xmlRootAvailable) {
        $result['warning'] = 'Hay una factura nueva de gas natural, pero la carpeta de XML no está montada para el reporte.';
        break;
      }

      $resolvedPath = energyResolveGasNaturalXmlPath($xmlRoot, $companyPaths, $documentPath);
      if ($resolvedPath === null) {
        $result['missing_files']++;
        continue;
      }

      try {
        $gasInvoice = readGasNaturalInvoiceXml($resolvedPath, $calorificValue);
      } catch (Throwable $exception) {
        $result['invalid_files']++;
        continue;
      }

      $monthEnd = $reportMonth->modify('last day of this month');
      $invoiceNumber = trim((string)($invoice['numero_factura'] ?? $invoice['identificador']['no_facc'] ?? ''));
      if (!isset($receiptsByMonth[$monthKey])) {
        $receiptsByMonth[$monthKey] = [
          'id' => 0,
          'service_key' => 'gas_natural',
          'company' => $company,
          'receipt_date' => $monthOffset === 0 ? $invoiceDate->format('Y-m-d') : $monthEnd->format('Y-m-d'),
          'period_start' => $reportMonth->format('Y-m-d'),
          'period_end' => $monthEnd->format('Y-m-d'),
          'reference' => $invoiceNumber !== '' ? 'Factura ' . $invoiceNumber : 'Factura de gas natural',
          'quantity' => 0.0,
          'amount' => 0.0,
          'production_kg' => null,
          'production_start' => null,
          'production_end' => null,
          'registered_at' => null,
          'updated_at' => null,
          'cost_source' => 'api',
          'document_paths' => [],
          'invoice_dates' => [],
          'invoice_total' => 0.0,
          'invoice_numbers' => [],
          'estimated_consumption' => false,
        ];
      }
      $receiptsByMonth[$monthKey]['quantity'] += (float)$gasInvoice['consumo_m3'];
      $receiptsByMonth[$monthKey]['amount'] += (float)$gasInvoice['costo'];
      $receiptsByMonth[$monthKey]['invoice_total'] += is_numeric($gasInvoice['total_factura'] ?? null) ? (float)$gasInvoice['total_factura'] : 0.0;
      $receiptsByMonth[$monthKey]['document_paths'][] = $documentPath;
      $receiptsByMonth[$monthKey]['invoice_dates'][] = (string)($gasInvoice['fecha_factura'] ?? $invoiceDate->format('Y-m-d'));
      if ($invoiceNumber !== '') $receiptsByMonth[$monthKey]['invoice_numbers'][] = $invoiceNumber;
      if (!empty($gasInvoice['consumo_estimado'])) $receiptsByMonth[$monthKey]['estimated_consumption'] = true;
      $result['processed']++;
    }

    $receipts = array_values($receiptsByMonth);
    foreach ($receipts as &$receipt) {
      $numbers = array_values(array_unique((array)($receipt['invoice_numbers'] ?? [])));
      $receipt['reference'] = $numbers !== [] ? 'Factura' . (count($numbers) > 1 ? 's ' : ' ') . implode(', ', $numbers) : 'Factura de gas natural';
      if (!empty($receipt['estimated_consumption']) && $calorificValue !== null) {
        $receipt['reference'] .= ' · Consumo estimado con ' . rtrim(rtrim(number_format($calorificValue, 2, '.', ''), '0'), '.') . ' MJ/m³';
      }
    }
    unset($receipt);
    usort($receipts, static fn(array $left, array $right): int => strcmp((string)$left['receipt_date'], (string)$right['receipt_date']));
    $result['receipts'] = $receipts;
    $result['consulted_at'] = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    if ($result['warning'] === '' && ($result['missing_files'] > 0 || $result['invalid_files'] > 0)) {
      $result['warning'] = sprintf(
        'Gas natural: %d XML no encontrados y %d XML inválidos.',
        $result['missing_files'],
        $result['invalid_files']
      );
    }

    $directory = dirname($cacheFile);
    if (is_dir($directory) || @mkdir($directory, 0777, true)) @chmod($directory, 0777);
    if ($result['warning'] !== 'Hay una factura nueva de gas natural, pero la carpeta de XML no está montada para el reporte.' && is_dir($directory) && is_writable($directory)) {
      $temporary = @tempnam($directory, 'gas_');
      $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($temporary !== false && $json !== false && @file_put_contents($temporary, $json, LOCK_EX) !== false && @rename($temporary, $cacheFile)) {
        @chmod($cacheFile, 0666);
      } elseif ($temporary !== false) {
        @unlink($temporary);
      }
    }
  } catch (Throwable $exception) {
    $result['warning'] = 'No fue posible consultar las facturas de gas natural; se muestran los registros guardados.';
  }

  return $result;
}

function energySyncGasNaturalInvoices(array $sourceConfig, array $productionDatabase, DateTimeZone $timezone): array
{
  $company = trim((string)($sourceConfig['company'] ?? 'Progel')) ?: 'Progel';
  $storedReceipts = energyLoadReceipts();
  $storedMonths = energyGasNaturalStoredMonths($storedReceipts, $company);
  $allowedYears = energyGasNaturalAllowedYears($sourceConfig);
  $result = energyLoadGasNaturalInvoices($sourceConfig, $timezone, $storedMonths);
  $result['inserted'] = 0;
  $capturedNow = new DateTimeImmutable('now', $timezone);

  foreach ((array)($result['receipts'] ?? []) as $receipt) {
    if (!is_array($receipt)) continue;
    $month = substr((string)($receipt['receipt_date'] ?? ''), 0, 7);
    if ($allowedYears !== [] && !isset($allowedYears[(int)substr($month, 0, 4)])) continue;
    if ($month === '' || isset($storedMonths[$month])) continue;
    $production = energyLoadProductionKgDates(
      (string)($receipt['period_start'] ?? ''),
      (string)($receipt['period_end'] ?? ''),
      $productionDatabase,
      $timezone
    );
    try {
      energySaveReceipt([
        'service_key' => 'gas_natural',
        'company' => $company,
        'receipt_date' => (string)$receipt['receipt_date'],
        'period_start' => (string)$receipt['period_start'],
        'period_end' => (string)$receipt['period_end'],
        'reference' => (string)($receipt['reference'] ?? 'Factura de gas natural'),
        'quantity' => (float)$receipt['quantity'],
        'amount' => (float)$receipt['amount'],
        'production_kg' => $production['kg'] ?? null,
        'production_start' => $production['inicio'] ?? '',
        'production_end' => $production['fin'] ?? '',
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
