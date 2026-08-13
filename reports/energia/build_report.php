<?php

declare(strict_types=1);

function energyMetricCatalog(array $config): array
{
  $catalog = [];
  foreach ((array)($config['consumos'] ?? []) as $key => $metric) {
    $catalog[$key] = array_merge($metric, [
      'key' => $key,
      'group' => 'Consumos',
      'quantity_key' => 'total',
      'money_key' => 'valor',
      'unit' => (string)($metric['total_unit'] ?? ''),
      'ratio_unit' => (string)($metric['unit'] ?? ''),
    ]);
  }
  foreach ((array)($config['recuperaciones'] ?? []) as $key => $metric) {
    $catalog[$key] = array_merge($metric, [
      'key' => $key,
      'group' => 'Recuperación',
      'quantity_key' => 'm3',
      'money_key' => 'valor',
    ]);
  }
  foreach ((array)($config['generacion'] ?? []) as $key => $metric) {
    $catalog[$key] = array_merge($metric, [
      'key' => $key,
      'group' => 'Generación',
      'quantity_key' => 'kw',
      'money_key' => 'valor',
    ]);
  }
  return $catalog;
}

function energyMetricRecord(array $record, array $metric): array
{
  $group = (string)($metric['group'] ?? '');
  $key = (string)($metric['key'] ?? '');
  if ($group === 'Consumos') $source = (array)($record['consumos'][$key] ?? []);
  elseif ($group === 'Recuperación') $source = (array)($record['recuperaciones'][$key] ?? []);
  else $source = (array)($record['generacion'][$key] ?? []);

  $quantity = $source[(string)($metric['quantity_key'] ?? '')] ?? null;
  $money = $source[(string)($metric['money_key'] ?? '')] ?? null;
  return [
    'quantity' => is_numeric($quantity) ? (float)$quantity : null,
    'money' => is_numeric($money) ? (float)$money : null,
  ];
}

function energyBuildAnnualMetric(array $records, array $metric, int $selectedYear, DateTimeZone $timezone): array
{
  $months = [];
  for ($month = 1; $month <= 12; $month++) {
    $months[$month] = ['quantity' => 0.0, 'money' => 0.0, 'production' => 0.0, 'records' => [], 'has_quantity' => false, 'has_money' => false];
  }

  foreach ($records as $recordKey => $record) {
    if (!is_array($record) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$recordKey, $matches) !== 1) continue;
    $isoYear = (int)$matches[1];
    $week = (int)$matches[2];
    if (!energyValidWeek($isoYear, $week)) continue;
    $weekStart = (new DateTimeImmutable('now', $timezone))->setISODate($isoYear, $week, 1)->setTime(7, 0);
    if ((int)$weekStart->format('Y') !== $selectedYear) continue;

    $month = (int)$weekStart->format('n');
    $metricRecord = energyMetricRecord($record, $metric);
    $production = is_numeric($record['produccion']['kg'] ?? null) ? (float)$record['produccion']['kg'] : null;
    if ($metricRecord['quantity'] === null && $metricRecord['money'] === null) continue;

    if ($metricRecord['quantity'] !== null) {
      $months[$month]['quantity'] += $metricRecord['quantity'];
      $months[$month]['has_quantity'] = true;
      if ($production !== null && $production > 0) $months[$month]['production'] += $production;
    }
    if ($metricRecord['money'] !== null) {
      $months[$month]['money'] += $metricRecord['money'];
      $months[$month]['has_money'] = true;
    }
    $months[$month]['records'][] = [
      'key' => (string)$recordKey,
      'week' => $week,
      'date' => $weekStart,
      'quantity' => $metricRecord['quantity'],
      'money' => $metricRecord['money'],
      'production' => $production,
    ];
  }

  $annualQuantity = 0.0;
  $annualMoney = 0.0;
  $annualProduction = 0.0;
  $hasQuantity = false;
  $hasMoney = false;
  foreach ($months as &$month) {
    $month['ratio'] = $month['has_quantity'] && $month['production'] > 0 ? $month['quantity'] / $month['production'] : null;
    if ($month['has_quantity']) {
      $annualQuantity += $month['quantity'];
      $annualProduction += $month['production'];
      $hasQuantity = true;
    }
    if ($month['has_money']) {
      $annualMoney += $month['money'];
      $hasMoney = true;
    }
  }
  unset($month);

  return [
    'months' => $months,
    'quantity' => $hasQuantity ? $annualQuantity : null,
    'money' => $hasMoney ? $annualMoney : null,
    'production' => $annualProduction > 0 ? $annualProduction : null,
    'ratio' => $hasQuantity && $annualProduction > 0 ? $annualQuantity / $annualProduction : null,
  ];
}
