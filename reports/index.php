<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$registry = require __DIR__ . '/../config/reports_registry.php';
$groupCatalog = (array)($registry['groups'] ?? []);
$reports = (array)($registry['reports'] ?? []);
$reports = array_values(array_filter($reports, fn($r) => !isset($r['enabled']) || $r['enabled'] === true));
$selectedGroupKey = trim((string)($_GET['grupo'] ?? ''));
$currentMode = trim((string)($_GET['modo'] ?? ($_GET['mode'] ?? '')));
$modeKey = isset($_GET['mode']) ? 'mode' : 'modo';

function e(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function isExternalUrl(string $value): bool
{
  return preg_match('/^https?:\/\//i', $value) === 1;
}

function groupVisibleInMode(array $groupMeta, string $mode, string $groupKey = ''): bool
{
  $visibleModes = array_values(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    (array)($groupMeta['visible_modes'] ?? [])
  )));
  $hiddenModes = array_values(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    (array)($groupMeta['hidden_modes'] ?? [])
  )));

  if ($mode !== '' && in_array($mode, $hiddenModes, true)) {
    return false;
  }

  // Si modo es vacio, mostrar grupos sin visible_modes
  if ($mode === '') {
    return empty($visibleModes);
  }

  // Si modo es 'muro', mostrar grupos sin visible_modes + grupos con 'muro'
  if ($mode === 'muro') {
    return empty($visibleModes) || in_array('muro', $visibleModes, true);
  }

  // Para otros modos (como 'direccion-general'), mostrar solo los grupos configurados para ese modo
  return in_array($mode, $visibleModes, true);
}

$groups = [];
foreach ($groupCatalog as $groupKey => $groupMeta) {
  if (!groupVisibleInMode((array)$groupMeta, $currentMode)) {
    continue;
  }

  $groups[(string)$groupKey] = [
    'key' => (string)$groupKey,
    'title' => (string)($groupMeta['title'] ?? 'Otros reportes'),
    'description' => (string)($groupMeta['description'] ?? 'Reportes disponibles.'),
    'icon' => (string)($groupMeta['icon'] ?? 'fa-folder-open'),
    'color' => (string)($groupMeta['color'] ?? '#475569'),
    'reports' => [],
  ];
}

foreach ($reports as $report) {
  $reportGroupKeys = (array)($report['groups'] ?? []);
  if (empty($reportGroupKeys)) {
    $reportGroupKeys = ['otros'];
  }

  foreach ($reportGroupKeys as $rawGroupKey) {
    $groupKey = trim((string)$rawGroupKey);
    if ($groupKey === '') {
      $groupKey = 'otros';
    }

    $groupMeta = (array)($groupCatalog[$groupKey] ?? []);
    if (!groupVisibleInMode($groupMeta, $currentMode)) {
      continue;
    }

    if (!isset($groups[$groupKey])) {
      $groups[$groupKey] = [
        'key' => $groupKey,
        'title' => (string)($groupMeta['title'] ?? 'Otros reportes'),
        'description' => (string)($groupMeta['description'] ?? 'Reportes disponibles.'),
        'icon' => (string)($groupMeta['icon'] ?? 'fa-folder-open'),
        'color' => (string)($groupMeta['color'] ?? '#475569'),
        'reports' => [],
      ];
    }

    $groups[$groupKey]['reports'][] = $report;
  }
}

$groups = array_values(array_filter($groups, static function (array $group): bool {
  return !empty($group['reports']);
}));

$selectedGroup = null;
if ($selectedGroupKey !== '') {
  foreach ($groups as $group) {
    if ((string)($group['key'] ?? '') === $selectedGroupKey) {
      $selectedGroup = $group;
      break;
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Centro de Reportes</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <script src="../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../assets/js/display-mode.js') ?: time())) ?>"></script>

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
      color: #0f172a;
    }

    .page {
      max-width: 1400px;
      margin: 0 auto;
      padding: 32px 24px 48px;
    }

    .hero {
      margin-bottom: 32px;
    }

    .hero h1 {
      font-size: 2.4rem;
      font-weight: 800;
      margin-bottom: 10px;
      background: linear-gradient(135deg, #0f172a 0%, #475569 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .hero p {
      color: #64748b;
      font-size: 1rem;
      max-width: 850px;
      line-height: 1.6;
    }

    .toolbar {
      display: flex;
      gap: 14px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .search-box {
      position: relative;
      flex: 1;
      min-width: 260px;
    }

    .search-box i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
    }

    .search-box input {
      width: 100%;
      padding: 12px 16px 12px 42px;
      border-radius: 999px;
      border: 1px solid #dbe2ea;
      background: #ffffff;
      font-size: 0.95rem;
      outline: none;
      transition: all 0.2s ease;
    }

    .search-box input:focus {
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.10);
    }

    .count-badge {
      background: #ffffff;
      border: 1px solid #dbe2ea;
      color: #475569;
      border-radius: 999px;
      padding: 10px 14px;
      font-size: 0.85rem;
      font-weight: 600;
      white-space: nowrap;
    }

    .display-mode-toggle {
      display: inline-flex;
      align-items: center;
      gap: 9px;
      border: 1px solid #dbe2ea;
      border-radius: 999px;
      background: #ffffff;
      color: #475569;
      padding: 10px 14px;
      font-size: 0.85rem;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s ease;
    }

    .display-mode-toggle i {
      color: #64748b;
    }

    .display-mode-toggle:hover {
      border-color: #93c5fd;
      color: #1d4ed8;
      box-shadow: 0 8px 18px rgba(37, 99, 235, 0.10);
      transform: translateY(-1px);
    }

    .display-mode-toggle.is-active {
      background: #1d4ed8;
      border-color: #1d4ed8;
      color: #ffffff;
      box-shadow: 0 10px 22px rgba(37, 99, 235, 0.18);
    }

    .display-mode-toggle.is-active i {
      color: #ffffff;
    }

    .group-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
      gap: 22px;
    }

    .group-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 24px;
      padding: 22px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
      transition: all 0.25s ease;
      display: flex;
      flex-direction: column;
      min-height: 180px;
    }

    .group-card:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 38px rgba(15, 23, 42, 0.10);
      border-color: #cbd5e1;
    }

    .group-card-top {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 18px;
    }

    .icon-wrap {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.35rem;
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
      flex-shrink: 0;
    }

    .group-card h2 {
      font-size: 1.1rem;
      font-weight: 700;
      line-height: 1.25;
      margin-bottom: 4px;
    }

    .group-meta {
      color: #94a3b8;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .group-description {
      color: #475569;
      font-size: 0.94rem;
      line-height: 1.6;
      margin-bottom: 18px;
      flex: 1;
    }

    .group-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: auto;
    }

    .group-toggle {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border: 0;
      border-radius: 999px;
      padding: 10px 16px;
      font-size: 0.9rem;
      font-weight: 700;
      color: white;
      cursor: pointer;
      transition: opacity 0.2s ease;
    }

    .group-toggle:hover {
      opacity: 0.9;
    }

    .reports-panel {
      grid-column: 1 / -1;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 24px;
      padding: 24px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
      display: none;
    }

    .reports-panel.active {
      display: block;
    }

    .panel-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 16px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }

    .panel-title {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .panel-title h3 {
      font-size: 1.2rem;
      font-weight: 800;
      margin-bottom: 4px;
    }

    .panel-title p {
      color: #64748b;
      font-size: 0.92rem;
      line-height: 1.5;
      max-width: 720px;
    }

    .panel-close {
      border: 1px solid #dbe2ea;
      background: #ffffff;
      color: #334155;
      border-radius: 999px;
      padding: 10px 14px;
      font-weight: 700;
      cursor: pointer;
    }

    .reports-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 18px;
    }

    .report-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 20px;
      padding: 18px;
      display: flex;
      flex-direction: column;
      min-height: 210px;
    }

    .report-card-top {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 14px;
    }

    .report-card h4 {
      font-size: 1rem;
      font-weight: 700;
      margin-bottom: 4px;
      line-height: 1.3;
    }

    .report-slug {
      color: #94a3b8;
      font-size: 0.78rem;
      font-weight: 600;
    }

    .report-description {
      color: #475569;
      font-size: 0.92rem;
      line-height: 1.55;
      margin-bottom: 16px;
      flex: 1;
    }

    .report-actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: auto;
    }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.82rem;
      color: #64748b;
      font-weight: 600;
    }

    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #10b981;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      border-radius: 999px;
      padding: 10px 16px;
      font-size: 0.9rem;
      font-weight: 700;
      color: white;
      transition: opacity 0.2s ease;
    }

    .btn:hover {
      opacity: 0.9;
    }

    .empty {
      background: #ffffff;
      border: 1px dashed #cbd5e1;
      border-radius: 22px;
      padding: 40px 24px;
      text-align: center;
      color: #64748b;
    }

    .empty i {
      font-size: 2.4rem;
      color: #cbd5e1;
      margin-bottom: 12px;
    }

    .footer-note {
      margin-top: 28px;
      color: #64748b;
      font-size: 0.85rem;
      line-height: 1.6;
    }

    @media (min-width: 1800px) {
      .page {
        max-width: min(96vw, 2360px);
        padding: 40px 48px 60px;
      }

      .hero {
        margin-bottom: 38px;
      }

      .hero h1 {
        font-size: 3rem;
      }

      .hero p {
        max-width: 1080px;
        font-size: 1.08rem;
      }

      .toolbar {
        margin-bottom: 30px;
      }

      .search-box input {
        padding: 14px 18px 14px 46px;
        font-size: 1rem;
      }

      .count-badge {
        font-size: 0.95rem;
        padding: 12px 16px;
      }

      .group-grid {
        grid-template-columns: repeat(auto-fill, minmax(430px, 1fr));
        gap: 26px;
      }

      .reports-grid {
        grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
        gap: 18px;
      }

      .group-card,
      .report-card {
        border-radius: 26px;
      }

      .group-card {
        padding: 26px;
      }
    }

    @media (min-width: 2560px) {
      .page {
        max-width: min(94vw, 3000px);
        padding: 48px 64px 72px;
      }

      .group-grid {
        grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
        gap: 30px;
      }

      .reports-grid {
        grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
      }
    }

    @media (max-width: 768px) {
      .page {
        padding: 20px 16px 36px;
      }

      .hero h1 {
        font-size: 1.8rem;
      }

      .group-grid,
      .reports-grid {
        grid-template-columns: 1fr;
      }
    }

    html.executive-display .page {
      max-width: none;
      width: 100%;
      padding: 14px 18px 20px;
    }

    html.executive-display .hero {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 14px;
    }

    html.executive-display .hero h1 {
      font-size: 1.55rem;
      line-height: 1.1;
      margin-bottom: 0;
    }

    html.executive-display .hero p,
    html.executive-display .footer-note {
      display: none;
    }

    html.executive-display .toolbar {
      margin-bottom: 12px;
      gap: 10px;
    }

    html.executive-display .search-box input {
      padding: 10px 14px 10px 40px;
      min-height: 42px;
      font-size: 0.86rem;
    }

    html.executive-display .count-badge,
    html.executive-display .back-link,
    html.executive-display .btn {
      padding: 9px 12px;
      font-size: 0.8rem;
    }

    html.executive-display .group-grid {
      grid-template-columns: repeat(auto-fit, minmax(245px, 1fr));
      gap: 10px;
    }

    html.executive-display .reports-grid {
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 10px;
    }

    html.executive-display .group-card,
    html.executive-display .report-card {
      border-radius: 14px;
      padding: 14px;
      min-height: auto;
    }

    html.executive-display .group-card-top,
    html.executive-display .report-card-top {
      gap: 10px;
      margin-bottom: 10px;
    }

    html.executive-display .icon-wrap {
      width: 40px;
      height: 40px;
      min-width: 40px;
      border-radius: 12px;
      font-size: 1rem;
    }

    html.executive-display .group-card h2,
    html.executive-display .report-card h2 {
      font-size: 1rem;
      line-height: 1.18;
    }

    html.executive-display .group-description,
    html.executive-display .report-description {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
      font-size: 0.78rem;
      line-height: 1.35;
      margin-bottom: 10px;
    }

    html.executive-display .group-actions,
    html.executive-display .report-actions {
      margin-top: 8px;
      gap: 8px;
    }

    html.executive-display .group-toggle,
    html.executive-display .report-link {
      padding: 8px 10px;
      font-size: 0.78rem;
    }
  </style>
</head>

<body>
  <div class="page">
    <section class="hero">
      <h1><i class="fas fa-table-columns" style="margin-right: 10px;"></i>Centro de Reportes</h1>
      <p>
        Selecciona el reporte que deseas consultar. Este índice te permite centralizar todos los dashboards
        y crecer el sistema sin depender de archivos sueltos.
      </p>
    </section>

    <section class="toolbar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchReports" placeholder="Buscar reporte...">
      </div>

      <div class="count-badge">
        <span id="reportCount"><?= $selectedGroup !== null ? count((array)($selectedGroup['reports'] ?? [])) : count($groups) ?></span>
        <?= $selectedGroup !== null ? 'sitio(s)' : 'grupo(s)' ?>
      </div>

      <button type="button" class="display-mode-toggle" id="displayModeToggle" aria-pressed="false">
        <i class="fas fa-display"></i>
        <span>Modo pantalla</span>
      </button>
    </section>

    <?php if ($selectedGroup !== null): ?>
      <section class="reports-panel active" id="reportsGrid">
        <div class="panel-header">
          <div class="panel-title">
            <div class="icon-wrap" style="background: <?= e($selectedGroup['color']) ?>; width: 48px; height: 48px; border-radius: 14px; font-size: 1.15rem;">
              <i class="fas <?= e($selectedGroup['icon']) ?>"></i>
            </div>
            <div>
              <h3><?= e($selectedGroup['title']) ?></h3>
              <p><?= e($selectedGroup['description']) ?></p>
            </div>
          </div>
          <?php $backGroupHref = './index.php' . ($currentMode !== '' ? ('?' . $modeKey . '=' . urlencode($currentMode)) : ''); ?>
          <a href="<?= e($backGroupHref) ?>" class="panel-close" style="text-decoration:none;">
            <i class="fas fa-arrow-left" style="margin-right: 8px;"></i>
            Regresar
          </a>
        </div>

        <div class="reports-grid">
          <?php foreach ($selectedGroup['reports'] as $report): ?>
            <?php
            $reportUrl = (string)($report['url'] ?? '#');
            $isExternalReport = isExternalUrl($reportUrl) || !empty($report['external']);
            // Preserve current mode when opening internal reports from group view
            $reportHref = $reportUrl;
            if (!$isExternalReport && $currentMode !== '') {
              $reportHref .= (strpos($reportUrl, '?') === false ? '?' : '&') . $modeKey . '=' . urlencode($currentMode);
            }

            $reportCtaLabel = trim((string)($report['cta_label'] ?? ''));
            if ($reportCtaLabel === '') {
              $reportCtaLabel = $isExternalReport ? 'Ir al sitio' : 'Ver reporte';
            }
            ?>
            <article class="report-card report-item-card" data-search="<?= e(mb_strtolower(($report['title'] ?? '') . ' ' . ($report['description'] ?? '') . ' ' . ($report['slug'] ?? ''))) ?>">
              <div class="report-card-top">
                <div class="icon-wrap" style="background: <?= e($report['color'] ?? '#10b981') ?>; width: 50px; height: 50px; border-radius: 14px; font-size: 1.2rem;">
                  <i class="fas <?= e($report['icon'] ?? 'fa-chart-line') ?>"></i>
                </div>
                <div>
                  <h4><?= e($report['title'] ?? 'Reporte') ?></h4>
                  <div class="report-slug"><?= e($report['slug'] ?? '') ?></div>
                </div>
              </div>

              <div class="report-description">
                <?= e($report['description'] ?? 'Sin descripción.') ?>
              </div>

              <div class="report-actions">
                <div class="status">
                  <span class="status-dot"></span>
                  Disponible
                </div>

                <a
                  class="btn"
                  href="<?= e($reportHref) ?>"
                  style="background: <?= e($report['color'] ?? '#10b981') ?>;"
                  <?= $isExternalReport ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                  <?= e($reportCtaLabel) ?>
                  <i class="fas <?= $isExternalReport ? 'fa-arrow-up-right-from-square' : 'fa-arrow-right' ?>"></i>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <div class="empty" id="emptyState" style="display:none; margin-top:18px;">
          <i class="fas fa-folder-open"></i>
          <div>No se encontraron reportes con ese criterio.</div>
        </div>
      </section>
    <?php else: ?>
      <section class="group-grid" id="reportsGrid">
          <?php foreach ($groups as $group): ?>
          <?php
          $groupSearch = mb_strtolower($group['title'] . ' ' . $group['description'] . ' ' . implode(' ', array_map(
            static fn(array $report): string => (($report['title'] ?? '') . ' ' . ($report['description'] ?? '') . ' ' . ($report['slug'] ?? '')),
            $group['reports']
          )));
          ?>
          <article
            class="group-card report-group-card"
            data-group-key="<?= e($group['key']) ?>"
            data-search="<?= e($groupSearch) ?>">
            <div class="group-card-top">
              <div class="icon-wrap" style="background: <?= e($group['color'] ?? '#10b981') ?>;">
                <i class="fas <?= e($group['icon'] ?? 'fa-folder-open') ?>"></i>
              </div>
              <div>
                <h2><?= e($group['title']) ?></h2>
                <div class="group-meta"><?= count($group['reports']) ?> sitio(s)</div>
              </div>
            </div>

            <div class="group-description">
              <?= e($group['description']) ?>
            </div>

            <div class="group-actions">
              <div class="status">
                <span class="status-dot"></span>
                Disponible
              </div>

              <a
                class="group-toggle"
                href="./index.php?<?= e(http_build_query(array_filter([
                    'grupo' => (string)$group['key'],
                    $modeKey => $currentMode !== '' ? $currentMode : null,
                  ], static fn($value) => $value !== null && $value !== ''))) ?>"
                style="background: <?= e($group['color']) ?>; text-decoration:none;">
                Abrir
                <i class="fas fa-arrow-right"></i>
              </a>
            </div>
          </article>
          <?php endforeach; ?>

        <div class="empty" id="emptyState" style="display:none; grid-column: 1 / -1;">
          <i class="fas fa-folder-open"></i>
          <div>No se encontraron reportes con ese criterio.</div>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <script>
    (function() {
      const input = document.getElementById('searchReports');
      const groupCards = Array.from(document.querySelectorAll('.report-group-card'));
      const reportCards = Array.from(document.querySelectorAll('.report-item-card'));
      const count = document.getElementById('reportCount');
      const empty = document.getElementById('emptyState');
      const inGroupScreen = <?= $selectedGroup !== null ? 'true' : 'false' ?>;

      function filterReports() {
        const term = (input.value || '').trim().toLowerCase();
        let visible = 0;

        const cards = inGroupScreen ? reportCards : groupCards;
        cards.forEach(card => {
          const haystack = (card.dataset.search || '').toLowerCase();
          const show = term === '' || haystack.includes(term);
          card.style.display = show ? '' : 'none';
          if (show) visible++;
        });

        count.textContent = visible;
        empty.style.display = visible === 0 ? '' : 'none';
      }

      input.addEventListener('input', filterReports);
    })();

    (function() {
      const button = document.getElementById('displayModeToggle');
      if (!button) return;

      const label = button.querySelector('span');

      function syncButton(mode) {
        const active = mode === 'executive';
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (label) {
          label.textContent = active ? 'Modo pantalla activo' : 'Modo pantalla';
        }
      }

      syncButton(document.documentElement.dataset.displayMode || 'normal');

      button.addEventListener('click', function() {
        if (window.ReportDisplayMode && typeof window.ReportDisplayMode.toggle === 'function') {
          syncButton(window.ReportDisplayMode.toggle());
        }
      });

      window.addEventListener('report-display-mode-change', function(event) {
        syncButton(event.detail && event.detail.mode ? event.detail.mode : 'normal');
      });
    })();
  </script>
</body>

</html>
