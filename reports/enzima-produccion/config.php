<?php

declare(strict_types=1);

$grupos = [
  'enzimas_preparacion' => [
    'titulo' => 'ENZIMA Preparación / Producción',
    'productos' => [
      'DETERZYME1',
      'COROLASE',
    ],
  ],

  'enzimas_pelambre' => [
    'titulo' => 'ENZIMA Pelambre / Producción',
    'productos' => [
      'BUZ78',
      'BUZ77',
    ],
  ],
];

$grupoSolicitado = isset($_GET['grupo']) ? trim((string)$_GET['grupo']) : 'enzimas_preparacion';

if (!isset($grupos[$grupoSolicitado])) {
  $grupoSolicitado = 'enzimas_preparacion';
}

$productoSeleccionado = isset($_GET['producto']) ? trim((string)$_GET['producto']) : null;

if ($productoSeleccionado === '') {
  $productoSeleccionado = null;
}

// Si llega un producto que no pertenece al grupo actual, se limpia
if (
  $productoSeleccionado !== null &&
  !in_array($productoSeleccionado, $grupos[$grupoSolicitado]['productos'], true)
) {
  $productoSeleccionado = null;
}

$modoSolicitado = isset($_GET['modo']) ? trim((string)$_GET['modo']) : 'consumo';

$modosPermitidos = ['consumo', 'costo', 'impacto'];

$tolerancia_pct = 10;

if (!in_array($modoSolicitado, $modosPermitidos, true)) {
  $modoSolicitado = 'consumo';
  $tolerancia_pct = 6;
}

return [
  'titulo' => $grupos[$grupoSolicitado]['titulo'],
  'grupo_actual' => $grupoSolicitado,
  'producto_seleccionado' => $productoSeleccionado,
  'modo' => $modoSolicitado,

  'fecha_desde' => '2025-01-01',
  'campo_fecha_movs' => 'F_MOV',

  'productos' => $grupos[$grupoSolicitado]['productos'],
  'grupos' => $grupos,

  'tolerancia_pct' => $tolerancia_pct,
  'cve_mov' => '17',

  // Campo real de costo
  'campo_costo' => 'COSTO_ENT',
];
