<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = (int)($version ?? time());
$filtros = (array)($filtros ?? []);
$kpis = (array)($kpis ?? []);
$series = (array)($series ?? []);
$tablas = (array)($tablas ?? []);
$meta = (array)($meta ?? []);
$periodMode = in_array((string)($filtros['periodo'] ?? 'mes'), ['mes', 'semana', 'fecha'], true) ? (string)$filtros['periodo'] : 'mes';
$todayUrl = './?' . http_build_query([
  'periodo' => 'fecha',
  'fecha_inicio' => $filtros['hoy'] ?? date('Y-m-d'),
  'fecha_fin' => $filtros['hoy'] ?? date('Y-m-d'),
  'mt_id' => $filtros['mt_id'] ?? 'all',
]);

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 1): string => is_numeric($value) ? n((float)$value, $decimals) : '-';
$fmtPct = static fn($value, int $decimals = 2): string => is_numeric($value) ? n((float)$value, $decimals) . ' %' : '-';
$fmtInt = static fn($value): string => is_numeric($value) ? n((float)$value, 0) : '-';
$fmtMoney = static fn($value): string => is_numeric($value) ? '$ ' . n((float)$value, 2) : '-';

$dailyRows = (array)($series['diaria'] ?? []);
$dailyGroups = (array)($series['grupos_diarios'] ?? []);
$groupRows = (array)($tablas['grupos_material'] ?? []);
$priceGroupRows = (array)($tablas['precios_grupo'] ?? []);
$materialYieldRows = (array)($tablas['rendimiento_material'] ?? []);
$materialRows = (array)($tablas['materiales'] ?? []);
$providerRows = (array)($tablas['proveedores'] ?? []);
$providerMaterialRows = (array)($tablas['proveedores_material'] ?? []);
$providerMaterialPeriodRows = (array)($tablas['proveedor_material_periodos'] ?? ['mensual' => [], 'semanal' => []]);
$providerMaterialComparisonRows = [];
$providerMaterialWeeklyGroups = [];
foreach ((array)($providerMaterialPeriodRows['semanal'] ?? []) as $weeklyRow) {
  $comparisonKey = (int)($weeklyRow['prv_id'] ?? 0) . '|' . (int)($weeklyRow['mat_id'] ?? 0);
  $providerMaterialWeeklyGroups[$comparisonKey][] = $weeklyRow;
}
foreach ((array)($providerMaterialPeriodRows['mensual'] ?? []) as $monthlyRow) {
  $comparisonKey = (int)($monthlyRow['prv_id'] ?? 0) . '|' . (int)($monthlyRow['mat_id'] ?? 0);
  $weeklyRows = (array)($providerMaterialWeeklyGroups[$comparisonKey] ?? []);
  if ($weeklyRows === []) {
    $providerMaterialComparisonRows[] = ['mensual' => $monthlyRow, 'semanal' => null];
  } else {
    foreach ($weeklyRows as $weeklyRow) {
      $providerMaterialComparisonRows[] = ['mensual' => $monthlyRow, 'semanal' => $weeklyRow];
    }
  }
  unset($providerMaterialWeeklyGroups[$comparisonKey]);
}
foreach ($providerMaterialWeeklyGroups as $weeklyRows) {
  foreach ($weeklyRows as $weeklyRow) {
    $providerMaterialComparisonRows[] = ['mensual' => null, 'semanal' => $weeklyRow];
  }
}

// Una sola fila por combinación proveedor/material; las semanas se apilan dentro de esa fila.
$providerMaterialComparisonGroups = [];
foreach ($providerMaterialComparisonRows as $comparison) {
  $monthlyRow = is_array($comparison['mensual'] ?? null) ? $comparison['mensual'] : [];
  $weeklyRow = is_array($comparison['semanal'] ?? null) ? $comparison['semanal'] : [];
  $identityRow = $monthlyRow !== [] ? $monthlyRow : $weeklyRow;
  $groupKey = (int)($identityRow['prv_id'] ?? 0) . '|' . (int)($identityRow['mat_id'] ?? 0);
  if (!isset($providerMaterialComparisonGroups[$groupKey])) {
    $providerMaterialComparisonGroups[$groupKey] = [
      'identidad' => $identityRow,
      'mensual' => $monthlyRow,
      'semanas' => [],
    ];
  }
  if ($monthlyRow !== []) {
    $providerMaterialComparisonGroups[$groupKey]['mensual'] = $monthlyRow;
  }
  if ($weeklyRow !== []) {
    $providerMaterialComparisonGroups[$groupKey]['semanas'][] = $weeklyRow;
  }
}
$providerMaterialComparisonGroups = array_values(array_filter(
  $providerMaterialComparisonGroups,
  static fn(array $group): bool => (array)($group['semanas'] ?? []) !== []
));
usort($providerMaterialComparisonGroups, static function (array $left, array $right): int {
  $leftIdentity = (array)($left['identidad'] ?? []);
  $rightIdentity = (array)($right['identidad'] ?? []);
  return strcasecmp((string)($leftIdentity['proveedor'] ?? ''), (string)($rightIdentity['proveedor'] ?? ''))
    ?: strcasecmp((string)($leftIdentity['material'] ?? ''), (string)($rightIdentity['material'] ?? ''));
});
$enzymeProcessRows = (array)($tablas['enzima_procesos'] ?? []);
usort($materialYieldRows, static function (array $a, array $b): int {
  $yieldCompare = ((float)($b['rendimiento'] ?? -1)) <=> ((float)($a['rendimiento'] ?? -1));
  if ($yieldCompare !== 0) {
    return $yieldCompare;
  }

  return ((float)($b['toneladas_consumidas'] ?? 0)) <=> ((float)($a['toneladas_consumidas'] ?? 0));
});
$recorteMaterialNames = ['DESBARBE', 'RECORTE', 'DESORILLE', 'GARRA', 'DELANTERO'];
$dailyPalette = [
    'Carnaza'      => '#1D4ED8', // Azul
    'Cuero Entero C/P (con pelo)' => '#EA580C', // Naranja quemado
    'Cuero Entero Depilado' => '#D97706', // Ámbar
    'Pedacera Americana S/P' => '#7C3AED',
    'Pedacera Americana C/P' => '#6D28D9',
    'Pedacera Americana Depilada' => '#8B5CF6',
    'Pedacera Nacional C/P' => '#5B21B6',
    'DESBARBE'     => '#92400E',
    'RECORTE'      => '#92400E',
    'DESORILLE'    => '#92400E',
    'GARRA'        => '#92400E',
    'DELANTERO'    => '#92400E',
    'Otros'        => '#334155', // Gris azulado
];
$yieldStatusColor = static function (string $group, $value) use ($recorteMaterialNames): string {
  if (!is_numeric($value)) {
    return '#94a3b8';
  }

  $number = (float)$value;
  if ($group === 'Carnaza') {
    if ($number > 15.5) return '#2e8b57';
    if ($number >= 15) return '#facc15';
    return '#c94436';
  }

  if (strpos($group, 'Cuero Entero') === 0) {
    if ($number > 18) return '#2e8b57';
    if ($number >= 17) return '#facc15';
    return '#c94436';
  }

  if (stripos($group, 'Pedacera') === 0 || in_array($group, $recorteMaterialNames, true)) {
    if ($number > 14) return '#2e8b57';
    if ($number >= 13.5) return '#facc15';
    return '#c94436';
  }

  if ($number >= 17) return '#2e8b57';
  if ($number >= 16) return '#facc15';
  return '#c94436';
};
$yieldStatusKey = static function (string $group, $value) use ($recorteMaterialNames): string {
  if (!is_numeric($value)) {
    return 'gris';
  }

  $number = (float)$value;
  if ($group === 'Carnaza') {
    return $number > 15.5 ? 'verde' : ($number >= 15 ? 'amarillo' : 'rojo');
  }
  if (strpos($group, 'Cuero Entero') === 0) {
    return $number > 18 ? 'verde' : ($number >= 17 ? 'amarillo' : 'rojo');
  }
  if (stripos($group, 'Pedacera') === 0 || in_array($group, $recorteMaterialNames, true)) {
    return $number > 14 ? 'verde' : ($number >= 13.5 ? 'amarillo' : 'rojo');
  }
  return $number >= 17 ? 'verde' : ($number >= 16 ? 'amarillo' : 'rojo');
};

$yieldLabels = array_map(static fn(array $row): string => (string)($row['grupo'] ?? ''), $materialYieldRows);
$yieldData = array_map(static fn(array $row): float => round((float)($row['rendimiento'] ?? 0), 2), $materialYieldRows);
$yieldColors = array_map(static fn(array $row): string => $yieldStatusColor((string)($row['grupo'] ?? ''), $row['rendimiento'] ?? null), $materialYieldRows);
$yieldRangeCards = [
  ['grupo' => 'Carnaza', 'verde' => '> 15.5%', 'amarillo' => '15 - 15.5%', 'rojo' => '< 15%'],
  ['grupo' => 'Cuero Entero C/P (con pelo)', 'verde' => '> 18%', 'amarillo' => '17 - 18%', 'rojo' => '< 17%'],
  ['grupo' => 'Cuero Entero Depilado', 'verde' => '> 18%', 'amarillo' => '17 - 18%', 'rojo' => '< 17%'],
  ['grupo' => 'Pedacera Americana S/P', 'verde' => '> 14%', 'amarillo' => '13.5 - 14%', 'rojo' => '< 13.5%'],
  ['grupo' => 'Pedacera Americana C/P', 'verde' => '> 14%', 'amarillo' => '13.5 - 14%', 'rojo' => '< 13.5%'],
  ['grupo' => 'Pedacera Americana Depilada', 'verde' => '> 14%', 'amarillo' => '13.5 - 14%', 'rojo' => '< 13.5%'],
  ['grupo' => 'Pedacera Nacional C/P', 'verde' => '> 14%', 'amarillo' => '13.5 - 14%', 'rojo' => '< 13.5%'],
];
foreach ($recorteMaterialNames as $materialName) {
  $yieldRangeCards[] = ['grupo' => $materialName, 'verde' => '> 14%', 'amarillo' => '13.5 - 14%', 'rojo' => '< 13.5%'];
}
$chartGroups = array_slice($groupRows, 0, 8);
$groupLabels = array_map(static fn(array $row): string => (string)($row['grupo'] ?? ''), $chartGroups);
$groupData = array_map(static fn(array $row): float => round((float)($row['toneladas'] ?? 0), 2), $chartGroups);
$groupPalette = array_map(static fn(array $row): string => $dailyPalette[(string)($row['grupo'] ?? '')] ?? '#0891b2', $chartGroups);
$groupTotal = array_sum($groupData);
$wholeLeatherTonnes = 0.0;
$pedaceraTonnes = 0.0;
foreach ($chartGroups as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  if (strpos($groupName, 'Cuero Entero') === 0) {
    $wholeLeatherTonnes += (float)($row['toneladas'] ?? 0);
  }
  if (stripos($groupName, 'Pedacera') === 0) {
    $pedaceraTonnes += (float)($row['toneladas'] ?? 0);
  }
}
$wholeLeatherParticipation = $groupTotal > 0 ? ($wholeLeatherTonnes / $groupTotal) * 100 : 0.0;
$pedaceraParticipation = $groupTotal > 0 ? ($pedaceraTonnes / $groupTotal) * 100 : 0.0;
$participationTargets = [
  'Carnaza' => [30.0, 35.0],
  'Pedacera' => [10.0, 15.0],
  'Cuero Entero' => [45.0, 55.0],
];
$participationStatus = static function (string $group, float $value) use ($participationTargets, $wholeLeatherParticipation, $pedaceraParticipation): string {
  $targetGroup = strpos($group, 'Cuero Entero') === 0
    ? 'Cuero Entero'
    : (stripos($group, 'Pedacera') === 0 ? 'Pedacera' : $group);
  $evaluatedValue = $targetGroup === 'Cuero Entero'
    ? $wholeLeatherParticipation
    : ($targetGroup === 'Pedacera' ? $pedaceraParticipation : $value);
  if (!isset($participationTargets[$targetGroup])) {
    return 'mp-share-neutral';
  }

  [$min, $max] = $participationTargets[$targetGroup];
  return $evaluatedValue >= $min && $evaluatedValue <= $max ? 'mp-share-good' : 'mp-share-bad';
};
$participationStatusLabel = static function (string $status): string {
  if ($status === 'mp-share-good') {
    return 'En rango';
  }

  if ($status === 'mp-share-bad') {
    return 'Fuera';
  }

  return 'Referencia';
};
$participationTargetLabel = static function (string $group) use ($participationTargets): string {
  $targetGroup = strpos($group, 'Cuero Entero') === 0
    ? 'Cuero Entero'
    : (stripos($group, 'Pedacera') === 0 ? 'Pedacera' : $group);
  if (!isset($participationTargets[$targetGroup])) {
    return 'sin rango';
  }

  [$min, $max] = $participationTargets[$targetGroup];
  $prefix = $targetGroup === 'Cuero Entero' ? 'total cuero ' : ($targetGroup === 'Pedacera' ? 'total pedacera ' : '');
  return $prefix . n($min, 0) . ' - ' . n($max, 0) . '%';
};
$groupCards = array_map(static function (array $row, int $index) use ($groupTotal, $groupPalette, $participationStatus, $participationStatusLabel, $participationTargetLabel): array {
  $toneladas = (float)($row['toneladas'] ?? 0);
  $groupName = (string)($row['grupo'] ?? '');
  $participacion = $groupTotal > 0 ? (($toneladas / $groupTotal) * 100) : 0;
  $participacionClase = $participationStatus($groupName, $participacion);
  return [
    'grupo' => $groupName,
    'toneladas' => $toneladas,
    'materiales' => (int)($row['materiales'] ?? 0),
    'proveedores' => (int)($row['proveedores'] ?? 0),
    'procesos' => (int)($row['procesos'] ?? 0),
    'participacion' => $participacion,
    'participacion_clase' => $participacionClase,
    'participacion_rango' => $participationTargetLabel($groupName),
    'participacion_estado' => $participationStatusLabel($participacionClase),
    'participacion_barra' => max(0, min(100, $participacion)),
    'color' => $groupPalette[$index] ?? '#0891b2',
  ];
}, $chartGroups, array_keys($chartGroups));
$mainGroup = $groupCards[0] ?? null;
$providerChartRows = array_slice($providerRows, 0, 10);
$providerIds = array_map(static fn(array $row): int => (int)($row['prv_id'] ?? 0), $providerChartRows);
$providerLabels = array_map(static fn(array $row): string => (string)($row['proveedor'] ?? ''), $providerChartRows);
$providerYieldData = array_map(static fn(array $row): float => round((float)($row['rendimiento'] ?? 0), 2), $providerChartRows);
$providerConsumptionData = array_map(static fn(array $row): float => round((float)($row['toneladas_consumidas'] ?? 0), 1), $providerChartRows);
$providerMaterialsByProvider = [];
foreach ($providerMaterialRows as $row) {
  $providerId = (int)($row['prv_id'] ?? 0);
  if (!in_array($providerId, $providerIds, true)) {
    continue;
  }

  $groupName = (string)($row['grupo'] ?? 'Otros');
  $providerMaterialsByProvider[$providerId][$groupName] = round((float)($row['toneladas_consumidas'] ?? 0), 1);
}
$providerYieldByMaterial = [];
$providerYieldGroups = [
  'Carnaza',
  'Cuero Entero C/P (con pelo)',
  'Cuero Entero Depilado',
  'Pedacera Americana S/P',
  'Pedacera Americana C/P',
  'Pedacera Americana Depilada',
  'Pedacera Nacional C/P',
];
foreach ($recorteMaterialNames as $materialName) {
  foreach ($providerMaterialRows as $providerMaterialRow) {
    if ((string)($providerMaterialRow['grupo'] ?? '') === $materialName) {
      $providerYieldGroups[] = $materialName;
      break;
    }
  }
}
foreach ($providerYieldGroups as $groupName) {
  $rows = array_values(array_filter($providerMaterialRows, static function (array $row) use ($groupName): bool {
    return (string)($row['grupo'] ?? '') === $groupName
      && (float)($row['toneladas_consumidas'] ?? 0) > 0;
  }));
  usort($rows, static function (array $a, array $b): int {
    $yieldCompare = ((float)($b['rendimiento'] ?? -1)) <=> ((float)($a['rendimiento'] ?? -1));
    if ($yieldCompare !== 0) {
      return $yieldCompare;
    }

    return ((float)($b['toneladas_consumidas'] ?? 0)) <=> ((float)($a['toneladas_consumidas'] ?? 0));
  });
  $rows = array_slice($rows, 0, 8);

  $providerYieldByMaterial[] = [
    'grupo' => $groupName,
    'labels' => array_map(static fn(array $row): string => (string)($row['proveedor'] ?? ''), $rows),
    'data' => array_map(static fn(array $row): float => round((float)($row['rendimiento'] ?? 0), 2), $rows),
    'consumo' => array_map(static fn(array $row): float => round((float)($row['toneladas_consumidas'] ?? 0), 1), $rows),
    'colors' => array_map(static fn(array $row): string => $yieldStatusColor($groupName, $row['rendimiento'] ?? null), $rows),
  ];
}
$groupDetails = [];
foreach ($groupCards as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  $groupDetails[$groupName] = [
    'grupo' => $groupName,
    'color' => (string)($row['color'] ?? '#2563eb'),
    'consumo' => $row,
    'rendimiento' => null,
    'precio' => null,
    'materiales' => [],
    'proveedores' => [],
  ];
}
foreach ($materialYieldRows as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  $groupDetails[$groupName]['rendimiento'] = $row;
  $groupDetails[$groupName]['grupo'] = $groupName;
  $groupDetails[$groupName]['color'] = $groupDetails[$groupName]['color'] ?? ($dailyPalette[$groupName] ?? '#2563eb');
}
foreach ($priceGroupRows as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  $groupDetails[$groupName]['precio'] = $row;
  $groupDetails[$groupName]['grupo'] = $groupName;
  $groupDetails[$groupName]['color'] = $groupDetails[$groupName]['color'] ?? ($dailyPalette[$groupName] ?? '#2563eb');
}
foreach ($materialRows as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  $groupDetails[$groupName]['materiales'][] = $row;
  $groupDetails[$groupName]['grupo'] = $groupName;
  $groupDetails[$groupName]['color'] = $groupDetails[$groupName]['color'] ?? ($dailyPalette[$groupName] ?? '#2563eb');
}
foreach ($providerMaterialRows as $row) {
  $groupName = (string)($row['grupo'] ?? '');
  $groupDetails[$groupName]['proveedores'][] = $row;
  $groupDetails[$groupName]['grupo'] = $groupName;
  $groupDetails[$groupName]['color'] = $groupDetails[$groupName]['color'] ?? ($dailyPalette[$groupName] ?? '#2563eb');
}
$rendimiento = $kpis['rendimiento'] ?? null;
$rendClass = '';
if (is_numeric($rendimiento)) {
  $rendClass = (float)$rendimiento >= 17 ? 'mp-good' : ((float)$rendimiento >= 16 ? 'mp-warn' : 'mp-bad');
}
$productionClass = '';
$selectedYear = (int)($filtros['anio'] ?? date('Y'));
$selectedMonth = (int)($filtros['mes'] ?? date('n'));
$selectedMonth = max(1, min(12, $selectedMonth));
$selectedYear = max(1, $selectedYear);
$monthDate = DateTimeImmutable::createFromFormat('!Y-n-j', "{$selectedYear}-{$selectedMonth}-1");
$daysInMonth = $monthDate instanceof DateTimeImmutable ? (int)$monthDate->format('t') : 30;
$productionGreenTarget = 22.0 * $daysInMonth;
$productionYellowMin = 20.0 * $daysInMonth;
$producedTons = $kpis['toneladas_producidas'] ?? null;
if (is_numeric($producedTons)) {
  $productionValue = (float)$producedTons;
  if ($productionValue < $productionYellowMin) {
    $productionClass = 'mp-bad';
  } elseif ($productionValue < $productionGreenTarget) {
    $productionClass = 'mp-warn';
  } else {
    $productionClass = 'mp-good';
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo ?? 'Materia Prima') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/css/dashboard.css') ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      background: #f4f7fb;
    }

    .mp-dashboard {
      max-width: 1680px;
      padding: 18px 28px 24px;
    }

    .mp-header {
      margin-bottom: 14px;
    }

    .mp-header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }

    .mp-title h1 {
      margin: 0;
      color: #0f172a;
      font-size: clamp(1.9rem, 2.8vw, 2.7rem);
      letter-spacing: 0;
    }

    .mp-title p {
      margin: 5px 0 0;
      color: #64748b;
      font-size: .95rem;
      font-weight: 600;
    }

    .mp-filter-form {
      display: flex;
      align-items: end;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 14px;
      padding: 12px 14px;
      border: 1px solid #dbe7f5;
      border-radius: 14px;
      background: #ffffff;
      box-shadow: 0 6px 18px rgba(15, 23, 42, .05);
    }

    .mp-filter-field {
      display: grid;
      gap: 6px;
      min-width: 150px;
    }

    .mp-filter-field.wide {
      min-width: 230px;
    }

    .mp-filter-field label {
      color: #64748b;
      font-size: .72rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .08em;
    }

    .mp-filter-field select,
    .mp-filter-field input,
    .mp-filter-form button {
      min-height: 40px;
      border-radius: 10px;
      font: inherit;
      font-weight: 700;
    }

    .mp-filter-field select,
    .mp-filter-field input {
      border: 1px solid #cbd5e1;
      background: #f8fafc;
      color: #0f172a;
      padding: 0 12px;
    }

    .mp-period-control { display: grid; gap: 6px; }
    .mp-period-control > label, .mp-period-values > label { color: #64748b; font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; }
    .mp-period-switch { display: flex; min-height: 40px; padding: 3px; border: 1px solid #cbd5e1; border-radius: 10px; background: #f1f5f9; }
    .mp-filter-form .mp-period-btn { min-height: 32px; border: 0; background: transparent; color: #64748b; box-shadow: none; padding: 0 13px; }
    .mp-filter-form .mp-period-btn.is-active { background: #0f766e; color: #fff; }
    .mp-period-values { display: grid; gap: 6px; min-width: 330px; flex: 1 1 400px; }
    .mp-period-panel { display: none; align-items: end; gap: 8px; }
    .mp-period-panel.is-active { display: flex; }
    .mp-period-panel .mp-filter-field { flex: 1; min-width: 135px; }
    .mp-today { display: inline-flex; min-height: 40px; align-items: center; justify-content: center; gap: 6px; padding: 0 12px; border: 1px solid #b8d8d3; border-radius: 10px; background: #edf8f6; color: #0f766e; text-decoration: none; font-size: .78rem; font-weight: 800; }

    .mp-filter-form button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      border: 1px solid #bfdbfe;
      background: #eff6ff;
      color: #1d4ed8;
      padding: 0 14px;
      text-decoration: none;
      cursor: pointer;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border-radius: 40px;
      text-decoration: none;
      font-weight: 500;
      font-size: 0.8rem;
      border: 1px solid #dce5ec;
      background: #ffffff;
      color: #3a5a78;
      transition: all 0.2s;
      margin-bottom: 12px;
    }

    .back-btn:hover {
      background: #f5f9ff;
      border-color: #b9cfec;
      transform: translateX(-2px);
    }

    .mp-filter-form.is-applying {
      opacity: .72;
      pointer-events: none;
    }

    .mp-filter-form.is-applying button {
      background: #e2e8f0;
      border-color: #cbd5e1;
      color: #475569;
    }

    .mp-grid {
      display: grid;
      grid-template-columns: repeat(12, minmax(0, 1fr));
      gap: 12px;
    }

    .mp-card {
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #ffffff;
      box-shadow: 0 8px 20px rgba(15, 23, 42, .05);
      overflow: hidden;
    }

    .mp-kpis {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 10px;
    }

    .mp-kpi {
      min-height: 110px;
      padding: 14px;
      display: grid;
      align-content: space-between;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #ffffff;
    }

    .mp-kpi span {
      color: #64748b;
      font-size: .75rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    .mp-kpi strong {
      color: #0f172a;
      font-size: clamp(1.45rem, 2.1vw, 2.05rem);
      line-height: 1;
      letter-spacing: 0;
    }

    .mp-kpi small {
      color: #64748b;
      font-size: .78rem;
      font-weight: 700;
    }

    .mp-kpi.mp-good {
      background: #2e8b57;
      border-color: #257447;
    }

    .mp-kpi.mp-warn {
      background: #facc15;
      color: #111827;
      border-color: #eab308;
    }

    .mp-kpi.mp-bad {
      background: #c94436;
      border-color: #a9362c;
    }

    .mp-kpi.mp-good span,
    .mp-kpi.mp-good strong,
    .mp-kpi.mp-good small,
    .mp-kpi.mp-warn span,
    .mp-kpi.mp-warn strong,
    .mp-kpi.mp-warn small,
    .mp-kpi.mp-bad span,
    .mp-kpi.mp-bad strong,
    .mp-kpi.mp-bad small {
      color: #ffffff;
    }

    .mp-chart-card {
      grid-column: span 7;
      min-height: 430px;
      padding: 14px;
    }

    .mp-side-card {
      grid-column: span 5;
      min-height: 340px;
      padding: 14px;
    }

    .mp-table-card {
      grid-column: span 4;
    }

    .mp-material-price-card {
      grid-column: 1 / -1;
      padding: 14px;
    }

    .mp-price-total {
      color: #0f172a;
      font-weight: 800;
    }

    .mp-provider-material-head > div {
      min-width: 0;
    }

    .mp-provider-material-head > div > span {
      display: block;
      margin-top: 3px;
    }

    .mp-cost-formula {
      display: flex;
      align-items: center;
      gap: 9px;
      margin: 0 0 10px;
      padding: 9px 12px;
      border: 1px solid #b8d8d3;
      border-radius: 10px;
      background: #edf8f6;
      color: #134e4a;
      font-size: .8rem;
      font-weight: 800;
    }

    .mp-cost-formula strong {
      color: #0f766e;
    }

    .mp-provider-material-table-wrap {
      max-height: 510px;
    }

    .mp-provider-material-table {
      min-width: 1320px;
    }

    .mp-provider-material-table td {
      vertical-align: middle;
    }

    .mp-comparison-table thead tr:nth-child(2) th {
      top: 37px;
    }

    .mp-week-stack {
      display: grid;
      gap: 4px;
    }

    .mp-week-stack > span {
      display: flex;
      min-height: 28px;
      align-items: center;
      justify-content: inherit;
      padding: 4px 6px;
      border-bottom: 1px solid #e2e8f0;
      white-space: nowrap;
    }

    .mp-week-stack > span:last-child {
      border-bottom: 0;
    }

    .mp-period-group {
      padding: 10px !important;
      color: #ffffff !important;
      text-align: center !important;
      font-size: .8rem !important;
      letter-spacing: .05em !important;
    }

    .mp-period-group small {
      margin-left: 6px;
      color: inherit;
      font-size: .65rem;
      opacity: .9;
    }

    .mp-period-group-monthly {
      background: #0f766e !important;
    }

    .mp-period-group-weekly {
      background: #31516f !important;
    }

    .mp-period-start {
      border-left: 3px solid #cbd5e1 !important;
    }

    .mp-week-label {
      min-width: 185px;
      color: #31516f !important;
      font-size: .75rem;
      font-weight: 900;
      white-space: nowrap;
    }

    .mp-yield-cell {
      color: #ffffff !important;
      font-weight: 900;
    }

    .mp-yield-verde {
      background: #2e8b57 !important;
    }

    .mp-yield-amarillo {
      background: #facc15 !important;
      color: #111827 !important;
    }

    .mp-yield-rojo {
      background: #c94436 !important;
    }

    .mp-yield-gris {
      background: #94a3b8 !important;
    }

    .mp-cost-cell {
      color: #174d6b !important;
      font-weight: 900;
    }

    .mp-provider-chart-card {
      grid-column: 1 / -1;
      padding: 14px;
    }

    .mp-enzyme-card {
      grid-column: 1 / -1;
      padding: 14px;
    }

    .mp-enzyme-material strong {
      display: block;
      max-width: 620px;
      line-height: 1.35;
    }

    .mp-enzyme-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 5px 10px;
      margin-top: 4px;
      color: #64748b;
      font-size: .72rem;
      font-weight: 700;
    }

    .mp-enzyme-type {
      display: inline-flex;
      align-items: center;
      padding: 2px 7px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #075985;
    }

    .mp-provider-chart-wrap {
      position: relative;
      height: 360px;
    }

    .mp-provider-split-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
    }

    .mp-provider-split-card {
      min-width: 0;
      padding: 12px;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #ffffff;
    }

    .mp-provider-split-head {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 8px;
    }

    .mp-provider-split-head h3 {
      margin: 0;
      color: #0f172a;
      font-size: .95rem;
      letter-spacing: 0;
    }

    .mp-provider-split-head span {
      color: #64748b;
      font-size: .72rem;
      font-weight: 800;
    }

    .mp-provider-split-chart {
      position: relative;
      height: 220px;
    }

    .mp-provider-mix {
      display: grid;
      gap: 10px;
    }

    .mp-provider-mix-row {
      display: grid;
      grid-template-columns: minmax(180px, .52fr) minmax(0, 1fr) minmax(90px, .18fr);
      gap: 12px;
      align-items: center;
      padding: 10px 12px;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #ffffff;
    }

    .mp-provider-name {
      min-width: 0;
    }

    .mp-provider-name strong {
      display: block;
      overflow: hidden;
      color: #0f172a;
      font-size: .9rem;
      line-height: 1.15;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .mp-provider-name span,
    .mp-provider-rend span {
      color: #64748b;
      font-size: .74rem;
      font-weight: 700;
    }

    .mp-provider-stack {
      display: flex;
      height: 22px;
      overflow: hidden;
      border-radius: 999px;
      background: #e2e8f0;
      border: 1px solid #dbe7f5;
    }

    .mp-provider-stack i {
      display: block;
      min-width: 2px;
      height: 100%;
    }

    .mp-provider-segments {
      display: flex;
      gap: 8px 12px;
      flex-wrap: wrap;
      margin-top: 6px;
    }

    .mp-provider-segments span {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      color: #64748b;
      font-size: .72rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .mp-provider-segments i {
      width: 8px;
      height: 8px;
      border-radius: 999px;
    }

    .mp-provider-rend {
      text-align: right;
      white-space: nowrap;
    }

    .mp-provider-rend strong {
      display: block;
      color: #0f172a;
      font-size: 1rem;
      line-height: 1.1;
    }

    .mp-section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 10px;
    }

    .mp-section-title h2 {
      margin: 0;
      color: #0f172a;
      font-size: 1rem;
      letter-spacing: 0;
    }

    .mp-section-title span {
      color: #64748b;
      font-size: .78rem;
      font-weight: 700;
    }

    .mp-chart-wrap {
      position: relative;
      height: 280px;
    }

    .mp-chart-card .mp-chart-wrap {
      align-self: center;
      height: 300px;
      transform: translateY(10px);
    }

    .mp-yield-ranges {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 22px;
    }

    .mp-yield-range {
      min-width: 0;
      padding: 11px 12px;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #f8fafc;
    }

    .mp-yield-range strong {
      display: block;
      margin-bottom: 8px;
      color: #0f172a;
      font-size: .9rem;
      line-height: 1.1;
    }

    .mp-yield-range-row {
      display: flex;
      gap: 5px;
      flex-wrap: wrap;
    }

    .mp-yield-pill {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 5px 9px;
      border-radius: 999px;
      color: #ffffff;
      font-size: .76rem;
      font-weight: 800;
      white-space: nowrap;
    }

    .mp-yield-pill.verde {
      background: #2e8b57;
    }

    .mp-yield-pill.amarillo {
      background: #facc15;
      color: #111827;
    }

    .mp-yield-pill.rojo {
      background: #c94436;
    }

    .mp-exec-mix {
      display: grid;
      grid-template-rows: auto auto 1fr;
      gap: 14px;
      min-height: 280px;
    }

    .mp-exec-main {
      display: grid;
      gap: 10px;
      padding: 16px;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #f8fafc;
    }

    .mp-exec-main-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 12px;
    }

    .mp-exec-main h3 {
      margin: 0;
      color: #0f172a;
      font-size: 1.15rem;
      letter-spacing: 0;
    }

    .mp-exec-main strong {
      color: #0f172a;
      font-size: 2.2rem;
      line-height: .95;
      letter-spacing: 0;
    }

    .mp-exec-main small,
    .mp-exec-main span {
      color: #64748b;
      font-size: .78rem;
      font-weight: 800;
    }

    .mp-exec-share {
      display: grid;
      justify-items: end;
      gap: 3px;
      min-width: 88px;
    }

    .mp-exec-share b {
      color: #0f172a;
      font-size: 1.35rem;
      line-height: 1;
    }

    .mp-exec-stack {
      display: flex;
      height: 16px;
      overflow: hidden;
      border-radius: 999px;
      background: #e2e8f0;
      border: 1px solid #dbe7f5;
    }

    .mp-exec-stack i {
      display: block;
      height: 100%;
    }

    .mp-exec-list {
      display: grid;
      gap: 8px;
    }

    .mp-exec-row {
      display: grid;
      grid-template-columns: 20px minmax(0, 1fr) auto;
      gap: 16px;
      align-items: center;
      min-height: 76px;
      padding: 10px 12px 10px 0;
      border: 1px solid #e2e8f0;
      border-radius: 10px;
      background: #ffffff;
      overflow: hidden;
      cursor: pointer;
      transition: transform .16s ease, box-shadow .16s ease;
    }

    .mp-exec-row:hover {
      transform: translateY(-1px);
      box-shadow: 0 10px 24px rgba(15, 23, 42, .09);
    }

    .mp-exec-row.mp-share-good {
      background: #2e8b57;
      border-color: #257447;
    }

    .mp-exec-row.mp-share-bad {
      background: #c94436;
      border-color: #a9362c;
    }

    .mp-exec-row.mp-share-neutral {
      background: #64748b;
      border-color: #475569;
    }

    .mp-exec-row.mp-share-good strong,
    .mp-exec-row.mp-share-good span,
    .mp-exec-row.mp-share-good b,
    .mp-exec-row.mp-share-bad strong,
    .mp-exec-row.mp-share-bad span,
    .mp-exec-row.mp-share-bad b,
    .mp-exec-row.mp-share-neutral strong,
    .mp-exec-row.mp-share-neutral span,
    .mp-exec-row.mp-share-neutral b {
      color: #ffffff;
    }

    .mp-exec-row-name {
      display: flex;
      align-items: center;
      min-width: 0;
    }

    .mp-exec-color {
      width: 100%;
      height: calc(100% + 22px);
      min-height: 86px;
      background: #2563eb;
    }

    .mp-exec-row strong {
      color: #0f172a;
      font-size: 1rem;
      line-height: 1.15;
      white-space: normal;
    }

    .mp-exec-row span,
    .mp-exec-range {
      color: #64748b;
      font-size: .74rem;
      font-weight: 700;
    }

    .mp-exec-range {
      display: block;
      margin-top: 4px;
    }

    .mp-exec-meta {
      display: block;
      margin-top: 6px;
      opacity: .9;
    }

    .mp-exec-row-value {
      text-align: right;
      white-space: nowrap;
    }

    .mp-exec-row-value b {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 66px;
      min-height: 28px;
      padding: 5px 9px;
      border: 1px solid #dbe7f5;
      border-radius: 999px;
      background: #f8fafc;
      color: #0f172a;
      font-size: .95rem;
      line-height: 1.1;
    }

    .mp-exec-row-value span {
      display: block;
      margin-top: 5px;
    }

    .mp-exec-row.mp-share-good .mp-exec-row-value b,
    .mp-exec-row.mp-share-bad .mp-exec-row-value b,
    .mp-exec-row.mp-share-neutral .mp-exec-row-value b {
      background: rgba(255, 255, 255, .16);
      border-color: rgba(255, 255, 255, .32);
    }

    .mp-pie-board {
      display: grid;
      grid-template-columns: minmax(250px, 1fr) minmax(210px, .78fr);
      gap: 18px;
      align-items: center;
      min-height: 280px;
    }

    .mp-pie-stage {
      display: grid;
      place-items: center;
      min-height: 280px;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #f8fafc;
    }

    .mp-pie-stage .mp-chart-wrap {
      width: min(100%, 330px);
      height: 260px;
    }

    .mp-pie-summary {
      display: grid;
      gap: 12px;
    }

    .mp-pie-total {
      padding: 14px;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #ffffff;
    }

    .mp-pie-total span {
      color: #64748b;
      font-size: .74rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    .mp-pie-total strong {
      display: block;
      margin-top: 5px;
      color: #0f172a;
      font-size: 2rem;
      line-height: 1;
      letter-spacing: 0;
    }

    .mp-pie-total small {
      color: #64748b;
      font-size: .78rem;
      font-weight: 700;
    }

    .mp-table-wrap {
      max-height: 390px;
      overflow: auto;
    }

    .mp-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
    }

    .mp-table th {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f8fafc;
      color: #475569;
      padding: 9px 10px;
      text-align: left;
      font-size: .68rem;
      letter-spacing: .07em;
      text-transform: uppercase;
      border-bottom: 1px solid #e2e8f0;
    }

    .mp-table th.mp-num {
      text-align: right;
    }

    .mp-table td {
      padding: 9px 10px;
      color: #0f172a;
      border-bottom: 1px solid #edf2f7;
      vertical-align: top;
    }

    .mp-table tr:nth-child(even) td {
      background: #fafafa;
    }

    .mp-num {
      text-align: right;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }

    .mp-muted {
      color: #64748b;
      font-size: .74rem;
      font-weight: 700;
    }

    .mp-modal {
      position: fixed;
      inset: 0;
      z-index: 100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 22px;
      background: rgba(15, 23, 42, .48);
    }

    .mp-modal.is-open {
      display: flex;
    }

    .mp-modal-panel {
      width: min(980px, 100%);
      max-height: min(86vh, 760px);
      overflow: auto;
      border-radius: 14px;
      background: #ffffff;
      box-shadow: 0 24px 80px rgba(15, 23, 42, .32);
    }

    .mp-modal-head {
      display: grid;
      grid-template-columns: 12px minmax(0, 1fr) auto;
      gap: 14px;
      align-items: center;
      padding: 16px 18px;
      border-bottom: 1px solid #e2e8f0;
    }

    .mp-modal-color {
      width: 12px;
      height: 56px;
      border-radius: 999px;
      background: #2563eb;
    }

    .mp-modal-head h2 {
      margin: 0;
      color: #0f172a;
      font-size: 1.35rem;
    }

    .mp-modal-head p {
      margin: 3px 0 0;
      color: #64748b;
      font-size: .82rem;
      font-weight: 700;
    }

    .mp-modal-close {
      width: 38px;
      height: 38px;
      border: 1px solid #cbd5e1;
      border-radius: 999px;
      background: #f8fafc;
      color: #0f172a;
      font-size: 1.2rem;
      font-weight: 900;
      cursor: pointer;
    }

    .mp-modal-body {
      display: grid;
      gap: 14px;
      padding: 16px 18px 18px;
    }

    .mp-modal-kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
    }

    .mp-modal-kpi {
      padding: 12px;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #f8fafc;
    }

    .mp-modal-kpi span {
      display: block;
      color: #64748b;
      font-size: .68rem;
      font-weight: 900;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    .mp-modal-kpi strong {
      display: block;
      margin-top: 5px;
      color: #0f172a;
      font-size: 1.15rem;
      line-height: 1;
    }

    .mp-modal-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .mp-modal-section {
      min-width: 0;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      overflow: hidden;
    }

    .mp-modal-section h3 {
      margin: 0;
      padding: 10px 12px;
      background: #f8fafc;
      color: #0f172a;
      font-size: .86rem;
    }

    .mp-modal-table-wrap {
      max-height: 260px;
      overflow: auto;
    }

    .mp-empty {
      padding: 14px;
      color: #64748b;
      font-size: .82rem;
      font-weight: 800;
      text-align: center;
      background: #f8fafc;
    }

    @media (max-width: 1180px) {
      .mp-kpis {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }

      .mp-chart-card,
      .mp-side-card,
      .mp-table-card,
      .mp-material-price-card,
      .mp-enzyme-card {
        grid-column: 1 / -1;
      }

      .mp-pie-board {
        grid-template-columns: 1fr;
      }

      .mp-provider-split-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 720px) {
      .mp-dashboard {
        padding: 14px;
      }

      .mp-kpis {
        grid-template-columns: 1fr;
      }

      .mp-filter-field,
      .mp-filter-field.wide {
        min-width: 100%;
      }

      .mp-modal {
        padding: 10px;
      }

      .mp-modal-kpis,
      .mp-modal-grid {
        grid-template-columns: 1fr;
      }

      .mp-provider-material-head {
        align-items: stretch;
        flex-direction: column;
      }

    }
  </style>
</head>

<body>
  <div class="dashboard mp-dashboard">
    <header class="mp-header">
      <div class="mp-header-actions">
        <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Regresar al inicio</a>
      </div>
      <div class="mp-title">
        <h1><?= $e($titulo ?? 'Materia Prima') ?></h1>
        <p><?= $e($meta['periodo_label'] ?? (($filtros['mes_nombre'] ?? '') . ' ' . ($filtros['anio'] ?? ''))) ?> · <?= $e($meta['periodo_inicio'] ?? '') ?> al <?= $e($meta['periodo_fin'] ?? '') ?></p>
      </div>
    </header>

    <form class="mp-filter-form" method="get">
      <input type="hidden" id="periodo" name="periodo" value="<?= $e($periodMode) ?>">
      <div class="mp-period-control">
        <label>Periodo</label>
        <div class="mp-period-switch" role="group" aria-label="Seleccionar periodo">
          <button class="mp-period-btn <?= $periodMode === 'mes' ? 'is-active' : '' ?>" type="button" data-period-mode="mes">Mes</button>
          <button class="mp-period-btn <?= $periodMode === 'semana' ? 'is-active' : '' ?>" type="button" data-period-mode="semana">Semana</button>
          <button class="mp-period-btn <?= $periodMode === 'fecha' ? 'is-active' : '' ?>" type="button" data-period-mode="fecha">Fecha</button>
        </div>
      </div>
      <div class="mp-period-values">
        <label>Selección</label>
        <div class="mp-period-panel <?= $periodMode === 'mes' ? 'is-active' : '' ?>" data-period-panel="mes">
          <div class="mp-filter-field">
            <label for="anio">Año</label>
            <select id="anio" name="anio" data-period-input="mes">
              <?php foreach ((array)($filtros['anios'] ?? []) as $anio): ?>
                <option value="<?= (int)$anio ?>" <?= (int)$anio === (int)($filtros['anio'] ?? 0) ? 'selected' : '' ?>><?= (int)$anio ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mp-filter-field">
            <label for="mes">Mes</label>
            <select id="mes" name="mes" data-period-input="mes">
              <?php foreach ((array)($filtros['meses'] ?? []) as $monthNumber => $monthName): ?>
                <option value="<?= (int)$monthNumber ?>" <?= (int)$monthNumber === (int)($filtros['mes'] ?? 0) ? 'selected' : '' ?>><?= $e($monthName) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="mp-period-panel <?= $periodMode === 'semana' ? 'is-active' : '' ?>" data-period-panel="semana">
          <div class="mp-filter-field"><label for="semana_inicio">Semana inicio</label><input id="semana_inicio" name="semana_inicio" type="week" value="<?= $e($filtros['semana_inicio'] ?? $filtros['semana'] ?? '') ?>" data-period-input="semana"></div>
          <div class="mp-filter-field"><label for="semana_fin">Semana fin</label><input id="semana_fin" name="semana_fin" type="week" value="<?= $e($filtros['semana_fin'] ?? $filtros['semana'] ?? '') ?>" data-period-input="semana"></div>
        </div>
        <div class="mp-period-panel <?= $periodMode === 'fecha' ? 'is-active' : '' ?>" data-period-panel="fecha">
          <a class="mp-today" href="<?= $e($todayUrl) ?>"><i class="fas fa-bullseye"></i> Hoy</a>
          <div class="mp-filter-field"><label for="fecha_inicio">Inicio</label><input id="fecha_inicio" name="fecha_inicio" type="date" value="<?= $e($filtros['fecha_inicio'] ?? $filtros['fecha'] ?? '') ?>" data-period-input="fecha"></div>
          <div class="mp-filter-field"><label for="fecha_fin">Fin</label><input id="fecha_fin" name="fecha_fin" type="date" value="<?= $e($filtros['fecha_fin'] ?? $filtros['fecha'] ?? '') ?>" data-period-input="fecha"></div>
        </div>
      </div>
      <div class="mp-filter-field wide">
        <label for="mt_id">Grupo material</label>
        <select id="mt_id" name="mt_id">
          <option value="all" <?= ($filtros['mt_id'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todos</option>
          <?php foreach ((array)($filtros['tipos_material'] ?? []) as $type): ?>
            <option value="<?= (int)$type['id'] ?>" <?= (string)$type['id'] === (string)($filtros['mt_id'] ?? '') ? 'selected' : '' ?>><?= $e($type['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit"><i class="fas fa-filter"></i> Aplicar</button>
    </form>

    <main class="mp-grid">
      <section class="mp-kpis">
        <article class="mp-kpi">
          <span>Consumo MP</span>
          <strong><?= $fmt($kpis['toneladas_consumidas'] ?? null, 1) ?></strong>
          <small>toneladas</small>
        </article>
        <article class="mp-kpi <?= $e($productionClass) ?>">
          <span>Producción</span>
          <strong><?= $fmt($kpis['toneladas_producidas'] ?? null, 1) ?></strong>
          <small>meta <?= $fmt($productionGreenTarget, 0) ?> t</small>
        </article>
        <article class="mp-kpi <?= $e($rendClass) ?>">
          <span>Rendimiento</span>
          <strong><?= $fmtPct($kpis['rendimiento'] ?? null, 2) ?></strong>
          <small>base <?= $fmt($kpis['toneladas_producidas_rendimiento'] ?? null, 1) ?> t cerradas + barredura</small>
        </article>
        <article class="mp-kpi">
          <span>Procesos</span>
          <strong><?= $fmtInt($kpis['procesos'] ?? null) ?></strong>
          <small><?= $fmtInt($kpis['partidas'] ?? null) ?> partidas</small>
        </article>
        <article class="mp-kpi">
          <span>Proveedores</span>
          <strong><?= $fmtInt($kpis['proveedores'] ?? null) ?></strong>
          <small>con consumo</small>
        </article>
        <article class="mp-kpi">
          <span>Materiales</span>
          <strong><?= $fmtInt($kpis['materiales'] ?? null) ?></strong>
          <small>materiales y familias</small>
        </article>
        <article class="mp-kpi">
          <span>Precio total</span>
          <strong><?= $fmtMoney($kpis['precio_total_promedio'] ?? null) ?></strong>
          <small>compra + maquila</small>
        </article>
      </section>
      
      <section class="mp-card mp-side-card">
        <div class="mp-section-title">
          <h2>Consumo por material</h2>
          <span>Participación de MP</span>
        </div>
        <div class="mp-pie-board">
          <div class="mp-pie-stage">
            <div class="mp-chart-wrap">
              <canvas id="groupChart"></canvas>
            </div>
          </div>

          <div class="mp-pie-summary">
            <div class="mp-pie-total">
              <span>Total consumo</span>
              <strong><?= $fmt($groupTotal, 1) ?></strong>
              <small>toneladas de materia prima</small>
            </div>

            <div class="mp-exec-list">
              <?php foreach ($groupCards as $row): ?>
                <div class="mp-exec-row <?= $e($row['participacion_clase']) ?>" role="button" tabindex="0" data-detail-trigger="consumo" data-group="<?= $e($row['grupo']) ?>">
                  <i class="mp-exec-color" style="background: <?= $e($row['color']) ?>;"></i>
                  <div>
                    <div class="mp-exec-row-name">
                      <strong><?= $e($row['grupo']) ?></strong>
                    </div>
                    <span class="mp-exec-range">Rango <?= $e($row['participacion_rango']) ?></span>
                    <span class="mp-exec-meta"><?= $fmt($row['toneladas'], 1) ?> ton · <?= $fmtInt($row['materiales']) ?> mat.</span>
                  </div>
                  <div class="mp-exec-row-value">
                    <b><?= $fmt($row['participacion'], 1) ?>%</b>
                    <span><?= $e($row['participacion_estado']) ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </section>

      <section class="mp-card mp-chart-card">
        <div class="mp-section-title">
          <h2>Rendimiento por material</h2>
          <span>Producción / MP</span>
        </div>
        <div class="mp-chart-wrap">
          <canvas id="yieldChart"></canvas>
        </div>
        <div class="mp-yield-ranges" aria-label="Rangos de rendimiento por material">
          <?php foreach ($yieldRangeCards as $range): ?>
            <div class="mp-yield-range">
              <strong><?= $e($range['grupo']) ?></strong>
              <div class="mp-yield-range-row">
                <span class="mp-yield-pill verde">Verde <?= $e($range['verde']) ?></span>
                <span class="mp-yield-pill amarillo">Amarillo <?= $e($range['amarillo']) ?></span>
                <span class="mp-yield-pill rojo">Rojo <?= $e($range['rojo']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="mp-card mp-material-price-card">
        <div class="mp-section-title mp-provider-material-head">
          <div>
            <h2>Proveedor por material</h2>
            <span><?= $periodMode !== 'mes' ? 'Comparación del periodo contra su última semana' : 'Comparación mensual contra la última semana del periodo' ?></span>
          </div>
          <span><?= $fmtInt(count($providerMaterialComparisonGroups)) ?> combinaciones</span>
        </div>

        <div class="mp-cost-formula">
          <i class="fas fa-calculator" aria-hidden="true"></i>
          <span><strong>Fórmula del costo:</strong> Precio ajustado de cuero (MXN/kg) ÷ rendimiento (en decimal) · C/P o Con pelo + $1.50 · Depilado/a + $0.50</span>
        </div>

        <div class="mp-table-wrap mp-provider-material-table-wrap">
          <table class="mp-table mp-provider-material-table mp-comparison-table">
            <thead>
              <tr>
                <th rowspan="2">Proveedor</th>
                <th rowspan="2">Material</th>
                <th colspan="3" class="mp-period-group mp-period-group-monthly"><?= $periodMode !== 'mes' ? 'Periodo' : 'Mensual' ?> <small><?= $e($meta['periodo_label'] ?? (ucfirst((string)($filtros['mes_nombre'] ?? '')) . ' ' . (string)($filtros['anio'] ?? ''))) ?></small></th>
                <th colspan="4" class="mp-period-group mp-period-group-weekly">Semanal</th>
              </tr>
              <tr>
                <th class="mp-num mp-period-start">Precio</th>
                <th class="mp-num">Rendimiento</th>
                <th class="mp-num">Costo / kg prod.</th>
                <th class="mp-period-start">Semana</th>
                <th class="mp-num">Precio</th>
                <th class="mp-num">Rendimiento</th>
                <th class="mp-num">Costo / kg prod.</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($providerMaterialComparisonGroups === []): ?>
                <tr><td class="mp-empty" colspan="9">Sin información para el periodo seleccionado.</td></tr>
              <?php else: ?>
                <?php foreach ($providerMaterialComparisonGroups as $comparisonGroup): ?>
                  <?php
                    $identityRow = (array)($comparisonGroup['identidad'] ?? []);
                    $monthlyRow = (array)($comparisonGroup['mensual'] ?? []);
                    $weeklyRows = (array)($comparisonGroup['semanas'] ?? []);
                    $monthlyYieldKey = $yieldStatusKey((string)($monthlyRow['grupo'] ?? ''), $monthlyRow['rendimiento'] ?? null);
                  ?>
                  <tr>
                    <td><strong><?= $e($identityRow['proveedor'] ?? 'Sin proveedor') ?></strong></td>
                    <td><strong><?= $e($identityRow['material'] ?? 'Sin material') ?></strong></td>
                    <td class="mp-num mp-price-total mp-period-start"><?= $fmtMoney($monthlyRow['precio_proveedor'] ?? null) ?></td>
                    <td class="mp-num mp-yield-cell mp-yield-<?= $e($monthlyYieldKey) ?>"><?= $fmtPct($monthlyRow['rendimiento'] ?? null, 2) ?></td>
                    <td class="mp-num mp-cost-cell"><?= $fmtMoney($monthlyRow['costo_cuero_kg_produccion'] ?? null) ?></td>
                    <td class="mp-week-label mp-period-start"><div class="mp-week-stack">
                      <?php if ($weeklyRows === []): ?><span>Sin semana</span><?php else: ?>
                        <?php foreach ($weeklyRows as $weeklyRow): ?><span><?= $e($weeklyRow['periodo_etiqueta'] ?? 'Sin semana') ?></span><?php endforeach; ?>
                      <?php endif; ?>
                    </div></td>
                    <td class="mp-num mp-price-total"><div class="mp-week-stack">
                      <?php if ($weeklyRows === []): ?><span>-</span><?php else: ?>
                        <?php foreach ($weeklyRows as $weeklyRow): ?><span><?= $fmtMoney($weeklyRow['precio_proveedor'] ?? null) ?></span><?php endforeach; ?>
                      <?php endif; ?>
                    </div></td>
                    <td class="mp-num"><div class="mp-week-stack">
                      <?php if ($weeklyRows === []): ?><span>-</span><?php else: ?>
                        <?php foreach ($weeklyRows as $weeklyRow): $weeklyYieldKey = $yieldStatusKey((string)($weeklyRow['grupo'] ?? ''), $weeklyRow['rendimiento'] ?? null); ?><span class="mp-yield-cell mp-yield-<?= $e($weeklyYieldKey) ?>"><?= $fmtPct($weeklyRow['rendimiento'] ?? null, 2) ?></span><?php endforeach; ?>
                      <?php endif; ?>
                    </div></td>
                    <td class="mp-num mp-cost-cell"><div class="mp-week-stack">
                      <?php if ($weeklyRows === []): ?><span>-</span><?php else: ?>
                        <?php foreach ($weeklyRows as $weeklyRow): ?><span><?= $fmtMoney($weeklyRow['costo_cuero_kg_produccion'] ?? null) ?></span><?php endforeach; ?>
                      <?php endif; ?>
                    </div></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="mp-card mp-provider-chart-card">
        <div class="mp-section-title">
          <h2>Rendimiento por proveedor</h2>
          <span>Separado por material</span>
        </div>
        <div class="mp-provider-split-grid">
          <?php foreach ($providerYieldByMaterial as $index => $chart): ?>
            <article class="mp-provider-split-card">
              <div class="mp-provider-split-head">
                <h3><?= $e($chart['grupo']) ?></h3>
                <span><?= $fmtInt(count($chart['labels'])) ?> proveedores</span>
              </div>
              <div class="mp-provider-split-chart">
                <canvas id="providerMaterialChart<?= (int)$index ?>"></canvas>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="mp-card mp-enzyme-card">
        <div class="mp-section-title">
          <h2>Enzima por proceso y material</h2>
          <span>Etapa 2B · última captura disponible por proceso</span>
        </div>
        <div class="mp-table-wrap">
          <table class="mp-table">
            <thead>
              <tr>
                <th>Material</th>
                <th class="mp-num">Hr enzima</th>
                <th class="mp-num">Lts / ton</th>
                <th class="mp-num">Sólidos</th>
                <th class="mp-num">Extractibilidad</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($enzymeProcessRows === []): ?>
                <tr>
                  <td colspan="5" class="mp-empty">No hay procesos Enzima Plus o Enzima Alpha en el periodo.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($enzymeProcessRows as $row): ?>
                  <tr>
                    <td class="mp-enzyme-material">
                      <strong><?= $e($row['material'] ?? 'Sin material') ?></strong>
                      <div class="mp-enzyme-meta">
                        <span>P<?= $fmtInt($row['pro_id'] ?? null) ?></span>
                        <span><?= $e($row['fecha'] ?? '') ?></span>
                        <span class="mp-enzyme-type"><?= $e($row['preparacion'] ?? '') ?></span>
                      </div>
                    </td>
                    <td class="mp-num"><?= $fmt($row['horas_enzima'] ?? null, 2) ?></td>
                    <td class="mp-num"><?= $fmt($row['litros_tonelada'] ?? null, 2) ?></td>
                    <td class="mp-num"><?= $fmt($row['solidos'] ?? null, 2) ?></td>
                    <td class="mp-num"><?= $fmtPct($row['extractibilidad'] ?? null, 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <div class="mp-modal" id="mpDetailModal" aria-hidden="true">
    <div class="mp-modal-panel" role="dialog" aria-modal="true" aria-labelledby="mpModalTitle">
      <div class="mp-modal-head">
        <i class="mp-modal-color" id="mpModalColor"></i>
        <div>
          <h2 id="mpModalTitle">Detalle</h2>
          <p id="mpModalSubtitle"></p>
        </div>
        <button class="mp-modal-close" type="button" id="mpModalClose" aria-label="Cerrar">×</button>
      </div>
      <div class="mp-modal-body" id="mpModalBody"></div>
    </div>
  </div>

  <script>
    const yieldLabels = <?= json_encode($yieldLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const yieldData = <?= json_encode($yieldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const yieldColors = <?= json_encode($yieldColors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const groupLabels = <?= json_encode($groupLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const groupData = <?= json_encode($groupData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const groupPalette = <?= json_encode($groupPalette, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const providerYieldByMaterial = <?= json_encode($providerYieldByMaterial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const groupDetails = <?= json_encode($groupDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
    Chart.defaults.color = '#475569';

    const fmtNumber = (value, decimals = 1) => {
      if (!Number.isFinite(Number(value))) return '-';
      const precision = Math.max(0, Math.min(2, Math.trunc(Number(decimals) || 0)));
      return Number(value).toLocaleString('en-US', {
        minimumFractionDigits: precision,
        maximumFractionDigits: precision
      });
    };
    const fmtInt = (value) => Number.isFinite(Number(value)) ?
      Number(value).toLocaleString('en-US', {
        maximumFractionDigits: 0
      }) :
      '-';
    const fmtPct = (value, decimals = 2) => Number.isFinite(Number(value)) ? `${fmtNumber(value, decimals)} %` : '-';
    const fmtMoney = (value) => Number.isFinite(Number(value)) ? `$ ${fmtNumber(value, 2)}` : '-';
    const escapeHtml = (value) => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');

    const modal = document.getElementById('mpDetailModal');
    const modalTitle = document.getElementById('mpModalTitle');
    const modalSubtitle = document.getElementById('mpModalSubtitle');
    const modalColor = document.getElementById('mpModalColor');
    const modalBody = document.getElementById('mpModalBody');
    const modalClose = document.getElementById('mpModalClose');

    const tableHtml = (headers, rows) => {
      if (!rows.length) {
        return '<div class="mp-empty">Sin datos para este grupo.</div>';
      }

      return `
        <div class="mp-modal-table-wrap">
          <table class="mp-table">
            <thead><tr>${headers.map((header) => `<th class="${header.num ? 'mp-num' : ''}">${escapeHtml(header.label)}</th>`).join('')}</tr></thead>
            <tbody>
              ${rows.map((row) => `<tr>${headers.map((header) => `<td class="${header.num ? 'mp-num' : ''}">${row[header.key] ?? '-'}</td>`).join('')}</tr>`).join('')}
            </tbody>
          </table>
        </div>
      `;
    };

    const openGroupModal = (groupName, mode = 'consumo') => {
      const detail = groupDetails[groupName];
      if (!detail || !modal) return;

      const consumo = detail.consumo || {};
      const rendimiento = detail.rendimiento || {};
      const precio = detail.precio || {};
      const color = detail.color || '#2563eb';

      modalTitle.textContent = mode === 'rendimiento' ? `Rendimiento · ${groupName}` : `Consumo · ${groupName}`;
      modalSubtitle.textContent = mode === 'rendimiento' ?
        'Producción asignada, materia prima y proveedores del grupo.' :
        'Participación, materiales y compras relacionadas del grupo.';
      modalColor.style.background = color;

      const materialRows = (detail.materiales || []).map((row) => ({
        material: escapeHtml(row.material || ''),
        toneladas: `${fmtNumber(row.toneladas, 1)} t`,
        proveedores: fmtInt(row.proveedores),
        procesos: fmtInt(row.procesos)
      }));
      const providerRows = (detail.proveedores || []).map((row) => ({
        proveedor: escapeHtml(row.proveedor || ''),
        rendimiento: fmtPct(row.rendimiento, 2),
        consumo: `${fmtNumber(row.toneladas_consumidas, 1)} t`,
        produccion: `${fmtNumber(row.toneladas_producidas, 1)} t`
      }));

      modalBody.innerHTML = `
        <div class="mp-modal-kpis">
          <div class="mp-modal-kpi"><span>Consumo</span><strong>${fmtNumber(consumo.toneladas, 1)} t</strong></div>
          <div class="mp-modal-kpi"><span>Participación</span><strong>${fmtPct(consumo.participacion, 1)}</strong></div>
          <div class="mp-modal-kpi"><span>Rendimiento</span><strong>${fmtPct(rendimiento.rendimiento, 2)}</strong></div>
          <div class="mp-modal-kpi"><span>Precio total</span><strong>${fmtMoney(precio.precio_total)}</strong></div>
        </div>
        <div class="mp-modal-grid">
          <section class="mp-modal-section">
            <h3>Materiales</h3>
            ${tableHtml([
              { key: 'material', label: 'Material' },
              { key: 'toneladas', label: 'Ton', num: true },
              { key: 'proveedores', label: 'Prov.', num: true },
              { key: 'procesos', label: 'Proc.', num: true }
            ], materialRows)}
          </section>
          <section class="mp-modal-section">
            <h3>Proveedores / rendimiento</h3>
            ${tableHtml([
              { key: 'proveedor', label: 'Proveedor' },
              { key: 'rendimiento', label: 'Rend.', num: true },
              { key: 'consumo', label: 'MP', num: true },
              { key: 'produccion', label: 'Prod.', num: true }
            ], providerRows)}
          </section>
        </div>
      `;

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      modalClose?.focus();
    };

    const closeGroupModal = () => {
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    };

    const percentAxisBounds = (values) => {
      const numbers = values
        .map((value) => Number(value))
        .filter((value) => Number.isFinite(value));

      if (!numbers.length) {
        return {
          beginAtZero: true
        };
      }

      const minValue = Math.min(...numbers);
      const maxValue = Math.max(...numbers);
      const range = Math.max(1, maxValue - minValue);
      const min = minValue <= 0 ? 0 : Math.max(0, Math.floor((minValue - (range * 0.24)) * 2) / 2);
      const max = Math.ceil((maxValue + (range * 0.22)) * 2) / 2;

      return {
        beginAtZero: false,
        min,
        max: Math.max(max, min + 1)
      };
    };

    const barValueLabels = {
      id: 'barValueLabels',
      afterDatasetsDraw(chart) {
        if (chart.config.type !== 'bar') return;

        const {
          ctx,
          chartArea
        } = chart;
        const horizontal = chart.options.indexAxis === 'y';

        ctx.save();
        ctx.font = '700 11px Inter, system-ui, sans-serif';
        ctx.textBaseline = 'middle';

        chart.data.datasets.forEach((dataset, datasetIndex) => {
          const meta = chart.getDatasetMeta(datasetIndex);
          if (meta.hidden) return;

          meta.data.forEach((bar, index) => {
            const value = Number(dataset.data?.[index] || 0);
            if (!Number.isFinite(value)) return;

            const label = `${value.toFixed(2)}%`;
            const position = bar.tooltipPosition();
            const labelWidth = ctx.measureText(label).width;

            if (horizontal) {
              let x = position.x + 8;
              let align = 'left';
              let color = '#0f172a';

              if (x + labelWidth > chartArea.right) {
                x = position.x - 8;
                align = 'right';
                color = '#ffffff';
              }

              ctx.textAlign = align;
              ctx.fillStyle = color;
              ctx.fillText(label, x, position.y);
              return;
            }

            let y = position.y - 10;
            let color = '#0f172a';
            if (y < chartArea.top + 6) {
              y = position.y + 12;
              color = '#ffffff';
            }

            ctx.textAlign = 'center';
            ctx.fillStyle = color;
            ctx.fillText(label, position.x, y);
          });
        });

        ctx.restore();
      }
    };

    Chart.register(barValueLabels);

    const yieldChart = new Chart(document.getElementById('yieldChart'), {
      type: 'bar',
      data: {
        labels: yieldLabels,
        datasets: [{
          label: 'Rendimiento',
          data: yieldData,
          backgroundColor: yieldColors,
          borderRadius: 4,
          maxBarThickness: 42
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        onClick: (_event, elements) => {
          const first = elements?.[0];
          if (!first) return;
          const groupName = yieldLabels[first.index];
          openGroupModal(groupName, 'rendimiento');
        },
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: (context) => `${Number(context.raw || 0).toFixed(2)} %`
            }
          }
        },
        scales: {
          x: {
            beginAtZero: true,
            ticks: {
              callback: (value) => `${value}%`
            }
          },
          y: {
            grid: {
              display: false
            }
          }
        }
      }
    });

    document.querySelectorAll('[data-detail-trigger="consumo"]').forEach((card) => {
      const open = () => openGroupModal(card.dataset.group || '', 'consumo');
      card.addEventListener('click', open);
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          open();
        }
      });
    });

    modalClose?.addEventListener('click', closeGroupModal);
    modal?.addEventListener('click', (event) => {
      if (event.target === modal) {
        closeGroupModal();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeGroupModal();
      }
    });

    new Chart(document.getElementById('groupChart'), {
      type: 'pie',
      data: {
        labels: groupLabels,
        datasets: [{
          data: groupData,
          backgroundColor: groupPalette,
          borderColor: '#ffffff',
          borderWidth: 3,
          hoverOffset: 8
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const total = context.dataset.data.reduce((sum, value) => sum + Number(value || 0), 0);
                const value = Number(context.raw || 0);
                const pct = total > 0 ? (value / total) * 100 : 0;
                return `${context.label}: ${value.toLocaleString('en-US', { maximumFractionDigits: 1 })} ton (${pct.toFixed(1)}%)`;
              }
            }
          }
        }
      }
    });

    providerYieldByMaterial.forEach((chart, index) => {
      const canvas = document.getElementById(`providerMaterialChart${index}`);
      if (!canvas) return;

      new Chart(canvas, {
        type: 'bar',
        data: {
          labels: chart.labels,
          datasets: [{
            label: 'Rendimiento',
            data: chart.data,
            backgroundColor: chart.colors,
            borderRadius: 4,
            maxBarThickness: 32
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              display: false
            },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const consumo = Number(chart.consumo?.[context.dataIndex] || 0).toLocaleString('en-US', {
                    maximumFractionDigits: 1
                  });
                  return `${Number(context.raw || 0).toFixed(2)}% · ${consumo} ton MP`;
                }
              }
            }
          },
          scales: {
            x: {
              grid: {
                display: false
              },
              ticks: {
                maxRotation: 35,
                minRotation: 35,
                font: {
                  size: 9,
                  weight: 700
                }
              }
            },
            y: {
              ...percentAxisBounds(chart.data),
              ticks: {
                callback: (value) => `${value}%`
              }
            }
          }
        }
      });
    });

    const filterForm = document.querySelector('.mp-filter-form');
    if (filterForm) {
      const submitButton = filterForm.querySelector('button[type="submit"]');
      const periodInput = document.getElementById('periodo');
      let filterTimer = null;

      filterForm.querySelectorAll('[data-period-mode]').forEach((button) => {
        button.addEventListener('click', () => {
          const mode = button.dataset.periodMode;
          if (!mode || !periodInput) return;
          periodInput.value = mode;
          filterForm.querySelectorAll('[data-period-mode]').forEach((item) => item.classList.toggle('is-active', item === button));
          filterForm.querySelectorAll('[data-period-panel]').forEach((panel) => panel.classList.toggle('is-active', panel.dataset.periodPanel === mode));
          if (mode === 'mes') filterForm.requestSubmit();
          if (mode === 'semana') document.getElementById('semana_inicio')?.focus();
          if (mode === 'fecha') document.getElementById('fecha_inicio')?.focus();
        });
      });

      filterForm.querySelectorAll('select').forEach((select) => {
        select.addEventListener('change', () => {
          if (select.hasAttribute('data-period-input') && periodInput) {
            periodInput.value = select.dataset.periodInput || 'mes';
          }
          window.clearTimeout(filterTimer);
          filterTimer = window.setTimeout(() => {
            filterForm.classList.add('is-applying');
            if (submitButton) {
              submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando';
            }
            filterForm.requestSubmit();
          }, 180);
        });
      });

      filterForm.querySelectorAll('input[type="date"],input[type="week"]').forEach((input) => {
        input.addEventListener('change', () => {
          const mode = input.dataset.periodInput || 'fecha';
          if (periodInput) periodInput.value = mode;
          if (mode === 'semana') {
            const start = document.getElementById('semana_inicio')?.value;
            const end = document.getElementById('semana_fin')?.value;
            if (start && end) filterForm.requestSubmit();
            return;
          }
          const start = document.getElementById('fecha_inicio')?.value;
          const end = document.getElementById('fecha_fin')?.value;
          if (start && end) filterForm.requestSubmit();
        });
      });
    }

    window.setTimeout(() => {
      window.location.reload();
    }, Number(<?= (int)($meta['intervaloActualizacion'] ?? 1800000) ?>));
  </script>
</body>

</html>
