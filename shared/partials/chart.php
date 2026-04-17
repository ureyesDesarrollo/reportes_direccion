<?php

/** @var int $anioAnterior */
/** @var int $anioActual */
/** @var float|null $ratioBase */
/** @var float|null $limiteAmarillo */
?>
<div class="chart-container">
  <div class="chart-header">
    <?php $omitBaseYear = $anioAnterior === 2025; ?>
    <h3>
      <i class="fas fa-chart-line"></i>
      <?= $modo === 'impacto' ? 'Comportamiento del Impacto' : 'Comparativa Semanal' ?>
      <?= $omitBaseYear ? htmlspecialchars((string)$anioActual) : htmlspecialchars((string)$anioAnterior) . ' vs ' . htmlspecialchars((string)$anioActual) ?>
      <?php if (!$omitBaseYear): ?>
        <span style="font-size: 0.8rem;">
          <span style="color: #3b82f6;">● <?= htmlspecialchars((string)$anioAnterior) ?> (Base)</span>
          vs
          <span style="color: #10b981;">● <?= htmlspecialchars((string)$anioActual) ?></span>
        </span>
      <?php endif; ?>
    </h3>

    <div class="legend">
      <?php if ($modo === 'impacto'): ?>
        <div class="legend-item">
          <div class="legend-color" style="background: #10b981;"></div>
          <span>Ahorro (≤ 0%)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #f59e0b;"></div>
          <span>Cuidado (≤ <?= $limiteAmarillo !== null ? (($limiteAmarillo) * 100) . '%' : '-' ?>)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #ef4444;"></div>
          <span>Sobrecosto (&gt; <?= $limiteAmarillo !== null ? (($limiteAmarillo) * 100) . '%' : '-' ?>)</span>
        </div>
      <?php else: ?>
        <div class="legend-item">
          <div class="legend-color" style="background: #10b981;"></div>
          <span>Óptimo (≤ <?= $ratioBase !== null ? n($ratioBase, 2) : '-' ?>)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #f59e0b;"></div>
          <span>Cuidado (≤ <?= $limiteAmarillo !== null ? n($limiteAmarillo, 2) : '-' ?>)</span>
        </div>

        <div class="legend-item">
          <div class="legend-color" style="background: #ef4444;"></div>
          <span>Alto (&gt; <?= $limiteAmarillo !== null ? n($limiteAmarillo, 2) : '-' ?>)</span>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <canvas id="ratioChart"></canvas>
</div>
