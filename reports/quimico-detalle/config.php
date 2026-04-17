<?php

declare(strict_types=1);

$productoSeleccionado = isset($_GET['producto']) ? trim((string)$_GET['producto']) : null;

if ($productoSeleccionado === '' || $productoSeleccionado === null) {
  $productoSeleccionado = 'DETERZYME1';
}

$productoLabel = isset($_GET['productoLabel']) ? trim((string)$_GET['productoLabel']) : $productoSeleccionado;

if ($productoLabel === '' || $productoLabel === null) {
  $productoLabel = $productoSeleccionado;
}

$modoSolicitado = isset($_GET['modo']) ? trim((string)$_GET['modo']) : 'consumo';

$modosPermitidos = ['consumo', 'costo', 'impacto'];

$tolerancia_pct = 10;

if (!in_array($modoSolicitado, $modosPermitidos, true)) {
  $modoSolicitado = 'consumo';
  $tolerancia_pct = 6;
}

return [
  'titulo' => $productoLabel . ' / Producción',
  'producto_seleccionado' => $productoSeleccionado,
  'producto_label' => $productoLabel,
  'modo' => $modoSolicitado,

  'fecha_desde' => '2025-01-01',
  'campo_fecha_movs' => 'F_MOV',

  'productos' => [$productoSeleccionado],

  'tolerancia_pct' => $tolerancia_pct,
  'cve_mov' => '17',

  'campo_costo' => 'COSTO_ENT',
];
