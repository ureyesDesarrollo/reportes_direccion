<?php

/** @var int $anioAnterior */
/** @var int $anioActual */
/** @var float|null $ratioBase */
/** @var float|null $ratioPromedioAnioActual */
/** @var float|null $variacionRatio */
/** @var float $totalQuimicosAnioAnterior */
/** @var float $totalQuimicosAnioActual */
/** @var float|null $variacionQuimicos */
?>
<div class="kpi-grid">
  <?php
  // KPI 1 — Total consumo año anterior (Base)
  $kpi1Label = 'Total consumo ' . $anioAnterior . ' (Base)';
  $kpi1Value = n($totalQuimicosAnioAnterior, 0);
  $kpi1Trend = 'vs ' . $anioActual . ': ' . n($totalQuimicosAnioActual, 0);
  ?>
  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas fa-wrench" style="color: #10b981;"></i>
    </div>
    <div class="kpi-label"><?= htmlspecialchars($kpi1Label) ?></div>
    <div class="kpi-value"><?= htmlspecialchars($kpi1Value) ?></div>
    <div class="kpi-trend"><?= htmlspecialchars($kpi1Trend) ?></div>
  </div>

  <?php
  // KPI 2 — Promedio semanal año anterior
  $numSemanasAnterior = count(array_filter(
    $datosAnioAnterior ?? [],
    static fn($r) => (float)$r['quimicos'] > 0
  ));
  $promedioSemanalAnterior = $numSemanasAnterior > 0
    ? $totalQuimicosAnioAnterior / $numSemanasAnterior
    : null;

  $kpi2Label = 'Promedio semanal ' . $anioAnterior . ' (Base)';
  $kpi2Value = $promedioSemanalAnterior !== null ? n($promedioSemanalAnterior, 1) : '-';
  $kpi2Trend = $ratioPromedioAnioActual !== null
    ? 'vs ' . $anioActual . ': ' . n($ratioPromedioAnioActual, 1)
    : '-';
  ?>
  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas fa-chart-pie" style="color: #8b5cf6;"></i>
    </div>
    <div class="kpi-label"><?= htmlspecialchars($kpi2Label) ?></div>
    <div class="kpi-value"><?= htmlspecialchars($kpi2Value) ?></div>
    <div class="kpi-trend"><?= htmlspecialchars($kpi2Trend) ?></div>
  </div>

  <?php
  // KPI 3 — Total consumo año actual
  $kpi3Label = 'Total consumo ' . $anioActual;
  $kpi3Value = n($totalQuimicosAnioActual, 0);
  $kpi3Trend = 'Base ' . $anioAnterior . ': ' . n($totalQuimicosAnioAnterior, 0);
  ?>
  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas fa-toolbox" style="color: #3b82f6;"></i>
    </div>
    <div class="kpi-label"><?= htmlspecialchars($kpi3Label) ?></div>
    <div class="kpi-value"><?= htmlspecialchars($kpi3Value) ?></div>
    <div class="kpi-trend"><?= htmlspecialchars($kpi3Trend) ?></div>
  </div>

  <?php
  // KPI 4 — Variación vs Base
  $varVal   = $variacionQuimicos ?? null;
  $varClase = ($varVal ?? 0) < 0
    ? 'trend-down'
    : (($varVal ?? 0) > 0 ? 'trend-up' : '');
  $varTexto = $varVal !== null
    ? (($varVal > 0 ? '+' : '') . n($varVal, 2) . '%')
    : '-';
  $varEstado = ($varVal ?? 0) < 0
    ? 'Mejora'
    : (($varVal ?? 0) > 0 ? 'Deterioro' : 'Estable');
  $varIcono = ($varVal ?? 0) < 0
    ? 'down'
    : (($varVal ?? 0) > 0 ? 'up' : 'right');
  ?>
  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas fa-chart-line" style="color: #f59e0b;"></i>
    </div>
    <div class="kpi-label">Variación vs Base</div>
    <div class="kpi-value <?= htmlspecialchars($varClase) ?>">
      <?= htmlspecialchars($varTexto) ?>
    </div>
    <div class="kpi-trend">
      <i class="fas fa-arrow-<?= htmlspecialchars($varIcono) ?>"></i>
      <?= htmlspecialchars($varEstado) ?>
    </div>
  </div>
</div>
