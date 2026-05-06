<?php

/** @var int $anio_anterior */
/** @var int $anio_actual */
/** @var string $mes_corte_label */
/** @var array $clientes_comparativa */

$detailBaseUrl = './index.php';

$formatPct = static function (?float $value): string {
  if ($value === null) {
    return '-';
  }

  return ($value > 0 ? '+' : '') . n($value, 1) . '%';
};

$variationClass = static function (?float $value): string {
  if ($value === null || $value == 0.0) {
    return 'ventas-var-neutral';
  }

  return $value < 0 ? 'ventas-var-negative' : 'ventas-var-positive';
};

$semaforoGrupo = static function (string $estado): string {
  if (in_array($estado, ['Crecimiento', 'Estable'], true)) {
    return 'verde';
  }

  if (in_array($estado, ['Nuevo', 'Atencion'], true)) {
    return 'amarillo';
  }

  if (in_array($estado, ['Declive', 'Inactivo'], true)) {
    return 'rojo';
  }

  return 'gris';
};

$tiposFiltro = array_values(array_unique(array_filter(
  array_map(static fn(array $cliente): string => (string)($cliente['tipo_cliente'] ?? ''), $clientes_comparativa),
  static fn(string $tipo): bool => $tipo !== ''
)));
sort($tiposFiltro);

$vendedoresFiltro = [];
foreach ($clientes_comparativa as $cliente) {
  foreach ((array)($cliente['vendedores'] ?? []) as $vendedor) {
    $vendedor = trim((string)$vendedor);
    if ($vendedor !== '') {
      $vendedoresFiltro[$vendedor] = $vendedor;
    }
  }
}
$vendedoresFiltro = array_values($vendedoresFiltro);
sort($vendedoresFiltro);

$filterToken = static function (string $value): string {
  $value = trim($value);
  $value = function_exists('mb_strtolower')
    ? mb_strtolower($value, 'UTF-8')
    : strtolower($value);

  return str_replace('|', ' ', $value);
};
?>
<div class="filters">
  <div class="search-box">
    <i class="fas fa-search"></i>
    <input type="text" id="ventasClienteSearch" placeholder="Buscar cliente, tipo o vendedor...">
  </div>

  <label class="filter-select-wrap" for="ventasTipoFilter">
    <span>
      <i class="fas fa-tag"></i>
      Tipo de cliente
    </span>
    <select id="ventasTipoFilter" class="filter-select" aria-label="Tipo de cliente">
      <option value="all">Todos</option>
      <?php foreach ($tiposFiltro as $tipoFiltro): ?>
        <option value="<?= htmlspecialchars($tipoFiltro) ?>"><?= htmlspecialchars($tipoFiltro) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="filter-select-wrap" for="ventasVendedorFilter">
    <span>
      <i class="fas fa-user-tie"></i>
      Vendedor
    </span>
    <select id="ventasVendedorFilter" class="filter-select" aria-label="Vendedor">
      <option value="all">Todos</option>
      <?php foreach ($vendedoresFiltro as $vendedorFiltro): ?>
        <option value="<?= htmlspecialchars($filterToken($vendedorFiltro)) ?>"><?= htmlspecialchars($vendedorFiltro) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="filter-select-wrap" for="ventasOrigenFilter">
    <span>
      <i class="fas fa-file-lines"></i>
      Origen
    </span>
    <select id="ventasOrigenFilter" class="filter-select" aria-label="Origen de venta">
      <option value="all">Todos</option>
      <option value="facturado">Facturados</option>
      <option value="remisionado">Remisionados</option>
    </select>
  </label>

  <div class="filter-buttons">
    <button type="button" class="filter-btn active" data-filter="all">Todos</button>
    <button type="button" class="filter-btn" data-filter="verde">Verde</button>
    <button type="button" class="filter-btn" data-filter="amarillo">Amarillo</button>
    <button type="button" class="filter-btn" data-filter="rojo">Rojo</button>
  </div>
</div>

<div class="cards-section">
  <div class="pivot-header">
    <div>
      <h2>
        <i class="fas fa-users"></i>
        Ventas por cliente
      </h2>
      <div class="cards-sub">
        Semaforo por frecuencia anual de venta: facturas no canceladas y remisiones validas por cliente. El acumulado a <?= htmlspecialchars($mes_corte_label) ?> queda como referencia operativa.
      </div>
    </div>

    <div class="pivot-controls">
      <div class="sort-buttons">
        <button type="button" class="sort-btn active" data-sort="compras">
          <i class="fas fa-receipt"></i>
          Ventas
        </button>
        <button type="button" class="sort-btn" data-sort="ventas">
          <i class="fas fa-dollar-sign"></i>
          Importe
        </button>
        <button type="button" class="sort-btn" data-sort="riesgo">
          <i class="fas fa-triangle-exclamation"></i>
          Riesgo
        </button>
        <button type="button" class="sort-btn" data-sort="mora">
          <i class="fas fa-file-invoice-dollar"></i>
          Mora
        </button>
        <button type="button" class="sort-btn" data-sort="estado">
          <i class="fas fa-traffic-light"></i>
          Estado
        </button>
        <button type="button" class="sort-btn" data-sort="tipo">
          <i class="fas fa-tag"></i>
          Tipo
        </button>
      </div>
    </div>
  </div>

  <div class="pivot-legend">
    <div class="legend-item-card">
      <span class="dot" style="background:#10b981;"></span>
      Crecimiento / Estable
    </div>
    <div class="legend-item-card">
      <span class="dot" style="background:#f59e0b;"></span>
      Nuevo / Atencion
    </div>
    <div class="legend-item-card">
      <span class="dot" style="background:#ef4444;"></span>
      Declive / Inactivo
    </div>
    <div class="legend-item-card">
      <span class="dot" style="background:#94a3b8;"></span>
      Sin dato
    </div>
  </div>

  <div class="table-wrapper ventas-clientes-table">
    <table>
      <colgroup>
        <col class="ventas-col-cliente">
        <col class="ventas-col-tipo">
        <col class="ventas-col-estado">
        <col class="ventas-col-cartera">
        <col class="ventas-col-mes">
        <col class="ventas-col-acumulado">
        <col class="ventas-col-anual">
        <col class="ventas-col-precio-anterior">
        <col class="ventas-col-ventas">
      </colgroup>
      <thead>
        <tr>
          <th>Cliente</th>
          <th style="text-align: center;">Tipo</th>
          <th style="text-align: center;">Semaforo</th>
          <th style="text-align: center;">Vencido</th>
          <th>Ventas <?= htmlspecialchars($mes_corte_label) ?></th>
          <th>Ventas acum.</th>
          <th>Ventas anuales</th>
          <th style="text-align: right;">Precio kg <?= htmlspecialchars((string)$anio_anterior) ?></th>
          <th style="text-align: right;">Importe / KG <?= htmlspecialchars((string)$anio_actual) ?></th>
        </tr>
      </thead>
      <tbody id="ventasClientesTableBody">
        <?php if (empty($clientes_comparativa)): ?>
          <tr id="ventasClientesEmptyRow">
            <td colspan="9">
              <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No hay ventas para este periodo.</p>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($clientes_comparativa as $cliente): ?>
            <?php
            $grupo = $semaforoGrupo((string)$cliente['estado']);
            $estadoCartera = (string)($cliente['estado_cartera'] ?? 'Pagado');
            $vendedoresCliente = array_values(array_filter(
              array_map(static fn($vendedor): string => trim((string)$vendedor), (array)($cliente['vendedores'] ?? [])),
              static fn(string $vendedor): bool => $vendedor !== ''
            ));
            $vendedoresTexto = implode(', ', $vendedoresCliente);
            $vendedoresPreview = implode(', ', array_slice($vendedoresCliente, 0, 2));
            if (count($vendedoresCliente) > 2) {
              $vendedoresPreview .= ' +' . (count($vendedoresCliente) - 2);
            }
            $vendedoresTokens = $vendedoresCliente !== []
              ? '|' . implode('|', array_map($filterToken, $vendedoresCliente)) . '|'
              : '';
            $origenesCliente = array_values(array_filter(
              array_map(static fn($origen): string => trim((string)$origen), (array)($cliente['origenes'] ?? [])),
              static fn(string $origen): bool => $origen !== ''
            ));
            $origenesTokens = $origenesCliente !== []
              ? '|' . implode('|', array_map($filterToken, $origenesCliente)) . '|'
              : '';
            $searchText = mb_strtolower(
              (string)$cliente['nombre'] . ' ' . (string)$cliente['tipo_cliente'] . ' ' . $estadoCartera . ' ' . $vendedoresTexto,
              'UTF-8'
            );
            $precioPromedioKg = $cliente['precio_promedio_kg_actual'];
            $precioPromedioKgAnterior = $cliente['precio_promedio_kg_anio_anterior'];
            $proximaVenta = $cliente['proxima_venta_estimada'] ?? null;
            $diasProximaVenta = $cliente['dias_para_proxima_venta'];
            $diasProximaVentaSort = $diasProximaVenta !== null ? (int)$diasProximaVenta : 999999;
            $tieneProximaVentaCalculada = $diasProximaVenta !== null;
            $estadoCliente = (string)$cliente['estado'];
            $colorCartera = (string)($cliente['color_cartera'] ?? '#10b981');
            $saldoPendiente = (float)($cliente['saldo_pendiente'] ?? 0);
            $saldoVencido = (float)($cliente['saldo_vencido'] ?? 0);
            $facturasMorosas = (int)($cliente['facturas_morosas'] ?? 0);
            $diasVencidoMax = (int)($cliente['dias_vencido_max'] ?? 0);
            $diasCredito = (int)($cliente['dias_credito'] ?? 0);
            $esSano = in_array($estadoCliente, ['Crecimiento', 'Estable'], true);
            $esRiesgo = in_array($estadoCliente, ['Atencion', 'Declive', 'Inactivo'], true);
            $esMoroso = (bool)($cliente['es_moroso'] ?? false);
            $tieneVentaAcumulada = (int)$cliente['num_compras_actual'] > 0;
            $tieneVentaAnual = (int)$cliente['total_compras_anio_actual'] > 0;
            $tieneVariacionBaja = $cliente['variacion_acumulado'] !== null && (float)$cliente['variacion_acumulado'] < 0;
            ?>
            <tr
              class="data-row"
              data-semaforo="<?= htmlspecialchars($grupo) ?>"
              data-estado="<?= htmlspecialchars((string)$cliente['estado']) ?>"
              data-tipo="<?= htmlspecialchars((string)$cliente['tipo_cliente']) ?>"
              data-vendedor="<?= htmlspecialchars($vendedoresTokens) ?>"
              data-origen="<?= htmlspecialchars($origenesTokens) ?>"
              data-compras="<?= htmlspecialchars((string)$cliente['num_compras_actual']) ?>"
              data-ventas="<?= htmlspecialchars((string)$cliente['total_ventas_actual']) ?>"
              data-precio="<?= htmlspecialchars((string)($precioPromedioKg ?? 0)) ?>"
              data-riesgo="<?= htmlspecialchars((string)$cliente['meses_en_riesgo']) ?>"
              data-mora="<?= htmlspecialchars((string)$saldoVencido) ?>"
              data-saldo="<?= htmlspecialchars((string)$saldoPendiente) ?>"
              data-moroso="<?= $esMoroso ? '1' : '0' ?>"
              data-acumulado="<?= $tieneVentaAcumulada ? '1' : '0' ?>"
              data-variacion-baja="<?= $tieneVariacionBaja ? '1' : '0' ?>"
              data-con-venta="<?= $tieneVentaAnual ? '1' : '0' ?>"
              data-proxima="<?= $cliente['esta_para_proxima_venta'] ? '1' : '0' ?>"
              data-proxima-calculada="<?= $tieneProximaVentaCalculada ? '1' : '0' ?>"
              data-proxima-dias="<?= htmlspecialchars((string)$diasProximaVentaSort) ?>"
              data-sano="<?= $esSano ? '1' : '0' ?>"
              data-riesgo-kpi="<?= $esRiesgo ? '1' : '0' ?>"
              data-search="<?= htmlspecialchars($searchText) ?>">
              <td>
                <div class="ventas-client-cell">
                  <span class="dot" style="background: <?= htmlspecialchars((string)$cliente['color']) ?>;"></span>
                  <div>
                    <a
                      href="<?= htmlspecialchars($detailBaseUrl . '?cliente=' . urlencode((string)$cliente['cve_cliente'])) ?>"
                      class="pivot-link-detail"
                      title="Ver detalle del cliente">
                      <strong><?= htmlspecialchars((string)$cliente['nombre']) ?></strong>
                      <span class="pivot-link-icon"><i class="fas fa-arrow-up-right-from-square"></i></span>
                    </a>
                    <?php if ($vendedoresPreview !== ''): ?>
                      <div class="ventas-client-vendor">
                        <i class="fas fa-user-tie"></i>
                        <?= htmlspecialchars($vendedoresPreview) ?>
                      </div>
                    <?php endif; ?>
                    <?php if ($proximaVenta !== null): ?>
                      <div class="ventas-next-sale <?= $cliente['esta_para_proxima_venta'] ? 'ventas-next-sale-due' : '' ?>">
                        Próx: <?= htmlspecialchars((string)$proximaVenta) ?>
                        <?php if ($diasProximaVenta !== null): ?>
                          (<?= (int)$diasProximaVenta ?> d)
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td style="text-align: center;">
                <span class="badge ventas-type-badge">
                  <?= htmlspecialchars((string)$cliente['tipo_cliente']) ?>
                </span>
              </td>
              <td style="text-align: center;">
                <span class="status-badge" style="background: <?= htmlspecialchars((string)$cliente['color']) ?>; color: #ffffff;">
                  <span class="status-dot" style="background:#ffffff;"></span>
                  <?= htmlspecialchars((string)$cliente['estado']) ?>
                </span>
              </td>
              <td style="text-align: center;">
                <div class="ventas-credit-cell">
                  <span class="status-badge ventas-credit-badge" style="background: <?= htmlspecialchars($colorCartera) ?>; color: #ffffff;">
                    <span class="status-dot" style="background:#ffffff;"></span>
                    <?= htmlspecialchars($estadoCartera) ?>
                  </span>
                  <span>$<?= n($saldoVencido, 0) ?></span>
                  <span><?= n((float)$diasVencidoMax, 0) ?> d venc.</span>
                </div>
              </td>
              <td>
                <div class="ventas-metric-stack">
                  <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)$cliente['compras_mes_anterior'], 0) ?></span>
                  <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= n((float)$cliente['compras_mes_actual'], 0) ?></strong>
                  <span class="<?= htmlspecialchars($variationClass($cliente['variacion_mes_actual'])) ?>">
                    <?= htmlspecialchars($formatPct($cliente['variacion_mes_actual'])) ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="ventas-metric-stack">
                  <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)$cliente['num_compras_anterior'], 0) ?></span>
                  <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= n((float)$cliente['num_compras_actual'], 0) ?></strong>
                  <span class="<?= htmlspecialchars($variationClass($cliente['variacion_acumulado'])) ?>">
                    <?= htmlspecialchars($formatPct($cliente['variacion_acumulado'])) ?>
                  </span>
                </div>
              </td>
              <td>
                <div class="ventas-metric-stack">
                  <span><?= htmlspecialchars((string)$anio_anterior) ?>: <?= n((float)$cliente['total_compras_anio_anterior'], 0) ?></span>
                  <strong><?= htmlspecialchars((string)$anio_actual) ?>: <?= n((float)$cliente['total_compras_anio_actual'], 0) ?></strong>
                  <span class="<?= htmlspecialchars($variationClass($cliente['variacion_compras'])) ?>">
                    <?= htmlspecialchars($formatPct($cliente['variacion_compras'])) ?>
                  </span>
                </div>
              </td>
              <td style="text-align: right;">
                <div class="ventas-metric-stack ventas-metric-stack-right">
                  <strong><?= $precioPromedioKgAnterior !== null ? '$' . n((float)$precioPromedioKgAnterior, 2) : '-' ?></strong>
                  <span><?= n((float)$cliente['total_kg_anio_anterior'], 0) ?> kg</span>
                </div>
              </td>
              <td style="text-align: right;">
                <div class="ventas-metric-stack ventas-metric-stack-right">
                  <strong>$<?= n((float)$cliente['total_ventas_actual'], 0) ?></strong>
                  <span><?= n((float)$cliente['total_kg_actual'], 0) ?> kg</span>
                  <span><?= $precioPromedioKg !== null ? '$' . n((float)$precioPromedioKg, 2) . ' / kg' : '- / kg' ?></span>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr id="ventasClientesNoResults" style="display:none;">
            <td colspan="9">
              <div class="empty-state">
                <i class="fas fa-search"></i>
                <p>No se encontraron clientes con ese criterio.</p>
              </div>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="pagination" id="ventasClientesPagination">
    <button id="prevPageVentasClientes" type="button">
      <i class="fas fa-chevron-left"></i> Anterior
    </button>
    <div id="ventasClientesPageNumbers" class="page-numbers"></div>
    <button id="nextPageVentasClientes" type="button">
      Siguiente <i class="fas fa-chevron-right"></i>
    </button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const tbody = document.getElementById('ventasClientesTableBody');
  if (!tbody) return;

  const searchInput = document.getElementById('ventasClienteSearch');
  const tipoFilter = document.getElementById('ventasTipoFilter');
  const vendedorFilter = document.getElementById('ventasVendedorFilter');
  const origenFilter = document.getElementById('ventasOrigenFilter');
  const filterButtons = document.querySelectorAll('.filter-btn');
  const sortButtons = document.querySelectorAll('.sort-btn');
  const kpiCards = document.querySelectorAll('.ventas-kpi-filter');
  const noResultsRow = document.getElementById('ventasClientesNoResults');
  const pagination = document.getElementById('ventasClientesPagination');
  const prevBtn = document.getElementById('prevPageVentasClientes');
  const nextBtn = document.getElementById('nextPageVentasClientes');
  const pageNumbers = document.getElementById('ventasClientesPageNumbers');
  const rowsPerPage = Number((window.reportConfig && window.reportConfig.filasPorPagina) || 15);
  let activeFilter = 'all';
  let activeTipo = 'all';
  let activeVendedor = 'all';
  let activeOrigen = 'all';
  let activeKpi = 'all';
  let activeSort = 'compras';
  let currentPage = 0;
  window.ventasClientesSetKpiFilter = function(filter) {
    setKpiFilter(filter || 'all');
  };

  function normalize(value) {
    return String(value || '').toLowerCase();
  }

  function compareRows(a, b, sortType) {
    if (sortType === 'estado' || sortType === 'tipo') {
      return normalize(a.dataset[sortType]).localeCompare(normalize(b.dataset[sortType]));
    }

    if (sortType === 'proxima') {
      const nextA = parseFloat(a.dataset.proximaDias || '999999');
      const nextB = parseFloat(b.dataset.proximaDias || '999999');
      const sortableA = nextA < -30 ? 999999 + Math.abs(nextA) : nextA;
      const sortableB = nextB < -30 ? 999999 + Math.abs(nextB) : nextB;
      return (sortableA - sortableB)
        || (parseFloat(b.dataset.ventas || '0') - parseFloat(a.dataset.ventas || '0'));
    }

    const valueA = parseFloat(a.dataset[sortType] || '0');
    const valueB = parseFloat(b.dataset[sortType] || '0');
    return valueB - valueA;
  }

  function getMatchingRows() {
    const term = normalize(searchInput ? searchInput.value : '');
    const rows = Array.from(tbody.querySelectorAll('.data-row'));

    const matchingRows = rows.filter(row => {
      const matchesFilter = activeFilter === 'all' || row.dataset.semaforo === activeFilter;
      const matchesTipo = activeTipo === 'all' || row.dataset.tipo === activeTipo;
      const matchesVendedor = activeVendedor === 'all' || String(row.dataset.vendedor || '').includes('|' + activeVendedor + '|');
      const matchesOrigen = activeOrigen === 'all' || String(row.dataset.origen || '').includes('|' + activeOrigen + '|');
      const matchesKpi = activeKpi === 'all'
        || (activeKpi === 'acumulado' && row.dataset.acumulado === '1')
        || (activeKpi === 'variacion-baja' && row.dataset.variacionBaja === '1')
        || (activeKpi === 'con-venta' && row.dataset.conVenta === '1')
        || (activeKpi === 'anual' && row.dataset.conVenta === '1')
        || (activeKpi === 'proxima' && row.dataset.proximaCalculada === '1')
        || (activeKpi === 'sanos' && row.dataset.sano === '1')
        || (activeKpi === 'riesgo' && row.dataset.riesgoKpi === '1');
      const matchesSearch = !term || normalize(row.dataset.search).includes(term);
      return matchesFilter && matchesTipo && matchesVendedor && matchesOrigen && matchesKpi && matchesSearch;
    });

    const effectiveSort = activeKpi === 'proxima' ? 'proxima' : activeSort;
    matchingRows.sort((a, b) => compareRows(a, b, effectiveSort));
    return matchingRows;
  }

  function updateKpiCards() {
    kpiCards.forEach(card => {
      const isActive = activeKpi !== 'all' && card.dataset.kpiFilter === activeKpi;
      card.classList.toggle('active', isActive);
      card.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  }

  function resetTableFiltersForKpi(filter) {
    if (filter !== 'proxima') {
      return;
    }

    if (searchInput) {
      searchInput.value = '';
    }

    if (tipoFilter) {
      tipoFilter.value = 'all';
    }

    if (vendedorFilter) {
      vendedorFilter.value = 'all';
    }
    if (origenFilter) {
      origenFilter.value = 'all';
    }

    activeFilter = 'all';
    activeTipo = 'all';
    activeVendedor = 'all';
    activeOrigen = 'all';
    filterButtons.forEach(btn => {
      btn.classList.toggle('active', (btn.dataset.filter || 'all') === 'all');
    });
  }

  function setKpiFilter(filter) {
    activeKpi = filter === 'proxima'
      ? 'proxima'
      : (activeKpi === filter ? 'all' : filter);
    const shouldScrollToTable = ['proxima', 'sanos', 'riesgo'].includes(activeKpi);
    resetTableFiltersForKpi(activeKpi);
    currentPage = 0;
    updateKpiCards();

    if (activeKpi === 'proxima') {
      activeSort = 'proxima';
      sortButtons.forEach(btn => btn.classList.remove('active'));
      sortRows(activeSort);
    } else {
      applyFilters();
    }

    if (shouldScrollToTable) {
      const tableSection = tbody.closest('.cards-section');
      if (tableSection) {
        tableSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
  }

  function renderPagination(totalPages) {
    if (!pagination || !pageNumbers || !prevBtn || !nextBtn) return;

    pagination.style.display = totalPages > 1 ? '' : 'none';
    pageNumbers.innerHTML = '';

    const maxButtons = 5;
    let startPage = Math.max(0, currentPage - Math.floor(maxButtons / 2));
    let endPage = Math.min(totalPages - 1, startPage + maxButtons - 1);

    if (endPage - startPage + 1 < maxButtons) {
      startPage = Math.max(0, endPage - maxButtons + 1);
    }

    for (let page = startPage; page <= endPage; page++) {
      const btn = document.createElement('div');
      btn.className = 'page-number' + (page === currentPage ? ' active' : '');
      btn.textContent = String(page + 1);
      btn.addEventListener('click', function() {
        currentPage = page;
        applyFilters();
      });
      pageNumbers.appendChild(btn);
    }

    prevBtn.disabled = currentPage === 0;
    nextBtn.disabled = currentPage >= totalPages - 1;
  }

  function applyFilters() {
    const rows = Array.from(tbody.querySelectorAll('.data-row'));
    const matchingRows = getMatchingRows();
    const totalPages = Math.max(1, Math.ceil(matchingRows.length / rowsPerPage));

    if (currentPage > totalPages - 1) {
      currentPage = Math.max(0, totalPages - 1);
    }

    const start = currentPage * rowsPerPage;
    const end = start + rowsPerPage;
    const visibleRows = new Set(matchingRows.slice(start, end));

    rows.forEach(row => {
      row.style.display = visibleRows.has(row) ? '' : 'none';
    });

    if (noResultsRow) {
      noResultsRow.style.display = matchingRows.length === 0 && rows.length > 0 ? '' : 'none';
    }

    renderPagination(totalPages);
  }

  function sortRows(sortType) {
    const rows = Array.from(tbody.querySelectorAll('.data-row'));
    rows.sort((a, b) => compareRows(a, b, sortType));

    rows.forEach(row => tbody.appendChild(row));
    if (noResultsRow) tbody.appendChild(noResultsRow);
    applyFilters();
  }

  if (searchInput) {
    searchInput.addEventListener('input', function() {
      currentPage = 0;
      applyFilters();
    });
  }

  if (tipoFilter) {
    tipoFilter.addEventListener('change', function() {
      activeTipo = this.value || 'all';
      currentPage = 0;
      applyFilters();
    });
  }

  if (vendedorFilter) {
    vendedorFilter.addEventListener('change', function() {
      activeVendedor = this.value || 'all';
      currentPage = 0;
      applyFilters();
    });
  }

  if (origenFilter) {
    origenFilter.addEventListener('change', function() {
      activeOrigen = this.value || 'all';
      currentPage = 0;
      applyFilters();
    });
  }

  filterButtons.forEach(button => {
    button.addEventListener('click', function() {
      filterButtons.forEach(btn => btn.classList.remove('active'));
      this.classList.add('active');
      activeFilter = this.dataset.filter || 'all';
      currentPage = 0;
      applyFilters();
    });
  });

  sortButtons.forEach(button => {
    button.addEventListener('click', function() {
      sortButtons.forEach(btn => btn.classList.remove('active'));
      this.classList.add('active');
      activeSort = this.dataset.sort || 'compras';
      currentPage = 0;
      sortRows(activeSort);
    });
  });

  kpiCards.forEach(card => {
    card.addEventListener('click', function() {
      setKpiFilter(this.dataset.kpiFilter || 'all');
    });

    card.addEventListener('keydown', function(event) {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      event.preventDefault();
      setKpiFilter(this.dataset.kpiFilter || 'all');
    });
  });

  if (prevBtn) {
    prevBtn.addEventListener('click', function() {
      if (currentPage > 0) {
        currentPage--;
        applyFilters();
      }
    });
  }

  if (nextBtn) {
    nextBtn.addEventListener('click', function() {
      currentPage++;
      applyFilters();
    });
  }

  updateKpiCards();
  sortRows(activeSort);
});
</script>
