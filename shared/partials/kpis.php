<?php

/** @var int $anioAnterior */
/** @var int $anioActual */
/** @var float|null $ratioBase */
/** @var float|null $ratioPromedioAnioActual */
/** @var float|null $variacionRatio */
/** @var float $totalQuimicosAnioAnterior */
/** @var float $totalProduccionAnioAnterior */
/** @var float $totalQuimicosAnioActual */
/** @var float $totalProduccionAnioActual */
/** @var array $meta */

$modo = $meta['modo'] ?? 'consumo';
$metricaTitulo = $meta['metricaTitulo'] ?? (
  $modo === 'costo'
  ? 'Costo Promedio Grupo'
  : ($modo === 'impacto' ? 'Impacto Total Grupo' : 'Consumo Grupo')
);
$metricaUnidad = $meta['metricaUnidad'] ?? ($modo === 'consumo' ? 'kg' : '$');

$formatMetrica = static function (?float $valor) use ($modo, $metricaUnidad): string {
  if ($valor === null) {
    return '-';
  }

  return n($valor, 2) . ($modo === 'consumo' ? ' ' . $metricaUnidad : '$');
};

$kpi1Label = 'Total ' . $metricaTitulo . ' ' . $anioAnterior . ' (Base)';
$kpi1Value = $formatMetrica($totalQuimicosAnioAnterior);
$kpi1Trend = 'vs ' . $anioActual . ': ' . $formatMetrica($totalQuimicosAnioActual);

$kpi2Label = 'Total Producción ' . $anioAnterior . ' (Base)';
$kpi2Value = n($totalProduccionAnioAnterior, 2) . ' kg';
$kpi2Trend = 'vs ' . $anioActual . ': ' . n($totalProduccionAnioActual, 2) . ' kg';

if ($modo === 'costo') {
  $kpi3Label = 'Costo promedio ' . $anioActual;
  $kpi3Value = $ratioPromedioAnioActual !== null ? '$ ' . n($ratioPromedioAnioActual, 2) : '-';
  $kpi3Trend = 'Base ' . $anioAnterior . ': ' . ($ratioBase !== null ? '$ ' . n($ratioBase, 2) : '-');
} elseif ($modo === 'impacto') {
  $kpi3Label = 'Impacto promedio por kg ' . $anioActual;
  $kpi3Value = $ratioPromedioAnioActual !== null ? '$ ' . n($ratioPromedioAnioActual, 2) : '-';
  $kpi3Trend = 'Costo base: ' . ($ratioBase !== null ? '$ ' . n($ratioBase, 2) : '-');
} else {
  $kpi3Label = 'Ratio Base (' . $anioAnterior . ')';
  $kpi3Value = $ratioBase !== null ? n($ratioBase, 2) : '-';
  $kpi3Trend = $anioActual . ': ' . ($ratioPromedioAnioActual !== null ? n($ratioPromedioAnioActual, 2) : '-');
}

if ($modo === 'impacto') {
  $variacionClase = ($ratioPromedioAnioActual ?? 0) <= 0 ? 'trend-down' : 'trend-up';
  $variacionTexto = $ratioPromedioAnioActual !== null ? '$ ' . n($ratioPromedioAnioActual, 2) : '-';
  $variacionEstado = ($ratioPromedioAnioActual ?? 0) <= 0 ? 'Ahorro' : 'Sobrecosto';
  $variacionIcono = ($ratioPromedioAnioActual ?? 0) <= 0 ? 'down' : 'up';
} else {
  $variacionClase = ($variacionRatio ?? 0) < 0
    ? 'trend-down'
    : (($variacionRatio ?? 0) > 0 ? 'trend-up' : '');

  $variacionTexto = $variacionRatio !== null
    ? (($variacionRatio > 0 ? '+' : '') . n($variacionRatio, 2) . '%')
    : '-';

  $variacionEstado = ($variacionRatio ?? 0) < 0
    ? 'Mejora'
    : (($variacionRatio ?? 0) > 0 ? 'Deterioro' : 'Estable');

  $variacionIcono = ($variacionRatio ?? 0) < 0
    ? 'down'
    : (($variacionRatio ?? 0) > 0 ? 'up' : 'right');
}
?>
<div class="kpi-grid">
  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas <?= $modo === 'consumo' ? 'fa-flask' : 'fa-dollar-sign' ?>" style="color: #10b981;"></i>
    </div>
    <div class="kpi-label"><?= htmlspecialchars($kpi1Label) ?></div>
    <div class="kpi-value"><?= htmlspecialchars($kpi1Value) ?></div>
    <div class="kpi-trend"><?= htmlspecialchars($kpi1Trend) ?></div>
  </div>

  <?php if ($modo === 'consumo'): ?>
    <div class="kpi-card">
      <div class="kpi-icon">
        <i class="fas fa-industry" style="color: #3b82f6;"></i>
      </div>
      <div class="kpi-label"><?= htmlspecialchars($kpi2Label) ?></div>
      <div class="kpi-value"><?= htmlspecialchars($kpi2Value) ?></div>
      <div class="kpi-trend"><?= htmlspecialchars($kpi2Trend) ?></div>
    </div>
  <?php endif; ?>

  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas <?= $modo === 'costo' ? 'fa-dollar-sign' : 'fa-chart-pie' ?>" style="color: #8b5cf6;"></i>
    </div>
    <div class="kpi-label"><?= htmlspecialchars($kpi3Label) ?></div>
    <div class="kpi-value"><?= htmlspecialchars($kpi3Value) ?></div>
    <div class="kpi-trend"><?= htmlspecialchars($kpi3Trend) ?></div>
  </div>

  <div class="kpi-card">
    <div class="kpi-icon">
      <i class="fas fa-chart-line" style="color: #f59e0b;"></i>
    </div>
    <div class="kpi-label">Variación vs Base</div>
    <div class="kpi-value <?= htmlspecialchars($variacionClase) ?>">
      <?= htmlspecialchars($variacionTexto) ?>
    </div>
    <div class="kpi-trend">
      <i class="fas fa-arrow-<?= htmlspecialchars($variacionIcono) ?>"></i>
      <?= htmlspecialchars($variacionEstado) ?>
    </div>
  </div>
</div>
