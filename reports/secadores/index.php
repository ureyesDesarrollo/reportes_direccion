<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$appConfig = require __DIR__ . '/../../config/app.php';
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
$secadorCaptura = isset($_GET['secador']) ? (string)$_GET['secador'] : '';
$secadoresValidos = array_keys((array)($tuneles ?? []));
$modoCaptura = isset($_GET['capture']) && in_array($secadorCaptura, $secadoresValidos, true);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: 0))) ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    /* ============================================
       FONDOS Y ESTILOS MEJORADOS
       ============================================ */

    /* Fondo general */
    body {
      background: #f4f7fb;
      min-height: 100vh;
    }

    .dashboard {
      background: transparent;
    }

    /* ============================================
       TARJETAS PRINCIPALES - TÚNELES
       ============================================ */

    .secadores-exec-tunnel {
      background: #ffffff;
      border: 1px solid #dbe7f5;
      border-radius: 16px;
      box-shadow:
        0 10px 22px rgba(37, 99, 235, 0.08),
        0 2px 6px rgba(15, 23, 42, 0.05);
      padding: 14px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .secadores-exec-tunnel:hover {
      box-shadow:
        0 16px 34px rgba(37, 99, 235, 0.12),
        0 3px 10px rgba(15, 23, 42, 0.06);
      border-color: #93c5fd;
      transform: translateY(-2px);
    }

    .secadores-exec-tunnel h2 {
      margin: 0;
      font-size: 20px;
      color: #475569;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .secadores-exec-tunnel-title-row {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 8px;
      width: 100%;
    }

    .secadores-exec-tunnel-title-row h2 {
      margin-right: auto;
    }

    /* Cabecera del túnel */
    .secadores-exec-tunnel-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
      background: #eef6ff;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid #cfe0fb;
      box-shadow: none;
    }

    .secadores-exec-tunnel-title {
      display: flex;
      flex-direction: column;
      gap: 6px;
      width: 100%;
    }

    .secadores-exec-inline-metrics {
      display: grid;
      gap: 8px;
      margin-top: 2px;
    }

    .secadores-exec-top-indicator {
      flex: 1 1 300px;
      min-width: 280px;
      min-height: 104px;
      padding: 0;
      border: 1px solid #dbe7f5;
      border-radius: 12px;
      background: #ffffff;
      box-shadow: none;
      display: grid;
      grid-template-columns: minmax(95px, .45fr) minmax(0, 1.55fr);
      gap: 0;
      overflow: hidden;
      color: #111827;
    }

    .secadores-exec-top-indicator-status {
      min-width: 0;
      padding: 8px 6px;
      background: #0ea5e9;
      color: #ffffff;
      border-right: 1px solid rgba(15, 23, 42, .12);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 7px;
      text-align: center;
    }

    .secadores-exec-top-indicator-parameters {
      min-width: 0;
      padding: 10px 12px;
      background: #ffffff;
      color: #111827;
      display: grid;
      align-content: center;
    }

    .secadores-exec-top-indicator.ok .secadores-exec-top-indicator-status {
      color: #ffffff;
      background: #2e8b57;
    }

    .secadores-exec-top-indicator.warning .secadores-exec-top-indicator-status {
      color: #111827;
      background: #facc15;
    }

    .secadores-exec-top-indicator.gold .secadores-exec-top-indicator-status {
      color: #111827;
      background: #d4a017;
    }

    .secadores-exec-top-indicator.danger .secadores-exec-top-indicator-status {
      color: #ffffff;
      background: #c94436;
    }

    .secadores-exec-top-indicator.neutral .secadores-exec-top-indicator-status {
      color: #ffffff;
      background: #2563eb;
    }

    .secadores-exec-top-indicator.unavailable .secadores-exec-top-indicator-status {
      color: #ffffff;
      background: #94a3b8;
    }

    .secadores-exec-top-indicator-label {
      color: inherit;
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
      opacity: .9;
    }

    .secadores-exec-top-indicator-value {
      color: inherit;
      font-size: 21px;
      font-weight: 900;
      line-height: 1;
    }

    .secadores-exec-tunnel-title-row {
      flex-wrap: wrap;
    }

    .secadores-exec-tunnel-title-row h2 {
      flex: 1 0 100%;
    }

    .secadores-exec-inline-group {
      display: grid;
      gap: 5px;
    }

    .secadores-exec-inline-group-title {
      font-size: 20px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #475569;
    }

    .secadores-exec-votator-section {
      display: grid;
      gap: 5px;
    }

    .secadores-exec-votator-section .secadores-exec-votators {
      margin-top: 0;
    }

    .secadores-exec-inline-group-items {
      display: grid;
      grid-template-columns: repeat(var(--metric-cols, 2), minmax(0, 1fr));
      grid-auto-rows: minmax(82px, 1fr);
      gap: 4px;
    }

    .secadores-exec-inline-group-items.is-banda {
      --metric-cols: 2;
    }

    .secadores-exec-inline-group-items.is-agua-y-vapor,
    .secadores-exec-inline-group-items.is-humedades,
    .secadores-exec-inline-group-items.is-verificacion-de-secado {
      --metric-cols: 2;
    }

    .secadores-exec-inline-metric {
      display: inline-flex;
      align-items: flex-start;
      gap: 6px;
      padding: 8px 8px;
      border-radius: 12px;
      background: #0ea5e9;
      border: 1px solid #0284c7;
      color: #ffffff;
      box-shadow: 0 10px 24px rgba(14, 165, 233, 0.22);
      width: 100%;
      min-width: 0;
      min-height: 82px;
    }

    .secadores-exec-inline-metric.is-placeholder {
      visibility: hidden;
      pointer-events: none;
      box-shadow: none;
    }

    .secadores-exec-inline-metric-body {
      display: flex;
      flex-direction: column;
      gap: 4px;
      min-width: 0;
      width: 100%;
    }

    .secadores-exec-inline-metric.clickable {
      cursor: pointer;
      transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease;
    }

    .secadores-exec-inline-metric.clickable:hover {
      transform: translateY(-2px) scale(1.01);
      box-shadow: 0 14px 28px rgba(14, 165, 233, 0.24);
    }

    .secadores-exec-inline-metric.warning {
      background: #facc15;
      border-color: #eab308;
      color: #111827;
    }

    .secadores-exec-inline-metric.danger {
      background: #c94436;
      border-color: #a9362c;
    }

    .secadores-exec-inline-metric.ok {
      background: #2e8b57;
      border-color: #257447;
    }

    .secadores-exec-inline-metric.neutral {
      background: #ffffff;
      border-color: #d9e0ea;
      color: #111827;
      box-shadow: none;
    }

    .secadores-exec-inline-metric.unavailable {
      background: #94a3b8;
      border-color: #64748b;
      color: #ffffff;
      box-shadow: none;
    }

    .secadores-exec-inline-metric i {
      font-size: 15px;
      opacity: .95;
      margin-top: 2px;
    }

    .secadores-exec-inline-metric-label {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 6px;
      font-size: 9px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
      opacity: 0.86;
      color: inherit;
      min-height: 22px;
    }

    .secadores-exec-inline-metric-date {
      flex: 0 0 auto;
      font-size: 8px;
      font-weight: 800;
      line-height: 1;
      opacity: .82;
      white-space: nowrap;
      text-transform: none;
      letter-spacing: 0;
    }

    .secadores-exec-inline-metric-value {
      font-size: 17px;
      font-weight: 800;
      line-height: 1;
      color: inherit;
      white-space: nowrap;
    }

    .secadores-exec-inline-metric-range {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      overflow: hidden;
      font-size: 9px;
      line-height: 1.3;
      opacity: 0.9;
      color: inherit;
      min-height: 23px;
    }

    .secadores-exec-inline-metric-range.is-empty,
    .secadores-exec-zone-range.is-empty {
      visibility: hidden;
    }

    .secadores-exec-inline-metric-time {
      color: inherit;
      font-size: 9px;
      line-height: 1.2;
      min-height: 12px;
      opacity: .78;
      font-weight: 800;
    }

    .secadores-history-modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: rgba(15, 23, 42, 0.58);
      z-index: 2000;
    }

    .secadores-history-modal.open {
      display: flex;
    }

    .secadores-history-dialog {
      width: min(920px, 100%);
      max-height: min(88vh, 900px);
      overflow: auto;
      background: #ffffff;
      border-radius: 22px;
      border: 1px solid #dbe7f5;
      box-shadow: 0 30px 80px rgba(15, 23, 42, 0.24);
      padding: 22px;
    }

    .secadores-history-head {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
      margin-bottom: 16px;
    }

    .secadores-history-title {
      display: grid;
      gap: 6px;
    }

    .secadores-history-title h3 {
      margin: 0;
      font-size: 22px;
      color: #0f172a;
    }

    .secadores-history-title p {
      margin: 0;
      color: #475569;
      font-size: 13px;
      line-height: 1.5;
    }

    .secadores-history-close {
      border: 0;
      background: #eff6ff;
      color: #1d4ed8;
      width: 40px;
      height: 40px;
      border-radius: 999px;
      font-size: 16px;
      cursor: pointer;
    }

    .secadores-history-layout {
      display: grid;
      grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr);
      gap: 16px;
    }

    .secadores-history-panel {
      background: #f8fbff;
      border: 1px solid #dbeafe;
      border-radius: 18px;
      padding: 16px;
    }

    .secadores-history-panel h4 {
      margin: 0 0 12px;
      font-size: 14px;
      color: #0f172a;
    }

    .secadores-history-chart-wrap {
      height: 280px;
    }

    .secadores-history-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    .secadores-history-table th,
    .secadores-history-table td {
      padding: 10px 8px;
      border-bottom: 1px solid #e2e8f0;
      text-align: left;
    }

    .secadores-history-table th {
      color: #475569;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .secadores-history-empty {
      color: #64748b;
      font-size: 13px;
      line-height: 1.5;
    }

    .secadores-exec-tunnel-sub {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      color: #475569;
      font-size: 11px;
    }

    /* ============================================
       BADGE DE ESTADO MEJORADO
       ============================================ */

    .secadores-exec-status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .02em;
      background: var(--status-bg, #f1f5f9);
      color: var(--status-color, #475569);
      border: 1px solid rgba(255, 255, 255, 0.3);
      box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
      transition: all 0.2s;
    }

    .secadores-exec-status:hover {
      transform: scale(1.02);
    }

    .secadores-exec-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 18px;
      border-radius: 999px;
      background: #2563eb;
      color: #ffffff;
      font-weight: 700;
      text-decoration: none;
      white-space: nowrap;
      transition: all 0.2s;
      border: 1px solid #2563eb;
      box-shadow: 0 8px 18px rgba(37, 99, 235, 0.18);
    }

    .secadores-exec-link:hover {
      background: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 12px 24px rgba(37, 99, 235, 0.24);
    }

    /* ============================================
       CUERPO DEL TÚNEL
       ============================================ */

    .secadores-exec-tunnel-body {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .secadores-exec-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(480px, 1fr));
      gap: 16px;
      margin-top: 12px;
      align-items: stretch;
    }

    .secadores-exec-zones {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 4px;
    }

    .secadores-exec-room-climate-section {
      display: grid;
      gap: 6px;
    }

    .secadores-exec-room-climate-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 7px;
    }

    .secadores-exec-room-climate {
      min-width: 0;
      padding: 6px;
      border: 2px solid #64748b;
      border-radius: 13px;
      background: #ffffff;
      box-shadow: 0 2px 5px rgba(15, 23, 42, .16);
    }

    .secadores-exec-room-climate-title {
      margin: 0 0 5px;
      color: #1e293b;
      font-size: 19px;
      font-weight: 900;
      letter-spacing: .04em;
      text-align: center;
    }

    .secadores-exec-room-climate-cards {
      display: grid;
      grid-template-columns: 1fr;
      gap: 4px;
    }

    .secadores-exec-room-climate-cards .secadores-exec-inline-metric,
    .secadores-exec-room-climate-cards .secadores-exec-zone {
      min-width: 0;
      height: 100%;
    }

    .secadores-exec-zone-section-title {
      margin: 0 0 8px;
      color: #475569;
      font-size: 20px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .secadores-exec-zone {
      padding: 8px 8px;
      min-height: 82px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      position: relative;
      border-radius: 12px;
      border: 1px solid rgba(226, 232, 240, 0.4);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: none;
    }

    .secadores-exec-zone.is-placeholder {
      visibility: hidden;
      pointer-events: none;
      box-shadow: none;
    }

    .secadores-exec-zone:hover {
      transform: translateY(-2px);
      box-shadow: none;
    }

    /* Estados por color plano */

    .secadores-exec-zone[data-status="ok"] {
      background: #2e8b57;
      border-color: #257447;
      box-shadow: none;
    }

    .secadores-exec-zone[data-status="ok"]:hover {
      box-shadow: none;
      border-color: #257447;
    }

    /* Amarillo visible, pero más ejecutivo */
    .secadores-exec-zone[data-status="warning"] {
      background: #facc15;
      border-color: #eab308;
      box-shadow: none;
    }

    .secadores-exec-zone[data-status="warning"]:hover {
      box-shadow: none;
      border-color: #eab308;
    }

    .secadores-exec-zone[data-status="danger"] {
      background: #c94436;
      border-color: #a9362c;
      box-shadow: none;
    }

    .secadores-exec-zone[data-status="danger"]:hover {
      box-shadow: none;
      border-color: #a9362c;
    }

    .secadores-exec-zone[data-status="unknown"] {
      background: #94a3b8;
      border-color: #64748b;
      color: #ffffff;
    }

    .secadores-exec-zone-label {
      font-size: 10px;
      font-weight: 800;
      color: #ffffff;
      padding-left: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .secadores-exec-zone[data-status="ok"] .secadores-exec-zone-label,
    .secadores-exec-zone[data-status="ok"] .secadores-exec-zone-value,
    .secadores-exec-zone[data-status="ok"] .secadores-exec-zone-value small,
    .secadores-exec-zone[data-status="ok"] .secadores-exec-zone-range,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-zone-label,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-zone-value,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-zone-value small,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-zone-range {
      color: #ffffff;
    }

    .secadores-exec-zone[data-status="warning"] .secadores-exec-zone-label,
    .secadores-exec-zone[data-status="warning"] .secadores-exec-zone-value,
    .secadores-exec-zone[data-status="warning"] .secadores-exec-zone-value small,
    .secadores-exec-zone[data-status="warning"] .secadores-exec-zone-range {
      color: #111827;
    }

    .secadores-exec-zone[data-status="ok"] .secadores-exec-status,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-status {
      color: #ffffff !important;
      background: rgba(255, 255, 255, 0.18) !important;
      border-color: rgba(255, 255, 255, 0.36);
    }

    .secadores-exec-zone[data-status="warning"] .secadores-exec-status {
      color: #111827 !important;
      background: rgba(255, 255, 255, 0.42) !important;
      border-color: rgba(17, 24, 39, 0.14);
    }

    .secadores-exec-zone-value {
      font-size: 17px;
      line-height: 1;
      font-weight: 800;
      color: #ffffff;
      padding-left: 2px;
      margin: 2px 0;
    }

    .secadores-exec-zone-value small {
      font-size: 11px;
      color: #ffffff;
      margin-left: 4px;
      font-weight: 600;
    }

    .secadores-exec-zone-range {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      
      overflow: hidden;
      color: #64748b;
      font-size: 9px;
      line-height: 1.3;
      min-height: 23px;
      padding-left: 2px;
      font-weight: 500;
    }

    .secadores-exec-votators {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 8px;
      width: 100%;
    }

    .secadores-exec-votator {
      display: grid;
      grid-template-columns: 1fr;
      align-items: stretch;
      gap: 8px;
      padding: 10px;
      border-radius: 12px;
      background: #f8fbff;
      border: 1px solid #dbeafe;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .secadores-exec-votator-head {
      display: flex;
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      min-width: 0;
    }

    .secadores-exec-votator-title {
      display: inline-flex;
      align-items: center;
      min-width: 0;
      color: #475569;
      font-size: 20px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    .secadores-exec-votator-badge {
      flex: 0 0 auto;
      padding: 3px 8px;
      border-radius: 999px;
      background: #e2e8f0;
      color: #475569;
      border: 1px solid #cbd5e1;
      font-size: 8px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .secadores-exec-votator-fields {
      display: grid;
      grid-template-columns: 1fr;
      gap: 6px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .secadores-exec-votator-field {
      min-height: 112px;
      padding: 0;
      border-radius: 12px;
      background: #ffffff;
      border: 1px solid #d9e0ea;
      display: grid;
      grid-template-columns: minmax(100px, .5fr) minmax(0, 1.5fr);
      gap: 0;
      overflow: hidden;
      color: #111827;
    }

    .secadores-exec-votator-field-status {
      min-width: 0;
      padding: 10px 8px;
      background: #0ea5e9;
      color: #ffffff;
      border-right: 1px solid rgba(15, 23, 42, .12);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 7px;
      text-align: center;
    }

    .secadores-exec-votator-field-parameters {
      min-width: 0;
      padding: 12px 14px;
      background: #ffffff;
      color: #111827;
      display: grid;
      align-content: center;
    }

    .secadores-exec-votator-range-list {
      display: grid;
      gap: 6px;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .secadores-exec-votator-range-item {
      display: grid;
      grid-template-columns: 9px minmax(105px, auto) minmax(0, 1fr);
      align-items: center;
      gap: 7px;
      color: #111827;
      font-size: 12px;
      font-weight: 800;
      line-height: 1.2;
    }

    .secadores-exec-votator-range-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      background: #94a3b8;
    }

    .secadores-exec-votator-range-dot.rojo {
      background: #c94436;
    }

    .secadores-exec-votator-range-dot.amarillo {
      background: #facc15;
    }

    .secadores-exec-votator-range-dot.verde {
      background: #2e8b57;
    }

    .secadores-exec-votator-range-name {
      text-transform: uppercase;
      letter-spacing: .02em;
    }

    .secadores-exec-votator-range-value {
      color: #111827;
      text-align: right;
      font-weight: 800;
      overflow-wrap: anywhere;
    }

    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-status {
      background: #2e8b57;
      color: white;
    }

    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-status {
      background: #facc15;
      color: #111827;
    }

    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-status {
      background: #c94436;
      color: white;
    }

    .secadores-exec-votator-field.status-azul .secadores-exec-votator-field-status {
      background: #ffffff;
      color: #111827;
    }

    .secadores-exec-votator-field.status-gris .secadores-exec-votator-field-status {
      background: #94a3b8;
      color: #ffffff;
    }

    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-value small,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-value small {
      color: white;
    }

    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-value small {
      color: #111827;
    }

    .secadores-exec-votator-field-label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: inherit;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
      line-height: 1.2;
      min-height: auto;
      opacity: .86;
    }

    .secadores-exec-votator-field-label i {
      color: inherit;
      font-size: 14px;
    }

    .secadores-exec-votator-field-range {
      display: -webkit-box;
      -webkit-box-orient: vertical;
      color: inherit;
      font-size: 9px;
      font-weight: 800;
      line-height: 1.3;
      min-height: auto;
      opacity: .9;
      overflow: hidden;
      overflow-wrap: anywhere;
    }

    .secadores-exec-votator-field-value {
      color: inherit;
      font-size: 22px;
      font-weight: 800;
      line-height: 1;
      white-space: nowrap;
    }

    .secadores-exec-votator-field-value small {
      display: block;
      margin: 5px 0 0;
      color: inherit;
      font-size: 14px;
      font-weight: 700;
    }

    /* Formato común para todas las tarjetas de métricas */
    .secadores-exec-inline-metric,
    .secadores-exec-zone {
      display: grid;
      grid-template-columns: minmax(100px, .5fr) minmax(0, 1.5fr);
      align-items: stretch;
      gap: 0;
      min-height: 112px;
      padding: 0;
      overflow: hidden;
      background: #ffffff;
      color: #111827;
      border: 1px solid #d9e0ea;
    }

    .secadores-exec-inline-metric {
      box-shadow: 0 1px 3px rgba(15, 23, 42, .08);
    }

    .secadores-exec-zone {
      box-shadow: none;
    }

    .secadores-exec-inline-metric-body,
    .secadores-exec-zone-status {
      min-width: 0;
      width: auto;
      padding: 10px 8px;
      background: #0ea5e9;
      color: #ffffff;
      border-right: 1px solid rgba(15, 23, 42, .12);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 7px;
      text-align: center;
    }

    .secadores-exec-inline-metric-parameters,
    .secadores-exec-zone-parameters {
      min-width: 0;
      padding: 12px 14px;
      background: #ffffff;
      color: #111827;
      display: grid;
      align-content: center;
      gap: 5px;
    }

    .secadores-exec-inline-metric.ok,
    .secadores-exec-inline-metric.warning,
    .secadores-exec-inline-metric.danger,
    .secadores-exec-inline-metric.neutral,
    .secadores-exec-inline-metric.unavailable,
    .secadores-exec-zone[data-status="ok"],
    .secadores-exec-zone[data-status="warning"],
    .secadores-exec-zone[data-status="danger"],
    .secadores-exec-zone[data-status="unknown"] {
      background: #ffffff;
      color: #111827;
      border-color: #d9e0ea;
    }

    .secadores-exec-inline-metric.ok .secadores-exec-inline-metric-body,
    .secadores-exec-zone[data-status="ok"] .secadores-exec-zone-status {
      background: #2e8b57;
      color: #ffffff;
    }

    .secadores-exec-inline-metric.warning .secadores-exec-inline-metric-body,
    .secadores-exec-zone[data-status="warning"] .secadores-exec-zone-status {
      background: #facc15;
      color: #111827;
    }

    .secadores-exec-inline-metric.danger .secadores-exec-inline-metric-body,
    .secadores-exec-zone[data-status="danger"] .secadores-exec-zone-status {
      background: #c94436;
      color: #ffffff;
    }

    .secadores-exec-inline-metric.unavailable .secadores-exec-inline-metric-body,
    .secadores-exec-zone[data-status="unknown"] .secadores-exec-zone-status {
      background: #94a3b8;
      color: #ffffff;
    }

    .secadores-exec-inline-metric.neutral .secadores-exec-inline-metric-body {
      background: #ffffff;
      color: #111827;
    }

    .secadores-exec-inline-metric-label,
    .secadores-exec-zone-label {
      min-height: auto;
      padding: 0;
      color: inherit;
      font-size: 11px;
      justify-content: center;
      text-align: center;
    }

    .secadores-exec-inline-metric-label {
      flex-wrap: wrap;
    }

    .secadores-exec-inline-metric-value,
    .secadores-exec-zone-value {
      margin: 0;
      padding: 0;
      color: inherit;
      font-size: 22px;
      text-align: center;
    }

    .secadores-exec-inline-metric-value small {
      display: block;
      margin: 5px 0 0;
      color: inherit;
      font-size: 14px;
      font-weight: 700;
    }

    .secadores-exec-inline-metric[data-metric-key="caudal_aire"] .secadores-exec-inline-metric-value {
      font-size: 19px;
      font-variant-numeric: tabular-nums;
      letter-spacing: -.02em;
    }

    .secadores-exec-zone-value small {
      display: block;
      margin: 5px 0 0;
      color: inherit;
      font-size: 14px;
    }

    /* Nombres de parámetros: alto contraste en todas las cards */
    .secadores-exec-top-indicator-label,
    .secadores-exec-inline-metric-label,
    .secadores-exec-zone-label,
    .secadores-exec-votator-field-label {
      color: inherit;
      font-size: 14px;
      font-weight: 900;
      line-height: 1.1;
      letter-spacing: .02em;
      opacity: 1;
    }

    .secadores-exec-inline-metric-date {
      color: #475569;
      font-size: 9px;
      text-align: right;
    }

    .secadores-exec-votator-range-dot.dorado {
      background: #d4a017;
    }

    .secadores-exec-votator-range-dot.azul {
      background: #2563eb;
    }

    /* ============================================
       STRIP DE ADVERTENCIA
       ============================================ */

    .secadores-exec-warning-strip {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 8px 12px;
      border-radius: 10px;
      background: #fef3c7;
      border: 1px solid #facc15;
      color: #78350f;
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.12);
      font-size: 11px;
      line-height: 1.3;
      font-weight: 600;
    }

    .secadores-exec-warning-strip i {
      font-size: 14px;
      color: #eab308;
    }

    .secadores-exec-warning-strip strong {
      color: #78350f;
      font-weight: 800;
    }

    /* ============================================
       ACCIONES - FONDOS CON JERARQUÍA
       ============================================ */

    .secadores-exec-actions {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      background: #f8fbff;
      padding: 18px 20px;
      border-radius: 16px;
      border: 1px solid #dbeafe;
      box-shadow: none;
    }

    .secadores-exec-actions h3 {
      grid-column: 1 / -1;
      margin: 0 0 2px 0;
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
      letter-spacing: 0.02em;
    }

    .secadores-exec-action {
      padding: 14px 16px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      border-radius: 14px;
      border: 1px solid rgba(203, 213, 225, 0.68);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 2px 6px rgba(15, 23, 42, 0.05);
    }

    .secadores-exec-action:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(15, 23, 42, 0.08);
    }

    /* Prioridad Alta - Rojo */
    .secadores-exec-action[data-priority="high"] {
      background: #fee2e2;
      border-color: #fca5a5;
    }

    .secadores-exec-action[data-priority="high"]:hover {
      border-color: rgba(239, 68, 68, 0.4);
      box-shadow: 0 6px 20px rgba(239, 68, 68, 0.12);
    }

    /* Prioridad Media - Amarillo */
    .secadores-exec-action[data-priority="medium"] {
      background: #fef3c7;
      border-color: #facc15;
      box-shadow: 0 4px 10px rgba(245, 158, 11, 0.10);
    }

    .secadores-exec-action[data-priority="medium"]:hover {
      border-color: rgba(217, 119, 6, 0.45);
      box-shadow: 0 6px 20px rgba(217, 119, 6, 0.16);
    }

    /* Prioridad Baja - Verde */
    .secadores-exec-action[data-priority="low"] {
      background: #dcfce7;
      border-color: #86efac;
    }

    .secadores-exec-action[data-priority="low"]:hover {
      border-color: rgba(74, 222, 128, 0.4);
      box-shadow: 0 6px 20px rgba(74, 222, 128, 0.12);
    }

    .secadores-exec-action strong {
      color: #0f172a;
      font-size: 13px;
      font-weight: 700;
    }

    .secadores-exec-action span,
    .secadores-exec-action small {
      color: #475569;
      line-height: 1.3;
      font-size: 11px;
      font-weight: 500;
    }

    .secadores-exec-action small {
      color: #64748b;
      font-size: 10px;
    }

    /* Mensaje vacío */
    .secadores-exec-actions-empty {
      grid-column: 1 / -1;
      padding: 16px 20px;
      border-radius: 14px;
      background: #e2e8f0;
      color: #64748b;
      font-size: 12px;
      font-weight: 600;
      text-align: center;
      border: 1px dashed #94a3b8;
    }

    /* ============================================
       WARNING GLOBAL
       ============================================ */

    .secadores-exec-warning {
      margin-top: 16px;
      padding: 14px 20px;
      border-radius: 14px;
      background: #fef3c7;
      color: #78350f;
      border: 1px solid #facc15;
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.12);
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .secadores-exec-warning i {
      font-size: 20px;
      color: #b45309;
    }

    /* ============================================
       BOTÓN DE REGRESO
       ============================================ */

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 18px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      background: #ffffff;
      color: #334155;
      border: 1px solid #dbe7f5;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
      transition: all 0.2s;
    }

    .back-btn:hover {
      background: #eff6ff;
      transform: translateX(-2px);
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
    }

    .print-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 16px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      background: #0f172a;
      color: #ffffff;
      border: 1px solid #0f172a;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
      cursor: pointer;
      transition: all 0.2s;
    }

    .print-btn:hover {
      background: #1d4ed8;
      border-color: #1d4ed8;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.18);
    }

    .secadores-exec-inline-metric-value,
    .secadores-exec-zone-value,
    .secadores-exec-votator-field-value {
      display: flex;
      flex-direction: row;
      align-items: baseline;
      justify-content: center;
      gap: 4px;
      white-space: nowrap;
    }

    .secadores-exec-inline-metric-value small,
    .secadores-exec-zone-value small,
    .secadores-exec-votator-field-value small {
      display: inline;
      margin: 0;
      font-size: .55em;
      line-height: 1;
    }

    body.capture-mode {
      background: #ffffff;
    }

    body.capture-mode .dashboard {
      width: min(900px, calc(100vw - 12px));
      padding: 4px 0;
    }

    body.capture-mode .header-left > div:first-child,
    body.capture-mode .header .sub,
    body.capture-mode .secadores-exec-warning {
      display: none !important;
    }

    body.capture-mode .header {
      margin-bottom: 2px;
    }

    body.capture-mode .header h1 {
      margin: 0;
      font-size: 20px;
      line-height: 1.05;
    }

    body.capture-mode .secadores-exec-grid {
      grid-template-columns: 1fr;
      gap: 0;
      margin-top: 2px;
    }

    body.capture-mode .secadores-exec-tunnel {
      box-shadow: none;
      transform: none !important;
      gap: 3px;
      padding: 5px;
    }

    body.capture-mode .secadores-exec-tunnel-head {
      gap: 3px;
      padding: 5px;
    }

    body.capture-mode .secadores-exec-tunnel-title {
      gap: 2px;
    }

    body.capture-mode .secadores-exec-tunnel-title-row {
      gap: 2px;
    }

    body.capture-mode .secadores-exec-top-indicator {
      grid-template-columns: minmax(120px, .65fr) minmax(0, 1.35fr);
      min-height: 84px;
    }

    body.capture-mode .secadores-exec-top-indicator-status,
    body.capture-mode .secadores-exec-votator-field-status,
    body.capture-mode .secadores-exec-inline-metric-body,
    body.capture-mode .secadores-exec-zone-status {
      display: grid;
      grid-template-rows: auto minmax(30px, 1fr);
      align-items: stretch;
      justify-content: stretch;
      padding: 0;
      gap: 0;
    }

    body.capture-mode .secadores-exec-top-indicator-parameters {
      padding: 3px 5px;
    }

    body.capture-mode .secadores-exec-inline-metrics,
    body.capture-mode .secadores-exec-tunnel-body {
      gap: 2px;
      margin-top: 0;
    }

    body.capture-mode .secadores-exec-inline-group,
    body.capture-mode .secadores-exec-votator-section {
      gap: 1px;
    }

    body.capture-mode .secadores-exec-inline-group-items,
    body.capture-mode .secadores-exec-zones {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      grid-auto-rows: auto;
      gap: 2px;
    }

    body.capture-mode .secadores-exec-room-climate-section,
    body.capture-mode .secadores-exec-room-climate-grid {
      gap: 3px;
    }

    body.capture-mode .secadores-exec-room-climate-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    body.capture-mode .secadores-exec-room-climate {
      padding: 2px;
      border: 2px solid #64748b;
      border-radius: 8px;
      background: #ffffff;
      box-shadow: 0 1px 3px rgba(15, 23, 42, .18);
    }

    body.capture-mode .secadores-exec-room-climate-title {
      margin-bottom: 1px;
      font-size: 17px;
      line-height: 1;
    }

    body.capture-mode .secadores-exec-room-climate-cards {
      gap: 2px;
    }

    body.capture-mode .secadores-exec-votators {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 3px;
      margin-top: 0;
    }

    body.capture-mode .secadores-exec-votator {
      gap: 2px;
      padding: 3px;
    }

    body.capture-mode .secadores-exec-votator-fields {
      gap: 2px;
    }

    body.capture-mode .secadores-exec-inline-metric,
    body.capture-mode .secadores-exec-zone,
    body.capture-mode .secadores-exec-votator-field {
      grid-template-columns: minmax(120px, .65fr) minmax(0, 1.35fr);
      min-height: 84px;
    }

    body.capture-mode .secadores-exec-inline-metric.is-placeholder,
    body.capture-mode .secadores-exec-zone.is-placeholder {
      display: none !important;
    }

    body.capture-mode .secadores-exec-votator-field-parameters,
    body.capture-mode .secadores-exec-inline-metric-parameters,
    body.capture-mode .secadores-exec-zone-parameters {
      padding: 3px 5px;
    }

    body.capture-mode .secadores-exec-votator-range-list {
      gap: 1px;
    }

    body.capture-mode .secadores-exec-votator-range-item {
      grid-template-columns: 7px minmax(48px, auto) minmax(0, 1fr);
      gap: 3px;
      font-size: 9px;
    }

    body.capture-mode .secadores-exec-votator-range-name {
      display: block;
      font-size: 0;
      line-height: 1;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.rojo)
      .secadores-exec-votator-range-name::after {
      content: "Rojo";
      font-size: 8px;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.amarillo)
      .secadores-exec-votator-range-name::after {
      content: "Amarillo";
      font-size: 8px;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.verde)
      .secadores-exec-votator-range-name::after {
      content: "Verde";
      font-size: 8px;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.dorado)
      .secadores-exec-votator-range-name::after {
      content: "Dorado";
      font-size: 8px;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.azul)
      .secadores-exec-votator-range-name::after {
      content: "Azul";
      font-size: 8px;
    }

    body.capture-mode .secadores-exec-votator-range-value {
      text-align: left;
    }

    body.capture-mode .secadores-exec-votator-range-item:has(.secadores-exec-votator-range-dot.verde)
      .secadores-exec-votator-range-value::after {
      content: " (Objetivo)";
      font-weight: 900;
    }

    body.capture-mode .secadores-exec-votator-range-dot {
      width: 7px;
      height: 7px;
    }

    body.capture-mode .secadores-exec-top-indicator-label,
    body.capture-mode .secadores-exec-inline-metric-label,
    body.capture-mode .secadores-exec-zone-label,
    body.capture-mode .secadores-exec-votator-field-label {
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 0;
      padding: 2px;
      border-bottom: 1px solid rgba(255, 255, 255, .35);
      color: inherit;
      font-size: 17px;
      font-weight: 900;
      line-height: 1.05;
      opacity: 1;
      text-align: center;
    }

    body.capture-mode .secadores-exec-top-indicator-value,
    body.capture-mode .secadores-exec-inline-metric-value,
    body.capture-mode .secadores-exec-zone-value,
    body.capture-mode .secadores-exec-votator-field-value {
      display: flex;
      flex-direction: row;
      align-items: baseline;
      justify-content: center;
      gap: 4px;
      margin: 0;
      padding: 2px;
      font-size: 24px;
      text-align: center;
      white-space: nowrap;
    }

    body.capture-mode .secadores-exec-inline-metric-value small,
    body.capture-mode .secadores-exec-zone-value small,
    body.capture-mode .secadores-exec-votator-field-value small {
      display: inline;
      margin: 0;
      font-size: .55em;
      line-height: 1;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric-value,
    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone-value {
      flex-direction: row;
      align-items: baseline;
      gap: 4px;
      white-space: nowrap;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric-value small,
    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone-value small {
      display: inline;
      margin: 0;
      font-size: .55em;
      line-height: 1;
    }

    body.capture-mode .secadores-exec-room-climate-cards {
      grid-template-columns: minmax(0, 1fr);
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone {
      order: 1;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric {
      order: 2;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric,
    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone {
      display: grid;
      grid-template-columns: minmax(135px, .55fr) minmax(0, 1.45fr);
      min-height: 0;
      height: 100%;
      padding: 0;
      gap: 0;
      box-shadow: none;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric-body,
    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone-status {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      width: 100%;
      min-height: 62px;
      padding: 3px;
      gap: 2px;
      border-right: 1px solid rgba(15, 23, 42, .14);
      border-bottom: 0;
    }

    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-inline-metric-parameters,
    body.capture-mode .secadores-exec-room-climate-cards .secadores-exec-zone-parameters {
      width: 100%;
      padding: 3px 5px;
      align-content: center;
    }

    body.capture-mode .secadores-exec-inline-group-title,
    body.capture-mode .secadores-exec-zone-section-title,
    body.capture-mode .secadores-exec-votator-title,
    body.capture-mode .secadores-exec-tunnel h2 {
      margin-bottom: 1px;
      font-size: 13px;
      line-height: 1.05;
    }

    /* ============================================
       RESPONSIVE
       ============================================ */

    @media (max-width: 1020px) {
      .secadores-exec-zones {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .secadores-exec-votators {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 960px) {
      .secadores-exec-actions {
        grid-template-columns: 1fr;
      }

      .secadores-exec-zones {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .secadores-history-layout {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .secadores-exec-room-climate-grid {
        grid-template-columns: 1fr;
      }

      .secadores-exec-zones {
        grid-template-columns: 1fr;
      }

      .secadores-exec-votator-fields {
        grid-template-columns: 1fr;
      }

      .secadores-exec-votator {
        grid-template-columns: 1fr;
      }

      .secadores-exec-tunnel {
        padding: 12px;
      }

      .secadores-exec-tunnel-head {
        flex-direction: column;
        align-items: stretch;
      }

      .secadores-exec-zone {
        min-height: 82px;
      }
    }

    @media print {
      @page {
        size: letter portrait;
        margin: 4mm;
      }

      * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
        box-shadow: none !important;
        text-shadow: none !important;
      }

      html,
      body {
        width: 100%;
        min-height: auto;
        background: #ffffff !important;
      }

      body {
        margin: 0;
        font-size: 8px;
      }

      .dashboard {
        width: 130% !important;
        max-width: none !important;
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
        transform: scale(.77);
        transform-origin: top left;
      }

      .header {
        margin: 0 0 3px !important;
        padding: 0 !important;
      }

      .header-left > div:first-child,
      .secadores-exec-warning,
      .secadores-history-modal {
        display: none !important;
      }

      .header h1 {
        margin: 0 0 4px !important;
        font-size: 20px !important;
        line-height: 1.1 !important;
        color: #0f172a !important;
      }

      .header .sub {
        display: none !important;
      }

      .header h1 i {
        display: none !important;
      }

      #secadoresExecRefreshBadge {
        display: none !important;
      }

      .secadores-exec-grid {
        display: grid !important;
        grid-template-columns: 1fr !important;
        gap: 0 !important;
        margin-top: 0 !important;
        align-items: start !important;
        grid-auto-rows: auto !important;
      }

      .secadores-exec-tunnel {
        page-break-inside: avoid;
        break-inside: avoid;
        page-break-after: always;
        break-after: page;
        border: 1px solid #cbd5e1 !important;
        border-radius: 7px !important;
        padding: 5px !important;
        gap: 4px !important;
        background: #ffffff !important;
        transform: none !important;
      }

      .secadores-exec-tunnel:last-child {
        page-break-after: auto;
        break-after: auto;
      }

      .secadores-exec-tunnel-head {
        padding: 5px !important;
        gap: 4px !important;
        border-radius: 6px !important;
        background: #eef6ff !important;
      }

      .secadores-exec-tunnel-title {
        gap: 4px !important;
      }

      .secadores-exec-tunnel-title-row {
        gap: 6px !important;
      }

      .secadores-exec-tunnel h2 {
        font-size: 14px !important;
      }

      .secadores-exec-tunnel-sub {
        gap: 6px !important;
        font-size: 8px !important;
      }

      .secadores-exec-top-indicator {
        min-width: 125px !important;
        min-height: 36px !important;
        padding: 4px 7px !important;
        border-radius: 6px !important;
      }

      .secadores-exec-top-indicator-label,
      .secadores-exec-top-indicator-range {
        font-size: 7px !important;
      }

      .secadores-exec-top-indicator-value {
        font-size: 14px !important;
      }

      .secadores-exec-inline-metrics {
        gap: 5px !important;
      }

      .secadores-exec-inline-group {
        gap: 3px !important;
      }

      .secadores-exec-inline-group-title,
      .secadores-exec-zone-section-title {
        margin: 0 0 3px !important;
        font-size: 9px !important;
        color: #334155 !important;
      }

      .secadores-exec-inline-group-items,
      .secadores-exec-zones {
        grid-template-columns: 1fr !important;
        grid-auto-rows: auto !important;
        gap: 6px !important;
      }

      .secadores-exec-inline-group-items.is-banda {
        grid-template-columns: 1fr !important;
      }

      .secadores-exec-votators {
        grid-template-columns: 1fr !important;
        gap: 6px !important;
        margin-top: 2px !important;
      }

      .secadores-exec-votator {
        padding: 4px !important;
        gap: 3px !important;
        border-radius: 6px !important;
      }

      .secadores-exec-votator-title {
        font-size: 8px !important;
        gap: 4px !important;
      }

      .secadores-exec-votator-badge {
        display: none !important;
      }

      .secadores-exec-votator-fields {
        gap: 3px !important;
      }

      .secadores-exec-inline-metric,
      .secadores-exec-zone,
      .secadores-exec-votator-field {
        min-height: 62px !important;
        padding: 4px 5px !important;
        border-radius: 6px !important;
        gap: 2px !important;
      }

      .secadores-exec-votator-field {
        grid-template-columns: minmax(82px, .45fr) minmax(0, 1.55fr) !important;
        min-height: 104px !important;
        padding: 0 !important;
        gap: 0 !important;
      }

      .secadores-exec-inline-metric,
      .secadores-exec-zone {
        grid-template-columns: minmax(82px, .45fr) minmax(0, 1.55fr) !important;
        min-height: 104px !important;
        padding: 0 !important;
        gap: 0 !important;
      }

      .secadores-exec-votator-field-status,
      .secadores-exec-inline-metric-body,
      .secadores-exec-zone-status {
        padding: 5px 3px !important;
        gap: 3px !important;
      }

      .secadores-exec-votator-field-parameters,
      .secadores-exec-inline-metric-parameters,
      .secadores-exec-zone-parameters {
        padding: 8px 10px !important;
      }

      .secadores-exec-votator-range-list {
        gap: 4px !important;
      }

      .secadores-exec-votator-range-item {
        grid-template-columns: 7px minmax(92px, auto) minmax(0, 1fr) !important;
        gap: 6px !important;
        font-size: 10px !important;
      }

      .secadores-exec-votator-range-dot {
        width: 7px !important;
        height: 7px !important;
      }

      .secadores-exec-votator-range-value {
        grid-column: auto;
        text-align: right;
      }

      .secadores-exec-inline-metric {
        gap: 4px !important;
      }

      .secadores-exec-inline-metric i,
      .secadores-exec-votator-field-label i {
        font-size: 9px !important;
      }

      .secadores-exec-inline-metric-label,
      .secadores-exec-zone-label,
      .secadores-exec-votator-field-label {
        min-height: auto !important;
        font-size: 8px !important;
        line-height: 1.1 !important;
      }

      .secadores-exec-inline-metric-value,
      .secadores-exec-zone-value,
      .secadores-exec-votator-field-value {
        margin: 0 !important;
        font-size: 14px !important;
      }

      .secadores-exec-inline-metric-value small,
      .secadores-exec-zone-value small,
      .secadores-exec-votator-field-value small {
        font-size: 7px !important;
      }

      .secadores-exec-votator-field-label i {
        font-size: 10px !important;
      }

      .secadores-exec-votator-field-label,
      .secadores-exec-inline-metric-label,
      .secadores-exec-zone-label {
        font-size: 10px !important;
      }

      .secadores-exec-votator-field-value,
      .secadores-exec-inline-metric-value,
      .secadores-exec-zone-value {
        font-size: 18px !important;
      }

      .secadores-exec-votator-field-value small,
      .secadores-exec-inline-metric-value small,
      .secadores-exec-zone-value small {
        font-size: 9px !important;
      }

      .secadores-exec-inline-metric-range,
      .secadores-exec-zone-range,
      .secadores-exec-votator-field-range {
        min-height: auto !important;
        max-height: none !important;
        font-size: 6.8px !important;
        line-height: 1.1 !important;
        overflow: visible !important;
      }

      .secadores-exec-inline-metric-time,
      .secadores-exec-status {
        font-size: 7px !important;
        min-height: auto !important;
        padding: 2px 4px !important;
      }

      .secadores-exec-status i {
        font-size: 6px !important;
      }

      .secadores-exec-zone:hover,
      .secadores-exec-inline-metric:hover,
      .secadores-exec-tunnel:hover {
        transform: none !important;
      }
    }

    /* Impresión ejecutiva: conserva tarjetas completas y evita compresión */
    @media print {
      @page {
        size: letter landscape;
        margin: 6mm;
      }

      html,
      body {
        width: auto !important;
        min-height: auto !important;
        overflow: visible !important;
      }

      body {
        margin: 0 !important;
        font-size: 8px !important;
      }

      .dashboard {
        width: 135.14% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        zoom: .74;
        transform: none !important;
      }

      .header {
        margin-bottom: 4px !important;
      }

      .header h1 {
        font-size: 17px !important;
      }

      .secadores-exec-grid {
        display: block !important;
      }

      .secadores-exec-tunnel {
        display: block !important;
        page-break-inside: avoid !important;
        break-inside: avoid-page !important;
        page-break-after: always !important;
        break-after: page !important;
        padding: 6px !important;
      }

      .secadores-exec-tunnel:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
      }

      .secadores-exec-tunnel-head,
      .secadores-exec-tunnel-title,
      .secadores-exec-tunnel-body {
        display: block !important;
        page-break-inside: auto !important;
        break-inside: auto !important;
      }

      .secadores-exec-tunnel-title-row,
      .secadores-exec-tunnel-sub {
        margin-bottom: 4px !important;
      }

      .secadores-exec-top-indicator {
        min-width: 0 !important;
        min-height: 64px !important;
        padding: 0 !important;
        grid-template-columns: minmax(60px, .4fr) minmax(0, 1.6fr) !important;
      }

      .secadores-exec-top-indicator-status {
        padding: 3px 2px !important;
        gap: 2px !important;
      }

      .secadores-exec-top-indicator-parameters {
        padding: 3px 4px !important;
      }

      .secadores-exec-top-indicator-label {
        font-size: 9px !important;
      }

      .secadores-exec-top-indicator-value {
        font-size: 17px !important;
      }

      .secadores-exec-inline-metrics,
      .secadores-exec-inline-group,
      .secadores-exec-tunnel-body {
        margin-top: 5px !important;
      }

      .secadores-exec-inline-group-title,
      .secadores-exec-zone-section-title,
      .secadores-exec-votator-title,
      .secadores-exec-tunnel h2 {
        break-after: avoid !important;
        page-break-after: avoid !important;
        font-size: 10px !important;
      }

      .secadores-exec-inline-group-items,
      .secadores-exec-inline-group-items.is-banda,
      .secadores-exec-zones {
        grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
        grid-auto-rows: auto !important;
        gap: 3px !important;
      }

      .secadores-exec-votators {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 4px !important;
      }

      .secadores-exec-votator-fields {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
        gap: 3px !important;
      }

      .secadores-exec-inline-metric.is-placeholder,
      .secadores-exec-zone.is-placeholder {
        display: none !important;
      }

      .secadores-exec-inline-metric,
      .secadores-exec-zone,
      .secadores-exec-votator,
      .secadores-exec-votator-field {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }

      .secadores-exec-inline-metric,
      .secadores-exec-zone,
      .secadores-exec-votator-field {
        grid-template-columns: minmax(60px, .4fr) minmax(0, 1.6fr) !important;
        min-height: 68px !important;
        padding: 0 !important;
        gap: 0 !important;
      }

      .secadores-exec-votator-field-status,
      .secadores-exec-inline-metric-body,
      .secadores-exec-zone-status {
        padding: 3px 2px !important;
        gap: 2px !important;
      }

      .secadores-exec-votator-field-parameters,
      .secadores-exec-inline-metric-parameters,
      .secadores-exec-zone-parameters {
        padding: 3px 4px !important;
      }

      .secadores-exec-votator-range-list {
        gap: 1px !important;
      }

      .secadores-exec-votator-range-item {
        grid-template-columns: 5px minmax(66px, auto) minmax(0, 1fr) !important;
        gap: 3px !important;
        font-size: 8px !important;
        line-height: 1.05 !important;
      }

      .secadores-exec-votator-range-dot {
        width: 5px !important;
        height: 5px !important;
      }

      .secadores-exec-votator-range-value {
        text-align: right !important;
      }

      .secadores-exec-inline-metric-label,
      .secadores-exec-zone-label,
      .secadores-exec-votator-field-label {
        font-size: 9px !important;
      }

      .secadores-exec-inline-metric-value,
      .secadores-exec-zone-value,
      .secadores-exec-votator-field-value {
        font-size: 17px !important;
      }

      .secadores-exec-inline-metric-value small,
      .secadores-exec-zone-value small,
      .secadores-exec-votator-field-value small {
        margin-top: 3px !important;
        font-size: 7px !important;
      }
    }
  </style>
</head>

<body class="<?= $modoCaptura ? 'capture-mode' : '' ?>">
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a
            href="../index.php"
            class="back-btn">
            <i class="fas fa-arrow-left"></i>
            Regresar al inicio
          </a>
          <button type="button" class="print-btn" id="secadoresPrintBtn">
            <i class="fas fa-print"></i>
            Imprimir
          </button>
        </div>

        <h1>
          <?= htmlspecialchars($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            Vista rápida para dirección
          </span>
          <span>
            Refresco: <?= n(((float)(($meta['intervaloActualizacion'] ?? 600000) / 1000)) / 60, 0) ?> min
          </span>
          <span id="secadoresExecRefreshBadge" class="badge">
            <i class="fas fa-rotate"></i>
            Actualizando
          </span>
        </div>

        <?php foreach (($warnings ?? []) as $warning): ?>
          <div class="secadores-exec-warning">
            <i class="fas fa-triangle-exclamation"></i>
            <?= htmlspecialchars((string)$warning) ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div id="secadoresExecApp"></div>
  </div>

  <div class="secadores-history-modal" id="secadoresHistoryModal" aria-hidden="true">
    <div class="secadores-history-dialog" role="dialog" aria-modal="true" aria-labelledby="secadoresHistoryTitle">
      <div class="secadores-history-head">
        <div class="secadores-history-title">
          <h3 id="secadoresHistoryTitle">Histórico</h3>
          <p id="secadoresHistorySubtitle">Últimos 5 registros</p>
        </div>
        <button type="button" class="secadores-history-close" id="secadoresHistoryClose" aria-label="Cerrar">
          <i class="fas fa-times"></i>
        </button>
      </div>
      <div class="secadores-history-layout">
        <section class="secadores-history-panel">
          <h4>Tendencia</h4>
          <div class="secadores-history-chart-wrap">
            <canvas id="secadoresHistoryChart"></canvas>
          </div>
        </section>
        <section class="secadores-history-panel">
          <h4>Últimos 5 registros</h4>
          <div id="secadoresHistoryTableWrap"></div>
        </section>
      </div>
    </div>
  </div>

  <script>
    window.secadoresExecutiveBootstrap = <?= json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.secadoresCaptureSecador = <?= json_encode($modoCaptura ? $secadorCaptura : '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
  <script>
    (() => {
      const metricBandsPlugin = {
        id: 'metricBands',
        beforeDraw(chart, args, pluginOptions) {
          const bands = pluginOptions || {};
          const yScale = chart.scales?.y;
          const chartArea = chart.chartArea;
          if (!yScale || !chartArea || !bands || !bands.enabled) {
            return;
          }

          const { ctx } = chart;
          const min = yScale.min;
          const max = yScale.max;
          const mode = bands.mode || 'rango';

          const clamp = (value) => Math.max(min, Math.min(max, value));
          const paint = (start, end, color) => {
            if (start === null || end === null || start === undefined || end === undefined) {
              return;
            }
            const s = clamp(start);
            const e = clamp(end);
            if (s === e) return;
            const y1 = yScale.getPixelForValue(s);
            const y2 = yScale.getPixelForValue(e);
            const top = Math.min(y1, y2);
            const height = Math.abs(y2 - y1);
            if (height <= 0) return;
            ctx.save();
            ctx.fillStyle = color;
            ctx.fillRect(chartArea.left, top, chartArea.right - chartArea.left, height);
            ctx.restore();
          };

          const greenMin = bands.greenMin ?? null;
          const greenMax = bands.greenMax ?? null;
          const yellowMin = bands.yellowMin ?? null;
          const yellowMax = bands.yellowMax ?? null;

          if (mode === 'minimo') {
            paint(min, yellowMin ?? greenMin ?? min, 'rgba(239, 68, 68, 0.10)');
            paint(yellowMin ?? min, greenMin ?? max, 'rgba(245, 158, 11, 0.12)');
            paint(greenMin ?? min, max, 'rgba(16, 185, 129, 0.10)');
            return;
          }

          if (mode === 'maximo') {
            paint(min, greenMax ?? max, 'rgba(16, 185, 129, 0.10)');
            paint(greenMax ?? min, yellowMax ?? max, 'rgba(245, 158, 11, 0.12)');
            paint(yellowMax ?? min, max, 'rgba(239, 68, 68, 0.10)');
            return;
          }

          paint(min, yellowMin ?? greenMin ?? min, 'rgba(239, 68, 68, 0.10)');
          paint(yellowMin ?? min, greenMin ?? max, 'rgba(245, 158, 11, 0.12)');
          paint(greenMin ?? min, greenMax ?? max, 'rgba(16, 185, 129, 0.10)');
          paint(greenMax ?? min, yellowMax ?? max, 'rgba(245, 158, 11, 0.12)');
          paint(yellowMax ?? min, max, 'rgba(239, 68, 68, 0.10)');
        }
      };

      if (window.Chart && !Chart.registry.plugins.get('metricBands')) {
        Chart.register(metricBandsPlugin);
      }

      const state = {
        payload: window.secadoresExecutiveBootstrap || {},
        timer: null,
        fastTimer: null,
        historyChart: null,
      };

      const formatNumber = (value, decimals = 1) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
          return '-';
        }

        decimals = Math.max(0, Math.min(2, Math.trunc(Number(decimals) || 0)));

        return Number(value).toLocaleString('en-US', {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals,
        });
      };

      const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

      const renderStatus = (label, color, bgColor) => {
        const isNeutral = String(color || '').toLowerCase() === '#ffffff';
        const bg = bgColor || (isNeutral ? '#ffffff' : color + '1A');
        const textColor = isNeutral ? '#111827' : color;
        return '<span class="secadores-exec-status" style="background:' + bg + '; color:' + textColor + ';">' +
          '<i class="fas fa-circle" style="font-size:10px;"></i>' +
          escapeHtml(label) +
          '</span>';
      };

      const statusClassFromKey = (statusKey, hasData = true) => {
        if (!hasData) return 'unavailable';
        return (
          statusKey === 'verde' ? 'ok' :
          statusKey === 'amarillo' ? 'warning' :
          statusKey === 'dorado' ? 'gold' :
          statusKey === 'rojo' ? 'danger' :
          statusKey === 'azul' ? 'neutral' :
          'unavailable'
        );
      };

      const renderHeaderIndicator = (indicators) => {
        const items = Object.values(indicators || {});
        if (!items.length) return '';

        return items.map((item) => {
          const plainValue = String(item.formatted || '').trim();
          const hasData = plainValue !== '' && plainValue !== '-' && plainValue.toLowerCase() !== 'sin dato';
          const value = hasData
            ? `${escapeHtml(item.formatted || '-')} ${escapeHtml(item.unit || '')}`.trim()
            : '-';
          const statusClass = statusClassFromKey(String(item.statusKey || 'gris'), hasData);
          const rangeRows = compactMetricRangeRows(item);

          return `
            <article class="secadores-exec-top-indicator ${statusClass}">
              <div class="secadores-exec-top-indicator-status">
                <div class="secadores-exec-top-indicator-label">${escapeHtml(item.label || 'Indicador')}</div>
                <div class="secadores-exec-top-indicator-value">${value}</div>
              </div>
              <div class="secadores-exec-top-indicator-parameters">
                <ul class="secadores-exec-votator-range-list" aria-label="Rangos de ${escapeHtml(item.label || 'Indicador')}">
                  ${rangeRows.map((row) => `
                    <li class="secadores-exec-votator-range-item">
                      <span class="secadores-exec-votator-range-dot ${escapeHtml(row.status)}" aria-hidden="true"></span>
                      <span class="secadores-exec-votator-range-name">${escapeHtml(row.label)}</span>
                      <span class="secadores-exec-votator-range-value">${escapeHtml(row.range)}</span>
                    </li>
                  `).join('')}
                </ul>
              </div>
            </article>
          `;
        }).join('');
      };

      const renderInlineMetric = (groupName, metricKey, metric, tunnelKey, rangeRowsOverride = null) => {
        const formattedValue = String(metric.formatted || '').trim();
        const hasFormattedValue = formattedValue !== '' && formattedValue !== '-' && formattedValue.toLowerCase() !== 'sin dato';
        const numericValue = Number(metric.value);
        const displayValue = metricKey === 'caudal_aire' && Number.isFinite(numericValue)
          ? numericValue.toLocaleString('es-MX', { maximumFractionDigits: 0 })
          : formattedValue;
        const metricUnit = String(metric.unit || '').trim();
        const renderedValue = hasFormattedValue
          ? `<span>${escapeHtml(displayValue)}</span>${metricUnit ? `<small>${escapeHtml(metricUnit)}</small>` : ''}`
          : escapeHtml(metric.emptyLabel || 'Sin dato');
        const plainValue = String(formattedValue || renderedValue || '').trim();
        const hasData = plainValue !== '' && plainValue !== '-' && plainValue.toLowerCase() !== 'sin dato';
        const statusClass = metric.available && hasData
          ? (
            metric.statusKey === 'verde' ? ' ok' :
            metric.statusKey === 'amarillo' ? ' warning' :
            metric.statusKey === 'rojo' ? ' danger' :
            metric.statusKey === 'azul' ? ' neutral' :
            metric.statusKey === 'gris' ? ' unavailable' :
            ''
          )
          : ' unavailable';

        const isClickable = Array.isArray(metric.history) && metric.history.length > 0;
        const rangeLabel = String(metric.rangeLabel || '').trim();
        const timestampLabel = String(metric.timestampLabel || '').trim();
        const rangeClass = rangeLabel ? '' : ' is-empty';
        const rangeAttrs = rangeLabel ? '' : ' aria-hidden="true"';
        const rangeRows = Array.isArray(rangeRowsOverride) ? rangeRowsOverride : compactMetricRangeRows(metric);
        return `
          <div class="secadores-exec-inline-metric${statusClass}${isClickable ? ' clickable' : ''}" data-tunnel-key="${escapeHtml(tunnelKey)}" data-metric-key="${escapeHtml(metricKey)}">
            <div class="secadores-exec-inline-metric-body">
              <div class="secadores-exec-inline-metric-label">
                <span>${escapeHtml(metric.label || 'Métrica')}</span>
              </div>
              <div class="secadores-exec-inline-metric-value">${renderedValue}</div>
            </div>
            <div class="secadores-exec-inline-metric-parameters">
              ${timestampLabel ? `<div class="secadores-exec-inline-metric-date">${escapeHtml(timestampLabel)}</div>` : ''}
              <ul class="secadores-exec-votator-range-list${rangeClass}"${rangeAttrs} aria-label="Rangos de ${escapeHtml(metric.label || 'Métrica')}">
                ${rangeRows.map((row) => `
                  <li class="secadores-exec-votator-range-item">
                    <span class="secadores-exec-votator-range-dot ${escapeHtml(row.status)}" aria-hidden="true"></span>
                    <span class="secadores-exec-votator-range-name">${escapeHtml(row.label)}</span>
                    <span class="secadores-exec-votator-range-value">${escapeHtml(row.range)}</span>
                  </li>
                `).join('')}
              </ul>
            </div>
          </div>
        `;
      };

      const metricGroupClass = (groupName) => {
        return String(groupName || 'General')
          .normalize('NFD')
          .replace(/[\u0300-\u036f]/g, '')
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, '-')
          .replace(/^-+|-+$/g, '') || 'general';
      };

      const metricGroupSlots = (groupName, total) => {
        const normalized = metricGroupClass(groupName);
        const minimums = {
          'banda': 2,
          'agua-y-vapor': 3,
          'humedades': 9,
          'verificacion-de-secado': 8,
        };
        const columns = 2;
        const minimum = minimums[normalized] || columns;
        return Math.max(minimum, Math.ceil(total / columns) * columns);
      };

      const renderMetricPlaceholders = (count) => {
        if (count <= 0) {
          return '';
        }

        return Array.from({
          length: count
        }, () => '<div class="secadores-exec-inline-metric is-placeholder" aria-hidden="true"></div>').join('');
      };

      const getStatusAttribute = (status) => {
        const map = {
          'Óptimo': 'ok',
          'Atención': 'warning',
          'Cuidado': 'warning',
          'Crítico': 'danger',
          'Sin dato': 'unknown'
        };
        return map[status] || 'unknown';
      };

      const renderZonePlaceholders = (count) => {
        if (count <= 0) {
          return '';
        }

        return Array.from({
          length: count
        }, () => '<div class="secadores-exec-zone is-placeholder" aria-hidden="true"></div>').join('');
      };

      const renderInlineMetrics = (metrics, tunnelKey, groupMode = 'main') => {
        const entries = Object.entries(metrics || {}).filter(([metricKey, metric]) => {
          if (metric.hidden) {
            return false;
          }

          if (['verificacion_hum_penultima', 'verificacion_hum_ultima', 'verificacion_hum_relativa_cam5', 'verificacion_flujo', 'verificacion_hz', 'verificacion_rc9'].includes(metricKey)) {
            return false;
          }

          const isVerification = metricGroupClass(metric.group || 'General') === 'verificacion-de-secado';
          const isHumidity = metricGroupClass(metric.group || 'General') === 'humedades';
          if (groupMode === 'main' && isHumidity) {
            return false;
          }
          return groupMode === 'verification' ? isVerification : !isVerification;
        });
        if (!entries.length) {
          return '';
        }

        const grouped = entries.reduce((acc, [metricKey, metric]) => {
          const groupKey = metric.group || 'General';
          if (!acc[groupKey]) {
            acc[groupKey] = [];
          }
          acc[groupKey].push([metricKey, metric]);
          return acc;
        }, {});

        return `
          <div class="secadores-exec-inline-metrics">
            ${Object.entries(grouped).map(([groupName, groupMetrics]) => {
              const groupClass = metricGroupClass(groupName);
              const displayGroupName = groupName === 'Verificación de secado'
                ? 'Verificación secado'
                : groupName;
              const targetSlots = groupClass === 'verificacion-de-secado'
                ? groupMetrics.length
                : metricGroupSlots(groupName, groupMetrics.length);
              const placeholders = renderMetricPlaceholders(targetSlots - groupMetrics.length);
              return `
                <div class="secadores-exec-inline-group">
	                  <div class="secadores-exec-inline-group-title">${escapeHtml(displayGroupName)}</div>
	                  <div class="secadores-exec-inline-group-items is-${escapeHtml(groupClass)}">
	                    ${groupMetrics.map(([metricKey, metric]) => renderInlineMetric(
                        groupName,
                        metricKey,
                        metric,
                        tunnelKey,
                        metricRangeRowsForDisplay(metricKey, metric, tunnelKey)
                      )).join('')}
                      ${placeholders}
	                  </div>
                </div>
              `;
            }).join('')}
          </div>
        `;
      };

      const closeHistoryModal = () => {
        const modal = document.getElementById('secadoresHistoryModal');
        if (!modal) return;
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        if (state.historyChart) {
          state.historyChart.destroy();
          state.historyChart = null;
        }
      };

      const openHistoryModal = (tunnelKey, metricKey) => {
        const tunnel = state.payload.tuneles?.[tunnelKey];
        const metric = tunnel?.metricas?.[metricKey];
        if (!tunnel || !metric || !Array.isArray(metric.history) || !metric.history.length) {
          return;
        }

        const modal = document.getElementById('secadoresHistoryModal');
        const title = document.getElementById('secadoresHistoryTitle');
        const subtitle = document.getElementById('secadoresHistorySubtitle');
        const tableWrap = document.getElementById('secadoresHistoryTableWrap');
        const canvas = document.getElementById('secadoresHistoryChart');

        if (!modal || !title || !subtitle || !tableWrap || !canvas) {
          return;
        }

        title.textContent = `${metric.label} | ${tunnel.titulo}`;
        subtitle.textContent = metric.rangeLabel || 'Últimos 5 registros';

        tableWrap.innerHTML = `
          <table class="secadores-history-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Valor</th>
              </tr>
            </thead>
            <tbody>
              ${metric.history.map((item) => `
                <tr>
                  <td>${escapeHtml(item.timestamp || '-')}</td>
                  <td>${escapeHtml(item.formatted || '-')} ${escapeHtml(metric.unit || '')}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        `;

        if (state.historyChart) {
          state.historyChart.destroy();
        }

        const historyAsc = [...metric.history].reverse();
        const rule = metric.rule || {};
        const chartBands = {
          enabled: Boolean(Object.keys(rule).length),
          mode: rule.modo || 'rango',
          greenMin: typeof rule.verde_min === 'number' ? rule.verde_min : null,
          greenMax: typeof rule.verde_max === 'number' ? rule.verde_max : null,
          yellowMin: typeof rule.amarillo_min === 'number' ? rule.amarillo_min : null,
          yellowMax: typeof rule.amarillo_max === 'number' ? rule.amarillo_max : null,
        };

        state.historyChart = new Chart(canvas, {
          type: 'line',
          data: {
            labels: historyAsc.map((item) => item.timestamp || '-'),
            datasets: [{
              label: metric.label,
              data: historyAsc.map((item) => item.value),
              borderColor: metric.statusColor || '#2563eb',
              backgroundColor: metric.statusColor || '#2563eb',
              tension: 0.25,
              fill: false,
              pointRadius: 4,
              pointHoverRadius: 5,
              borderWidth: 3,
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: {
              metricBands: chartBands,
              legend: {
                display: false,
              },
            },
            scales: {
              x: {
                ticks: {
                  maxRotation: 0,
                  autoSkip: false,
                },
              },
              y: {
                beginAtZero: false,
              },
            },
          }
        });

        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
      };

      const renderZone = (cell, tunnelKey, rangeRowsOverride = null) => {
        const statusAttr = getStatusAttribute(cell.statusLabel);
        const cellLabel = String(cell.label || 'REC')
          .replace(/^rec[aá]mara\s*/i, 'REC ')
          .trim();
        const rangeLabel = String(cell.rangeLabel || '').trim();
        const rangeClass = rangeLabel ? '' : ' is-empty';
        const rangeAttrs = rangeLabel ? '' : ' aria-hidden="true"';
        const rangeRows = Array.isArray(rangeRowsOverride) ? rangeRowsOverride : compactMetricRangeRows(cell);

        return `
    <article class="secadores-exec-zone unavailable" data-status="${statusAttr}" data-open-temperature="1" data-tunnel-key="${escapeHtml(tunnelKey)}">
      <div class="secadores-exec-zone-status">
        <div class="secadores-exec-zone-label">${escapeHtml(cellLabel)}</div>
        <div class="secadores-exec-zone-value">
          ${escapeHtml(cell.formatted || '-')}
          <small>°C</small>
        </div>
      </div>
      <div class="secadores-exec-zone-parameters">
        <ul class="secadores-exec-votator-range-list${rangeClass}"${rangeAttrs} aria-label="Rangos de ${escapeHtml(cellLabel)}">
          ${rangeRows.map((row) => `
            <li class="secadores-exec-votator-range-item">
              <span class="secadores-exec-votator-range-dot ${escapeHtml(row.status)}" aria-hidden="true"></span>
              <span class="secadores-exec-votator-range-name">${escapeHtml(row.label)}</span>
              <span class="secadores-exec-votator-range-value">${escapeHtml(row.range)}</span>
            </li>
          `).join('')}
        </ul>
      </div>
	    </article>
	  `;
      };

      const extractRoomNumber = (key, item) => {
        const candidates = [key, item?.label, item?.field];
        for (const candidate of candidates) {
          const match = String(candidate || '').match(/(?:zona|rec[aá]mara|rec)[^0-9]*(\d+)/i);
          if (match) {
            return Number(match[1]);
          }
        }
        return null;
      };

      const renderRoomClimate = (metrics, cells, tunnelKey) => {
        const penultimateCookieHumidity = metrics?.verificacion_hum_penultima || null;
        const cookieHumidity = metrics?.verificacion_hum_ultima || null;
        const relativeHumidityRoom5 = metrics?.verificacion_hum_relativa_cam5 || null;
        const humidityByRoom = new Map();
        Object.entries(metrics || {}).forEach(([metricKey, metric]) => {
          if (metric.hidden || metricGroupClass(metric.group || 'General') !== 'humedades') {
            return;
          }
          const room = extractRoomNumber(metricKey, metric);
          if (room !== null) {
            humidityByRoom.set(room, [metricKey, metric]);
          }
        });

        const temperatureByRoom = new Map();
        (cells || []).forEach((cell) => {
          const room = extractRoomNumber('', cell);
          if (room !== null) {
            temperatureByRoom.set(room, cell);
          }
        });

        const rooms = [...new Set([...humidityByRoom.keys(), ...temperatureByRoom.keys()])]
          .sort((left, right) => left - right);
        if (!rooms.length) {
          return '';
        }

        return `
          <div class="secadores-exec-room-climate-section">
            <div class="secadores-exec-zone-section-title">Humedad y temperatura de recámaras</div>
            <div class="secadores-exec-room-climate-grid">
              ${rooms.map((room) => {
                const humidityEntry = humidityByRoom.get(room);
                const temperature = temperatureByRoom.get(room);
                const humidityCard = humidityEntry
                  ? renderInlineMetric(
                    'Humedades',
                    humidityEntry[0],
                    { ...humidityEntry[1], label: 'Humedad' },
                    tunnelKey,
                    compactMetricRangeRows(humidityEntry[1])
                  )
                  : '<div class="secadores-exec-inline-metric is-placeholder" aria-hidden="true"></div>';
                const temperatureCard = temperature
                  ? renderZone(
                    { ...temperature, label: 'Temperatura' },
                    tunnelKey,
                    compactMetricRangeRows(temperature)
                  )
                  : '<div class="secadores-exec-zone is-placeholder" aria-hidden="true"></div>';
                const cookieHumidityCard = room === 9 && cookieHumidity
                  ? renderInlineMetric(
                    'Humedades',
                    'verificacion_hum_ultima',
                    { ...cookieHumidity, label: 'Hum. Galleta', timestampLabel: '' },
                    tunnelKey,
                    compactMetricRangeRows(cookieHumidity)
                  )
                  : '';
                const penultimateCookieHumidityCard = room === 8 && penultimateCookieHumidity
                  ? renderInlineMetric(
                    'Humedades',
                    'verificacion_hum_penultima',
                    { ...penultimateCookieHumidity, label: 'Hum. Galleta', timestampLabel: '' },
                    tunnelKey,
                    compactMetricRangeRows(penultimateCookieHumidity)
                  )
                  : '';
                const relativeHumidityCard = room === 5 && relativeHumidityRoom5
                  ? renderInlineMetric(
                    'Humedades',
                    'verificacion_hum_relativa_cam5',
                    { ...relativeHumidityRoom5, label: 'Hum. Rel.', timestampLabel: '' },
                    tunnelKey,
                    compactMetricRangeRows(relativeHumidityRoom5)
                  )
                  : '';

                return `
                  <section class="secadores-exec-room-climate">
                    <h3 class="secadores-exec-room-climate-title">REC ${room}</h3>
                    <div class="secadores-exec-room-climate-cards">
                      ${humidityCard}
                      ${temperatureCard}
                      ${cookieHumidityCard}
                      ${penultimateCookieHumidityCard}
                      ${relativeHumidityCard}
                    </div>
                  </section>
                `;
              }).join('')}
            </div>
          </div>
        `;
      };

      const formatVotatorRangeNumber = (value) => {
        if (value === null || value === undefined || value === '') {
          return '';
        }
        const numericValue = Number(value);
        return Number.isFinite(numericValue)
          ? numericValue.toLocaleString('es-MX', { maximumFractionDigits: 2 })
          : '';
      };

      const buildMetricRangeRows = (field) => {
        const rule = field?.rule && typeof field.rule === 'object' ? field.rule : {};
        const mode = String(rule.modo || 'rango');
        const makeRow = (status, range) => ({
          status,
          label: status === 'verde' ? 'Verde (objetivo)' : status.charAt(0).toUpperCase() + status.slice(1),
          range,
        });
        const makeFiveRows = (lowerRed, lowerYellow, green, upperYellow, upperRed) => [
          makeRow('rojo', lowerRed || 'Por definir'),
          makeRow('amarillo', lowerYellow || 'Por definir'),
          makeRow('verde', green || 'Por definir'),
          makeRow('amarillo', upperYellow || 'Por definir'),
          makeRow('rojo', upperRed || 'Por definir'),
        ];

        if (mode === 'bandas' && Array.isArray(rule.bandas) && rule.bandas.length) {
          const bandDefinitions = rule.bandas.map((band) => {
            const status = ['rojo', 'amarillo', 'verde', 'dorado', 'azul'].includes(String(band?.estado || '').toLowerCase())
              ? String(band.estado).toLowerCase()
              : 'gris';
            const min = formatVotatorRangeNumber(band?.min);
            const max = formatVotatorRangeNumber(band?.max);
            const explicitRange = String(band?.leyenda || '').trim();
            const range = explicitRange || (min && max ? `${min} – ${max}` : (min ? `≥ ${min}` : (max ? `≤ ${max}` : 'Sin límite')));
            return { status, range };
          });
          return bandDefinitions.map((band) => makeRow(band.status, band.range));
        }

        if (mode === 'rango') {
          const greenMin = formatVotatorRangeNumber(rule.verde_min);
          const greenMax = formatVotatorRangeNumber(rule.verde_max);
          const yellowMin = formatVotatorRangeNumber(rule.amarillo_min);
          const yellowMax = formatVotatorRangeNumber(rule.amarillo_max);
          const hasUpperYellow = greenMax && yellowMax && Number(rule.amarillo_max) > Number(rule.verde_max);
          const lowerRedLimit = yellowMin || greenMin;
          const upperRedLimit = hasUpperYellow ? yellowMax : greenMax;

          return makeFiveRows(
            lowerRedLimit ? `< ${lowerRedLimit}` : '',
            yellowMin && greenMin ? `${yellowMin} – < ${greenMin}` : '',
            greenMin && greenMax ? `${greenMin} – ${greenMax}` : '',
            hasUpperYellow ? `> ${greenMax} – ${yellowMax}` : '',
            upperRedLimit ? `> ${upperRedLimit}` : ''
          );
        }

        if (mode === 'minimo') {
          const greenMin = formatVotatorRangeNumber(rule.verde_min);
          const yellowMin = formatVotatorRangeNumber(rule.amarillo_min);
          return makeFiveRows(
            yellowMin ? `< ${yellowMin}` : (greenMin ? `< ${greenMin}` : ''),
            yellowMin && greenMin ? `${yellowMin} – < ${greenMin}` : '',
            greenMin ? `≥ ${greenMin}` : '',
            'No aplica',
            'No aplica'
          );
        }

        if (mode === 'maximo') {
          const greenMax = formatVotatorRangeNumber(rule.verde_max);
          const yellowMax = formatVotatorRangeNumber(rule.amarillo_max);
          return makeFiveRows(
            'No aplica',
            'No aplica',
            greenMax ? `≤ ${greenMax}` : '',
            greenMax && yellowMax ? `> ${greenMax} – ${yellowMax}` : '',
            yellowMax ? `> ${yellowMax}` : (greenMax ? `> ${greenMax}` : '')
          );
        }

        if (mode === 'texto') {
          const values = (status) => Array.isArray(rule[status]) && rule[status].length
            ? rule[status].join(', ')
            : '';
          return [
            makeRow('rojo', values('rojo') || 'Por definir'),
            makeRow('amarillo', values('amarillo') || 'Por definir'),
            makeRow('verde', values('verde') || 'Por definir'),
          ];
        }

        return makeFiveRows('', '', '', '', '');
      };

      const compactMetricRangeRows = (field) => {
        const rows = buildMetricRangeRows(field);
        const presentStatuses = [...new Set(rows.map((row) => row.status).filter((status) => status && status !== 'gris'))];
        const standardStatuses = ['verde', 'amarillo', 'rojo'];
        const statuses = presentStatuses.every((status) => standardStatuses.includes(status))
          ? standardStatuses
          : presentStatuses.slice(0, 3);

        return statuses.map((status) => {
          const statusRows = rows.filter((row) => row.status === status);
          const definedRanges = [...new Set(
            statusRows
              .map((row) => String(row.range || '').trim())
              .filter((range) => range !== '' && range !== 'No aplica' && range !== 'Por definir')
          )];
          const fallback = statusRows.some((row) => row.range === 'Por definir')
            ? 'Por definir'
            : 'No aplica';

          return {
            status,
            label: status === 'verde' ? 'Verde (objetivo)' : status.charAt(0).toUpperCase() + status.slice(1),
            range: definedRanges.length ? definedRanges.join(' o ') : fallback,
          };
        });
      };

      const metricRangeRowsForDisplay = (metricKey, field, tunnelKey) => {
        if (metricKey === 'caudal_aire') {
          const rule = field?.rule && typeof field.rule === 'object' ? field.rule : {};
          const compactThousands = (value) => {
            const number = Number(value);
            return Number.isFinite(number)
              ? `${(number / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 })}k`
              : '';
          };
          const compactNumber = (value) => {
            const number = Number(value);
            return Number.isFinite(number)
              ? (number / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 })
              : '';
          };
          const greenMin = compactNumber(rule.verde_min);
          const greenMax = compactNumber(rule.verde_max);
          const yellowMin = compactNumber(rule.amarillo_min);
          const yellowMax = compactNumber(rule.amarillo_max);

          if (tunnelKey === 'tunel_1') {
            return [
              {
                status: 'verde',
                label: 'Verde (objetivo)',
                range: `≥${compactThousands(rule.verde_min)}`,
              },
              {
                status: 'amarillo',
                label: 'Amarillo',
                range: `${yellowMin}–${greenMin}k`,
              },
              {
                status: 'rojo',
                label: 'Rojo',
                range: `<${compactThousands(rule.amarillo_min)}`,
              },
            ];
          }

          if (tunnelKey !== 'tunel_2') {
            return compactMetricRangeRows(field);
          }

          return [
            {
              status: 'verde',
              label: 'Verde (objetivo)',
              range: greenMin && greenMax ? `${greenMin}–${greenMax}k` : 'Objetivo',
            },
            {
              status: 'amarillo',
              label: 'Amarillo',
              range: `${yellowMin}–${greenMin}k / ${greenMax}–${yellowMax}k`,
            },
            {
              status: 'rojo',
              label: 'Rojo',
              range: `<${compactThousands(rule.amarillo_min)} / >${compactThousands(rule.amarillo_max)}`,
            },
          ];
        }

        return compactMetricRangeRows(field);
      };

      const renderVotators = (votators, tunnelKey) => {
        if (!Array.isArray(votators) || !votators.length) {
          return '';
        }

        return `
          <section class="secadores-exec-votator-section" data-tunnel-key="${escapeHtml(tunnelKey)}">
            <div class="secadores-exec-votators">
              ${votators.map((votator) => {
              const fields = Array.isArray(votator.fields) ? votator.fields : [];

              return `
                <article class="secadores-exec-votator">
                  <div class="secadores-exec-votator-head">
                    <div class="secadores-exec-votator-title">
                      <span>${escapeHtml(votator.label || 'Votator')}</span>
                    </div>
                    <span class="secadores-exec-votator-badge">${escapeHtml(votator.statusLabel || 'Visual')}</span>
                  </div>
                  <ul class="secadores-exec-votator-fields">
                    ${fields.map((field) => {
                      const unit = String(field.unit || '').trim();
                      const value = field.value !== null && field.value !== undefined
                        ? escapeHtml(field.formatted || '-')
                        : escapeHtml(field.emptyLabel || field.formatted || 'Pendiente');
                      const plainValue = String(field.formatted || value || '').trim();
                      const statusKey = plainValue !== '' && plainValue !== '-' && plainValue.toLowerCase() !== 'sin dato'
                        ? String(field.statusKey || 'gris').replace(/[^a-z0-9_-]/gi, '').toLowerCase()
                        : 'gris';
                      const rangeRows = compactMetricRangeRows(field);

                      return `
                        <li class="secadores-exec-votator-field status-${escapeHtml(statusKey)}" title="${escapeHtml(field.rangeLabel || field.statusLabel || '')}">
                          <div class="secadores-exec-votator-field-status">
                            <div class="secadores-exec-votator-field-label">
                              <span>${escapeHtml(field.label || 'Campo')}</span>
                            </div>
                            <div class="secadores-exec-votator-field-value">
                              ${value}${unit ? `<small>${escapeHtml(unit)}</small>` : ''}
                            </div>
                          </div>
                          <div class="secadores-exec-votator-field-parameters">
                            <ul class="secadores-exec-votator-range-list" aria-label="Rangos de ${escapeHtml(field.label || 'Campo')}">
                              ${rangeRows.map((row) => `
                                <li class="secadores-exec-votator-range-item">
                                  <span class="secadores-exec-votator-range-dot ${escapeHtml(row.status)}" aria-hidden="true"></span>
                                  <span class="secadores-exec-votator-range-name">${escapeHtml(row.label)}</span>
                                  <span class="secadores-exec-votator-range-value">${escapeHtml(row.range)}</span>
                                </li>
                              `).join('')}
                            </ul>
                          </div>
                        </li>
                      `;
                    }).join('')}
                  </ul>
                </article>
              `;
              }).join('')}
            </div>
          </section>
        `;
      };

      const renderTunnel = (tunnel) => {
        const cells = tunnel.cells || [];
        const headerIndicator = renderHeaderIndicator(state.payload.indicadores || {});

        return `
          <section class="secadores-exec-tunnel">
            <div class="secadores-exec-tunnel-head">
              <div class="secadores-exec-tunnel-title">
                <div class="secadores-exec-tunnel-title-row">
                  <h2>${escapeHtml(tunnel.titulo || 'Túnel')}</h2>
                  ${headerIndicator}
                </div>
                ${renderVotators(tunnel.votators || [], tunnel.key || '')}
                ${renderInlineMetrics(tunnel.metricas || {}, tunnel.key || '', 'main')}
                <div class="secadores-exec-tunnel-body">
                  ${renderRoomClimate(tunnel.metricas || {}, cells, tunnel.key || '')}
                </div>
                ${renderInlineMetrics(tunnel.metricas || {}, tunnel.key || '', 'verification')}
              </div>
            </div>
          </section>
        `;
      };

      const render = () => {
        const app = document.getElementById('secadoresExecApp');
        if (!app) return;

        let tunnels = Object.values(state.payload.tuneles || {});
        const captureSecador = String(window.secadoresCaptureSecador || '').trim();
        if (captureSecador) {
          tunnels = tunnels.filter((tunnel) => String(tunnel.key || '') === captureSecador);
        }
        app.innerHTML = '<div class="secadores-exec-grid">' + tunnels.map(renderTunnel).join('') + '</div>';
      };

      const updateRefreshBadge = (ok, message) => {
        const badge = document.getElementById('secadoresExecRefreshBadge');
        if (!badge) return;

        if (ok) {
          badge.innerHTML = '<i class="fas fa-rotate"></i> Actualizado: ' + new Date().toLocaleTimeString();
          return;
        }

        badge.innerHTML = '<i class="fas fa-triangle-exclamation"></i> ' + message;
      };

      const fetchData = async () => {
        try {
          const response = await fetch('data.php?t=' + Date.now(), {
            cache: 'no-store'
          });
          if (!response.ok) throw new Error('HTTP ' + response.status);

          const payload = await response.json();
          if (payload && !payload.error) {
            state.payload = payload;
            render();
            updateRefreshBadge(true, '');
            return;
          }

          throw new Error(payload?.message || 'No se pudo refrescar el reporte.');
        } catch {
          updateRefreshBadge(false, 'Sin actualizar');
        }
      };

      const findMetricCard = (tunnelKey, metricKey) => {
        return Array.from(document.querySelectorAll(`.secadores-exec-inline-metric[data-metric-key="${metricKey}"]`))
          .find((element) => element.dataset.tunnelKey === tunnelKey) || null;
      };

      const applyFastFields = (payload) => {
        if (!payload?.tuneles || !state.payload?.tuneles) {
          return false;
        }

        let updated = false;

        Object.entries(payload.tuneles).forEach(([tunnelKey, incomingTunnel]) => {
          const currentTunnel = state.payload.tuneles?.[tunnelKey];
          if (!currentTunnel) {
            return;
          }

          [
            ['presion_vapor', 'Agua y vapor'],
            ['caudal_aire', 'Banda'],
          ].forEach(([metricKey, groupName]) => {
            const incomingMetric = incomingTunnel?.metricas?.[metricKey];
            if (!incomingMetric) {
              return;
            }

            currentTunnel.metricas = currentTunnel.metricas || {};
            currentTunnel.metricas[metricKey] = incomingMetric;

            const card = findMetricCard(tunnelKey, metricKey);
            if (card) {
              card.outerHTML = renderInlineMetric(groupName, metricKey, incomingMetric, tunnelKey);
              updated = true;
            }
          });

          if (Array.isArray(incomingTunnel?.votators)) {
            currentTunnel.votators = incomingTunnel.votators;

            const votatorBlock = Array.from(document.querySelectorAll('.secadores-exec-votator-section'))
              .find((element) => element.dataset.tunnelKey === tunnelKey);
            if (votatorBlock) {
              votatorBlock.outerHTML = renderVotators(currentTunnel.votators, tunnelKey);
              updated = true;
            }
          }
        });

        return updated;
      };

      const fetchFastFields = async () => {
        try {
          const response = await fetch('data.php?scope=fast&t=' + Date.now(), {
            cache: 'no-store'
          });
          if (!response.ok) throw new Error('HTTP ' + response.status);

          const payload = await response.json();
          if (payload && !payload.error) {
            applyFastFields(payload);
          }
        } catch {
          // El refresco rápido no debe afectar el estado del reporte completo.
        }
      };

      document.addEventListener('click', (event) => {
        const printButton = event.target.closest('#secadoresPrintBtn');
        if (printButton) {
          window.print();
          return;
        }

        const metricCard = event.target.closest('.secadores-exec-inline-metric.clickable');
        if (metricCard) {
          openHistoryModal(metricCard.dataset.tunnelKey || '', metricCard.dataset.metricKey || '');
          return;
        }

        const temperatureCard = event.target.closest('.secadores-exec-zone.clickable[data-open-temperature="1"]');
        if (temperatureCard) {
          const tunnelKey = temperatureCard.dataset.tunnelKey || '';
          window.location.href = `../secadores-temperatura/index.php?tunel=${encodeURIComponent(tunnelKey)}`;
          return;
        }

        if (
          event.target.id === 'secadoresHistoryClose' ||
          event.target.closest('#secadoresHistoryClose') ||
          event.target.id === 'secadoresHistoryModal'
        ) {
          closeHistoryModal();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
          closeHistoryModal();
        }
      });

      render();
      updateRefreshBadge(true, '');
      state.timer = window.setInterval(fetchData, Number(state.payload.meta?.intervaloActualizacion || 600000));
      state.fastTimer = window.setInterval(fetchFastFields, Number(state.payload.meta?.intervaloActualizacionRapida || 60000));
    })();
  </script>
</body>

</html>
