<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$appConfig = $appConfig ?? require __DIR__ . '/../../config/app.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'America/Mazatlan');
date_default_timezone_set($timezone);
$tz = new DateTimeZone($timezone);

$monthNames = [
  1 => 'enero',
  2 => 'febrero',
  3 => 'marzo',
  4 => 'abril',
  5 => 'mayo',
  6 => 'junio',
  7 => 'julio',
  8 => 'agosto',
  9 => 'septiembre',
  10 => 'octubre',
  11 => 'noviembre',
  12 => 'diciembre',
];

$safeInt = static function ($value, int $fallback, int $min, int $max): int {
  if (!is_scalar($value) || !is_numeric($value)) {
    return $fallback;
  }

  $number = (int)$value;
  return ($number >= $min && $number <= $max) ? $number : $fallback;
};

$horaCorte = (string)($config['hora_corte'] ?? '07:00:00');
$barreduraProId = (int)($config['barredura_pro_id'] ?? 2);
if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $horaCorte) !== 1) {
  $horaCorte = '07:00:00';
}

$pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'prod')] ?? $dbConfig['prod']));
$currentDate = new DateTimeImmutable('now', $tz);
$selectedYear = $safeInt($_GET['anio'] ?? null, (int)$currentDate->format('Y'), 2020, 2100);
$selectedMonth = $safeInt($_GET['mes'] ?? null, (int)$currentDate->format('n'), 1, 12);
$selectedMaterialType = trim((string)($_GET['mt_id'] ?? 'all'));

$yearStmt = $pdo->query("
  SELECT DISTINCT YEAR(pma_fe_entrada) AS anio
  FROM procesos_materiales
  WHERE pma_fe_entrada IS NOT NULL
  ORDER BY anio DESC
");
$yearOptions = array_values(array_filter(array_map('intval', array_column($yearStmt->fetchAll() ?: [], 'anio'))));
if (!in_array($selectedYear, $yearOptions, true)) {
  $yearOptions[] = $selectedYear;
  rsort($yearOptions);
}

$typeStmt = $pdo->query("
  SELECT mt_id, mt_descripcion
  FROM materiales_tipo
  WHERE mt_est = 'A'
  ORDER BY mt_descripcion
");
$materialTypes = [];
foreach ($typeStmt->fetchAll() ?: [] as $row) {
  $materialTypes[(string)$row['mt_id']] = [
    'id' => (int)$row['mt_id'],
    'label' => (string)$row['mt_descripcion'],
  ];
}
if ($selectedMaterialType !== 'all' && !isset($materialTypes[$selectedMaterialType])) {
  $selectedMaterialType = 'all';
}

$periodStart = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
$periodEnd = $periodStart->modify('first day of next month');
$startDate = $periodStart->format('Y-m-d');
$endDate = $periodEnd->format('Y-m-d');
$startDateTime = $periodStart->format('Y-m-d 00:00:00');
$endDateTime = $periodEnd->modify('+7 hours')->format('Y-m-d H:i:s');
$operationDateSql = "DATE(CASE WHEN TIME(t.tar_fecha) < '{$horaCorte}' THEN DATE_SUB(t.tar_fecha, INTERVAL 1 DAY) ELSE t.tar_fecha END)";
$primaryProcessIsCarnazaSql = "EXISTS (
  SELECT 1
  FROM procesos_materiales pm_carnaza_primary
  WHERE pm_carnaza_primary.pro_id = t.pro_id
    AND pm_carnaza_primary.mat_id = 1
)";
$secondaryProcessIsCarnazaSql = "EXISTS (
  SELECT 1
  FROM procesos_materiales pm_carnaza_secondary
  WHERE pm_carnaza_secondary.pro_id = t.pro_id_2
    AND pm_carnaza_secondary.mat_id = 1
)";
$primaryProductionKilosSql = "CASE
  WHEN t.pro_id_2 IS NOT NULL AND t.pro_id_2 <> 0 AND t.pro_id_2 <> t.pro_id
    THEN t.tar_kilos * CASE
      WHEN {$primaryProcessIsCarnazaSql} AND NOT {$secondaryProcessIsCarnazaSql} THEN 0.70
      WHEN {$secondaryProcessIsCarnazaSql} AND NOT {$primaryProcessIsCarnazaSql} THEN 0.30
      ELSE 0.50
    END
  ELSE t.tar_kilos
END";
$secondaryProductionKilosSql = "t.tar_kilos * CASE
  WHEN {$secondaryProcessIsCarnazaSql} AND NOT {$primaryProcessIsCarnazaSql} THEN 0.70
  WHEN {$primaryProcessIsCarnazaSql} AND NOT {$secondaryProcessIsCarnazaSql} THEN 0.30
  ELSE 0.50
END";
$selectedYieldMonth = sprintf('%04d-%02d', $selectedYear, $selectedMonth);
$closedProcessProductionStmt = $pdo->query("
  SELECT
    t.pro_id,
    t.pro_id_2,
    DATE_FORMAT({$operationDateSql}, '%Y-%m') AS operation_month,
    COUNT(*) AS tarimas,
    SUM(t.tar_kilos) AS kilos,
    SUM({$primaryProductionKilosSql}) AS kilos_primario,
    SUM(CASE
      WHEN t.pro_id_2 IS NOT NULL AND t.pro_id_2 <> 0 AND t.pro_id_2 <> t.pro_id
        THEN {$secondaryProductionKilosSql}
      ELSE 0
    END) AS kilos_secundario
  FROM rev_tarimas t
  WHERE t.tar_count_etiquetado > 0
    AND t.pro_id IS NOT NULL
    AND t.pro_id <> 0
    AND t.pro_id <> {$barreduraProId}
    AND (t.pro_id_2 IS NULL OR t.pro_id_2 = 0 OR t.pro_id_2 <> {$barreduraProId})
    AND EXISTS (
      SELECT 1
      FROM procesos_agrupados pa_cerrado_1
      INNER JOIN lotes_anio lote_cerrado_1
        ON lote_cerrado_1.lote_id = pa_cerrado_1.lote_id
       AND lote_cerrado_1.lote_estatus = 3
      WHERE pa_cerrado_1.pro_id = t.pro_id
    )
    AND (
      t.pro_id_2 IS NULL
      OR t.pro_id_2 = 0
      OR t.pro_id_2 = t.pro_id
      OR EXISTS (
        SELECT 1
        FROM procesos_agrupados pa_cerrado_2
        INNER JOIN lotes_anio lote_cerrado_2
          ON lote_cerrado_2.lote_id = pa_cerrado_2.lote_id
         AND lote_cerrado_2.lote_estatus = 3
        WHERE pa_cerrado_2.pro_id = t.pro_id_2
      )
    )
  GROUP BY t.pro_id, t.pro_id_2, operation_month
  ORDER BY t.pro_id, t.pro_id_2, operation_month
");
$processPairKey = static function (int $processId1, int $processId2): string {
  $processId2 = $processId2 > 0 && $processId2 !== $processId1 ? $processId2 : 0;
  return $processId1 . '|' . $processId2;
};
$closedPairProduction = [];
foreach ($closedProcessProductionStmt->fetchAll() ?: [] as $productionRow) {
  $processId1 = (int)($productionRow['pro_id'] ?? 0);
  $processId2 = (int)($productionRow['pro_id_2'] ?? 0);
  $operationMonth = (string)($productionRow['operation_month'] ?? '');
  if ($processId1 <= 0 || $operationMonth === '') {
    continue;
  }

  $pairKey = $processPairKey($processId1, $processId2);
  if (!isset($closedPairProduction[$pairKey])) {
    $closedPairProduction[$pairKey] = [
      'pro_id' => $processId1,
      'pro_id_2' => $processId2 > 0 && $processId2 !== $processId1 ? $processId2 : 0,
      'kilos' => 0.0,
      'kilos_primario' => 0.0,
      'kilos_secundario' => 0.0,
      'tarimas' => 0,
      'periodos' => [],
      'periodo_dominante' => null,
    ];
  }
  $closedPairProduction[$pairKey]['kilos'] += (float)($productionRow['kilos'] ?? 0);
  $closedPairProduction[$pairKey]['kilos_primario'] += (float)($productionRow['kilos_primario'] ?? 0);
  $closedPairProduction[$pairKey]['kilos_secundario'] += (float)($productionRow['kilos_secundario'] ?? 0);
  $closedPairProduction[$pairKey]['tarimas'] += (int)($productionRow['tarimas'] ?? 0);
  $closedPairProduction[$pairKey]['periodos'][$operationMonth] = [
    'tarimas' => (int)($productionRow['tarimas'] ?? 0),
    'kilos' => (float)($productionRow['kilos'] ?? 0),
  ];
}

foreach ($closedPairProduction as &$processProduction) {
  $dominantMonth = null;
  $dominantValues = ['tarimas' => -1, 'kilos' => -1.0];
  foreach ($processProduction['periodos'] as $operationMonth => $periodValues) {
    $isDominant = (int)$periodValues['tarimas'] > (int)$dominantValues['tarimas']
      || (
        (int)$periodValues['tarimas'] === (int)$dominantValues['tarimas']
        && (float)$periodValues['kilos'] > (float)$dominantValues['kilos']
      )
      || (
        (int)$periodValues['tarimas'] === (int)$dominantValues['tarimas']
        && (float)$periodValues['kilos'] === (float)$dominantValues['kilos']
        && ($dominantMonth === null || strcmp((string)$operationMonth, $dominantMonth) < 0)
      );
    if ($isDominant) {
      $dominantMonth = (string)$operationMonth;
      $dominantValues = $periodValues;
    }
  }
  $processProduction['periodo_dominante'] = $dominantMonth;
}
unset($processProduction);

$selectedProcessProduction = [];
foreach ($closedPairProduction as $pairProduction) {
  if (($pairProduction['periodo_dominante'] ?? null) !== $selectedYieldMonth) {
    continue;
  }

  $processId1 = (int)($pairProduction['pro_id'] ?? 0);
  $processId2 = (int)($pairProduction['pro_id_2'] ?? 0);
  if (!isset($selectedProcessProduction[$processId1])) {
    $selectedProcessProduction[$processId1] = ['kilos' => 0.0, 'tarimas' => 0];
  }
  $selectedProcessProduction[$processId1]['kilos'] += (float)($pairProduction['kilos_primario'] ?? 0);
  $selectedProcessProduction[$processId1]['tarimas'] += (int)($pairProduction['tarimas'] ?? 0);

  if ($processId2 > 0) {
    if (!isset($selectedProcessProduction[$processId2])) {
      $selectedProcessProduction[$processId2] = ['kilos' => 0.0, 'tarimas' => 0];
    }
    $selectedProcessProduction[$processId2]['kilos'] += (float)($pairProduction['kilos_secundario'] ?? 0);
  }
}

$pdo->exec("
  CREATE TEMPORARY TABLE tmp_materia_prima_rendimiento (
    pro_id INT NOT NULL PRIMARY KEY,
    kilos_producidos DECIMAL(18,4) NOT NULL,
    tarimas INT NOT NULL
  ) ENGINE=MEMORY
");
$insertYieldProcessStmt = $pdo->prepare("
  INSERT INTO tmp_materia_prima_rendimiento (pro_id, kilos_producidos, tarimas)
  VALUES (?, ?, ?)
");
$selectedYieldProcessIds = [];
foreach ($selectedProcessProduction as $processId => $processProduction) {
  $selectedYieldProcessIds[] = (int)$processId;
  $insertYieldProcessStmt->execute([
    (int)$processId,
    (float)($processProduction['kilos'] ?? 0),
    (int)($processProduction['tarimas'] ?? 0),
  ]);
}

$periodProcessSql = $selectedYieldProcessIds === []
  ? 'SELECT NULL AS pro_id WHERE 1 = 0'
  : implode(' UNION ALL ', array_map(
    static fn(int $processId): string => 'SELECT ' . $processId . ' AS pro_id',
    $selectedYieldProcessIds
  ));
$periodProcessParams = [];

$materialTypeSql = '';
$materialParams = [];
if ($selectedMaterialType !== 'all') {
  $materialTypeSql = ' AND m.mt_id = ? ';
  $materialParams[] = (int)$selectedMaterialType;
}

$consumptionBaseSql = "
  FROM procesos_materiales pm
  INNER JOIN ({$periodProcessSql}) period_processes ON period_processes.pro_id = pm.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
  LEFT JOIN proveedores p ON p.prv_id = i.prv_id
  WHERE 1 = 1
    {$materialTypeSql}
";
$baseParams = array_merge($periodProcessParams, $materialParams);
$purchaseBaseSql = "
  FROM inventario i
  INNER JOIN materiales m ON m.mat_id = i.mat_id
  LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
  LEFT JOIN proveedores p ON p.prv_id = i.prv_id
  WHERE i.inv_fecha >= ?
    AND i.inv_fecha < ?
    {$materialTypeSql}
";
$purchaseParams = array_merge([$startDate, $endDate], $materialParams);
$materialFamilySql = "
  CASE
    WHEN m.mat_id IN (5, 7, 9, 12) THEN 'Cuero Entero'
    WHEN m.mat_id IN (4, 3, 10, 11, 13) THEN 'Recorte'
    WHEN m.mat_id IN (2, 6, 8) THEN 'Pedacera'
    WHEN m.mat_id IN (1) THEN 'Carnaza'
    ELSE 'Otros'
  END
";

$summaryStmt = $pdo->prepare("
  SELECT
    COUNT(DISTINCT pm.pro_id) AS procesos,
    COUNT(*) AS partidas,
    COUNT(DISTINCT i.prv_id) AS proveedores,
    COUNT(DISTINCT m.mat_id) AS materiales,
    SUM(i.inv_kilos) AS kilos_consumidos
  {$consumptionBaseSql}
");
$summaryStmt->execute($baseParams);
$summary = $summaryStmt->fetch() ?: [];

$purchaseSummaryStmt = $pdo->prepare("
  SELECT
    SUM(i.inv_kilos) AS kilos_comprados,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END) AS valor_compra,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END) AS valor_maquila,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END) / NULLIF(SUM(i.inv_kilos), 0) AS precio_promedio,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END) / NULLIF(SUM(i.inv_kilos), 0) AS precio_maquila,
    (
      SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END)
      + SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END)
    ) / NULLIF(SUM(i.inv_kilos), 0) AS precio_total
  {$purchaseBaseSql}
");
$purchaseSummaryStmt->execute($purchaseParams);
$purchaseSummary = $purchaseSummaryStmt->fetch() ?: [];

$productionStmt = $pdo->query("
  SELECT
    SUM(kilos_producidos) AS kilos_producidos,
    SUM(tarimas) AS tarimas
  FROM tmp_materia_prima_rendimiento
");
$production = $productionStmt->fetch() ?: [];

$periodProductionStmt = $pdo->prepare("
  SELECT
    COUNT(*) AS tarimas,
    COALESCE(SUM(d.tar_kilos), 0) AS kilos
  FROM (
    SELECT t.tar_kilos, {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND t.tar_count_etiquetado > 0
  ) d
  WHERE d.op_dia >= ?
    AND d.op_dia < ?
");
$periodProductionStmt->execute([
  $startDateTime,
  $endDateTime,
  $startDate,
  $endDate,
]);
$periodProduction = $periodProductionStmt->fetch() ?: [];

$barreduraStmt = $pdo->prepare("
  SELECT
    COALESCE(SUM(d.tar_kilos), 0) AS kilos,
    COUNT(*) AS tarimas
  FROM (
    SELECT t.*, {$operationDateSql} AS op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND t.tar_count_etiquetado > 0
  ) d
  WHERE d.op_dia >= ?
    AND d.op_dia < ?
    AND (d.pro_id = ? OR d.pro_id_2 = ?)
");
$barreduraStmt->execute([
  $startDateTime,
  $endDateTime,
  $startDate,
  $endDate,
  $barreduraProId,
  $barreduraProId,
]);
$barredura = $barreduraStmt->fetch() ?: [];

$dailyStmt = $pdo->prepare("
  SELECT
    prod.op_dia AS fecha,
    DAY(prod.op_dia) AS dia,
    material_data.grupo,
    SUM(material_data.kilos_consumidos * (prod.kilos_dia / NULLIF(prod_total.kilos_total, 0))) AS kilos,
    COUNT(DISTINCT material_data.pro_id) AS procesos
  FROM (
    SELECT
      pm.pro_id,
      {$materialFamilySql} AS grupo,
      SUM(i.inv_kilos) AS kilos_consumidos
    FROM procesos_materiales pm
    INNER JOIN ({$periodProcessSql}) period_processes ON period_processes.pro_id = pm.pro_id
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    INNER JOIN materiales m ON m.mat_id = pm.mat_id
    LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
    WHERE 1 = 1
      {$materialTypeSql}
    GROUP BY pm.pro_id, grupo
  ) material_data
  INNER JOIN (
    SELECT process_id AS pro_id, op_dia, SUM(kilos) AS kilos_dia
    FROM (
      SELECT t.pro_id AS process_id, {$primaryProductionKilosSql} AS kilos, {$operationDateSql} AS op_dia
      FROM rev_tarimas t
      WHERE t.tar_fecha >= ?
        AND t.tar_fecha < ?
        AND t.tar_count_etiquetado > 0
      UNION ALL
      SELECT t.pro_id_2 AS process_id, {$secondaryProductionKilosSql} AS kilos, {$operationDateSql} AS op_dia
      FROM rev_tarimas t
      WHERE t.tar_fecha >= ?
        AND t.tar_fecha < ?
        AND t.tar_count_etiquetado > 0
        AND t.pro_id_2 IS NOT NULL
        AND t.pro_id_2 <> 0
        AND t.pro_id_2 <> t.pro_id
    ) production_by_day
    WHERE op_dia >= ?
      AND op_dia < ?
    GROUP BY process_id, op_dia
  ) prod ON prod.pro_id = material_data.pro_id
  INNER JOIN (
    SELECT process_id AS pro_id, SUM(kilos) AS kilos_total
    FROM (
      SELECT t.pro_id AS process_id, {$primaryProductionKilosSql} AS kilos, {$operationDateSql} AS op_dia
      FROM rev_tarimas t
      WHERE t.tar_fecha >= ?
        AND t.tar_fecha < ?
        AND t.tar_count_etiquetado > 0
      UNION ALL
      SELECT t.pro_id_2 AS process_id, {$secondaryProductionKilosSql} AS kilos, {$operationDateSql} AS op_dia
      FROM rev_tarimas t
      WHERE t.tar_fecha >= ?
        AND t.tar_fecha < ?
        AND t.tar_count_etiquetado > 0
        AND t.pro_id_2 IS NOT NULL
        AND t.pro_id_2 <> 0
        AND t.pro_id_2 <> t.pro_id
    ) production_total
    WHERE op_dia >= ?
      AND op_dia < ?
    GROUP BY process_id
  ) prod_total ON prod_total.pro_id = material_data.pro_id
  GROUP BY prod.op_dia, material_data.grupo
  ORDER BY prod.op_dia, material_data.grupo
");
$dailyStmt->execute(array_merge(
  $periodProcessParams,
  $materialParams,
  [$startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDate, $endDate],
  [$startDateTime, $endDateTime, $startDateTime, $endDateTime, $startDate, $endDate]
));
$dailyByDate = [];
$dailyGroups = [];
foreach ($dailyStmt->fetchAll() ?: [] as $row) {
  $dateKey = (string)$row['fecha'];
  $groupName = (string)($row['grupo'] ?? 'Otros');
  $kilos = (float)$row['kilos'];
  $dailyGroups[$groupName] = $groupName;

  if (!isset($dailyByDate[$dateKey])) {
    $dailyByDate[$dateKey] = [
      'fecha' => $dateKey,
      'dia' => (int)$row['dia'],
      'kilos' => 0.0,
      'toneladas' => 0.0,
      'procesos' => 0,
      'grupos' => [],
    ];
  }

  $dailyByDate[$dateKey]['kilos'] += $kilos;
  $dailyByDate[$dateKey]['toneladas'] += $kilos / 1000;
  $dailyByDate[$dateKey]['procesos'] += (int)$row['procesos'];
  $dailyByDate[$dateKey]['grupos'][$groupName] = [
    'grupo' => $groupName,
    'kilos' => $kilos,
    'toneladas' => $kilos / 1000,
    'procesos' => (int)$row['procesos'],
  ];
}

$dailySeries = [];
for ($cursor = $periodStart; $cursor < $periodEnd; $cursor = $cursor->modify('+1 day')) {
  $key = $cursor->format('Y-m-d');
  $dailySeries[] = $dailyByDate[$key] ?? [
    'fecha' => $key,
    'dia' => (int)$cursor->format('j'),
    'kilos' => 0.0,
    'toneladas' => 0.0,
    'procesos' => 0,
    'grupos' => [],
  ];
}

$materialGroupStmt = $pdo->prepare("
  SELECT
    {$materialFamilySql} AS grupo,
    SUM(i.inv_kilos) AS kilos,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END)
      / NULLIF(SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_kilos ELSE 0 END), 0) AS precio_promedio,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END)
      / NULLIF(SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_kilos ELSE 0 END), 0) AS precio_maquila,
    COUNT(DISTINCT pm.pro_id) AS procesos,
    COUNT(DISTINCT i.prv_id) AS proveedores,
    COUNT(DISTINCT m.mat_id) AS materiales
  {$consumptionBaseSql}
  GROUP BY grupo
  ORDER BY kilos DESC
");
$materialGroupStmt->execute($baseParams);
$materialGroups = array_map(static function (array $row): array {
  $kilos = (float)($row['kilos'] ?? 0);
  $precioPromedio = is_numeric($row['precio_promedio'] ?? null) ? (float)$row['precio_promedio'] : null;
  $precioMaquila = is_numeric($row['precio_maquila'] ?? null) ? (float)$row['precio_maquila'] : null;
  return [
    'grupo' => (string)($row['grupo'] ?? 'Sin grupo'),
    'kilos' => $kilos,
    'toneladas' => $kilos / 1000,
    'precio_promedio' => $precioPromedio,
    'precio_maquila' => $precioMaquila,
    'precio_total' => $precioPromedio === null && $precioMaquila === null ? null : ($precioPromedio ?? 0.0) + ($precioMaquila ?? 0.0),
    'procesos' => (int)($row['procesos'] ?? 0),
    'proveedores' => (int)($row['proveedores'] ?? 0),
    'materiales' => (int)($row['materiales'] ?? 0),
  ];
}, $materialGroupStmt->fetchAll() ?: []);

$priceGroupStmt = $pdo->prepare("
  SELECT
    {$materialFamilySql} AS grupo,
    SUM(i.inv_kilos) AS kilos,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END) AS valor_compra,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END) AS valor_maquila,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END) / NULLIF(SUM(i.inv_kilos), 0) AS precio_promedio,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END) / NULLIF(SUM(i.inv_kilos), 0) AS precio_maquila,
    (
      SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END)
      + SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END)
    ) / NULLIF(SUM(i.inv_kilos), 0) AS precio_total,
    COUNT(*) AS compras,
    COUNT(DISTINCT i.prv_id) AS proveedores,
    COUNT(DISTINCT m.mat_id) AS materiales
  {$purchaseBaseSql}
  GROUP BY grupo
  ORDER BY kilos DESC
");
$priceGroupStmt->execute($purchaseParams);
$priceGroups = array_map(static function (array $row): array {
  $kilos = (float)($row['kilos'] ?? 0);
  return [
    'grupo' => (string)($row['grupo'] ?? 'Sin grupo'),
    'kilos' => $kilos,
    'toneladas' => $kilos / 1000,
    'valor_compra' => (float)($row['valor_compra'] ?? 0),
    'valor_maquila' => (float)($row['valor_maquila'] ?? 0),
    'precio_promedio' => is_numeric($row['precio_promedio'] ?? null) ? (float)$row['precio_promedio'] : null,
    'precio_maquila' => is_numeric($row['precio_maquila'] ?? null) ? (float)$row['precio_maquila'] : null,
    'precio_total' => is_numeric($row['precio_total'] ?? null) ? (float)$row['precio_total'] : null,
    'compras' => (int)($row['compras'] ?? 0),
    'proveedores' => (int)($row['proveedores'] ?? 0),
    'materiales' => (int)($row['materiales'] ?? 0),
  ];
}, $priceGroupStmt->fetchAll() ?: []);

$materialYieldStmt = $pdo->prepare("
  SELECT
    material_data.grupo,
    SUM(material_data.kilos_consumidos) AS kilos_consumidos,
    SUM(material_data.kilos_producidos_asignados) AS kilos_producidos,
    COUNT(DISTINCT material_data.pro_id) AS procesos,
    COUNT(DISTINCT material_data.mat_id) AS materiales
  FROM (
    SELECT
      {$materialFamilySql} AS grupo,
      pm.pro_id,
      m.mat_id,
      SUM(i.inv_kilos) AS kilos_consumidos,
      COALESCE(po.kilos_producidos, 0) * (SUM(i.inv_kilos) / NULLIF(pt.kilos_proceso, 0)) AS kilos_producidos_asignados
    FROM procesos_materiales pm
    INNER JOIN ({$periodProcessSql}) period_processes ON period_processes.pro_id = pm.pro_id
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    INNER JOIN materiales m ON m.mat_id = pm.mat_id
    LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
    LEFT JOIN (
      SELECT pm_total.pro_id, SUM(i_total.inv_kilos) AS kilos_proceso
      FROM procesos_materiales pm_total
      INNER JOIN inventario i_total ON i_total.inv_id = pm_total.inv_id
      GROUP BY pm_total.pro_id
    ) pt ON pt.pro_id = pm.pro_id
    LEFT JOIN tmp_materia_prima_rendimiento po ON po.pro_id = pm.pro_id
    WHERE 1 = 1
      {$materialTypeSql}
    GROUP BY grupo, pm.pro_id, m.mat_id, po.kilos_producidos, pt.kilos_proceso
  ) material_data
  GROUP BY material_data.grupo
  ORDER BY kilos_consumidos DESC
");
$yieldParams = array_merge(
  $periodProcessParams,
  $materialParams
);
$materialYields = array_map(static function (array $row): array {
  $consumed = (float)($row['kilos_consumidos'] ?? 0);
  $produced = (float)($row['kilos_producidos'] ?? 0);
  return [
    'grupo' => (string)($row['grupo'] ?? 'Sin grupo'),
    'kilos_consumidos' => $consumed,
    'toneladas_consumidas' => $consumed / 1000,
    'kilos_producidos' => $produced,
    'toneladas_producidas' => $produced / 1000,
    'rendimiento' => $consumed > 0 ? ($produced / $consumed) * 100 : null,
    'procesos' => (int)($row['procesos'] ?? 0),
    'materiales' => (int)($row['materiales'] ?? 0),
  ];
}, $materialYieldStmt->execute($yieldParams) ? ($materialYieldStmt->fetchAll() ?: []) : []);

$topMateriales = max(5, (int)($config['top_materiales'] ?? 12));
$materialStmt = $pdo->prepare("
  SELECT
    m.mat_id,
    m.mat_nombre,
    {$materialFamilySql} AS grupo,
    SUM(i.inv_kilos) AS kilos,
    SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_costo * i.inv_kilos ELSE 0 END)
      / NULLIF(SUM(CASE WHEN i.inv_costo > 0 THEN i.inv_kilos ELSE 0 END), 0) AS precio_promedio,
    SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_costo_mql * i.inv_kilos ELSE 0 END)
      / NULLIF(SUM(CASE WHEN i.inv_costo_mql > 0 THEN i.inv_kilos ELSE 0 END), 0) AS precio_maquila,
    COUNT(DISTINCT pm.pro_id) AS procesos,
    COUNT(DISTINCT i.prv_id) AS proveedores
  {$consumptionBaseSql}
  GROUP BY m.mat_id, m.mat_nombre, grupo
  ORDER BY kilos DESC
  LIMIT {$topMateriales}
");
$materialStmt->execute($baseParams);
$materials = array_map(static function (array $row): array {
  $kilos = (float)($row['kilos'] ?? 0);
  $precioPromedio = is_numeric($row['precio_promedio'] ?? null) ? (float)$row['precio_promedio'] : null;
  $precioMaquila = is_numeric($row['precio_maquila'] ?? null) ? (float)$row['precio_maquila'] : null;
  return [
    'mat_id' => (int)($row['mat_id'] ?? 0),
    'material' => (string)($row['mat_nombre'] ?? 'Sin material'),
    'grupo' => (string)($row['grupo'] ?? 'Sin grupo'),
    'kilos' => $kilos,
    'toneladas' => $kilos / 1000,
    'precio_promedio' => $precioPromedio,
    'precio_maquila' => $precioMaquila,
    'precio_total' => $precioPromedio === null && $precioMaquila === null ? null : ($precioPromedio ?? 0.0) + ($precioMaquila ?? 0.0),
    'procesos' => (int)($row['procesos'] ?? 0),
    'proveedores' => (int)($row['proveedores'] ?? 0),
  ];
}, $materialStmt->fetchAll() ?: []);

$topProveedores = max(5, (int)($config['top_proveedores'] ?? 12));
$providerStmt = $pdo->prepare("
  SELECT
    provider_data.prv_id,
    provider_data.proveedor,
    SUM(provider_data.kilos_consumidos) AS kilos_consumidos,
    SUM(provider_data.kilos_producidos_asignados) AS kilos_producidos,
    COUNT(DISTINCT provider_data.pro_id) AS procesos,
    COUNT(DISTINCT provider_data.mat_id) AS materiales
  FROM (
    SELECT
      COALESCE(p.prv_id, 0) AS prv_id,
      COALESCE(NULLIF(p.prv_nom_comercial, ''), NULLIF(p.prv_nombre, ''), 'Sin proveedor') AS proveedor,
      pm.pro_id,
      m.mat_id,
      SUM(i.inv_kilos) AS kilos_consumidos,
      COALESCE(po.kilos_producidos, 0) * (SUM(i.inv_kilos) / NULLIF(pt.kilos_proceso, 0)) AS kilos_producidos_asignados
    FROM procesos_materiales pm
    INNER JOIN ({$periodProcessSql}) period_processes ON period_processes.pro_id = pm.pro_id
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    INNER JOIN materiales m ON m.mat_id = pm.mat_id
    LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
    LEFT JOIN proveedores p ON p.prv_id = i.prv_id
    LEFT JOIN (
      SELECT pm_total.pro_id, SUM(i_total.inv_kilos) AS kilos_proceso
      FROM procesos_materiales pm_total
      INNER JOIN inventario i_total ON i_total.inv_id = pm_total.inv_id
      GROUP BY pm_total.pro_id
    ) pt ON pt.pro_id = pm.pro_id
    LEFT JOIN tmp_materia_prima_rendimiento po ON po.pro_id = pm.pro_id
    WHERE 1 = 1
      {$materialTypeSql}
    GROUP BY prv_id, proveedor, pm.pro_id, m.mat_id, po.kilos_producidos, pt.kilos_proceso
  ) provider_data
  GROUP BY provider_data.prv_id, provider_data.proveedor
  ORDER BY kilos_consumidos DESC
  LIMIT {$topProveedores}
");
$providerParams = array_merge(
  $periodProcessParams,
  $materialParams
);
$providerStmt->execute($providerParams);
$providers = array_map(static function (array $row): array {
  $consumed = (float)($row['kilos_consumidos'] ?? 0);
  $produced = (float)($row['kilos_producidos'] ?? 0);
  return [
    'prv_id' => (int)($row['prv_id'] ?? 0),
    'proveedor' => (string)($row['proveedor'] ?? 'Sin proveedor'),
    'kilos_consumidos' => $consumed,
    'toneladas_consumidas' => $consumed / 1000,
    'kilos_producidos' => $produced,
    'toneladas_producidas' => $produced / 1000,
    'rendimiento' => $consumed > 0 ? ($produced / $consumed) * 100 : null,
    'procesos' => (int)($row['procesos'] ?? 0),
    'materiales' => (int)($row['materiales'] ?? 0),
  ];
}, $providerStmt->fetchAll() ?: []);

$providerMaterialStmt = $pdo->prepare("
  SELECT
    provider_material_data.prv_id,
    provider_material_data.proveedor,
    provider_material_data.grupo,
    SUM(provider_material_data.kilos_consumidos) AS kilos_consumidos,
    SUM(provider_material_data.kilos_producidos_asignados) AS kilos_producidos,
    COUNT(DISTINCT provider_material_data.pro_id) AS procesos,
    COUNT(DISTINCT provider_material_data.mat_id) AS materiales
  FROM (
    SELECT
      COALESCE(p.prv_id, 0) AS prv_id,
      COALESCE(NULLIF(p.prv_nom_comercial, ''), NULLIF(p.prv_nombre, ''), 'Sin proveedor') AS proveedor,
      {$materialFamilySql} AS grupo,
      pm.pro_id,
      m.mat_id,
      SUM(i.inv_kilos) AS kilos_consumidos,
      COALESCE(po.kilos_producidos, 0) * (SUM(i.inv_kilos) / NULLIF(pt.kilos_proceso, 0)) AS kilos_producidos_asignados
    FROM procesos_materiales pm
    INNER JOIN ({$periodProcessSql}) period_processes ON period_processes.pro_id = pm.pro_id
    INNER JOIN inventario i ON i.inv_id = pm.inv_id
    INNER JOIN materiales m ON m.mat_id = pm.mat_id
    LEFT JOIN materiales_tipo mt ON mt.mt_id = m.mt_id
    LEFT JOIN proveedores p ON p.prv_id = i.prv_id
    LEFT JOIN (
      SELECT pm_total.pro_id, SUM(i_total.inv_kilos) AS kilos_proceso
      FROM procesos_materiales pm_total
      INNER JOIN inventario i_total ON i_total.inv_id = pm_total.inv_id
      GROUP BY pm_total.pro_id
    ) pt ON pt.pro_id = pm.pro_id
    LEFT JOIN tmp_materia_prima_rendimiento po ON po.pro_id = pm.pro_id
    WHERE 1 = 1
      {$materialTypeSql}
    GROUP BY prv_id, proveedor, grupo, pm.pro_id, m.mat_id, po.kilos_producidos, pt.kilos_proceso
  ) provider_material_data
  GROUP BY provider_material_data.prv_id, provider_material_data.proveedor, provider_material_data.grupo
  ORDER BY kilos_consumidos DESC
");
$providerMaterialParams = array_merge(
  $periodProcessParams,
  $materialParams
);
$providerMaterialStmt->execute($providerMaterialParams);
$providerMaterials = array_map(static function (array $row): array {
  $consumed = (float)($row['kilos_consumidos'] ?? 0);
  $produced = (float)($row['kilos_producidos'] ?? 0);
  return [
    'prv_id' => (int)($row['prv_id'] ?? 0),
    'proveedor' => (string)($row['proveedor'] ?? 'Sin proveedor'),
    'grupo' => (string)($row['grupo'] ?? 'Sin grupo'),
    'kilos_consumidos' => $consumed,
    'toneladas_consumidas' => $consumed / 1000,
    'kilos_producidos' => $produced,
    'toneladas_producidas' => $produced / 1000,
    'rendimiento' => $consumed > 0 ? ($produced / $consumed) * 100 : null,
    'procesos' => (int)($row['procesos'] ?? 0),
    'materiales' => (int)($row['materiales'] ?? 0),
  ];
}, $providerMaterialStmt->fetchAll() ?: []);

$enzymeProcessStmt = $pdo->prepare("
  SELECT
    p.pro_id,
    p.pt_id,
    p.pro_fe_carga,
    CASE p.pt_id
      WHEN 10 THEN 'Enzima Plus'
      WHEN 11 THEN 'Enzima Alpha'
      ELSE 'Sin tipo'
    END AS preparacion,
    material_data.materiales,
    material_data.kilos_material,
    fase_enzima.pfg2_hr_totales AS horas_enzima,
    fase_enzima.pfg2_enzima AS litros_enzima,
    (
      SELECT liberacion_b.prol_solides
      FROM procesos_liberacion_b liberacion_b
      WHERE liberacion_b.pro_id = p.pro_id
        AND liberacion_b.prol_solides IS NOT NULL
      ORDER BY liberacion_b.prol_fecha DESC, liberacion_b.prol_hora DESC, liberacion_b.prol_id DESC
      LIMIT 1
    ) AS solidos,
    (
      SELECT liberacion.extractibilidad
      FROM procesos_liberacion liberacion
      WHERE liberacion.pro_id = p.pro_id
        AND liberacion.extractibilidad IS NOT NULL
      ORDER BY liberacion.prol_fecha DESC, liberacion.prol_id DESC
      LIMIT 1
    ) AS extractibilidad
  FROM procesos p
  INNER JOIN procesos_fase_2b_g fase_enzima
    ON fase_enzima.pfg2_id = (
      SELECT MAX(fase_enzima_ultima.pfg2_id)
      FROM procesos_fase_2b_g fase_enzima_ultima
      WHERE fase_enzima_ultima.pro_id = p.pro_id
        AND fase_enzima_ultima.pe_id = 3
    )
  INNER JOIN (
    SELECT
      materiales_proceso.pro_id,
      SUM(materiales_proceso.kilos) AS kilos_material,
      GROUP_CONCAT(
        CONCAT(
          materiales_proceso.mat_nombre,
          ' (',
          ROUND(materiales_proceso.kilos / 1000, 2),
          ' t)'
        )
        ORDER BY materiales_proceso.mat_nombre
        SEPARATOR ' + '
      ) AS materiales
    FROM (
      SELECT
        pm.pro_id,
        pm.mat_id,
        m.mat_nombre,
        SUM(pm.pma_kg) AS kilos
      FROM procesos_materiales pm
      INNER JOIN materiales m ON m.mat_id = pm.mat_id
      GROUP BY pm.pro_id, pm.mat_id, m.mat_nombre
    ) materiales_proceso
    GROUP BY materiales_proceso.pro_id
  ) material_data ON material_data.pro_id = p.pro_id
  WHERE p.pt_id IN (10, 11)
    AND p.pro_fe_carga >= ?
    AND p.pro_fe_carga < ?
  ORDER BY p.pro_fe_carga DESC, p.pro_id DESC
");
$enzymeProcessStmt->execute([$startDate, $endDate]);
$enzymeProcesses = array_map(static function (array $row): array {
  $materialKilos = (float)($row['kilos_material'] ?? 0);
  $enzymeLiters = is_numeric($row['litros_enzima'] ?? null) ? (float)$row['litros_enzima'] : null;

  return [
    'pro_id' => (int)($row['pro_id'] ?? 0),
    'pt_id' => (int)($row['pt_id'] ?? 0),
    'fecha' => (string)($row['pro_fe_carga'] ?? ''),
    'preparacion' => (string)($row['preparacion'] ?? ''),
    'material' => (string)($row['materiales'] ?? 'Sin material'),
    'kilos_material' => $materialKilos,
    'horas_enzima' => is_numeric($row['horas_enzima'] ?? null) ? (float)$row['horas_enzima'] : null,
    'litros_enzima' => $enzymeLiters,
    'litros_tonelada' => $enzymeLiters !== null && $materialKilos > 0
      ? $enzymeLiters / ($materialKilos / 1000)
      : null,
    'solidos' => is_numeric($row['solidos'] ?? null) ? (float)$row['solidos'] : null,
    'extractibilidad' => is_numeric($row['extractibilidad'] ?? null) ? (float)$row['extractibilidad'] : null,
  ];
}, $enzymeProcessStmt->fetchAll() ?: []);

$kilosConsumidos = (float)($summary['kilos_consumidos'] ?? 0);
$kilosProcesosCerrados = (float)($production['kilos_producidos'] ?? 0);
$kilosBarredura = (float)($barredura['kilos'] ?? 0);
$kilosProduccionRendimiento = $kilosProcesosCerrados + $kilosBarredura;
$kilosProduccionPeriodo = (float)($periodProduction['kilos'] ?? 0);
$rendimiento = $kilosConsumidos > 0 ? ($kilosProduccionRendimiento / $kilosConsumidos) * 100 : null;
$precioPromedioTotal = is_numeric($purchaseSummary['precio_promedio'] ?? null) ? (float)$purchaseSummary['precio_promedio'] : null;
$precioMaquilaTotal = is_numeric($purchaseSummary['precio_maquila'] ?? null) ? (float)$purchaseSummary['precio_maquila'] : null;
$precioTotalPromedio = is_numeric($purchaseSummary['precio_total'] ?? null) ? (float)$purchaseSummary['precio_total'] : null;

return [
  'titulo' => (string)($config['titulo'] ?? 'Materia Prima'),
  'filtros' => [
    'anio' => $selectedYear,
    'mes' => $selectedMonth,
    'mes_nombre' => $monthNames[$selectedMonth] ?? (string)$selectedMonth,
    'mt_id' => $selectedMaterialType,
    'anios' => $yearOptions,
    'meses' => $monthNames,
    'tipos_material' => array_values($materialTypes),
  ],
  'kpis' => [
    'kilos_consumidos' => $kilosConsumidos,
    'toneladas_consumidas' => $kilosConsumidos / 1000,
    'kilos_producidos' => $kilosProduccionPeriodo,
    'toneladas_producidas' => $kilosProduccionPeriodo / 1000,
    'kilos_producidos_rendimiento' => $kilosProduccionRendimiento,
    'toneladas_producidas_rendimiento' => $kilosProduccionRendimiento / 1000,
    'kilos_procesos_cerrados' => $kilosProcesosCerrados,
    'kilos_barredura_rendimiento' => $kilosBarredura,
    'rendimiento' => $rendimiento,
    'procesos' => (int)($summary['procesos'] ?? 0),
    'partidas' => (int)($summary['partidas'] ?? 0),
    'proveedores' => (int)($summary['proveedores'] ?? 0),
    'materiales' => (int)($summary['materiales'] ?? 0),
    'tarimas' => (int)($periodProduction['tarimas'] ?? 0),
    'kilos_comprados' => (float)($purchaseSummary['kilos_comprados'] ?? 0),
    'toneladas_compradas' => ((float)($purchaseSummary['kilos_comprados'] ?? 0)) / 1000,
    'valor_compra' => (float)($purchaseSummary['valor_compra'] ?? 0),
    'valor_maquila' => (float)($purchaseSummary['valor_maquila'] ?? 0),
    'precio_promedio' => $precioPromedioTotal,
    'precio_maquila' => $precioMaquilaTotal,
    'precio_total_promedio' => $precioTotalPromedio,
  ],
  'series' => [
    'diaria' => $dailySeries,
    'grupos_diarios' => array_values($dailyGroups),
  ],
  'tablas' => [
    'grupos_material' => $materialGroups,
    'precios_grupo' => $priceGroups,
    'rendimiento_material' => $materialYields,
    'materiales' => $materials,
    'proveedores' => $providers,
    'proveedores_material' => $providerMaterials,
    'enzima_procesos' => $enzymeProcesses,
  ],
  'meta' => [
    'periodo_inicio' => $startDate,
    'periodo_fin' => $periodEnd->modify('-1 day')->format('Y-m-d'),
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? ($appConfig['intervalo_actualizacion'] ?? 300000)),
    'agrupador_materiales' => (string)($config['agrupador_materiales'] ?? 'tipo'),
    'nota_rendimiento' => 'La producción visible incluye todas las tarimas etiquetadas del mes operativo. El rendimiento usa procesos cerrados asignados al mes con más tarimas, más barredura.',
    'rendimiento_solo_procesos_cerrados' => false,
    'rendimiento_procesos_solo_cerrados' => true,
    'rendimiento_asignado_mes_mayor_tarimas' => true,
    'rendimiento_general_incluye_barredura' => true,
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
