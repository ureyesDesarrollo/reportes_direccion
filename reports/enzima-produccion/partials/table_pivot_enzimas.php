<?php

/** @var array $meta */

// Extraer variables del meta
$anioPivot = $meta['anioPivot'] ?? date('Y');
$semanasCatalogo = $meta['semanasCatalogo'] ?? [];
$quimicosCatalogo = $meta['quimicosCatalogo'] ?? [];
$quimicosEtiquetas = $meta['quimicosEtiquetas'] ?? [];
$matrizRatioQuimicos = $meta['matrizRatioQuimicos'] ?? [];
$matrizImpactoEconomicoQuimicos = $meta['matrizImpactoEconomicoQuimicos'] ?? [];
$ratioBasePorQuimico = $meta['ratioBasePorQuimico'] ?? [];
$consumoQuimicoAnioAnterior = $meta['consumoEnzimaAnioAnterior'] ?? [];
$consumoQuimicoAnioActual = $meta['consumoEnzimaAnioActual'] ?? [];
$variacionConsumoQuimico = $meta['variacionConsumoEnzima'] ?? [];
$costoPromedioAnioAnterior = $meta['costoPromedioAnioAnterior'] ?? [];
$costoPromedioAnioActual = $meta['costoPromedioAnioActual'] ?? [];
$variacionCostoQuimico = $meta['variacionCostoEnzima'] ?? [];
$impactoEconomicoAnioAnterior = $meta['impactoEconomicoAnioAnterior'] ?? [];
$impactoEconomicoAnioActual = $meta['impactoEconomicoAnioActual'] ?? [];
$totalesConsumoQuimico = $meta['totalesConsumoQuimico'] ?? [];
$totalesCostoQuimico = $meta['totalesCostoQuimico'] ?? [];
$grupoEstructura = $meta['grupo_estructura'] ?? [];

$toleranciaPct = (float)($meta['toleranciaPct'] ?? 10);
$totalSemanas = count($semanasCatalogo);
$windowSize = 5;
$startIndex = max(0, $totalSemanas - $windowSize);
?>
<div class="pivot-header">
  <div>
    <h2>
      <i class="fas fa-table"></i>
      Matriz de enzimas por semana (<?= htmlspecialchars((string)$anioPivot) ?>)
    </h2>
    <div class="cards-sub">
      Filas = enzimas · Columnas = semanas ISO · Valor = enzima individual / producción semanal
    </div>
  </div>

  <div class="pivot-controls">
    <div class="sort-buttons">
      <button type="button" class="sort-btn active" id="sortByConsumo" data-sort="consumo" title="Ordenar por mayor consumo">
        <i class="fas fa-droplet"></i>
        Consumo
      </button>
      <button type="button" class="sort-btn" id="sortByCosto" data-sort="costo" title="Ordenar por mayor impacto económico">
        <i class="fas fa-dollar-sign"></i>
        Costo
      </button>
    </div>
    <label class="toggle-semaforo">
      <input type="checkbox" id="toggleSummaryRows" checked>
      <span>Mostrar resumen</span>
    </label>
  </div>
</div>

<div class="pivot-legend" id="pivotLegend">
  <div class="legend-item-card">
    <span class="dot" style="background:#10b981;"></span>
    Óptimo
  </div>
  <div class="legend-item-card">
    <span class="dot" style="background:#f59e0b;"></span>
    Cuidado
  </div>
  <div class="legend-item-card">
    <span class="dot" style="background:#ef4444;"></span>
    Alto
  </div>
  <div class="legend-item-card">
    <span class="dot" style="background:#94a3b8;"></span>
    Sin dato
  </div>
</div>

<div class="pivot-window-bar">
  <button type="button" class="pivot-nav-btn" id="pivotPrevBtn">
    <i class="fas fa-chevron-left"></i>
    Anteriores
  </button>

  <div class="pivot-window-info" id="pivotWindowInfo">
    Mostrando semanas
  </div>

  <button type="button" class="pivot-nav-btn" id="pivotNextBtn">
    Siguientes
    <i class="fas fa-chevron-right"></i>
  </button>
</div>

<div class="table-wrapper table-wrapper-pivot">
  <table class="table-pivot-enzimas" id="pivotTable">
    <thead>
      <tr>
        <th class="sticky-col sticky-header-col">ENZIMAS</th>
        <th class="sticky-col sticky-header-col" style="width: 70px; text-align: center;">
          <div style="font-size: 0.85em; font-weight: 600;">Anterior</div>
        </th>
        <th class="sticky-col sticky-header-col" style="width: 90px; text-align: center;">
          <div style="font-size: 0.85em; font-weight: 600;">Actual | Var%</div>
        </th>

        <?php foreach ($semanasCatalogo as $index => $semana): ?>
          <th
            class="week-col week-col-header"
            data-week-index="<?= $index ?>"
            data-semana="<?= htmlspecialchars($semana) ?>">
            <div class="week-pill">
              <span class="week-pill-top"><?= htmlspecialchars($semana) ?></span>
              <span class="week-pill-bottom">Semana</span>
            </div>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>

    <tbody>
      <?php if (empty($quimicosCatalogo)): ?>
        <tr>
          <td colspan="<?= 3 + count($semanasCatalogo) ?>">
            <div class="empty-state">
              <i class="fas fa-inbox"></i>
              <p>No hay enzimas para mostrar en <?= htmlspecialchars((string)$anioPivot) ?></p>
            </div>
          </td>
        </tr>
      <?php else: ?>

        <?php foreach ($quimicosCatalogo as $grupoKey): ?>
          <?php $grupoTitulo = $quimicosEtiquetas[$grupoKey] ?? $grupoKey; ?>

          <tr class="product-row" data-producto="<?= htmlspecialchars($grupoKey) ?>">
            <td class="sticky-col pivot-product-col">
              <strong><?= htmlspecialchars($grupoTitulo) ?></strong>
            </td>

            <?php
            $anterior = (float)($consumoQuimicoAnioAnterior[$grupoTitulo] ?? 0);
            $actual = (float)($consumoQuimicoAnioActual[$grupoTitulo] ?? 0);
            $variacion = $variacionConsumoQuimico[$grupoTitulo] ?? 0;
            $costoAnterior = (float)($costoPromedioAnioAnterior[$grupoTitulo] ?? 0);
            $costoActual = (float)($costoPromedioAnioActual[$grupoTitulo] ?? 0);
            $variacionCosto = $variacionCostoQuimico[$grupoTitulo] ?? 0;

            // Aplicar semáforo: rojo si variación > 10%, amarillo si 0% a 10%, verde si < 0%
            if ($variacion > 10) {
              $colorSemaforo = '#ef4444'; // Rojo
              $estadoSemaforo = 'Alto';
            } elseif ($variacion > 0) {
              $colorSemaforo = '#f59e0b'; // Amarillo
              $estadoSemaforo = 'Medio';
            } else {
              $colorSemaforo = '#10b981'; // Verde
              $estadoSemaforo = 'Bajo';
            }
            ?>

            <td class="sticky-col" style="text-align: center; font-weight: 600; background: #f8fafc;" data-valor-anterior="<?= $anterior ?>" data-valor-costo-anterior="<?= $costoAnterior ?>">
              <span class="valor-anterior"><?= n($anterior, 2) ?></span>
            </td>

            <td class="sticky-col" style="text-align: center; background: #f8fafc; border-left: 4px solid <?= htmlspecialchars($colorSemaforo) ?>;" data-valor-actual="<?= $actual ?>" data-valor-costo-actual="<?= (float)($impactoEconomicoAnioActual[$grupoTitulo] ?? 0) ?>" data-variacion="<?= $variacion ?>" data-variacion-costo="<?= $variacionCosto ?>">
              <div style="font-weight: 600;">
                <span class="valor-actual"><?= n($actual, 2) ?></span><br>
                <span style="font-size: 0.85em; color: <?= htmlspecialchars($colorSemaforo) ?>;" class="variacion-texto">
                  <?= $variacion >= 0 ? '+' : '' ?><?= n($variacion, 1) ?>%
                </span>
              </div>
            </td>
          </tr>

        <?php endforeach; ?>

      <?php endif; ?>
    </tbody>
  </table>
</div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const sortByConsumo = document.getElementById('sortByConsumo');
    const sortByCosto = document.getElementById('sortByCosto');
    const table = document.getElementById('pivotTable');

    if (sortByConsumo && sortByCosto && table) {
      const totalesConsumo = <?= json_encode($totalesConsumoQuimico ?? [], JSON_UNESCAPED_UNICODE) ?>;
      const totalesCosto = <?= json_encode($totalesCostoQuimico ?? [], JSON_UNESCAPED_UNICODE) ?>;

      function semaforoColor(variacion) {
        if (variacion > 10) return '#ef4444';
        if (variacion > 0) return '#f59e0b';
        return '#10b981';
      }

      function sortTable(modo) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const productRows = Array.from(tbody.querySelectorAll('tr.product-row'));
        productRows.sort((rowA, rowB) => {
          const grupoKeyA = rowA.getAttribute('data-producto');
          const grupoKeyB = rowB.getAttribute('data-producto');
          const valorA = modo === 'consumo' ? (totalesConsumo[grupoKeyA] || 0) : (totalesCosto[grupoKeyA] || 0);
          const valorB = modo === 'consumo' ? (totalesConsumo[grupoKeyB] || 0) : (totalesCosto[grupoKeyB] || 0);
          return valorB - valorA;
        });
        productRows.forEach(row => tbody.appendChild(row));
      }

      function cambiarModoVisualizacion(modo) {
        const rowsProducto = table.querySelectorAll('tbody tr.product-row');
        rowsProducto.forEach(row => {
          const celdaAnterior = row.querySelector('td.sticky-col[data-valor-anterior]');
          const celdaActual = row.querySelector('td.sticky-col[data-variacion]');

          if (celdaAnterior && celdaActual) {
            if (modo === 'costo') {
              const costoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-costo-anterior'));
              const costoActual = parseFloat(celdaActual.getAttribute('data-valor-costo-actual'));
              const variacionCosto = parseFloat(celdaActual.getAttribute('data-variacion-costo'));
              const color = semaforoColor(variacionCosto);

              celdaActual.style.borderLeftColor = color;

              const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
              if (spanAnterior && !isNaN(costoAnterior)) {
                spanAnterior.textContent = '$' + costoAnterior.toLocaleString('es-MX', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }

              const spanActual = celdaActual.querySelector('.valor-actual');
              const spanVariacion = celdaActual.querySelector('.variacion-texto');

              if (spanActual && !isNaN(costoActual)) {
                spanActual.textContent = '$' + costoActual.toLocaleString('es-MX', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }
              if (spanVariacion && !isNaN(variacionCosto)) {
                spanVariacion.style.color = color;
                spanVariacion.textContent = (variacionCosto >= 0 ? '+' : '') + variacionCosto.toFixed(1) + '%';
              }
            } else {
              const consumoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-anterior'));
              const consumoActual = parseFloat(celdaActual.getAttribute('data-valor-actual'));
              const variacion = parseFloat(celdaActual.getAttribute('data-variacion'));
              const color = semaforoColor(variacion);

              celdaActual.style.borderLeftColor = color;

              const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
              if (spanAnterior && !isNaN(consumoAnterior)) {
                spanAnterior.textContent = consumoAnterior.toLocaleString('es-MX', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }

              const spanActual = celdaActual.querySelector('.valor-actual');
              const spanVariacion = celdaActual.querySelector('.variacion-texto');

              if (spanActual && !isNaN(consumoActual)) {
                spanActual.textContent = consumoActual.toLocaleString('es-MX', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }
              if (spanVariacion && !isNaN(variacion)) {
                spanVariacion.style.color = color;
                spanVariacion.textContent = (variacion >= 0 ? '+' : '') + variacion.toFixed(1) + '%';
              }
            }
          }
        });
      }

      sortByConsumo.addEventListener('click', function() {
        sortByConsumo.classList.add('active');
        sortByCosto.classList.remove('active');
        sortTable('consumo');
        cambiarModoVisualizacion('consumo');
      });

      sortByCosto.addEventListener('click', function() {
        sortByCosto.classList.add('active');
        sortByConsumo.classList.remove('active');
        sortTable('costo');
        cambiarModoVisualizacion('costo');
      });

      sortTable('consumo');
    }
  });
</script>
