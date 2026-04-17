<?php

/** @var int $anioAnterior */
/** @var array $datosAnioAnterior */
?>
<div class="toggle-container">
  <div class="toggle-switch">
    <i class="fas fa-calendar-alt" style="color: #10b981;"></i>
    <span style="font-weight: 600;">Mostrar tarjetas de <?= htmlspecialchars((string)$anioAnterior) ?></span>
    <label class="switch">
      <input type="checkbox" id="toggleAnioAnteriorCards">
      <span class="slider"></span>
    </label>
  </div>

  <div style="font-size: 0.75rem; color: #64748b;">
    <i class="fas fa-info-circle"></i>
    Año base visible bajo demanda
  </div>
</div>

<div class="cards-section hidden" id="cardsAnioAnteriorSection">
  <h2>
    <i class="fas fa-chart-simple"></i>
    Semáforos visuales - Año <?= htmlspecialchars((string)$anioAnterior) ?> (Base)
  </h2>

  <div class="cards-sub">
    <?= count($datosAnioAnterior) ?> semanas registradas en <?= htmlspecialchars((string)$anioAnterior) ?>
  </div>

  <div class="legend-cards">
    <div class="legend-item-card"><span class="dot" style="background: #10b981;"></span> Óptimo</div>
    <div class="legend-item-card"><span class="dot" style="background: #f59e0b;"></span> Cuidado</div>
    <div class="legend-item-card"><span class="dot" style="background: #ef4444;"></span> Alto</div>
    <div class="legend-item-card"><span class="dot" style="background: #94a3b8;"></span> Sin dato</div>
  </div>

  <div id="cardsAnioAnteriorContainer" class="weeks"></div>

  <div class="pagination">
    <button id="prevPageCardsAnioAnterior" onclick="changeCardsAnioAnteriorPage(-1)">
      <i class="fas fa-chevron-left"></i> Anteriores
    </button>
    <div id="cardsAnioAnteriorPageNumbers" class="page-numbers"></div>
    <button id="nextPageCardsAnioAnterior" onclick="changeCardsAnioAnteriorPage(1)">
      Siguientes <i class="fas fa-chevron-right"></i>
    </button>
  </div>
</div>
