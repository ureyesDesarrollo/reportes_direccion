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
      color: #0f172a;
      font-weight: 700;
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

    .secadores-exec-inline-group {
      display: grid;
      gap: 5px;
    }

    .secadores-exec-inline-group-title {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #475569;
    }

    .secadores-exec-inline-group-items {
      display: grid;
      grid-template-columns: repeat(var(--metric-cols, 3), minmax(0, 1fr));
      grid-auto-rows: minmax(82px, 1fr);
      gap: 6px;
    }

    .secadores-exec-inline-group-items.is-banda {
      --metric-cols: 2;
    }

    .secadores-exec-inline-group-items.is-agua-y-vapor,
    .secadores-exec-inline-group-items.is-humedades {
      --metric-cols: 3;
    }

    .secadores-exec-inline-metric {
      display: inline-flex;
      align-items: flex-start;
      gap: 8px;
      padding: 8px 10px;
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
      background: #e49a32;
      border-color: #c47b1c;
    }

    .secadores-exec-inline-metric.danger {
      background: #c94436;
      border-color: #a9362c;
    }

    .secadores-exec-inline-metric.ok {
      background: #2e8b57;
      border-color: #257447;
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
      font-size: 9px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
      opacity: 0.86;
      color: inherit;
      min-height: 22px;
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
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 8px;
    }

    .secadores-exec-zone {
      padding: 10px;
      min-height: 112px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      position: relative;
      border-radius: 12px;
      border: 1px solid rgba(226, 232, 240, 0.4);
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .secadores-exec-zone.is-placeholder {
      visibility: hidden;
      pointer-events: none;
      box-shadow: none;
    }

    .secadores-exec-zone.clickable {
      cursor: pointer;
    }

    .secadores-exec-zone:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.10);
    }

    /* Estados por color plano */

    .secadores-exec-zone[data-status="ok"] {
      background: #dcfce7;
      border-color: #86efac;
      box-shadow: 0 4px 10px rgba(34, 197, 94, 0.10);
    }

    .secadores-exec-zone[data-status="ok"]:hover {
      box-shadow: 0 8px 24px rgba(74, 222, 128, 0.15);
      border-color: rgba(74, 222, 128, 0.5);
    }

    /* Amarillo visible, pero más ejecutivo */
    .secadores-exec-zone[data-status="warning"] {
      background: #fde68a;
      border-color: #e49a32;
      box-shadow: 0 4px 12px rgba(245, 158, 11, 0.16);
    }

    .secadores-exec-zone[data-status="warning"]:hover {
      box-shadow: 0 10px 24px rgba(245, 158, 11, 0.22);
      border-color: #c47b1c;
    }

    .secadores-exec-zone[data-status="danger"] {
      background: #fecaca;
      border-color: #f87171;
      box-shadow: 0 4px 10px rgba(239, 68, 68, 0.12);
    }

    .secadores-exec-zone[data-status="danger"]:hover {
      box-shadow: 0 8px 24px rgba(239, 68, 68, 0.15);
      border-color: rgba(239, 68, 68, 0.5);
    }

    .secadores-exec-zone[data-status="unknown"] {
      background: #e2e8f0;
      border-color: #cbd5e1;
    }

    .secadores-exec-zone-label {
      font-size: 10px;
      font-weight: 800;
      color: #334155;
      padding-left: 2px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .secadores-exec-zone-value {
      font-size: 22px;
      line-height: 1;
      font-weight: 800;
      color: #0f172a;
      padding-left: 2px;
      margin: 2px 0;
    }

    .secadores-exec-zone-value small {
      font-size: 11px;
      color: #64748b;
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

    /* ============================================
   BADGE DE AJUSTE — GRANDE Y PROMINENTE
   ============================================ */

    .secadores-exec-zone-cue {
      display: flex;
      align-items: center;
      gap: 6px;
      align-self: stretch;
      margin-top: 4px;
      padding: 6px 8px;
      min-height: 44px;
      box-sizing: border-box;
      border-radius: 8px;
      font-size: 10px;
      font-weight: 800;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
      cursor: default;
    }

    .secadores-exec-zone-cue:hover {
      transform: scale(1.02);
    }

    .secadores-exec-zone-cue .cue-icon {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 22px;
      height: 22px;
      border-radius: 6px;
      flex-shrink: 0;
      font-size: 12px;
      background: rgba(255, 255, 255, 0.22);
    }

    .secadores-exec-zone-cue .cue-text {
      display: flex;
      flex-direction: column;
      gap: 1px;
      line-height: 1.2;
      min-width: 0;
    }

    .secadores-exec-zone-cue .cue-text strong {
      font-size: 10px;
      font-weight: 800;
      display: block;
    }

    .secadores-exec-zone-cue .cue-text span {
      font-size: 9px;
      font-weight: 600;
      opacity: 0.85;
      display: block;
      text-transform: none;
      letter-spacing: 0;
    }

    /* SUBIR — azul */
    .secadores-exec-zone-cue.cue-up {
      background: #2563eb;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.38);
      border: none;
    }

    /* BAJAR — rojo */
    .secadores-exec-zone-cue.cue-down {
      background: #a9362c;
      color: #ffffff;
      box-shadow: 0 4px 12px rgba(220, 38, 38, 0.38);
      border: none;
    }

    /* NEUTRO */
    .secadores-exec-zone-cue.cue-neutral {
      background: #e2e8f0;
      color: #475569;
      box-shadow: none;
      border: none;
    }

    .secadores-exec-zone-cue.cue-neutral .cue-icon {
      background: rgba(71, 85, 105, 0.12);
    }

    .secadores-exec-votators {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
      margin-top: 6px;
      width: 100%;
    }

    .secadores-exec-votator {
      display: grid;
      grid-template-columns: minmax(118px, .36fr) minmax(0, 1fr);
      align-items: stretch;
      gap: 10px;
      padding: 10px;
      border-radius: 12px;
      background: #f8fbff;
      border: 1px solid #dbeafe;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    }

    .secadores-exec-votator-head {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      justify-content: center;
      gap: 5px;
      min-width: 0;
    }

    .secadores-exec-votator-title {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
      color: #0f172a;
      font-size: 13px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .secadores-exec-votator-title i {
      color: #2563eb;
      font-size: 15px;
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
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .secadores-exec-votator-field {
      min-height: 58px;
      padding: 10px 12px;
      border-radius: 10px;
      background: #ffffff;
      border: 1px dashed #cbd5e1;
      display: grid;
      gap: 4px;
      align-content: center;
    }

    .secadores-exec-votator-field.status-verde {
      background: #2e8b57;
      color: white;
      border-color: #257447;
    }

    .secadores-exec-votator-field.status-amarillo {
      background: #e49a32;
      color: white;
      border-color: #c47b1c;
    }

    .secadores-exec-votator-field.status-rojo {
      background: #c94436;
      color: white;
      border-color: #a9362c;
    }

    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-label,
    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-label i,
    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-verde .secadores-exec-votator-field-value small,
    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-label,
    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-label i,
    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-amarillo .secadores-exec-votator-field-value small,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-label,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-label i,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-value,
    .secadores-exec-votator-field.status-rojo .secadores-exec-votator-field-value small {
      color: white;
    }

    .secadores-exec-votator-field-label {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #475569;
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .secadores-exec-votator-field-label i {
      color: #64748b;
      font-size: 12px;
    }

    .secadores-exec-votator-field-value {
      color: #0f172a;
      font-size: 22px;
      font-weight: 800;
      line-height: 1;
    }

    .secadores-exec-votator-field-value small {
      margin-left: 4px;
      color: #64748b;
      font-size: 12px;
      font-weight: 700;
    }

    /* Animación en el ícono */
    .secadores-exec-zone-cue.cue-up .cue-icon i {
      animation: bounce-up 1.4s ease-in-out infinite;
    }

    .secadores-exec-zone-cue.cue-down .cue-icon i {
      animation: bounce-down 1.4s ease-in-out infinite;
    }

    @keyframes bounce-up {

      0%,
      100% {
        transform: translateY(0);
        opacity: 1;
      }

      50% {
        transform: translateY(-3px);
        opacity: 0.7;
      }
    }

    @keyframes bounce-down {

      0%,
      100% {
        transform: translateY(0);
        opacity: 1;
      }

      50% {
        transform: translateY(3px);
        opacity: 0.7;
      }
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
      border: 1px solid #e49a32;
      color: #78350f;
      box-shadow: 0 6px 16px rgba(245, 158, 11, 0.12);
      font-size: 11px;
      line-height: 1.3;
      font-weight: 600;
    }

    .secadores-exec-warning-strip i {
      font-size: 14px;
      color: #c47b1c;
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
      border-color: #e49a32;
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
      border: 1px solid #e49a32;
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
        min-height: 108px;
      }
    }
  </style>
</head>

<body>
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
        </div>

        <h1>
          <i class="fas fa-fan" style="margin-right: 12px;"></i>
          <?= htmlspecialchars($titulo) ?>
        </h1>

        <div class="sub">
          <span>
            <i class="fas fa-temperature-three-quarters"></i>
            Vista rápida para dirección
          </span>
          <span>
            <i class="fas fa-clock"></i>
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
        const bg = bgColor || color + '1A';
        return '<span class="secadores-exec-status" style="background:' + bg + '; color:' + color + ';">' +
          '<i class="fas fa-circle" style="font-size:10px;"></i>' +
          escapeHtml(label) +
          '</span>';
      };

      const renderInlineMetric = (groupName, metricKey, metric, tunnelKey) => {
        const statusClass = metric.available
          ? (
            metric.statusKey === 'verde' ? ' ok' :
            metric.statusKey === 'amarillo' ? ' warning' :
            metric.statusKey === 'rojo' ? ' danger' :
            ''
          )
          : ' unavailable';

        const value = metric.value !== null && metric.value !== undefined
          ? `${escapeHtml(metric.formatted || '-')} ${escapeHtml(metric.unit || '')}`.trim()
          : escapeHtml(metric.emptyLabel || 'Sin dato');
        const isClickable = Array.isArray(metric.history) && metric.history.length > 0;
        const rangeLabel = String(metric.rangeLabel || '').trim();
        const rangeClass = rangeLabel ? '' : ' is-empty';
        const rangeAttrs = rangeLabel ? '' : ' aria-hidden="true"';
        const metricIcon =
          groupName === 'Agua y vapor' ? 'fa-fire-flame-curved' :
          groupName === 'Humedades' ? 'fa-droplet' :
          'fa-gauge-high';

        return `
          <div class="secadores-exec-inline-metric${statusClass}${isClickable ? ' clickable' : ''}" data-tunnel-key="${escapeHtml(tunnelKey)}" data-metric-key="${escapeHtml(metricKey)}">
            <i class="fas ${metricIcon}"></i>
            <div class="secadores-exec-inline-metric-body">
              <div class="secadores-exec-inline-metric-label">${escapeHtml(metric.label || 'Métrica')}</div>
              <div class="secadores-exec-inline-metric-value">${value}</div>
              <div class="secadores-exec-inline-metric-range${rangeClass}"${rangeAttrs}>${escapeHtml(rangeLabel || 'Rango pendiente de definir')}</div>
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
        };
        const columns = normalized === 'banda' ? 2 : 3;
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

      const renderInlineMetrics = (metrics, tunnelKey) => {
        const entries = Object.entries(metrics || {});
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
              const targetSlots = metricGroupSlots(groupName, groupMetrics.length);
              const placeholders = renderMetricPlaceholders(targetSlots - groupMetrics.length);
              return `
                <div class="secadores-exec-inline-group">
                  <div class="secadores-exec-inline-group-title">${escapeHtml(groupName)}</div>
	                  <div class="secadores-exec-inline-group-items is-${escapeHtml(groupClass)}">
	                    ${groupMetrics.map(([metricKey, metric]) => renderInlineMetric(groupName, metricKey, metric, tunnelKey)).join('')}
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

      const renderZone = (cell, tunnelKey) => {
        const statusAttr = getStatusAttribute(cell.statusLabel);
        const rangeLabel = String(cell.rangeLabel || '').trim();
        const rangeClass = rangeLabel ? '' : ' is-empty';
        const rangeAttrs = rangeLabel ? '' : ' aria-hidden="true"';
        const adjustment = cell.adjustmentCue || {};
        const dir = adjustment.icon || 'neutral';
        const cueClass = dir === 'up' ? 'cue-up' : dir === 'down' ? 'cue-down' : 'cue-neutral';
        const iconName = dir === 'up' ?
          'fa-arrow-up' :
          dir === 'down' ?
          'fa-arrow-down' :
          'fa-minus';
        const cueLabel = adjustment.label || (
          cell.statusLabel === 'Óptimo' ?
            'Temperatura estable' :
            cell.statusLabel === 'Sin dato' ?
              'Sin lectura' :
              'Sin ajuste definido'
        );
        const subtext = dir === 'up' ?
          'Aumentar temperatura' :
          dir === 'down' ?
          'Reducir temperatura' :
          cell.statusLabel === 'Sin dato' ?
            'Esperando medición' :
            'Sin ajuste requerido';

        const cueHtml = `
      <div class="secadores-exec-zone-cue ${cueClass}"
           role="status"
           aria-label="${escapeHtml(cueLabel)}">
        <div class="cue-icon">
          <i class="fas ${iconName}" aria-hidden="true"></i>
        </div>
        <div class="cue-text">
          <strong>${escapeHtml(cueLabel)}</strong>
          <span>${subtext}</span>
        </div>
      </div>
    `;

        return `
    <article class="secadores-exec-zone clickable" data-status="${statusAttr}" data-open-temperature="1" data-tunnel-key="${escapeHtml(tunnelKey)}">
      <div class="secadores-exec-zone-label">${escapeHtml(cell.label || 'Recámara')}</div>
      ${renderStatus(cell.statusLabel || 'Sin dato', cell.statusColor || '#94a3b8')}
      <div class="secadores-exec-zone-value">
        ${escapeHtml(cell.formatted || '-')}
        <small>°C</small>
      </div>
      <div class="secadores-exec-zone-range${rangeClass}"${rangeAttrs}>${escapeHtml(rangeLabel || 'Rango pendiente de definir')}</div>
      ${cueHtml}
	    </article>
	  `;
      };

      const renderVotators = (votators, tunnelKey) => {
        if (!Array.isArray(votators) || !votators.length) {
          return '';
        }

        return `
          <div class="secadores-exec-votators" data-tunnel-key="${escapeHtml(tunnelKey)}">
            ${votators.map((votator) => {
              const fields = Array.isArray(votator.fields) ? votator.fields : [];

              return `
                <article class="secadores-exec-votator">
                  <div class="secadores-exec-votator-head">
                    <div class="secadores-exec-votator-title">
                      <i class="fas fa-arrows-spin"></i>
                      <span>${escapeHtml(votator.label || 'Votator')}</span>
                    </div>
                    <span class="secadores-exec-votator-badge">${escapeHtml(votator.statusLabel || 'Visual')}</span>
                  </div>
                  <div class="secadores-exec-votator-fields">
                    ${fields.map((field) => {
                      const fieldKey = String(field.key || '');
                      const fieldIcon = field.icon || (fieldKey === 'flujo' ? 'fa-water' : 'fa-gauge-high');
                      const unit = String(field.unit || '').trim();
                      const statusKey = String(field.statusKey || 'gris').replace(/[^a-z0-9_-]/gi, '').toLowerCase();
                      const value = field.value !== null && field.value !== undefined
                        ? escapeHtml(field.formatted || '-')
                        : escapeHtml(field.emptyLabel || field.formatted || 'Pendiente');

                      return `
                        <div class="secadores-exec-votator-field status-${escapeHtml(statusKey)}" title="${escapeHtml(field.rangeLabel || field.statusLabel || '')}">
                          <div class="secadores-exec-votator-field-label">
                            <i class="fas ${escapeHtml(fieldIcon)}"></i>
                            <span>${escapeHtml(field.label || 'Campo')}</span>
                          </div>
                          <div class="secadores-exec-votator-field-value">
                            ${value}${unit ? `<small>${escapeHtml(unit)}</small>` : ''}
                          </div>
                        </div>
                      `;
                    }).join('')}
                  </div>
                </article>
              `;
            }).join('')}
          </div>
        `;
      };

      const renderTunnel = (tunnel) => {
        const cells = tunnel.cells || [];
        const targetCells = Math.max(8, Math.ceil(cells.length / 4) * 4);

        return `
          <section class="secadores-exec-tunnel">
            <div class="secadores-exec-tunnel-head">
              <div class="secadores-exec-tunnel-title">
                <h2>${escapeHtml(tunnel.titulo || 'Túnel')}</h2>
                <div class="secadores-exec-tunnel-sub">
                  <span><i class="fas fa-clock"></i> ${escapeHtml(tunnel.ultimaLectura || '-')}</span>
                  <span>${renderStatus(tunnel.statusLabel || 'Referencia', tunnel.statusColor || '#94a3b8')}</span>
                </div>
                ${renderVotators(tunnel.votators || [], tunnel.key || '')}
                ${renderInlineMetrics(tunnel.metricas || {}, tunnel.key || '')}
              </div>
            </div>
            <div class="secadores-exec-tunnel-body">
              <div class="secadores-exec-zones">
                  ${cells.map((cell) => renderZone(cell, tunnel.key || '')).join('')}
                  ${renderZonePlaceholders(targetCells - cells.length)}
                </div>
              </div>
            </section>
        `;
      };

      const render = () => {
        const app = document.getElementById('secadoresExecApp');
        if (!app) return;

        const tunnels = Object.values(state.payload.tuneles || {});
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

          const incomingPresionVapor = incomingTunnel?.metricas?.presion_vapor;
          if (incomingPresionVapor) {
            currentTunnel.metricas = currentTunnel.metricas || {};
            currentTunnel.metricas.presion_vapor = incomingPresionVapor;

            const card = findMetricCard(tunnelKey, 'presion_vapor');
            if (card) {
              card.outerHTML = renderInlineMetric('Agua y vapor', 'presion_vapor', incomingPresionVapor, tunnelKey);
              updated = true;
            }
          }

          if (Array.isArray(incomingTunnel?.votators)) {
            currentTunnel.votators = incomingTunnel.votators;

            const votatorBlock = Array.from(document.querySelectorAll('.secadores-exec-votators'))
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
