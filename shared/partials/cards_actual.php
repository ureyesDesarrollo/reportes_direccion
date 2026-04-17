<?php

/** @var int $anioActual */
/** @var array $datosAnioActual */
?>
<div class="cards-section">
  <h2>
    <i class="fas fa-chart-line"></i>
    Semáforos visuales - Año <?= htmlspecialchars((string)$anioActual) ?>
  </h2>

  <div class="cards-sub">
    <?= count($datosAnioActual) ?> semanas registradas en <?= htmlspecialchars((string)$anioActual) ?>
  </div>

  <div class="legend-cards">
    <div class="legend-item-card"><span class="dot" style="background: #10b981;"></span> Óptimo</div>
    <div class="legend-item-card"><span class="dot" style="background: #f59e0b;"></span> Cuidado</div>
    <div class="legend-item-card"><span class="dot" style="background: #ef4444;"></span> Alto</div>
    <div class="legend-item-card"><span class="dot" style="background: #94a3b8;"></span> Sin dato</div>
  </div>

  <div id="cardsAnioActualContainer" class="weeks"></div>

  <div class="pagination">
    <button id="prevPageCardsAnioActual" onclick="changeCardsAnioActualPage(-1)">
      <i class="fas fa-chevron-left"></i> Anteriores
    </button>
    <div id="cardsAnioActualPageNumbers" class="page-numbers"></div>
    <button id="nextPageCardsAnioActual" onclick="changeCardsAnioActualPage(1)">
      Siguientes <i class="fas fa-chevron-right"></i>
    </button>
  </div>
</div>
