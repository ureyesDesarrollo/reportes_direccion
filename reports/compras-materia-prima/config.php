<?php

declare(strict_types=1);

$productionConfig = require __DIR__ . '/../avance-produccion-hora/config.php';

return [
  'titulo' => 'Compra de Materia Prima',
  'subtitulo' => 'Entradas, movimientos e inventario de cuero',
  'timezone' => 'America/Mexico_City',
  'database' => (array)($productionConfig['database'] ?? []),
  'metricas' => [
    'mp_molienda' => [
      'label' => 'MP para molienda',
      'tipo' => 'mp',
      'bandas' => [
        ['max' => 71.999999, 'estado' => 'rojo', 'plantilla' => '< {max}'],
        ['min' => 72, 'max' => 79.999999, 'estado' => 'amarillo', 'plantilla' => '{min} – < {max}'],
        ['min' => 80, 'max' => 88, 'estado' => 'verde', 'plantilla' => '{min} – {max}'],
        ['min' => 88.000001, 'max' => 96, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 96.000001, 'estado' => 'rojo', 'plantilla' => '> {min}'],
      ],
    ],
    'entrada_granja' => [
      'label' => 'Entrada para la granja',
      'tipo' => 'granja',
      'bandas' => [
        ['max' => 48, 'estado' => 'rojo', 'plantilla' => '≤ {max}'],
        ['min' => 48.000001, 'max' => 52.4, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 52.400001, 'max' => 58, 'estado' => 'verde', 'plantilla' => '> {min} – {max}'],
        ['min' => 58.000001, 'max' => 63, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 63.000001, 'estado' => 'rojo', 'plantilla' => '> {min}'],
      ],
    ],
    'total_entradas' => [
      'label' => 'Total de entradas',
      'tipo' => 'total',
      'bandas' => [
        ['max' => 119.999999, 'estado' => 'rojo', 'plantilla' => '< {max}'],
        ['min' => 120, 'max' => 132.399999, 'estado' => 'amarillo', 'plantilla' => '{min} – < {max}'],
        ['min' => 132.4, 'max' => 146, 'estado' => 'verde', 'plantilla' => '{min} – {max}'],
        ['min' => 146.000001, 'max' => 159, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 159.000001, 'estado' => 'rojo', 'plantilla' => '> {min}'],
      ],
    ],
    'entrega_apelambrado' => [
      'label' => 'Cuero apelambrado a molienda',
      'tipo' => 'maquila',
      'bandas' => [
        ['max' => 57.299999, 'estado' => 'rojo', 'plantilla' => '< {max}'],
        ['min' => 57.3, 'max' => 63.5, 'estado' => 'amarillo', 'plantilla' => '{min} – {max}'],
        ['min' => 63.500001, 'max' => 70, 'estado' => 'verde', 'plantilla' => '> {min} – {max}'],
        ['min' => 70.000001, 'max' => 77, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 77.000001, 'estado' => 'rojo', 'plantilla' => '> {min}'],
      ],
    ],
    'stock_granja' => [
      'label' => 'Producto almacenado en granja',
      'tipo' => 'stock',
      'bandas' => [
        ['max' => 1439.999999, 'estado' => 'rojo', 'plantilla' => '< {max}'],
        ['min' => 1440, 'max' => 1549.999999, 'estado' => 'amarillo', 'plantilla' => '{min} – < {max}'],
        ['min' => 1550, 'max' => 1740, 'estado' => 'verde', 'plantilla' => '{min} – {max}'],
        ['min' => 1740.000001, 'max' => 2310, 'estado' => 'amarillo', 'plantilla' => '> {min} – {max}'],
        ['min' => 2310.000001, 'estado' => 'rojo', 'plantilla' => '> {min}'],
      ],
    ],
  ],
];
