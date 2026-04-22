<?php

/** @var int $anioPivot */
/** @var array $semanasCatalogo */
/** @var array $refaccionesCatalogo */
/** @var array $refaccionesEtiquetas */
/** @var array $matrizRatioRefacciones */
/** @var array $ratioBasePorRefaccion */
/** @var array $consumoRefaccionAnioAnterior */
/** @var array $consumoRefaccionAnioActual */
/** @var array $variacionConsumoRefaccion */
/** @var array $costoPromedioAnioAnterior */
/** @var array $costoPromedioAnioActual */
/** @var array $variacionCostoRefaccion */
/** @var array $impactoEconomicoAnioAnterior */
/** @var array $impactoEconomicoAnioActual */
/** @var array $matrizCostos */
/** @var array $totalesPorSemana */
/** @var array $ratioPorSemana */
/** @var float|null $ratioBase */
/** @var float|null $limiteAmarillo */
/** @var array $meta */

$toleranciaPct = (float)($meta['toleranciaPct'] ?? 10);
$totalSemanas  = count($semanasCatalogo);
$windowSize    = 5;
$startIndex    = max(0, $totalSemanas - $windowSize);
?>
<div class="cards-section">
  <div class="pivot-header">
    <div>
      <h2>
        <i class="fas fa-table"></i>
        Matriz de refacciones críticas por semana (<?= htmlspecialchars((string)$anioPivot) ?>)
      </h2>
      <div class="cards-sub">
        Filas = refacciones · Columnas = semanas ISO · Valor = cantidad consumida por semana
      </div>
    </div>

    <div class="pivot-controls">
      <div class="sort-buttons">
        <button type="button" class="sort-btn active" id="sortByConsumo" data-sort="consumo" title="Ordenar por mayor consumo">
          <i class="fas fa-wrench"></i>
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
    <div class="pivot-window-info" id="pivotWindowInfo">Mostrando semanas</div>
    <button type="button" class="pivot-nav-btn" id="pivotNextBtn">
      Siguientes
      <i class="fas fa-chevron-right"></i>
    </button>
  </div>

  <div class="table-wrapper table-wrapper-pivot">
    <table class="table-pivot-quimicos" id="pivotTable">
      <thead>
        <tr>
          <th class="sticky-col sticky-header-col">REFACCIÓN</th>
          <th class="sticky-col sticky-header-col" style="width: 70px; text-align: center;">
            <div style="font-size: 0.85em; font-weight: 600;">Anterior</div>
          </th>
          <th class="sticky-col sticky-header-col" style="width: 90px; text-align: center;">
            <div style="font-size: 0.85em; font-weight: 600;">Actual | Var%</div>
          </th>
          <th class="sticky-col sticky-header-col col-costo-unit" style="width: 80px; text-align: center; display: none;">
            <div style="font-size: 0.85em; font-weight: 600;">Costo Unit.<br><small style="font-weight:400;"><?= $anioAnterior ?></small></div>
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

      <?php
      $_detailBaseUrl = '../refaccion-detalle/index.php';
      $_pivotRows = [];
      foreach ($refaccionesCatalogo as $_k) {
        $_lbl  = $refaccionesEtiquetas[$_k] ?? $_k;
        $_base = $ratioBasePorRefaccion[$_k] ?? null;
        $_sw   = [];
        foreach ($semanasCatalogo as $_idx => $_sem) {
          $_r = $matrizRatioRefacciones[$_k][$_sem] ?? null;
          $_c = (float)($matrizCostos[$_k][$_sem] ?? 0);
          [$_e,, $_h] = semaforo($_r, $_base, $toleranciaPct);
          $_sw[] = ['r' => $_r, 'c' => $_c, 'e' => $_e, 'h' => $_h, 'b' => $_base];
        }
        $_pivotRows[] = [
          'key'  => $_k,
          'lbl'  => $_lbl,
          'ant'  => (float)($consumoRefaccionAnioAnterior[$_k] ?? 0),
          'act'  => (float)($consumoRefaccionAnioActual[$_k]  ?? 0),
          'var'  => (float)($variacionConsumoRefaccion[$_k]   ?? 0),
          'ca'   => (float)($impactoEconomicoAnioAnterior[$_k] ?? 0),
          'cac'  => (float)($impactoEconomicoAnioActual[$_k]   ?? 0),
          'varc' => (float)($variacionCostoRefaccion[$_k]      ?? 0),
          'cu'   => (float)($costoPromedioAnioAnterior[$_k]    ?? 0),
          's'    => $_sw,
        ];
      }
      ?>
      <tbody id="pivotProductBody">
        <?php if (empty($refaccionesCatalogo)): ?>
          <tr>
            <td colspan="<?= 4 + count($semanasCatalogo) ?>">
              <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No hay refacciones para mostrar en <?= htmlspecialchars((string)$anioPivot) ?></p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
      <tbody>
        <?php if (!empty($refaccionesCatalogo)): ?>
          <!-- Fila de totales (sin producción ni ratio ya que no hay producción) -->
          <tr class="row-total summary-row row-ratio-semaforo">
            <td class="sticky-col pivot-summary-col"><strong>TOTAL REFACCIONES</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col col-costo-unit" style="background: #f8fafc; display: none;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <?php
              $total = $totalesPorSemana[$semana] ?? 0;
              $ratio = $ratioPorSemana[$semana] ?? null;
              [$estado, $color, $colorHex] = semaforo($ratio, $ratioBase, $toleranciaPct);
              ?>
              <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>">
                <div class="ratio-semaforo-wrap">
                  <div class="ratio-value">
                    <strong><?= n((float)$total, 2) ?></strong>
                  </div>

                  <div class="ratio-base-cell">
                    Base gral: <?= $ratioBase !== null ? n((float)$ratioBase, 1) : '-' ?>
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
  <div id="pivotRowPagination" class="pagination" style="display:none; margin-top: 1rem;"></div>
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
      if (nextBtn) nextBtn.disabled = startIndex + windowSize >= semanas.length;
    }

    if (prevBtn) prevBtn.addEventListener('click', () => {
      if (startIndex > 0) {
        startIndex = Math.max(0, startIndex - 1);
        renderWindow();
      }
    });

    if (nextBtn) nextBtn.addEventListener('click', () => {
      const maxStart = Math.max(0, semanas.length - windowSize);
      if (startIndex < maxStart) {
        startIndex = Math.min(maxStart, startIndex + 1);
        renderWindow();
      }
    });

    const toggleSummary = document.getElementById('toggleSummaryRows');
    if (toggleSummary) {
      toggleSummary.addEventListener('change', function() {
        document.querySelectorAll('.summary-row').forEach(row => {
          row.style.display = this.checked ? '' : 'none';
        });
      });
    }

    const sortByConsumo = document.getElementById('sortByConsumo');
    const sortByCosto = document.getElementById('sortByCosto');
    const table = document.getElementById('pivotTable');

    if (sortByConsumo && sortByCosto && table) {
      const pivotRows = <?= json_encode(array_values($_pivotRows), JSON_UNESCAPED_UNICODE) ?>;
      const detailUrl = '<?= addslashes($_detailBaseUrl) ?>';

      const rowsPerPage = 20;
      let currentRowPage = 0;
      let currentMode = 'consumo';
      let sortedData = [];

      function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
      }

      function fmtN(n, d) {
        return parseFloat(n).toLocaleString('es-MX', {
          minimumFractionDigits: d !== undefined ? d : 2,
          maximumFractionDigits: d !== undefined ? d : 2
        });
      }

      function buildProductRow(item) {
        var winEnd = Math.min(semanas.length - 1, startIndex + windowSize - 1);
        var vc = item.var > 10 ? '#ef4444' : item.var > 0 ? '#f59e0b' : '#10b981';
        var vcc = item.varc > 10 ? '#ef4444' : item.varc > 0 ? '#f59e0b' : '#10b981';
        var borderColor = currentMode === 'costo' ? vcc : vc;
        var antDisp = currentMode === 'costo' ? '$' + fmtN(item.ca) : fmtN(item.ant);
        var actDisp = currentMode === 'costo' ? '$' + fmtN(item.cac) : fmtN(item.act);
        var varVal = currentMode === 'costo' ? item.varc : item.var;
        var varDisp = (varVal >= 0 ? '+' : '') + fmtN(varVal, 1) + '%';
        var varColor = currentMode === 'costo' ? vcc : vc;
        var cuDisp = item.cu > 0 ? '$' + fmtN(item.cu) : '-';
        var weekCells = '';

        semanas.forEach(function(sem, idx) {
          var visible = idx >= startIndex && idx <= winEnd;
          var d = item.s[idx] || {};
          var ratio = d.r != null ? d.r : null;
          var costoU = d.c || 0;
          var base = d.b != null ? d.b : null;
          var estadoC = d.e || 'Sin dato';
          var colorHex = d.h || '#94a3b8';
          var rDisp, bColor, bLabel, baseHtml;

          if (currentMode === 'costo') {
            rDisp = costoU > 0 ? '$' + fmtN(costoU) : '-';
            baseHtml = '';
            if (costoU > 0 && item.cu > 0) {
              var varU = (costoU - item.cu) / item.cu * 100;
              bColor = varU > 6 ? '#ef4444' : varU > 0 ? '#f59e0b' : '#10b981';
              bLabel = varU > 6 ? 'Alto' : varU > 0 ? 'Cuidado' : '\u00d3ptimo';
            } else {
              bColor = '#94a3b8';
              bLabel = 'Sin dato';
            }
          } else {
            rDisp = ratio !== null ? fmtN(ratio) : '-';
            baseHtml = '<div class="ratio-base-cell">Base: ' + (base !== null ? fmtN(base, 1) : '-') + '</div>';
            bColor = colorHex;
            bLabel = estadoC;
          }

          var ci = (costoU > 0 && ratio !== null) ? costoU * ratio : null;
          weekCells +=
            '<td class="week-col cell-ratio-semaforo"' +
            ' data-week-index="' + idx + '"' +
            ' data-ratio="' + (ratio !== null ? ratio : 'null') + '"' +
            ' data-costo="' + (ci !== null ? ci : 'null') + '"' +
            ' data-costo-unitario="' + costoU + '"' +
            (visible ? '' : ' style="display:none"') + '>' +
            '<div class="ratio-semaforo-wrap">' +
            '<div class="ratio-value"><strong>' + rDisp + '</strong></div>' +
            baseHtml +
            '<span class="status-badge status-badge-ratio" title="' + esc(bLabel) + '"' +
            ' style="background:' + bColor + '15;color:' + bColor + ';border:1px solid ' + bColor + '30;">' +
            '<span class="status-dot" style="background:' + bColor + ';"></span>' +
            esc(bLabel) + '</span></div></td>';
        });

        return '<tr data-quimico="' + esc(item.key) + '" data-costo-unit-anterior="' + item.cu + '">' +
          '<td class="sticky-col pivot-product-col">' +
          '<a href="' + esc(detailUrl + '?producto=' + encodeURIComponent(item.key) + '&productoLabel=' + encodeURIComponent(item.lbl)) + '"' +
          ' class="pivot-link-detail" title="Ver detalle de la refacci\u00f3n">' +
          '<strong>' + esc(item.lbl) + '</strong>' +
          '<span class="pivot-link-icon"><i class="fas fa-arrow-up-right-from-square"></i></span>' +
          '</a></td>' +
          '<td class="sticky-col" style="text-align:center;font-weight:600;background:#f8fafc;"' +
          ' data-valor-anterior="' + item.ant + '" data-valor-costo-anterior="' + item.ca + '">' +
          '<span class="valor-anterior">' + antDisp + '</span></td>' +
          '<td class="sticky-col" style="text-align:center;background:#f8fafc;border-left:4px solid ' + borderColor + ';"' +
          ' data-valor-actual="' + item.act + '" data-valor-costo-actual="' + item.cac + '"' +
          ' data-variacion="' + item.var+'" data-variacion-costo="' + item.varc + '">' +
          '<div style="font-weight:600;">' +
          '<span class="valor-actual">' + actDisp + '</span><br>' +
          '<span style="font-size:0.85em;color:' + varColor + ';" class="variacion-texto">' + varDisp + '</span>' +
          '</div></td>' +
          '<td class="sticky-col col-costo-unit" style="text-align:center;background:#f8fafc;' + (currentMode !== 'costo' ? 'display:none;' : '') + '">' +
          '<span style="font-size:0.85em;font-weight:600;">' + cuDisp + '</span></td>' +
          weekCells + '</tr>';
      }

      function renderProductPage() {
        var tbody = document.getElementById('pivotProductBody');
        if (!tbody) return;
        tbody.innerHTML = sortedData.slice(currentRowPage * rowsPerPage, (currentRowPage + 1) * rowsPerPage).map(buildProductRow).join('');
        document.querySelectorAll('#pivotTable .col-costo-unit').forEach(function(el) {
          el.style.display = currentMode === 'costo' ? '' : 'none';
        });
        updateRowPagination();
      }

      function updateRowPagination() {
        var total = sortedData.length;
        var totalPages = Math.ceil(total / rowsPerPage);
        var el = document.getElementById('pivotRowPagination');
        if (!el) return;
        if (totalPages <= 1) {
          el.style.display = 'none';
          return;
        }
        el.style.display = '';
        var from = currentRowPage * rowsPerPage + 1;
        var to = Math.min((currentRowPage + 1) * rowsPerPage, total);
        el.innerHTML =
          '<button class="pagination-btn" id="pivotRowPrev"' + (currentRowPage === 0 ? ' disabled' : '') + '>' +
          '<i class="fas fa-chevron-left"></i> Anterior</button>' +
          '<span class="page-info">Refacciones ' + from + '\u2013' + to + ' de ' + total + '</span>' +
          '<button class="pagination-btn" id="pivotRowNext"' + (currentRowPage >= totalPages - 1 ? ' disabled' : '') + '>' +
          'Siguiente <i class="fas fa-chevron-right"></i></button>';
        var pp = document.getElementById('pivotRowPrev');
        var pn = document.getElementById('pivotRowNext');
        if (pp) pp.addEventListener('click', function() {
          if (currentRowPage > 0) {
            currentRowPage--;
            renderProductPage();
            renderWindow();
          }
        });
        if (pn) pn.addEventListener('click', function() {
          if (currentRowPage < totalPages - 1) {
            currentRowPage++;
            renderProductPage();
            renderWindow();
          }
        });
      }

      function sortTable(modo) {
        sortedData = pivotRows.slice().sort(function(a, b) {
          return modo === 'consumo' ? b.act - a.act : b.cu - a.cu;
        });
        currentRowPage = 0;
        renderProductPage();
        renderWindow();
      }

      function cambiarModo(modo) {
        currentMode = modo;
        var cs = table.closest('.cards-section');
        var wr = table.closest('.table-wrapper-pivot');
        if (modo === 'costo') {
          if (cs) {
            cs.style.marginLeft = '-56px';
            cs.style.marginRight = '-56px';
            cs.style.borderRadius = '0';
          }
          if (wr) {
            wr.style.marginLeft = '0';
            wr.style.marginRight = '0';
          }
        } else {
          if (cs) {
            cs.style.marginLeft = '';
            cs.style.marginRight = '';
            cs.style.borderRadius = '';
          }
          if (wr) {
            wr.style.marginLeft = '';
            wr.style.marginRight = '';
          }
        }
        sortTable(modo);
        sortByConsumo.classList.toggle('active', modo === 'consumo');
        sortByCosto.classList.toggle('active', modo === 'costo');
      }

      function _deadRenderRowPage() {
        const start = currentRowPage * rowsPerPage;
        const end = start + rowsPerPage;
        sortedProductRows.forEach((row, idx) => {
          row.style.display = (idx >= start && idx < end) ? '' : 'none';
        });
        updateRowPagination();
      }

      function _deadUpdateRowPagination() {
        const total = sortedProductRows.length;
        const totalPages = Math.ceil(total / rowsPerPage);
        const el = document.getElementById('pivotRowPagination');
        if (!el) return;

        if (totalPages <= 1) {
          el.style.display = 'none';
          return;
        }
        el.style.display = '';

        const from = currentRowPage * rowsPerPage + 1;
        const to = Math.min((currentRowPage + 1) * rowsPerPage, total);

        el.innerHTML = `
          <button class="pagination-btn" id="pivotRowPrev" ${currentRowPage === 0 ? 'disabled' : ''}>
            <i class="fas fa-chevron-left"></i> Anterior
          </button>
          <span class="page-info">Refacciones ${from}\u2013${to} de ${total}</span>
          <button class="pagination-btn" id="pivotRowNext" ${currentRowPage >= totalPages - 1 ? 'disabled' : ''}>
            Siguiente <i class="fas fa-chevron-right"></i>
          </button>
        `;
        document.getElementById('pivotRowPrev')?.addEventListener('click', () => {
          if (currentRowPage > 0) {
            currentRowPage--;
            renderRowPage();
          }
        });
        document.getElementById('pivotRowNext')?.addEventListener('click', () => {
          if (currentRowPage < totalPages - 1) {
            currentRowPage++;
            renderRowPage();
          }
        });
      }

      function _deadSortTable(modo) {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const summaryRows = Array.from(tbody.querySelectorAll('tr.summary-row'));
        sortedProductRows = Array.from(tbody.querySelectorAll('tr[data-quimico]'));

        sortedProductRows.sort((a, b) => {
          const kA = a.getAttribute('data-quimico');
          const kB = b.getAttribute('data-quimico');
          const vA = modo === 'consumo' ? (totalesConsumo[kA] || 0) : (costosUnitarios[kA] || 0);
          const vB = modo === 'consumo' ? (totalesConsumo[kB] || 0) : (costosUnitarios[kB] || 0);
          return vB - vA;
        });

        sortedProductRows.forEach(row => tbody.appendChild(row));
        summaryRows.forEach(row => tbody.appendChild(row));
        currentRowPage = 0;
        renderRowPage();
      }

      function _deadCambiarModo(modo) {
        // Mostrar/ocultar columna de costo unitario
        document.querySelectorAll('#pivotTable .col-costo-unit').forEach(el => {
          el.style.display = modo === 'costo' ? '' : 'none';
        });

        // Expandir/restaurar tabla al activar modo costo
        const cardsSection = table.closest('.cards-section');
        const wrapper = table.closest('.table-wrapper-pivot');
        if (modo === 'costo') {
          if (cardsSection) {
            cardsSection.style.marginLeft = '-56px';
            cardsSection.style.marginRight = '-56px';
            cardsSection.style.borderRadius = '0';
          }
          if (wrapper) {
            wrapper.style.marginLeft = '0';
            wrapper.style.marginRight = '0';
          }
        } else {
          if (cardsSection) {
            cardsSection.style.marginLeft = '';
            cardsSection.style.marginRight = '';
            cardsSection.style.borderRadius = '';
          }
          if (wrapper) {
            wrapper.style.marginLeft = '';
            wrapper.style.marginRight = '';
          }
        }

        // Cambiar celdas de semanas
        table.querySelectorAll('td.cell-ratio-semaforo').forEach(celda => {
          const ratioValue = celda.querySelector('.ratio-value strong');
          const ratioBase = celda.querySelector('.ratio-base-cell');
          const badge = celda.querySelector('.status-badge.status-badge-ratio');

          if (badge && !celda.hasAttribute('data-badge-original')) {
            celda.setAttribute('data-badge-original', badge.innerHTML);
            celda.setAttribute('data-badge-style-original', badge.getAttribute('style') || '');
            celda.setAttribute('data-badge-title-original', badge.getAttribute('title') || '');
          }

          if (modo === 'costo') {
            const costoUnit = parseFloat(celda.getAttribute('data-costo-unitario'));
            if (!isNaN(costoUnit) && costoUnit > 0) {
              ratioValue.textContent = '$' + costoUnit.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              if (ratioBase) ratioBase.style.display = 'none';
            } else {
              ratioValue.textContent = '-';
              if (ratioBase) ratioBase.style.display = 'none';
            }

            if (badge) {
              const row = celda.closest('tr[data-quimico]');
              const costoUnitAnterior = row ? parseFloat(row.getAttribute('data-costo-unit-anterior')) : NaN;
              let badgeColor, badgeLabel;
              if (!isNaN(costoUnit) && costoUnit > 0 && !isNaN(costoUnitAnterior) && costoUnitAnterior > 0) {
                const varUnit = (costoUnit - costoUnitAnterior) / costoUnitAnterior * 100;
                badgeColor = varUnit > 6 ? '#ef4444' : (varUnit > 0 ? '#f59e0b' : '#10b981');
                badgeLabel = varUnit > 6 ? 'Alto' : (varUnit > 0 ? 'Cuidado' : 'Óptimo');
              } else {
                badgeColor = '#94a3b8';
                badgeLabel = 'Sin dato';
              }
              const dot = badge.querySelector('.status-dot');
              if (dot) dot.style.background = badgeColor;
              badge.style.background = badgeColor + '15';
              badge.style.color = badgeColor;
              badge.style.border = '1px solid ' + badgeColor + '30';
              badge.title = badgeLabel;
              badge.childNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE && node.textContent.trim() !== '') {
                  node.textContent = ' ' + badgeLabel;
                }
              });
            }
          } else {
            const ratio = parseFloat(celda.getAttribute('data-ratio'));
            if (!isNaN(ratio)) {
              ratioValue.textContent = ratio.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              if (ratioBase) ratioBase.style.display = '';
            } else {
              ratioValue.textContent = '-';
              if (ratioBase) ratioBase.style.display = '';
            }
            if (badge && celda.hasAttribute('data-badge-original')) {
              badge.innerHTML = celda.getAttribute('data-badge-original');
              badge.setAttribute('style', celda.getAttribute('data-badge-style-original'));
              badge.setAttribute('title', celda.getAttribute('data-badge-title-original'));
            }
          }
        });

        // Cambiar columnas adhesivas Anterior / Actual
        table.querySelectorAll('tbody tr[data-quimico]').forEach(row => {
          const celdaAnterior = row.querySelector('td.sticky-col[data-valor-anterior]');
          const celdaActual = row.querySelector('td.sticky-col[data-variacion]');

          if (!celdaAnterior || !celdaActual) return;

          if (modo === 'costo') {
            const costoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-costo-anterior'));
            const costoActual = parseFloat(celdaActual.getAttribute('data-valor-costo-actual'));
            const variacionCosto = parseFloat(celdaActual.getAttribute('data-variacion-costo'));
            const color = semaforoColor(variacionCosto);

            celdaActual.style.borderLeftColor = color;

            const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
            const spanActual = celdaActual.querySelector('.valor-actual');
            const spanVariacion = celdaActual.querySelector('.variacion-texto');

            if (spanAnterior && !isNaN(costoAnterior))
              spanAnterior.textContent = '$' + costoAnterior.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
            if (spanActual && !isNaN(costoActual))
              spanActual.textContent = '$' + costoActual.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
            if (spanVariacion && !isNaN(variacionCosto)) {
              spanVariacion.style.color = color;
              spanVariacion.textContent = (variacionCosto >= 0 ? '+' : '') + variacionCosto.toFixed(1) + '%';
            }
          } else {
            const consumoAnterior = parseFloat(celdaAnterior.getAttribute('data-valor-anterior'));
            const consumoActual = parseFloat(celdaActual.getAttribute('data-valor-actual'));
            const variacionConsumo = parseFloat(celdaActual.getAttribute('data-variacion'));
            const color = semaforoColor(variacionConsumo);

            celdaActual.style.borderLeftColor = color;

            const spanAnterior = celdaAnterior.querySelector('.valor-anterior');
            const spanActual = celdaActual.querySelector('.valor-actual');
            const spanVariacion = celdaActual.querySelector('.variacion-texto');

            if (spanAnterior && !isNaN(consumoAnterior))
              spanAnterior.textContent = consumoAnterior.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
            if (spanActual && !isNaN(consumoActual))
              spanActual.textContent = consumoActual.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
            if (spanVariacion && !isNaN(variacionConsumo)) {
              spanVariacion.style.color = color;
              spanVariacion.textContent = (variacionConsumo >= 0 ? '+' : '') + variacionConsumo.toFixed(1) + '%';
            }
          }
        });
      }

      sortByConsumo.addEventListener('click', function() {
        cambiarModo('consumo');
      });
      sortByCosto.addEventListener('click', function() {
        cambiarModo('costo');
      });
      sortTable('consumo');
    }

    renderWindow();
  })();
</script>
