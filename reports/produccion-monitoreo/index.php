<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
  exit;
}

$titulo = (string)($report['titulo'] ?? 'Produccion Monitoreo');
$cards = (array)($report['cards'] ?? []);
$extraccionIndicadores = (array)($report['extraccion']['indicadores'] ?? []);
$vista = strtolower(trim((string)($_GET['vista'] ?? '')));
$vistasPermitidas = ['extraccion', 'secado'];
if (!in_array($vista, $vistasPermitidas, true)) {
  $vista = 'todo';
}
$mostrarExtraccion = $vista === 'todo' || $vista === 'extraccion';
$mostrarSecado = $vista === 'todo' || $vista === 'secado';
$extractionCards = array_values(array_filter([
  $cards['cocedores'] ?? null,
  $cards['clarificadores'] ?? null,
  $cards['integracion'] ?? null,
], static fn($card): bool => is_array($card)));
$votatorCards = array_filter($cards, static fn(array $card): bool => (string)($card['key'] ?? '') === 'votators');
$primaryCards = array_filter($cards, static fn(array $card): bool => (string)($card['key'] ?? '') === 'secadores');
$sideCards = array_values(array_filter([
  $cards['concentradores'] ?? null,
], static fn($card): bool => is_array($card)));
$otherCards = array_filter($cards, static fn(array $card): bool => !in_array((string)($card['key'] ?? ''), ['secadores', 'votators', 'concentradores', 'cocedores', 'clarificadores', 'integracion'], true));
$visibleCardCount = ($mostrarExtraccion ? count($extractionCards) : 0)
  + ($mostrarSecado ? count($votatorCards) + count($primaryCards) + count($sideCards) + count($otherCards) : 0);
$meta = (array)($report['meta'] ?? []);
$version = (int)($report['version'] ?? time());
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$stripTrendPayload = static function (array $value) use (&$stripTrendPayload): array {
  foreach ($value as $key => $item) {
    if ($key === 'history' || $key === 'trends') {
      unset($value[$key]);
      continue;
    }

    if (is_array($item)) {
      $value[$key] = $stripTrendPayload($item);
    }
  }

  return $value;
};
$clientReport = $stripTrendPayload($report);
$vistaUrl = static function (string $target): string {
  $params = $_GET;
  if ($target === 'todo') {
    unset($params['vista']);
  } else {
    $params['vista'] = $target;
  }

  $query = http_build_query($params);
  return 'index.php' . ($query !== '' ? '?' . $query : '');
};
$cardClass = static function (string $key): string {
  if ($key === 'votators') {
    return 'is-over-primary';
  }

  if ($key === 'secadores') {
    return 'is-primary';
  }

  if ($key === 'concentradores') {
    return 'is-side';
  }

  return 'is-full';
};
$shortEquipmentTitle = static function (string $cardKey, string $equipmentKey, string $title): string {
  if ($cardKey === 'secadores' && preg_match('/tunel_?(\d+)/i', $equipmentKey, $matches) === 1) {
    return 'T' . (string)$matches[1];
  }

  if ($cardKey === 'votators' && preg_match('/votator_?(\d+)/i', $equipmentKey, $matches) === 1) {
    return 'V' . (string)$matches[1];
  }

  if ($cardKey === 'concentradores') {
    if ($equipmentKey === 'invertido' || mb_strtolower($title, 'UTF-8') === 'invertido') {
      return 'Inv';
    }
    if (preg_match('/concentrador_?(\d+)/i', $equipmentKey, $matches) === 1 || preg_match('/concentrador\s+(\d+)/i', $title, $matches) === 1) {
      return 'C' . (string)$matches[1];
    }
  }

  if ($cardKey === 'cocedores' && preg_match('/cocedor_?(\d+)/i', $equipmentKey, $matches) === 1) {
    return 'Co' . (string)$matches[1];
  }

  if ($cardKey === 'clarificadores') {
    return 'Clari';
  }

  if ($cardKey === 'integracion') {
    return 'Int';
  }

  return $title;
};
$renderSourceIcons = static function (array $sources) use ($e): string {
  $icons = [
    'sqlserver' => ['icon' => 'fa-gear', 'title' => 'SQL Server / AVEVA'],
    'mysql_105' => ['icon' => 'fa-book-open', 'title' => 'MySQL 105'],
  ];
  $html = '';

  foreach ($sources as $source) {
    $source = (string)$source;
    if (!isset($icons[$source])) {
      continue;
    }

    $html .= '<span class="monitor-source-icon" title="' . $e($icons[$source]['title']) . '"><i class="fa-solid ' . $e($icons[$source]['icon']) . '"></i></span>';
  }

  return $html;
};
$invertidoMetricGroups = [
  'General' => ['flujo_entrada_evaporador', 'flujo_salida_evaporador', 'temperatura_precalentamiento', 'nivel_tanque_alimentacion'],
  'Etapa 1' => ['flujo_etapa_1_2', 'temperatura_etapa_1', 'vacio_etapa_1', 'presion_etapa_1', 'nivel_etapa_1', 'valvula_control_temperatura_etapa_1', 'valvula_control_nivel_etapa_1'],
  'Etapa 2' => ['flujo_etapa_2_3', 'vacio_etapa_2', 'presion_etapa_2', 'nivel_etapa_2', 'moyno_control_nivel_etapa_2'],
  'Etapa 3' => ['vacio_etapa_3', 'presion_etapa_3', 'nivel_etapa_3', 'moyno_control_nivel_etapa_3'],
  'Agua / vapor' => ['temperatura_salida_agua', 'presion_agua_enfriamiento', 'presion_vapor'],
  'Controles' => [
    'valvula_control_flujo_entrada',
    'valvula_control_precalentamiento',
    'valvula_control_presion',
  ],
];
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      min-height: 100vh;
      background: #f4f7fb;
    }

    .dashboard {
      background: transparent;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border: 1px solid #dbe7f5;
      border-radius: 999px;
      color: #334155;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
      font-weight: 700;
      text-decoration: none;
      transition: all .2s;
    }

    .back-btn:hover {
      background: #eff6ff;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
      transform: translateX(-2px);
    }

    .view-switch {
      display: inline-flex;
      gap: 4px;
      margin-top: 10px;
      padding: 4px;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
    }

    .view-switch a {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 6px 11px;
      border-radius: 7px;
      color: #475569;
      font-size: 12px;
      font-weight: 900;
      text-decoration: none;
      white-space: nowrap;
    }

    .view-switch a.is-active {
      color: #ffffff;
      background: #2563eb;
    }

    .monitor-grid {
      display: grid;
      column-gap: 8px;
      row-gap: 6px;
      grid-template-columns: minmax(0, 1.08fr) minmax(500px, 0.92fr);
      align-items: stretch;
    }

    .monitor-section {
      margin-top: 10px;
    }

    .monitor-section:first-of-type {
      margin-top: 0;
    }

    .monitor-section-title {
      margin: 0 0 6px;
      color: #334155;
      font-size: 13px;
      font-weight: 900;
      letter-spacing: 0;
      text-transform: uppercase;
    }

    .monitor-extraction-grid {
      display: grid;
      gap: 8px;
      grid-template-columns: minmax(0, 1.08fr) minmax(500px, 0.92fr);
      align-items: start;
    }

    .monitor-card {
      overflow: hidden;
      border: 1px solid #dbe7f5;
      border-radius: 16px;
      background: #ffffff;
      box-shadow:
        0 10px 22px rgba(37, 99, 235, 0.08),
        0 2px 6px rgba(15, 23, 42, 0.05);
    }

    .monitor-card.is-primary {
      grid-column: 2;
      grid-row: 2;
      border-top-right-radius: 0;
      border-top-left-radius: 0;
    }

    .monitor-card.is-over-primary {
      grid-column: 2;
      grid-row: 1;
      border-bottom-right-radius: 0;
      border-bottom-left-radius: 0;
    }

    .monitor-card.is-side {
      grid-column: auto;
    }

    .monitor-side-stack {
      display: flex;
      grid-column: 1;
      grid-row: 1 / span 2;
      flex-direction: column;
      gap: 8px;
      align-self: stretch;
      min-width: 0;
    }

    .monitor-side-stack .monitor-card {
      flex: 1 1 auto;
    }

    .monitor-side-stack .monitor-card[data-card="votators"] {
      border-top: 1px solid #dbe7f5;
      border-top-right-radius: 0;
      border-top-left-radius: 0;
      border-bottom-right-radius: 0;
      border-bottom-left-radius: 0;
    }

    .monitor-card[data-card="concentradores"] {
      border-bottom-right-radius: 0;
      border-bottom-left-radius: 0;
    }

    .monitor-card[data-card="cocedores"] {
      border-top: 1px solid #dbe7f5;
      border-top-right-radius: 0;
      border-top-left-radius: 0;
    }

    .monitor-extraction-grid .monitor-card[data-card="cocedores"] {
      grid-column: 1 / -1;
      border-radius: 16px;
    }

    .monitor-extraction-grid .monitor-card[data-card="clarificadores"] {
      grid-column: 1;
    }

    .monitor-extraction-grid .monitor-card[data-card="integracion"] {
      grid-column: 2;
    }

    .monitor-card.is-full {
      grid-column: 1 / -1;
    }

    .monitor-card-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 10px 14px;
      border-bottom: 1px solid #cfe0fb;
      background: #eef6ff;
    }

    .monitor-card-title {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 0;
    }

    .monitor-card-title i {
      color: #2563eb;
      font-size: 20px;
    }

    .monitor-card-title h2 {
      margin: 0;
      color: #0f172a;
      font-size: 20px;
      font-weight: 800;
      line-height: 1;
    }

    .monitor-card-metrics-strip {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 6px;
      border-bottom: 1px solid #dbe7f5;
      background: #f8fbff;
      min-width: 0;
    }

    .monitor-section-metrics {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: -2px 0 10px;
      min-width: 0;
    }

    .monitor-section-metrics .monitor-head-metric {
      min-width: 190px;
      min-height: 32px;
    }

    .monitor-head-metric {
      display: inline-flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      min-height: 34px;
      min-width: 150px;
      padding: 7px 10px;
      border: 1px solid #0284c7;
      border-radius: 6px;
      color: #ffffff;
      background: #0ea5e9;
      font-size: 13px;
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
    }

    .monitor-head-metric.unavailable {
      border-color: #64748b;
      background: #94a3b8;
    }

    .monitor-head-metric.neutral {
      border-color: #d9e0ea;
      color: #111827;
      background: #ffffff;
    }

    .monitor-head-metric.ok {
      border-color: #257447;
      background: #2e8b57;
    }

    .monitor-head-metric.warning {
      border-color: #eab308;
      color: #111827;
      background: #facc15;
    }

    .monitor-head-metric.danger {
      border-color: #a9362c;
      background: #c94436;
    }

    .monitor-head-metric span {
      opacity: 0.86;
    }

    .monitor-head-label {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      min-width: 0;
    }

    .monitor-head-metric strong {
      font-size: 15px;
    }

    .monitor-card-status {
      display: inline-flex;
      align-items: center;
      min-height: 24px;
      padding: 4px 9px;
      border-radius: 999px;
      color: #ffffff;
      background: #94a3b8;
      font-size: 12px;
      font-weight: 900;
      white-space: nowrap;
    }

    .monitor-table-wrap {
      padding: 6px;
      overflow-x: auto;
    }

    .monitor-table {
      width: 100%;
      min-width: 0;
      border-collapse: separate;
      border-spacing: 0;
      table-layout: fixed;
    }

    .monitor-table th,
    .monitor-table td {
      padding: 2px 3px;
      border-bottom: 1px solid #e8eef6;
      color: #263244;
      font-size: 12px;
      line-height: 1.15;
      vertical-align: middle;
    }

    .monitor-table th {
      color: #475569;
      background: #f4f7fb;
      font-size: 11px;
      font-weight: 900;
      text-align: left;
      text-transform: uppercase;
    }

    .monitor-table th:first-child,
    .monitor-table td:first-child {
      font-weight: 900;
    }

    .monitor-param {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      min-width: 0;
    }

    .monitor-param span {
      overflow: hidden;
      min-width: 0;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .monitor-param-label {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      min-width: 0;
    }

    .monitor-param-button {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      min-width: 0;
      border: 0;
      padding: 0;
      color: inherit;
      background: transparent;
      font: inherit;
      font-weight: 900;
      cursor: pointer;
      text-align: left;
    }

    .monitor-param-button:hover span:first-child {
      text-decoration: underline;
      text-underline-offset: 2px;
    }

    .monitor-source-icons {
      display: inline-flex;
      flex: 0 0 auto;
      align-items: center;
      gap: 3px;
    }

    .monitor-source-icon {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 15px;
      height: 15px;
      border-radius: 999px;
      color: #475569;
      background: #e2e8f0;
      font-size: 8px;
    }

    .monitor-param small {
      flex: 0 0 auto;
      max-width: 48%;
      overflow: hidden;
      color: #64748b;
      font-size: 9px;
      font-weight: 900;
      line-height: 1;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .monitor-table th:not(:first-child),
    .monitor-table td:not(:first-child) {
      text-align: center;
    }

    .monitor-value-box {
      display: grid;
      width: min(100%, 88px);
      min-height: 22px;
      margin: 0 auto;
      place-items: center;
      padding: 2px 4px;
      border: 1px solid #0284c7;
      border-radius: 5px;
      color: #ffffff;
      background: #0ea5e9;
    }

    .monitor-value-box.ok {
      border-color: #257447;
      background: #2e8b57;
    }

    .monitor-value-box.warning {
      border-color: #eab308;
      color: #111827;
      background: #facc15;
    }

    .monitor-value-box.danger {
      border-color: #a9362c;
      background: #c94436;
    }

    .monitor-value-box.unavailable {
      border-color: #64748b;
      background: #94a3b8;
    }

    .monitor-value-box.neutral {
      border-color: #d9e0ea;
      color: #111827;
      background: #ffffff;
    }

    .monitor-value-box strong {
      overflow: hidden;
      max-width: 100%;
      font-size: 12px;
      font-weight: 900;
      line-height: 1;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .monitor-card[data-card="votators"] .monitor-table th,
    .monitor-card[data-card="votators"] .monitor-table td,
    .monitor-card[data-card="concentradores"] .monitor-table th,
    .monitor-card[data-card="concentradores"] .monitor-table td,
    .monitor-card[data-card="cocedores"] .monitor-table th,
    .monitor-card[data-card="cocedores"] .monitor-table td {
      padding: 2px;
    }

    .monitor-card[data-card="votators"] .monitor-value-box,
    .monitor-card[data-card="concentradores"] .monitor-value-box,
    .monitor-card[data-card="cocedores"] .monitor-value-box {
      min-height: 21px;
      padding: 2px 3px;
    }

    .monitor-card[data-card="votators"] .monitor-value-box strong,
    .monitor-card[data-card="concentradores"] .monitor-value-box strong,
    .monitor-card[data-card="cocedores"] .monitor-value-box strong {
      font-size: 11px;
    }

    .monitor-invertido-panel {
      display: grid;
      gap: 6px;
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px solid #dbe7f5;
    }

    .monitor-invertido-title {
      margin: 0;
      color: #0f172a;
      font-size: 12px;
      font-weight: 900;
      letter-spacing: 0;
      text-transform: uppercase;
    }

    .monitor-invertido-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 6px;
    }

    .monitor-invertido-stage {
      display: grid;
      gap: 4px;
      min-width: 0;
      padding: 6px;
      border: 1px solid #dbe7f5;
      border-radius: 8px;
      background: #f8fbff;
    }

    .monitor-invertido-stage h4 {
      margin: 0;
      color: #334155;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: 0;
      text-transform: uppercase;
    }

    .monitor-invertido-items {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 4px;
    }

    .monitor-invertido-param {
      display: grid;
      gap: 2px;
      min-width: 0;
    }

    .monitor-invertido-param .monitor-param-button {
      justify-content: flex-start;
      min-height: 16px;
      font-size: 9px;
    }

    .monitor-invertido-param .monitor-value-box {
      min-height: 21px;
      padding: 2px 3px;
    }

    .monitor-invertido-param .monitor-value-box strong {
      font-size: 11px;
    }

    .monitor-trend-modal {
      position: fixed;
      inset: 0;
      z-index: 50;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.62);
    }

    .monitor-trend-modal.is-open {
      display: flex;
    }

    .monitor-trend-dialog {
      width: min(1040px, calc(100vw - 48px));
      max-height: calc(100vh - 48px);
      overflow: hidden;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      background: #ffffff;
      box-shadow: 0 24px 80px rgba(15, 23, 42, 0.28);
    }

    .monitor-trend-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      padding: 16px 18px;
      border-bottom: 1px solid #e2e8f0;
      background: #f8fbff;
    }

    .monitor-trend-head h2 {
      margin: 0 0 4px;
      color: #0f172a;
      font-size: 20px;
      line-height: 1.1;
    }

    .monitor-trend-head p {
      margin: 0;
      color: #64748b;
      font-size: 12px;
      font-weight: 800;
    }

    .monitor-trend-actions {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-left: auto;
    }

    .monitor-trend-range {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 74px;
      min-height: 32px;
      padding: 7px 10px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      color: #334155;
      background: #ffffff;
      font-size: 11px;
      font-weight: 900;
      letter-spacing: 0;
      cursor: pointer;
    }

    .monitor-trend-range.is-active {
      color: #ffffff;
      border-color: #1d4ed8;
      background: #2563eb;
    }

    .monitor-trend-close {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      color: #334155;
      background: #ffffff;
      cursor: pointer;
    }

    .monitor-trend-body {
      position: relative;
      padding: 16px 18px 18px;
    }

    .monitor-trend-chart {
      height: 390px;
    }

    .monitor-trend-loader {
      position: absolute;
      inset: 16px 18px 18px;
      z-index: 2;
      display: none;
      place-items: center;
      border: 1px solid #dbe7f5;
      border-radius: 10px;
      color: #0f172a;
      background: rgba(248, 251, 255, 0.94);
      font-size: 15px;
      font-weight: 900;
    }

    .monitor-trend-loader.is-visible {
      display: grid;
    }

    .monitor-trend-loader span {
      display: inline-flex;
      align-items: center;
      gap: 10px;
    }

    .monitor-trend-loader i {
      color: #2563eb;
    }

    .monitor-trend-empty {
      display: none;
      margin-top: 12px;
      padding: 10px 12px;
      border-radius: 8px;
      color: #475569;
      background: #f1f5f9;
      font-size: 13px;
      font-weight: 800;
    }

    .monitor-trend-empty.is-visible {
      display: block;
    }

    .monitor-warning {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 6px;
      padding: 12px 14px;
      border: 1px solid #f59e0b;
      border-radius: 12px;
      color: #78350f;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 800;
    }

    @media (max-width: 760px) {}

    @media (max-width: 1280px) {
      .monitor-grid {
        grid-template-columns: 1fr;
      }

      .monitor-extraction-grid {
        grid-template-columns: 1fr;
      }

      .monitor-card.is-primary,
      .monitor-card.is-over-primary,
      .monitor-card.is-side,
      .monitor-card.is-full,
      .monitor-side-stack,
      .monitor-extraction-grid .monitor-card[data-card="cocedores"],
      .monitor-extraction-grid .monitor-card[data-card="clarificadores"],
      .monitor-extraction-grid .monitor-card[data-card="integracion"] {
        grid-column: 1;
        grid-row: auto;
      }

      .monitor-card[data-card="votators"] {
        border-top: 1px solid #dbe7f5;
        border-top-right-radius: 16px;
        border-top-left-radius: 16px;
        border-bottom-right-radius: 16px;
        border-bottom-left-radius: 16px;
      }

      .monitor-card[data-card="concentradores"] {
        border-top: 1px solid #dbe7f5;
        border-top-right-radius: 16px;
        border-top-left-radius: 16px;
        border-bottom-right-radius: 16px;
        border-bottom-left-radius: 16px;
      }

      .monitor-card[data-card="cocedores"] {
        border-top: 1px solid #dbe7f5;
        border-top-right-radius: 16px;
        border-top-left-radius: 16px;
        border-bottom-right-radius: 16px;
        border-bottom-left-radius: 16px;
      }
    }
  </style>
</head>

<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">

        <h1>
          <i class="fas fa-gauge-high" style="margin-right: 12px;"></i>
          <?= $e($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            <i class="fas fa-eye"></i>
            Resumen operativo
          </span>
          <span>
            <i class="fas fa-clock"></i>
            Refresco: <?= $e((int)ceil(((int)($meta['intervaloActualizacion'] ?? 60000)) / 1000)) ?>s
          </span>
          <span class="badge">
            <i class="fas fa-layer-group"></i>
            <?= $e($visibleCardCount) ?> modulo(s)
          </span>
        </div>

        <nav class="view-switch" aria-label="Cambiar vista">
          <a class="<?= $vista === 'todo' ? 'is-active' : '' ?>" href="<?= $e($vistaUrl('todo')) ?>">Todo</a>
          <a class="<?= $vista === 'extraccion' ? 'is-active' : '' ?>" href="<?= $e($vistaUrl('extraccion')) ?>">Extracción</a>
          <a class="<?= $vista === 'secado' ? 'is-active' : '' ?>" href="<?= $e($vistaUrl('secado')) ?>">Secado</a>
        </nav>
      </div>
    </div>

    <div data-warnings>
      <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
        <div class="monitor-warning"><i class="fas fa-triangle-exclamation"></i><?= $e($warning) ?></div>
      <?php endforeach; ?>
    </div>

    <?php if ($mostrarExtraccion): ?>
    <section class="monitor-section" aria-label="Extraccion">
      <h2 class="monitor-section-title">Extracción</h2>
      <div class="monitor-section-metrics" data-extraction-indicators>
        <?php foreach ($extraccionIndicadores as $metric): ?>
          <span class="monitor-head-metric <?= $e($metric['class'] ?? 'unavailable') ?>" title="<?= $e($metric['statusLabel'] ?? 'Sin dato') ?>">
            <span class="monitor-head-label">
              <span><?= $e($metric['label'] ?? '') ?><?= !empty($metric['rangeLabel']) ? ' ' . $e($metric['rangeLabel']) : '' ?></span>
              <span class="monitor-source-icons"><?= $renderSourceIcons([(string)($metric['source'] ?? '')]) ?></span>
            </span>
            <strong><?= $e($metric['value'] ?? '-') ?></strong>
          </span>
        <?php endforeach; ?>
      </div>
      <div class="monitor-extraction-grid" data-extraction-grid>
        <?php foreach ($extractionCards as $card): ?>
          <article class="monitor-card <?= $e($cardClass((string)($card['key'] ?? ''))) ?>" data-card="<?= $e($card['key'] ?? '') ?>">
            <header class="monitor-card-head">
              <div class="monitor-card-title">
                <i class="fas <?= $e($card['icon'] ?? 'fa-chart-line') ?>"></i>
                <h2><?= $e($card['titulo'] ?? 'Modulo') ?></h2>
              </div>
              <span class="monitor-card-status" data-field="card-status" style="background: <?= $e($card['statusColor'] ?? '#94a3b8') ?>;">
                <?= $e($card['statusLabel'] ?? 'Referencia') ?>
              </span>
            </header>

            <?php if (!empty($card['headMetrics'])): ?>
              <div class="monitor-card-metrics-strip">
                <?php foreach ((array)$card['headMetrics'] as $metric): ?>
                  <span class="monitor-head-metric <?= $e($metric['class'] ?? 'unavailable') ?>" title="<?= $e($metric['statusLabel'] ?? 'Sin dato') ?>">
                    <span class="monitor-head-label">
                      <span><?= $e($metric['label'] ?? '') ?><?= !empty($metric['rangeLabel']) ? ' ' . $e($metric['rangeLabel']) : '' ?></span>
                      <span class="monitor-source-icons"><?= $renderSourceIcons([(string)($metric['source'] ?? '')]) ?></span>
                    </span>
                    <strong><?= $e($metric['value'] ?? '-') ?></strong>
                  </span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div class="monitor-table-wrap">
              <table class="monitor-table">
                <?php
                $tunnelCount = max(1, count((array)($card['tuneles'] ?? [])));
                $paramWidth = $tunnelCount >= 7 ? 18 : ($tunnelCount >= 5 ? 20 : 30);
                $valueWidth = (100 - $paramWidth) / $tunnelCount;
                ?>
                <colgroup>
                  <col style="width: <?= $e(n((float)$paramWidth, 2)) ?>%;">
                  <?php foreach ((array)($card['tuneles'] ?? []) as $_): ?>
                    <col style="width: <?= $e(n((float)$valueWidth, 2)) ?>%;">
                  <?php endforeach; ?>
                </colgroup>
                <thead>
                  <tr>
                    <th>Parámetro</th>
                    <?php foreach ((array)($card['tuneles'] ?? []) as $tunnelKey => $tunnel): ?>
                      <th title="<?= $e($tunnel['titulo'] ?? 'Tunel') ?>"><?= $e($shortEquipmentTitle((string)($card['key'] ?? ''), (string)$tunnelKey, (string)($tunnel['titulo'] ?? 'Tunel'))) ?></th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ((array)($card['tabla'] ?? []) as $row): ?>
                    <tr>
                      <td>
                        <div class="monitor-param">
                          <button class="monitor-param-button" type="button" data-trend-card="<?= $e($card['key'] ?? '') ?>" data-trend-row="<?= $e($row['key'] ?? '') ?>">
                            <span><?= $e($row['label'] ?? '') ?></span>
                            <span class="monitor-source-icons"><?= $renderSourceIcons((array)($row['sources'] ?? [])) ?></span>
                          </button>
                          <?php if (!empty($row['rangeLabel'])): ?>
                            <small title="<?= $e($row['rangeLabel']) ?>"><?= $e($row['rangeLabel']) ?></small>
                          <?php endif; ?>
                        </div>
                      </td>
                      <?php foreach ((array)($card['tuneles'] ?? []) as $tunnelKey => $tunnel): ?>
                        <?php $value = (array)(($row['values'] ?? [])[$tunnelKey] ?? []); ?>
                        <td>
                          <div class="monitor-value-box <?= $e($value['class'] ?? 'unavailable') ?>" title="<?= $e(($value['statusLabel'] ?? 'Sin dato') . ' | ' . ($row['label'] ?? '')) ?>">
                            <strong><?= $e($value['value'] ?? '-') ?></strong>
                          </div>
                        </td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($mostrarSecado): ?>
    <section class="monitor-section" aria-label="Secado">
      <h2 class="monitor-section-title">Secado</h2>
      <div class="monitor-grid" data-monitor-grid>
      <?php foreach (array_merge($votatorCards, $primaryCards, $otherCards) as $card): ?>
        <article class="monitor-card <?= $e($cardClass((string)($card['key'] ?? ''))) ?>" data-card="<?= $e($card['key'] ?? '') ?>">
          <header class="monitor-card-head">
            <div class="monitor-card-title">
              <i class="fas <?= $e($card['icon'] ?? 'fa-chart-line') ?>"></i>
              <h2><?= $e($card['titulo'] ?? 'Modulo') ?></h2>
            </div>
            <span class="monitor-card-status" data-field="card-status" style="background: <?= $e($card['statusColor'] ?? '#94a3b8') ?>;">
              <?= $e($card['statusLabel'] ?? 'Referencia') ?>
            </span>
          </header>

          <div class="monitor-table-wrap">
            <table class="monitor-table">
              <?php
              $tunnelCount = max(1, count((array)($card['tuneles'] ?? [])));
              $paramWidth = $tunnelCount >= 7 ? 18 : ($tunnelCount >= 5 ? 20 : 30);
              $valueWidth = (100 - $paramWidth) / $tunnelCount;
              ?>
              <colgroup>
                <col style="width: <?= $e(n((float)$paramWidth, 2)) ?>%;">
                <?php foreach ((array)($card['tuneles'] ?? []) as $_): ?>
                  <col style="width: <?= $e(n((float)$valueWidth, 2)) ?>%;">
                <?php endforeach; ?>
              </colgroup>
              <thead>
                <tr>
                  <th>Parámetro</th>
                  <?php foreach ((array)($card['tuneles'] ?? []) as $tunnelKey => $tunnel): ?>
                    <th title="<?= $e($tunnel['titulo'] ?? 'Tunel') ?>"><?= $e($shortEquipmentTitle((string)($card['key'] ?? ''), (string)$tunnelKey, (string)($tunnel['titulo'] ?? 'Tunel'))) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ((array)($card['tabla'] ?? []) as $row): ?>
                  <tr>
                    <td>
                      <div class="monitor-param">
                        <button class="monitor-param-button" type="button" data-trend-card="<?= $e($card['key'] ?? '') ?>" data-trend-row="<?= $e($row['key'] ?? '') ?>">
                          <span><?= $e($row['label'] ?? '') ?></span>
                          <span class="monitor-source-icons"><?= $renderSourceIcons((array)($row['sources'] ?? [])) ?></span>
                        </button>
                        <?php if (!empty($row['rangeLabel'])): ?>
                          <small title="<?= $e($row['rangeLabel']) ?>"><?= $e($row['rangeLabel']) ?></small>
                        <?php endif; ?>
                      </div>
                    </td>
                      <?php foreach ((array)($card['tuneles'] ?? []) as $tunnelKey => $tunnel): ?>
                      <?php $value = (array)(($row['values'] ?? [])[$tunnelKey] ?? []); ?>
                      <td>
                        <div class="monitor-value-box <?= $e($value['class'] ?? 'unavailable') ?>" title="<?= $e(($value['statusLabel'] ?? 'Sin dato') . ' | ' . ($row['label'] ?? '')) ?>">
                          <strong><?= $e($value['value'] ?? '-') ?></strong>
                        </div>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!empty($sideCards)): ?>
        <div class="monitor-side-stack" data-side-stack>
          <?php foreach ($sideCards as $card): ?>
            <article class="monitor-card <?= $e($cardClass((string)($card['key'] ?? ''))) ?>" data-card="<?= $e($card['key'] ?? '') ?>">
              <header class="monitor-card-head">
                <div class="monitor-card-title">
                  <i class="fas <?= $e($card['icon'] ?? 'fa-chart-line') ?>"></i>
                  <h2><?= $e($card['titulo'] ?? 'Modulo') ?></h2>
                </div>
                <span class="monitor-card-status" data-field="card-status" style="background: <?= $e($card['statusColor'] ?? '#94a3b8') ?>;">
                  <?= $e($card['statusLabel'] ?? 'Referencia') ?>
                </span>
              </header>

              <div class="monitor-table-wrap">
                <table class="monitor-table">
                  <?php
                  $cardKey = (string)($card['key'] ?? '');
                  $visibleTuneles = (array)($card['tuneles'] ?? []);
                  if ($cardKey === 'concentradores') {
                    unset($visibleTuneles['invertido']);
                  }
                  $tunnelCount = max(1, count($visibleTuneles));
                  $paramWidth = $tunnelCount >= 7 ? 18 : ($tunnelCount >= 5 ? 20 : 30);
                  $valueWidth = (100 - $paramWidth) / $tunnelCount;
                  ?>
                  <colgroup>
                    <col style="width: <?= $e(n((float)$paramWidth, 2)) ?>%;">
                    <?php foreach ($visibleTuneles as $_): ?>
                      <col style="width: <?= $e(n((float)$valueWidth, 2)) ?>%;">
                    <?php endforeach; ?>
                  </colgroup>
                  <thead>
                    <tr>
                      <th>Parámetro</th>
                      <?php foreach ($visibleTuneles as $tunnelKey => $tunnel): ?>
                        <th title="<?= $e($tunnel['titulo'] ?? 'Tunel') ?>"><?= $e($shortEquipmentTitle((string)($card['key'] ?? ''), (string)$tunnelKey, (string)($tunnel['titulo'] ?? 'Tunel'))) ?></th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ((array)($card['tabla'] ?? []) as $row): ?>
                      <?php
                      $hasVisibleValue = true;
                      if ($cardKey === 'concentradores') {
                        $hasVisibleValue = false;
                        foreach ($visibleTuneles as $visibleTunnelKey => $_visibleTunnel) {
                          $visibleValue = (array)(($row['values'] ?? [])[$visibleTunnelKey] ?? []);
                          if (($visibleValue['value'] ?? '-') !== '-') {
                            $hasVisibleValue = true;
                            break;
                          }
                        }
                      }
                      if (!$hasVisibleValue) continue;
                      ?>
                      <tr>
                        <td>
                          <div class="monitor-param">
                            <button class="monitor-param-button" type="button" data-trend-card="<?= $e($card['key'] ?? '') ?>" data-trend-row="<?= $e($row['key'] ?? '') ?>">
                              <span><?= $e($row['label'] ?? '') ?></span>
                              <span class="monitor-source-icons"><?= $renderSourceIcons((array)($row['sources'] ?? [])) ?></span>
                            </button>
                            <?php if (!empty($row['rangeLabel'])): ?>
                              <small title="<?= $e($row['rangeLabel']) ?>"><?= $e($row['rangeLabel']) ?></small>
                            <?php endif; ?>
                          </div>
                        </td>
                        <?php foreach ($visibleTuneles as $tunnelKey => $tunnel): ?>
                          <?php $value = (array)(($row['values'] ?? [])[$tunnelKey] ?? []); ?>
                          <td>
                            <div class="monitor-value-box <?= $e($value['class'] ?? 'unavailable') ?>" title="<?= $e(($value['statusLabel'] ?? 'Sin dato') . ' | ' . ($row['label'] ?? '')) ?>">
                              <strong><?= $e($value['value'] ?? '-') ?></strong>
                            </div>
                          </td>
                        <?php endforeach; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($cardKey === 'concentradores' && isset($card['tuneles']['invertido'])): ?>
                <?php
                $invertidoItemsByKey = [];
                foreach ((array)($card['tuneles']['invertido']['items'] ?? []) as $item) {
                  $invertidoItemsByKey[(string)($item['key'] ?? '')] = (array)$item;
                }
                ?>
                <section class="monitor-invertido-panel" aria-label="Invertido por etapas">
                  <h3 class="monitor-invertido-title">Invertido</h3>
                  <div class="monitor-invertido-grid">
                    <?php foreach ($invertidoMetricGroups as $groupTitle => $groupKeys): ?>
                      <div class="monitor-invertido-stage">
                        <h4><?= $e($groupTitle) ?></h4>
                        <div class="monitor-invertido-items">
                          <?php foreach ($groupKeys as $itemKey): ?>
                            <?php if (!isset($invertidoItemsByKey[$itemKey])) continue; ?>
                            <?php $item = $invertidoItemsByKey[$itemKey]; ?>
                            <div class="monitor-invertido-param">
                              <button class="monitor-param-button" type="button" data-trend-card="<?= $e($card['key'] ?? '') ?>" data-trend-row="<?= $e($item['key'] ?? '') ?>">
                                <span><?= $e($item['label'] ?? '') ?></span>
                                <span class="monitor-source-icons"><?= $renderSourceIcons(!empty($item['source']) ? [(string)$item['source']] : []) ?></span>
                              </button>
                              <div class="monitor-value-box <?= $e($item['class'] ?? 'unavailable') ?>" title="<?= $e(($item['statusLabel'] ?? 'Sin dato') . ' | ' . ($item['label'] ?? '')) ?>">
                                <strong><?= $e($item['value'] ?? '-') ?></strong>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <div class="monitor-trend-modal" id="monitorTrendModal" aria-hidden="true">
    <div class="monitor-trend-dialog" role="dialog" aria-modal="true" aria-labelledby="monitorTrendTitle">
      <div class="monitor-trend-head">
        <div>
          <h2 id="monitorTrendTitle">Tendencia</h2>
          <p id="monitorTrendSubtitle">Últimos registros disponibles</p>
        </div>
        <div class="monitor-trend-actions" aria-label="Rango de tendencia">
          <button class="monitor-trend-range is-active" type="button" data-trend-range="24h">24 HRS</button>
          <button class="monitor-trend-range" type="button" data-trend-range="week">SEMANA</button>
          <button class="monitor-trend-range" type="button" data-trend-range="month">MES</button>
          <button class="monitor-trend-close" type="button" id="monitorTrendClose" aria-label="Cerrar">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
      </div>
      <div class="monitor-trend-body">
        <div class="monitor-trend-loader" id="monitorTrendLoader">
          <span><i class="fa-solid fa-circle-notch fa-spin"></i>Obteniendo datos...</span>
        </div>
        <div class="monitor-trend-chart">
          <canvas id="monitorTrendChart"></canvas>
        </div>
        <div class="monitor-trend-empty" id="monitorTrendEmpty">No hay histórico suficiente para este parámetro; se muestra el dato vigente disponible.</div>
      </div>
    </div>
  </div>

  <script>
    const refreshMs = <?= (int)($meta['intervaloActualizacion'] ?? 60000) ?>;
    const activeView = <?= json_encode($vista, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const invertidoMetricGroups = <?= json_encode($invertidoMetricGroups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const monitorState = {
      payload: <?= json_encode($clientReport, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      trendChart: null,
      trendRange: '24h',
      activeTrend: null,
    };

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char]));
    }

    const monitorBandsPlugin = {
      id: 'monitorBands',
      beforeDraw(chart, args, pluginOptions) {
        const bands = pluginOptions || {};
        const yScale = chart.scales?.y;
        const chartArea = chart.chartArea;
        if (!yScale || !chartArea || !bands.enabled) return;

        const { ctx } = chart;
        const min = yScale.min;
        const max = yScale.max;
        const clamp = (value) => Math.max(min, Math.min(max, Number(value)));
        const paint = (start, end, color) => {
          if (start === null || start === undefined || end === null || end === undefined) return;
          const safeStart = clamp(start);
          const safeEnd = clamp(end);
          if (!Number.isFinite(safeStart) || !Number.isFinite(safeEnd) || safeStart === safeEnd) return;
          const y1 = yScale.getPixelForValue(safeStart);
          const y2 = yScale.getPixelForValue(safeEnd);
          ctx.save();
          ctx.fillStyle = color;
          ctx.fillRect(chartArea.left, Math.min(y1, y2), chartArea.width, Math.abs(y2 - y1));
          ctx.restore();
        };

        const mode = bands.mode || 'rango';
        if (mode === 'bandas') {
          (bands.items || []).forEach((band) => {
            const status = String(band.estado || '');
            const color = status === 'verde'
              ? 'rgba(46, 139, 87, 0.10)'
              : (status === 'amarillo' ? 'rgba(228, 154, 50, 0.12)' : 'rgba(201, 68, 54, 0.10)');
            paint(band.min ?? min, band.max ?? max, color);
          });
          return;
        }

        if (mode === 'minimo') {
          paint(min, bands.yellowMin ?? bands.greenMin ?? max, 'rgba(201, 68, 54, 0.10)');
          paint(bands.yellowMin ?? min, bands.greenMin ?? max, 'rgba(228, 154, 50, 0.12)');
          paint(bands.greenMin ?? min, max, 'rgba(46, 139, 87, 0.10)');
          return;
        }
        if (mode === 'maximo') {
          paint(min, bands.greenMax ?? max, 'rgba(46, 139, 87, 0.10)');
          paint(bands.greenMax ?? min, bands.yellowMax ?? max, 'rgba(228, 154, 50, 0.12)');
          paint(bands.yellowMax ?? min, max, 'rgba(201, 68, 54, 0.10)');
          return;
        }

        paint(min, bands.yellowMin ?? bands.greenMin ?? min, 'rgba(201, 68, 54, 0.10)');
        paint(bands.yellowMin ?? min, bands.greenMin ?? max, 'rgba(228, 154, 50, 0.12)');
        paint(bands.greenMin ?? min, bands.greenMax ?? max, 'rgba(46, 139, 87, 0.10)');
        paint(bands.greenMax ?? min, bands.yellowMax ?? max, 'rgba(228, 154, 50, 0.12)');
        paint(bands.yellowMax ?? bands.greenMax ?? max, max, 'rgba(201, 68, 54, 0.10)');
      }
    };

    if (window.Chart && !Chart.registry.plugins.get('monitorBands')) {
      Chart.register(monitorBandsPlugin);
    }

    function renderWarnings(warnings) {
      const container = document.querySelector('[data-warnings]');
      if (!container) return;
      container.innerHTML = (warnings || []).map((warning) => (
        `<div class="monitor-warning"><i class="fas fa-triangle-exclamation"></i>${escapeHtml(warning)}</div>`
      )).join('');
    }

    function renderValueBox(item, label) {
      return `
        <div class="monitor-value-box ${escapeHtml(item?.class || 'unavailable')}" title="${escapeHtml((item?.statusLabel || 'Sin dato') + ' | ' + (label || ''))}">
          <strong>${escapeHtml(item?.value || '-')}</strong>
        </div>
      `;
    }

    function parseNumericValue(value) {
      if (value === null || value === undefined) return null;
      const number = Number(String(value).replace(/[^0-9.-]/g, ''));
      return Number.isFinite(number) ? number : null;
    }

    function parseHistoryTimestamp(point) {
      const candidates = [point?.iso, point?.timestamp_iso, point?.datetime, point?.rawTimestamp, point?.timestamp];
      for (const value of candidates) {
        if (!value) continue;
        const parsed = Date.parse(String(value));
        if (Number.isFinite(parsed)) return parsed;
      }
      return null;
    }

    function rangeCutoff(rangeKey) {
      const hours = rangeKey === 'month' ? 24 * 31 : (rangeKey === 'week' ? 24 * 7 : 24);
      return Date.now() - (hours * 60 * 60 * 1000);
    }

    function rangeLabel(rangeKey) {
      if (rangeKey === 'month') return 'Mes';
      if (rangeKey === 'week') return 'Semana';
      return '24 horas';
    }

    function trendAggregationLabel(rangeKey) {
      if (rangeKey === 'month') return 'promedio semanal';
      if (rangeKey === 'week') return 'promedio diario';
      return 'promedio por hora';
    }

    function padNumber(value) {
      return String(value).padStart(2, '0');
    }

    function weekStart(date) {
      const localDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      const day = localDate.getDay() || 7;
      localDate.setDate(localDate.getDate() - day + 1);
      localDate.setHours(0, 0, 0, 0);
      return localDate;
    }

    function trendBucket(point, rangeKey) {
      const timestamp = parseHistoryTimestamp(point);
      if (timestamp === null) return null;

      const date = new Date(timestamp);
      if (rangeKey === 'month') {
        const start = weekStart(date);
        return {
          key: start.getTime(),
          label: `${padNumber(start.getDate())}/${padNumber(start.getMonth() + 1)}`,
        };
      }

      if (rangeKey === 'week') {
        const day = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        return {
          key: day.getTime(),
          label: `${padNumber(day.getDate())}/${padNumber(day.getMonth() + 1)}`,
        };
      }

      const hour = new Date(date.getFullYear(), date.getMonth(), date.getDate(), date.getHours());
      return {
        key: hour.getTime(),
        label: `${padNumber(hour.getDate())}/${padNumber(hour.getMonth() + 1)} ${padNumber(hour.getHours())}:00`,
      };
    }

    function aggregateHistory(history, rangeKey) {
      const cutoff = rangeCutoff(rangeKey);
      const buckets = new Map();

      (history || []).forEach((point) => {
        const timestamp = parseHistoryTimestamp(point);
        if (timestamp === null || timestamp < cutoff) return;

        const numericValue = point.value === null || point.value === undefined ? null : Number(point.value);
        if (!Number.isFinite(numericValue)) return;

        const bucket = trendBucket(point, rangeKey);
        if (!bucket) return;

        const current = buckets.get(bucket.key) || { label: bucket.label, sum: 0, count: 0 };
        current.sum += numericValue;
        current.count += 1;
        buckets.set(bucket.key, current);
      });

      return new Map([...buckets.entries()]
        .sort(([a], [b]) => a - b)
        .map(([key, bucket]) => [key, {
          label: bucket.label,
          value: bucket.count > 0 ? bucket.sum / bucket.count : null,
        }]));
    }

    function setTrendRange(rangeKey) {
      monitorState.trendRange = ['24h', 'week', 'month'].includes(rangeKey) ? rangeKey : '24h';
      document.querySelectorAll('[data-trend-range]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.trendRange === monitorState.trendRange);
      });
    }

    function metricColor(index) {
      return ['#2563eb', '#0f766e', '#7c3aed', '#ea580c', '#0891b2', '#be123c', '#4f46e5', '#15803d', '#a16207'][index % 9];
    }

    function sourceIcon(source) {
      if (source === 'sqlserver') {
        return { icon: 'fa-gear', title: 'SQL Server / AVEVA' };
      }
      if (source === 'mysql_105') {
        return { icon: 'fa-book-open', title: 'MySQL 105' };
      }
      return null;
    }

    function renderSourceIcons(sources) {
      return (sources || []).map((source) => {
        const config = sourceIcon(source);
        if (!config) return '';
        return `<span class="monitor-source-icon" title="${escapeHtml(config.title)}"><i class="fa-solid ${escapeHtml(config.icon)}"></i></span>`;
      }).join('');
    }

    function shortEquipmentTitle(cardKey, equipmentKey, title) {
      const text = String(title || '');
      const key = String(equipmentKey || '');
      let match = null;

      if (cardKey === 'secadores') {
        match = key.match(/tunel_?(\d+)/i);
        if (match) return `T${match[1]}`;
      }

      if (cardKey === 'votators') {
        match = key.match(/votator_?(\d+)/i);
        if (match) return `V${match[1]}`;
      }

      if (cardKey === 'concentradores') {
        if (key === 'invertido' || text.toLowerCase() === 'invertido') return 'Inv';
        match = key.match(/concentrador_?(\d+)/i) || text.match(/concentrador\s+(\d+)/i);
        if (match) return `C${match[1]}`;
      }

      if (cardKey === 'cocedores') {
        match = key.match(/cocedor_?(\d+)/i) || text.match(/cocedor\s+(\d+)/i);
        if (match) return `Co${match[1]}`;
      }

      if (cardKey === 'clarificadores') {
        return 'Clari';
      }

      if (cardKey === 'integracion') {
        return 'Int';
      }

      return text;
    }

    function renderTable(card) {
      if (card?.key === 'concentradores') {
        return renderConcentradoresTable(card);
      }

      const tunnels = Object.entries(card.tuneles || {});
      const tunnelCount = Math.max(1, tunnels.length);
      const paramWidth = tunnelCount >= 7 ? 18 : (tunnelCount >= 5 ? 20 : 30);
      const valueWidth = (100 - paramWidth) / tunnelCount;
      return `
        <div class="monitor-table-wrap">
          <table class="monitor-table">
            <colgroup>
              <col style="width: ${paramWidth}%;">
              ${tunnels.map(() => `<col style="width: ${valueWidth}%;">`).join('')}
            </colgroup>
            <thead>
              <tr>
                <th>Parámetro</th>
                ${tunnels.map(([tunnelKey, tunnel]) => {
                  const title = tunnel.titulo || 'Tunel';
                  return `<th title="${escapeHtml(title)}">${escapeHtml(shortEquipmentTitle(card.key || '', tunnelKey, title))}</th>`;
                }).join('')}
              </tr>
            </thead>
            <tbody>
              ${(card.tabla || []).map((row) => `
                <tr>
                  <td>
                    <div class="monitor-param">
                      <button class="monitor-param-button" type="button" data-trend-card="${escapeHtml(card.key || '')}" data-trend-row="${escapeHtml(row.key || '')}">
                        <span>${escapeHtml(row.label || '')}</span>
                        <span class="monitor-source-icons">${renderSourceIcons(row.sources || [])}</span>
                      </button>
                      ${row.rangeLabel ? `<small title="${escapeHtml(row.rangeLabel)}">${escapeHtml(row.rangeLabel)}</small>` : ''}
                    </div>
                  </td>
                  ${tunnels.map(([tunnelKey]) => `<td>${renderValueBox(row.values?.[tunnelKey], row.label || '')}</td>`).join('')}
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
      `;
    }

    function renderConcentradoresTable(card) {
      const tunnels = Object.entries(card.tuneles || {}).filter(([tunnelKey]) => tunnelKey !== 'invertido');
      const tunnelCount = Math.max(1, tunnels.length);
      const paramWidth = tunnelCount >= 7 ? 18 : (tunnelCount >= 5 ? 20 : 30);
      const valueWidth = (100 - paramWidth) / tunnelCount;
      const rows = card.tabla || [];
      const visibleRows = rows.filter((row) => tunnels.some(([tunnelKey]) => String(row.values?.[tunnelKey]?.value || '-') !== '-'));
      const rowsByKey = Object.fromEntries(rows.map((row) => [String(row.key || ''), row]));

      return `
        <div class="monitor-table-wrap">
          <table class="monitor-table">
            <colgroup>
              <col style="width: ${paramWidth}%;">
              ${tunnels.map(() => `<col style="width: ${valueWidth}%;">`).join('')}
            </colgroup>
            <thead>
              <tr>
                <th>Parámetro</th>
                ${tunnels.map(([tunnelKey, tunnel]) => {
                  const title = tunnel.titulo || 'Tunel';
                  return `<th title="${escapeHtml(title)}">${escapeHtml(shortEquipmentTitle(card.key || '', tunnelKey, title))}</th>`;
                }).join('')}
              </tr>
            </thead>
            <tbody>
              ${visibleRows.map((row) => `
                <tr>
                  <td>
                    <div class="monitor-param">
                      <button class="monitor-param-button" type="button" data-trend-card="${escapeHtml(card.key || '')}" data-trend-row="${escapeHtml(row.key || '')}">
                        <span>${escapeHtml(row.label || '')}</span>
                        <span class="monitor-source-icons">${renderSourceIcons(row.sources || [])}</span>
                      </button>
                      ${row.rangeLabel ? `<small title="${escapeHtml(row.rangeLabel)}">${escapeHtml(row.rangeLabel)}</small>` : ''}
                    </div>
                  </td>
                  ${tunnels.map(([tunnelKey]) => `<td>${renderValueBox(row.values?.[tunnelKey], row.label || '')}</td>`).join('')}
                </tr>
              `).join('')}
            </tbody>
          </table>
        </div>
        ${renderInvertidoStages(card)}
      `;
    }

    function renderInvertidoStages(card) {
      if (!card?.tuneles?.invertido) return '';
      const itemsByKey = Object.fromEntries((card.tuneles.invertido.items || []).map((item) => [String(item.key || ''), item]));

      return `
        <section class="monitor-invertido-panel" aria-label="Invertido por etapas">
          <h3 class="monitor-invertido-title">Invertido</h3>
          <div class="monitor-invertido-grid">
            ${Object.entries(invertidoMetricGroups).map(([groupTitle, groupKeys]) => `
              <div class="monitor-invertido-stage">
                <h4>${escapeHtml(groupTitle)}</h4>
                <div class="monitor-invertido-items">
                  ${(groupKeys || []).map((rowKey) => {
                    const item = itemsByKey[String(rowKey || '')];
                    if (!item) return '';
                    return `
                      <div class="monitor-invertido-param">
                        <button class="monitor-param-button" type="button" data-trend-card="${escapeHtml(card.key || '')}" data-trend-row="${escapeHtml(item.key || '')}">
                          <span>${escapeHtml(item.label || '')}</span>
                          <span class="monitor-source-icons">${renderSourceIcons(item.source ? [item.source] : [])}</span>
                        </button>
                        ${renderValueBox(item, item.label || '')}
                      </div>
                    `;
                  }).join('')}
                </div>
              </div>
            `).join('')}
          </div>
        </section>
      `;
    }

    function renderHeadMetrics(card) {
      const metrics = Object.values(card?.headMetrics || {});
      if (metrics.length === 0) return '';

      return `
        <div class="monitor-card-metrics-strip">
          ${metrics.map((metric) => `
            <span class="monitor-head-metric ${escapeHtml(metric?.class || 'unavailable')}" title="${escapeHtml(metric?.statusLabel || 'Sin dato')}">
              <span class="monitor-head-label">
                <span>${escapeHtml((metric?.label || '') + (metric?.rangeLabel ? ' ' + metric.rangeLabel : ''))}</span>
                <span class="monitor-source-icons">${renderSourceIcons(metric?.source ? [metric.source] : [])}</span>
              </span>
              <strong>${escapeHtml(metric?.value || '-')}</strong>
            </span>
          `).join('')}
        </div>
      `;
    }

    function renderMetricStrip(metrics) {
      return Object.values(metrics || {}).map((metric) => `
        <span class="monitor-head-metric ${escapeHtml(metric?.class || 'unavailable')}" title="${escapeHtml(metric?.statusLabel || 'Sin dato')}">
          <span class="monitor-head-label">
            <span>${escapeHtml((metric?.label || '') + (metric?.rangeLabel ? ' ' + metric.rangeLabel : ''))}</span>
            <span class="monitor-source-icons">${renderSourceIcons(metric?.source ? [metric.source] : [])}</span>
          </span>
          <strong>${escapeHtml(metric?.value || '-')}</strong>
        </span>
      `).join('');
    }

    function findTrendRow(cardKey, rowKey) {
      const card = monitorState.payload?.cards?.[cardKey] || {};
      const rows = card.tabla || [];
      const tableRow = rows.find((row) => String(row.key || '') === String(rowKey || ''));
      if (tableRow) return tableRow;

      for (const [tunnelKey, tunnel] of Object.entries(card.tuneles || {})) {
        const item = (tunnel.items || []).find((entry) => String(entry.key || '') === String(rowKey || ''));
        if (!item) continue;

        return {
          key: item.key,
          label: item.label,
          rangeLabel: item.rangeLabel || '',
          sources: item.source ? [item.source] : [],
          rule: item.rule || {},
          unit: item.unit || '',
          trendTuneles: {
            [tunnelKey]: tunnel,
          },
          values: {
            [tunnelKey]: item,
          },
        };
      }

      return null;
    }

    function closeTrendModal() {
      const modal = document.getElementById('monitorTrendModal');
      if (!modal) return;
      const loader = document.getElementById('monitorTrendLoader');
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      if (loader) {
        loader.classList.remove('is-visible');
      }
      monitorState.activeTrend = null;
      if (monitorState.trendChart) {
        monitorState.trendChart.destroy();
        monitorState.trendChart = null;
      }
    }

    async function ensureTrendRow(cardKey, rowKey) {
      let row = findTrendRow(cardKey, rowKey);
      const values = row?.values || {};
      const hasHistory = Object.values(values).some((item) => Array.isArray(item?.history) && item.history.length > 0);
      if (hasHistory) return row;

      const url = new URL('trend.php', window.location.href);
      url.searchParams.set('card', cardKey);
      url.searchParams.set('row', rowKey);
      url.searchParams.set('_', String(Date.now()));
      const response = await fetch(url.toString(), { cache: 'no-store' });
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const payload = await response.json();
      if (payload?.error || !payload?.row) {
        throw new Error(payload?.message || 'Sin tendencia');
      }

      return payload.row;
    }

    async function openTrendModal(cardKey, rowKey, rangeKey = monitorState.trendRange) {
      setTrendRange(rangeKey);
      monitorState.activeTrend = { cardKey, rowKey };
      const card = monitorState.payload?.cards?.[cardKey];
      const modal = document.getElementById('monitorTrendModal');
      const title = document.getElementById('monitorTrendTitle');
      const subtitle = document.getElementById('monitorTrendSubtitle');
      const empty = document.getElementById('monitorTrendEmpty');
      const loader = document.getElementById('monitorTrendLoader');
      const canvas = document.getElementById('monitorTrendChart');
      if (!card || !modal || !title || !subtitle || !empty || !loader || !canvas || !window.Chart) return;

      title.textContent = 'Tendencia';
      subtitle.textContent = 'Obteniendo datos...';
      empty.classList.remove('is-visible');
      loader.classList.add('is-visible');
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');

      let row = null;
      try {
        row = await ensureTrendRow(cardKey, rowKey);
      } catch (error) {
        row = findTrendRow(cardKey, rowKey);
        if (!row) {
          loader.classList.remove('is-visible');
          empty.textContent = `No se pudo obtener la tendencia: ${error.message}`;
          empty.classList.add('is-visible');
          return;
        }
      }

      const tunnels = Object.entries(row.trendTuneles || card.tuneles || {});
      const values = row.values || {};
      const historyItems = Object.entries(values).filter(([, item]) => Array.isArray(item?.history) && item.history.length > 0);
      const hasHistory = historyItems.length > 0;
      const aggregatedByTunnel = new Map();
      const labelBuckets = new Map();

      if (hasHistory) {
        tunnels.forEach(([tunnelKey]) => {
          const item = values[tunnelKey] || {};
          const configuredTrend = item.trends?.[monitorState.trendRange];
          const trendSource = Array.isArray(configuredTrend) && configuredTrend.length > 0
            ? configuredTrend
            : (item.history || []);
          const aggregated = aggregateHistory(trendSource, monitorState.trendRange);
          aggregatedByTunnel.set(tunnelKey, aggregated);
          aggregated.forEach((bucket, key) => {
            if (!labelBuckets.has(key)) {
              labelBuckets.set(key, bucket.label);
            }
          });
        });
      }

      const orderedBucketKeys = [...labelBuckets.keys()].sort((a, b) => a - b);
      const hasAggregatedHistory = orderedBucketKeys.length > 0;
      const labels = hasAggregatedHistory
        ? orderedBucketKeys.map((key) => labelBuckets.get(key) || '-')
        : ['Actual'];

      const datasets = tunnels.map(([tunnelKey, tunnel], index) => {
        const item = values[tunnelKey] || {};
        const aggregated = aggregatedByTunnel.get(tunnelKey) || new Map();
        const data = hasAggregatedHistory
          ? orderedBucketKeys.map((bucketKey) => aggregated.get(bucketKey)?.value ?? null)
          : [parseNumericValue(item.value)];

        return {
          label: tunnel.titulo || tunnelKey,
          data,
          borderColor: metricColor(index),
          backgroundColor: metricColor(index),
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 5,
          tension: 0.25,
          spanGaps: true,
        };
      });

      const rule = row.rule || {};
      const bands = {
        enabled: Object.keys(rule).length > 0,
        mode: rule.modo || 'rango',
        greenMin: Number.isFinite(Number(rule.verde_min)) ? Number(rule.verde_min) : null,
        greenMax: Number.isFinite(Number(rule.verde_max)) ? Number(rule.verde_max) : null,
        yellowMin: Number.isFinite(Number(rule.amarillo_min)) ? Number(rule.amarillo_min) : null,
        yellowMax: Number.isFinite(Number(rule.amarillo_max)) ? Number(rule.amarillo_max) : null,
        items: Array.isArray(rule.bandas) ? rule.bandas.map((band) => ({
          min: Number.isFinite(Number(band.min)) ? Number(band.min) : null,
          max: Number.isFinite(Number(band.max)) ? Number(band.max) : null,
          estado: band.estado || 'rojo',
        })) : [],
      };

      title.textContent = `${row.label || 'Parámetro'} | ${card.titulo || ''}`;
      subtitle.textContent = `${rangeLabel(monitorState.trendRange)} | ${trendAggregationLabel(monitorState.trendRange)} | ${row.rangeLabel || 'Sin rango configurado'}`;
      empty.classList.toggle('is-visible', !hasAggregatedHistory);

      if (monitorState.trendChart) {
        monitorState.trendChart.destroy();
      }

      monitorState.trendChart = new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          animation: false,
          plugins: {
            monitorBands: bands,
            legend: {
              position: 'bottom',
              labels: { boxWidth: 10, usePointStyle: true },
            },
          },
          scales: {
            x: { ticks: { maxRotation: 0 } },
            y: { beginAtZero: false },
          },
        },
      });

      loader.classList.remove('is-visible');
    }

    function renderCard(card) {
      const sizeClass = card.key === 'secadores'
        ? 'is-primary'
        : (card.key === 'votators'
          ? 'is-over-primary'
          : (card.key === 'concentradores' ? 'is-side' : 'is-full'));
      return `
        <article class="monitor-card ${sizeClass}" data-card="${escapeHtml(card.key || '')}">
          <header class="monitor-card-head">
            <div class="monitor-card-title">
              <i class="fas ${escapeHtml(card.icon || 'fa-chart-line')}"></i>
              <h2>${escapeHtml(card.titulo || 'Modulo')}</h2>
            </div>
            <span class="monitor-card-status" data-field="card-status" style="background: ${escapeHtml(card.statusColor || '#94a3b8')};">
              ${escapeHtml(card.statusLabel || 'Referencia')}
            </span>
          </header>
          ${renderHeadMetrics(card)}
          ${renderTable(card)}
        </article>
      `;
    }

    function updateReport(report) {
      monitorState.payload = report || {};
      renderWarnings(report?.meta?.warnings || []);
      const shouldRenderExtraction = activeView === 'todo' || activeView === 'extraccion';
      const shouldRenderSecado = activeView === 'todo' || activeView === 'secado';

      if (shouldRenderExtraction) {
        const indicators = document.querySelector('[data-extraction-indicators]');
        if (indicators) indicators.innerHTML = renderMetricStrip(report?.extraccion?.indicadores || {});

        const extractionGrid = document.querySelector('[data-extraction-grid]');
        const extractionCards = ['cocedores', 'clarificadores', 'integracion']
          .map((key) => report?.cards?.[key])
          .filter(Boolean);
        if (extractionGrid) extractionGrid.innerHTML = extractionCards.map(renderCard).join('');
      }

      if (!shouldRenderSecado) return;
      const grid = document.querySelector('[data-monitor-grid]');
      if (!grid) return;
      const cards = Object.values(report?.cards || {});
      const votatorCards = cards.filter((card) => card.key === 'votators');
      const primaryCards = cards.filter((card) => card.key === 'secadores');
      const sideCards = ['concentradores']
        .map((key) => report?.cards?.[key])
        .filter(Boolean);
      const otherCards = cards.filter((card) => !['secadores', 'votators', 'concentradores', 'cocedores', 'clarificadores', 'integracion'].includes(card.key));
      const sideHtml = sideCards.length > 0
        ? `<div class="monitor-side-stack" data-side-stack>${sideCards.map(renderCard).join('')}</div>`
        : '';
      grid.innerHTML = `${votatorCards.map(renderCard).join('')}${primaryCards.map(renderCard).join('')}${otherCards.map(renderCard).join('')}${sideHtml}`;

      if (monitorState.activeTrend) {
        openTrendModal(monitorState.activeTrend.cardKey, monitorState.activeTrend.rowKey, monitorState.trendRange);
      }
    }

    async function refreshReport() {
      try {
        const response = await fetch(`data.php?t=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        updateReport(await response.json());
      } catch (error) {
        renderWarnings([`No se pudo actualizar Produccion Monitoreo: ${error.message}`]);
      }
    }

    document.addEventListener('click', (event) => {
      const rangeButton = event.target.closest('[data-trend-range]');
      if (rangeButton) {
        setTrendRange(rangeButton.dataset.trendRange || '24h');
        if (monitorState.activeTrend) {
          openTrendModal(monitorState.activeTrend.cardKey, monitorState.activeTrend.rowKey, monitorState.trendRange);
        }
        return;
      }

      const button = event.target.closest('[data-trend-card][data-trend-row]');
      if (button) {
        openTrendModal(button.dataset.trendCard || '', button.dataset.trendRow || '', '24h').catch((error) => {
          renderWarnings([`No se pudo abrir tendencia: ${error.message}`]);
        });
        return;
      }

      if (event.target.id === 'monitorTrendModal') {
        closeTrendModal();
      }
    });

    document.getElementById('monitorTrendClose')?.addEventListener('click', closeTrendModal);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeTrendModal();
      }
    });

    window.setInterval(refreshReport, refreshMs);
  </script>
</body>

</html>
