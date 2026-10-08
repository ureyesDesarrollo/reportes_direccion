<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'America/Mexico_City');
date_default_timezone_set($timezone);
$tz = new DateTimeZone($timezone);
$today = new DateTimeImmutable('today', $tz);
$monthNames = [
  1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
  5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
  9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];
$safeInt = static function ($value, int $fallback, int $min, int $max): int {
  if (!is_scalar($value) || !is_numeric($value)) return $fallback;
  $number = (int)$value;
  return ($number >= $min && $number <= $max) ? $number : $fallback;
};
$labParams = (array)($config['parametros_lab'] ?? []);
$processParams = (array)($config['parametros_proceso'] ?? []);
$includeAllProcesses = !empty($config['incluir_todos_procesos']);
$evaluateLabBands = static function ($value, array $rule): array {
  if ($value === null || $value === '' || !is_numeric($value)) {
    return ['key' => 'gris', 'label' => 'Sin dato', 'range' => (string)($rule['leyenda'] ?? '')];
  }
  $number = (float)$value;
  foreach ((array)($rule['bandas'] ?? []) as $band) {
    $minOk = !array_key_exists('min', $band) || $number >= (float)$band['min'];
    $maxOk = !array_key_exists('max', $band) || $number <= (float)$band['max'];
    if ($minOk && $maxOk) {
      $key = (string)($band['estado'] ?? 'gris');
      return [
        'key' => $key,
        'label' => ['verde' => 'En objetivo', 'amarillo' => 'Alerta', 'rojo' => 'Fuera de rango'][$key] ?? 'Sin dato',
        'range' => (string)($rule['leyenda'] ?? ''),
      ];
    }
  }
  return ['key' => 'gris', 'label' => 'Sin rango', 'range' => (string)($rule['leyenda'] ?? '')];
};

$horaCorte = (string)($config['hora_corte'] ?? '07:00:00');
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $horaCorte) !== 1) {
  $horaCorte = '07:00:00';
}
$now = new DateTimeImmutable('now', $tz);
$operationalToday = $now->format('H:i:s') < $horaCorte ? $today->modify('-1 day') : $today;

$pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'prod')] ?? $dbConfig['prod']));
$periodMode = (string)($_GET['periodo'] ?? 'mes');
if (!in_array($periodMode, ['mes', 'semana', 'fecha'], true)) $periodMode = 'mes';
$selectedYear = $safeInt($_GET['anio'] ?? null, (int)$operationalToday->format('Y'), 2020, 2100);
$selectedMonth = $safeInt($_GET['mes'] ?? null, (int)$operationalToday->format('n'), 1, 12);

$legacyDateValue = trim((string)($_GET['fecha'] ?? ''));
$selectedStartDateValue = trim((string)($_GET['fecha_inicio'] ?? $legacyDateValue));
$selectedEndDateValue = trim((string)($_GET['fecha_fin'] ?? $legacyDateValue));
$parseSelectedDate = static function (string $value, DateTimeImmutable $fallback, DateTimeZone $timezone): DateTimeImmutable {
  $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
  return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $date : $fallback;
};
$selectedStartDate = $parseSelectedDate($selectedStartDateValue, $operationalToday, $tz);
$selectedEndDate = $parseSelectedDate($selectedEndDateValue, $selectedStartDate, $tz);
if ($selectedEndDate < $selectedStartDate) {
  [$selectedStartDate, $selectedEndDate] = [$selectedEndDate, $selectedStartDate];
}

$legacyWeekValue = trim((string)($_GET['semana'] ?? ''));
$selectedWeekStartValue = trim((string)($_GET['semana_inicio'] ?? $legacyWeekValue));
$selectedWeekEndValue = trim((string)($_GET['semana_fin'] ?? $legacyWeekValue));
$parseSelectedWeek = static function (string $value, DateTimeImmutable $fallback): DateTimeImmutable {
  $matches = [];
  if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $matches) !== 1) return $fallback;
  $weekStart = $fallback->setISODate((int)$matches[1], (int)$matches[2], 1)->setTime(0, 0);
  return $weekStart->format('o-\WW') === $value ? $weekStart : $fallback;
};
$currentWeekStart = $operationalToday->modify('monday this week')->setTime(0, 0);
$selectedWeekStart = $parseSelectedWeek($selectedWeekStartValue, $currentWeekStart);
$selectedWeekEnd = $parseSelectedWeek($selectedWeekEndValue, $selectedWeekStart);
if ($selectedWeekEnd < $selectedWeekStart) {
  [$selectedWeekStart, $selectedWeekEnd] = [$selectedWeekEnd, $selectedWeekStart];
}
$selectedWeekValue = $selectedWeekStart->format('o-\WW');
$selectedWeekEndValue = $selectedWeekEnd->format('o-\WW');

if ($periodMode === 'fecha') {
  $start = $selectedStartDate->setTime(0, 0);
  $endExclusive = $selectedEndDate->setTime(0, 0)->modify('+1 day');
} elseif ($periodMode === 'semana') {
  $start = $selectedWeekStart;
  $endExclusive = $selectedWeekEnd->modify('+7 days');
} else {
  $start = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $selectedYear, $selectedMonth), $tz);
  $endExclusive = $start->modify('first day of next month');
}
$end = $endExclusive->modify('-1 day');

$yearStmt = $pdo->query($includeAllProcesses
  ? "SELECT DISTINCT YEAR(pro_fe_carga) AS anio FROM procesos WHERE pro_fe_carga IS NOT NULL ORDER BY anio DESC"
  : "SELECT DISTINCT YEAR(tar_fecha) AS anio FROM rev_tarimas WHERE tar_fecha IS NOT NULL ORDER BY anio DESC");
$yearOptions = array_values(array_filter(array_map('intval', array_column($yearStmt->fetchAll() ?: [], 'anio'))));
if (!in_array($selectedYear, $yearOptions, true)) {
  $yearOptions[] = $selectedYear;
  rsort($yearOptions);
}

$periodLabel = $periodMode === 'fecha'
  ? ($start->format('Y-m-d') === $end->format('Y-m-d')
    ? $start->format('d/m/Y')
    : $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'))
  : ($periodMode === 'semana'
    ? ($selectedWeekStart->format('o-\WW') === $selectedWeekEnd->format('o-\WW')
      ? 'Semana ' . $start->format('W')
      : 'Semanas ' . $start->format('W') . ' a ' . $selectedWeekEnd->format('W'))
    : ucfirst($monthNames[$selectedMonth] ?? (string)$selectedMonth) . ' ' . $selectedYear);

$selectedMaterial = trim((string)($_GET['material'] ?? 'all'));
$selectedProvider = filter_var($_GET['proveedor'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$selectedProvider = $selectedProvider === false ? null : (int)$selectedProvider;

$barreduraProId = 2;
$operationDateSql = "DATE(CASE WHEN TIME(t.tar_fecha) < '{$horaCorte}' THEN DATE_SUB(t.tar_fecha, INTERVAL 1 DAY) ELSE t.tar_fecha END)";

$materialFamilySql = "CASE
  WHEN m.mat_id IN (5, 7) THEN 'Cuero Entero C/P (con pelo)'
  WHEN m.mat_id IN (9, 12) THEN 'Cuero Entero Depilado'
  WHEN m.mat_id IN (3, 4, 10, 11, 13) THEN m.mat_nombre
  WHEN m.mat_id = 2 THEN 'Pedacera Americana S/P'
  WHEN m.mat_id = 6 THEN 'Pedacera Americana C/P'
  WHEN m.mat_id = 8 THEN 'Pedacera Americana Depilada'
  WHEN m.mat_id = 14 THEN 'Pedacera Nacional C/P'
  WHEN m.mat_id = 1 THEN 'Carnaza'
  ELSE 'Otros'
END";

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rp_selected_pairs');
$pairBucketSql = $periodMode === 'fecha'
  ? 'op_dia'
  : ($periodMode === 'semana'
    ? 'DATE_SUB(op_dia, INTERVAL WEEKDAY(op_dia) DAY)'
    : "DATE_FORMAT(op_dia, '%Y-%m-01')");
$pairSelectionSql = $periodMode === 'mes' ? "pair_periods AS (
    SELECT pro_id, pro_id_2, {$pairBucketSql} periodo,
           COUNT(*) tarimas, SUM(tar_kilos) kilos
    FROM eligible_tarimas
    GROUP BY pro_id, pro_id_2, periodo
  ),
  ranked_pairs AS (
    SELECT pp.*,
           ROW_NUMBER() OVER (
             PARTITION BY pp.pro_id, pp.pro_id_2
             ORDER BY pp.tarimas DESC, pp.kilos DESC, pp.periodo ASC
           ) rn
    FROM pair_periods pp
  )
  SELECT pro_id, pro_id_2, periodo
  FROM ranked_pairs
  WHERE rn = 1 AND periodo >= ? AND periodo < ?" : "selected_pairs AS (
    SELECT pro_id, pro_id_2, MIN(op_dia) periodo
    FROM eligible_tarimas
    WHERE op_dia >= ? AND op_dia < ?
    GROUP BY pro_id, pro_id_2
  )
  SELECT pro_id, pro_id_2, periodo
  FROM selected_pairs";

if ($includeAllProcesses) {
  $selectedPairsStmt = $pdo->prepare("
    CREATE TEMPORARY TABLE tmp_rp_selected_pairs AS
    SELECT candidatos.pro_id, candidatos.pro_id_2, MIN(candidatos.periodo) periodo
    FROM (
      SELECT alcance.pro_id, 0 pro_id_2, {$pairBucketSql} periodo
      FROM (
        SELECT p.pro_id, DATE(p.pro_fe_carga) op_dia
        FROM procesos p
        WHERE p.pro_fe_carga >= ? AND p.pro_fe_carga < ?
          AND p.pro_id NOT IN (0, {$barreduraProId})
      ) alcance
      UNION ALL
      SELECT t.pro_id,
             CASE
               WHEN t.pro_id_2 IS NOT NULL AND t.pro_id_2 <> 0 AND t.pro_id_2 <> t.pro_id THEN t.pro_id_2
               ELSE 0
             END pro_id_2,
             {$pairBucketSql} periodo
      FROM rev_tarimas t
      INNER JOIN (
        SELECT p.pro_id, DATE(p.pro_fe_carga) op_dia
        FROM procesos p
        WHERE p.pro_fe_carga >= ? AND p.pro_fe_carga < ?
          AND p.pro_id NOT IN (0, {$barreduraProId})
      ) alcance ON alcance.pro_id = t.pro_id
      WHERE t.tar_count_etiquetado > 0
        AND (t.pro_id_2 IS NULL OR t.pro_id_2 = 0 OR t.pro_id_2 <> {$barreduraProId})
    ) candidatos
    GROUP BY candidatos.pro_id, candidatos.pro_id_2
  ");
  $selectedPairsStmt->execute([
    $start->format('Y-m-d'),
    $endExclusive->format('Y-m-d'),
    $start->format('Y-m-d'),
    $endExclusive->format('Y-m-d'),
  ]);
} else {
  $selectedPairsStmt = $pdo->prepare("
    CREATE TEMPORARY TABLE tmp_rp_selected_pairs AS
    WITH
    closed_processes AS (
      SELECT DISTINCT pa.pro_id
      FROM procesos_agrupados pa
      INNER JOIN lotes_anio lote ON lote.lote_id = pa.lote_id AND lote.lote_estatus = 3
    ),
    eligible_tarimas AS (
      SELECT t.pro_id,
             CASE
               WHEN t.pro_id_2 IS NOT NULL AND t.pro_id_2 <> 0 AND t.pro_id_2 <> t.pro_id THEN t.pro_id_2
               ELSE 0
             END pro_id_2,
             t.tar_kilos,
             {$operationDateSql} op_dia
      FROM rev_tarimas t
      INNER JOIN closed_processes cp1 ON cp1.pro_id = t.pro_id
      LEFT JOIN closed_processes cp2 ON cp2.pro_id = t.pro_id_2
      WHERE t.tar_count_etiquetado > 0
        AND t.pro_id IS NOT NULL
        AND t.pro_id NOT IN (0, {$barreduraProId})
        AND (t.pro_id_2 IS NULL OR t.pro_id_2 = 0 OR t.pro_id_2 <> {$barreduraProId})
        AND (
          t.pro_id_2 IS NULL
          OR t.pro_id_2 = 0
          OR t.pro_id_2 = t.pro_id
          OR cp2.pro_id IS NOT NULL
        )
    ),
    {$pairSelectionSql}
  ");
  $selectedPairsStmt->execute([$start->format('Y-m-d'), $endExclusive->format('Y-m-d')]);
}
$pdo->exec('ALTER TABLE tmp_rp_selected_pairs ADD PRIMARY KEY (pro_id, pro_id_2)');
$selectedPairRows = $pdo->query('SELECT pro_id, pro_id_2, periodo FROM tmp_rp_selected_pairs')->fetchAll() ?: [];
$selectedPairsSql = $selectedPairRows === []
  ? 'SELECT NULL pro_id, NULL pro_id_2 WHERE 1 = 0'
  : implode(' UNION ALL ', array_map(
      static fn(array $pair): string => 'SELECT ' . (int)$pair['pro_id'] . ' pro_id, ' . (int)$pair['pro_id_2'] . ' pro_id_2',
      $selectedPairRows
    ));
$scopeIds = [];
foreach ($selectedPairRows as $selectedPair) {
  $primaryProcessId = (int)$selectedPair['pro_id'];
  $secondaryProcessId = (int)$selectedPair['pro_id_2'];
  $scopeIds[$primaryProcessId] = true;
  if ($secondaryProcessId > 0) {
    $scopeIds[$secondaryProcessId] = true;
  }
}
$scopeProcessSql = $scopeIds === []
  ? 'SELECT NULL pro_id WHERE 1 = 0'
  : implode(' UNION ALL ', array_map(
      static fn(int $processId): string => 'SELECT ' . $processId . ' pro_id',
      array_keys($scopeIds)
    ));
$tarGroupPeriodSql = $periodMode === 'mes'
  ? ''
  : " AND {$operationDateSql} >= '" . $start->format('Y-m-d') . "'"
    . " AND {$operationDateSql} < '" . $endExclusive->format('Y-m-d') . "'";

// Los catálogos de filtro no dependen del periodo ni se limitan entre sí.
// Se incluyen todas las opciones que realmente han sido usadas en procesos.
$materialOptionStmt = $pdo->query("
  SELECT DISTINCT {$materialFamilySql} AS material
  FROM procesos_materiales pm
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  ORDER BY material
");
$materialOptions = array_values(array_filter(array_map(
  static fn(array $row): string => (string)($row['material'] ?? ''),
  $materialOptionStmt->fetchAll() ?: []
)));
if ($selectedMaterial !== 'all' && !in_array($selectedMaterial, $materialOptions, true)) {
  $selectedMaterial = 'all';
}

$providerOptionStmt = $pdo->query("
  SELECT DISTINCT prv.prv_id, prv.prv_nombre
  FROM procesos_materiales pm
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN proveedores prv ON prv.prv_id = i.prv_id
  ORDER BY prv.prv_nombre
");
$providerOptions = $providerOptionStmt->fetchAll() ?: [];
$validProviderIds = array_map('intval', array_column($providerOptions, 'prv_id'));
if ($selectedProvider !== null && !in_array($selectedProvider, $validProviderIds, true)) {
  $selectedProvider = null;
}

$detailWhere = [];
$detailParams = [];
if ($selectedMaterial !== 'all') {
  $detailWhere[] = 'mr.material = ?';
  $detailParams[] = $selectedMaterial;
}
if ($selectedProvider !== null) {
  $detailWhere[] = 'mr.prv_id = ?';
  $detailParams[] = $selectedProvider;
}
$detailWhereSql = $detailWhere === [] ? '' : 'WHERE ' . implode(' AND ', $detailWhere);

$sql = "
WITH
scope AS (
  SELECT p.pro_id, p.pt_id, p.pro_fe_carga
  FROM procesos p
  INNER JOIN ({$scopeProcessSql}) alcance ON alcance.pro_id = p.pro_id
),
eqr AS (
  SELECT pe.pro_id, ep.ep_descripcion,
         ROW_NUMBER() OVER (PARTITION BY pe.pro_id ORDER BY pe.ped_fe_hr, pe.ped_id) rn
  FROM procesos_equipos pe
  INNER JOIN scope s ON s.pro_id = pe.pro_id
  INNER JOIN equipos_preparacion ep ON ep.ep_id = pe.ep_id
),
enzr AS (
  SELECT g.*,
         ROW_NUMBER() OVER (PARTITION BY g.pro_id ORDER BY g.pfg2_id DESC) rn
  FROM procesos_fase_2b_g g
  INNER JOIN scope s ON s.pro_id = g.pro_id
  WHERE g.pe_id = 3
),
enzlib AS (
  SELECT l.*,
         ROW_NUMBER() OVER (PARTITION BY l.pro_id ORDER BY l.prol_fecha DESC, l.prol_id DESC) rn
  FROM procesos_liberacion l
  INNER JOIN scope s ON s.pro_id = l.pro_id
  WHERE l.pe_id = 3
),
libb AS (
  SELECT b.*,
         ROW_NUMBER() OVER (
           PARTITION BY b.pro_id, b.pe_id
           ORDER BY b.prol_fecha DESC, b.prol_hora DESC, b.prol_id DESC
         ) rn
  FROM procesos_liberacion_b b
  INNER JOIN scope s ON s.pro_id = b.pro_id
  WHERE b.pe_id IN (17, 18, 20)
),
coc AS (
  SELECT b.pro_id, b.pe_id, c.prol_cocido, c.prol_ce,
         ROW_NUMBER() OVER (
           PARTITION BY b.pro_id, b.pe_id
           ORDER BY ((c.prol_cocido <> 0) OR (c.prol_ce <> 0)) DESC, c.prol_ren DESC
         ) rn
  FROM libb b
  INNER JOIN procesos_liberacion_b_cocidos c ON c.prol_id = b.prol_id
  WHERE b.rn = 1
),
extfin AS (
  SELECT b.pro_id, b.pe_id, c.prol_por_extrac,
         ROW_NUMBER() OVER (
           PARTITION BY b.pro_id, b.pe_id
           ORDER BY (c.prol_por_extrac <> 0) DESC, c.prol_ren DESC
         ) rn
  FROM libb b
  INNER JOIN procesos_liberacion_b_cocidos c ON c.prol_id = b.prol_id
  WHERE b.rn = 1
),
g7 AS (
  SELECT g.*,
         ROW_NUMBER() OVER (PARTITION BY g.pro_id, g.pe_id ORDER BY g.pfg7_id DESC) rn
  FROM procesos_fase_7b_g g
  INNER JOIN scope s ON s.pro_id = g.pro_id
  WHERE g.pe_id IN (18, 19)
),
d7 AS (
  SELECT g.pro_id, g.pe_id, d.pfd7_norm,
         ROW_NUMBER() OVER (
           PARTITION BY g.pro_id, g.pe_id
           ORDER BY d.pfd7_ren DESC, d.pfd7_id DESC
         ) rn
  FROM g7 g
  INNER JOIN procesos_fase_7b_d d ON d.pfg7_id = g.pfg7_id
  WHERE g.rn = 1
),
g6 AS (
  SELECT g.*,
         ROW_NUMBER() OVER (PARTITION BY g.pro_id, g.pe_id ORDER BY g.pfg6_id DESC) rn
  FROM procesos_fase_6_g g
  INNER JOIN scope s ON s.pro_id = g.pro_id
  WHERE g.pe_id = 14
),
d6 AS (
  SELECT g.pro_id, g.pe_id, d.pfd6_norm,
         ROW_NUMBER() OVER (
           PARTITION BY g.pro_id, g.pe_id
           ORDER BY d.pfd6_ren DESC, d.pfd6_id DESC
         ) rn
  FROM g6 g
  INNER JOIN procesos_fase_6_d d ON d.pfg6_id = g.pfg6_id
  WHERE g.rn = 1
),
mp_total AS (
  SELECT pm.pro_id,
         SUM(CASE
           WHEN i.inv_enviado = 2 THEN i.inv_kilos
           ELSE i.inv_kg_totales
         END) kg_mp_total,
         MAX(m.mat_id = 1) es_carnaza
  FROM procesos_materiales pm
  INNER JOIN scope s ON s.pro_id = pm.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  GROUP BY pm.pro_id
),
tar_grupo AS (
  SELECT t.pro_id, MAX(t.pro_id_2) pro_id_2,
         COUNT(*) tarimas, SUM(t.tar_kilos) kg_producto_terminado,
         AVG(t.tar_bloom) bloom_promedio, AVG(t.tar_viscosidad) viscosidad_promedio
  FROM rev_tarimas t
  INNER JOIN ({$selectedPairsSql}) par
    ON par.pro_id = t.pro_id
   AND par.pro_id_2 = CASE
     WHEN t.pro_id_2 IS NOT NULL AND t.pro_id_2 <> 0 AND t.pro_id_2 <> t.pro_id THEN t.pro_id_2
     ELSE 0
   END
  WHERE t.tar_count_etiquetado > 0
    {$tarGroupPeriodSql}
  GROUP BY t.pro_id
),
rend_grupo AS (
  SELECT tg.pro_id grupo_pro_id, tg.pro_id, tg.pro_id_2, tg.tarimas,
         tg.kg_producto_terminado grupo_kg_producto_terminado,
         tg.bloom_promedio, tg.viscosidad_promedio,
         mp1.kg_mp_total + COALESCE(mp2.kg_mp_total, 0) kg_mp_grupo,
         mp1.kg_mp_total kg_mp_primario,
         mp2.kg_mp_total kg_mp_secundario,
         tg.kg_producto_terminado * CASE
           WHEN tg.pro_id_2 IS NULL OR tg.pro_id_2 = 0 OR tg.pro_id_2 = tg.pro_id THEN 1
           WHEN mp1.es_carnaza = 1 AND COALESCE(mp2.es_carnaza, 0) = 0 THEN 0.70
           WHEN COALESCE(mp2.es_carnaza, 0) = 1 AND mp1.es_carnaza = 0 THEN 0.30
           ELSE 0.50
         END kg_producto_primario,
         tg.kg_producto_terminado * CASE
           WHEN tg.pro_id_2 IS NULL OR tg.pro_id_2 = 0 OR tg.pro_id_2 = tg.pro_id THEN 0
           WHEN COALESCE(mp2.es_carnaza, 0) = 1 AND mp1.es_carnaza = 0 THEN 0.70
           WHEN mp1.es_carnaza = 1 AND COALESCE(mp2.es_carnaza, 0) = 0 THEN 0.30
           ELSE 0.50
         END kg_producto_secundario
  FROM tar_grupo tg
  LEFT JOIN mp_total mp1 ON mp1.pro_id = tg.pro_id
  LEFT JOIN mp_total mp2 ON mp2.pro_id = tg.pro_id_2
),
rend_proceso AS (
  SELECT grupo_pro_id, pro_id, tarimas, grupo_kg_producto_terminado, bloom_promedio,
         viscosidad_promedio, kg_mp_grupo, kg_producto_primario kg_producto_proceso,
         kg_mp_primario kg_mp_proceso,
         kg_producto_primario / NULLIF(kg_mp_primario, 0) rendimiento_pt
  FROM rend_grupo
  UNION ALL
  SELECT grupo_pro_id, pro_id_2, tarimas, grupo_kg_producto_terminado, bloom_promedio,
         viscosidad_promedio, kg_mp_grupo, kg_producto_secundario kg_producto_proceso,
         kg_mp_secundario kg_mp_proceso,
         kg_producto_secundario / NULLIF(kg_mp_secundario, 0) rendimiento_pt
  FROM rend_grupo r
  WHERE pro_id_2 IS NOT NULL
    AND NOT EXISTS (SELECT 1 FROM rend_grupo x WHERE x.pro_id = r.pro_id_2)
),
maq_proceso_ticket AS (
  SELECT vinc.pro_id, i.inv_no_ticket,
         SUM(COALESCE(i.inv_kilos, 0)) kg_enviados,
         SUM(COALESCE(i.inv_kg_totales, 0)) kg_recibidos,
         (SUM(COALESCE(i.inv_kg_totales, 0)) / NULLIF(SUM(COALESCE(i.inv_kilos, 0)), 0) - 1) * 100 rendimiento_granja
  FROM (
    SELECT DISTINCT pm.pro_id, pm.inv_id
    FROM procesos_materiales pm
    INNER JOIN scope s ON s.pro_id = pm.pro_id
  ) vinc
  INNER JOIN inventario i ON i.inv_id = vinc.inv_id
  WHERE i.inv_enviado = 2
  GROUP BY vinc.pro_id, i.inv_no_ticket
),
material_rows AS (
  SELECT s.pro_id, s.pt_id, s.pro_fe_carga, i.inv_no_ticket,
         {$materialFamilySql} material, m.mat_id,
         i.prv_id, prv.prv_nombre proveedor,
         i.inv_kilos kg_mp,
         CASE
           WHEN i.inv_enviado = 2 THEN i.inv_kilos
           ELSE i.inv_kg_totales
         END kg_mp_rendimiento,
         i.inv_humedad, i.inv_extrac, i.inv_solidos, i.inv_ph, i.inv_rendimiento, i.inv_riesgo
  FROM scope s
  INNER JOIN procesos_materiales pm ON pm.pro_id = s.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  INNER JOIN proveedores prv ON prv.prv_id = i.prv_id
)
SELECT
  mr.pro_id, mr.pro_fe_carga, mr.inv_no_ticket,
  GROUP_CONCAT(DISTINCT mr.material ORDER BY mr.material SEPARATOR ' / ') material,
  GROUP_CONCAT(DISTINCT mr.mat_id ORDER BY mr.mat_id) material_ids,
  MIN(mr.prv_id) prv_id,
  GROUP_CONCAT(DISTINCT mr.proveedor ORDER BY mr.proveedor SEPARATOR ' / ') proveedor,
  SUM(mr.kg_mp) kg_mp_filtrada,
  SUM(mr.kg_mp_rendimiento) kg_mp_rendimiento_filtrada,
  AVG(mr.inv_humedad) inv_humedad, AVG(mr.inv_extrac) inv_extractibilidad,
  AVG(mr.inv_solidos) inv_solidos, AVG(mr.inv_ph) inv_ph,
  AVG(mr.inv_rendimiento) inv_rendimiento,
  GROUP_CONCAT(DISTINCT mr.inv_riesgo ORDER BY mr.inv_riesgo SEPARATOR ', ') inv_riesgo,
  SUM(mr.inv_riesgo LIKE 'ALTO%') riesgo_alto,
  eq.ep_descripcion equipo_inicial,
  rp.grupo_pro_id, rp.tarimas, rp.grupo_kg_producto_terminado, rp.kg_mp_grupo,
  rp.kg_producto_proceso * (SUM(mr.kg_mp_rendimiento) / NULLIF(rp.kg_mp_proceso, 0)) kg_producto_terminado,
  rp.rendimiento_pt, rp.bloom_promedio, rp.viscosidad_promedio,
  el.extractibilidad extractibilidad_enzima_2b,
  enz.pfg2_enzima enzima_kg, enz.pfg2_hr_totales horas_enzima,
  CASE mr.pt_id WHEN 10 THEN a7p.pfg7_acido WHEN 11 THEN a7a.pfg7_acido WHEN 7 THEN a6.pfg6_acido END acido_litros,
  CASE mr.pt_id WHEN 10 THEN n7p.pfd7_norm WHEN 11 THEN n7a.pfd7_norm WHEN 7 THEN n6.pfd6_norm END acido_normalidad,
  CASE mr.pt_id WHEN 10 THEN cp.prol_cocido WHEN 11 THEN ca.prol_cocido WHEN 7 THEN cc.prol_cocido END cocimiento_ph,
  CASE mr.pt_id WHEN 10 THEN cp.prol_ce WHEN 11 THEN ca.prol_ce WHEN 7 THEN cc.prol_ce END cocimiento_ce,
  CASE mr.pt_id WHEN 10 THEN epf.prol_por_extrac WHEN 11 THEN eaf.prol_por_extrac WHEN 7 THEN lb7.prol_por_extrac END extractibilidad_final,
  mp.kg_recibidos kg_granja,
  mp.rendimiento_granja
FROM material_rows mr
LEFT JOIN eqr eq ON eq.pro_id = mr.pro_id AND eq.rn = 1
LEFT JOIN rend_proceso rp ON rp.pro_id = mr.pro_id
LEFT JOIN enzr enz ON enz.pro_id = mr.pro_id AND enz.rn = 1
LEFT JOIN enzlib el ON el.pro_id = mr.pro_id AND el.rn = 1
LEFT JOIN g7 a7p ON a7p.pro_id = mr.pro_id AND a7p.pe_id = 19 AND a7p.rn = 1
LEFT JOIN d7 n7p ON n7p.pro_id = mr.pro_id AND n7p.pe_id = 19 AND n7p.rn = 1
LEFT JOIN g7 a7a ON a7a.pro_id = mr.pro_id AND a7a.pe_id = 18 AND a7a.rn = 1
LEFT JOIN d7 n7a ON n7a.pro_id = mr.pro_id AND n7a.pe_id = 18 AND n7a.rn = 1
LEFT JOIN g6 a6 ON a6.pro_id = mr.pro_id AND a6.pe_id = 14 AND a6.rn = 1
LEFT JOIN d6 n6 ON n6.pro_id = mr.pro_id AND n6.pe_id = 14 AND n6.rn = 1
LEFT JOIN coc cp ON cp.pro_id = mr.pro_id AND cp.pe_id = 20 AND cp.rn = 1
LEFT JOIN coc ca ON ca.pro_id = mr.pro_id AND ca.pe_id = 18 AND ca.rn = 1
LEFT JOIN coc cc ON cc.pro_id = mr.pro_id AND cc.pe_id = 17 AND cc.rn = 1
LEFT JOIN extfin epf ON epf.pro_id = mr.pro_id AND epf.pe_id = 20 AND epf.rn = 1
LEFT JOIN extfin eaf ON eaf.pro_id = mr.pro_id AND eaf.pe_id = 18 AND eaf.rn = 1
LEFT JOIN libb lb7 ON lb7.pro_id = mr.pro_id AND lb7.pe_id = 17 AND lb7.rn = 1
LEFT JOIN maq_proceso_ticket mp ON mp.pro_id = mr.pro_id AND mp.inv_no_ticket = mr.inv_no_ticket
{$detailWhereSql}
GROUP BY mr.pro_id, mr.pro_fe_carga, mr.inv_no_ticket,
         eq.ep_descripcion, rp.grupo_pro_id, rp.tarimas, rp.grupo_kg_producto_terminado,
         rp.kg_mp_grupo, rp.kg_producto_proceso, rp.kg_mp_proceso,
         rp.rendimiento_pt, rp.bloom_promedio, rp.viscosidad_promedio,
         el.extractibilidad, enz.pfg2_enzima, enz.pfg2_hr_totales,
         acido_litros, acido_normalidad, cocimiento_ph, cocimiento_ce,
         extractibilidad_final, mp.kg_recibidos, mp.rendimiento_granja
ORDER BY mr.pro_id DESC, mr.inv_no_ticket DESC
";

$detailStmt = $pdo->prepare($sql);
$detailStmt->execute($detailParams);
$rows = $detailStmt->fetchAll() ?: [];

$periodProductionStmt = $pdo->prepare("
  SELECT COUNT(*) tarimas, COALESCE(SUM(d.tar_kilos), 0) kg_producto_terminado,
         AVG(d.tar_bloom) bloom_promedio, AVG(d.tar_viscosidad) viscosidad_promedio
  FROM (
    SELECT t.tar_kilos, t.tar_bloom, t.tar_viscosidad, {$operationDateSql} op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < DATE_ADD(?, INTERVAL 7 HOUR)
      AND t.tar_count_etiquetado > 0
  ) d
  WHERE d.op_dia >= ? AND d.op_dia < ?
");
$periodProductionStmt->execute([
  $start->format('Y-m-d H:i:s'),
  $endExclusive->format('Y-m-d H:i:s'),
  $start->format('Y-m-d'),
  $endExclusive->format('Y-m-d'),
]);
$periodProduction = $periodProductionStmt->fetch() ?: [];

$barreduraStmt = $pdo->prepare("
  SELECT COUNT(*) tarimas, COALESCE(SUM(d.tar_kilos), 0) kg_producto_terminado,
         AVG(d.tar_bloom) bloom_promedio, AVG(d.tar_viscosidad) viscosidad_promedio
  FROM (
    SELECT t.tar_kilos, t.tar_bloom, t.tar_viscosidad, t.pro_id, t.pro_id_2,
           {$operationDateSql} op_dia
    FROM rev_tarimas t
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < DATE_ADD(?, INTERVAL 7 HOUR)
      AND t.tar_count_etiquetado > 0
  ) d
  WHERE d.op_dia >= ? AND d.op_dia < ?
    AND (d.pro_id = {$barreduraProId} OR d.pro_id_2 = {$barreduraProId})
");
$barreduraStmt->execute([
  $start->format('Y-m-d H:i:s'),
  $endExclusive->format('Y-m-d H:i:s'),
  $start->format('Y-m-d'),
  $endExclusive->format('Y-m-d'),
]);
$barredura = $barreduraStmt->fetch() ?: [];

$processes = [];
$productionGroups = [];
$materialChart = [];
$providerChart = [];
$riskHigh = 0;
$selectedMpKg = 0.0;
$selectedMpRendimientoKg = 0.0;

foreach ($rows as &$row) {
  $processId = (int)$row['pro_id'];
  $groupId = (int)($row['grupo_pro_id'] ?? 0);
  $row['pro_id'] = $processId;
  $row['prv_id'] = (int)$row['prv_id'];
  $row['riesgo_alto'] = (int)$row['riesgo_alto'];
  foreach ([
    'kg_mp_filtrada', 'kg_mp_rendimiento_filtrada', 'inv_humedad', 'inv_extractibilidad', 'inv_solidos', 'inv_ph',
    'inv_rendimiento', 'kg_producto_terminado', 'grupo_kg_producto_terminado', 'kg_mp_grupo', 'rendimiento_pt',
    'bloom_promedio', 'viscosidad_promedio', 'extractibilidad_enzima_2b', 'enzima_kg',
    'horas_enzima', 'acido_litros', 'acido_normalidad', 'cocimiento_ph', 'cocimiento_ce',
    'extractibilidad_final', 'kg_granja', 'rendimiento_granja',
  ] as $field) {
    $row[$field] = $row[$field] === null ? null : (float)$row[$field];
  }
  $row['semaforos_lab'] = [
    'rendimiento' => $evaluateLabBands($row['inv_rendimiento'], (array)($labParams['rendimiento'] ?? [])),
    'humedad' => $evaluateLabBands($row['inv_humedad'], (array)($labParams['humedad'] ?? [])),
    'extractibilidad' => $evaluateLabBands($row['inv_extractibilidad'], (array)($labParams['extractibilidad'] ?? [])),
    'solidos' => $evaluateLabBands($row['inv_solidos'], (array)($labParams['solidos'] ?? [])),
    'ph' => $evaluateLabBands($row['inv_ph'], (array)($labParams['ph'] ?? [])),
  ];
  $materialNames = array_map('trim', explode('/', strtoupper((string)($row['material'] ?? ''))));
  $materialIds = array_values(array_filter(array_map('intval', explode(',', (string)($row['material_ids'] ?? '')))));
  $enzymeRuleKeys = $materialNames;
  if (array_intersect($materialIds, [5, 7, 9, 12]) !== []) $enzymeRuleKeys[] = 'CUERO_ENTERO';
  if (array_intersect($materialIds, [2, 6, 8]) !== []) $enzymeRuleKeys[] = 'PEDACERA_AMERICANA';
  $enzymeRuleKeys = array_values(array_unique($enzymeRuleKeys));
  $enzymeStatus = null;
  $statusPriority = ['gris' => 0, 'verde' => 1, 'amarillo' => 2, 'rojo' => 3];
  foreach ($enzymeRuleKeys as $materialName) {
    $enzymeRule = (array)($processParams['extractibilidad_enzima'][$materialName] ?? []);
    if ($enzymeRule === []) continue;
    $candidate = $evaluateLabBands($row['extractibilidad_enzima_2b'], $enzymeRule);
    $candidate['range'] = $materialName . ' · ' . (string)($candidate['range'] ?? '');
    if ($enzymeStatus === null || ($statusPriority[$candidate['key']] ?? 0) > ($statusPriority[$enzymeStatus['key']] ?? 0)) {
      $enzymeStatus = $candidate;
    }
  }
  $rendimientoPtRuleKeys = $materialNames;
  if (array_intersect($materialIds, [5, 7, 9, 12]) !== []) $rendimientoPtRuleKeys[] = 'CUERO_ENTERO';
  if (array_intersect($materialIds, [2, 6, 8, 14]) !== []) $rendimientoPtRuleKeys[] = 'PEDACERA';
  $rendimientoPtRuleKeys = array_values(array_unique($rendimientoPtRuleKeys));
  $rendimientoPtStatus = null;
  foreach ($rendimientoPtRuleKeys as $materialName) {
    $rendimientoPtRule = (array)($processParams['rendimiento_pt'][$materialName] ?? []);
    if ($rendimientoPtRule === []) continue;
    $candidate = $evaluateLabBands(
      $row['rendimiento_pt'] === null ? null : (float)$row['rendimiento_pt'] * 100,
      $rendimientoPtRule
    );
    $candidate['range'] = $materialName . ' · ' . (string)($candidate['range'] ?? '');
    if ($rendimientoPtStatus === null || ($statusPriority[$candidate['key']] ?? 0) > ($statusPriority[$rendimientoPtStatus['key']] ?? 0)) {
      $rendimientoPtStatus = $candidate;
    }
  }
  $row['semaforos_proceso'] = [
    'extractibilidad_enzima' => $enzymeStatus,
    'rendimiento_pt' => $rendimientoPtStatus,
  ];

  $processes[$processId] = true;
  $selectedMpKg += (float)$row['kg_mp_filtrada'];
  $selectedMpRendimientoKg += (float)$row['kg_mp_rendimiento_filtrada'];
  $riskHigh += (int)$row['riesgo_alto'];

  if ($groupId > 0 && !isset($productionGroups[$groupId])) {
    $productionGroups[$groupId] = [
      'kg_pt' => (float)($row['grupo_kg_producto_terminado'] ?? 0),
      'kg_mp' => (float)($row['kg_mp_grupo'] ?? 0),
      'tarimas' => (int)($row['tarimas'] ?? 0),
      'bloom' => $row['bloom_promedio'],
      'viscosidad' => $row['viscosidad_promedio'],
    ];
  }

  $material = (string)$row['material'];
  $materialChart[$material] = ($materialChart[$material] ?? 0) + (float)$row['kg_mp_rendimiento_filtrada'];

  $providerId = (int)$row['prv_id'];
  if (!isset($providerChart[$providerId])) {
    $providerChart[$providerId] = [
      'label' => (string)$row['proveedor'],
      'kg' => 0.0,
      'kg_pt' => 0.0,
    ];
  }
  $providerChart[$providerId]['kg'] += (float)$row['kg_mp_rendimiento_filtrada'];
  $providerChart[$providerId]['kg_pt'] += (float)($row['kg_producto_terminado'] ?? 0);
}
unset($row);

$detailRows = $rows;
$detailPeriodLabel = $periodLabel;

$hasParticipationFilter = $selectedMaterial !== 'all' || $selectedProvider !== null;
$totalPt = 0.0;
$totalGroupMp = 0.0;
$totalTarimas = 0;
$bloomWeighted = 0.0;
$bloomWeight = 0;
$viscWeighted = 0.0;
$viscWeight = 0;
foreach ($productionGroups as $group) {
  if (!$hasParticipationFilter) {
    $totalPt += $group['kg_pt'];
    $totalGroupMp += $group['kg_mp'];
  }
  $totalTarimas += $group['tarimas'];
  if ($group['bloom'] !== null && $group['tarimas'] > 0) {
    $bloomWeighted += (float)$group['bloom'] * $group['tarimas'];
    $bloomWeight += $group['tarimas'];
  }
  if ($group['viscosidad'] !== null && $group['tarimas'] > 0) {
    $viscWeighted += (float)$group['viscosidad'] * $group['tarimas'];
    $viscWeight += $group['tarimas'];
  }
}
if ($hasParticipationFilter) {
  $totalPt = array_sum(array_map(
    static fn(array $row): float => (float)($row['kg_producto_terminado'] ?? 0),
    $rows
  ));
  $totalGroupMp = $selectedMpRendimientoKg;
}
$closedPtForYield = $totalPt;

$barreduraTarimas = (int)($barredura['tarimas'] ?? 0);
$barreduraKg = (float)($barredura['kg_producto_terminado'] ?? 0);
$yieldPt = $closedPtForYield + ($hasParticipationFilter ? 0.0 : $barreduraKg);
$barreduraBloom = is_numeric($barredura['bloom_promedio'] ?? null) ? (float)$barredura['bloom_promedio'] : null;
$barreduraViscosidad = is_numeric($barredura['viscosidad_promedio'] ?? null) ? (float)$barredura['viscosidad_promedio'] : null;
if (!$hasParticipationFilter) {
  $totalPt += $barreduraKg;
  $totalTarimas += $barreduraTarimas;
  if ($barreduraBloom !== null && $barreduraTarimas > 0) {
    $bloomWeighted += $barreduraBloom * $barreduraTarimas;
    $bloomWeight += $barreduraTarimas;
  }
  if ($barreduraViscosidad !== null && $barreduraTarimas > 0) {
    $viscWeighted += $barreduraViscosidad * $barreduraTarimas;
    $viscWeight += $barreduraTarimas;
  }
}

$displayPt = $totalPt;
$displayTarimas = $totalTarimas;
$displayBloom = $bloomWeight > 0 ? $bloomWeighted / $bloomWeight : null;
$displayViscosidad = $viscWeight > 0 ? $viscWeighted / $viscWeight : null;
if (!$hasParticipationFilter) {
  $displayPt = (float)($periodProduction['kg_producto_terminado'] ?? 0);
  $displayTarimas = (int)($periodProduction['tarimas'] ?? 0);
  $displayBloom = is_numeric($periodProduction['bloom_promedio'] ?? null) ? (float)$periodProduction['bloom_promedio'] : null;
  $displayViscosidad = is_numeric($periodProduction['viscosidad_promedio'] ?? null) ? (float)$periodProduction['viscosidad_promedio'] : null;
}

arsort($materialChart);
uasort($providerChart, static fn(array $a, array $b): int => $b['kg'] <=> $a['kg']);
$providerChart = array_slice($providerChart, 0, 12, true);
$providerChartRows = [];
foreach ($providerChart as $provider) {
  $providerChartRows[] = [
    'label' => $provider['label'],
    'kg' => $provider['kg'],
    'rendimiento' => $provider['kg'] > 0 ? ($provider['kg_pt'] / $provider['kg']) * 100 : null,
  ];
}

$processChartMap = [];
foreach ($rows as $row) {
  $processId = (int)$row['pro_id'];
  if ($row['rendimiento_pt'] === null || isset($processChartMap[$processId])) {
    continue;
  }
  $processChartMap[$processId] = [
    'proceso' => $processId,
    'rendimiento' => (float)$row['rendimiento_pt'] * 100,
  ];
}
krsort($processChartMap);
$processChartRows = array_reverse(array_slice(array_values($processChartMap), 0, 18));

/*
 * La comparación proveedor/material usa exactamente la misma fuente y cálculo
 * del reporte Materia Prima. Se carga en un ámbito aislado para no alterar las
 * variables, filtros ni conexión de Rendimiento por Procesos.
 */
$providerMaterialComparisonGroups = [];
$providerMaterialComparisonError = null;
$monthlyCostPerKg = null;
$weeklyCostPerKg = null;
$providerMaterialMonthAnchor = $periodMode === 'semana'
  ? $selectedWeekEnd->modify('+6 days')
  : ($periodMode === 'fecha' ? $selectedEndDate : $start);
$providerMaterialYear = (int)$providerMaterialMonthAnchor->format('Y');
$providerMaterialMonth = (int)$providerMaterialMonthAnchor->format('n');
$originalQuery = $_GET;
try {
  $loadMateriaPrimaComparison = static function (array $query): array {
    $_GET = $query;
    $config = require __DIR__ . '/../materia-prima/config.php';
    $config['proveedor_material_todas_semanas'] = true;
    // En este reporte el comparativo proveedor/material parte de los kilos
    // comprados del ticket, no de los kilos consumidos por el proceso.
    $config['proveedor_material_usar_consumo_proceso'] = false;
    $appConfig = require __DIR__ . '/../../config/app.php';
    $dbConfig = require __DIR__ . '/../../config/database.php';
    return require __DIR__ . '/../materia-prima/build_report.php';
  };
  $materiaPrimaReport = $loadMateriaPrimaComparison([
    'periodo' => 'mes',
    'anio' => $providerMaterialYear,
    'mes' => $providerMaterialMonth,
    'mt_id' => 'all',
  ]);
  $periodRows = (array)($materiaPrimaReport['tablas']['proveedor_material_periodos'] ?? []);
  $selectedPeriodRows = $periodRows;
  if ($periodMode === 'semana') {
    $selectedPeriodReport = $loadMateriaPrimaComparison([
      'periodo' => 'semana',
      'semana_inicio' => $selectedWeekValue,
      'semana_fin' => $selectedWeekEndValue,
      'mt_id' => 'all',
    ]);
    $selectedPeriodRows = (array)($selectedPeriodReport['tablas']['proveedor_material_periodos'] ?? []);
  } elseif ($periodMode === 'fecha') {
    $selectedPeriodReport = $loadMateriaPrimaComparison([
      'periodo' => 'fecha',
      'fecha_inicio' => $selectedStartDate->format('Y-m-d'),
      'fecha_fin' => $selectedEndDate->format('Y-m-d'),
      'mt_id' => 'all',
    ]);
    $selectedPeriodRows = (array)($selectedPeriodReport['tablas']['proveedor_material_periodos'] ?? []);
  }
  $matchesProviderMaterialFilters = static function (array $periodRow) use ($selectedMaterial, $selectedProvider): bool {
    if ($selectedMaterial !== 'all' && (string)($periodRow['grupo'] ?? '') !== $selectedMaterial) {
      return false;
    }
    if ($selectedProvider !== null && (int)($periodRow['prv_id'] ?? 0) !== $selectedProvider) {
      return false;
    }
    return true;
  };
  $monthlyPeriodRows = array_values(array_filter(
    (array)($periodRows['mensual'] ?? []),
    $matchesProviderMaterialFilters
  ));
  $allWeeklyPeriodRows = array_values(array_filter(
    (array)($periodRows['semanal'] ?? []),
    $matchesProviderMaterialFilters
  ));
  $selectedRangeWeeklyPeriodRows = array_values(array_filter(
    (array)($selectedPeriodRows['semanal'] ?? []),
    $matchesProviderMaterialFilters
  ));
  $hasProviderMaterialFilter = $selectedMaterial !== 'all' || $selectedProvider !== null;
  $selectedComparisonWeekStartKey = '';
  $selectedComparisonWeekEndKey = '';
  if ($periodMode === 'semana') {
    $selectedComparisonWeekStartKey = $selectedWeekStart->format('oW');
    $selectedComparisonWeekEndKey = $selectedWeekEnd->format('oW');
  } elseif ($periodMode === 'fecha') {
    $selectedComparisonWeekStartKey = $selectedStartDate->format('oW');
    $selectedComparisonWeekEndKey = $selectedEndDate->format('oW');
  } elseif ($allWeeklyPeriodRows !== []) {
    $selectedComparisonWeekStartKey = (string)($allWeeklyPeriodRows[0]['periodo_clave'] ?? '');
    $selectedComparisonWeekEndKey = $selectedComparisonWeekStartKey;
  }
  $selectedWeeklyPeriodRows = array_values(array_filter(
    $selectedRangeWeeklyPeriodRows,
    static function (array $weeklyRow) use ($selectedComparisonWeekStartKey, $selectedComparisonWeekEndKey): bool {
      $weekKey = (string)($weeklyRow['periodo_clave'] ?? '');
      return $weekKey !== ''
        && $weekKey >= $selectedComparisonWeekStartKey
        && $weekKey <= $selectedComparisonWeekEndKey;
    }
  ));
  $weeklyPeriodRows = $hasProviderMaterialFilter
    ? $allWeeklyPeriodRows
    : $selectedWeeklyPeriodRows;
  $weightedCostPerKg = static function (array $costRows): ?float {
    $weightedCost = 0.0;
    $producedKg = 0.0;
    foreach ($costRows as $costRow) {
      $rowCost = $costRow['costo_cuero_kg_produccion'] ?? null;
      $rowProducedKg = $costRow['kilos_producidos'] ?? null;
      if (!is_numeric($rowCost) || !is_numeric($rowProducedKg) || (float)$rowProducedKg <= 0) {
        continue;
      }
      $weightedCost += (float)$rowCost * (float)$rowProducedKg;
      $producedKg += (float)$rowProducedKg;
    }
    return $producedKg > 0 ? $weightedCost / $producedKg : null;
  };
  $monthlyCostPerKg = $weightedCostPerKg($monthlyPeriodRows);
  $weeklyCostPerKg = $weightedCostPerKg($selectedWeeklyPeriodRows);
  $comparisonGroups = [];
  foreach ($monthlyPeriodRows as $monthlyRow) {
    $key = (int)($monthlyRow['prv_id'] ?? 0) . '|' . (int)($monthlyRow['mat_id'] ?? 0);
    $comparisonGroups[$key] = [
      'identidad' => $monthlyRow,
      'mensual' => $monthlyRow,
      'semanas' => [],
    ];
  }
  foreach ($weeklyPeriodRows as $weeklyRow) {
    $key = (int)($weeklyRow['prv_id'] ?? 0) . '|' . (int)($weeklyRow['mat_id'] ?? 0);
    if (!isset($comparisonGroups[$key])) {
      $comparisonGroups[$key] = [
        'identidad' => $weeklyRow,
        'mensual' => [],
        'semanas' => [],
      ];
    }
    $comparisonGroups[$key]['semanas'][] = $weeklyRow;
  }
  $providerMaterialComparisonGroups = array_values(array_filter(
    $comparisonGroups,
    static fn(array $group): bool => (array)($group['mensual'] ?? []) !== []
      || (array)($group['semanas'] ?? []) !== []
  ));
  usort($providerMaterialComparisonGroups, static function (array $left, array $right): int {
    $leftMonthly = (array)($left['mensual'] ?? []);
    $rightMonthly = (array)($right['mensual'] ?? []);
    $leftCost = is_numeric($leftMonthly['costo_cuero_kg_produccion'] ?? null)
      ? (float)$leftMonthly['costo_cuero_kg_produccion']
      : -INF;
    $rightCost = is_numeric($rightMonthly['costo_cuero_kg_produccion'] ?? null)
      ? (float)$rightMonthly['costo_cuero_kg_produccion']
      : -INF;
    $costCompare = $rightCost <=> $leftCost;
    if ($costCompare !== 0) return $costCompare;
    $leftIdentity = (array)($left['identidad'] ?? []);
    $rightIdentity = (array)($right['identidad'] ?? []);
    return strcasecmp((string)($leftIdentity['proveedor'] ?? ''), (string)($rightIdentity['proveedor'] ?? ''))
      ?: strcasecmp((string)($leftIdentity['material'] ?? ''), (string)($rightIdentity['material'] ?? ''));
  });
} catch (Throwable $providerMaterialException) {
  $providerMaterialComparisonError = 'No fue posible cargar la comparación por proveedor y material.';
} finally {
  $_GET = $originalQuery;
  date_default_timezone_set($timezone);
}

$inventoryEntryRows = [];
$inventoryEntryCriteria = [];
$inventoryEntryError = null;
if (!empty($config['mostrar_tabla_inventario_entrada'])) {
  $originalInventoryQuery = $_GET;
  try {
    $inventoryQuery = [
      'periodo' => $periodMode,
      'anio' => $selectedYear,
      'mes' => $selectedMonth,
      'semana_inicio' => $selectedWeekValue,
      'semana_fin' => $selectedWeekEndValue,
      'fecha_inicio' => $selectedStartDate->format('Y-m-d'),
      'fecha_fin' => $selectedEndDate->format('Y-m-d'),
    ];
    if ($selectedProvider !== null) {
      $inventoryQuery['proveedor'] = $selectedProvider;
    }
    $loadInventoryEntries = static function (array $query): array {
      $_GET = $query;
      $config = require __DIR__ . '/../inventario-materia-prima/config.php';
      $dbConfig = require __DIR__ . '/../../config/database.php';
      return require __DIR__ . '/../inventario-materia-prima/build_report.php';
    };
    $inventoryEntryReport = $loadInventoryEntries($inventoryQuery);
    $inventoryEntryRows = (array)($inventoryEntryReport['filas'] ?? []);
    $inventoryEntryCriteria = (array)($inventoryEntryReport['criterios'] ?? []);

    if ($selectedMaterial !== 'all') {
      $inventoryMaterialFamily = static function (array $row): string {
        $materialId = (int)($row['mat_id'] ?? 0);
        $materialName = (string)($row['material'] ?? '');
        if (in_array($materialId, [5, 7], true)) return 'Cuero Entero C/P (con pelo)';
        if (in_array($materialId, [9, 12], true)) return 'Cuero Entero Depilado';
        if (in_array($materialId, [3, 4, 10, 11, 13], true)) return $materialName;
        if ($materialId === 2) return 'Pedacera Americana S/P';
        if ($materialId === 6) return 'Pedacera Americana C/P';
        if ($materialId === 8) return 'Pedacera Americana Depilada';
        if ($materialId === 14) return 'Pedacera Nacional C/P';
        if ($materialId === 1) return 'Carnaza';
        return 'Otros';
      };
      $inventoryEntryRows = array_values(array_filter(
        $inventoryEntryRows,
        static fn(array $row): bool => $inventoryMaterialFamily($row) === $selectedMaterial
      ));
    }
    foreach ($inventoryEntryRows as $inventoryEntryIndex => &$inventoryEntryRow) {
      $inventoryEntryRow['numero'] = $inventoryEntryIndex + 1;
    }
    unset($inventoryEntryRow);
  } catch (Throwable $inventoryEntryException) {
    $inventoryEntryError = 'No fue posible cargar los resultados de entrada de materia prima.';
  } finally {
    $_GET = $originalInventoryQuery;
    date_default_timezone_set($timezone);
  }
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Rendimiento por Proceso'),
  'filtros' => [
    'periodo' => $periodMode,
    'anio' => $selectedYear,
    'mes' => $selectedMonth,
    'mes_nombre' => $monthNames[$selectedMonth] ?? (string)$selectedMonth,
    'semana' => $selectedWeekValue,
    'semana_inicio' => $selectedWeekValue,
    'semana_fin' => $selectedWeekEndValue,
    'fecha' => $selectedStartDate->format('Y-m-d'),
    'fecha_inicio' => $selectedStartDate->format('Y-m-d'),
    'fecha_fin' => $selectedEndDate->format('Y-m-d'),
    'hoy' => $operationalToday->format('Y-m-d'),
    'anios' => $yearOptions,
    'meses' => $monthNames,
    'material' => $selectedMaterial,
    'proveedor' => $selectedProvider,
  ],
  'opciones' => [
    'materiales' => $materialOptions,
    'proveedores' => $providerOptions,
  ],
  'kpis' => [
    'procesos' => count($processes),
    'costo_kg_mensual' => $monthlyCostPerKg,
    'costo_kg_semanal' => $weeklyCostPerKg,
    'kg_mp_filtrada' => $selectedMpKg,
    'kg_mp_rendimiento' => $totalGroupMp,
    'kg_producto_terminado' => $displayPt,
    'kg_producto_rendimiento' => $yieldPt,
    'rendimiento_pt' => $totalGroupMp > 0 ? round(($yieldPt / $totalGroupMp) * 100, 2) : null,
    'tarimas' => $displayTarimas,
    'bloom' => $displayBloom,
    'viscosidad' => $displayViscosidad,
    'riesgos_altos' => $riskHigh,
    'barredura_tarimas' => $hasParticipationFilter ? 0 : $barreduraTarimas,
    'barredura_kg' => $hasParticipationFilter ? 0 : $barreduraKg,
    'participacion_filtrada' => $hasParticipationFilter,
  ],
  'graficas' => [
    'materiales' => array_map(
      static fn(string $label, float $kg): array => ['label' => $label, 'kg' => $kg],
      array_keys($materialChart),
      array_values($materialChart)
    ),
    'proveedores' => $providerChartRows,
    'procesos' => $processChartRows,
  ],
  'filas' => $detailRows,
  'tabla_proveedor_material' => [
    'grupos' => $providerMaterialComparisonGroups,
    'error' => $providerMaterialComparisonError,
    'anio' => $providerMaterialYear,
    'mes' => $providerMaterialMonth,
    'mes_nombre' => $monthNames[$providerMaterialMonth] ?? (string)$providerMaterialMonth,
  ],
  'tabla_inventario_entrada' => [
    'filas' => $inventoryEntryRows,
    'criterios' => $inventoryEntryCriteria,
    'error' => $inventoryEntryError,
  ],
  'meta' => [
    'generado_en' => (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s'),
    'periodo_label' => $periodLabel,
    'periodo_inicio' => $start->format('Y-m-d'),
    'periodo_fin' => $end->format('Y-m-d'),
    'detalle_periodo_label' => $detailPeriodLabel,
    'hora_corte' => $horaCorte,
    'zona_horaria' => (string)($config['timezone_label'] ?? 'UTC-6'),
    'intervalo_actualizacion_ms' => (int)($config['intervalo_actualizacion_ms'] ?? 900000),
    'rendimiento_pt_todas_etiquetadas' => false,
    'rendimiento_pt_solo_procesos_cerrados' => true,
    'rendimiento_mp_solo_procesos_cerrados' => true,
    'rendimiento_incluye_barredura' => true,
  ],
  'version' => time(),
];
