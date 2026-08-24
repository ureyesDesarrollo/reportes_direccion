<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$appConfig = $appConfig ?? require __DIR__ . '/../../config/app.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../shared/helpers.php';

$sourceCache = [];
$loadSource = static function (string $directory) use ($appConfig, $dbConfig, &$sourceCache): array {
  if (isset($sourceCache[$directory])) {
    return $sourceCache[$directory];
  }
  $sourceConfig = require __DIR__ . '/../' . $directory . '/config.php';
  $buildSource = static function () use ($appConfig, $dbConfig, $sourceConfig, $directory): array {
      $config = $sourceConfig;
      return require __DIR__ . '/../' . $directory . '/build_report.php';
  };
  try {
    $sourceCache[$directory] = $buildSource();
  } catch (Throwable $firstError) {
    usleep(150000);
    clearstatcache(true);
    $sourceCache[$directory] = $buildSource();
  }
  return $sourceCache[$directory];
};

$sourceKeys = static function (string $type): array {
  if ($type === 'quimicos') {
    return [
      'labels' => 'quimicosEtiquetas',
      'cost_previous' => 'costoPromedioAnioAnterior',
      'cost_current' => 'costoPromedioAnioActual',
      'impact_previous' => 'impactoEconomicoAnioAnterior',
      'impact_current' => 'impactoEconomicoAnioActual',
      'consumption_previous' => 'consumoQuimicoAnioAnterior',
      'consumption_current' => 'consumoQuimicoAnioActual',
      'matrix' => 'matrizQuimicos',
      'impact_matrix' => 'matrizImpactoEconomicoQuimicos',
    ];
  }

  if ($type === 'empaques') {
    return [
      'labels' => 'empaquesEtiquetas',
      'cost_previous' => 'costoPromedioEmpaqueAnioAnterior',
      'cost_current' => 'costoPromedioEmpaqueAnioActual',
      'impact_previous' => 'impactoEconomicoEmpaqueAnioAnterior',
      'impact_current' => 'impactoEconomicoEmpaqueAnioActual',
      'consumption_previous' => 'cantidadEmpaqueAnioAnterior',
      'consumption_current' => 'cantidadEmpaqueAnioActual',
      'matrix' => 'matrizEmpaques',
      'impact_matrix' => 'matrizImpactoEconomicoEmpaques',
    ];
  }

  return [
    'labels' => 'refaccionesEtiquetas',
    'cost_previous' => 'costoPromedioAnioAnterior',
    'cost_current' => 'costoPromedioAnioActual',
    'impact_previous' => 'impactoEconomicoAnioAnterior',
    'impact_current' => 'impactoEconomicoAnioActual',
    'consumption_previous' => 'consumoRefaccionAnioAnterior',
    'consumption_current' => 'consumoRefaccionAnioActual',
    'matrix' => 'matrizRefacciones',
    'impact_matrix' => '',
  ];
};

$normalizeLabel = static function ($value, string $fallback): string {
  $label = is_scalar($value) ? trim((string)$value) : '';
  if ($label !== '') {
    return $label;
  }

  return str_replace('|', ' · ', $fallback);
};

$buildTopPriceChanges = static function (array $report, array $keys) use ($normalizeLabel): array {
  $previous = (array)($report[$keys['cost_previous']] ?? []);
  $current = (array)($report[$keys['cost_current']] ?? []);
  $labels = (array)($report[$keys['labels']] ?? []);
  $items = [];

  foreach (array_unique(array_merge(array_keys($previous), array_keys($current))) as $key) {
    $before = $previous[$key] ?? null;
    $now = $current[$key] ?? null;
    if (!is_numeric($before) || !is_numeric($now) || (float)$before <= 0 || (float)$now <= 0) {
      continue;
    }

    $variation = (((float)$now - (float)$before) / (float)$before) * 100;
    if (abs($variation) < 0.000001) {
      continue;
    }

    $items[] = [
      'key' => (string)$key,
      'label' => $normalizeLabel($labels[$key] ?? null, (string)$key),
      'previous' => (float)$before,
      'current' => (float)$now,
      'variation' => $variation,
    ];
  }

  $increases = array_values(array_filter($items, static fn(array $item): bool => $item['variation'] > 0));
  $decreases = array_values(array_filter($items, static fn(array $item): bool => $item['variation'] < 0));
  usort($increases, static fn(array $a, array $b): int => $b['variation'] <=> $a['variation']);
  usort($decreases, static fn(array $a, array $b): int => $a['variation'] <=> $b['variation']);

  return [
    'aumentos' => array_slice($increases, 0, 5),
    'disminuciones' => array_slice($decreases, 0, 5),
  ];
};

$buildTopPerProduction = static function (
  array $report,
  array $keys,
  string $previousKey,
  string $currentKey,
  float $productionPrevious,
  float $productionCurrent
) use ($buildTopPriceChanges): array {
  if ($productionPrevious <= 0 || $productionCurrent <= 0) {
    return ['aumentos' => [], 'disminuciones' => []];
  }

  $normalized = $report;
  $normalizedPrevious = [];
  foreach ((array)($report[$previousKey] ?? []) as $itemKey => $value) {
    if (is_numeric($value)) $normalizedPrevious[$itemKey] = (float)$value / $productionPrevious;
  }
  $normalizedCurrent = [];
  foreach ((array)($report[$currentKey] ?? []) as $itemKey => $value) {
    if (is_numeric($value)) $normalizedCurrent[$itemKey] = (float)$value / $productionCurrent;
  }
  $normalized['_per_production_previous'] = $normalizedPrevious;
  $normalized['_per_production_current'] = $normalizedCurrent;

  return $buildTopPriceChanges($normalized, array_replace($keys, [
    'cost_previous' => '_per_production_previous',
    'cost_current' => '_per_production_current',
  ]));
};

$buildWeeklySpend = static function (array $report, array $keys): array {
  $weeks = array_values((array)($report['semanasCatalogo'] ?? []));
  $matrix = (array)($report[$keys['matrix']] ?? []);
  $costMatrix = (array)($report['matrizCostos'] ?? []);
  $impactMatrix = $keys['impact_matrix'] !== '' ? (array)($report[$keys['impact_matrix']] ?? []) : [];
  $production = (array)($report['produccionPorSemana'] ?? []);
  $spend = array_fill_keys($weeks, 0.0);
  $quantityTotals = array_fill_keys($weeks, 0.0);

  foreach ($matrix as $product => $values) {
    foreach ($weeks as $week) {
      $impact = $impactMatrix[$product][$week] ?? null;
      if (!is_numeric($impact)) {
        $quantity = $values[$week] ?? null;
        $cost = $costMatrix[$product][$week] ?? null;
        $impact = is_numeric($quantity) && is_numeric($cost) ? (float)$quantity * (float)$cost : 0.0;
      }
      $spend[$week] += (float)$impact;
      $quantity = $values[$week] ?? null;
      if (is_numeric($quantity)) {
        $quantityTotals[$week] += (float)$quantity;
      }
    }
  }

  $costPerProduction = [];
  $unitCost = [];
  foreach ($weeks as $week) {
    $productionValue = $production[$week] ?? null;
    $costPerProduction[$week] = is_numeric($productionValue) && (float)$productionValue > 0
      ? $spend[$week] / (float)$productionValue
      : null;
    $unitCost[$week] = ($quantityTotals[$week] ?? 0) > 0
      ? $spend[$week] / $quantityTotals[$week]
      : null;
  }

  $weeks = array_values(array_filter($weeks, static function (string $week) use ($spend, $production): bool {
    return abs((float)($spend[$week] ?? 0)) > 0.000001
      || (is_numeric($production[$week] ?? null) && (float)$production[$week] > 0);
  }));
  $limit = 18;
  $weeks = array_slice($weeks, -$limit);

  return [
    'labels' => $weeks,
    'gasto' => array_map(static fn(string $week): float => (float)($spend[$week] ?? 0), $weeks),
    'costo_produccion' => array_map(static fn(string $week) => $costPerProduction[$week] ?? null, $weeks),
    'costo_unitario' => array_map(static fn(string $week) => $unitCost[$week] ?? null, $weeks),
  ];
};

$normalizeSourceChart = static function (array $chart, int $limit = 18): array {
  $labels = array_values((array)($chart['labels'] ?? []));
  $actual = array_values((array)($chart['ratiosActual'] ?? []));
  $base = array_values((array)($chart['ratiosBase'] ?? []));
  $colors = array_values((array)($chart['colorsActual'] ?? []));
  $points = [];

  foreach ($labels as $index => $label) {
    $value = $actual[$index] ?? null;
    if (!is_numeric($value)) {
      continue;
    }
    $points[] = [
      'label' => (string)$label,
      'actual' => (float)$value,
      'base' => is_numeric($base[$index] ?? null) ? (float)$base[$index] : null,
      'color' => (string)($colors[$index] ?? '#94a3b8'),
    ];
  }

  $points = array_slice($points, -$limit);
  return [
    'labels' => array_column($points, 'label'),
    'actual' => array_column($points, 'actual'),
    'base' => array_column($points, 'base'),
    'colors' => array_column($points, 'color'),
    'ratio_base' => is_numeric($chart['ratioBase'] ?? null) ? (float)$chart['ratioBase'] : null,
  ];
};

$quadrants = [];
$warnings = [];
foreach ((array)($config['fuentes'] ?? []) as $key => $definition) {
  $type = (string)($definition['tipo'] ?? 'refacciones');
  $keys = $sourceKeys($type);

  try {
    $sourceReport = $loadSource((string)$definition['directorio']);
    $fixedMode = (string)($definition['modo_fijo'] ?? '');
    $impactPrevious = array_sum(array_map('floatval', (array)($sourceReport[$keys['impact_previous']] ?? [])));
    $impactCurrent = array_sum(array_map('floatval', (array)($sourceReport[$keys['impact_current']] ?? [])));
    $consumptionPrevious = array_sum(array_map('floatval', (array)($sourceReport[$keys['consumption_previous']] ?? [])));
    $consumptionCurrent = array_sum(array_map('floatval', (array)($sourceReport[$keys['consumption_current']] ?? [])));
    $productionPrevious = (float)($sourceReport['totalProduccionAnioAnterior'] ?? 0);
    $productionCurrent = (float)($sourceReport['totalProduccionAnioActual'] ?? 0);
    $weightedUnitCost = $consumptionCurrent > 0 ? $impactCurrent / $consumptionCurrent : null;
    $spendPerProduction = $productionCurrent > 0 ? $impactCurrent / $productionCurrent : null;

    $ratioChart = $normalizeSourceChart((array)($sourceReport['chartData'] ?? []));
    $costChart = $normalizeSourceChart((array)($sourceReport['chartDataCosto'] ?? []));
    $ratioChart['limite_verde'] = is_numeric($sourceReport['limiteVerde'] ?? null) ? (float)$sourceReport['limiteVerde'] : null;
    $ratioChart['limite_amarillo'] = is_numeric($sourceReport['limiteAmarillo'] ?? null) ? (float)$sourceReport['limiteAmarillo'] : null;
    if (is_numeric($ratioChart['ratio_base'] ?? null) && (float)$ratioChart['ratio_base'] > 0) {
      $consumptionBase = (float)$ratioChart['ratio_base'];
      $consumptionYellowLimit = $consumptionBase * 1.06;
      $ratioChart['limite_verde'] = $consumptionBase;
      $ratioChart['limite_amarillo'] = $consumptionYellowLimit;
      $ratioChart['colors'] = array_map(static function ($value) use ($consumptionBase, $consumptionYellowLimit): string {
        if (!is_numeric($value) || (float)$value <= $consumptionBase) return '#10b981';
        return (float)$value <= $consumptionYellowLimit ? '#f59e0b' : '#ef4444';
      }, (array)($ratioChart['actual'] ?? []));
    }
    $weeklyTrend = $buildWeeklySpend($sourceReport, $keys);
    if ($fixedMode === 'costo_produccion') {
      $costBase = $consumptionPrevious > 0 ? $impactPrevious / $consumptionPrevious : null;
      $costValues = array_values((array)($weeklyTrend['costo_unitario'] ?? []));
      $costYellowLimit = $costBase !== null ? $costBase * 1.10 : null;
      $costChart = [
        'labels' => array_values((array)($weeklyTrend['labels'] ?? [])),
        'actual' => $costValues,
        'base' => array_fill(0, count($costValues), $costBase),
        'colors' => array_map(static function ($value) use ($costBase, $costYellowLimit): string {
          if (!is_numeric($value) || $costBase === null || $costYellowLimit === null) return '#94a3b8';
          if ((float)$value <= $costBase) return '#10b981';
          return (float)$value <= $costYellowLimit ? '#f59e0b' : '#ef4444';
        }, $costValues),
        'ratio_base' => $costBase,
      ];
    } elseif (empty($costChart['actual']) && $productionPrevious > 0 && $productionCurrent > 0) {
      $costBase = $impactPrevious / $productionPrevious;
      $yellowLimit = $costBase * 1.10;
      $costValues = array_values((array)($weeklyTrend['costo_produccion'] ?? []));
      $costChart = [
        'labels' => array_values((array)($weeklyTrend['labels'] ?? [])),
        'actual' => $costValues,
        'base' => array_fill(0, count($costValues), $costBase),
        'colors' => array_map(static function ($value) use ($costBase, $yellowLimit): string {
          if (!is_numeric($value)) {
            return '#10b981';
          }
          if ((float)$value <= $costBase) {
            return '#10b981';
          }
          return (float)$value <= $yellowLimit ? '#f59e0b' : '#ef4444';
        }, $costValues),
        'ratio_base' => $costBase,
      ];
    } elseif (empty($costChart['actual'])) {
      $costValues = array_values((array)($weeklyTrend['gasto'] ?? []));
      $previousPeriods = max(1, count((array)($sourceReport['datosAnioAnterior'] ?? [])));
      $costBase = $impactPrevious / $previousPeriods;
      $yellowLimit = $costBase * 1.10;
      $costChart = [
        'labels' => array_values((array)($weeklyTrend['labels'] ?? [])),
        'actual' => $costValues,
        'base' => array_fill(0, count($costValues), $costBase),
        'colors' => array_map(static function ($value) use ($costBase, $yellowLimit): string {
          if (!is_numeric($value) || (float)$value <= $costBase) return '#10b981';
          return (float)$value <= $yellowLimit ? '#f59e0b' : '#ef4444';
        }, $costValues),
        'ratio_base' => $costBase,
      ];
    }
    $costBase = is_numeric($costChart['ratio_base'] ?? null) ? (float)$costChart['ratio_base'] : null;
    if ($costBase !== null && $costBase > 0) {
      $costYellowLimit = $costBase * 1.10;
      $costChart['limite_verde'] = $costBase;
      $costChart['limite_amarillo'] = $costYellowLimit;
      $costChart['colors'] = array_map(static function ($value) use ($costBase, $costYellowLimit): string {
        if (!is_numeric($value) || (float)$value <= $costBase) return '#10b981';
        return (float)$value <= $costYellowLimit ? '#f59e0b' : '#ef4444';
      }, (array)($costChart['actual'] ?? []));
    }
    $fallbackSemaphoreColor = (string)($sourceReport['colorGlobalHex'] ?? '#10b981');
    if ($fallbackSemaphoreColor === '' || strtolower($fallbackSemaphoreColor) === '#94a3b8') {
      $fallbackSemaphoreColor = '#10b981';
    }
    foreach (['ratioChart', 'costChart'] as $chartVariable) {
      foreach ((${$chartVariable}['colors'] ?? []) as $colorIndex => $pointColor) {
        if ((string)$pointColor === '' || strtolower((string)$pointColor) === '#94a3b8') {
          ${$chartVariable}['colors'][$colorIndex] = $fallbackSemaphoreColor;
        }
      }
    }
    $colorByWeek = [];
    $weekKey = static function (string $label): string {
      if (preg_match('/S0*([0-9]{1,2})$/i', $label, $matches) === 1) {
        return 'S' . (int)$matches[1];
      }
      return strtoupper(trim($label));
    };
    foreach (($ratioChart['labels'] ?? []) as $chartIndex => $weekLabel) {
      $colorByWeek[$weekKey((string)$weekLabel)] = (string)($ratioChart['colors'][$chartIndex] ?? $fallbackSemaphoreColor);
    }
    $weeklyTrend['colors'] = array_map(
      static fn(string $week): string => $colorByWeek[$weekKey($week)] ?? $fallbackSemaphoreColor,
      (array)($weeklyTrend['labels'] ?? [])
    );
    $weeklyTrend['ratio'] = $ratioChart;
    $weeklyTrend['costo_fuente'] = $costChart;

    if ($fixedMode === 'consumo') {
      $tops = $buildTopPerProduction(
        $sourceReport, $keys, $keys['consumption_previous'], $keys['consumption_current'],
        $productionPrevious, $productionCurrent
      );
    } elseif ($fixedMode === 'costo_produccion') {
      $tops = $buildTopPriceChanges($sourceReport, $keys);
    } else {
      $tops = $buildTopPriceChanges($sourceReport, $keys);
    }
    $ratioBaseValue = is_numeric($sourceReport['ratioBase'] ?? null) ? (float)$sourceReport['ratioBase'] : null;
    $ratioActualValue = is_numeric($sourceReport['ratioPromedioAnioActual'] ?? null) ? (float)$sourceReport['ratioPromedioAnioActual'] : null;
    $variationValue = is_numeric($sourceReport['variacionRatio'] ?? null) ? (float)$sourceReport['variacionRatio'] : null;
    $globalState = (string)($sourceReport['estadoGlobal'] ?? 'Sin dato');
    $globalColor = (string)($sourceReport['colorGlobal'] ?? 'gris');
    $globalHex = (string)($sourceReport['colorGlobalHex'] ?? '#94a3b8');
    $greenLimitValue = is_numeric($sourceReport['limiteVerde'] ?? null) ? (float)$sourceReport['limiteVerde'] : null;
    $yellowLimitValue = is_numeric($sourceReport['limiteAmarillo'] ?? null) ? (float)$sourceReport['limiteAmarillo'] : null;
    if ($fixedMode === 'costo_produccion') {
      $ratioBaseValue = $consumptionPrevious > 0 ? $impactPrevious / $consumptionPrevious : null;
      $ratioActualValue = $weightedUnitCost;
      $variationValue = ($ratioBaseValue && $ratioActualValue !== null)
        ? (($ratioActualValue - $ratioBaseValue) / $ratioBaseValue) * 100 : null;
      [$globalState, $globalColor, $globalHex] = semaforo($ratioActualValue, $ratioBaseValue, 10.0);
    } elseif ($fixedMode === 'consumo') {
      $greenLimitValue = $ratioBaseValue;
      $yellowLimitValue = $ratioBaseValue !== null ? $ratioBaseValue * 1.06 : null;
      [$globalState, $globalColor, $globalHex] = semaforo($ratioActualValue, $ratioBaseValue, 6.0);
    }

    $consumptionBaseSummary = is_numeric($sourceReport['ratioBase'] ?? null) ? (float)$sourceReport['ratioBase'] : null;
    $consumptionCurrentSummary = is_numeric($sourceReport['ratioPromedioAnioActual'] ?? null) ? (float)$sourceReport['ratioPromedioAnioActual'] : null;
    [$consumptionState, $consumptionColor, $consumptionHex] = semaforo($consumptionCurrentSummary, $consumptionBaseSummary, 6.0);
    $currentPeriods = max(1, count((array)($sourceReport['datosAnioActual'] ?? [])));
    $costBaseSummary = is_numeric($costChart['ratio_base'] ?? null) ? (float)$costChart['ratio_base'] : null;
    $costCurrentSummary = $fixedMode === 'costo_produccion'
      ? $weightedUnitCost
      : ($productionCurrent > 0 ? $spendPerProduction : ($impactCurrent / $currentPeriods));
    $costVariationSummary = ($costBaseSummary !== null && $costBaseSummary > 0 && $costCurrentSummary !== null)
      ? (($costCurrentSummary - $costBaseSummary) / $costBaseSummary) * 100
      : null;
    [$costState, $costColor, $costHex] = semaforo($costCurrentSummary, $costBaseSummary, 10.0);

    $quadrants[$key] = array_replace($definition, [
      'key' => (string)$key,
      'available' => true,
      'anio_anterior' => (int)($sourceReport['anioAnterior'] ?? ((int)date('Y') - 1)),
      'anio_actual' => (int)($sourceReport['anioActual'] ?? (int)date('Y')),
      'ratio_base' => $ratioBaseValue,
      'ratio_actual' => $ratioActualValue,
      'variacion_base' => $variationValue,
      'estado_global' => $globalState,
      'color_global' => $globalColor,
      'color_global_hex' => $globalHex,
      'limite_verde' => $greenLimitValue,
      'limite_amarillo' => $yellowLimitValue,
      'costo_unitario' => $weightedUnitCost,
      'gasto_actual' => $impactCurrent,
      'gasto_produccion' => $spendPerProduction,
      'consumo_actual' => $consumptionCurrent,
      'produccion_actual' => $productionCurrent,
      'periodos_actual' => $currentPeriods,
      'usa_produccion' => $productionCurrent > 0,
      'resumen_consumo' => [
        'etiqueta' => $productionCurrent > 0 ? 'Consumo / producción' : 'Consumo semanal',
        'base' => $consumptionBaseSummary,
        'actual' => $consumptionCurrentSummary,
        'variacion' => is_numeric($sourceReport['variacionRatio'] ?? null) ? (float)$sourceReport['variacionRatio'] : null,
        'limite_verde' => $consumptionBaseSummary,
        'limite_amarillo' => $consumptionBaseSummary !== null ? $consumptionBaseSummary * 1.06 : null,
        'estado' => $consumptionState,
        'color' => $consumptionColor,
        'color_hex' => $consumptionHex,
        'es_dinero' => false,
      ],
      'resumen_costo' => [
        'etiqueta' => $fixedMode === 'costo_produccion' ? 'Costo unitario químico' : ($productionCurrent > 0 ? 'Costo / producción' : 'Costo semanal'),
        'base' => $costBaseSummary,
        'actual' => $costCurrentSummary,
        'variacion' => $costVariationSummary,
        'limite_verde' => $costBaseSummary,
        'limite_amarillo' => $costBaseSummary !== null ? $costBaseSummary * 1.10 : null,
        'estado' => $costState,
        'color' => $costColor,
        'color_hex' => $costHex,
        'es_dinero' => true,
      ],
      'tops' => $tops,
      'frecuencia_compras' => $type === 'refacciones'
        ? array_slice((array)($sourceReport['frecuenciaCompraRefacciones'] ?? []), 0, 8)
        : [],
      'top_tipo' => $fixedMode === 'consumo' ? 'consumo_produccion' : 'precio',
      'tendencia' => $weeklyTrend,
      'error' => '',
    ]);
  } catch (Throwable $e) {
    error_log('[compras-general] Fuente ' . (string)($definition['directorio'] ?? $key) . ': ' . $e->getMessage());
    $quadrants[$key] = array_replace($definition, [
      'key' => (string)$key,
      'available' => false,
      'tops' => ['aumentos' => [], 'disminuciones' => []],
      'tendencia' => ['labels' => [], 'gasto' => [], 'costo_produccion' => []],
      'error' => 'No fue posible consultar el reporte fuente.',
    ]);
    $warnings[] = (string)($definition['titulo'] ?? $key) . ': fuente no disponible.';
  }
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Compras General'),
  'cuadrantes' => $quadrants,
  'produccion_acumulada' => (static function (array $items): ?float {
    foreach ($items as $item) {
      if (is_numeric($item['produccion_actual'] ?? null) && (float)$item['produccion_actual'] > 0) {
        return (float)$item['produccion_actual'];
      }
    }
    return null;
  })($quadrants),
  'actualizado' => date('d/m/Y H:i'),
  'intervalo_actualizacion_ms' => max(60000, (int)($config['intervalo_actualizacion_ms'] ?? 300000)),
  'warnings' => $warnings,
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
