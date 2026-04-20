<?php

/** @var int $anioPivot */
/** @var array $semanasCatalogo */
/** @var array $empaquessCatalogo */
/** @var array $empaquesEtiquetas */
/** @var array $matrizRatioEmpaques */
/** @var array $ratioBasePorEmpaque */
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

              <?php foreach ($semanasCatalogo as $index => $semana): ?>
                <?php
                $ratioEmpaque = $matrizRatioEmpaques[$empaque][$semana] ?? null;
                $baseEmpaque = $ratioBasePorEmpaque[$empaque] ?? null;
                [$estadoCelda, $colorCelda, $colorHexCelda] = semaforo($ratioEmpaque, $baseEmpaque, $toleranciaPct);
                ?>
                <td class="week-col cell-ratio-semaforo" data-week-index="<?= $index ?>">
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
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <td class="week-col" data-week-index="<?= $index ?>">
                <strong><?= n((float)($totalesPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row">
            <td class="sticky-col pivot-summary-col"><strong>PRODUCCION</strong></td>
            <?php foreach ($semanasCatalogo as $index => $semana): ?>
              <td class="week-col" data-week-index="<?= $index ?>">
                <strong><?= n((float)($produccionPorSemana[$semana] ?? 0), 2) ?></strong>
              </td>
            <?php endforeach; ?>
          </tr>

          <tr class="row-total summary-row row-ratio-semaforo">
            <td class="sticky-col pivot-summary-col"><strong>RATIO</strong></td>
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

    renderWindow();
  })();
</script>
