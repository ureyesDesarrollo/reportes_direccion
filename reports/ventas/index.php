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

$titulo = (string)($report['titulo'] ?? 'Ventas');
$filtros = (array)($report['filtros'] ?? []);
$kpis = (array)($report['kpis'] ?? []);
$pedidos = (array)($kpis['pedidos'] ?? []);
$pedidosPorTipo = (array)($pedidos['por_tipo'] ?? []);
$pedidosComercial = (array)($pedidosPorTipo['comercial'] ?? []);
$pedidosIndustrial = (array)($pedidosPorTipo['industrial'] ?? []);
$pedidosSinClasificar = (array)($pedidosPorTipo['sin_clasificar'] ?? []);
$pedidosTotalSemaforo = (array)($pedidos['semaforo'] ?? []);
$pedidosComercialSemaforo = (array)($pedidosComercial['semaforo'] ?? []);
$pedidosIndustrialSemaforo = (array)($pedidosIndustrial['semaforo'] ?? []);
$backorderPartidas = (array)($pedidos['partidas'] ?? []);
$backorderVenta = (array)($kpis['backorder_venta'] ?? []);
$ventas = (array)($kpis['ventas'] ?? []);
$ventasPorTipo = (array)($ventas['por_tipo'] ?? []);
$ventasComercial = (array)($ventasPorTipo['comercial'] ?? []);
$ventasIndustrial = (array)($ventasPorTipo['industrial'] ?? []);
$ventasTotalSemaforo = (array)($ventas['semaforo'] ?? []);
$precioPromedioSemaforo = (array)($ventas['precio_promedio_semaforo'] ?? []);
$ventasComercialSemaforo = (array)($ventasComercial['semaforo'] ?? []);
$ventasIndustrialSemaforo = (array)($ventasIndustrial['semaforo'] ?? []);
$precioPromedioComercialSemaforo = (array)($ventasComercial['precio_promedio_semaforo'] ?? []);
$precioPromedioIndustrialSemaforo = (array)($ventasIndustrial['precio_promedio_semaforo'] ?? []);
$calidadPorProducir = (array)($kpis['calidad_por_producir'] ?? []);
$objetivos = (array)($report['objetivos'] ?? []);
$series = (array)($report['series'] ?? []);
$dailyRows = (array)($series['diaria'] ?? []);
$meta = (array)($report['meta'] ?? []);
$version = (int)($report['version'] ?? time());
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fmt = static fn($value, int $decimals = 1): string => is_numeric($value) ? n((float)$value, $decimals) : '-';
$money = static fn($value, int $decimals = 2): string => is_numeric($value) ? '$' . n((float)$value, $decimals) : '—';
$salesTrafficLightClass = static function (array $trafficLight): string {
  $class = trim((string)($trafficLight['clase'] ?? ''));
  return in_array($class, ['avance-semaforo-verde', 'avance-semaforo-amarillo', 'avance-semaforo-rojo'], true) ? $class : '';
};
$meses = [
  1 => 'Enero',
  2 => 'Febrero',
  3 => 'Marzo',
  4 => 'Abril',
  5 => 'Mayo',
  6 => 'Junio',
  7 => 'Julio',
  8 => 'Agosto',
  9 => 'Septiembre',
  10 => 'Octubre',
  11 => 'Noviembre',
  12 => 'Diciembre',
];
$anioActual = (int)date('Y');
$chartLabels = array_map(static fn(array $row): string => (string)($row['day'] ?? ''), $dailyRows);
$chartTons = array_map(static fn(array $row): float => round((float)($row['toneladas'] ?? 0), 2), $dailyRows);
$chartComercial = array_map(static fn(array $row): float => round((float)($row['comercial_toneladas'] ?? 0), 2), $dailyRows);
$chartIndustrial = array_map(static fn(array $row): float => round((float)($row['industrial_toneladas'] ?? 0), 2), $dailyRows);
$objetivoDiario = (float)($objetivos['diario_toneladas'] ?? 0.0);
$chartTarget = array_fill(0, count($chartLabels), round($objetivoDiario, 2));
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= $e($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/css/dashboard.css') ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max($version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      background: #f4f7fb;
    }

    .ventas-dashboard {
      max-width: 1600px;
      padding: 18px 28px 22px;
    }

    .ventas-header {
      display: flex;
      align-items: center;
      gap: 20px;
      margin-bottom: 14px;
      padding: 10px 16px;
      border-radius: 20px;
      flex-wrap: nowrap;
    }

    .ventas-header-copy {
      flex: 1 1 280px;
      min-width: 0;
    }

    .ventas-header h1 {
      font-size: clamp(1.85rem, 2.7vw, 2.45rem);
    }

    .ventas-header .back-btn {
      flex: 0 0 auto;
      margin-left: auto;
    }

    .ventas-filter-form {
      display: flex;
      flex: 0 1 auto;
      align-items: flex-end;
      gap: 10px;
      margin: 0;
      padding: 0;
      border: 0;
      background: transparent;
    }

    .ventas-filter-field {
      display: grid;
      gap: 6px;
      min-width: 150px;
    }

    .ventas-filter-field label {
      color: #64748b;
      font-size: 0.72rem;
      font-weight: 800;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .ventas-filter-field .filter-select {
      min-width: 150px;
      min-height: 40px;
      padding-top: 9px;
      padding-bottom: 9px;
    }

    .ventas-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      width: 100%;
      gap: 6px;
      margin: 0 0 7px;
    }

    .ventas-summary-grid > .ventas-kpi-card { grid-column: span 1; }
    #ventasBackorderSinClasificar { grid-column: 1 / -1; }

    .ventas-kpi-card {
      position: relative;
      display: grid;
      grid-template-columns: minmax(0, .78fr) minmax(0, 1.22fr);
      grid-template-rows: auto 1fr auto;
      column-gap: 12px;
      align-items: center;
      box-sizing: border-box;
      min-height: 92px;
      padding: 7px 10px 6px;
      overflow: hidden;
    }

    .ventas-backorder-card {
      cursor: pointer;
    }

    .ventas-backorder-card:focus-visible {
      outline: 3px solid #2563eb;
      outline-offset: 3px;
    }

    .ventas-kpi-card .kpi-icon {
      position: absolute;
      top: 7px;
      left: 10px;
      margin: 0;
      font-size: .88rem;
    }

    .ventas-kpi-card .kpi-value {
      grid-column: 1;
      grid-row: 2 / 4;
      align-self: center;
      margin-bottom: 0;
      font-size: clamp(1.5rem, 2vw, 1.95rem);
      line-height: 1;
      white-space: nowrap;
    }

    .ventas-kpi-card .kpi-label {
      grid-column: 1 / -1;
      grid-row: 1;
      max-width: calc(100% - 92px);
      margin-bottom: 1px;
      padding-left: 20px;
      font-size: .7rem;
      line-height: 1.05;
    }

    .ventas-kpi-card.is-sales-status {
      min-height: 92px;
      padding: 7px 10px 6px;
    }

    .ventas-kpi-card.is-sales-status .kpi-label,
    .ventas-kpi-card.is-backorder-status .kpi-label,
    .ventas-kpi-card.is-average-price .kpi-label {
      max-width: calc(100% - 92px);
      font-size: .7rem;
      letter-spacing: .055em;
      text-transform: uppercase;
    }

    .ventas-kpi-card.is-sales-status .kpi-value {
      margin-top: 2px;
      font-size: clamp(1.6rem, 2.05vw, 2rem);
    }

    .ventas-kpi-card .kpi-trend {
      grid-column: 2;
      grid-row: 2;
      align-self: end;
      margin-top: 1px;
      padding-left: 10px;
      border-left: 1px solid rgba(255, 255, 255, .28);
      font-size: .66rem;
      line-height: 1.1;
    }

    .ventas-kpi-card .ventas-kpi-target {
      grid-column: 2;
      grid-row: 3;
      align-self: start;
      margin-top: 2px;
      padding: 3px 0 0 10px;
      border-left: 1px solid rgba(255, 255, 255, .28);
      font-size: .6rem;
      line-height: 1.05;
    }

    .ventas-kpi-status {
      position: absolute;
      top: 6px;
      right: 6px;
      padding: 3px 6px;
      border: 1px solid rgba(255,255,255,.42);
      border-radius: 999px;
      color: inherit;
      background: rgba(255,255,255,.17);
      font-size: .56rem;
      font-weight: 900;
      letter-spacing: .035em;
      line-height: 1;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .ventas-total-card {
      box-shadow: 0 8px 22px rgba(15, 23, 42, .12);
    }

    .ventas-kpi-card.is-backorder {
      background: #f8fafc;
    }

    .ventas-kpi-card.is-average-price {
      background: linear-gradient(145deg, #ffffff 0%, #ecfdf5 100%);
    }

    .ventas-kpi-card.is-average-price:not(.avance-semaforo-verde):not(.avance-semaforo-amarillo):not(.avance-semaforo-rojo) .kpi-value {
      color: #0f766e;
      font-size: clamp(1.65rem, 2.2vw, 2.15rem);
    }

    .ventas-kpi-card.is-average-price.avance-semaforo-verde,
    .ventas-kpi-card.is-average-price.avance-semaforo-amarillo,
    .ventas-kpi-card.is-average-price.avance-semaforo-rojo {
      background-image: none;
    }

    .ventas-kpi-card.is-average-price .ventas-kpi-status {
      top: 6px;
      right: 6px;
    }

    .ventas-kpi-card.is-average-price .ventas-kpi-target {
      margin-top: 2px;
    }

    .ventas-price-unit {
      font-size: .48em;
      font-weight: 800;
      letter-spacing: .04em;
      white-space: nowrap;
    }

    .ventas-unclassified-note {
      grid-column: 1 / -1;
      margin: -2px 4px 0;
      color: #64748b;
      font-size: .78rem;
      font-weight: 700;
    }

    .ventas-kpi-card.avance-semaforo-verde,
    .ventas-kpi-card.avance-semaforo-amarillo,
    .ventas-kpi-card.avance-semaforo-rojo {
      color: #ffffff;
    }

    .ventas-kpi-card.avance-semaforo-verde::before,
    .ventas-kpi-card.avance-semaforo-amarillo::before,
    .ventas-kpi-card.avance-semaforo-rojo::before {
      opacity: 0;
    }

    .ventas-kpi-card.avance-semaforo-verde .kpi-icon,
    .ventas-kpi-card.avance-semaforo-verde .kpi-label,
    .ventas-kpi-card.avance-semaforo-verde .kpi-value,
    .ventas-kpi-card.avance-semaforo-verde .kpi-trend,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-icon,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-label,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-value,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-trend,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-icon,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-label,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-value,
    .ventas-kpi-card.avance-semaforo-rojo .kpi-trend {
      color: #ffffff !important;
    }

    .ventas-kpi-card.avance-semaforo-verde {
      background: #2e8b57;
      border-color: #257447;
    }

    .ventas-kpi-card.avance-semaforo-amarillo {
      background: #facc15;
      color: #111827;
      border-color: #eab308;
    }

    .ventas-kpi-card.avance-semaforo-amarillo .kpi-icon,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-label,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-value,
    .ventas-kpi-card.avance-semaforo-amarillo .kpi-trend,
    .ventas-kpi-card.avance-semaforo-amarillo .ventas-kpi-target {
      color: #111827 !important;
    }

    .ventas-kpi-card.avance-semaforo-amarillo .ventas-kpi-status {
      border-color: rgba(17,24,39,.24);
      color: #111827;
      background: rgba(255,255,255,.32);
    }

    .ventas-kpi-card.avance-semaforo-rojo {
      background: #c94436;
      border-color: #a9362c;
    }

    .ventas-kpi-target {
      margin-top: 4px;
      padding-top: 4px;
      border-top: 1px solid rgba(148,163,184,.3);
      color: #64748b;
      font-size: .66rem;
      font-weight: 800;
      line-height: 1.15;
    }

    .ventas-kpi-card.avance-semaforo-verde .ventas-kpi-target,
    .ventas-kpi-card.avance-semaforo-rojo .ventas-kpi-target {
      color: #ffffff;
      border-top-color: rgba(255,255,255,.28);
    }

    .ventas-chart-container {
      min-width: 0;
      margin-bottom: 0;
      border-radius: 20px;
      padding: 12px;
    }

    .ventas-quality-section {
      min-width: 0;
      margin-bottom: 0;
      border-radius: 20px;
      padding: 12px;
    }

    .ventas-detail-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 8px;
      align-items: stretch;
    }

    .ventas-quality-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }

    .ventas-quality-header h3 {
      margin: 0;
      color: #0f172a;
      font-size: 1.15rem;
      font-weight: 800;
      letter-spacing: 0;
    }

    .ventas-quality-header span {
      color: #64748b;
      font-size: 0.82rem;
      font-weight: 700;
      white-space: nowrap;
    }

    .ventas-quality-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 6px;
      justify-content: start;
    }

    .ventas-quality-card {
      display: grid;
      align-content: center;
      gap: 2px;
      box-sizing: border-box;
      min-height: 63px;
      padding: 7px 8px;
      border: 1px solid #dbe7f5;
      border-radius: 14px;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease;
    }

    .ventas-quality-card:hover,
    .ventas-quality-card:focus-visible {
      box-shadow: 0 8px 18px rgba(15, 23, 42, .18);
      outline: none;
      transform: translateY(-2px);
    }

    .ventas-quality-card[data-quality-card="315"] {
      color: #ffffff;
      background: #2563eb;
      border-color: #1d4ed8;
    }

    .ventas-quality-card[data-quality-card="300"] {
      color: #ffffff;
      background: #b8860b;
      border-color: #9a6b08;
    }

    .ventas-quality-card[data-quality-card="Dorada"] {
      color: #ffffff;
      background: #d4a017;
      border-color: #b8860b;
    }

    .ventas-quality-card[data-quality-card="280"] {
      color: #ffffff;
      background: #7f1d3a;
      border-color: #67162f;
    }

    .ventas-quality-card[data-quality-card="265"]{
      color: #ffffff;
      background: #2e8b57;
      border-color: #257447;
    }

    .ventas-quality-card[data-quality-card="250"] {
      color: #ffffff;
      background: #6f6565;
      border-color: #6f6565;
    }

    .ventas-quality-card[data-quality-card="230"] {
      color: #ffffff;
      background: #6d28d9;
      border-color: #5b21b6;
    }

    .ventas-quality-card[data-quality-card="Económica"] {
      color: #ffffff;
      background: #475569;
      border-color: #334155;
    }

    .ventas-quality-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .ventas-quality-key {
      color: #0f172a;
      font-size: 0.78rem;
      font-weight: 900;
      line-height: 1;
    }

    .ventas-quality-card[data-quality-card] .ventas-quality-key,
    .ventas-quality-card[data-quality-card] .ventas-quality-value,
    .ventas-quality-card[data-quality-card] .ventas-quality-meta,
    .ventas-quality-card[data-quality-card] .ventas-quality-detail {
      color: inherit;
    }

    .ventas-quality-value {
      color: #0f172a;
      font-size: clamp(1.12rem, 1.5vw, 1.5rem);
      font-weight: 900;
      line-height: 1;
      white-space: nowrap;
    }

    .ventas-quality-detail {
      margin-top: 2px;
      color: #475569;
      font-size: .59rem;
      font-weight: 800;
      line-height: 1.25;
      opacity: .9;
      white-space: nowrap;
    }

    .ventas-quality-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      color: #64748b;
      font-size: 0.74rem;
      font-weight: 800;
    }

    .ventas-chart-box {
      height: 250px;
      min-height: 0;
    }

    .ventas-chart-box canvas {
      width: 100% !important;
      height: 100% !important;
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
      transition: all 0.2s;
    }

    .back-btn:hover {
      background: #eff6ff;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
      transform: translateX(-2px);
    }

    .ventas-warning {
      margin-bottom: 14px;
      padding: 10px 12px;
      border: 1px solid #f59e0b;
      border-radius: 8px;
      color: #78350f;
      background: #fef3c7;
      font-size: 13px;
      font-weight: 800;
    }

    .ventas-backorder-modal {
      position: fixed;
      inset: 0;
      z-index: 1000;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .ventas-backorder-modal.is-open {
      display: flex;
    }

    .ventas-backorder-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(15, 23, 42, .68);
      backdrop-filter: blur(3px);
    }

    .ventas-backorder-dialog {
      position: relative;
      z-index: 1;
      width: min(1180px, 100%);
      max-height: calc(100vh - 48px);
      overflow: hidden;
      border: 1px solid #dbe7f5;
      border-radius: 20px;
      background: #ffffff;
      box-shadow: 0 28px 80px rgba(15, 23, 42, .28);
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr);
    }

    .ventas-backorder-modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      padding: 18px 20px;
      border-bottom: 1px solid #e2e8f0;
    }

    .ventas-backorder-modal-header h2 {
      margin: 0;
      color: #0f172a;
      font-size: 1.35rem;
      font-weight: 800;
    }

    .ventas-backorder-close {
      width: 38px;
      height: 38px;
      border: 0;
      border-radius: 999px;
      color: #475569;
      background: #f1f5f9;
      cursor: pointer;
      font-size: 1rem;
    }

    .ventas-backorder-summary {
      padding: 12px 20px;
      color: #475569;
      background: #f8fafc;
      border-bottom: 1px solid #e2e8f0;
      font-size: .86rem;
      font-weight: 800;
    }

    .ventas-backorder-table-wrap {
      min-height: 0;
      overflow: auto;
    }

    .ventas-backorder-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .82rem;
    }

    .ventas-backorder-table th,
    .ventas-backorder-table td {
      padding: 11px 12px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
      vertical-align: middle;
    }

    .ventas-backorder-table th {
      position: sticky;
      top: 0;
      z-index: 1;
      color: #334155;
      background: #f1f5f9;
      font-size: .7rem;
      font-weight: 900;
      letter-spacing: .05em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .ventas-backorder-table td.is-number,
    .ventas-backorder-table th.is-number {
      text-align: right;
      white-space: nowrap;
    }

    .ventas-backorder-quality {
      display: inline-flex;
      padding: 4px 8px;
      border-radius: 999px;
      color: #1e3a8a;
      background: #dbeafe;
      font-weight: 900;
      white-space: nowrap;
    }

    .ventas-backorder-status {
      display: inline-flex;
      padding: 4px 8px;
      border-radius: 999px;
      color: #7c2d12;
      background: #ffedd5;
      font-weight: 900;
      white-space: nowrap;
    }

    .ventas-backorder-status.is-partial {
      color: #854d0e;
      background: #fef9c3;
    }

    .ventas-backorder-empty {
      padding: 36px 20px !important;
      color: #64748b;
      text-align: center !important;
      font-weight: 800;
    }

    body.ventas-modal-open {
      overflow: hidden;
    }

    @media (max-width: 700px) {
      .ventas-header {
        align-items: center;
        flex-wrap: wrap;
      }

      .ventas-header .back-btn {
        padding-inline: 11px;
      }

      .ventas-filter-form {
        order: 3;
        width: 100%;
      }

      .ventas-filter-field {
        flex: 1 1 130px;
        min-width: 0;
      }

      .ventas-filter-field .filter-select {
        width: 100%;
        min-width: 0;
      }

      .ventas-summary-grid {
        grid-template-columns: 1fr;
      }

      .ventas-summary-grid > * {
        grid-column: auto !important;
      }

      .ventas-detail-grid {
        grid-template-columns: 1fr;
      }

      .ventas-quality-grid {
        grid-template-columns: 1fr;
      }

      .ventas-backorder-modal {
        padding: 10px;
      }

      .ventas-backorder-dialog {
        max-height: calc(100vh - 20px);
        border-radius: 14px;
      }
    }

    @media (min-width: 701px) and (max-width: 1050px) {
      .ventas-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .ventas-summary-grid > * {
        grid-column: auto !important;
      }

      .ventas-detail-grid {
        grid-template-columns: 1fr;
      }
    }

    /* El modo pantalla aumenta legibilidad sin alterar la matriz 3 × 3. */
    html.executive-display .ventas-dashboard .ventas-summary-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
      gap: 6px;
      margin: 0 0 7px;
    }

    html.executive-display .ventas-dashboard .ventas-summary-grid > .ventas-kpi-card {
      grid-column: span 1 !important;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card {
      display: grid;
      grid-template-columns: minmax(0, .78fr) minmax(0, 1.22fr);
      grid-template-rows: auto 1fr auto;
      column-gap: 12px;
      min-height: 92px;
      padding: 7px 10px 6px;
      border-radius: 24px;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card.is-sales-status {
      min-height: 92px;
      padding: 7px 10px 6px;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card .kpi-icon {
      top: 7px;
      left: 10px;
      margin: 0;
      font-size: .88rem;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card .kpi-label,
    html.executive-display .ventas-dashboard .ventas-kpi-card.is-sales-status .kpi-label,
    html.executive-display .ventas-dashboard .ventas-kpi-card.is-backorder-status .kpi-label,
    html.executive-display .ventas-dashboard .ventas-kpi-card.is-average-price .kpi-label {
      grid-column: 1 / -1;
      grid-row: 1;
      max-width: calc(100% - 92px);
      margin-bottom: 1px;
      padding-left: 20px;
      font-size: .7rem;
      line-height: 1.05;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card .kpi-value {
      grid-column: 1;
      grid-row: 2 / 4;
      align-self: center;
      margin: 0;
      font-size: clamp(1.5rem, 2vw, 1.95rem);
      line-height: 1;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card.is-sales-status .kpi-value {
      margin-top: 2px;
      font-size: clamp(1.6rem, 2.05vw, 2rem);
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card .kpi-trend {
      grid-column: 2;
      grid-row: 2;
      align-self: end;
      margin-top: 1px;
      font-size: .66rem;
      line-height: 1.1;
    }

    html.executive-display .ventas-dashboard .ventas-kpi-card .ventas-kpi-target {
      grid-column: 2;
      grid-row: 3;
      align-self: start;
      margin-top: 2px;
      font-size: .6rem;
      line-height: 1.05;
    }
  </style>
</head>

<body>
  <main class="dashboard ventas-dashboard">
    <header class="filters ventas-header">
      <div class="ventas-header-copy">
        <h1><?= $e($titulo) ?></h1>
        <p><?= $e((string)($filtros['desde'] ?? '')) ?> al <?= $e((string)($filtros['hasta'] ?? '')) ?></p>
      </div>

      <form class="ventas-filter-form" method="get" id="ventasFilters">
        <div class="ventas-filter-field">
          <label>Año</label>
          <select class="filter-select" name="anio">
            <?php for ($year = $anioActual + 1; $year >= $anioActual - 4; $year--): ?>
              <option value="<?= $e($year) ?>" <?= (int)($filtros['anio'] ?? 0) === $year ? 'selected' : '' ?>><?= $e($year) ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="ventas-filter-field">
          <label>Mes</label>
          <select class="filter-select" name="mes">
            <?php foreach ($meses as $monthNumber => $monthName): ?>
              <option value="<?= $e($monthNumber) ?>" <?= (int)($filtros['mes'] ?? 0) === $monthNumber ? 'selected' : '' ?>><?= $e($monthName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>

      <a class="back-btn" href="../index.php" data-smart-back="reports-index">
        <i class="fas fa-arrow-left"></i>
        Regresar al inicio
      </a>
    </header>

    <?php foreach ((array)($meta['warnings'] ?? []) as $warning): ?>
      <div class="ventas-warning"><?= $e($warning) ?></div>
    <?php endforeach; ?>

    <section class="kpi-grid ventas-summary-grid" aria-label="Indicadores de ventas">
      <article class="kpi-card ventas-kpi-card is-sales-status is-industrial <?= $e($salesTrafficLightClass($ventasIndustrialSemaforo)) ?>" id="ventasKpiIndustrialCard">
        <span class="ventas-kpi-status" id="ventasKpiIndustrialEstado"><?= $e($ventasIndustrialSemaforo['estado_label'] ?? 'Pendiente') ?></span><div class="kpi-icon"><i class="fa-solid fa-industry"></i></div><div class="kpi-label">Venta industrial</div><div class="kpi-value" id="ventasKpiIndustrialToneladas"><?= $e($fmt($ventasIndustrial['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiIndustrialDetalle">Facturas: <?= $e($fmt($ventasIndustrial['facturas_toneladas'] ?? null, 1)) ?> TON · Remisiones: <?= $e($fmt($ventasIndustrial['remisiones_toneladas'] ?? null, 1)) ?> TON</div><div class="ventas-kpi-target" id="ventasKpiIndustrialMeta"><?= $e($ventasIndustrialSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card ventas-backorder-card is-industrial is-backorder is-backorder-status <?= $e($salesTrafficLightClass($pedidosIndustrialSemaforo)) ?>" id="ventasKpiBackorderIndustrialCard" data-backorder-type="industrial" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="ventasBackorderModal">
        <span class="ventas-kpi-status" id="ventasKpiBackorderIndustrialEstado"><?= $e($pedidosIndustrialSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></div><div class="kpi-label">Back Order industrial</div><div class="kpi-value" id="ventasKpiBackorderIndustrial"><?= $e($fmt($pedidosIndustrial['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiBackorderIndustrialDetalle"><?= $e($fmt($pedidosIndustrial['cantidad'] ?? null, 0)) ?> pedidos · Ver partidas</div><div class="ventas-kpi-target" id="ventasKpiBackorderIndustrialMeta"><?= $e($pedidosIndustrialSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card is-average-price <?= $e($salesTrafficLightClass($precioPromedioIndustrialSemaforo)) ?>" id="ventasKpiPrecioPromedioIndustrialCard">
        <span class="ventas-kpi-status" id="ventasKpiPrecioPromedioIndustrialEstado"><?= $e($precioPromedioIndustrialSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-tags"></i></div><div class="kpi-label">Precio promedio industrial</div><div class="kpi-value" id="ventasKpiPrecioPromedioIndustrial"><?= $e($money($ventasIndustrial['precio_promedio'] ?? null, 2)) ?> <span class="ventas-price-unit">/ KG</span></div><div class="kpi-trend" id="ventasKpiPrecioPromedioIndustrialDetalle">Venta neta: <?= $e($money($ventasIndustrial['monto'] ?? null, 0)) ?> · <?= $e($fmt($ventasIndustrial['kilos'] ?? null, 0)) ?> kg</div><div class="ventas-kpi-target" id="ventasKpiPrecioPromedioIndustrialMeta"><?= $e($precioPromedioIndustrialSemaforo['detalle'] ?? '') ?></div>
      </article>

      <article class="kpi-card ventas-kpi-card is-sales-status is-comercial <?= $e($salesTrafficLightClass($ventasComercialSemaforo)) ?>" id="ventasKpiComercialCard">
        <span class="ventas-kpi-status" id="ventasKpiComercialEstado"><?= $e($ventasComercialSemaforo['estado_label'] ?? 'Pendiente') ?></span><div class="kpi-icon"><i class="fa-solid fa-store"></i></div><div class="kpi-label">Venta comercial</div><div class="kpi-value" id="ventasKpiComercialToneladas"><?= $e($fmt($ventasComercial['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiComercialDetalle">Facturas: <?= $e($fmt($ventasComercial['facturas_toneladas'] ?? null, 1)) ?> TON · Remisiones: <?= $e($fmt($ventasComercial['remisiones_toneladas'] ?? null, 1)) ?> TON</div><div class="ventas-kpi-target" id="ventasKpiComercialMeta"><?= $e($ventasComercialSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card ventas-backorder-card is-comercial is-backorder is-backorder-status <?= $e($salesTrafficLightClass($pedidosComercialSemaforo)) ?>" id="ventasKpiBackorderComercialCard" data-backorder-type="comercial" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="ventasBackorderModal">
        <span class="ventas-kpi-status" id="ventasKpiBackorderComercialEstado"><?= $e($pedidosComercialSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-truck-ramp-box"></i></div><div class="kpi-label">Back Order comercial</div><div class="kpi-value" id="ventasKpiBackorderComercial"><?= $e($fmt($pedidosComercial['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiBackorderComercialDetalle"><?= $e($fmt($pedidosComercial['cantidad'] ?? null, 0)) ?> pedidos · Ver partidas</div><div class="ventas-kpi-target" id="ventasKpiBackorderComercialMeta"><?= $e($pedidosComercialSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card is-average-price <?= $e($salesTrafficLightClass($precioPromedioComercialSemaforo)) ?>" id="ventasKpiPrecioPromedioComercialCard">
        <span class="ventas-kpi-status" id="ventasKpiPrecioPromedioComercialEstado"><?= $e($precioPromedioComercialSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-tags"></i></div><div class="kpi-label">Precio promedio comercial</div><div class="kpi-value" id="ventasKpiPrecioPromedioComercial"><?= $e($money($ventasComercial['precio_promedio'] ?? null, 2)) ?> <span class="ventas-price-unit">/ KG</span></div><div class="kpi-trend" id="ventasKpiPrecioPromedioComercialDetalle">Venta neta: <?= $e($money($ventasComercial['monto'] ?? null, 0)) ?> · <?= $e($fmt($ventasComercial['kilos'] ?? null, 0)) ?> kg</div><div class="ventas-kpi-target" id="ventasKpiPrecioPromedioComercialMeta"><?= $e($precioPromedioComercialSemaforo['detalle'] ?? '') ?></div>
      </article>

      <article class="kpi-card ventas-kpi-card is-sales-status ventas-total-card <?= $e($salesTrafficLightClass($ventasTotalSemaforo)) ?>" id="ventasKpiTotalCard">
        <span class="ventas-kpi-status" id="ventasKpiTotalEstado"><?= $e($ventasTotalSemaforo['estado_label'] ?? 'Pendiente') ?></span><div class="kpi-icon"><i class="fa-solid fa-chart-line"></i></div><div class="kpi-label">Venta total</div><div class="kpi-value" id="ventasKpiTotalToneladas"><?= $e($fmt($ventas['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiTotalDetalle">Facturas: <?= $e($fmt($ventas['facturas_toneladas'] ?? null, 1)) ?> TON · Remisiones: <?= $e($fmt($ventas['remisiones_toneladas'] ?? null, 1)) ?> TON</div><div class="ventas-kpi-target" id="ventasKpiTotalMeta"><?= $e($ventasTotalSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card ventas-backorder-card is-backorder is-backorder-status <?= $e($salesTrafficLightClass($pedidosTotalSemaforo)) ?>" id="ventasKpiBackorderTotalCard" data-backorder-type="total" role="button" tabindex="0" aria-haspopup="dialog" aria-controls="ventasBackorderModal">
        <span class="ventas-kpi-status" id="ventasKpiBackorderTotalEstado"><?= $e($pedidosTotalSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-layer-group"></i></div><div class="kpi-label">Back Order total</div><div class="kpi-value" id="ventasKpiBackorderTotal"><?= $e($fmt($pedidos['toneladas'] ?? null, 1)) ?> TON</div><div class="kpi-trend" id="ventasKpiBackorderTotalDetalle"><?= $e($fmt($pedidos['cantidad'] ?? null, 0)) ?> pedidos · Ver partidas</div><div class="ventas-kpi-target" id="ventasKpiBackorderTotalMeta"><?= $e($pedidosTotalSemaforo['detalle'] ?? '') ?></div>
      </article>
      <article class="kpi-card ventas-kpi-card is-average-price <?= $e($salesTrafficLightClass($precioPromedioSemaforo)) ?>" id="ventasKpiPrecioPromedioCard">
        <span class="ventas-kpi-status" id="ventasKpiPrecioPromedioEstado"><?= $e($precioPromedioSemaforo['estado_label'] ?? 'Sin dato') ?></span><div class="kpi-icon"><i class="fa-solid fa-tags"></i></div><div class="kpi-label">Precio promedio total</div><div class="kpi-value" id="ventasKpiPrecioPromedio"><?= $e($money($ventas['precio_promedio'] ?? null, 2)) ?> <span class="ventas-price-unit">/ KG</span></div><div class="kpi-trend" id="ventasKpiPrecioPromedioDetalle">Venta neta: <?= $e($money($ventas['monto_total'] ?? null, 0)) ?> · <?= $e($fmt($ventas['kilos'] ?? null, 0)) ?> kg</div><div class="ventas-kpi-target" id="ventasKpiPrecioPromedioMeta"><?= $e($precioPromedioSemaforo['detalle'] ?? '') ?></div>
      </article>

      <div class="ventas-unclassified-note" id="ventasBackorderSinClasificar" <?= (float)($pedidosSinClasificar['toneladas'] ?? 0) > 0 ? '' : 'hidden' ?>>Back Order sin clasificar: <?= $e($fmt($pedidosSinClasificar['toneladas'] ?? null, 1)) ?> TON</div>
    </section>

    <div class="ventas-detail-grid">
    <section class="chart-container ventas-chart-container">
      <div class="chart-header">
        <h3>Toneladas vendidas por día</h3>
        <div class="legend">
          <span class="legend-item"><i class="legend-color" style="background:#0f766e;"></i> Comercial</span>
          <span class="legend-item"><i class="legend-color" style="background:#2563eb;"></i> Industrial</span>
          <span class="legend-item"><i class="legend-color" style="background:#0f172a;"></i> Meta diaria</span>
        </div>
      </div>
      <div class="ventas-chart-box">
        <canvas id="ventasDailyChart"></canvas>
      </div>
    </section>

    <section class="chart-container ventas-quality-section" aria-label="Calidad por producir">
      <div class="ventas-quality-header">
        <h3>Calidad por producir</h3>
        <span>Agrupado por Bloom</span>
      </div>
      <div class="ventas-quality-grid" id="ventasQualityGrid">
        <?php foreach ($calidadPorProducir as $producto): ?>
          <article class="ventas-quality-card" data-quality-card="<?= $e($producto['grupo'] ?? '') ?>" role="button" tabindex="0" aria-label="Ver detalle de inventario Bloom <?= $e($producto['grupo'] ?? '') ?>">
            <div class="ventas-quality-top">
              <div class="ventas-quality-key"><?= is_numeric($producto['grupo'] ?? null) ? 'Bloom ' : '' ?><?= $e($producto['grupo'] ?? '-') ?></div>
          </div>
          <div class="ventas-quality-value" data-quality-value><?= $e($fmt($producto['toneladas'] ?? null, 1)) ?> TON</div>
          <div class="ventas-quality-detail">Pedido <?= $e($fmt($producto['toneladas_pedido'] ?? null, 1)) ?> · Disponible <?= $e($fmt($producto['toneladas_inventario'] ?? null, 1)) ?> TON</div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
    </div>
  </main>

  <div class="ventas-backorder-modal" id="ventasBackorderModal" aria-hidden="true">
    <div class="ventas-backorder-backdrop" data-backorder-close></div>
    <section
      class="ventas-backorder-dialog"
      role="dialog"
      aria-modal="true"
      aria-labelledby="ventasBackorderTitle">
      <header class="ventas-backorder-modal-header">
        <h2 id="ventasBackorderTitle">Partidas de Back Order</h2>
        <button class="ventas-backorder-close" type="button" data-backorder-close aria-label="Cerrar detalle">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </header>
      <div class="ventas-backorder-summary" id="ventasBackorderSummary"></div>
      <div class="ventas-backorder-table-wrap">
        <table class="ventas-backorder-table">
          <thead>
            <tr>
              <th>Pedido</th>
              <th>Estatus</th>
              <th>Partida</th>
              <th>Cliente</th>
              <th>Calidad</th>
              <th>Clave</th>
              <th class="is-number">TON solicitadas</th>
              <th class="is-number">TON pendientes</th>
            </tr>
          </thead>
          <tbody id="ventasBackorderTableBody"></tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="ventas-backorder-modal" id="ventasQualityModal" aria-hidden="true">
    <div class="ventas-backorder-backdrop" data-quality-close></div>
    <section class="ventas-backorder-dialog" role="dialog" aria-modal="true" aria-labelledby="ventasQualityTitle">
      <header class="ventas-backorder-modal-header">
        <h2 id="ventasQualityTitle">Detalle de inventario</h2>
        <button class="ventas-backorder-close" type="button" data-quality-close aria-label="Cerrar detalle">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </header>
      <div class="ventas-backorder-summary" id="ventasQualitySummary"></div>
      <div class="ventas-backorder-table-wrap">
        <table class="ventas-backorder-table">
          <thead>
            <tr>
              <th>Revoltura</th>
              <th>Origen</th>
              <th>Cliente asignado</th>
              <th>Calidad</th>
              <th>Presentación</th>
              <th class="is-number">Unidades</th>
              <th class="is-number">Kg</th>
            </tr>
          </thead>
          <tbody id="ventasQualityTableBody"></tbody>
        </table>
      </div>
    </section>
  </div>

  <script>
    const ventasRefreshMs = <?= (int)($meta['intervaloActualizacion'] ?? 600000) ?>;
    let ventasBackorderPartidas = <?= json_encode($backorderPartidas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let ventasBackorderTipoActivo = 'comercial';
    let ventasCalidadPorProducir = <?= json_encode($calidadPorProducir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = document.documentElement.classList.contains('executive-display') ? 12 : 11;
    Chart.defaults.color = '#475569';

    function ventasFormatNumber(value, decimals = 1) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '-';
      decimals = Math.max(0, Math.min(2, Math.trunc(Number(decimals) || 0)));
      return number.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      });
    }

    function ventasFormatCurrency(value, decimals = 2) {
      const number = Number(value);
      if (!Number.isFinite(number)) return '—';
      decimals = Math.max(0, Math.min(2, Math.trunc(Number(decimals) || 0)));
      return `$${number.toLocaleString('en-US', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
      })}`;
    }

    function escapeHtml(value) {
      return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char]));
    }

    function ventasCalidadLabel(value) {
      const calidad = String(value ?? '').trim();
      if (calidad === '') return 'Sin clasificar';
      return Number.isFinite(Number(calidad)) ? `Bloom ${calidad}` : calidad;
    }

    function ventasBackorderStatusClass(value) {
      return String(value ?? '').trim().toLowerCase() === 'parcial' ? ' is-partial' : '';
    }

    function renderBackorderPartidas() {
      const tbody = document.getElementById('ventasBackorderTableBody');
      const summary = document.getElementById('ventasBackorderSummary');
      if (!tbody || !summary) return;

      const allRows = Array.isArray(ventasBackorderPartidas) ? ventasBackorderPartidas : [];
      const rows = ventasBackorderTipoActivo === 'total'
        ? allRows
        : allRows.filter((row) => String(row.tipo_venta || 'sin_clasificar') === ventasBackorderTipoActivo);
      const toneladasSolicitadas = rows.reduce((total, row) => total + Number(row.toneladas_solicitadas || 0), 0);
      const toneladasPendientes = rows.reduce((total, row) => total + Number(row.toneladas_pendientes || 0), 0);

      summary.textContent = `${ventasFormatNumber(rows.length, 0)} partidas · ${ventasFormatNumber(toneladasSolicitadas, 1)} TON solicitadas · ${ventasFormatNumber(toneladasPendientes, 1)} TON pendientes`;
      tbody.innerHTML = rows.length
        ? rows.map((row) => `
          <tr>
            <td>${escapeHtml(row.pedido || '-')}</td>
            <td><span class="ventas-backorder-status${ventasBackorderStatusClass(row.estatus)}">${escapeHtml(row.estatus || 'Sin estatus')}</span></td>
            <td>${escapeHtml(row.partida || '-')}</td>
            <td><strong>${escapeHtml(row.cliente || 'Cliente sin identificar')}</strong></td>
            <td><span class="ventas-backorder-quality">${escapeHtml(ventasCalidadLabel(row.calidad))}</span></td>
            <td>${escapeHtml(row.clave_producto || '-')}</td>
            <td class="is-number">${ventasFormatNumber(row.toneladas_solicitadas, 2)}</td>
            <td class="is-number">${ventasFormatNumber(row.toneladas_pendientes, 2)}</td>
          </tr>
        `).join('')
        : '<tr><td class="ventas-backorder-empty" colspan="8">No hay partidas disponibles para este Back Order.</td></tr>';
    }

    function openBackorderModal(tipo = 'comercial') {
      const modal = document.getElementById('ventasBackorderModal');
      if (!modal) return;
      ventasBackorderTipoActivo = tipo;
      const title = document.getElementById('ventasBackorderTitle');
      if (title) {
        const tipoLabel = tipo === 'total' ? 'Total' : (tipo === 'industrial' ? 'Industrial' : 'Comercial');
        title.textContent = `Partidas de Back Order · ${tipoLabel}`;
      }
      renderBackorderPartidas();
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('ventas-modal-open');
      modal.querySelector('.ventas-backorder-close')?.focus();
    }

    function closeBackorderModal() {
      const modal = document.getElementById('ventasBackorderModal');
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('ventas-modal-open');
      document.querySelector(`[data-backorder-type="${ventasBackorderTipoActivo}"]`)?.focus();
    }

    function renderCalidadPorProducir(rows) {
      const grid = document.getElementById('ventasQualityGrid');
      if (!grid) return;

      grid.innerHTML = (rows || []).map((row) => `
        <article class="ventas-quality-card" data-quality-card="${escapeHtml(row.grupo || '')}" role="button" tabindex="0" aria-label="Ver detalle de inventario Bloom ${escapeHtml(row.grupo || '')}">
          <div class="ventas-quality-top">
            <div class="ventas-quality-key">${Number.isFinite(Number(row.grupo)) ? 'Bloom ' : ''}${escapeHtml(row.grupo || '-')}</div>
          </div>
          <div class="ventas-quality-value" data-quality-value>${ventasFormatNumber(row.toneladas, 1)} TON</div>
          <div class="ventas-quality-detail">Pedido ${ventasFormatNumber(row.toneladas_pedido, 1)} · Disponible ${ventasFormatNumber(row.toneladas_inventario, 1)} TON</div>
        </article>
      `).join('');
    }

    function openQualityInventoryModal(group) {
      const modal = document.getElementById('ventasQualityModal');
      const title = document.getElementById('ventasQualityTitle');
      const summary = document.getElementById('ventasQualitySummary');
      const tbody = document.getElementById('ventasQualityTableBody');
      if (!modal || !title || !summary || !tbody) return;

      const quality = ventasCalidadPorProducir.find((row) => String(row.grupo) === String(group));
      const rows = Array.isArray(quality?.inventario_detalle) ? quality.inventario_detalle : [];
      const kilos = rows.reduce((total, row) => total + Number(row.kilos || 0), 0);
      title.textContent = `Inventario · ${Number.isFinite(Number(group)) ? 'Bloom ' : ''}${group}`;
      summary.textContent = `${ventasFormatNumber(rows.length, 0)} registros · ${ventasFormatNumber(kilos, 1)} kg en existencia`;
      tbody.innerHTML = rows.length ? rows.map((row) => `
        <tr>
          <td><strong>${escapeHtml(row.folio || '-')}</strong></td>
          <td>${escapeHtml(row.origen || 'Sin asignar')}</td>
          <td>${row.cliente ? `${escapeHtml(row.cliente)}${row.cliente_id ? ` (#${escapeHtml(row.cliente_id)})` : ''}` : '—'}</td>
          <td><span class="ventas-backorder-quality">${escapeHtml(row.calidad || '-')}</span></td>
          <td>${escapeHtml(row.presentacion || '-')} (${ventasFormatNumber(row.kg_presentacion, 2)} kg)</td>
          <td class="is-number">${ventasFormatNumber(row.unidades, 2)}</td>
          <td class="is-number"><strong>${ventasFormatNumber(row.kilos, 2)}</strong></td>
        </tr>
      `).join('') : '<tr><td class="ventas-backorder-empty" colspan="7">No hay producto empacado disponible para esta calidad.</td></tr>';

      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('ventas-modal-open');
      modal.querySelector('.ventas-backorder-close')?.focus();
    }

    function closeQualityInventoryModal() {
      const modal = document.getElementById('ventasQualityModal');
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      if (!document.getElementById('ventasBackorderModal')?.classList.contains('is-open')) {
        document.body.classList.remove('ventas-modal-open');
      }
    }

    const ventasValueLabelPlugin = {
      id: 'ventasValueLabels',
      afterDatasetsDraw(chart) {
        const { ctx } = chart;
        ctx.save();
        ctx.font = '10px Inter, sans-serif';
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

    let ventasDailyChart = null;
    const ventasDailyCanvas = document.getElementById('ventasDailyChart');
    if (ventasDailyCanvas) {
      ventasDailyChart = new Chart(ventasDailyCanvas, {
        data: {
          labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
          datasets: [{
            type: 'bar',
            label: 'Comercial',
            data: <?= json_encode($chartComercial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            backgroundColor: '#0f766e',
            borderColor: '#0f766e',
            borderWidth: 0,
            borderRadius: 5,
            maxBarThickness: 22,
            yAxisID: 'y',
            categoryPercentage: 0.78,
            barPercentage: 0.9,
          }, {
            type: 'bar',
            label: 'Industrial',
            data: <?= json_encode($chartIndustrial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            backgroundColor: '#2563eb',
            borderColor: '#2563eb',
            borderWidth: 0,
            borderRadius: 5,
            maxBarThickness: 22,
            yAxisID: 'y',
            categoryPercentage: 0.78,
            barPercentage: 0.9,
          }, {
            type: 'line',
            label: 'Meta diaria',
            data: <?= json_encode($chartTarget, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
            borderColor: '#0f172a',
            backgroundColor: '#0f172a',
            pointRadius: 0,
            borderWidth: 3,
            tension: 0,
            yAxisID: 'y',
            stack: 'meta',
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
              grid: { color: 'rgba(148, 163, 184, 0.24)' },
            }
          }
        },
        plugins: [ventasValueLabelPlugin]
      });
    }

    function updateVentasChart(report) {
      if (!ventasDailyChart) return;
      const rows = report?.series?.diaria || [];
      const labels = rows.map((row) => String(row.day ?? ''));
      const toneladas = rows.map((row) => Number(row.toneladas || 0));
      const comercial = rows.map((row) => Number(row.comercial_toneladas || 0));
      const industrial = rows.map((row) => Number(row.industrial_toneladas || 0));
      const objetivoDiario = Number(report?.objetivos?.diario_toneladas || 0);

      ventasDailyChart.data.labels = labels;
      ventasDailyChart.data.datasets[0].data = comercial;
      ventasDailyChart.data.datasets[1].data = industrial;
      ventasDailyChart.data.datasets[2].data = labels.map(() => objetivoDiario);
      ventasDailyChart.options.scales.y.suggestedMax = Math.max(objetivoDiario + 5, Math.max(0, ...toneladas) + 4);
      ventasDailyChart.update('none');
    }

    function updateSalesTrafficLight(cardId, targetId, statusId, trafficLight) {
      const card = document.getElementById(cardId);
      const target = document.getElementById(targetId);
      const status = document.getElementById(statusId);
      const allowed = ['avance-semaforo-verde', 'avance-semaforo-amarillo', 'avance-semaforo-rojo'];
      if (card) {
        card.classList.remove(...allowed);
        const className = String(trafficLight?.clase || '');
        if (allowed.includes(className)) card.classList.add(className);
      }
      if (target) target.textContent = String(trafficLight?.detalle || '');
      if (status) status.textContent = String(trafficLight?.estado_label || 'Pendiente');
    }

    function updateVentasReport(report) {
      const ventas = report?.kpis?.ventas || {};
      const comercial = ventas?.por_tipo?.comercial || {};
      const industrial = ventas?.por_tipo?.industrial || {};
      const pedidos = report?.kpis?.pedidos || {};
      const pedidosComercial = pedidos?.por_tipo?.comercial || {};
      const pedidosIndustrial = pedidos?.por_tipo?.industrial || {};
      const pedidosSinClasificar = pedidos?.por_tipo?.sin_clasificar || {};
      const backorderVenta = report?.kpis?.backorder_venta || {};
      const calidadPorProducir = report?.kpis?.calidad_por_producir || [];
      const totalToneladas = document.getElementById('ventasKpiTotalToneladas');
      const totalDetalle = document.getElementById('ventasKpiTotalDetalle');
      const precioPromedio = document.getElementById('ventasKpiPrecioPromedio');
      const precioPromedioDetalle = document.getElementById('ventasKpiPrecioPromedioDetalle');
      const precioPromedioComercial = document.getElementById('ventasKpiPrecioPromedioComercial');
      const precioPromedioComercialDetalle = document.getElementById('ventasKpiPrecioPromedioComercialDetalle');
      const precioPromedioIndustrial = document.getElementById('ventasKpiPrecioPromedioIndustrial');
      const precioPromedioIndustrialDetalle = document.getElementById('ventasKpiPrecioPromedioIndustrialDetalle');
      const comercialToneladas = document.getElementById('ventasKpiComercialToneladas');
      const comercialDetalle = document.getElementById('ventasKpiComercialDetalle');
      const industrialToneladas = document.getElementById('ventasKpiIndustrialToneladas');
      const industrialDetalle = document.getElementById('ventasKpiIndustrialDetalle');
      const backorderComercialValue = document.getElementById('ventasKpiBackorderComercial');
      const backorderComercialDetalle = document.getElementById('ventasKpiBackorderComercialDetalle');
      const backorderIndustrialValue = document.getElementById('ventasKpiBackorderIndustrial');
      const backorderIndustrialDetalle = document.getElementById('ventasKpiBackorderIndustrialDetalle');
      const backorderTotalValue = document.getElementById('ventasKpiBackorderTotal');
      const backorderTotalDetalle = document.getElementById('ventasKpiBackorderTotalDetalle');
      const backorderSinClasificar = document.getElementById('ventasBackorderSinClasificar');
      const backorderVentaValue = document.getElementById('ventasKpiBackorderVenta');
      const backorderVentaLabel = document.getElementById('ventasKpiBackorderVentaLabel');
      const backorderVentaDetalle = document.getElementById('ventasKpiBackorderVentaDetalle');

      if (totalToneladas) {
        totalToneladas.textContent = `${ventasFormatNumber(ventas.toneladas, 1)} TON`;
      }

      if (totalDetalle) {
        totalDetalle.textContent = `Facturas: ${ventasFormatNumber(ventas.facturas_toneladas, 1)} TON · Remisiones: ${ventasFormatNumber(ventas.remisiones_toneladas, 1)} TON`;
      }

      if (precioPromedio) {
        precioPromedio.innerHTML = `${ventasFormatCurrency(ventas.precio_promedio, 2)} <span class="ventas-price-unit">/ KG</span>`;
      }

      if (precioPromedioDetalle) {
        precioPromedioDetalle.textContent = `Venta neta: ${ventasFormatCurrency(ventas.monto_total, 0)} · ${ventasFormatNumber(ventas.kilos, 0)} kg`;
      }

      if (precioPromedioComercial) {
        precioPromedioComercial.innerHTML = `${ventasFormatCurrency(comercial.precio_promedio, 2)} <span class="ventas-price-unit">/ KG</span>`;
      }
      if (precioPromedioComercialDetalle) {
        precioPromedioComercialDetalle.textContent = `Venta neta: ${ventasFormatCurrency(comercial.monto, 0)} · ${ventasFormatNumber(comercial.kilos, 0)} kg`;
      }
      if (precioPromedioIndustrial) {
        precioPromedioIndustrial.innerHTML = `${ventasFormatCurrency(industrial.precio_promedio, 2)} <span class="ventas-price-unit">/ KG</span>`;
      }
      if (precioPromedioIndustrialDetalle) {
        precioPromedioIndustrialDetalle.textContent = `Venta neta: ${ventasFormatCurrency(industrial.monto, 0)} · ${ventasFormatNumber(industrial.kilos, 0)} kg`;
      }

      updateSalesTrafficLight('ventasKpiTotalCard', 'ventasKpiTotalMeta', 'ventasKpiTotalEstado', ventas.semaforo);
      updateSalesTrafficLight('ventasKpiPrecioPromedioCard', 'ventasKpiPrecioPromedioMeta', 'ventasKpiPrecioPromedioEstado', ventas.precio_promedio_semaforo);
      updateSalesTrafficLight('ventasKpiPrecioPromedioComercialCard', 'ventasKpiPrecioPromedioComercialMeta', 'ventasKpiPrecioPromedioComercialEstado', comercial.precio_promedio_semaforo);
      updateSalesTrafficLight('ventasKpiPrecioPromedioIndustrialCard', 'ventasKpiPrecioPromedioIndustrialMeta', 'ventasKpiPrecioPromedioIndustrialEstado', industrial.precio_promedio_semaforo);
      updateSalesTrafficLight('ventasKpiComercialCard', 'ventasKpiComercialMeta', 'ventasKpiComercialEstado', comercial.semaforo);
      updateSalesTrafficLight('ventasKpiIndustrialCard', 'ventasKpiIndustrialMeta', 'ventasKpiIndustrialEstado', industrial.semaforo);
      updateSalesTrafficLight('ventasKpiBackorderComercialCard', 'ventasKpiBackorderComercialMeta', 'ventasKpiBackorderComercialEstado', pedidosComercial.semaforo);
      updateSalesTrafficLight('ventasKpiBackorderIndustrialCard', 'ventasKpiBackorderIndustrialMeta', 'ventasKpiBackorderIndustrialEstado', pedidosIndustrial.semaforo);
      updateSalesTrafficLight('ventasKpiBackorderTotalCard', 'ventasKpiBackorderTotalMeta', 'ventasKpiBackorderTotalEstado', pedidos.semaforo);

      if (backorderComercialValue) {
        backorderComercialValue.textContent = `${ventasFormatNumber(pedidosComercial.toneladas, 1)} TON`;
      }

      if (backorderComercialDetalle) {
        backorderComercialDetalle.textContent = `${ventasFormatNumber(pedidosComercial.cantidad, 0)} pedidos · Ver partidas`;
      }

      if (backorderIndustrialValue) {
        backorderIndustrialValue.textContent = `${ventasFormatNumber(pedidosIndustrial.toneladas, 1)} TON`;
      }

      if (backorderIndustrialDetalle) {
        backorderIndustrialDetalle.textContent = `${ventasFormatNumber(pedidosIndustrial.cantidad, 0)} pedidos · Ver partidas`;
      }

      if (backorderTotalValue) {
        backorderTotalValue.textContent = `${ventasFormatNumber(pedidos.toneladas, 1)} TON`;
      }
      if (backorderTotalDetalle) {
        backorderTotalDetalle.textContent = `${ventasFormatNumber(pedidos.cantidad, 0)} pedidos · Ver partidas`;
      }

      if (backorderSinClasificar) {
        const sinClasificarToneladas = Number(pedidosSinClasificar.toneladas || 0);
        backorderSinClasificar.hidden = sinClasificarToneladas <= 0;
        backorderSinClasificar.textContent = `Back Order sin clasificar: ${ventasFormatNumber(sinClasificarToneladas, 1)} TON`;
      }

      if (backorderVentaValue) {
        backorderVentaValue.textContent = `${ventasFormatNumber(backorderVenta.toneladas, 1)} TON`;
      }

      if (backorderVentaLabel) {
        backorderVentaLabel.textContent = backorderVenta.label || 'Back Order + venta';
      }

      if (backorderVentaDetalle) {
        backorderVentaDetalle.textContent = backorderVenta.detalle || '';
      }

      ventasBackorderPartidas = Array.isArray(pedidos.partidas) ? pedidos.partidas : [];
      if (document.getElementById('ventasBackorderModal')?.classList.contains('is-open')) {
        renderBackorderPartidas();
      }

      if (comercialToneladas) {
        comercialToneladas.textContent = `${ventasFormatNumber(comercial.toneladas, 1)} TON`;
      }

      if (comercialDetalle) {
        comercialDetalle.textContent = `Facturas: ${ventasFormatNumber(comercial.facturas_toneladas, 1)} TON · Remisiones: ${ventasFormatNumber(comercial.remisiones_toneladas, 1)} TON`;
      }

      if (industrialToneladas) {
        industrialToneladas.textContent = `${ventasFormatNumber(industrial.toneladas, 1)} TON`;
      }

      if (industrialDetalle) {
        industrialDetalle.textContent = `Facturas: ${ventasFormatNumber(industrial.facturas_toneladas, 1)} TON · Remisiones: ${ventasFormatNumber(industrial.remisiones_toneladas, 1)} TON`;
      }

      ventasCalidadPorProducir = Array.isArray(calidadPorProducir) ? calidadPorProducir : [];
      renderCalidadPorProducir(ventasCalidadPorProducir);
      updateVentasChart(report);
    }

    async function refreshVentasReport() {
      const params = new URLSearchParams(window.location.search);
      params.set('_', String(Date.now()));
      const response = await fetch(`data.php?${params.toString()}`, { cache: 'no-store' });
      if (!response.ok) return;
      const report = await response.json();
      if (!report?.error) {
        updateVentasReport(report);
      }
    }

    document.querySelectorAll('#ventasFilters select').forEach((select) => {
      select.addEventListener('change', () => {
        select.form.submit();
      });
    });

    document.querySelectorAll('[data-backorder-type]').forEach((card) => {
      card.addEventListener('click', () => openBackorderModal(card.dataset.backorderType || 'comercial'));
      card.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openBackorderModal(card.dataset.backorderType || 'comercial');
        }
      });
    });

    document.querySelectorAll('[data-backorder-close]').forEach((element) => {
      element.addEventListener('click', closeBackorderModal);
    });

    document.getElementById('ventasQualityGrid')?.addEventListener('click', (event) => {
      const card = event.target.closest('[data-quality-card]');
      if (card) openQualityInventoryModal(card.dataset.qualityCard || '');
    });

    document.getElementById('ventasQualityGrid')?.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const card = event.target.closest('[data-quality-card]');
      if (!card) return;
      event.preventDefault();
      openQualityInventoryModal(card.dataset.qualityCard || '');
    });

    document.querySelectorAll('[data-quality-close]').forEach((element) => {
      element.addEventListener('click', closeQualityInventoryModal);
    });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (document.getElementById('ventasQualityModal')?.classList.contains('is-open')) closeQualityInventoryModal();
      else if (document.getElementById('ventasBackorderModal')?.classList.contains('is-open')) closeBackorderModal();
    });

    setInterval(() => {
      refreshVentasReport().catch(() => {});
    }, ventasRefreshMs);
  </script>
</body>

</html>
