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
/** @var array $costoPorProduccionAnioAnterior */
/** @var array $costoPorProduccionAnioActual */
/** @var array $variacionCostoPorProduccionQuimico */
/** @var array $impactoEconomicoAnioAnterior */
/** @var array $impactoEconomicoAnioActual */
/** @var array $matrizCostos */
/** @var array $matrizCostoProduccionQuimicos */
/** @var array $totalesPorSemana */
/** @var array $produccionPorSemana */
/** @var array $ratioPorSemana */
/** @var float|null $ratioBase */
/** @var float|null $limiteAmarillo */
/** @var array $meta */

$toleranciaPct = (float)($meta['toleranciaPct'] ?? 10);
$totalSemanas = count($semanasCatalogo);
$windowSize = 5;
$currentIsoYear = (int)date('o');
$currentIsoWeek = (int)date('W');
$maxVisibleWeekIndex = max(0, min($totalSemanas - 1, $currentIsoWeek - 1));

if ($anioPivot < $currentIsoYear) {
  $maxVisibleWeekIndex = max(0, $totalSemanas - 1);
} elseif ($anioPivot > $currentIsoYear) {
  $maxVisibleWeekIndex = 0;
}

$endIndex = $maxVisibleWeekIndex;
$startIndex = max(0, $endIndex - $windowSize + 1);
$grupoEstructura = $meta['grupo_estructura'] ?? [];
$semanaDisplay = static fn($semana): string => preg_replace('/^S0([1-9])$/', 'S$1', (string)$semana) ?? (string)$semana;
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
          Consumo por producción
        </button>
        <button type="button" class="sort-btn" id="sortByCosto" data-sort="costo" title="Ordenar por mayor costo por producción">
          <i class="fas fa-dollar-sign"></i>
          Costo por producción
        </button>
        <button type="button" class="sort-btn" id="sortByCompra" data-sort="compra" title="Ordenar por mayor costo de compra">
          <i class="fas fa-dollar-sign"></i>
          Costo de compra
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
      Mostrando <?= htmlspecialchars($semanaDisplay($semanasCatalogo[$startIndex] ?? '-')) ?> a <?= htmlspecialchars($semanaDisplay($semanasCatalogo[$endIndex] ?? '-')) ?>
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
          <th class="sticky-col sticky-header-col col-costo-unit" style="width: 80px; text-align: center; display: none;">
            <div style="font-size: 0.85em; font-weight: 600;">Costo Unit.<br><small style="font-weight:400;"><?= $anioAnterior ?></small></div>
          </th>

          <?php foreach ($semanasCatalogo as $index => $semana): ?>
            <?php $weekVisible = $index >= $startIndex && $index <= $endIndex; ?>
            <th
              class="week-col week-col-header"
              data-week-index="<?= $index ?>"
              data-semana="<?= htmlspecialchars($semana) ?>"
              style="<?= $weekVisible ? '' : 'display:none;' ?>">
              <div class="week-pill">
                <span class="week-pill-top"><?= htmlspecialchars($semanaDisplay($semana)) ?></span>
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
            <tr
              data-quimico="<?= htmlspecialchars($quimico) ?>"
              data-costo-unit-anterior="<?= (float)($costoPromedioAnioAnterior[$quimico] ?? 0) ?>"
              data-costo-produccion-anterior="<?= (float)($costoPorProduccionAnioAnterior[$quimico] ?? 0) ?>">
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
              $costoAnterior = (float)($costoPorProduccionAnioAnterior[$quimico] ?? 0);
              $costoActual = (float)($costoPorProduccionAnioActual[$quimico] ?? 0);
              $variacionCosto = $variacionCostoPorProduccionQuimico[$quimico] ?? 0;

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

              <?php $costoUnitAnterior = (float)($costoPromedioAnioAnterior[$quimico] ?? 0); ?>
              <td class="sticky-col col-costo-unit" style="text-align: center; background: #f8fafc; display: none;">
                <span style="font-size: 0.85em; font-weight: 600;"><?= $costoUnitAnterior > 0 ? '$' . n($costoUnitAnterior, 2) : '-' ?></span>
              </td>

              <?php foreach ($semanasCatalogo as $index => $semana): ?>
                <?php $weekVisible = $index >= $startIndex && $index <= $endIndex; ?>
                <?php
                $ratioQuimico = $matrizRatioQuimicos[$quimico][$semana] ?? null;
                $baseQuimico = $ratioBasePorQuimico[$quimico] ?? null;
                $impactoEconomico = $matrizImpactoEconomicoQuimicos[$quimico][$semana] ?? null;
                [$estadoCelda, $colorCelda, $colorHexCelda] = semaforo($ratioQuimico, $baseQuimico, $toleranciaPct);
                ?>
                <?php $costoUnitSemana = (float)($matrizCostos[$quimico][$semana] ?? 0); ?>
                <td
                  class="week-col cell-ratio-semaforo"
                  data-week-index="<?= $index ?>"
                  style="<?= $weekVisible ? '' : 'display:none;' ?>"
                  data-ratio="<?= $ratioQuimico !== null ? (float)$ratioQuimico : 'null' ?>"
                  data-costo="<?= $impactoEconomico !== null ? (float)$impactoEconomico : 'null' ?>"
                  data-costo-unitario="<?= $costoUnitSemana ?>"
                  data-costo-produccion="<?= ($matrizCostoProduccionQuimicos[$quimico][$semana] ?? null) !== null ? (float)$matrizCostoProduccionQuimicos[$quimico][$semana] : 'null' ?>">
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
            <td class="sticky-col col-costo-unit" style="background: #f8fafc; display: none;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <?php $weekVisible = $index >= $startIndex && $index <= $endIndex; ?>
              <td class="week-col" data-week-index="<?= $index ?>" style="<?= $weekVisible ? '' : 'display:none;' ?>">
                <strong><?= n((float)($totalesPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row">
            <td class="sticky-col pivot-summary-col"><strong>PRODUCCION</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col col-costo-unit" style="background: #f8fafc; display: none;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <?php $weekVisible = $index >= $startIndex && $index <= $endIndex; ?>
              <td class="week-col" data-week-index="<?= $index ?>" style="<?= $weekVisible ? '' : 'display:none;' ?>">
                <strong><?= n((float)($produccionPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row row-ratio-semaforo">
            <td class="sticky-col pivot-summary-col"><strong>RATIO</strong></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col" style="background: #f8fafc;"></td>
            <td class="sticky-col col-costo-unit" style="background: #f8fafc; display: none;"></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <?php $weekVisible = $index >= $startIndex && $index <= $endIndex; ?>
              <?php
              $ratio = $ratioPorSemana[$semana] ?? null;
              [$estado, $color, $colorHex] = semaforo($ratio, $ratioBase, $toleranciaPct);
              ?>
              <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>" style="<?= $weekVisible ? '' : 'display:none;' ?>">
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
    const maxVisibleWeekIndex = <?= (int)$maxVisibleWeekIndex ?>;
    let startIndex = <?= (int)$startIndex ?>;
    const formatSemana = (semana) => String(semana || '').replace(/^S0([1-9])$/, 'S$1');

    const prevBtn = document.getElementById('pivotPrevBtn');
    const nextBtn = document.getElementById('pivotNextBtn');
    const info = document.getElementById('pivotWindowInfo');

    function renderWindow() {
      const endIndex = Math.min(maxVisibleWeekIndex, startIndex + windowSize - 1);

      document.querySelectorAll('#pivotTable .week-col').forEach(cell => {
        const idx = parseInt(cell.dataset.weekIndex, 10);
        cell.style.display = (idx >= startIndex && idx <= endIndex) ? '' : 'none';
        cell.classList.toggle('week-visible-current', idx === endIndex);
      });

      if (info) {
        const desde = formatSemana(semanas[startIndex] || '-');
        const hasta = formatSemana(semanas[endIndex] || '-');
        info.textContent = `Mostrando ${desde} a ${hasta}`;
      }

      if (prevBtn) prevBtn.disabled = startIndex <= 0;
      if (nextBtn) nextBtn.disabled = endIndex >= maxVisibleWeekIndex;
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
        const maxStart = Math.max(0, maxVisibleWeekIndex - windowSize + 1);
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
      const totalesCosto = <?= json_encode($costoPorProduccionAnioActual, JSON_UNESCAPED_UNICODE) ?>;
      const resumenKpis = {
        consumo: {
          kpi1Label: 'Total Consumo Grupo <?= htmlspecialchars((string)$anioAnterior) ?> (Base)',
          kpi1Value: '<?= n((float)$totalQuimicosAnioAnterior, 2) ?> kg',
          kpi1Trend: 'vs <?= htmlspecialchars((string)$anioActual) ?>: <?= n((float)$totalQuimicosAnioActual, 2) ?> kg',
          kpi2Label: 'Total Producción <?= htmlspecialchars((string)$anioAnterior) ?> (Base)',
          kpi2Value: '<?= n((float)$totalProduccionAnioAnterior, 2) ?> kg',
          kpi2Trend: 'vs <?= htmlspecialchars((string)$anioActual) ?>: <?= n((float)$totalProduccionAnioActual, 2) ?> kg',
          kpi3Label: 'Ratio Base (<?= htmlspecialchars((string)$anioAnterior) ?>)',
          kpi3Value: '<?= $ratioBase !== null ? n((float)$ratioBase, 2) : '-' ?>',
          kpi3Trend: '<?= htmlspecialchars((string)$anioActual) ?>: <?= $ratioPromedioAnioActual !== null ? n((float)$ratioPromedioAnioActual, 2) : '-' ?>',
          kpi4Value: '<?= $variacionRatio !== null ? (($variacionRatio > 0 ? '+' : '') . n((float)$variacionRatio, 2) . '%') : '-' ?>',
          kpi4Class: 'kpi-value <?= ($variacionRatio ?? 0) < 0 ? 'trend-down' : (($variacionRatio ?? 0) > 0 ? 'trend-up' : '') ?>',
          kpi4Trend: '<?= ($variacionRatio ?? 0) < 0 ? 'Mejora' : (($variacionRatio ?? 0) > 0 ? 'Deterioro' : 'Estable') ?>',
          kpi4Icon: 'fa-arrow-<?= ($variacionRatio ?? 0) < 0 ? 'down' : (($variacionRatio ?? 0) > 0 ? 'up' : 'right') ?>'
        },
        costo: {
          kpi1Label: 'Costo por producción <?= htmlspecialchars((string)$anioAnterior) ?> (Base)',
          kpi1Value: '<?= $costoPromedioPorProduccionAnioAnterior !== null ? '$ ' . n((float)$costoPromedioPorProduccionAnioAnterior, 2) : '-' ?>',
          kpi1Trend: 'vs <?= htmlspecialchars((string)$anioActual) ?>: <?= $costoPromedioPorProduccionAnioActual !== null ? '$ ' . n((float)$costoPromedioPorProduccionAnioActual, 2) : '-' ?>',
          kpi2Label: 'Total Producción <?= htmlspecialchars((string)$anioAnterior) ?> (Base)',
          kpi2Value: '<?= n((float)$totalProduccionAnioAnterior, 2) ?> kg',
          kpi2Trend: 'vs <?= htmlspecialchars((string)$anioActual) ?>: <?= n((float)$totalProduccionAnioActual, 2) ?> kg',
          kpi3Label: 'Costo prom. por producción <?= htmlspecialchars((string)$anioActual) ?>',
          kpi3Value: '<?= $costoPromedioPorProduccionAnioActual !== null ? '$ ' . n((float)$costoPromedioPorProduccionAnioActual, 2) : '-' ?>',
          kpi3Trend: 'Base <?= htmlspecialchars((string)$anioAnterior) ?>: <?= $costoPromedioPorProduccionAnioAnterior !== null ? '$ ' . n((float)$costoPromedioPorProduccionAnioAnterior, 2) : '-' ?>',
          kpi4Value: '<?= $variacionCostoProduccion !== null ? (($variacionCostoProduccion > 0 ? '+' : '') . n((float)$variacionCostoProduccion, 2) . '%') : '-' ?>',
          kpi4Class: 'kpi-value <?= ($variacionCostoProduccion ?? 0) < 0 ? 'trend-down' : (($variacionCostoProduccion ?? 0) > 0 ? 'trend-up' : '') ?>',
          kpi4Trend: '<?= ($variacionCostoProduccion ?? 0) < 0 ? 'Mejora' : (($variacionCostoProduccion ?? 0) > 0 ? 'Deterioro' : 'Estable') ?>',
          kpi4Icon: 'fa-arrow-<?= ($variacionCostoProduccion ?? 0) < 0 ? 'down' : (($variacionCostoProduccion ?? 0) > 0 ? 'up' : 'right') ?>'
        }
      };

      function semaforoColor(variacion) {
        if (variacion > 10) return '#ef4444';
        if (variacion > 0) return '#f59e0b';
        return '#10b981';
      }

      function actualizarKpisGenerales(modo) {
        const datos = resumenKpis[modo] || resumenKpis.consumo;
        const cards = document.querySelectorAll('.kpi-grid .kpi-card');
        if (cards.length < 4) return;

        const kpi1 = cards[0];
        const kpi2 = cards[1];
        const kpi3 = cards[2];
        const kpi4 = cards[3];

        const setText = function(card, label, value, trend) {
          const labelEl = card.querySelector('.kpi-label');
          const valueEl = card.querySelector('.kpi-value');
          const trendEl = card.querySelector('.kpi-trend');
          if (labelEl) labelEl.textContent = label;
          if (valueEl) valueEl.textContent = value;
          if (trendEl) trendEl.textContent = trend;
        };

        setText(kpi1, datos.kpi1Label, datos.kpi1Value, datos.kpi1Trend);
        setText(kpi2, datos.kpi2Label, datos.kpi2Value, datos.kpi2Trend);
        setText(kpi3, datos.kpi3Label, datos.kpi3Value, datos.kpi3Trend);

        const valueEl = kpi4.querySelector('.kpi-value');
        const trendEl = kpi4.querySelector('.kpi-trend');
        const labelEl = kpi4.querySelector('.kpi-label');
        const iconEl = kpi4.querySelector('.kpi-trend i');
        if (labelEl) labelEl.textContent = 'Variación vs Base';
        if (valueEl) {
          valueEl.textContent = datos.kpi4Value;
          valueEl.className = datos.kpi4Class;
        }
        if (trendEl) {
          trendEl.innerHTML = '<i class="fas ' + datos.kpi4Icon + '"></i> ' + datos.kpi4Trend;
        } else if (iconEl) {
          iconEl.className = 'fas ' + datos.kpi4Icon;
        }
      }

      function actualizarCabeceraGrafica(modo) {
        const title = document.querySelector('.chart-header h3');
        const legend = document.querySelector('.chart-header .legend');
        if (!title || !legend) return;

        if (modo === 'costo') {
          title.innerHTML =
            '<i class="fas fa-chart-line"></i> Comportamiento de costo por producción <?= htmlspecialchars((string)$anioAnterior) ?> vs <?= htmlspecialchars((string)$anioActual) ?>' +
            '<span style="font-size: 0.8rem;">' +
            '<span style="color: #3b82f6;">● <?= htmlspecialchars((string)$anioAnterior) ?> (Base)</span> vs ' +
            '<span style="color: #10b981;">● <?= htmlspecialchars((string)$anioActual) ?></span>' +
            '</span>';
          legend.innerHTML =
            '<div class="legend-item"><div class="legend-color" style="background: #10b981;"></div><span>Óptimo (≤ <?= $costoPromedioPorProduccionAnioAnterior !== null ? '$ ' . n((float)$costoPromedioPorProduccionAnioAnterior, 2) : '-' ?>)</span></div>' +
            '<div class="legend-item"><div class="legend-color" style="background: #f59e0b;"></div><span>Cuidado (≤ <?= $costoPromedioPorProduccionAnioAnterior !== null ? '$ ' . n((float)($costoPromedioPorProduccionAnioAnterior * (1 + ($toleranciaPct / 100))), 2) : '-' ?>)</span></div>' +
            '<div class="legend-item"><div class="legend-color" style="background: #ef4444;"></div><span>Alto (&gt; <?= $costoPromedioPorProduccionAnioAnterior !== null ? '$ ' . n((float)($costoPromedioPorProduccionAnioAnterior * (1 + ($toleranciaPct / 100))), 2) : '-' ?>)</span></div>';
          return;
        }

        title.innerHTML =
          '<i class="fas fa-chart-line"></i> Comparativa Semanal <?= htmlspecialchars((string)$anioAnterior) ?> vs <?= htmlspecialchars((string)$anioActual) ?>' +
          '<span style="font-size: 0.8rem;">' +
          '<span style="color: #3b82f6;">● <?= htmlspecialchars((string)$anioAnterior) ?> (Base)</span> vs ' +
          '<span style="color: #10b981;">● <?= htmlspecialchars((string)$anioActual) ?></span>' +
          '</span>';
        legend.innerHTML =
          '<div class="legend-item"><div class="legend-color" style="background: #10b981;"></div><span>Óptimo (≤ <?= $ratioBase !== null ? n((float)$ratioBase, 2) : '-' ?>)</span></div>' +
          '<div class="legend-item"><div class="legend-color" style="background: #f59e0b;"></div><span>Cuidado (≤ <?= $limiteAmarillo !== null ? n((float)$limiteAmarillo, 2) : '-' ?>)</span></div>' +
          '<div class="legend-item"><div class="legend-color" style="background: #ef4444;"></div><span>Alto (&gt; <?= $limiteAmarillo !== null ? n((float)$limiteAmarillo, 2) : '-' ?>)</span></div>';
      }

      function renderPivotChart(modo) {
        if (typeof Chart === 'undefined') return;

        const canvas = document.getElementById('ratioChart');
        if (!canvas) return;

        const chartSource = modo === 'costo' ?
          (window.reportData.chartDataCosto || {}) :
          (window.reportData.chartData || {});

        const labels = chartSource.labels || [];
        const actualData = chartSource.ratiosActual || [];
        const baseData = chartSource.ratiosBase || [];
        const pointColors = chartSource.colorsActual || actualData.map(() => '#94a3b8');
        const ratioBaseChart = Number(chartSource.ratioBase || 0);
        const tolerancia = <?= json_encode($toleranciaPct) ?>;
        const limiteAmarilloChart = ratioBaseChart > 0 ? ratioBaseChart * (1 + (tolerancia / 100)) : null;

        const existingChart = Chart.getChart(canvas);
        if (existingChart) {
          existingChart.destroy();
        }

        const datasets = [{
          label: modo === 'costo' ? 'Costo por producción <?= htmlspecialchars((string)$anioActual) ?>' : 'Ratio <?= htmlspecialchars((string)$anioActual) ?>',
          data: actualData,
          borderColor: '#2a35d4',
          backgroundColor: 'rgba(16, 185, 129, 0.05)',
          borderWidth: 3,
          pointRadius: 6,
          pointHoverRadius: 8,
          pointBackgroundColor: pointColors,
          pointBorderColor: 'white',
          pointBorderWidth: 2,
          tension: 0.3,
          fill: false,
        }];

        if (<?= json_encode($anioAnterior) ?> !== 2025) {
          datasets.push({
            label: modo === 'costo' ? 'Base <?= htmlspecialchars((string)$anioAnterior) ?>' : 'Base <?= htmlspecialchars((string)$anioAnterior) ?>',
            data: baseData,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.08)',
            borderDash: [6, 4],
            borderWidth: 2,
            pointRadius: 0,
            tension: 0.3,
            fill: false,
          });
        }

        if (limiteAmarilloChart !== null) {
          datasets.push({
            label: 'Límite Amarillo',
            data: Array(labels.length).fill(limiteAmarilloChart),
            borderColor: '#f59e0b',
            borderWidth: 2,
            backgroundColor: 'transparent',
            borderDash: [6, 4],
            fill: false,
            pointRadius: 0,
            tension: 0,
          });
        }

        const semaforoPlugin = {
          id: 'semaforoShadingPivot',
          afterDraw(chartInstance) {
            const yAxis = chartInstance.scales.y;
            const chartArea = chartInstance.chartArea;
            if (!yAxis || !chartArea || !ratioBaseChart || !limiteAmarilloChart) return;

            const ctx = chartInstance.ctx;
            const verdePx = yAxis.getPixelForValue(ratioBaseChart);
            const amarilloPx = yAxis.getPixelForValue(limiteAmarilloChart);

            ctx.save();
            ctx.fillStyle = 'rgba(239, 68, 68, 0.15)';
            ctx.fillRect(chartArea.left, chartArea.top, chartArea.width, verdePx - chartArea.top);

            ctx.fillStyle = 'rgba(245, 158, 11, 0.15)';
            ctx.fillRect(chartArea.left, verdePx, chartArea.width, amarilloPx - verdePx);

            ctx.fillStyle = 'rgba(16, 185, 129, 0.15)';
            ctx.fillRect(chartArea.left, amarilloPx, chartArea.width, chartArea.bottom - amarilloPx);
            ctx.restore();
          }
        };

        new Chart(canvas.getContext('2d'), {
          type: 'line',
          data: {
            labels,
            datasets
          },
          plugins: [semaforoPlugin],
          options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
              mode: 'index',
              intersect: false
            },
            plugins: {
              legend: {
                display: true,
                position: 'top'
              },
              tooltip: {
                backgroundColor: 'white',
                titleColor: '#0f172a',
                bodyColor: '#475569',
                borderColor: '#e2e8f0',
                borderWidth: 1,
                callbacks: {
                  title: function(context) {
                    return context.length ? formatSemana(context[0].label) : '';
                  },
                  label: function(context) {
                    const label = context.dataset.label || '';
                    const value = context.parsed.y;
                    if (value === null) return label + ': Sin dato';
                    const formatted = modo === 'costo' ?
                      '$ ' + value.toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                      }) :
                      value.toLocaleString('es-MX', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                      });
                    return label + ': ' + formatted;
                  }
                }
              }
            },
            scales: {
              y: {
                grid: {
                  color: '#e2e8f0'
                },
                title: {
                  display: true,
                  text: modo === 'costo' ? 'Costo por producción' : 'Ratio (kg químico / kg producción)',
                  color: '#64748b',
                },
              },
              x: {
                grid: {
                  display: false
                },
                ticks: {
                  callback: function(value) {
                    return formatSemana(this.getLabelForValue(value));
                  }
                },
                title: {
                  display: true,
                  text: 'Semana',
                  color: '#64748b',
                },
              },
            },
          }
        });

        actualizarCabeceraGrafica(modo);
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
        // Mostrar/ocultar columna de costo unitario anterior
        document.querySelectorAll('#pivotTable .col-costo-unit').forEach(el => {
          el.style.display = modo === 'costo' ? '' : 'none';
        });

        // Ampliar/restaurar la tabla al activar modo costo
        // Rompe el padding del cards-section para ganar espacio extra
        const cardsSection = table.closest('.cards-section');
        const wrapper = table.closest('.table-wrapper-pivot');
        if (modo === 'costo') {
          if (cardsSection) {
            cardsSection.style.marginLeft = '-80px';
            cardsSection.style.marginRight = '-80px';
            cardsSection.style.borderRadius = '0';
          }
          if (wrapper) {
            wrapper.style.marginLeft = '0';
            wrapper.style.marginRight = '0';
          }
          table.style.minWidth = '';
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
          table.style.minWidth = '';
        }
        // Cambiar valores en celdas de semanas
        const celdas = table.querySelectorAll('td.cell-ratio-semaforo');
        celdas.forEach(celda => {
          const ratioValue = celda.querySelector('.ratio-value strong');
          const ratioBase = celda.querySelector('.ratio-base-cell');
          const badge = celda.querySelector('.status-badge.status-badge-ratio');

          // Guardar HTML original del badge antes de la primera modificación
          if (badge && !celda.hasAttribute('data-badge-original')) {
            celda.setAttribute('data-badge-original', badge.innerHTML);
            celda.setAttribute('data-badge-style-original', badge.getAttribute('style') || '');
            celda.setAttribute('data-badge-title-original', badge.getAttribute('title') || '');
          }

          if (modo === 'costo') {
            const costoProduccion = parseFloat(celda.getAttribute('data-costo-produccion'));
            if (!isNaN(costoProduccion) && costoProduccion > 0) {
              ratioValue.textContent = '$' + costoProduccion.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              ratioBase.style.display = 'none';
            } else {
              ratioValue.textContent = '-';
              ratioBase.style.display = 'none';
            }

            // Actualizar badge según costo por producción semanal vs año anterior
            if (badge) {
              const row = celda.closest('tr[data-quimico]');
              const costoProduccionAnterior = row ? parseFloat(row.getAttribute('data-costo-produccion-anterior')) : NaN;
              let badgeColor, badgeLabel;
              if (!isNaN(costoProduccion) && costoProduccion > 0 && !isNaN(costoProduccionAnterior) && costoProduccionAnterior > 0) {
                const variacionUnit = (costoProduccion - costoProduccionAnterior) / costoProduccionAnterior * 100;
                badgeColor = variacionUnit > 6 ? '#ef4444' : (variacionUnit > 0 ? '#f59e0b' : '#10b981');
                badgeLabel = variacionUnit > 6 ? 'Alto' : (variacionUnit > 0 ? 'Cuidado' : 'Óptimo');
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
            // Modo consumo - mostrar ratio
            const ratio = parseFloat(celda.getAttribute('data-ratio'));
            if (!isNaN(ratio) && ratio !== null) {
              ratioValue.textContent = ratio.toLocaleString('es-MX', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
              });
              ratioBase.style.display = 'block';
            } else {
              ratioValue.textContent = '-';
              ratioBase.style.display = 'block';
            }

            // Restaurar badge original
            if (badge && celda.hasAttribute('data-badge-original')) {
              badge.innerHTML = celda.getAttribute('data-badge-original');
              badge.setAttribute('style', celda.getAttribute('data-badge-style-original'));
              badge.setAttribute('title', celda.getAttribute('data-badge-title-original'));
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
        actualizarKpisGenerales('consumo');
        renderWindow();
        renderPivotChart('consumo');
      });

      sortByCosto.addEventListener('click', function() {
        sortByCosto.classList.add('active');
        sortByConsumo.classList.remove('active');
        sortTable('costo');
        cambiarModoVisualizacion('costo');
        actualizarKpisGenerales('costo');
        renderWindow();
        renderPivotChart('costo');
      });

      // Aplicar ordenamiento inicial por consumo (mayor a menor)
      sortTable('consumo');
      actualizarKpisGenerales('consumo');
      renderPivotChart('consumo');
    }

    renderWindow();
    if (typeof requestAnimationFrame === 'function') {
      requestAnimationFrame(renderWindow);
    } else {
      setTimeout(renderWindow, 0);
    }
  })();
</script>
