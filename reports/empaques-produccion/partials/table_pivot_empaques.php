<?php

/** @var int $anioPivot */
/** @var array $semanasCatalogo */
/** @var array $empaquessCatalogo */
/** @var array $empaquesEtiquetas */
/** @var array $matrizRatioEmpaques */
/** @var array $matrizImpactoEconomicoEmpaques */
/** @var array $ratioBasePorEmpaque */
/** @var array $cantidadEmpaqueAnioAnterior */
/** @var array $cantidadEmpaqueAnioActual */
/** @var array $variacionCantidadEmpaque */
/** @var array $costoPromedioEmpaqueAnioAnterior */
/** @var array $costoPromedioEmpaqueAnioActual */
/** @var array $variacionCostoEmpaque */
/** @var array $impactoEconomicoEmpaqueAnioAnterior */
/** @var array $impactoEconomicoEmpaqueAnioActual */
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

$productosEmpaques = [
  'BOTELLA1',
  'BOTELLA2',
  'BOLSA1',
  'BOLSA2',
];
?>
<div class="cards-section">
  <div class="pivot-header">
    <div>
      <h2>
        <i class="fas fa-table"></i>
        Matriz de empaques por semana (<?= htmlspecialchars((string)$anioPivot) ?>)
      </h2>
      <div class="cards-sub">
        Filas = empaques · Columnas = semanas ISO · Valor = empaque individual / producción semanal
      </div>
    </div>

    <div class="pivot-controls">
      <div class="sort-buttons">
        <button type="button" class="sort-btn active" id="sortByCantidad" data-sort="cantidad" title="Ordenar por mayor cantidad">
          <i class="fas fa-box"></i>
          Cantidad
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
    <table class="table-pivot-empaques" id="pivotTable">
      <thead>
        <tr>
          <th class="sticky-col sticky-header-col">EMPAQUES</th>
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
        <?php if (empty($empaquessCatalogo)): ?>
          <tr>
            <td colspan="<?= 1 + count($semanasCatalogo) ?>">
              <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No hay empaques para mostrar en <?= htmlspecialchars((string)$anioPivot) ?></p>
              </div>
            </td>
          </tr>
        <?php else: ?>

          <?php foreach ($empaquessCatalogo as $empaque): ?>
            <tr>
              <td class="sticky-col pivot-product-col">
                <?php
                $etiqueta = $empaquesEtiquetas[$empaque] ?? $empaque;

                $mapaGrupos = [
                  'BOTELLA1' => 'empaques_botellas',
                  'BOTELLA2' => 'empaques_botellas',
                  'BOLSA1'   => 'empaques_bolsas',
                  'BOLSA2'   => 'empaques_bolsas',
                ];

                $grupoDestino = $mapaGrupos[$empaque] ?? null;

                if ($grupoDestino !== null) {
                  $urlDetalle = '../empaques-grupo/index.php?grupo=' . urlencode($grupoDestino) . '&producto=' . urlencode($empaque);
                  $tituloLink = 'Ver detalle del grupo';
                } else {
                  $urlDetalle = '../empaque-detalle/index.php?producto=' . urlencode($empaque) . '&productoLabel=' . urlencode($etiqueta);
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
              $anterior = (float)($cantidadEmpaqueAnioAnterior[$empaque] ?? 0);
              $actual = (float)($cantidadEmpaqueAnioActual[$empaque] ?? 0);
              $variacion = $variacionCantidadEmpaque[$empaque] ?? 0;
              $costoAnterior = (float)($impactoEconomicoEmpaqueAnioAnterior[$empaque] ?? 0);
              $costoActual = (float)($impactoEconomicoEmpaqueAnioActual[$empaque] ?? 0);
              $variacionCosto = $variacionCostoEmpaque[$empaque] ?? 0;

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
                $ratioEmpaque = $matrizRatioEmpaques[$empaque][$semana] ?? null;
                $baseEmpaque = $ratioBasePorEmpaque[$empaque] ?? null;
                $impactoEconomico = $matrizImpactoEconomicoEmpaques[$empaque][$semana] ?? null;
                [$estadoCelda, $colorCelda, $colorHexCelda] = semaforo($ratioEmpaque, $baseEmpaque, $toleranciaPct);
                ?>
                <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>" data-ratio="<?= $ratioEmpaque !== null ? (float)$ratioEmpaque : 'null' ?>" data-costo="<?= $impactoEconomico !== null ? (float)$impactoEconomico : 'null' ?>">
                  <div class="ratio-semaforo-wrap">
                    <div class="ratio-value">
                      <strong><?= $ratioEmpaque !== null ? n((float)$ratioEmpaque, 2) : '-' ?></strong>
                    </div>

                    <div class="ratio-base-cell">
                      Base: <?= $baseEmpaque !== null ? n((float)$baseEmpaque, 2) : '-' ?>
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
            <td class="sticky-col pivot-summary-col"><strong>TOTAL EMPAQUES</strong></td>
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

    // Ordenamiento dinámico por cantidad/costo
    const sortByCantidad = document.getElementById('sortByCantidad');
    const sortByCosto = document.getElementById('sortByCosto');
    const table = document.getElementById('pivotTable');

    if (sortByCantidad && sortByCosto && table) {
      const totalesCantidad = <?= json_encode($totalesCantidadEmpaque, JSON_UNESCAPED_UNICODE) ?>;
      const totalesCosto = <?= json_encode($totalesCostoEmpaque, JSON_UNESCAPED_UNICODE) ?>;

      function sortTable(modo) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        // Guardar referencia a todas las filas de totales (summary-row)
        const summaryRows = Array.from(tbody.querySelectorAll('tr.summary-row'));

        // Ordenar solo las filas de producto (que NO tienen summary-row)
        const productRows = Array.from(tbody.querySelectorAll('tr:not(.summary-row)'));

        productRows.sort((rowA, rowB) => {
          const linkA = rowA.querySelector('.pivot-link-detail strong');
          const linkB = rowB.querySelector('.pivot-link-detail strong');

          if (!linkA || !linkB) return 0;

          const etiquetaA = linkA.textContent.trim();
          const etiquetaB = linkB.textContent.trim();

          // Buscar la clave del empaque en los totales
          const etiquetas = <?= json_encode($empaquesEtiquetas, JSON_UNESCAPED_UNICODE) ?>;
          let keyA, keyB;
          for (const [key, etiqueta] of Object.entries(etiquetas)) {
            if (etiqueta === etiquetaA) keyA = key;
            if (etiqueta === etiquetaB) keyB = key;
          }

          if (!keyA || !keyB) return 0;

          const valorA = modo === 'cantidad' ? (totalesCantidad[keyA] || 0) : (totalesCosto[keyA] || 0);
          const valorB = modo === 'cantidad' ? (totalesCantidad[keyB] || 0) : (totalesCosto[keyB] || 0);

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
            // Modo cantidad - mostrar ratio
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
        const rowsProducto = table.querySelectorAll('tbody tr:not(.summary-row)');
        rowsProducto.forEach(row => {
          const celdaAnterior = row.querySelector('td.sticky-col[data-valor-anterior]');
          const celdaActual = row.querySelector('td.sticky-col[data-variacion]');

          if (celdaAnterior && celdaActual) {
            if (modo === 'costo') {
              const costoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-costo-anterior'));
              const costoActual = parseFloat(celdaActual.getAttribute('data-valor-costo-actual'));
              const variacionCosto = parseFloat(celdaActual.getAttribute('data-variacion-costo'));

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
                spanVariacion.textContent = (variacionCosto >= 0 ? '+' : '') + variacionCosto.toFixed(1) + '%';
              }
            } else {
              // Modo cantidad - mostrar cantidad y variacion original
              const cantidadAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-anterior'));
              const cantidadActual = parseFloat(celdaActual.getAttribute('data-valor-actual'));
              const variacionCantidad = parseFloat(celdaActual.getAttribute('data-variacion'));

              const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
              if (spanAnterior && !isNaN(cantidadAnterior)) {
                spanAnterior.textContent = cantidadAnterior.toFixed(2);
              }

              const spanActual = celdaActual.querySelector('.valor-actual');
              const spanVariacion = celdaActual.querySelector('.variacion-texto');
              if (spanActual && !isNaN(cantidadActual)) {
                spanActual.textContent = cantidadActual.toFixed(2);
              }
              if (spanVariacion && !isNaN(variacionCantidad)) {
                spanVariacion.textContent = (variacionCantidad >= 0 ? '+' : '') + variacionCantidad.toFixed(1) + '%';
              }
            }
          }
        });
      }

      sortByCantidad.addEventListener('click', function() {
        sortByCantidad.classList.add('active');
        sortByCosto.classList.remove('active');
        sortTable('cantidad');
        cambiarModoVisualizacion('cantidad');
      });

      sortByCosto.addEventListener('click', function() {
        sortByCosto.classList.add('active');
        sortByCantidad.classList.remove('active');
        sortTable('costo');
        cambiarModoVisualizacion('costo');
      });

      // Aplicar ordenamiento inicial por cantidad (mayor a menor)
      sortTable('cantidad');
    }

    renderWindow();
  })();
</script>
