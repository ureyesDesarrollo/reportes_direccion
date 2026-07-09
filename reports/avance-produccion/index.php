<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
$config = require __DIR__ . '/config.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

extract($report, EXTR_SKIP);

$version = (int)($version ?? time());
$filtros = (array)($filtros ?? []);
$kpis = (array)($kpis ?? []);
$objetivos = (array)($objetivos ?? []);
$series = (array)($series ?? []);
$tablas = (array)($tablas ?? []);
$meta = (array)($meta ?? []);

$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 1): string => is_numeric($value) ? n((float)$value, $decimals) : '-';
$fmtInt = static fn($value): string => is_numeric($value) ? n((float)$value, 0) : '-';
$fmtPct = static fn($value, int $decimals = 2): string => is_numeric($value) ? n((float)$value, $decimals) . ' %' : '-';
$fmtKilos = static fn($value): string => is_numeric($value) ? n((float)$value, 0) : '-';

$dailyRows = (array)($series['diaria'] ?? []);
$barreduraRows = (array)($tablas['barredura'] ?? []);
$processRows = (array)($tablas['procesos'] ?? []);
$processTotal = (array)($tablas['procesos_total'] ?? []);
$objetivoDiario = (float)($objetivos['diario_toneladas'] ?? 20.0);
$objetivoPeriodo = max($objetivoDiario, (float)($objetivos['periodo_toneladas'] ?? 600.0));
$objetivoComparacion = max($objetivoDiario, (float)($objetivos['comparacion_toneladas'] ?? ($objetivos['acumulado_toneladas'] ?? $objetivoPeriodo)));
$objetivoTarimasPeriodo = max(0.0, (float)($objetivos['tarimas_periodo'] ?? 0.0));
$tarimasAmarilloMinPeriodo = max(0.0, (float)($objetivos['tarimas_amarillo_min_periodo'] ?? 0.0));
$totalToneladas = (float)($kpis['toneladas'] ?? 0.0);
$avancePct = $objetivoComparacion > 0 ? min(100, max(0, ($totalToneladas / $objetivoComparacion) * 100)) : 0.0;
$chartLabels = array_map(static fn(array $row): string => (string)($row['day'] ?? ''), $dailyRows);
$chartTons = array_map(static fn(array $row): float => round((float)($row['toneladas'] ?? 0), 2), $dailyRows);
$chartTarget = array_fill(0, count($chartLabels), round($objetivoDiario, 2));
$barreduraLabels = array_map(static fn(array $row): string => (string)($row['day'] ?? ''), $barreduraRows);
$barreduraData = array_map(static fn(array $row): float => round((float)($row['toneladas'] ?? 0), 2), $barreduraRows);
$semaforoRendimiento = static function ($value): string {
  if (!is_numeric($value)) {
    return '';
  }

  $number = (float)$value;
  if ($number < 16) {
    return 'avance-semaforo-rojo';
  }
  if ($number <= 17) {
    return 'avance-semaforo-amarillo';
  }

  return 'avance-semaforo-verde';
};
$semaforoTarimas = static function ($value): string {
  if (!is_numeric($value)) {
    return '';
  }

  $number = (float)$value;
  if ($number < 20) {
    return 'avance-semaforo-rojo';
  }
  if ($number < 22) {
    return 'avance-semaforo-amarillo';
  }

  return 'avance-semaforo-verde';
};
$semaforoTarimasPeriodo = static function ($value) use ($objetivoTarimasPeriodo, $tarimasAmarilloMinPeriodo): string {
  if (!is_numeric($value) || $objetivoTarimasPeriodo <= 0) {
    return '';
  }

  $number = (float)$value;
  if ($number < $tarimasAmarilloMinPeriodo) {
    return 'avance-semaforo-rojo';
  }
  if ($number < $objetivoTarimasPeriodo) {
    return 'avance-semaforo-amarillo';
  }

  return 'avance-semaforo-verde';
};
$semaforoProduccionPromedio = static function ($value): string {
  if (!is_numeric($value)) {
    return '';
  }

  $number = (float)$value;
  if ($number < 20) {
    return 'avance-semaforo-rojo';
  }
  if ($number < 22) {
    return 'avance-semaforo-amarillo';
  }

  return 'avance-semaforo-verde';
};
$semaforoFinos = static function ($value): string {
  if (!is_numeric($value)) {
    return '';
  }

  $number = (float)$value;
  if ($number < 19) {
    return 'avance-semaforo-verde';
  }
  if ($number <= 21) {
    return 'avance-semaforo-amarillo';
  }

  return 'avance-semaforo-rojo';
};
$semaforoDeficit = static function ($value): string {
  if (!is_numeric($value)) {
    return '';
  }

  return (float)$value <= 0 ? 'avance-semaforo-verde' : 'avance-semaforo-rojo';
};
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo ?? 'Avance Producción') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/css/dashboard.css') ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      background: #f4f7fb;
    }

    .avance-dashboard {
      max-width: 1600px;
      padding: 18px 28px 22px;
    }

    .avance-header {
      margin-bottom: 12px;
    }

    .avance-header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 10px;
    }

    .avance-header h1 {
      font-size: clamp(1.85rem, 2.7vw, 2.45rem);
    }

    .avance-filter-form {
      margin-bottom: 14px;
      border-radius: 20px;
      padding: 10px 16px;
    }

    .avance-filter-field {
      display: grid;
      gap: 6px;
      min-width: 150px;
    }

    .avance-filter-field label {
      color: #64748b;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .avance-filter-field .filter-select {
      min-width: 150px;
      min-height: 40px;
      padding-top: 9px;
      padding-bottom: 9px;
    }

    .avance-summary-grid {
      display: grid;
      grid-template-columns: minmax(250px, 1.25fr) repeat(4, minmax(150px, 1fr));
      gap: 12px;
      margin-bottom: 14px;
    }

    .avance-progress-card {
      grid-row: span 2;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 12px;
      min-height: 174px;
      padding: 16px 18px;
    }

    .avance-progress-main {
      display: grid;
      gap: 4px;
    }

    .avance-progress-main strong {
      color: #0f172a;
      font-size: clamp(2rem, 3.3vw, 2.9rem);
      line-height: 1;
      letter-spacing: 0;
    }

    .avance-progress-main span {
      color: #64748b;
      font-size: 0.85rem;
      font-weight: 600;
    }

    .avance-progress-track {
      height: 14px;
      overflow: hidden;
      border-radius: 999px;
      background: #e2e8f0;
    }

    .avance-progress-track span {
      display: block;
      width: var(--avance-pct);
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, #10b981, #2563eb);
    }

    .avance-progress-meta {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      color: #64748b;
      font-size: 0.78rem;
      font-weight: 700;
    }

    .avance-kpi-card {
      min-height: 80px;
      padding: 14px 16px;
    }

    .avance-kpi-card .kpi-icon {
      margin-bottom: 7px;
      font-size: 1.18rem;
    }

    .avance-kpi-card .kpi-value {
      margin-bottom: 0;
      font-size: clamp(1.45rem, 2.1vw, 2rem);
      line-height: 1.05;
      white-space: nowrap;
    }

    .avance-kpi-card .kpi-label {
      margin-bottom: 4px;
    }

    .avance-kpi-card .kpi-trend {
      margin-top: 8px;
    }

    .avance-kpi-card.danger .kpi-icon,
    .avance-kpi-card.danger .kpi-value {
      color: #ef4444;
    }

    .avance-kpi-card.warning .kpi-icon,
    .avance-kpi-card.warning .kpi-value {
      color: #f59e0b;
    }

    .avance-kpi-card.avance-semaforo-verde,
    .avance-kpi-card.avance-semaforo-amarillo,
    .avance-kpi-card.avance-semaforo-rojo {
      color: #ffffff;
    }

    .avance-kpi-card.avance-semaforo-verde::before,
    .avance-kpi-card.avance-semaforo-amarillo::before,
    .avance-kpi-card.avance-semaforo-rojo::before {
      opacity: 0;
    }

    .avance-kpi-card.avance-semaforo-verde .kpi-icon,
    .avance-kpi-card.avance-semaforo-verde .kpi-label,
    .avance-kpi-card.avance-semaforo-verde .kpi-value,
    .avance-kpi-card.avance-semaforo-verde .kpi-trend,
    .avance-kpi-card.avance-semaforo-verde .avance-progress-main strong,
    .avance-kpi-card.avance-semaforo-verde .avance-progress-main span,
    .avance-kpi-card.avance-semaforo-verde .avance-progress-meta span,
    .avance-kpi-card.avance-semaforo-amarillo .kpi-icon,
    .avance-kpi-card.avance-semaforo-amarillo .kpi-label,
    .avance-kpi-card.avance-semaforo-amarillo .kpi-value,
    .avance-kpi-card.avance-semaforo-amarillo .kpi-trend,
    .avance-kpi-card.avance-semaforo-amarillo .avance-progress-main strong,
    .avance-kpi-card.avance-semaforo-amarillo .avance-progress-main span,
    .avance-kpi-card.avance-semaforo-amarillo .avance-progress-meta span,
    .avance-kpi-card.avance-semaforo-rojo .kpi-icon,
    .avance-kpi-card.avance-semaforo-rojo .kpi-label,
    .avance-kpi-card.avance-semaforo-rojo .kpi-value,
    .avance-kpi-card.avance-semaforo-rojo .kpi-trend,
    .avance-kpi-card.avance-semaforo-rojo .avance-progress-main strong,
    .avance-kpi-card.avance-semaforo-rojo .avance-progress-main span,
    .avance-kpi-card.avance-semaforo-rojo .avance-progress-meta span {
      color: #ffffff !important;
    }

    .avance-kpi-card.avance-semaforo-verde {
      background: #2e8b57;
      border-color: #257447;
    }

    .avance-kpi-card.avance-semaforo-amarillo {
      background: #e49a32;
      border-color: #c47b1c;
    }

    .avance-kpi-card.avance-semaforo-rojo {
      background: #c94436;
      border-color: #a9362c;
    }

    .avance-chart-container {
      margin-bottom: 14px;
      border-radius: 20px;
      padding: 16px;
    }

    .avance-chart-container .chart-header {
      margin-bottom: 12px;
    }

    .avance-chart-box {
      height: 238px;
    }

    .avance-chart-box.compact {
      height: 190px;
    }

    .avance-detail-grid {
      display: grid;
      grid-template-columns: minmax(460px, 1fr) minmax(420px, .95fr);
      gap: 18px;
    }

    .avance-barredura-layout {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(170px, .35fr);
      gap: 16px;
      align-items: stretch;
    }

    .avance-table-scroll {
      overflow: auto;
      max-height: 190px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
    }

    .avance-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.78rem;
    }

    .avance-table th,
    .avance-table td {
      padding: 8px 10px;
      border-bottom: 1px solid #e2e8f0;
      text-align: right;
      white-space: nowrap;
    }

    .avance-table th {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f8fafc;
      color: #475569;
      font-size: 0.68rem;
      font-weight: 800;
      letter-spacing: .06em;
      text-transform: uppercase;
    }

    .avance-table th:first-child,
    .avance-table td:first-child,
    .avance-table th:nth-child(2),
    .avance-table td:nth-child(2) {
      text-align: left;
    }

    .avance-table tbody tr:nth-child(even) td {
      background: #f8fafc;
    }

    .avance-table .avance-total-row td {
      position: sticky;
      bottom: 0;
      background: #ffffff !important;
      border-top: 1px solid #bfdbfe;
      color: #0f172a;
      font-weight: 800;
    }

    .avance-footnote {
      margin-top: 14px;
      color: #94a3b8;
      font-size: 0.78rem;
      text-align: right;
    }

    .avance-live-updating {
      opacity: .68;
    }

    .avance-live-error {
      background: #ef4444;
      animation: none;
    }

    html.executive-display .avance-dashboard {
      max-width: min(96vw, 1760px);
      padding: 16px 22px 18px;
    }

    html.executive-display .avance-header h1 {
      font-size: 2.25rem;
      line-height: 1.08;
    }

    html.executive-display .avance-header .sub {
      font-size: 0.86rem;
    }

    html.executive-display .avance-header,
    html.executive-display .avance-filter-form,
    html.executive-display .avance-summary-grid,
    html.executive-display .avance-chart-container {
      margin-bottom: 12px;
    }

    html.executive-display .avance-summary-grid {
      grid-template-columns: minmax(250px, 1.08fr) repeat(4, minmax(154px, 1fr));
      gap: 12px;
    }

    html.executive-display .avance-progress-card {
      min-height: 178px;
      padding: 16px;
    }

    html.executive-display .avance-progress-main strong {
      font-size: 3.1rem;
    }

    html.executive-display .avance-progress-main span,
    html.executive-display .avance-progress-meta {
      font-size: 0.88rem;
    }

    html.executive-display .avance-kpi-card {
      min-height: 82px;
      padding: 14px;
    }

    html.executive-display .avance-kpi-card .kpi-label {
      font-size: 0.78rem;
    }

    html.executive-display .avance-kpi-card .kpi-value {
      font-size: 2rem;
    }

    html.executive-display .avance-chart-box {
      height: 250px;
    }

    html.executive-display .avance-chart-box.compact,
    html.executive-display .avance-table-scroll {
      max-height: 202px;
      height: 202px;
    }

    html.executive-display .avance-chart-box.compact {
      height: 202px;
    }

    html.executive-display .avance-table {
      font-size: 0.82rem;
    }

    @media (max-width: 1180px) {
      .avance-summary-grid,
      .avance-detail-grid,
      .avance-barredura-layout {
        grid-template-columns: 1fr;
      }

      .avance-progress-card {
        grid-row: auto;
      }
    }

    @media (max-width: 720px) {
      .avance-dashboard {
        padding: 16px;
      }

      .avance-filter-field,
      .avance-filter-field .filter-select {
        width: 100%;
        min-width: 100%;
      }
    }
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
  </style>
</head>

<body>
  <div class="dashboard avance-dashboard">
    <header class="header avance-header">
      <div class="header-left">
        <div class="avance-header-actions">
          <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Regresar al inicio</a>
        </div>
        <h1>Avance Producción</h1>
        <div class="sub">
          <span class="badge">rev_tarimas</span>
          <span><i class="fa-solid fa-weight-hanging"></i> Base tar_kilos</span>
          <span id="avancePeriodoLabel"><?= $e($filtros['periodo_label'] ?? '') ?></span>
        </div>
      </div>
      <div class="header-right">
        <div class="update-panel">
          <div class="update-status">
            <span class="update-indicator" id="avanceUpdateIndicator"></span>
            <span>Actualizado</span>
          </div>
          <span class="last-update" id="avanceLastUpdate"><?= $e($meta['actualizado'] ?? '') ?></span>
        </div>
      </div>
    </header>

    <form class="filters avance-filter-form" method="get" id="avanceFilters">
      <div class="avance-filter-field">
        <label for="anio">Año</label>
        <select class="filter-select" id="anio" name="anio">
          <?php foreach ((array)($filtros['anios'] ?? []) as $year): ?>
            <option value="<?= (int)$year ?>" <?= (int)$year === (int)($filtros['anio'] ?? 0) ? 'selected' : '' ?>><?= (int)$year ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="avance-filter-field">
        <label for="mes">Mes</label>
        <select class="filter-select" id="mes" name="mes">
          <?php foreach ((array)($filtros['meses'] ?? []) as $monthNumber => $monthName): ?>
            <option value="<?= (int)$monthNumber ?>" <?= (int)$monthNumber === (int)($filtros['mes'] ?? 0) ? 'selected' : '' ?>><?= $e($monthName) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="avance-filter-field">
        <label for="semana">Semana</label>
        <select class="filter-select" id="semana" name="semana">
          <option value="all" <?= (string)($filtros['semana'] ?? 'all') === 'all' ? 'selected' : '' ?>>Todas</option>
          <?php foreach ((array)($filtros['semanas'] ?? []) as $week): ?>
            <option value="<?= $e($week['key'] ?? '') ?>" <?= (string)($week['key'] ?? '') === (string)($filtros['semana'] ?? '') ? 'selected' : '' ?>>
              <?= $e($week['label'] ?? $week['key'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <section class="avance-summary-grid" aria-label="Indicadores de avance">
      <article class="kpi-card avance-progress-card avance-kpi-card <?= $e($semaforoProduccionPromedio($kpis['promedio_diario'] ?? null)) ?>" id="avanceProduccionCard">
        <div>
          <div class="kpi-icon" style="color:#10b981;"><i class="fa-solid fa-chart-line"></i></div>
          <div class="kpi-label">Avance del periodo</div>
        </div>
        <div class="avance-progress-main">
          <strong id="avanceTotalToneladas"><?= $fmt($totalToneladas, 1) ?></strong>
          <span id="avanceObjetivoPeriodo">toneladas de <?= $fmt($objetivoComparacion, 0) ?> objetivo acumulado · <?= $fmt($objetivoPeriodo, 0) ?> objetivo mensual</span>
        </div>
        <div>
          <div class="avance-progress-track" id="avanceProgressTrack" style="--avance-pct: <?= $e(n($avancePct, 2)) ?>%;">
            <span></span>
          </div>
          <div class="avance-progress-meta">
            <span id="avanceProgressPct"><?= $fmt($avancePct, 1) ?>%</span>
            <span id="avanceObjetivoDiario"><?= $fmt($objetivoDiario, 1) ?> t/día</span>
          </div>
        </div>
      </article>

      <article class="kpi-card avance-kpi-card <?= $e($semaforoRendimiento($kpis['rendimiento'] ?? null)) ?>" id="avanceKpiRendimientoCard">
        <div class="kpi-icon" style="color:#10b981;"><i class="fa-solid fa-percent"></i></div>
        <div class="kpi-label">Rendimiento</div>
        <div class="kpi-value" id="avanceKpiRendimiento"><?= $fmtPct($kpis['rendimiento'] ?? null, 2) ?></div>
      </article>

      <article class="kpi-card avance-kpi-card <?= $e($semaforoFinos($kpis['porcentaje_finos'] ?? null)) ?>" id="avanceKpiFinosCard">
        <div class="kpi-icon"><i class="fa-solid fa-filter"></i></div>
        <div class="kpi-label">Finos</div>
        <div class="kpi-value" id="avanceKpiFinos"><?= $fmtPct($kpis['porcentaje_finos'] ?? null, 2) ?></div>
      </article>

      <article class="kpi-card avance-kpi-card <?= $e($semaforoTarimas($kpis['promedio_diario'] ?? null)) ?>" id="avanceKpiPromedioCard">
        <div class="kpi-icon" style="color:#0ea5e9;"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="kpi-label">Promedio diario</div>
        <div class="kpi-value" id="avanceKpiPromedio"><?= $fmt($kpis['promedio_diario'] ?? null, 2) ?></div>
      </article>

      <article class="kpi-card avance-kpi-card">
        <div class="kpi-icon" style="color:#6366f1;"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="kpi-label">Días a meta</div>
        <div class="kpi-value" id="avanceKpiDiasMeta"><?= $fmt($kpis['dias_para_meta'] ?? null, 2) ?></div>
      </article>

      <article class="kpi-card avance-kpi-card <?= $e($semaforoDeficit($kpis['deficit_toneladas'] ?? null)) ?>" id="avanceKpiDeficitTonCard">
        <div class="kpi-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div class="kpi-label">Déficit toneladas</div>
        <div class="kpi-value" id="avanceKpiDeficitTon"><?= $fmt($kpis['deficit_toneladas'] ?? null, 2) ?></div>
      </article>

      <article class="kpi-card avance-kpi-card <?= $e($semaforoDeficit($kpis['deficit_dias'] ?? null)) ?>" id="avanceKpiDeficitDiasCard">
        <div class="kpi-icon"><i class="fa-solid fa-hourglass-half"></i></div>
        <div class="kpi-label">Déficit días</div>
        <div class="kpi-value" id="avanceKpiDeficitDias"><?= $fmt($kpis['deficit_dias'] ?? null, 2) ?></div>
      </article>
    </section>

    <section class="chart-container avance-chart-container">
      <div class="chart-header">
        <h3>Toneladas por día contra objetivo</h3>
        <div class="legend">
          <span class="legend-item"><i class="legend-color" style="background:#2563eb;"></i> Tarimas</span>
          <span class="legend-item"><i class="legend-color" style="background:#0f172a;"></i> Objetivo diario</span>
        </div>
      </div>
      <div class="avance-chart-box">
        <canvas id="avanceDailyChart"></canvas>
      </div>
    </section>

    <section class="avance-detail-grid">
      <article class="chart-container avance-chart-container">
        <div class="chart-header">
          <h3>Finos B (Barredura)</h3>
          <span class="badge" id="avanceBarreduraBadge"><?= $fmt($kpis['barredura_toneladas'] ?? null, 1) ?> t</span>
        </div>
        <div class="avance-barredura-layout">
          <div class="avance-chart-box compact">
            <canvas id="avanceBarreduraChart"></canvas>
          </div>
          <div class="avance-table-scroll">
            <table class="avance-table">
              <thead>
                <tr>
                  <th>Día</th>
                  <th>Ton</th>
                </tr>
              </thead>
              <tbody id="avanceBarreduraBody">
                <?php foreach ($barreduraRows as $row): ?>
                  <tr>
                    <td><?= $fmtInt($row['day'] ?? null) ?></td>
                    <td><?= $fmt($row['toneladas'] ?? null, 1) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="avance-total-row">
                  <td>Total</td>
                  <td><?= $fmt($kpis['barredura_toneladas'] ?? null, 1) ?></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </article>

      <article class="chart-container avance-chart-container">
        <div class="chart-header">
          <h3>Detalle por proceso</h3>
          <span class="badge">MP inventario</span>
        </div>
        <div class="avance-table-scroll">
          <table class="avance-table">
            <thead>
              <tr>
                <th>Proc 1</th>
                <th>Proc 2</th>
                <th>Tarimas</th>
                <th>Ton</th>
                <th>MP</th>
                <th>Rend.</th>
              </tr>
            </thead>
            <tbody id="avanceProcesoBody">
              <?php foreach ($processRows as $row): ?>
                <tr>
                  <td><?= !empty($row['proc_1']) ? $fmtInt($row['proc_1']) : '-' ?></td>
                  <td><?= !empty($row['proc_2']) ? $fmtInt($row['proc_2']) : '-' ?></td>
                  <td><?= $fmtInt($row['tarimas'] ?? null) ?></td>
                  <td><?= $fmt($row['toneladas'] ?? null, 1) ?></td>
                  <td><?= $fmtKilos($row['mp_kilos'] ?? null) ?></td>
                  <td><?= $fmtPct($row['rendimiento'] ?? null, 2) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr class="avance-total-row">
                <td>Total</td>
                <td></td>
                <td><?= $fmtInt($processTotal['tarimas'] ?? null) ?></td>
                <td><?= $fmt($processTotal['toneladas'] ?? null, 1) ?></td>
                <td><?= $fmtKilos($processTotal['mp_kilos'] ?? null) ?></td>
                <td><?= $fmtPct($processTotal['rendimiento'] ?? null, 2) ?></td>
              </tr>
            </tbody>
          </table>
        </div>
      </article>
    </section>

    <div class="avance-footnote" id="avanceFootnote">
      Corte <?= $e($meta['hora_corte'] ?? '') ?> · <?= $e($filtros['periodo_inicio'] ?? '') ?> - <?= $e($filtros['periodo_fin'] ?? '') ?>
    </div>
  </div>

  <script>
    (() => {
      const form = document.getElementById('avanceFilters');
      if (form) {
        form.querySelectorAll('select').forEach((select) => {
          select.addEventListener('change', () => form.submit());
        });
      }

      const executiveMode = document.documentElement.classList.contains('executive-display');
      Chart.defaults.font.family = 'Inter, sans-serif';
      Chart.defaults.font.size = executiveMode ? 12 : 11;
      Chart.defaults.color = '#475569';

      const numberFormatter = new Intl.NumberFormat('es-MX', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      });
      const byId = (id) => document.getElementById(id);
      const setText = (id, value) => {
        const element = byId(id);
        if (element) element.textContent = value;
      };
      const asNumber = (value) => Number.isFinite(Number(value)) ? Number(value) : null;
      const fmtNumber = (value, decimals = 1) => {
        const number = asNumber(value);
        if (number === null) return '-';
        return new Intl.NumberFormat('es-MX', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        }).format(number);
      };
      const fmtInt = (value) => {
        const number = asNumber(value);
        return number === null ? '-' : numberFormatter.format(Math.round(number));
      };
      const fmtPct = (value, decimals = 2) => asNumber(value) === null ? '-' : `${fmtNumber(value, decimals)} %`;
      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
      const semaforoClasses = ['avance-semaforo-verde', 'avance-semaforo-amarillo', 'avance-semaforo-rojo'];
      const semaforoRendimiento = (value) => {
        const number = asNumber(value);
        if (number === null) return '';
        if (number < 16) return 'avance-semaforo-rojo';
        if (number <= 17) return 'avance-semaforo-amarillo';
        return 'avance-semaforo-verde';
      };
      const semaforoTarimas = (value) => {
        const number = asNumber(value);
        if (number === null) return '';
        if (number < 20) return 'avance-semaforo-rojo';
        if (number < 22) return 'avance-semaforo-amarillo';
        return 'avance-semaforo-verde';
      };
      const semaforoTarimasPeriodo = (value, objetivo, amarilloMin) => {
        const number = asNumber(value);
        const greenTarget = asNumber(objetivo);
        const yellowMin = asNumber(amarilloMin);
        if (number === null || greenTarget === null || greenTarget <= 0) return '';
        if (yellowMin !== null && number < yellowMin) return 'avance-semaforo-rojo';
        if (number < greenTarget) return 'avance-semaforo-amarillo';
        return 'avance-semaforo-verde';
      };
      const semaforoProduccionPromedio = (value) => semaforoTarimas(value);
      const semaforoFinos = (value) => {
        const number = asNumber(value);
        if (number === null) return '';
        if (number < 19) return 'avance-semaforo-verde';
        if (number <= 21) return 'avance-semaforo-amarillo';
        return 'avance-semaforo-rojo';
      };
      const semaforoDeficit = (value) => {
        const number = asNumber(value);
        if (number === null) return '';
        return number <= 0 ? 'avance-semaforo-verde' : 'avance-semaforo-rojo';
      };
      const setSemaforo = (cardId, statusClass) => {
        const card = byId(cardId);
        if (!card) return;
        card.classList.remove(...semaforoClasses);
        if (statusClass) {
          card.classList.add(statusClass);
        }
      };

      const valueLabelPlugin = {
        id: 'avanceValueLabels',
        afterDatasetsDraw(chart) {
          const { ctx } = chart;
          ctx.save();
          ctx.font = `${executiveMode ? 11 : 10}px Inter, sans-serif`;
          ctx.fillStyle = '#475569';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'bottom';
          chart.data.datasets.forEach((dataset, datasetIndex) => {
            if (dataset.type === 'line' || dataset.showLabels === false) return;
            const meta = chart.getDatasetMeta(datasetIndex);
            meta.data.forEach((bar, index) => {
              const value = Number(dataset.data[index] || 0);
              if (value <= 0) return;
              ctx.fillText(value.toFixed(1), bar.x, bar.y - 5);
            });
          });
          ctx.restore();
        }
      };

      const gridColor = 'rgba(148, 163, 184, 0.24)';
      let dailyChart = null;
      let barreduraChart = null;
      const dailyCanvas = document.getElementById('avanceDailyChart');
      if (dailyCanvas) {
        dailyChart = new Chart(dailyCanvas, {
          data: {
            labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            datasets: [{
              type: 'bar',
              label: 'Tarimas',
              data: <?= json_encode($chartTons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
              backgroundColor: '#2563eb',
              borderColor: '#2563eb',
              borderWidth: 0,
              borderRadius: 8,
              maxBarThickness: 34,
              yAxisID: 'y',
            }, {
              type: 'line',
              label: 'Objetivo diario',
              data: <?= json_encode($chartTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
              borderColor: '#0f172a',
              backgroundColor: '#0f172a',
              pointRadius: 0,
              borderWidth: 3,
              tension: 0,
              yAxisID: 'y',
              showLabels: false,
            }]
          },
          options: {
            animation: false,
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: { mode: 'index', intersect: false },
            },
            scales: {
              x: {
                title: { display: true, text: 'Día', color: '#1f2937', font: { weight: '700' } },
                grid: { display: false },
              },
              y: {
                title: { display: true, text: 'Toneladas', color: '#1f2937', font: { weight: '700' } },
                beginAtZero: true,
                suggestedMax: <?= json_encode(max($objetivoDiario + 5, max($chartTons ?: [0]) + 4)) ?>,
                grid: { color: gridColor },
              }
            }
          },
          plugins: [valueLabelPlugin]
        });
      }

      const barreduraCanvas = document.getElementById('avanceBarreduraChart');
      if (barreduraCanvas) {
        barreduraChart = new Chart(barreduraCanvas, {
          type: 'bar',
          data: {
            labels: <?= json_encode($barreduraLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            datasets: [{
              label: 'Finos B',
              data: <?= json_encode($barreduraData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
              backgroundColor: '#f59e0b',
              borderColor: '#f59e0b',
              borderWidth: 0,
              borderRadius: 8,
              maxBarThickness: 80,
            }]
          },
          options: {
            animation: false,
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
            },
            scales: {
              x: {
                title: { display: true, text: 'Día', color: '#1f2937', font: { weight: '700' } },
                grid: { display: false },
              },
              y: {
                title: { display: true, text: 'Toneladas', color: '#1f2937', font: { weight: '700' } },
                beginAtZero: true,
                suggestedMax: <?= json_encode(max(5, max($barreduraData ?: [0]) + 2)) ?>,
                grid: { color: gridColor },
              }
            }
          },
          plugins: [valueLabelPlugin]
        });
      }

      const renderBarreduraRows = (rows, total) => {
        const body = byId('avanceBarreduraBody');
        if (!body) return;
        const rowHtml = (rows || []).map((row) => `
          <tr>
            <td>${fmtInt(row.day)}</td>
            <td>${fmtNumber(row.toneladas, 1)}</td>
          </tr>
        `).join('');

        body.innerHTML = `${rowHtml}
          <tr class="avance-total-row">
            <td>Total</td>
            <td>${fmtNumber(total, 1)}</td>
          </tr>
        `;
      };

      const renderProcesoRows = (rows, total) => {
        const body = byId('avanceProcesoBody');
        if (!body) return;
        const rowHtml = (rows || []).map((row) => `
          <tr>
            <td>${row.proc_1 ? escapeHtml(fmtInt(row.proc_1)) : '-'}</td>
            <td>${row.proc_2 ? escapeHtml(fmtInt(row.proc_2)) : '-'}</td>
            <td>${fmtInt(row.tarimas)}</td>
            <td>${fmtNumber(row.toneladas, 1)}</td>
            <td>${fmtInt(row.mp_kilos)}</td>
            <td>${asNumber(row.rendimiento) === null ? '-' : fmtPct(row.rendimiento, 2)}</td>
          </tr>
        `).join('');

        body.innerHTML = `${rowHtml}
          <tr class="avance-total-row">
            <td>Total</td>
            <td></td>
            <td>${fmtInt(total?.tarimas)}</td>
            <td>${fmtNumber(total?.toneladas, 1)}</td>
            <td>${fmtInt(total?.mp_kilos)}</td>
            <td>${fmtPct(total?.rendimiento, 2)}</td>
          </tr>
        `;
      };

      const setRefreshState = (state) => {
        const indicator = byId('avanceUpdateIndicator');
        if (!indicator) return;
        indicator.classList.toggle('updating', state === 'updating');
        indicator.classList.toggle('avance-live-error', state === 'error');
      };

      const updateCharts = (report) => {
        const dailyRows = report?.series?.diaria || [];
        const labels = dailyRows.map((row) => String(row.day ?? ''));
        const toneladas = dailyRows.map((row) => Number(row.toneladas || 0));
        const objetivoDiario = Number(report?.objetivos?.diario_toneladas || 0);
        const objectiveLine = labels.map(() => objetivoDiario);

        if (dailyChart) {
          dailyChart.data.labels = labels;
          dailyChart.data.datasets[0].data = toneladas;
          dailyChart.data.datasets[1].data = objectiveLine;
          dailyChart.options.scales.y.suggestedMax = Math.max(objetivoDiario + 5, Math.max(0, ...toneladas) + 4);
          dailyChart.update('none');
        }

        const barreduraRows = report?.tablas?.barredura || [];
        if (barreduraChart) {
          barreduraChart.data.labels = barreduraRows.map((row) => String(row.day ?? ''));
          barreduraChart.data.datasets[0].data = barreduraRows.map((row) => Number(row.toneladas || 0));
          barreduraChart.options.scales.y.suggestedMax = Math.max(5, Math.max(0, ...barreduraChart.data.datasets[0].data) + 2);
          barreduraChart.update('none');
        }
      };

      const applyReport = (report) => {
        const kpis = report?.kpis || {};
        const objetivos = report?.objetivos || {};
        const filtros = report?.filtros || {};
        const tablas = report?.tablas || {};
        const meta = report?.meta || {};
        const objetivoDiario = Number(objetivos.diario_toneladas || 0);
        const objetivoPeriodo = Math.max(objetivoDiario, Number(objetivos.periodo_toneladas || 0));
        const objetivoComparacion = Math.max(
          objetivoDiario,
          Number(objetivos.comparacion_toneladas || objetivos.acumulado_toneladas || objetivoPeriodo)
        );
        const totalToneladas = Number(kpis.toneladas || 0);
        const avancePct = objetivoComparacion > 0 ? Math.min(100, Math.max(0, (totalToneladas / objetivoComparacion) * 100)) : 0;

        setText('avancePeriodoLabel', filtros.periodo_label || '');
        setText('avanceLastUpdate', meta.actualizado || '');
        setText('avanceTotalToneladas', fmtNumber(totalToneladas, 1));
        setText('avanceObjetivoPeriodo', `toneladas de ${fmtNumber(objetivoComparacion, 0)} objetivo acumulado · ${fmtNumber(objetivoPeriodo, 0)} objetivo mensual`);
        setText('avanceProgressPct', `${fmtNumber(avancePct, 1)}%`);
        setText('avanceObjetivoDiario', `${fmtNumber(objetivoDiario, 1)} t/día`);
        setText('avanceKpiRendimiento', fmtPct(kpis.rendimiento, 2));
        setText('avanceKpiFinos', fmtPct(kpis.porcentaje_finos, 2));
        setText('avanceKpiPromedio', fmtNumber(kpis.promedio_diario, 2));
        setText('avanceKpiDiasMeta', fmtNumber(kpis.dias_para_meta, 2));
        setText('avanceKpiDeficitTon', fmtNumber(kpis.deficit_toneladas, 2));
        setText('avanceKpiDeficitDias', fmtNumber(kpis.deficit_dias, 2));
        setText('avanceBarreduraBadge', `${fmtNumber(kpis.barredura_toneladas, 1)} t`);
        setText('avanceFootnote', `Corte ${meta.hora_corte || ''} · ${filtros.periodo_inicio || ''} - ${filtros.periodo_fin || ''}`);
        setSemaforo('avanceProduccionCard', semaforoProduccionPromedio(kpis.promedio_diario));
        setSemaforo('avanceKpiRendimientoCard', semaforoRendimiento(kpis.rendimiento));
        setSemaforo('avanceKpiFinosCard', semaforoFinos(kpis.porcentaje_finos));
        setSemaforo('avanceKpiPromedioCard', semaforoTarimas(kpis.promedio_diario));
        setSemaforo('avanceKpiDeficitTonCard', semaforoDeficit(kpis.deficit_toneladas));
        setSemaforo('avanceKpiDeficitDiasCard', semaforoDeficit(kpis.deficit_dias));

        const progress = byId('avanceProgressTrack');
        if (progress) {
          progress.style.setProperty('--avance-pct', `${avancePct.toFixed(2)}%`);
        }

        renderBarreduraRows(tablas.barredura || [], kpis.barredura_toneladas);
        renderProcesoRows(tablas.procesos || [], tablas.procesos_total || {});
        updateCharts(report);
      };

      const fetchReport = async () => {
        const url = new URL('data.php', window.location.href);
        const params = new URLSearchParams(window.location.search);
        params.set('_', String(Date.now()));
        url.search = params.toString();

        const response = await fetch(url.toString(), {
          cache: 'no-store',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const report = await response.json();
        if (report?.error) {
          throw new Error(report.message || 'Error al actualizar datos');
        }
        return report;
      };

      const refreshMs = Number(<?= json_encode((int)($meta['intervaloActualizacion'] ?? 1800000)) ?>);
      let refreshTimer = null;
      let refreshing = false;

      const scheduleRefresh = () => {
        if (refreshMs > 0) {
          refreshTimer = window.setTimeout(refreshReport, refreshMs);
        }
      };

      const refreshReport = async () => {
        if (refreshing) return;
        refreshing = true;
        setRefreshState('updating');
        try {
          const report = await fetchReport();
          applyReport(report);
          setRefreshState('ok');
        } catch (error) {
          console.error('No se pudo actualizar Avance Producción:', error);
          setRefreshState('error');
        } finally {
          refreshing = false;
          scheduleRefresh();
        }
      };

      if (refreshMs > 0) {
        scheduleRefresh();
      }
    })();
  </script>
</body>

</html>
