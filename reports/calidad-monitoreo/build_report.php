<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

$timezone = new DateTimeZone((string)($config['timezone'] ?? 'Etc/GMT+6'));
$now = new DateTimeImmutable('now', $timezone);
$cutoffHour = max(0, min(23, (int)($config['hora_corte'] ?? 7)));
$todayCutoff = $now->setTime($cutoffHour, 0, 0);
$currentPeriodStart = $now < $todayCutoff ? $todayCutoff->modify('-1 day') : $todayCutoff;
$periodEnd = $currentPeriodStart;
$periodStart = $periodEnd->modify('-1 day');
$warnings = [];
$pallets = [];
$mixes = [];
$completedMixes = [];
$shipments = [];
$parameterRanges = [];
$qualityRanges = [];
$qualityColors = (array)($config['colores_calidad'] ?? []);
$qualityRangesAvailable = false;

$normalizeQuality = static function (?int $qualityId, string $description): string {
  if (in_array($qualityId, [2, 4], true)) return 'Dorada';
  if (in_array($qualityId, [1, 6], true)) return 'Verde';

  $normalized = mb_strtolower(trim($description), 'UTF-8');
  if ($normalized === 'azul') return 'Azul';
  if ($normalized === 'dorada' || $normalized === '280') return 'Dorada';
  if ($normalized === 'verde' || $normalized === '250') return 'Verde';
  if ($normalized === 'morada') return 'Morada';

  return 'Otros';
};

$resolveQuality = static function (?float $bloom, ?float $viscosity) use (&$qualityRanges, &$qualityRangesAvailable, $qualityColors, $normalizeQuality): array {
  $pending = $bloom === null || $bloom <= 0 || $viscosity === null || $viscosity <= 0;
  if ($pending || !$qualityRangesAvailable) {
    return [
      'calidad' => 'Pendiente',
      'calidad_color' => (string)($qualityColors['Por Definir'] ?? '#9ca3af'),
      'calidad_calculada' => false,
    ];
  }

  foreach ($qualityRanges as $range) {
    if ($bloom < $range['bloom_inicio'] || $bloom > $range['bloom_fin']) continue;
    if ($viscosity < $range['viscosidad_inicio'] || $viscosity > $range['viscosidad_fin']) continue;

    $quality = $normalizeQuality($range['cal_id'], $range['descripcion']);
    return [
      'calidad' => $quality,
      'calidad_color' => (string)($qualityColors[$quality] ?? $qualityColors['Otros'] ?? '#64748b'),
      'calidad_calculada' => true,
    ];
  }

  return [
    'calidad' => 'Sin Calidad',
    'calidad_color' => (string)($qualityColors['Sin Calidad'] ?? '#ef4444'),
    'calidad_calculada' => true,
  ];
};

try {
  $pdo = conectar((array)($config['database'] ?? []));
  try {
    $rangeStatement = $pdo->query("
      SELECT rp_campo, rp_inicio, rp_fin
      FROM rev_parametros
      WHERE rp_tipo = 'F'
        AND rp_campo IS NOT NULL
        AND TRIM(rp_campo) <> ''
    ");
    foreach ($rangeStatement->fetchAll() ?: [] as $rangeRow) {
      $field = trim((string)($rangeRow['rp_campo'] ?? ''));
      if ($field === '' || !is_numeric($rangeRow['rp_inicio'] ?? null) || !is_numeric($rangeRow['rp_fin'] ?? null)) continue;
      $parameterRanges[$field] = [
        'inicio' => (float)$rangeRow['rp_inicio'],
        'fin' => (float)$rangeRow['rp_fin'],
      ];
    }
  } catch (Throwable $exception) {
    $warnings[] = 'No fue posible consultar los límites de rev_parametros.';
  }

  try {
    $qualityRangeStatement = $pdo->query("
      SELECT
        r.blo_ini,
        r.blo_fin,
        r.vis_ini,
        r.vis_fin,
        r.cal_id,
        c.cal_descripcion
      FROM rev_calidad_rango r
      LEFT JOIN rev_calidad c ON c.cal_id = r.cal_id
      ORDER BY r.cr_id ASC
    ");
    foreach ($qualityRangeStatement->fetchAll() ?: [] as $rangeRow) {
      if (!is_numeric($rangeRow['blo_ini'] ?? null) || !is_numeric($rangeRow['blo_fin'] ?? null)) continue;
      if (!is_numeric($rangeRow['vis_ini'] ?? null) || !is_numeric($rangeRow['vis_fin'] ?? null)) continue;
      $qualityRanges[] = [
        'bloom_inicio' => (float)$rangeRow['blo_ini'],
        'bloom_fin' => (float)$rangeRow['blo_fin'],
        'viscosidad_inicio' => (float)$rangeRow['vis_ini'],
        'viscosidad_fin' => (float)$rangeRow['vis_fin'],
        'cal_id' => is_numeric($rangeRow['cal_id'] ?? null) ? (int)$rangeRow['cal_id'] : null,
        'descripcion' => trim((string)($rangeRow['cal_descripcion'] ?? '')),
      ];
    }
    $qualityRangesAvailable = $qualityRanges !== [];
  } catch (Throwable $exception) {
    $warnings[] = 'No fue posible consultar los rangos de calidad por Bloom y viscosidad.';
  }

  $statement = $pdo->prepare("
    SELECT
      t.tar_id,
      t.pro_id,
      t.pro_id_2,
      t.tar_folio,
      t.tar_fecha,
      t.tar_bloom,
      t.tar_viscosidad,
      t.tar_fino,
      t.tar_malla_30,
      t.tar_malla_45,
      t.tar_trans,
      t.tar_porcentaje_t,
      t.tar_color,
      t.tar_olor,
      t.tar_redox,
      t.tar_ph,
      t.tar_ce,
      t.tar_humedad,
      t.tar_cenizas,
      t.cal_id,
      c.cal_descripcion
    FROM rev_tarimas t
    LEFT JOIN rev_calidad c ON c.cal_id = t.cal_id
    WHERE t.tar_fecha >= ?
      AND t.tar_fecha < ?
      AND COALESCE(t.tar_count_etiquetado, '0') > '0'
    ORDER BY t.tar_fecha DESC, t.tar_id DESC
  ");
  $statement->execute([
    $periodStart->format('Y-m-d H:i:s'),
    $periodEnd->format('Y-m-d H:i:s'),
  ]);

  $parameterFields = [
    'bloom' => 'tar_bloom',
    'viscosidad' => 'tar_viscosidad',
    'malla_30' => 'tar_malla_30',
    'malla_45' => 'tar_malla_45',
    'transparencia' => 'tar_trans',
    'transmitancia' => 'tar_por_t',
    'color' => 'tar_color',
    'olor' => 'tar_olor',
    'redox' => 'tar_redox',
    'ph' => 'tar_ph',
    'conductividad' => 'tar_ce',
    'humedad' => 'tar_humedad',
    'cenizas' => 'tar_cenizas',
  ];

  foreach ($statement->fetchAll() ?: [] as $row) {
    $pallet = [
      'id' => (int)($row['tar_id'] ?? 0),
      'pro_id' => is_numeric($row['pro_id'] ?? null) ? (int)$row['pro_id'] : null,
      'pro_id_2' => is_numeric($row['pro_id_2'] ?? null) && (int)$row['pro_id_2'] > 0 ? (int)$row['pro_id_2'] : null,
      'folio' => trim((string)($row['tar_folio'] ?? '')) ?: 'Sin folio',
      'fecha' => (string)($row['tar_fecha'] ?? ''),
      'hora' => ($date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)($row['tar_fecha'] ?? ''), $timezone)) instanceof DateTimeImmutable
        ? $date->format('H:i')
        : '—',
      'bloom' => is_numeric($row['tar_bloom'] ?? null) ? (float)$row['tar_bloom'] : null,
      'viscosidad' => is_numeric($row['tar_viscosidad'] ?? null) ? (float)$row['tar_viscosidad'] : null,
      'fino' => strtoupper(trim((string)($row['tar_fino'] ?? ''))),
      'malla_30' => is_numeric($row['tar_malla_30'] ?? null) ? (float)$row['tar_malla_30'] : null,
      'malla_45' => is_numeric($row['tar_malla_45'] ?? null) ? (float)$row['tar_malla_45'] : null,
      'transparencia' => is_numeric($row['tar_trans'] ?? null) ? (float)$row['tar_trans'] : null,
      'transmitancia' => is_numeric($row['tar_porcentaje_t'] ?? null) ? (float)$row['tar_porcentaje_t'] : null,
      'color' => is_numeric($row['tar_color'] ?? null) ? (float)$row['tar_color'] : null,
      'olor' => is_numeric($row['tar_olor'] ?? null) ? (float)$row['tar_olor'] : null,
      'redox' => is_numeric($row['tar_redox'] ?? null) ? (float)$row['tar_redox'] : null,
      'ph' => is_numeric($row['tar_ph'] ?? null) ? (float)$row['tar_ph'] : null,
      'conductividad' => is_numeric($row['tar_ce'] ?? null) ? (float)$row['tar_ce'] : null,
      'humedad' => is_numeric($row['tar_humedad'] ?? null) ? (float)$row['tar_humedad'] : null,
      'cenizas' => is_numeric($row['tar_cenizas'] ?? null) ? (float)$row['tar_cenizas'] : null,
      'calidad_registrada' => trim((string)($row['cal_descripcion'] ?? '')) ?: null,
    ];
    $pallet = array_replace($pallet, $resolveQuality($pallet['bloom'], $pallet['viscosidad']));
    $pallet['fuera_parametro'] = [];
    foreach ($parameterFields as $key => $field) {
      $value = $pallet[$key] ?? null;
      $range = $parameterRanges[$field] ?? null;
      if (in_array($key, ['malla_30', 'malla_45'], true) && $pallet['fino'] !== 'N') continue;
      if ($key === 'bloom' && is_numeric($value) && (float)$value <= 0) continue;
      if ($value !== null && $range !== null && ($value < $range['inicio'] || $value > $range['fin'])) {
        $pallet['fuera_parametro'][] = $key;
      }
    }
    $pallets[] = $pallet;
  }

  try {
    $mixStatement = $pdo->query("
      SELECT
        r.rev_id,
        r.rev_folio,
        r.rev_bloom,
        r.rev_viscosidad,
        r.rev_malla_30,
        r.rev_malla_45,
        r.rev_trans,
        r.rev_porcentaje_t,
        r.rev_color,
        r.rev_olor,
        r.rev_redox,
        r.rev_ph,
        r.rev_ce,
        r.rev_humedad,
        r.rev_cenizas
      FROM rev_revolturas r
      WHERE r.rev_estatus = 2
        AND COALESCE(r.rev_count_etiquetado, '0') > '0'
        AND (
          r.rev_bloom IS NULL
          OR r.rev_bloom = 0
          OR r.cal_id IS NULL
          OR r.cal_id = 0
        )
      ORDER BY r.rev_fecha_procesamiento DESC, r.rev_id DESC
    ");
    foreach ($mixStatement->fetchAll() ?: [] as $row) {
      $mix = [
        'id' => (int)($row['rev_id'] ?? 0),
        'folio' => trim((string)($row['rev_folio'] ?? '')) ?: 'Sin folio',
        'bloom' => is_numeric($row['rev_bloom'] ?? null) ? (float)$row['rev_bloom'] : null,
        'viscosidad' => is_numeric($row['rev_viscosidad'] ?? null) ? (float)$row['rev_viscosidad'] : null,
        'malla_30' => is_numeric($row['rev_malla_30'] ?? null) ? (float)$row['rev_malla_30'] : null,
        'malla_45' => is_numeric($row['rev_malla_45'] ?? null) ? (float)$row['rev_malla_45'] : null,
        'transparencia' => is_numeric($row['rev_trans'] ?? null) ? (float)$row['rev_trans'] : null,
        'transmitancia' => is_numeric($row['rev_porcentaje_t'] ?? null) ? (float)$row['rev_porcentaje_t'] : null,
        'color' => is_numeric($row['rev_color'] ?? null) ? (float)$row['rev_color'] : null,
        'olor' => is_numeric($row['rev_olor'] ?? null) ? (float)$row['rev_olor'] : null,
        'redox' => is_numeric($row['rev_redox'] ?? null) ? (float)$row['rev_redox'] : null,
        'ph' => is_numeric($row['rev_ph'] ?? null) ? (float)$row['rev_ph'] : null,
        'conductividad' => is_numeric($row['rev_ce'] ?? null) ? (float)$row['rev_ce'] : null,
        'humedad' => is_numeric($row['rev_humedad'] ?? null) ? (float)$row['rev_humedad'] : null,
        'cenizas' => is_numeric($row['rev_cenizas'] ?? null) ? (float)$row['rev_cenizas'] : null,
      ];
      $mix = array_replace($mix, $resolveQuality($mix['bloom'], $mix['viscosidad']));
      $mix['fuera_parametro'] = [];
      foreach ($parameterFields as $key => $field) {
        $value = $mix[$key] ?? null;
        $range = $parameterRanges[$field] ?? null;
        if ($key === 'bloom' && is_numeric($value) && (float)$value <= 0) continue;
        if ($value !== null && $range !== null && ($value < $range['inicio'] || $value > $range['fin'])) {
          $mix['fuera_parametro'][] = $key;
        }
      }
      $mixes[] = $mix;
    }
  } catch (Throwable $exception) {
    $warnings[] = 'No fue posible consultar las revolturas en proceso de análisis.';
  }

  try {
    $completedStatement = $pdo->query("
      SELECT
        r.rev_id,
        r.rev_folio,
        r.rev_bloom,
        r.rev_viscosidad,
        r.rev_malla_30,
        r.rev_malla_45,
        r.rev_trans,
        r.rev_porcentaje_t,
        r.rev_color,
        r.rev_olor,
        r.rev_redox,
        r.rev_ph,
        r.rev_ce,
        r.rev_humedad,
        r.rev_cenizas
      FROM rev_revolturas r
      WHERE r.rev_estatus = 2
        AND COALESCE(r.rev_count_etiquetado, '0') > '0'
        AND r.rev_bloom IS NOT NULL
        AND r.rev_bloom > 0
        AND r.cal_id IS NOT NULL
        AND r.cal_id > 0
      ORDER BY r.rev_fecha DESC, r.rev_id DESC
    ");
    foreach ($completedStatement->fetchAll() ?: [] as $row) {
      $completedMix = [
        'id' => (int)($row['rev_id'] ?? 0),
        'folio' => trim((string)($row['rev_folio'] ?? '')) ?: 'Sin folio',
        'bloom' => is_numeric($row['rev_bloom'] ?? null) ? (float)$row['rev_bloom'] : null,
        'viscosidad' => is_numeric($row['rev_viscosidad'] ?? null) ? (float)$row['rev_viscosidad'] : null,
        'malla_30' => is_numeric($row['rev_malla_30'] ?? null) ? (float)$row['rev_malla_30'] : null,
        'malla_45' => is_numeric($row['rev_malla_45'] ?? null) ? (float)$row['rev_malla_45'] : null,
        'transparencia' => is_numeric($row['rev_trans'] ?? null) ? (float)$row['rev_trans'] : null,
        'transmitancia' => is_numeric($row['rev_porcentaje_t'] ?? null) ? (float)$row['rev_porcentaje_t'] : null,
        'color' => is_numeric($row['rev_color'] ?? null) ? (float)$row['rev_color'] : null,
        'olor' => is_numeric($row['rev_olor'] ?? null) ? (float)$row['rev_olor'] : null,
        'redox' => is_numeric($row['rev_redox'] ?? null) ? (float)$row['rev_redox'] : null,
        'ph' => is_numeric($row['rev_ph'] ?? null) ? (float)$row['rev_ph'] : null,
        'conductividad' => is_numeric($row['rev_ce'] ?? null) ? (float)$row['rev_ce'] : null,
        'humedad' => is_numeric($row['rev_humedad'] ?? null) ? (float)$row['rev_humedad'] : null,
        'cenizas' => is_numeric($row['rev_cenizas'] ?? null) ? (float)$row['rev_cenizas'] : null,
      ];
      $completedMix = array_replace($completedMix, $resolveQuality($completedMix['bloom'], $completedMix['viscosidad']));
      $completedMix['fuera_parametro'] = [];
      foreach ($parameterFields as $key => $field) {
        $value = $completedMix[$key] ?? null;
        $range = $parameterRanges[$field] ?? null;
        if ($key === 'bloom' && is_numeric($value) && (float)$value <= 0) continue;
        if ($value !== null && $range !== null && ($value < $range['inicio'] || $value > $range['fin'])) {
          $completedMix['fuera_parametro'][] = $key;
        }
      }
      $completedMixes[] = $completedMix;
    }
  } catch (Throwable $exception) {
    $warnings[] = 'No fue posible consultar las revolturas terminadas.';
  }

  try {
    $shipmentStart = $now->setTime(0, 0, 0);
    $shipmentEnd = $shipmentStart->modify('+1 day');
    $shipmentStatement = $pdo->prepare("
      SELECT
        o.oe_id,
        o.oe_estado,
        o.tarimas_liberadas,
        COALESCE(NULLIF(TRIM(c.cte_nombre), ''), NULLIF(TRIM(c.cte_razon_social), ''), CONCAT('Cliente ', o.cte_id)) AS cliente
      FROM rev_orden_embarque o
      LEFT JOIN rev_clientes c ON c.cte_id = o.cte_id
      WHERE o.oe_fecha >= ?
        AND o.oe_fecha < ?
      ORDER BY o.oe_fecha DESC, o.oe_id DESC
    ");
    $shipmentStatement->execute([
      $shipmentStart->format('Y-m-d H:i:s'),
      $shipmentEnd->format('Y-m-d H:i:s'),
    ]);
    foreach ($shipmentStatement->fetchAll() ?: [] as $row) {
      $shipments[] = [
        'id' => (int)($row['oe_id'] ?? 0),
        'cliente' => trim((string)($row['cliente'] ?? '')) ?: 'Sin cliente',
        'estado' => trim((string)($row['oe_estado'] ?? '')) ?: '—',
        'tarimas_liberadas' => is_numeric($row['tarimas_liberadas'] ?? null) ? (int)$row['tarimas_liberadas'] : null,
      ];
    }
  } catch (Throwable $exception) {
    $warnings[] = 'No fue posible consultar los embarques del día.';
  }
} catch (Throwable $exception) {
  $warnings[] = 'No fue posible consultar las tarimas del servidor 105.';
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Calidad Monitoreo'),
  'periodo' => [
    'inicio' => $periodStart->format('Y-m-d H:i:s'),
    'fin' => $periodEnd->format('Y-m-d H:i:s'),
    'label' => $periodStart->format('d/m/Y H:i') . ' – ' . $periodEnd->format('d/m/Y H:i'),
    'tipo' => 'anterior',
  ],
  'tarimas' => $pallets,
  'revolturas' => $mixes,
  'revolturas_terminadas' => $completedMixes,
  'embarques' => $shipments,
  'resumen' => [
    'tarimas' => count($pallets),
  ],
  'meta' => [
    'actualizado' => $now->format('d/m/Y H:i:s'),
    'intervalo_actualizacion_ms' => (int)($config['intervalo_actualizacion_ms'] ?? 120000),
    'warnings' => $warnings,
    'fuente' => '105 · bd_sis_preparacion',
  ],
];
