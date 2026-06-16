<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = $version ?? time();
$intervaloActualizacion = (int)($meta['intervaloActualizacion'] ?? 300000);
$periodo = (string)($periodo ?? 'dia');
$periodoRangoLabel = (string)($periodoRangoLabel ?? $periodoLabel ?? '');
$fechaSeleccionada = (string)($fechaSeleccionada ?? date('Y-m-d', strtotime('-1 day')));
$semanaSeleccionada = (string)($semanaSeleccionada ?? date('o-\WW'));
$mesSeleccionado = (string)($mesSeleccionado ?? date('Y-m'));
$formatPct = static fn(?float $value): string => $value === null ? '-' : n($value, 1) . '%';
$formatKg = static fn(float $value): string => n($value, 0) . ' kg';
$formatAvg = static fn(?float $value): string => $value === null ? '-' : n($value, 1);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/css/dashboard.css") ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/js/display-mode.js") ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    /* ============================================
       ESTILOS GLOBALES - VERSIÓN OPTIMIZADA
       Colores mejorados, KPI en una sola fila
    ============================================ */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: #f0f4f8;
      color: #1a2c3e;
      overflow-x: hidden;
    }

    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-track {
      background: #e2e8f0;
      border-radius: 10px;
    }

    ::-webkit-scrollbar-thumb {
      background: #94a3b8;
      border-radius: 10px;
    }

    .dashboard {
      max-width: 1600px;
      margin: 0 auto;
      padding: 24px 28px;
    }

    /* Header */
    .header {
      margin-bottom: 24px;
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      flex-wrap: wrap;
      gap: 16px;
    }

    .header-left {
      flex: 1;
    }

    .header h1 {
      font-size: 1.9rem;
      font-weight: 700;
      color: #0f2b3d;
      margin-bottom: 6px;
      letter-spacing: -0.3px;
    }

    .header h1 i {
      color: #2c7a4d;
      margin-right: 10px;
    }

    .header .sub {
      color: #5a6e7c;
      font-size: 0.85rem;
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .badge {
      background: #e6f0fa;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 0.7rem;
      color: #2c6e9e;
      border: 1px solid #cde3f5;
    }

    .year-badge {
      background: #2c7a4d;
      color: white;
      padding: 4px 14px;
      border-radius: 20px;
      font-size: 0.7rem;
      font-weight: 500;
    }

    /* Back button */
    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border-radius: 40px;
      text-decoration: none;
      font-weight: 500;
      font-size: 0.8rem;
      border: 1px solid #dce5ec;
      background: #ffffff;
      color: #3a5a78;
      transition: all 0.2s;
      margin-bottom: 12px;
    }

    .back-btn:hover {
      background: #f5f9ff;
      border-color: #b9cfec;
      transform: translateX(-2px);
    }

    /* KPI Cards - UNA SOLA FILA, optimizadas */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 28px;
    }

    .kpi-card {
      background: white;
      border-radius: 20px;
      padding: 14px 18px;
      border: none;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 2px rgba(0, 0, 0, 0.03);
      transition: all 0.2s ease;
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      gap: 12px;
      min-height: 68px;
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, #2c7a4d, #5a9e6e, #7abf8f);
      opacity: 0;
      transition: opacity 0.25s;
    }

    .kpi-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 24px -12px rgba(0, 0, 0, 0.12);
    }

    .kpi-card:hover::before {
      opacity: 1;
    }

    .kpi-icon {
      width: 38px;
      height: 38px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex: 0 0 auto;
      font-size: 1.2rem;
      margin-bottom: 0;
      background: #eaf4ef;
      color: #2c7a4d;
    }

    .kpi-label {
      flex: 1 1 auto;
      min-width: 0;
      font-size: 0.7rem;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      font-weight: 600;
      color: #6b7f8f;
      margin-bottom: 0;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .kpi-value {
      flex: 0 0 auto;
      font-size: 1.55rem;
      font-weight: 700;
      color: #1a3a4f;
      line-height: 1;
      white-space: nowrap;
      text-align: right;
    }

    /* Target Cards - colores mejorados */
    .target-grid {
      display: grid;
      gap: 16px;
      grid-template-columns: repeat(4, 1fr);
      margin-bottom: 32px;
    }

    .target-card {
      border-radius: 20px;
      padding: 16px 20px;
      border: 1px solid transparent;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
      position: relative;
      overflow: hidden;
      box-shadow: 0 8px 18px rgba(15, 23, 42, 0.12);
    }

    .target-card.ok {
      background: #2e8b57;
      color: white;
      border-color: #257447;
    }

    .target-card.attention {
      background: #c94436;
      color: white;
      border-color: #a9362c;
    }

    .target-card.over {
      background: #e49a32;
      color: white;
      border-color: #c47b1c;
    }

    .target-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 18px 30px -12px rgba(0, 0, 0, 0.2);
    }

    .target-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
    }

    .target-name {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .quality-dot {
      width: 32px;
      height: 32px;
      border-radius: 12px;
      border: 2px solid rgba(255, 255, 255, 0.6);
      box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
    }

    .target-name span {
      font-size: 0.95rem;
      font-weight: 700;
      letter-spacing: -0.2px;
    }

    .target-state-badge {
      background: rgba(255, 255, 255, 0.2) !important;
      backdrop-filter: blur(4px);
      border-radius: 40px;
      padding: 5px 12px;
      font-size: 0.7rem;
      font-weight: 700;
      border: none;
      color: white !important;
    }

    .target-value-container {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 8px;
    }

    .target-main-value {
      font-size: 2.2rem;
      font-weight: 800;
      line-height: 1;
    }

    .target-vs-meta {
      background: rgba(255, 255, 255, 0.18);
      border-radius: 60px;
      padding: 5px 12px;
      display: flex;
      gap: 6px;
      align-items: baseline;
    }

    .target-meta-label {
      font-size: 0.6rem;
      font-weight: 600;
      text-transform: uppercase;
      opacity: 0.85;
    }

    .target-meta-value {
      font-size: 0.85rem;
      font-weight: 700;
    }

    .target-delta {
      margin-top: 10px;
      background: rgba(0, 0, 0, 0.12);
      border-radius: 30px;
      padding: 4px 10px;
      font-size: 0.7rem;
      font-weight: 600;
      display: inline-block;
    }

    /* Filtros panel */
    .filters-panel {
      background: white;
      border-radius: 20px;
      padding: 18px 24px;
      margin-bottom: 28px;
      border: 1px solid #e2edf7;
      box-shadow: 0 1px 4px rgba(0, 0, 0, 0.02);
    }

    .filters-row {
      display: flex;
      gap: 24px;
      align-items: flex-end;
      flex-wrap: wrap;
    }

    .field-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      min-width: 160px;
    }

    .field-group label {
      font-size: 0.7rem;
      font-weight: 700;
      color: #5a6e7c;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .field-group input {
      border: 1px solid #dce7ef;
      border-radius: 14px;
      padding: 10px 14px;
      font-family: 'Inter', sans-serif;
      background: #fefefe;
      color: #1a2c3e;
      transition: all 0.2s;
    }

    .field-group input:focus {
      outline: none;
      border-color: #2c7a4d;
      box-shadow: 0 0 0 3px rgba(44, 122, 77, 0.1);
    }

    .period-tabs {
      display: inline-flex;
      background: #f0f4fa;
      border-radius: 60px;
      padding: 3px;
    }

    .period-tabs label {
      cursor: pointer;
    }

    .period-tabs input {
      position: absolute;
      opacity: 0;
      pointer-events: none;
    }

    .period-tabs span {
      display: block;
      padding: 8px 22px;
      font-weight: 600;
      font-size: 0.8rem;
      border-radius: 60px;
      color: #5a6e7c;
      transition: all 0.2s;
    }

    .period-tabs input:checked+span {
      background: #2c7a4d;
      color: white;
      box-shadow: 0 2px 6px rgba(44, 122, 77, 0.2);
    }

    [data-period-field] {
      display: none;
    }

    [data-period-field].active {
      display: flex;
    }

    /* Charts Grid */
    .charts-grid {
      display: grid;
      gap: 24px;
      grid-template-columns: repeat(2, 1fr);
      margin-bottom: 32px;
    }

    .chart-container {
      background: white;
      border-radius: 24px;
      border: 1px solid #e7edf4;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.03);
      overflow: hidden;
    }

    .chart-header {
      padding: 18px 24px;
      border-bottom: 1px solid #edf2f8;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
    }

    .chart-header h3 {
      font-size: 1rem;
      font-weight: 700;
      color: #1a3a4f;
    }

    .chart-header span {
      color: #7a8e9c;
      font-size: 0.75rem;
    }

    .chart-box {
      padding: 20px;
      height: 380px;
    }

    .chart-box canvas {
      width: 100% !important;
      height: 100% !important;
    }

    /* Fisicoquimicos mejorados */
    .physchem-section {
      margin-bottom: 28px;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-end;
      gap: 16px;
      margin-bottom: 16px;
      flex-wrap: wrap;
    }

    .section-title h2 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #1a3a4f;
      margin-bottom: 4px;
    }

    .section-title p {
      color: #6b7f8f;
      font-size: 0.75rem;
    }

    .section-pill {
      background: #ecf7f0;
      border-radius: 40px;
      padding: 8px 16px;
      font-size: 0.75rem;
      font-weight: 600;
      color: #2c7a4d;
    }

    .physchem-list {
      background: white;
      border-radius: 20px;
      border: 1px solid #e7edf4;
      overflow-x: auto;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
    }

    .physchem-table {
      width: 100%;
      min-width: 1180px;
      border-collapse: separate;
      border-spacing: 0;
      table-layout: fixed;
    }

    .physchem-table th,
    .physchem-table td {
      border-bottom: 1px solid #edf2f8;
      padding: 12px;
      text-align: left;
      vertical-align: middle;
    }

    .physchem-table th {
      background: #fafcff;
      color: #4a6272;
      font-size: 0.7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      position: sticky;
      top: 0;
      z-index: 5;
      white-space: nowrap;
    }

    .physchem-table th:first-child,
    .physchem-table td:first-child {
      position: sticky;
      left: 0;
      background: white;
      z-index: 4;
      width: 190px;
      min-width: 190px;
      max-width: 190px;
    }

    .physchem-table th:nth-child(2),
    .physchem-table td:nth-child(2) {
      position: sticky;
      left: 190px;
      background: white;
      z-index: 4;
      width: 150px;
      min-width: 150px;
      max-width: 150px;
    }

    .physchem-table th:first-child,
    .physchem-table th:nth-child(2) {
      background: #fafcff;
      z-index: 7;
    }

    .physchem-table th:nth-child(n+3),
    .physchem-table td:nth-child(n+3) {
      width: 150px;
      min-width: 150px;
      text-align: center;
    }

    .physchem-table tbody tr:nth-child(even) td {
      background: #fbfdff;
    }

    .physchem-table tbody tr:nth-child(even) td:first-child,
    .physchem-table tbody tr:nth-child(even) td:nth-child(2) {
      background: #fbfdff;
    }

    .physchem-param-head {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
      margin-bottom: 4px;
    }

    .physchem-param-cell strong {
      font-size: 0.9rem;
      color: #1a3a4f;
    }

    .physchem-alert-badge {
      background: #dc4c3a;
      color: white;
      border-radius: 30px;
      padding: 2px 8px;
      font-size: 0.6rem;
      font-weight: 700;
    }

    .physchem-range-pill {
      background: #f0f4fa;
      padding: 5px 12px;
      border-radius: 30px;
      font-size: 0.7rem;
      font-weight: 600;
      color: #3a5a78;
      display: inline-flex;
      max-width: 100%;
      white-space: normal;
      line-height: 1.25;
    }

    .physchem-week-title {
      display: grid;
      gap: 3px;
      justify-items: center;
      line-height: 1.2;
    }

    .physchem-week-title strong {
      font-size: 0.8rem;
      color: #1a3a4f;
    }

    .physchem-week-title span,
    .physchem-week-title small {
      display: block;
      font-size: 0.65rem;
      color: #6b7f8f;
      text-transform: none;
      letter-spacing: 0;
      white-space: normal;
    }

    .physchem-current {
      background: #e0f0e6;
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 0.6rem;
      font-weight: 600;
      color: #2c7a4d;
      display: inline-block;
      margin-top: 5px;
    }

    .physchem-value-cell {
      background: #ffffff;
      transition: all 0.1s;
    }

    .physchem-value-box {
      border-radius: 14px;
      padding: 8px 12px;
      background: #fafdff;
      border: 1px solid #e2edf7;
    }

    .physchem-value-box strong {
      font-size: 1rem;
      font-weight: 700;
      color: #1a3a4f;
      display: block;
    }

    .physchem-value-box span {
      font-size: 0.6rem;
      font-weight: 600;
      color: #7a8e9c;
      text-transform: uppercase;
    }

    .physchem-value-cell.verde .physchem-value-box,
    .physchem-value-cell.dentro .physchem-value-box {
      background: #eafaf0;
      border-color: #b8e0c6;
    }

    .physchem-value-cell.amarillo .physchem-value-box {
      background: #fff4e6;
      border-color: #ffdeb3;
    }

    .physchem-value-cell.rojo .physchem-value-box,
    .physchem-value-cell.fuera .physchem-value-box {
      background: #fee9e7;
      border-color: #fbc5bd;
    }

    .physchem-value-cell.rojo strong,
    .physchem-value-cell.fuera strong {
      color: #c73a2b;
    }

    /* Responsive */
    @media (max-width: 1100px) {
      .dashboard {
        padding: 20px;
      }
      .kpi-grid, .target-grid {
        gap: 12px;
      }
      .kpi-value {
        font-size: 1.6rem;
      }
      .target-main-value {
        font-size: 1.8rem;
      }
    }

    @media (max-width: 900px) {
      .kpi-grid, .target-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      .charts-grid {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 600px) {
      .kpi-grid, .target-grid {
        grid-template-columns: 1fr;
      }
    }

    /* Modo ejecutivo compacto */
    html.executive-display .dashboard {
      padding: 12px 16px;
    }

    html.executive-display .kpi-grid {
      gap: 10px;
      margin-bottom: 12px;
    }

    html.executive-display .kpi-card {
      padding: 12px 14px;
    }

    html.executive-display .kpi-icon {
      font-size: 1.2rem;
      margin-bottom: 6px;
    }

    html.executive-display .kpi-value {
      font-size: 1.5rem;
    }

    html.executive-display .target-grid {
      gap: 10px;
      margin-bottom: 16px;
    }

    html.executive-display .target-card {
      padding: 12px 14px;
    }

    html.executive-display .target-main-value {
      font-size: 1.6rem;
    }

    html.executive-display .target-name span {
      font-size: 0.8rem;
    }

    html.executive-display .chart-box {
      height: 240px;
      padding: 10px;
    }

    html.executive-display .physchem-table th,
    html.executive-display .physchem-table td {
      padding: 8px 10px;
    }
  </style>
</head>
<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <?php $isDireccionGeneral = (
          (isset($_GET['mode']) && (string)$_GET['mode'] === 'direccion-general') ||
          (isset($_GET['modo']) && (string)$_GET['modo'] === 'direccion-general')
        ); ?>
        <?php if ($isDireccionGeneral): ?>
          <a href="#" class="back-btn" onclick="history.back(); return false;"><i class="fas fa-arrow-left"></i> Regresar</a>
        <?php else: ?>
          <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Regresar al inicio</a>
        <?php endif; ?>
        <h1><i class="fas fa-chart-line"></i><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($periodoRangoLabel) ?></span>
          <span class="badge"><i class="fas fa-chart-pie"></i> Distribución y comportamiento</span>
          <span class="year-badge"><i class="fas fa-sync-alt"></i> cada <?= n($intervaloActualizacion / 60000, 0) ?> min</span>
        </div>
      </div>
    </div>

    <!-- Filtros -->
    <div class="filters-panel">
      <form method="get" id="qualityFilters">
        <div class="filters-row">
          <div class="field-group">
            <label>Periodo</label>
            <div class="period-tabs">
              <label><input type="radio" name="periodo" value="dia" <?= $periodo === 'dia' ? 'checked' : '' ?>><span>Día</span></label>
              <label><input type="radio" name="periodo" value="semana" <?= $periodo === 'semana' ? 'checked' : '' ?>><span>Semana</span></label>
              <label><input type="radio" name="periodo" value="mes" <?= $periodo === 'mes' ? 'checked' : '' ?>><span>Mes</span></label>
            </div>
          </div>
          <div class="field-group" data-period-field="dia" style="display: none;">
            <label for="fecha">Día</label>
            <input type="date" id="fecha" name="fecha" value="<?= htmlspecialchars($fechaSeleccionada) ?>">
          </div>
          <div class="field-group" data-period-field="semana" style="display: none;">
            <label for="semana">Semana</label>
            <input type="week" id="semana" name="semana" value="<?= htmlspecialchars($semanaSeleccionada) ?>">
          </div>
          <div class="field-group" data-period-field="mes" style="display: none;">
            <label for="mes">Mes</label>
            <input type="month" id="mes" name="mes" value="<?= htmlspecialchars($mesSeleccionado) ?>">
          </div>
        </div>
      </form>
    </div>

    <!-- KPI Cards - Una sola fila -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-pallet"></i></div>
        <div class="kpi-label">Tarimas</div>
        <div class="kpi-value"><?= n((float)($kpis['tarimas'] ?? 0), 0) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-weight-hanging"></i></div>
        <div class="kpi-label">Kilos</div>
        <div class="kpi-value"><?= $formatKg((float)($kpis['kilos'] ?? 0)) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-chart-line"></i></div>
        <div class="kpi-label">Bloom promedio</div>
        <div class="kpi-value"><?= n($kpis['bloom_promedio'] ?? null, 1) ?></div>
      </div>
      <div class="kpi-card">
        <div class="kpi-icon"><i class="fas fa-tint"></i></div>
        <div class="kpi-label">Viscosidad promedio</div>
        <div class="kpi-value"><?= n($kpis['viscosidad_promedio'] ?? null, 1) ?></div>
      </div>
    </div>

    <!-- Target Cards (Calidades) -->
    <div class="target-grid">
      <?php foreach ($resumenCalidades as $item): ?>
        <?php if (empty($item['semaforo'])) continue; ?>
        <?php
        $estadoKey = (string)($item['estado_key'] ?? 'gris');
        $targetCardClass = 'target-card ok';
        if ($estadoKey === 'rojo') $targetCardClass = 'target-card attention';
        elseif ($estadoKey === 'amarillo') $targetCardClass = 'target-card over';
        
        $porcentaje = (float)$item['porcentaje'];
        $qualityColor = (string)($item['color'] ?? '#64748b');
        $rangoVerde = (string)($item['rango_verde_label'] ?? '-');
        ?>
        <div class="<?= htmlspecialchars($targetCardClass) ?>">
          <div class="target-head">
            <div class="target-name">
              <div class="quality-dot" style="background: <?= htmlspecialchars($qualityColor) ?>;"></div>
              <span><?= htmlspecialchars((string)$item['calidad']) ?></span>
            </div>
            <div class="target-state-badge"><?= htmlspecialchars((string)$item['estado']) ?></div>
          </div>
          <div class="target-value-container">
            <div class="target-main-value"><?= $formatPct($porcentaje) ?></div>
            <div class="target-vs-meta">
              <span class="target-meta-label">Verde</span>
              <span class="target-meta-value"><?= htmlspecialchars($rangoVerde) ?></span>
            </div>
          </div>
          <?php if ($estadoKey !== 'verde'): ?>
            <div class="target-delta">Fuera del rango verde</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
      <div class="chart-container">
        <div class="chart-header">
          <h3><i class="fas fa-chart-donut"></i> Distribución por calidad</h3>
          <span>Por tarimas</span>
        </div>
        <div class="chart-box"><canvas id="qualityPie"></canvas></div>
      </div>
      <div class="chart-container">
        <div class="chart-header">
          <h3><i class="fas fa-chart-line"></i> Comportamiento por calidad</h3>
          <span>% sobre tarimas</span>
        </div>
        <div class="chart-box"><canvas id="qualityTrend"></canvas></div>
      </div>
    </div>

    <!-- Fisicoquimicos -->
    <?php
    $fisicoquimicos = (array)($fisicoquimicos ?? []);
    $fisicoSemanas = (array)($fisicoquimicos['semanas'] ?? []);
    $fisicoParametros = (array)($fisicoquimicos['parametros'] ?? []);
    $fisicoAlertas = (int)($fisicoquimicos['alertas'] ?? 0);
    ?>
    <section class="physchem-section">
      <div class="section-header">
        <div class="section-title">
          <h2>Análisis Fisicoquímico</h2>
          <p><?= htmlspecialchars((string)($fisicoquimicos['ventana_label'] ?? 'Periodo seleccionado + 4 anteriores')) ?></p>
        </div>
      </div>
      <div class="physchem-list">
        <table class="physchem-table">
          <thead>
            <tr>
              <th>Parámetro</th>
              <th>Rango verde</th>
              <?php foreach ($fisicoSemanas as $semana): ?>
                <th>
                  <div class="physchem-week-title">
                    <strong><?= htmlspecialchars((string)($semana['label'] ?? '')) ?></strong>
                    <span><?= htmlspecialchars((string)($semana['rango'] ?? '')) ?></span>
                    <small><?= n((float)($semana['tarimas'] ?? 0), 0) ?> tarimas</small>
                    <?php if (!empty($semana['actual'])): ?>
                      <div class="physchem-current">Seleccionado</div>
                    <?php endif; ?>
                  </div>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($fisicoParametros as $parametro): ?>
              <tr>
                <td class="physchem-param-cell"><div class="physchem-param-head"><strong><?= htmlspecialchars((string)($parametro['nombre'] ?? '')) ?></strong><?php if (((int)($parametro['alertas'] ?? 0)) > 0): ?><em class="physchem-alert-badge"><?= n((float)$parametro['alertas'], 0) ?></em><?php endif; ?></div><span><?= htmlspecialchars((string)($parametro['codigo'] ?? $parametro['campo'] ?? '')) ?></span></td>
                <td><span class="physchem-range-pill"><?php if (($parametro['rango_label'] ?? '') !== ''): ?>Verde: <?= htmlspecialchars((string)$parametro['rango_label']) ?><?php elseif (($parametro['inicio'] ?? null) !== null && ($parametro['fin'] ?? null) !== null): ?><?= $formatAvg((float)$parametro['inicio']) ?> - <?= $formatAvg((float)$parametro['fin']) ?><?php else: ?>Sin rango<?php endif; ?></span></td>
                <?php foreach ((array)($parametro['semanas'] ?? []) as $semanaParametro): ?>
                  <?php $estadoKey = (string)($semanaParametro['estado_key'] ?? 'sin-dato'); ?>
                  <td class="physchem-value-cell <?= htmlspecialchars($estadoKey) ?>"><div class="physchem-value-box"><strong><?= $formatAvg(isset($semanaParametro['promedio']) ? (float)$semanaParametro['promedio'] : null) ?></strong><span><?= htmlspecialchars((string)($semanaParametro['estado'] ?? '')) ?></span></div></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <script>
    (() => {
      const activePeriod = <?= json_encode($periodo) ?>;
      const form = document.getElementById('qualityFilters');
      const fields = document.querySelectorAll('[data-period-field]');
      const radios = document.querySelectorAll('input[name="periodo"]');
      
      const setActiveField = (period) => {
        fields.forEach((field) => {
          const isActive = field.dataset.periodField === period;
          field.classList.toggle('active', isActive);
          const input = field.querySelector('input');
          if (input) input.disabled = !isActive;
        });
      };
      
      const submitFilters = () => form.requestSubmit ? form.requestSubmit() : form.submit();
      
      radios.forEach((radio) => radio.addEventListener('change', () => { setActiveField(radio.value); submitFilters(); }));
      fields.forEach((field) => { const input = field.querySelector('input'); if (input) input.addEventListener('change', submitFilters); });
      setActiveField(activePeriod);

      const pieData = <?= json_encode($chartPie, JSON_UNESCAPED_UNICODE) ?>;
      const trendData = <?= json_encode($chartTrend, JSON_UNESCAPED_UNICODE) ?>;
      
      if (pieData.data && pieData.data.length) {
        new Chart(document.getElementById('qualityPie'), {
          type: 'doughnut',
          data: { labels: pieData.labels, datasets: [{ data: pieData.data, backgroundColor: pieData.colors, borderWidth: 0, hoverOffset: 8 }] },
          options: { maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } }, tooltip: { callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw} tarimas (${((ctx.raw / ctx.dataset.data.reduce((a,b)=>a+b,0))*100).toFixed(1)}%)` } } } }
        });
      }
      new Chart(document.getElementById('qualityTrend'), {
        type: 'line', data: { labels: trendData.labels || [], datasets: trendData.datasets || [] },
        options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v) => v + '%' } } }, plugins: { tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label}: ${ctx.raw}%` } }, legend: { position: 'bottom' } } }
      });

      const interval = <?= json_encode($intervaloActualizacion) ?>;
      if (interval > 0) setTimeout(() => window.location.reload(), interval);
    })();
  </script>
</body>
</html>
