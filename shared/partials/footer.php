<?php

/** @var int $anioAnterior */
/** @var float|null $ratioBase */
/** @var float|null $limiteAmarillo */
/** @var array $meta */

$toleranciaPct = $meta['toleranciaPct'] ?? 10;
$intervaloActualizacion = $meta['intervaloActualizacion'] ?? 300000;
$modo = $meta['modo'] ?? 'consumo';
?>
<div class="nota">
  <strong><i class="fas fa-info-circle"></i> Lógica del Semáforo (Modo: <?= htmlspecialchars(ucfirst($modo)) ?>):</strong><br>

  <?php if ($modo === 'consumo'): ?>
    1. <strong>Ratio Base (<?= htmlspecialchars((string)$anioAnterior) ?>):</strong>
    <?= $ratioBase !== null ? n($ratioBase, 2) : 'No disponible' ?> kg químico / kg producción<br>

    2. <strong>🟢 Verde (Óptimo):</strong>
    Ratio ≤ Ratio Base (<?= $ratioBase !== null ? n($ratioBase, 2) : '-' ?>)<br>

    3. <strong>🟡 Amarillo (Cuidado):</strong>
    Ratio Base &lt; Ratio ≤ Base + <?= htmlspecialchars((string)$toleranciaPct) ?>%
    (<?= $limiteAmarillo !== null ? n($limiteAmarillo, 2) : '-' ?>)<br>

    4. <strong>🔴 Rojo (Alto):</strong>
    Ratio &gt; Base + <?= htmlspecialchars((string)$toleranciaPct) ?>%
    (<?= $limiteAmarillo !== null ? n($limiteAmarillo, 2) : '-' ?>)<br>

    5. <strong>Variación negativa (↓) = MEJORA</strong>
    (menos químico por kg) |
    <strong>Variación positiva (↑) = DETERIORO</strong>
    (más químico por kg)<br>

  <?php elseif ($modo === 'costo'): ?>
    1. <strong>Costo Base (<?= htmlspecialchars((string)$anioAnterior) ?>):</strong>
    <?= $ratioBase !== null ? '$ ' . n($ratioBase, 2) : 'No disponible' ?> promedio del grupo<br>

    2. <strong>🟢 Verde (Óptimo):</strong>
    Costo ≤ Costo Base (<?= $ratioBase !== null ? '$ ' . n($ratioBase, 2) : '-' ?>)<br>

    3. <strong>🟡 Amarillo (Cuidado):</strong>
    Costo Base &lt; Costo ≤ Base + <?= htmlspecialchars((string)$toleranciaPct) ?>%
    (<?= $limiteAmarillo !== null ? '$ ' . n($limiteAmarillo, 2) : '-' ?>)<br>

    4. <strong>🔴 Rojo (Alto):</strong>
    Costo &gt; Base + <?= htmlspecialchars((string)$toleranciaPct) ?>%
    (<?= $limiteAmarillo !== null ? '$ ' . n($limiteAmarillo, 2) : '-' ?>)<br>

    5. <strong>Variación negativa (↓) = MEJORA</strong>
    (costo menor) |
    <strong>Variación positiva (↑) = DETERIORO</strong>
    (costo mayor)<br>

  <?php elseif ($modo === 'impacto'): ?>
    1. <strong>Cálculo del Impacto:</strong><br>
    &nbsp;&nbsp;- Diferencia de precio = Costo semanal - Costo base<br>
    &nbsp;&nbsp;- Impacto total = Diferencia × Consumo semanal<br>
    &nbsp;&nbsp;- Ratio = Impacto total / Producción semanal<br>

    3. <strong>🟢 Verde (Ahorro):</strong>
    Ratio ≤ 0% (costo actual ≤ costo histórico → ahorro económico)<br>

    3. <strong>🟡 Amarillo (Cuidado):</strong>
    0% &lt; Ratio ≤ Base + 6%
    (<?= $limiteAmarillo !== null ? n(($limiteAmarillo) * 100, 2) . '%' : '-' ?>)<br>

    4. <strong>🔴 Rojo (Sobrecosto):</strong>
    Ratio &gt; Base + 6%
    (<?= $limiteAmarillo !== null ? n(($limiteAmarillo) * 100, 2) . '%' : '-' ?>)<br>

    6. <strong>Interpretación:</strong><br>
    &nbsp;&nbsp;- Ratio negativo = Estás ahorrando dinero<br>
    &nbsp;&nbsp;- Ratio positivo = Estás gastando más de lo habitual<br>
    &nbsp;&nbsp;- Unidad: % de incremento por kg de producción<br>

  <?php endif; ?>

  6. <strong>⏰ Actualización automática:</strong>
  Cada <?= (int)($intervaloActualizacion / 60000) ?> minutos
</div>
</div>
