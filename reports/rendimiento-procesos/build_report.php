<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'America/Mexico_City');
date_default_timezone_set($timezone);
$tz = new DateTimeZone($timezone);
$today = new DateTimeImmutable('today', $tz);

$parseDate = static function ($value, DateTimeImmutable $fallback, DateTimeZone $tz): DateTimeImmutable {
  $text = trim((string)$value);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
    return $fallback;
  }

  try {
    return new DateTimeImmutable($text . ' 00:00:00', $tz);
  } catch (Throwable $e) {
    return $fallback;
  }
};

$defaultStart = $parseDate(
  (string)($config['fecha_inicio_default'] ?? '2025-06-01'),
  $today->modify('-12 months'),
  $tz
);
$start = $parseDate($_GET['desde'] ?? null, $defaultStart, $tz);
$end = $parseDate($_GET['hasta'] ?? null, $today, $tz);
if ($end < $start) {
  [$start, $end] = [$end, $start];
}
$endExclusive = $end->modify('+1 day');

$selectedMaterial = trim((string)($_GET['material'] ?? 'all'));
$selectedProvider = filter_var($_GET['proveedor'] ?? null, FILTER_VALIDATE_INT, [
  'options' => ['min_range' => 1],
]);
$selectedProvider = $selectedProvider === false ? null : (int)$selectedProvider;

$pdo = conectar((array)($dbConfig[(string)($config['database_key'] ?? 'prod')] ?? $dbConfig['prod']));
$barreduraProId = 2;
$horaCorte = (string)($config['hora_corte'] ?? '07:00:00');
if (preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $horaCorte) !== 1) {
  $horaCorte = '07:00:00';
}
$operationDateSql = "DATE(CASE WHEN TIME(t.tar_fecha) < '{$horaCorte}' THEN DATE_SUB(t.tar_fecha, INTERVAL 1 DAY) ELSE t.tar_fecha END)";

$materialFamilySql = "CASE
  WHEN m.mat_id IN (5, 7, 9, 12) THEN 'Cuero Entero'
  WHEN m.mat_id IN (3, 4, 10, 11, 13) THEN 'Recorte'
  WHEN m.mat_id IN (2, 6, 8, 14) THEN 'Pedacera'
  WHEN m.mat_id = 1 THEN 'Carnaza'
  ELSE 'Otros'
END";

$pdo->exec('DROP TEMPORARY TABLE IF EXISTS tmp_rp_selected_pairs');
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
  pair_months AS (
    SELECT pro_id, pro_id_2, DATE_FORMAT(op_dia, '%Y-%m-01') periodo,
           COUNT(*) tarimas, SUM(tar_kilos) kilos
    FROM eligible_tarimas
    GROUP BY pro_id, pro_id_2, periodo
  ),
  ranked_pairs AS (
    SELECT pm.*,
           ROW_NUMBER() OVER (
             PARTITION BY pm.pro_id, pm.pro_id_2
             ORDER BY pm.tarimas DESC, pm.kilos DESC, pm.periodo ASC
           ) rn
    FROM pair_months pm
  )
  SELECT pro_id, pro_id_2
  FROM ranked_pairs
  WHERE rn = 1 AND periodo >= ? AND periodo < ?
");
$selectedPairsStmt->execute([$start->format('Y-m-d'), $endExclusive->format('Y-m-d')]);
$pdo->exec('ALTER TABLE tmp_rp_selected_pairs ADD PRIMARY KEY (pro_id, pro_id_2)');
$selectedPairRows = $pdo->query('SELECT pro_id, pro_id_2 FROM tmp_rp_selected_pairs')->fetchAll() ?: [];
$selectedPairsSql = $selectedPairRows === []
  ? 'SELECT NULL pro_id, NULL pro_id_2 WHERE 1 = 0'
  : implode(' UNION ALL ', array_map(
      static fn(array $pair): string => 'SELECT ' . (int)$pair['pro_id'] . ' pro_id, ' . (int)$pair['pro_id_2'] . ' pro_id_2',
      $selectedPairRows
    ));
$scopeIds = [];
foreach ($selectedPairRows as $selectedPair) {
  $scopeIds[(int)$selectedPair['pro_id']] = true;
  if ((int)$selectedPair['pro_id_2'] > 0) {
    $scopeIds[(int)$selectedPair['pro_id_2']] = true;
  }
}
$scopeProcessSql = $scopeIds === []
  ? 'SELECT NULL pro_id WHERE 1 = 0'
  : implode(' UNION ALL ', array_map(
      static fn(int $processId): string => 'SELECT ' . $processId . ' pro_id',
      array_keys($scopeIds)
    ));

$optionParams = [];
$materialOptionStmt = $pdo->prepare("
  SELECT DISTINCT {$materialFamilySql} AS material
  FROM procesos p
  INNER JOIN ({$scopeProcessSql}) alcance ON alcance.pro_id = p.pro_id
  INNER JOIN procesos_materiales pm ON pm.pro_id = p.pro_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  ORDER BY material
");
$materialOptionStmt->execute($optionParams);
$materialOptions = array_values(array_filter(array_map(
  static fn(array $row): string => (string)($row['material'] ?? ''),
  $materialOptionStmt->fetchAll() ?: []
)));
if ($selectedMaterial !== 'all' && !in_array($selectedMaterial, $materialOptions, true)) {
  $selectedMaterial = 'all';
}

$providerOptionSql = "
  SELECT DISTINCT prv.prv_id, prv.prv_nombre
  FROM procesos p
  INNER JOIN ({$scopeProcessSql}) alcance ON alcance.pro_id = p.pro_id
  INNER JOIN procesos_materiales pm ON pm.pro_id = p.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  INNER JOIN proveedores prv ON prv.prv_id = i.prv_id
  WHERE 1 = 1
";
$providerOptionParams = $optionParams;
if ($selectedMaterial !== 'all') {
  $providerOptionSql .= " AND {$materialFamilySql} = ?";
  $providerOptionParams[] = $selectedMaterial;
}
$providerOptionSql .= ' ORDER BY prv.prv_nombre';
$providerOptionStmt = $pdo->prepare($providerOptionSql);
$providerOptionStmt->execute($providerOptionParams);
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
         SUM(i.inv_kilos) kg_mp_total,
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
maq_ticket AS (
  SELECT inv_no_ticket, COUNT(*) parcialidades, SUM(inv_kilos) kg_enviados,
         SUM(inv_kg_totales) kg_recibidos
  FROM inventario
  WHERE prv_recibe = 126 AND inv_enviado = 2
  GROUP BY inv_no_ticket
),
maq_proceso_ticket AS (
  SELECT DISTINCT pm.pro_id, i.inv_no_ticket
  FROM procesos_materiales pm
  INNER JOIN scope s ON s.pro_id = pm.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  WHERE i.prv_recibe = 126
),
maq_proceso AS (
  SELECT mpt.pro_id, SUM(mt.kg_enviados) kg_enviados, SUM(mt.kg_recibidos) kg_recibidos,
         SUM(mt.kg_recibidos) / NULLIF(SUM(mt.kg_enviados), 0) rendimiento_maquila
  FROM maq_proceso_ticket mpt
  INNER JOIN maq_ticket mt ON mt.inv_no_ticket = mpt.inv_no_ticket
  GROUP BY mpt.pro_id
),
material_rows AS (
  SELECT s.pro_id, s.pt_id, s.pro_fe_carga, i.inv_no_ticket,
         {$materialFamilySql} material,
         i.prv_id, prv.prv_nombre proveedor,
         i.inv_kilos kg_mp,
         i.inv_humedad, i.inv_extrac, i.inv_solidos, i.inv_ph, i.inv_rendimiento, i.inv_riesgo
  FROM scope s
  INNER JOIN procesos_materiales pm ON pm.pro_id = s.pro_id
  INNER JOIN inventario i ON i.inv_id = pm.inv_id
  INNER JOIN materiales m ON m.mat_id = pm.mat_id
  INNER JOIN proveedores prv ON prv.prv_id = i.prv_id
)
SELECT
  mr.pro_id, mr.pro_fe_carga, mr.material, mr.prv_id, mr.proveedor,
  SUM(mr.kg_mp) kg_mp_filtrada,
  GROUP_CONCAT(DISTINCT mr.inv_no_ticket ORDER BY mr.inv_no_ticket SEPARATOR ', ') tickets,
  AVG(mr.inv_humedad) inv_humedad, AVG(mr.inv_extrac) inv_extractibilidad,
  AVG(mr.inv_solidos) inv_solidos, AVG(mr.inv_ph) inv_ph,
  AVG(mr.inv_rendimiento) inv_rendimiento,
  GROUP_CONCAT(DISTINCT mr.inv_riesgo ORDER BY mr.inv_riesgo SEPARATOR ', ') inv_riesgo,
  SUM(mr.inv_riesgo LIKE 'ALTO%') riesgo_alto,
  eq.ep_descripcion equipo_inicial,
  rp.grupo_pro_id, rp.tarimas, rp.grupo_kg_producto_terminado, rp.kg_mp_grupo,
  rp.kg_producto_proceso * (SUM(mr.kg_mp) / NULLIF(rp.kg_mp_proceso, 0)) kg_producto_terminado,
  rp.rendimiento_pt, rp.bloom_promedio, rp.viscosidad_promedio,
  el.extractibilidad extractibilidad_enzima_2b,
  enz.pfg2_enzima enzima_kg, enz.pfg2_hr_totales horas_enzima,
  CASE mr.pt_id WHEN 10 THEN a7p.pfg7_acido WHEN 11 THEN a7a.pfg7_acido WHEN 7 THEN a6.pfg6_acido END acido_litros,
  CASE mr.pt_id WHEN 10 THEN n7p.pfd7_norm WHEN 11 THEN n7a.pfd7_norm WHEN 7 THEN n6.pfd6_norm END acido_normalidad,
  CASE mr.pt_id WHEN 10 THEN cp.prol_cocido WHEN 11 THEN ca.prol_cocido WHEN 7 THEN cc.prol_cocido END cocimiento_ph,
  CASE mr.pt_id WHEN 10 THEN cp.prol_ce WHEN 11 THEN ca.prol_ce WHEN 7 THEN cc.prol_ce END cocimiento_ce,
  CASE mr.pt_id WHEN 10 THEN epf.prol_por_extrac WHEN 11 THEN eaf.prol_por_extrac WHEN 7 THEN lb7.prol_por_extrac END extractibilidad_final,
  mp.rendimiento_maquila
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
LEFT JOIN maq_proceso mp ON mp.pro_id = mr.pro_id
{$detailWhereSql}
GROUP BY mr.pro_id, mr.pro_fe_carga, mr.material, mr.prv_id, mr.proveedor,
         eq.ep_descripcion, rp.grupo_pro_id, rp.tarimas, rp.grupo_kg_producto_terminado,
         rp.kg_mp_grupo, rp.kg_producto_proceso, rp.kg_mp_proceso,
         rp.rendimiento_pt, rp.bloom_promedio, rp.viscosidad_promedio,
         el.extractibilidad, enz.pfg2_enzima, enz.pfg2_hr_totales,
         acido_litros, acido_normalidad, cocimiento_ph, cocimiento_ce,
         extractibilidad_final, mp.rendimiento_maquila
ORDER BY mr.pro_id DESC, mr.material, mr.proveedor
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

foreach ($rows as &$row) {
  $processId = (int)$row['pro_id'];
  $groupId = (int)($row['grupo_pro_id'] ?? 0);
  $row['pro_id'] = $processId;
  $row['prv_id'] = (int)$row['prv_id'];
  $row['tickets'] = trim((string)($row['tickets'] ?? ''));
  $row['riesgo_alto'] = (int)$row['riesgo_alto'];
  foreach ([
    'kg_mp_filtrada', 'inv_humedad', 'inv_extractibilidad', 'inv_solidos', 'inv_ph',
    'inv_rendimiento', 'kg_producto_terminado', 'grupo_kg_producto_terminado', 'kg_mp_grupo', 'rendimiento_pt',
    'bloom_promedio', 'viscosidad_promedio', 'extractibilidad_enzima_2b', 'enzima_kg',
    'horas_enzima', 'acido_litros', 'acido_normalidad', 'cocimiento_ph', 'cocimiento_ce',
    'extractibilidad_final', 'rendimiento_maquila',
  ] as $field) {
    $row[$field] = $row[$field] === null ? null : (float)$row[$field];
  }

  $processes[$processId] = true;
  $selectedMpKg += (float)$row['kg_mp_filtrada'];
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
  $materialChart[$material] = ($materialChart[$material] ?? 0) + (float)$row['kg_mp_filtrada'];

  $providerId = (int)$row['prv_id'];
  if (!isset($providerChart[$providerId])) {
    $providerChart[$providerId] = [
      'label' => (string)$row['proveedor'],
      'kg' => 0.0,
      'kg_pt' => 0.0,
    ];
  }
  $providerChart[$providerId]['kg'] += (float)$row['kg_mp_filtrada'];
  $providerChart[$providerId]['kg_pt'] += (float)($row['kg_producto_terminado'] ?? 0);
}
unset($row);

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
  $totalGroupMp = $selectedMpKg;
}

$barreduraTarimas = (int)($barredura['tarimas'] ?? 0);
$barreduraKg = (float)($barredura['kg_producto_terminado'] ?? 0);
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

return [
  'titulo' => (string)($config['titulo'] ?? 'Rendimiento por Proceso'),
  'filtros' => [
    'desde' => $start->format('Y-m-d'),
    'hasta' => $end->format('Y-m-d'),
    'material' => $selectedMaterial,
    'proveedor' => $selectedProvider,
  ],
  'opciones' => [
    'materiales' => $materialOptions,
    'proveedores' => $providerOptions,
  ],
  'kpis' => [
    'procesos' => count($processes),
    'kg_mp_filtrada' => $selectedMpKg,
    'kg_producto_terminado' => $displayPt,
    'kg_producto_rendimiento' => $totalPt,
    'rendimiento_pt' => $totalGroupMp > 0 ? ($totalPt / $totalGroupMp) * 100 : null,
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
  'filas' => $rows,
  'meta' => [
    'generado_en' => (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i:s'),
    'hora_corte' => $horaCorte,
    'zona_horaria' => (string)($config['timezone_label'] ?? 'UTC-6'),
    'intervalo_actualizacion_ms' => (int)($config['intervalo_actualizacion_ms'] ?? 900000),
  ],
  'version' => time(),
];
