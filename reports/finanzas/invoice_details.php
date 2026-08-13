<?php

declare(strict_types=1);

if (!isset($metric, $e, $money)) {
  http_response_code(404);
  exit;
}

$invoiceDetails = (array)($metric['detalle_facturas'] ?? []);
?>
<div class="invoice-panel">
  <div class="invoice-panel-title">Detalle por factura</div>
  <?php foreach ($invoiceDetails as $invoice): ?>
    <details class="invoice-item">
      <summary>
        <span class="invoice-provider"><?= $e($invoice['proveedor'] ?? 'Proveedor sin nombre') ?></span>
        <span class="invoice-reference">Factura <?= $e($invoice['numero'] ?? 'Sin número') ?> · <?= $e($invoice['fecha'] ?? '') ?></span>
        <strong><?= $money($invoice['total_convertido'] ?? null) ?></strong>
      </summary>
      <div class="invoice-detail-grid">
        <div><span>Proveedor</span><strong><?= $e($invoice['proveedor'] ?? '—') ?></strong></div>
        <div><span>Clave proveedor</span><strong><?= $e($invoice['proveedor_clave'] ?? '—') ?></strong></div>
        <div><span>Estatus</span><strong><?= $e($invoice['estatus'] !== '' ? $invoice['estatus'] : '—') ?></strong></div>
        <div><span>Vencimiento</span><strong><?= $e($invoice['fecha_vencimiento'] !== '' ? $invoice['fecha_vencimiento'] : '—') ?></strong></div>
        <div><span>Pedido</span><strong><?= $e($invoice['numero_pedido'] ?? '—') ?></strong></div>
        <div><span>Orden</span><strong><?= $e($invoice['numero_orden'] ?? '—') ?></strong></div>
        <div><span>Subtotal</span><strong><?= $money($invoice['importes']['subtotal'] ?? null) ?></strong></div>
        <div><span>Descuento</span><strong><?= $money($invoice['importes']['descuento'] ?? null) ?></strong></div>
        <div><span>IVA</span><strong><?= $money($invoice['importes']['iva'] ?? null) ?></strong></div>
        <div><span>IEPS</span><strong><?= $money($invoice['importes']['ieps'] ?? null) ?></strong></div>
        <div><span>Retención</span><strong><?= $money($invoice['importes']['retencion'] ?? null) ?></strong></div>
        <div><span>Retención IVA</span><strong><?= $money($invoice['importes']['retencion_iva'] ?? null) ?></strong></div>
        <div><span>Total factura</span><strong><?= $money($invoice['importes']['total'] ?? null) ?></strong></div>
        <div><span>Saldo</span><strong><?= $money($invoice['importes']['saldo'] ?? null) ?></strong></div>
        <div><span>Moneda</span><strong><?= $e($invoice['moneda'] ?? '—') ?></strong></div>
        <div><span>Tipo de cambio</span><strong><?= number_format((float)($invoice['tipo_cambio'] ?? 1), 4, '.', ',') ?></strong></div>
      </div>
      <?php if (!empty($invoice['datos_estadisticos'])): ?>
        <div class="invoice-statistics">
          <?php foreach ((array)$invoice['datos_estadisticos'] as $statistic): ?>
            <span><?= $e($statistic['campo'] ?? 'Clasificación') ?>: <?= $e($statistic['clave'] ?? '—') ?><?= ($statistic['nombre'] ?? '') !== '' ? ' · ' . $e($statistic['nombre']) : '' ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
</div>
