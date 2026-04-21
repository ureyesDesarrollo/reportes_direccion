<?php

/** @var int $anioPivot */
/** @var array $semanasCatalogo */
/** @var array $quimicosCatalogo */
/** @var array $quimicosEtiquetas */
/** @var array $matrizRatioQuimicos */
/** @var array $matrizImpactoEconomicoQuimicos */
/** @var array $ratioBasePorQuimico */
/** @var array $consumoQuimicoAnioAnterior */
/** @var array $consumoQuimicoAnioActual */
/** @var array $variacionConsumoQuimico */
/** @var array $costoPromedioAnioAnterior */
/** @var array $costoPromedioAnioActual */
/** @var array $variacionCostoQuimico */
/** @var array $impactoEconomicoAnioAnterior */
/** @var array $impactoEconomicoAnioActual */
/** @var array $totalesPorSemana */
/** @var array $produccionPorSemana */
/** @var array $ratioPorSemana */
/** @var float|null $ratioBase */
/** @var float|null $limiteAmarillo */
/** @var array $meta */

$toleranciaPct = (float)($meta['toleranciaPct'] ?? 10);
$totalSemanas = count($semanasCatalogo);
$windowSize = 5;
$startIndex = max(0, $totalSemanas - $windowSize);
$grupoEstructura = $meta['grupo_estructura'] ?? [];
?>
<div class="cards-section">
  <div class="pivot-header">
    <div>
      <h2>
        <i class="fas fa-table"></i>
        Matriz de químicos por semana (<?= htmlspecialchars((string)$anioPivot) ?>)
      </h2>
      <div class="cards-sub">
        Filas = químicos · Columnas = semanas ISO · Valor = químico individual / producción semanal
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
    <table class="table-pivot-quimicos" id="pivotTable">
      <thead>
        <tr>
          <th class="sticky-col sticky-header-col">QUIMICOS</th>
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
            <td colspan="<?= 1 + count($semanasCatalogo) ?>">
              <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No hay químicos para mostrar en <?= htmlspecialchars((string)$anioPivot) ?></p>
              </div>
            </td>
          </tr>
        <?php else: ?>

          <?php foreach ($quimicosCatalogo as $quimico): ?>
            <tr data-quimico="<?= htmlspecialchars($quimico) ?>">
              <td class="sticky-col pivot-product-col">
                <?php
                $etiqueta = $quimicosEtiquetas[$quimico] ?? $quimico;

                if (isset($grupoEstructura[$quimico])) {
                  $urlDetalle = '../enzima-produccion/index.php?grupo=' . urlencode($quimico);
                  $tituloLink = 'Ver detalle del grupo';
                } else {
                  $urlDetalle = '../quimico-detalle/index.php?producto=' . urlencode($quimico) . '&productoLabel=' . urlencode($etiqueta);
                  $tituloLink = 'Ver detalle individual';
                }
                ?>

                <a
                  href="<?= htmlspecialchars($urlDetalle) ?>"
                  class="pivot-link-detail"
                  title="<?= htmlspecialchars($tituloLink) ?>">
                  <strong><?= htmlspecialchars($etiqueta) ?></strong>
                  <span class="pivot-link-icon">
                    <i class="fas fa-arrow-up-right-from-square"></i>
                  </span>
                </a>
              </td>

              <?php
              $anterior = (float)($consumoQuimicoAnioAnterior[$quimico] ?? 0);
              $actual = (float)($consumoQuimicoAnioActual[$quimico] ?? 0);
              $variacion = $variacionConsumoQuimico[$quimico] ?? 0;
              $costoAnterior = (float)($impactoEconomicoAnioAnterior[$quimico] ?? 0);
              $costoActual = (float)($impactoEconomicoAnioActual[$quimico] ?? 0);
              $variacionCosto = $variacionCostoQuimico[$quimico] ?? 0;

              if ($variacion > 10) {
                $colorSemaforo = '#ef4444';
              } elseif ($variacion > 0) {
                $colorSemaforo = '#f59e0b';
              } else {
                $colorSemaforo = '#10b981';
              }
              ?>

              <td class="sticky-col" style="text-align: center; font-weight: 600; background: #f8fafc;" data-valor-anterior="<?= $anterior ?>" data-valor-costo-anterior="<?= $costoAnterior ?>">
                <span class="valor-anterior"><?= n($anterior, 2) ?></span>
              </td>

              <td class="sticky-col" style="text-align: center; background: #f8fafc; border-left: 4px solid <?= htmlspecialchars($colorSemaforo) ?>;" data-valor-actual="<?= $actual ?>" data-valor-costo-actual="<?= $costoActual ?>" data-variacion="<?= $variacion ?>" data-variacion-costo="<?= $variacionCosto ?>">
                <div style="font-weight: 600;">
                  <span class="valor-actual"><?= n($actual, 2) ?></span><br>
                  <span style="font-size: 0.85em; color: <?= htmlspecialchars($colorSemaforo) ?>;" class="variacion-texto">
                    <?= $variacion >= 0 ? '+' : '' ?><?= n($variacion, 1) ?>%
                  </span>
                </div>
              </td>

              <?php foreach ($semanasCatalogo as $index => $semana): ?>
                <?php
                $ratioQuimico = $matrizRatioQuimicos[$quimico][$semana] ?? null;
                $baseQuimico = $ratioBasePorQuimico[$quimico] ?? null;
                $impactoEconomico = $matrizImpactoEconomicoQuimicos[$quimico][$semana] ?? null;
                [$estadoCelda, $colorCelda, $colorHexCelda] = semaforo($ratioQuimico, $baseQuimico, $toleranciaPct);
                ?>
                <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>" data-ratio="<?= $ratioQuimico !== null ? (float)$ratioQuimico : 'null' ?>" data-costo="<?= $impactoEconomico !== null ? (float)$impactoEconomico : 'null' ?>">
                  <div class="ratio-semaforo-wrap">
                    <div class="ratio-value">
                      <strong><?= $ratioQuimico !== null ? n((float)$ratioQuimico, 2) : '-' ?></strong>
                    </div>

                    <div class="ratio-base-cell">
                      Base: <?= $baseQuimico !== null ? n((float)$baseQuimico, 2) : '-' ?>
                    </div>

                    <span
                      class="status-badge status-badge-ratio"
                      title="<?= htmlspecialchars($estadoCelda) ?>"
                      style="background: <?= htmlspecialchars($colorHexCelda) ?>15; color: <?= htmlspecialchars($colorHexCelda) ?>; border: 1px solid <?= htmlspecialchars($colorHexCelda) ?>30;">
                      <span class="status-dot" style="background: <?= htmlspecialchars($colorHexCelda) ?>;"></span>
                      <?= htmlspecialchars($estadoCelda) ?>
                    </span>
                  </div>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>

          <tr class="row-total summary-row">
            <td class="sticky-col pivot-summary-col"><strong>TOTAL QUIMICOS</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <td class="week-col" data-week-index="<?= $index ?>">
                <strong><?= n((float)($totalesPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row">
            <td class="sticky-col pivot-summary-col"><strong>PRODUCCION</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <td class="week-col" data-week-index="<?= $index ?>">
                <strong><?= n((float)($produccionPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row row-ratio-semaforo">
            <td class="sticky-col pivot-summary-col"><strong>RATIO</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <?php
              $ratio = $ratioPorSemana[$semana] ?? null;
              [$estado, $color, $colorHex] = semaforo($ratio, $ratioBase, $toleranciaPct);
              ?>
              <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>">
                <div class="ratio-semaforo-wrap">
                  <div class="ratio-value">
                    <strong><?= $ratio !== null ? n((float)$ratio, 2) : '-' ?></strong>
                  </div>

                  <div class="ratio-base-cell">
                    Base gral: <?= $ratioBase !== null ? n((float)$ratioBase, 2) : '-' ?>
                  </div>

                  <span
                    class="status-badge status-badge-ratio"
                    title="<?= htmlspecialchars($estado) ?>"
                    style="background: <?= htmlspecialchars($colorHex) ?>15; color: <?= htmlspecialchars($colorHex) ?>; border: 1px solid <?= htmlspecialchars($colorHex) ?>30;">
                    <span class="status-dot" style="background: <?= htmlspecialchars($colorHex) ?>;"></span>
                    <?= htmlspecialchars($estado) ?>
                  </span>
                </div>
              </td>
            <?php endforeach; ?>
          </tr>

        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  (function() {
    const semanas = <?= json_encode(array_values($semanasCatalogo), JSON_UNESCAPED_UNICODE) ?>;
    const windowSize = 5;
    let startIndex = <?= (int)$startIndex ?>;

    const prevBtn = document.getElementById('pivotPrevBtn');
    const nextBtn = document.getElementById('pivotNextBtn');
    const info = document.getElementById('pivotWindowInfo');

    function renderWindow() {
      const endIndex = Math.min(semanas.length - 1, startIndex + windowSize - 1);

      document.querySelectorAll('#pivotTable .week-col').forEach(cell => {
        const idx = parseInt(cell.dataset.weekIndex, 10);
        cell.style.display = (idx >= startIndex && idx <= endIndex) ? '' : 'none';
        cell.classList.toggle('week-visible-current', idx === endIndex);
      });

      if (info) {
        const desde = semanas[startIndex] || '-';
        const hasta = semanas[endIndex] || '-';
        info.textContent = `Mostrando ${desde} a ${hasta}`;
      }

      if (prevBtn) prevBtn.disabled = startIndex <= 0;
      if (nextBtn) nextBtn.disabled = endIndex >= semanas.length - 1;
    }

    if (prevBtn) {
      prevBtn.addEventListener('click', function() {
        if (startIndex > 0) {
          startIndex = Math.max(0, startIndex - 1);
          renderWindow();
        }
      });
    }

    if (nextBtn) {
      nextBtn.addEventListener('click', function() {
        const maxStart = Math.max(0, semanas.length - windowSize);
        if (startIndex < maxStart) {
          startIndex = Math.min(maxStart, startIndex + 1);
          renderWindow();
        }
      });
    }

    const toggleSummary = document.getElementById('toggleSummaryRows');
    const summaryRows = document.querySelectorAll('.summary-row');

    if (toggleSummary && summaryRows.length) {
      toggleSummary.addEventListener('change', function() {
        summaryRows.forEach(row => {
          row.style.display = this.checked ? '' : 'none';
        });
      });
    }

    // Ordenamiento dinámico por consumo/costo
    const sortByConsumo = document.getElementById('sortByConsumo');
    const sortByCosto = document.getElementById('sortByCosto');
    const table = document.getElementById('pivotTable');

    if (sortByConsumo && sortByCosto && table) {
      const totalesConsumo = <?= json_encode($totalesConsumoQuimico, JSON_UNESCAPED_UNICODE) ?>;
      const totalesCosto = <?= json_encode($totalesCostoQuimico, JSON_UNESCAPED_UNICODE) ?>;

      function semaforoColor(variacion) {
        if (variacion > 10) return '#ef4444';
        if (variacion > 0) return '#f59e0b';
        return '#10b981';
      }

      function sortTable(modo) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Guardar referencia a todas las filas de totales (summary-row)
        const summaryRows = Array.from(tbody.querySelectorAll('tr.summary-row'));

        // Ordenar solo las filas de producto (que NO tienen summary-row)
        const productRows = Array.from(tbody.querySelectorAll('tr[data-quimico]'));

        productRows.sort((rowA, rowB) => {
          const keyA = rowA.getAttribute('data-quimico');
          const keyB = rowB.getAttribute('data-quimico');

          if (!keyA || !keyB) return 0;

          const valorA = modo === 'consumo' ? (totalesConsumo[keyA] || 0) : (totalesCosto[keyA] || 0);
          const valorB = modo === 'consumo' ? (totalesConsumo[keyB] || 0) : (totalesCosto[keyB] || 0);

          return valorB - valorA; // Mayor a menor
        });

        // Reinsertar filas ordenadas
        productRows.forEach(row => tbody.appendChild(row));

        // Reinsertar todas las filas de resumen al final
        summaryRows.forEach(row => tbody.appendChild(row));
      }

      // Función para cambiar los valores mostrados en las celdas
      function cambiarModoVisualizacion(modo) {
        // Cambiar valores en celdas de semanas
        const celdas = table.querySelectorAll('td.cell-ratio-semaforo');
        celdas.forEach(celda => {
          const ratioValue = celda.querySelector('.ratio-value strong');
          const ratioBase = celda.querySelector('.ratio-base-cell');

          if (modo === 'costo') {
            const costo = parseFloat(celda.getAttribute('data-costo'));
            if (!isNaN(costo) && costo !== null) {
              // Mostrar en dinero con símbolo de pesos y separador de miles
              ratioValue.textContent = '$' + costo.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              ratioBase.style.display = 'none';
            } else {
              ratioValue.textContent = '-';
              ratioBase.style.display = 'none';
            }
          } else {
            // Modo consumo - mostrar ratio
            const ratio = parseFloat(celda.getAttribute('data-ratio'));
            if (!isNaN(ratio) && ratio !== null) {
              ratioValue.textContent = ratio.toFixed(2);
              ratioBase.style.display = 'block';
            } else {
              ratioValue.textContent = '-';
              ratioBase.style.display = 'block';
            }
          }
        });

        // Cambiar valores en columnas adhesivas (Anterior y Actual)
        const rowsProducto = table.querySelectorAll('tbody tr[data-quimico]');
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

              // Actualizar Anterior
              const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
              if (spanAnterior && !isNaN(costoAnterior)) {
                spanAnterior.textContent = '$' + costoAnterior.toLocaleString('es-MX', {
                  minimumFractionDigits: 2,
                  maximumFractionDigits: 2
                });
              }

              // Actualizar Actual y Variación
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
              // Modo consumo - mostrar consumo y variacion original
              const consumoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-anterior'));
              const consumoActual = parseFloat(celdaActual.getAttribute('data-valor-actual'));
              const variacionConsumo = parseFloat(celdaActual.getAttribute('data-variacion'));
              const color = semaforoColor(variacionConsumo);

              celdaActual.style.borderLeftColor = color;

              const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
              if (spanAnterior && !isNaN(consumoAnterior)) {
                spanAnterior.textContent = consumoAnterior.toFixed(2);
              }

              const spanActual = celdaActual.querySelector('.valor-actual');
              const spanVariacion = celdaActual.querySelector('.variacion-texto');
              if (spanActual && !isNaN(consumoActual)) {
                spanActual.textContent = consumoActual.toFixed(2);
              }
              if (spanVariacion && !isNaN(variacionConsumo)) {
                spanVariacion.style.color = color;
                spanVariacion.textContent = (variacionConsumo >= 0 ? '+' : '') + variacionConsumo.toFixed(1) + '%';
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

      // Aplicar ordenamiento inicial por consumo (mayor a menor)
      sortTable('consumo');
    }

    renderWindow();
  })();
</script>
