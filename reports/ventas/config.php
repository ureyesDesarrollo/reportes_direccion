<?php

declare(strict_types=1);

return [
  'titulo' => 'Ventas',
  'timezone' => 'America/Mexico_City',
  'intervalo_actualizacion_ms' => 600000,

  'semaforos_ventas' => [
    'industrial' => [
      'amarillo_desde' => 500,
      'verde_desde' => 530,
      'verde_inclusivo' => false,
    ],
    'comercial' => [
      'amarillo_desde' => 55,
      'verde_desde' => 70,
      'verde_inclusivo' => false,
    ],
    'total' => [
      'amarillo_desde' => 555,
      'verde_desde' => 600,
      'verde_inclusivo' => true,
    ],
    'precio_promedio' => [
      'amarillo_desde' => 110,
      'verde_arriba_de' => 112,
    ],
  ],

  'semaforo_backorder' => [
    'amarillo_desde' => 420,
    'verde_arriba_de' => 450,
    'distribuir_segun_objetivo_ventas' => true,
  ],

  'pedidos_api' => [
    'url' => 'http://192.168.1.104:5000/api/pedidos',
    'detalle_url' => 'http://192.168.1.104:5000/api/pedidos/detalle',
    'api_key' => 'SAI-REQ-2026-MI-CLAVE-SEGURA',
    'status' => 'Por Surtir,Parcial',
    'status2' => 'Confirmado',
    'timeout' => 8,
  ],

  'productos_calidad' => [
    ['clave' => 'GRE250B', 'grupo' => '250', 'orden' => 250, 'descripcion' => 'GRENETINA ALIMENTICIA 250 BLOOM SACOS'],
    ['clave' => 'GRE265B', 'grupo' => '265', 'orden' => 265, 'descripcion' => 'GRENETINA ALIMENTICIA 265 BLOOM SACOS'],
    ['clave' => 'GRE280B', 'grupo' => '280', 'orden' => 280, 'descripcion' => 'GRENETINA ALIMENTICIA 280 BLOOM SACOS'],
    ['clave' => 'GRE300B', 'grupo' => '300', 'orden' => 300, 'descripcion' => 'GRENETINA ALIMENTICIA 300 BLOOM SACOS'],
    ['clave' => 'GRE315B', 'grupo' => '315', 'orden' => 315, 'descripcion' => 'GRENETINA ALIMENTICIA 315 BLOOM SACOS'],
    ['clave' => 'GRE230B', 'grupo' => '230', 'orden' => 230, 'descripcion' => 'GRENETINA ALIMENTICIA 230 BLOOM GRANEL'],
    ['clave' => 'GRE230C', 'grupo' => '230', 'orden' => 230, 'descripcion' => 'GRENETINA ALIMENTICIA 230 BLOOM CAJA'],
    ['clave' => 'GRE265C', 'grupo' => '265', 'orden' => 265, 'descripcion' => 'GRENETINA ALIMENTICIA 265 BLOOM CAJA'],
    ['clave' => 'GRE300C', 'grupo' => '300', 'orden' => 300, 'descripcion' => 'GRENETINA ALIMENTICIA 300 BLOOM CAJA'],
    ['clave' => 'GRE300CL', 'grupo' => '300', 'orden' => 300, 'descripcion' => 'GRENETINA ALIMENTICIA 300 BLOOM SACOS DE 25 KG', 'presentacion_kg' => 25],
    ['clave' => 'GRE315C', 'grupo' => '315', 'orden' => 315, 'descripcion' => 'GRENETINA ALIMENTICIA 315 BLOOM CAJA'],
    ['clave' => 'GRE3151/4', 'grupo' => '315', 'orden' => 315, 'descripcion' => 'GRENETINA ALIMENTICIA 315 BLOOM CAJA 1/4'],
    ['clave' => 'GREECO', 'grupo' => 'Económica', 'orden' => 229, 'descripcion' => 'GRENETINA ALIMENTICIA ECONOMICA'],
    ['clave' => 'GRE300BL', 'grupo' => '300', 'orden' => 300, 'descripcion' => 'GRENETINA ALIMENTICIA DORADA'],
    ['clave' => 'GRE315BL', 'grupo' => '315', 'orden' => 315, 'descripcion' => 'GRENETINA ALIMENTICIA 315 BLOOM'],
  ],

  // Relación de la calidad física del producto empacado con las cards de Bloom.
  'inventario_calidad_bloom' => [
    '250' => '250',
    '280' => '280',
    'AZUL' => '315',
    'DORADA' => '300',
    'VERDE' => '265',
    'MORADA' => '230',
  ],
  'inventario_cliente_bloom' => [
    251 => '300',
  ],

  'mysql_105' => [
    'host' => '192.168.1.105',
    'port' => 3306,
    'dbname' => 'bd_sis_preparacion',
    'user' => 'user_pro',
    'pass' => 'Pr0g3l2025PR',
    'charset' => 'utf8mb4',
  ],

  'tablas' => [
    'facturas' => 'facturas_sai',
    'factura_detalle' => 'factura_sai_detalle',
    'remisiones' => 'remisiones',
    'remision_detalle' => 'remision_detalle',
    'notas_credito' => 'notas_credito',
  ],
];
