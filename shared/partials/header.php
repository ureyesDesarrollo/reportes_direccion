<?php

/** @var string $titulo */
/** @var string $fechaDesde */
/** @var int $anioAnterior */
/** @var array $meta */
/** @var string $modo */
/** @var string $urlVolverIndex */
/** @var bool $isGrupo */
/** @var string $grupoActual */
/** @var string $productoSeleccionado */
/** @var string $productoLabel */
/** @var array $productosQuimicos */
/** @var bool $showModeTabs */

$modoActual = $modo ?? ($meta['modo'] ?? ($_GET['modo'] ?? 'consumo'));
$showModeTabs = $showModeTabs ?? true;

$modoActual = $modo ?? ($meta['modo'] ?? ($_GET['modo'] ?? 'consumo'));

if ($isGrupo) {
  $badgeRatio = $meta['badgeRatio'] ?? (
    $modoActual === 'consumo'
    ? 'kg grupo / kg producidos'
    : ($modoActual === 'costo'
      ? 'Promedio del costo del grupo'
      : 'Impacto $ por kg producido')
  );
  $iconoPrincipal = $modoActual === 'costo'
    ? 'fa-dollar-sign'
    : ($modoActual === 'impacto' ? 'fa-sack-dollar' : 'fa-chart-line');

  if ($grupoActual === 'enzimas_preparacion') {
    $grupoLabel = 'Enzimas Preparación';
  } elseif ($grupoActual === 'enzimas_pelambre') {
    $grupoLabel = 'Enzimas Pelambre';
  } else {
    $grupoLabel = $grupoActual;
  }

  $queryBase = [
    'grupo' => $grupoActual,
  ];

  if ($productoSeleccionado !== null && $productoSeleccionado !== '') {
    $queryBase['producto'] = $productoSeleccionado;
  }
} else {
  $badgeRatio = $meta['badgeRatio'] ?? ($modoActual === 'consumo'
    ? 'kg químico / kg producidos'
    : ($modoActual === 'costo'
      ? 'Promedio del costo del químico'
      : 'Impacto $ por kg producido'));

  $iconoPrincipal = $modoActual === 'costo'
    ? 'fa-dollar-sign'
    : ($modoActual === 'impacto' ? 'fa-sack-dollar' : 'fa-chart-line');

  if ($productoLabel === null || $productoLabel === '') {
    $productoLabel = $productoSeleccionado;
  }

  $queryBase = [
    'producto' => $productoSeleccionado,
    'productoLabel' => $productoLabel,
  ];
}

$basePath = strtok($_SERVER['REQUEST_URI'], '?');

$urlConsumo = $basePath . '?' . http_build_query(array_merge($queryBase, ['modo' => 'consumo']));
$urlCosto = $basePath . '?' . http_build_query(array_merge($queryBase, ['modo' => 'costo']));
$urlImpacto = $basePath . '?' . http_build_query(array_merge($queryBase, ['modo' => 'impacto']));

?>
<!-- Loading Overlay -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="loading-spinner">
    <i class="fas fa-sync-alt"></i>
    <p>Actualizando datos...</p>
  </div>
</div>

<div class="dashboard">
  <div class="header">
    <div class="header-left">
      <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
        <a
          href="<?= htmlspecialchars($urlVolverIndex) ?>"
          class="back-btn"
          style="
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 14px;
            border-radius:999px;
            text-decoration:none;
            font-weight:700;
            border:1px solid #e2e8f0;
            background:#ffffff;
            color:#334155;
          ">
          <i class="fas fa-arrow-left"></i>
          Regresar al index
        </a>
      </div>

      <h1>
        <i class="fas <?= htmlspecialchars($iconoPrincipal) ?>" style="margin-right: 12px;"></i>
        <?= htmlspecialchars($titulo) ?>
      </h1>

      <div class="sub">
        <span>
          <i class="far fa-calendar-alt"></i>
          Desde: <?= htmlspecialchars($fechaDesde) ?>
        </span>

        <?php if ($isGrupo): ?>
          <span>
            <i class="fas fa-layer-group"></i>
            Grupo: <?= htmlspecialchars($grupoLabel) ?>
          </span>

          <span>
            <i class="fas fa-cube"></i>
            Productos: <?= count($productosQuimicos) ?>
          </span>

          <?php if ($productoSeleccionado !== null && $productoSeleccionado !== ''): ?>
            <span>
              <i class="fas fa-crosshairs"></i>
              Seleccionado: <?= htmlspecialchars($productoSeleccionado) ?>
            </span>
          <?php endif; ?>
        <?php else: ?>
          <span>
            <i class="fas fa-flask"></i>
            Producto: <?= htmlspecialchars($productoLabel) ?>
          </span>
        <?php endif; ?>

        <span class="badge">
          <i class="fas fa-chart-simple"></i>
          <?= htmlspecialchars($badgeRatio) ?>
        </span>

        <span class="year-badge">
          <i class="fas fa-calendar"></i>
          Año base: <?= htmlspecialchars((string)$anioAnterior) ?>
        </span>
      </div>

      <?php if ($showModeTabs): ?>
        <div class="mode-switch" style="margin-top: 14px; display: flex; gap: 10px; flex-wrap: wrap;">
          <a
            href="<?= htmlspecialchars($urlConsumo) ?>"
            class="mode-tab <?= $modoActual === 'consumo' ? 'active' : '' ?>"
            style="
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 14px;
            border-radius:999px;
            text-decoration:none;
            font-weight:700;
            border:1px solid <?= $modoActual === 'consumo' ? '#10b981' : '#e2e8f0' ?>;
            background:<?= $modoActual === 'consumo' ? 'rgba(16,185,129,0.10)' : '#ffffff' ?>;
            color:<?= $modoActual === 'consumo' ? '#047857' : '#334155' ?>;
          ">
            <i class="fas fa-weight-hanging"></i>
            Consumo
          </a>

          <a
            href="<?= htmlspecialchars($urlCosto) ?>"
            class="mode-tab <?= $modoActual === 'costo' ? 'active' : '' ?>"
            style="
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 14px;
            border-radius:999px;
            text-decoration:none;
            font-weight:700;
            border:1px solid <?= $modoActual === 'costo' ? '#3b82f6' : '#e2e8f0' ?>;
            background:<?= $modoActual === 'costo' ? 'rgba(59,130,246,0.10)' : '#ffffff' ?>;
            color:<?= $modoActual === 'costo' ? '#1d4ed8' : '#334155' ?>;
          ">
            <i class="fas fa-dollar-sign"></i>
            Costo
          </a>

          <a
            href="<?= htmlspecialchars($urlImpacto) ?>"
            class="mode-tab <?= $modoActual === 'impacto' ? 'active' : '' ?>"
            style="
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:10px 14px;
            border-radius:999px;
            text-decoration:none;
            font-weight:700;
            border:1px solid <?= $modoActual === 'impacto' ? '#f59e0b' : '#e2e8f0' ?>;
            background:<?= $modoActual === 'impacto' ? 'rgba(245,158,11,0.10)' : '#ffffff' ?>;
            color:<?= $modoActual === 'impacto' ? '#b45309' : '#334155' ?>;
          ">
            <i class="fas fa-sack-dollar"></i>
            Impacto
          </a>
        </div>
      <?php endif; ?>
    </div>

    <div class="header-right">
      <div class="update-panel">
        <div class="update-status">
          <span class="update-indicator" id="updateIndicator"></span>
          <span id="updateStatusText">Conectado</span>
        </div>

        <div class="update-timer" id="updateTimer">05:00</div>

        <button class="update-btn" id="manualUpdateBtn" onclick="manualUpdate()">
          <i class="fas fa-sync-alt"></i>
          Actualizar
        </button>

        <div class="auto-update-toggle">
          <span>
            <i class="fas fa-clock"></i>
            Auto
          </span>
          <label class="toggle-switch-small">
            <input type="checkbox" id="autoUpdateToggle" checked>
            <span class="slider-small"></span>
          </label>
        </div>

        <div class="last-update" id="lastUpdateTime">
          <i class="far fa-clock"></i>
          <span id="lastUpdateText"><?= date('H:i:s') ?></span>
        </div>
      </div>
    </div>
  </div>
