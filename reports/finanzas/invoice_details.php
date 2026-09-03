<?php

declare(strict_types=1);

if (!isset($metric, $e, $money)) {
  http_response_code(404);
  exit;
}

$invoiceDetails = (array)($metric['detalle_facturas'] ?? []);
$currencyLabels = ['1' => 'MXN', '2' => 'USD', '3' => 'EUR'];
?>
<div class="invoice-panel">
  <div class="invoice-panel-title">Detalle por factura</div>
  <?php foreach ($invoiceDetails as $invoice): ?>
    <details class="invoice-item" data-provider="<?= $e($invoice['proveedor_clave'] ?? '') ?>" data-invoice="<?= $e($invoice['numero'] ?? '') ?>">
      <summary>
        <span class="invoice-provider"><?= $e($invoice['proveedor'] ?? 'Proveedor sin nombre') ?></span>
        <span class="invoice-reference">Factura <?= $e($invoice['numero'] ?? 'Sin número') ?> · <?= $e($invoice['fecha'] ?? '') ?></span>
        <strong><?= $money($invoice['subtotal_convertido'] ?? null) ?></strong>
      </summary>
      <div class="invoice-detail-grid">
        <div><span>Subtotal</span><strong><?= $money($invoice['importes']['subtotal'] ?? null) ?></strong></div>
        <div><span>Total factura</span><strong><?= $money($invoice['importes']['total'] ?? null) ?></strong></div>
        <div><span>Moneda</span><strong><?= $e($currencyLabels[(string)($invoice['moneda'] ?? '')] ?? '—') ?></strong></div>
        <div><span>Tipo de cambio</span><strong><?= number_format((float)($invoice['tipo_cambio'] ?? 1), 2, '.', ',') ?></strong></div>
      </div>
      <div class="line-items" data-lines-state="idle">
        <div class="line-items-placeholder">Abre la factura para consultar sus partidas.</div>
      </div>
    </details>
  <?php endforeach; ?>
</div>
