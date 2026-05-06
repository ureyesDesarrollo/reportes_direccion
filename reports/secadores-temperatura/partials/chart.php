<div class="cards-section">
  <div class="pivot-header">
    <div>
      <h2>
        <i class="fas fa-chart-line"></i>
        Tendencia histórica <?= htmlspecialchars((new DateTime())->format('d/m/Y')) ?>
      </h2>
      <div class="cards-sub" id="secadoresChartSub">
        Comportamiento del túnel seleccionado
      </div>
    </div>
  </div>

  <div style="position: relative; min-height: 360px;">
    <canvas id="secadoresChart"></canvas>
  </div>
</div>
