<?php

return [
  'movs' => [
    'host' => '192.168.1.104:3306',
    'dbname' => 'saipbi',
    'user' => 'saipbi',
    'pass' => '4U4TIM2v3Oo1',
    'charset' => 'utf8mb4',
  ],
  'prod' => [
    'host' => getenv('PROD_DB_HOST') ?: 'sis_preparacion-db-1',
    'port' => (int)(getenv('PROD_DB_PORT') ?: 3306),
    'dbname' => getenv('PROD_DB_NAME') ?: 'bd_sis_preparacion',
    'user' => getenv('PROD_DB_USER') ?: 'root',
    'pass' => getenv('PROD_DB_PASS') ?: 'root',
    'charset' => getenv('PROD_DB_CHARSET') ?: 'utf8mb4',
  ],
  'hoshin' => [
    'host' => getenv('HOSHIN_DB_HOST') ?: 'sis_preparacion-db-1',
    'port' => (int)(getenv('HOSHIN_DB_PORT') ?: 3306),
    'dbname' => getenv('HOSHIN_DB_NAME') ?: 'hoshin_proyectos_test',
    'user' => getenv('HOSHIN_DB_USER') ?: 'root',
    'pass' => getenv('HOSHIN_DB_PASS') ?: 'root',
    'charset' => getenv('HOSHIN_DB_CHARSET') ?: 'utf8mb4',
  ],
  'hoshin_kanri' => [
    'host' => getenv('HOSHIN_KANRI_DB_HOST') ?: (getenv('HOSHIN_DB_HOST') ?: 'sis_preparacion-db-1'),
    'port' => (int)(getenv('HOSHIN_KANRI_DB_PORT') ?: (getenv('HOSHIN_DB_PORT') ?: 3306)),
    'dbname' => getenv('HOSHIN_KANRI_DB_NAME') ?: (getenv('HOSHIN_DB_NAME') ?: 'hoshin_kanri'),
    'user' => getenv('HOSHIN_KANRI_DB_USER') ?: (getenv('HOSHIN_DB_USER') ?: 'root'),
    'pass' => getenv('HOSHIN_KANRI_DB_PASS') ?: (getenv('HOSHIN_DB_PASS') ?: 'root'),
    'charset' => getenv('HOSHIN_KANRI_DB_CHARSET') ?: (getenv('HOSHIN_DB_CHARSET') ?: 'utf8mb4'),
  ],
];
