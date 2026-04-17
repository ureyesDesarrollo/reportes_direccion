<?php

/** @var array $meta */

$modo = $meta['modo'] ?? 'consumo';
$metricaTitulo = $meta['metricaTitulo'] ?? ($modo === 'costo' ? 'Costo' : 'Consumo');
$metricaUnidad = $meta['metricaUnidad'] ?? ($modo === 'costo' ? '$' : 'kg');
$metricaHeader = $modo === 'costo'
  ? $metricaTitulo . ' ($)'
  : $metricaTitulo . ' (' . $metricaUnidad . ')';

$ratioHeader = $modo === 'costo' ? 'Ratio ($/kg)' : 'Ratio (kg/kg)';
if ($modo === 'impacto') {
  $ratioHeader = 'Impacto ($/kg)';
}
?>
<div class="table-wrapper">
  <table id="dataTable">
    <thead>
      <tr>
        <th data-sort="semana">Semana <i class="fas fa-sort"></i></th>
        <?php if ($modo !== 'impacto'): ?>
          <th data-sort="inicio">Inicio</th>
          <th data-sort="fin">Fin</th>
        <?php endif; ?>
        <?php if ($modo === 'impacto'): ?>
          <th data-sort="costo_promedio">Costo Promedio ($) <i class="fas fa-sort"></i></th>
          <th data-sort="costo_base">Costo Base ($) <i class="fas fa-sort"></i></th>
          <th data-sort="diferencia_precio">Diferencia Precio ($) <i class="fas fa-sort"></i></th>
          <th data-sort="consumo_kg">Kilos Utilizados <i class="fas fa-sort"></i></th>
        <?php endif; ?>
        <th data-sort="quimicos"><?= htmlspecialchars($metricaHeader) ?> <i class="fas fa-sort"></i></th>
        <?php if ($modo !== 'impacto'): ?>
          <th data-sort="produccion">Producción (kg) <i class="fas fa-sort"></i></th>
        <?php endif; ?>
        <th data-sort="ratio"><?= htmlspecialchars($ratioHeader) ?> <i class="fas fa-sort"></i></th>
        <th>Semáforo</th>
        <th>Nivel</th>
      </tr>
    </thead>
    <tbody id="tableBody"></tbody>
  </table>

  <div class="pagination">
    <button id="prevPageTable" onclick="changeTablePage(-1)">
      <i class="fas fa-chevron-left"></i> Anterior
    </button>
    <div id="tablePageNumbers" class="page-numbers"></div>
    <button id="nextPageTable" onclick="changeTablePage(1)">
      Siguiente <i class="fas fa-chevron-right"></i>
    </button>
  </div>
</div>
