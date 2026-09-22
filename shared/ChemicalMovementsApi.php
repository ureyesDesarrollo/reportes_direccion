<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function chemicalMovementsApiQuantity(float $quantity, string $product, string $unit, array $conversions): float
{
  $product = trim($product);
  $unit = strtoupper(trim($unit));
  foreach ((array)($conversions[$product] ?? []) as $configuredUnit => $factor) {
    if ($unit === strtoupper(trim((string)$configuredUnit)) && is_numeric($factor) && (float)$factor > 0) {
      return $quantity * (float)$factor;
    }
  }
  if (in_array($unit, ['G', 'GR', 'GRAMO', 'GRAMOS'], true)) return $quantity / 1000;
  return $quantity;
}

function chemicalMovementsApiCostQuantity(float $quantity, string $product, string $unit, array $conversions): float
{
  $product = trim($product);
  $unit = strtoupper(trim($unit));
  foreach ((array)($conversions[$product] ?? []) as $configuredUnit => $factor) {
    if ($unit === strtoupper(trim((string)$configuredUnit)) && is_numeric($factor) && (float)$factor > 0) return $quantity;
  }
  if (in_array($unit, ['G', 'GR', 'GRAMO', 'GRAMOS'], true)) return $quantity / 1000;
  return $quantity;
}

function movementsApiNormalizeUnit(string $unit): string
{
  $unit = strtoupper(trim($unit));
  if (in_array($unit, ['KG', 'KGS', 'KILO', 'KILOS'], true)) return 'KG';
  if (in_array($unit, ['G', 'GR', 'GRAMO', 'GRAMOS'], true)) return 'G';
  if (in_array($unit, ['PZA', 'PIEZA', 'PIEZAS'], true)) return 'PZA';
  if (in_array($unit, ['ROLLO', 'ROLLOS'], true)) return 'ROLLO';
  if (in_array($unit, ['CAJA', 'CAJAS'], true)) return 'CAJA';
  if (in_array($unit, ['MILLA', 'MILL', 'MILLES', 'MILLARES'], true)) return 'MILLA';
  return $unit;
}

function movementsApiStringList($value): array
{
  $values = is_array($value) ? $value : [$value];
  $result = [];
  foreach ($values as $item) {
    $item = strtoupper(trim((string)$item));
    if ($item !== '' && !in_array($item, $result, true)) $result[] = $item;
  }
  return $result;
}

function chemicalMovementsApiFetchYear(array $apiConfig, int $year, array $conversions, DateTimeZone $timezone): array
{
  $version = max(1, (int)($apiConfig['cache_version'] ?? 1));
  $cacheKey = 'chemical_movements_api_' . md5(serialize([
    $apiConfig['url'] ?? '',
    $apiConfig['query'] ?? [],
    $apiConfig['tipo_mov'] ?? 'S',
    $apiConfig['cve_mov'] ?? '17',
    $apiConfig['lugares'] ?? ($apiConfig['lugar'] ?? null),
    $apiConfig['productos_a_ignorar'] ?? ['DIES01'],
    $year,
    $version,
    $conversions,
  ]));
  $cached = getCache($cacheKey);
  if (is_array($cached) && is_array($cached['movements'] ?? null)) {
    $cached['cached'] = true;
    return $cached;
  }

  $result = ['movements' => [], 'warning' => '', 'cached' => false, 'year' => $year];
  $url = trim((string)($apiConfig['url'] ?? ''));
  if ($url === '' || !function_exists('curl_init')) {
    $result['warning'] = 'La fuente API de movimientos no está disponible.';
    return $result;
  }

  $salesConfig = require __DIR__ . '/../reports/ventas/config.php';
  $apiKey = trim((string)($salesConfig['pedidos_api']['api_key'] ?? ''));
  if ($apiKey === '') {
    $result['warning'] = 'No está configurada la autorización del API de movimientos.';
    return $result;
  }

  try {
    $query = array_merge((array)($apiConfig['query'] ?? []), ['anio' => $year]);
    $requestUrl = $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    $ch = curl_init($requestUrl);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => max(10, (int)($apiConfig['timeout'] ?? 30)),
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response)) throw new RuntimeException($error !== '' ? $error : 'El API no respondió.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('El API respondió con HTTP ' . $status . '.');
    $payload = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['data'] ?? null)) {
      throw new RuntimeException('El API devolvió una estructura inesperada.');
    }

    $expectedTypes = movementsApiStringList($apiConfig['tipo_mov'] ?? 'S');
    $expectedKeys = movementsApiStringList($apiConfig['cve_mov'] ?? '17');
    $expectedLocations = movementsApiStringList(
      $apiConfig['lugares']
        ?? $apiConfig['lugar']
        ?? ($apiConfig['query']['lugar'] ?? 'QUIMICOS')
    );
    $ignoredProducts = movementsApiStringList($apiConfig['productos_a_ignorar'] ?? ['DIES01']);
    $movements = [];
    foreach ($payload['data'] as $item) {
      if (!is_array($item)) continue;
      $type = strtoupper(trim((string)($item['movimiento']['tipo_mov'] ?? '')));
      $movementKey = trim((string)($item['movimiento']['cve_mov'] ?? ''));
      $location = strtoupper(trim((string)($item['movimiento']['lugar'] ?? '')));
      $product = trim((string)($item['producto']['clave'] ?? ''));
      $dateText = substr((string)($item['fecha']['f_mov'] ?? ''), 0, 10);
      $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText, $timezone);
      $rawQuantity = $item['consumo']['cantidad_movimiento'] ?? $item['consumo']['cantidad_consumida'] ?? null;
      if ($expectedTypes !== [] && !in_array($type, $expectedTypes, true)) continue;
      if ($expectedKeys !== [] && !in_array(strtoupper($movementKey), $expectedKeys, true)) continue;
      if ($expectedLocations !== [] && !in_array($location, $expectedLocations, true)) continue;
      if ($product === '' || in_array(strtoupper($product), $ignoredProducts, true)) continue;
      if (!$date instanceof DateTimeImmutable || !is_numeric($rawQuantity)) continue;

      $unit = strtoupper(trim((string)($item['producto']['unidad_usuario'] ?? '')));
      $quantity = (float)$rawQuantity;
      $cost = is_numeric($item['costos']['costo_entrada'] ?? null) ? (float)$item['costos']['costo_entrada'] : 0.0;
      $weekStart = $date->modify('monday this week');
      $movements[] = [
        'periodo' => (int)$date->format('oW'),
        'semana_iso' => $date->format('o-\SW'),
        'semana_inicio' => $weekStart->format('Y-m-d'),
        'semana_fin' => $weekStart->modify('+6 days')->format('Y-m-d'),
        'anio_iso' => (int)$date->format('o'),
        'cve_prod' => $product,
        'desc_prod' => trim((string)($item['producto']['descripcion'] ?? $item['producto']['nombre'] ?? '')),
        'unidad' => $unit,
        'unidad_normalizada' => movementsApiNormalizeUnit($unit),
        'cantidad_original' => $quantity,
        'consumo_kg' => chemicalMovementsApiQuantity($quantity, $product, $unit, $conversions),
        'costo_entrada' => $cost,
        'impacto_economico' => $cost * chemicalMovementsApiCostQuantity($quantity, $product, $unit, $conversions),
      ];
    }

    $result['movements'] = $movements;
    $result['received'] = count($payload['data']);
    $result['accepted'] = count($movements);
    $result['consulted_at'] = (new DateTimeImmutable('now', $timezone))->format('Y-m-d H:i:s');
    setCache($cacheKey, $result, max(300, (int)($apiConfig['cache_ttl'] ?? 3600)));
  } catch (Throwable $exception) {
    $result['warning'] = 'No fue posible consultar los movimientos desde el API.';
  }

  return $result;
}

function aggregateMovementsApiWeekly(array $movements, array $products = [], bool $groupByUnit = false): array
{
  $allowedProducts = array_fill_keys(array_map('strval', $products), true);
  $groups = [];
  foreach ($movements as $movement) {
    if (!is_array($movement)) continue;
    $product = trim((string)($movement['cve_prod'] ?? ''));
    if ($product === '' || ($allowedProducts !== [] && !isset($allowedProducts[$product]))) continue;
    $unit = (string)($movement['unidad_normalizada'] ?? movementsApiNormalizeUnit((string)($movement['unidad'] ?? '')));
    $key = (string)($movement['periodo'] ?? '') . '|' . $product . ($groupByUnit ? '|' . $unit : '');
    if (!isset($groups[$key])) {
      $groups[$key] = [
        'periodo' => (int)($movement['periodo'] ?? 0),
        'semana_iso' => (string)($movement['semana_iso'] ?? ''),
        'semana_inicio' => (string)($movement['semana_inicio'] ?? ''),
        'semana_fin' => (string)($movement['semana_fin'] ?? ''),
        'cve_prod' => $product,
        'desc_prod' => (string)($movement['desc_prod'] ?? ''),
        'unidad_original' => (string)($movement['unidad'] ?? ''),
        'unidad_normalizada' => $unit,
        'cantidad' => 0.0,
        'refaccion_cantidad' => 0.0,
        'impacto_economico' => 0.0,
      ];
    }
    $quantity = (float)($movement['cantidad_original'] ?? 0.0);
    $groups[$key]['cantidad'] += $quantity;
    $groups[$key]['refaccion_cantidad'] += $quantity;
    $groups[$key]['impacto_economico'] += (float)($movement['impacto_economico'] ?? 0.0);
  }
  foreach ($groups as &$group) {
    $quantity = (float)$group['cantidad'];
    $group['costo_promedio'] = $quantity != 0.0
      ? (float)$group['impacto_economico'] / $quantity
      : 0.0;
  }
  unset($group);
  $rows = array_values($groups);
  usort($rows, static fn(array $left, array $right): int => [
    $left['periodo'], $left['cve_prod'], $left['unidad_normalizada']
  ] <=> [
    $right['periodo'], $right['cve_prod'], $right['unidad_normalizada']
  ]);
  return $rows;
}

function loadChemicalMovementsApi(array $apiConfig, array $years, array $conversions, DateTimeZone $timezone): array
{
  $result = ['movements' => [], 'warnings' => [], 'cached' => true];
  foreach (array_values(array_unique(array_map('intval', $years))) as $year) {
    if ($year < 2000 || $year > 2100) continue;
    $yearResult = chemicalMovementsApiFetchYear($apiConfig, $year, $conversions, $timezone);
    $result['movements'] = array_merge($result['movements'], (array)($yearResult['movements'] ?? []));
    if (trim((string)($yearResult['warning'] ?? '')) !== '') $result['warnings'][] = (string)$yearResult['warning'];
    if (empty($yearResult['cached'])) $result['cached'] = false;
  }
  $result['warnings'] = array_values(array_unique($result['warnings']));
  return $result;
}

function aggregateChemicalMovementsWeekly(array $movements, array $products = []): array
{
  $allowedProducts = array_fill_keys(array_map('strval', $products), true);
  $groups = [];
  foreach ($movements as $movement) {
    if (!is_array($movement)) continue;
    $product = (string)($movement['cve_prod'] ?? '');
    if ($allowedProducts !== [] && !isset($allowedProducts[$product])) continue;
    $key = (string)($movement['periodo'] ?? '') . '|' . $product;
    if (!isset($groups[$key])) {
      $groups[$key] = [
        'periodo' => (int)($movement['periodo'] ?? 0),
        'semana_iso' => (string)($movement['semana_iso'] ?? ''),
        'semana_inicio' => (string)($movement['semana_inicio'] ?? ''),
        'semana_fin' => (string)($movement['semana_fin'] ?? ''),
        'cve_prod' => $product,
        'desc_prod' => (string)($movement['desc_prod'] ?? ''),
        'quimicos_kg' => 0.0,
        'consumo_kg' => 0.0,
        'impacto_economico' => 0.0,
        'costo_suma' => 0.0,
        'costo_conteo' => 0,
      ];
    }
    $consumption = (float)($movement['consumo_kg'] ?? 0.0);
    $impact = (float)($movement['impacto_economico'] ?? 0.0);
    $groups[$key]['quimicos_kg'] += $consumption;
    $groups[$key]['consumo_kg'] += $consumption;
    $groups[$key]['impacto_economico'] += $impact;
    if (is_numeric($movement['costo_entrada'] ?? null)) {
      $groups[$key]['costo_suma'] += (float)$movement['costo_entrada'];
      $groups[$key]['costo_conteo']++;
    }
  }

  foreach ($groups as &$group) {
    $group['costo_promedio'] = abs((float)$group['consumo_kg']) > 0.0000001
      ? (float)$group['impacto_economico'] / (float)$group['consumo_kg']
      : ((int)$group['costo_conteo'] > 0 ? (float)$group['costo_suma'] / (int)$group['costo_conteo'] : 0.0);
    $group['costo_ponderado'] = $group['costo_promedio'];
    unset($group['costo_suma'], $group['costo_conteo']);
  }
  unset($group);
  $rows = array_values($groups);
  usort($rows, static fn(array $left, array $right): int => [$left['periodo'], $left['cve_prod']] <=> [$right['periodo'], $right['cve_prod']]);
  return $rows;
}
