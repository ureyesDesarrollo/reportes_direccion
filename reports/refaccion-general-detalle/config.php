<?php

declare(strict_types=1);

$productoSeleccionado = isset($_GET['producto']) ? trim((string)$_GET['producto']) : null;

if ($productoSeleccionado === '' || $productoSeleccionado === null) {
  $productoSeleccionado = null;
}

$productoLabel = isset($_GET['productoLabel']) ? trim((string)$_GET['productoLabel']) : $productoSeleccionado;

if ($productoLabel === '' || $productoLabel === null) {
  $productoLabel = $productoSeleccionado;
}

$modoSolicitado = isset($_GET['modo']) ? trim((string)$_GET['modo']) : 'consumo';

$modosPermitidos = ['consumo', 'costo', 'impacto'];

if (!in_array($modoSolicitado, $modosPermitidos, true)) {
  $modoSolicitado = 'consumo';
}

return [
  'titulo'                => ($productoLabel ?? 'Refacción') . ' / Refacción General',
  'producto_seleccionado' => $productoSeleccionado,
  'productoLabel'         => $productoLabel,
  'modo'                  => $modoSolicitado,

  'fecha_desde'      => '2024-01-01',
  'campo_fecha_movs' => 'F_MOV',

  'tolerancia_pct'    => 10,
  'cve_mov'           => null,
  'lugar'             => 'REFACCIONE',

  // Modos disponibles: consumo, costo, impacto (ahorro/pérdida por diferencia de precio)
  'modos_disponibles' => ['consumo', 'costo', 'impacto'],
];
