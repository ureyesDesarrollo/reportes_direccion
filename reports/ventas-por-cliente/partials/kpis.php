<?php

/** @var int $anio_anterior */
/** @var int $anio_actual */
/** @var string $mes_corte_label */
/** @var int $total_compras_anterior */
/** @var int $total_compras_actual */
/** @var int $total_compras_anio_anterior */
/** @var int $total_compras_anio_actual */
/** @var float|null $variacion_total_compras */
/** @var float|null $variacion_total_compras_anio */
/** @var float $total_ventas_actual */
/** @var float $total_ventas_anio_anterior */
/** @var float $total_ventas_anio_actual */
/** @var float $total_kg_anio_anterior */
/** @var float $total_kg_anio_actual */
/** @var int $cantidad_clientes_anterior */
/** @var int $cantidad_clientes_actual */
/** @var float|null $variacion_clientes_activos */
/** @var int $clientes_para_proxima_venta */
/** @var int $ventana_proxima_venta_dias */
/** @var array $clientes_por_estado */
/** @var float $promedio_compras_anterior */
/** @var float $promedio_compras_actual */

$formatPct = static function (?float $value): string {
  if ($value === null) {
    return '-';
  }

  return ($value > 0 ? '+' : '') . n($value, 2) . '%';
};

$calcVariation = static function ($actual, $anterior): ?float {
  if ((float)$anterior === 0.0) {
    return null;
  }

  return (((float)$actual - (float)$anterior) / abs((float)$anterior)) * 100;
};

$variacionKgAnual = $calcVariation($total_kg_anio_actual ?? 0, $total_kg_anio_anterior ?? 0);
$variacionVentasAnual = $calcVariation($total_ventas_anio_actual ?? 0, $total_ventas_anio_anterior ?? 0);

$trendIcon = static function (?float $value): string {
  if ($value === null || $value == 0.0) {
    return 'fa-minus';
  }

  return $value < 0 ? 'fa-arrow-down' : 'fa-arrow-up';
};

$trendColor = static function (?float $value): string {
  if ($value === null || $value == 0.0) {
    return '#94a3b8';
  }

  return $value < 0 ? '#ef4444' : '#10b981';
};
?>
<div class="kpi-grid">
  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="acumulado" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas fa-weight-hanging" style="color: #f59e0b;"></i>
    </div>
    <div class="kpi-label">KG vendidos <?= htmlspecialchars((string)$anio_actual) ?></div>
    <div class="kpi-value"><?= n((float)($total_kg_anio_actual ?? 0), 0) ?></div>
    <div class="kpi-trend">
      <?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)($total_kg_anio_anterior ?? 0), 0) ?> kg | Var: <?= htmlspecialchars($formatPct($variacionKgAnual)) ?>
    </div>
  </div>

  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="variacion-baja" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas <?= htmlspecialchars($trendIcon($variacion_total_compras)) ?>" style="color: <?= htmlspecialchars($trendColor($variacion_total_compras)) ?>;"></i>
    </div>
    <div class="kpi-label">Variacion de frecuencia de venta</div>
    <div class="kpi-value" style="color: <?= htmlspecialchars($trendColor($variacion_total_compras)) ?>;"><?= htmlspecialchars($formatPct($variacion_total_compras)) ?></div>
    <div class="kpi-trend">Facturas no canceladas + remisiones, mismo periodo acumulado</div>
  </div>

  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="con-venta" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas fa-users" style="color: #8b5cf6;"></i>
    </div>
    <div class="kpi-label">Clientes con venta</div>
    <div class="kpi-value"><?= n($cantidad_clientes_actual, 0) ?></div>
    <div class="kpi-trend">
      Base: <?= n($cantidad_clientes_anterior, 0) ?> | Variacion: <?= htmlspecialchars($formatPct($variacion_clientes_activos)) ?>
    </div>
  </div>

  <div
    class="kpi-card ventas-kpi-filter"
    data-kpi-filter="proxima"
    role="button"
    tabindex="0"
    aria-pressed="false"
    onclick="event.preventDefault(); event.stopImmediatePropagation(); if (window.ventasClientesSetKpiFilter) { window.ventasClientesSetKpiFilter('proxima'); } return false;">
    <div class="kpi-icon">
      <i class="fas fa-calendar-day" style="color: #f59e0b;"></i>
    </div>
    <div class="kpi-label">Clientes para próxima venta</div>
    <div class="kpi-value"><?= n($clientes_para_proxima_venta ?? 0, 0) ?></div>
    <div class="kpi-trend">
      Estimados por intervalo promedio | Ventana: <?= n($ventana_proxima_venta_dias ?? 30, 0) ?> días
    </div>
  </div>

  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="anual" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas fa-dollar-sign" style="color: #3b82f6;"></i>
    </div>
    <div class="kpi-label">Total anual vendido</div>
    <div class="kpi-value">$<?= n((float)($total_ventas_anio_actual ?? 0), 0) ?></div>
    <div class="kpi-trend">
      <?= htmlspecialchars((string)$anio_anterior) ?>: $<?= n((float)($total_ventas_anio_anterior ?? 0), 0) ?> | Var: <?= htmlspecialchars($formatPct($variacionVentasAnual)) ?>
    </div>
  </div>

  <?php
  $clientesCrecimiento = $clientes_por_estado['Crecimiento'] ?? 0;
  $clientesEstables = $clientes_por_estado['Estable'] ?? 0;
  $clientesAtencion = $clientes_por_estado['Atencion'] ?? 0;
  $clientesDeclive = $clientes_por_estado['Declive'] ?? 0;
  $clientesInactivos = $clientes_por_estado['Inactivo'] ?? 0;
  $clientesNuevos = $clientes_por_estado['Nuevo'] ?? 0;
  ?>
  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="sanos" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas fa-arrow-trend-up" style="color: #10b981;"></i>
    </div>
    <div class="kpi-label">Clientes sanos</div>
    <div class="kpi-value"><?= n($clientesCrecimiento + $clientesEstables, 0) ?></div>
    <div class="kpi-trend">
      Crecimiento: <?= n($clientesCrecimiento, 0) ?> | Estables: <?= n($clientesEstables, 0) ?> | Nuevos: <?= n($clientesNuevos, 0) ?>
    </div>
  </div>

  <div class="kpi-card ventas-kpi-filter" data-kpi-filter="riesgo" role="button" tabindex="0" aria-pressed="false">
    <div class="kpi-icon">
      <i class="fas fa-triangle-exclamation" style="color: #ef4444;"></i>
    </div>
    <div class="kpi-label">Clientes en riesgo</div>
    <div class="kpi-value"><?= n($clientesAtencion + $clientesDeclive + $clientesInactivos, 0) ?></div>
    <div class="kpi-trend">
      Atencion: <?= n($clientesAtencion, 0) ?> | Declive: <?= n($clientesDeclive, 0) ?> | Inactivos: <?= n($clientesInactivos, 0) ?>
    </div>
  </div>
</div>
