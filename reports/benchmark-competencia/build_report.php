<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$appConfig = $appConfig ?? require __DIR__ . '/../../config/app.php';
$dbConfig = $dbConfig ?? require __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../shared/helpers.php';

$timezone = (string)($config['timezone'] ?? 'America/Mazatlan');
date_default_timezone_set($timezone);
$mesesLimite = max(1, (int)($config['meses'] ?? 3));
$tendenciaMesesLimite = max(2, (int)($config['tendencia_meses'] ?? 12));
$condicionesComportamiento = (array)($config['condiciones_comportamiento'] ?? []);
$aspectosComportamiento = (array)($config['aspectos_comportamiento'] ?? []);

$pdo = conectar($dbConfig['prod']);

$stmtMeses = $pdo->prepare("
  SELECT
    DATE_FORMAT(fecha, '%Y-%m') AS mes_key,
    MAX(fecha) AS fecha_referencia,
    COUNT(*) AS reportes
  FROM lab_reportes
  GROUP BY mes_key
  ORDER BY mes_key DESC
  LIMIT {$mesesLimite}
");
$stmtMeses->execute();
$meses = array_reverse($stmtMeses->fetchAll());

if (empty($meses)) {
  return [
    'titulo' => (string)($config['titulo'] ?? 'PULSO DE MERCADO / Competencia'),
    'meses' => [],
    'marcas' => [],
    'gruposBloom' => [],
    'filas' => [],
    'tendencias' => ['labels' => [], 'series' => []],
    'kpis' => ['marcas' => 0, 'blooms' => 0, 'parametros' => 0, 'reportes' => 0, 'alertas' => 0],
    'meta' => [
      'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? $appConfig['intervalo_actualizacion'] ?? 300000),
    ],
    'version' => time(),
  ];
}

$monthName = static function (string $monthKey): string {
  $date = DateTimeImmutable::createFromFormat('!Y-m', $monthKey);
  if (!$date instanceof DateTimeImmutable) {
    return $monthKey;
  }

  $names = [
    '01' => 'Ene',
    '02' => 'Feb',
    '03' => 'Mar',
    '04' => 'Abr',
    '05' => 'May',
    '06' => 'Jun',
    '07' => 'Jul',
    '08' => 'Ago',
    '09' => 'Sep',
    '10' => 'Oct',
    '11' => 'Nov',
    '12' => 'Dic',
  ];
  $month = $date->format('m');

  return ($names[$month] ?? $month) . ' ' . $date->format('Y');
};

$mesKeys = array_map(static fn(array $row): string => (string)$row['mes_key'], $meses);
$mesesResumen = array_map(static function (array $row) use ($monthName): array {
  return [
    'key' => (string)$row['mes_key'],
    'label' => $monthName((string)$row['mes_key']),
    'fecha_referencia' => (string)($row['fecha_referencia'] ?? ''),
    'reportes' => (int)($row['reportes'] ?? 0),
  ];
}, $meses);

$placeholdersMeses = implode(',', array_fill(0, count($mesKeys), '?'));

$stmtMarcas = $pdo->prepare("
  SELECT
    m.marca_id,
    m.nombre,
    m.bloom,
    MIN(rm.orden) AS orden
  FROM lab_reportes r
  INNER JOIN lab_reporte_muestras rm ON rm.reporte_id = r.reporte_id
  INNER JOIN lab_marcas m ON m.marca_id = rm.marca_id
  WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersMeses})
    AND m.activo = 1
  GROUP BY m.marca_id, m.nombre, m.bloom
  ORDER BY m.bloom IS NULL ASC, m.bloom DESC, orden ASC, m.nombre ASC
");
$stmtMarcas->execute($mesKeys);
$marcas = array_map(static function (array $row): array {
  $bloom = $row['bloom'] !== null ? (int)$row['bloom'] : null;

  return [
    'id' => (int)$row['marca_id'],
    'nombre' => (string)$row['nombre'],
    'bloom' => $bloom,
    'bloom_key' => $bloom !== null ? (string)$bloom : 'sin-bloom',
    'bloom_label' => $bloom !== null ? 'Bloom ' . $bloom : 'Sin bloom',
  ];
}, $stmtMarcas->fetchAll());
$marcaIds = array_column($marcas, 'id');

$gruposBloom = [];
foreach ($marcas as $marca) {
  $bloomKey = (string)$marca['bloom_key'];
  if (!isset($gruposBloom[$bloomKey])) {
    $gruposBloom[$bloomKey] = [
      'key' => $bloomKey,
      'label' => (string)$marca['bloom_label'],
      'bloom' => $marca['bloom'],
      'marcas' => [],
    ];
  }

  $gruposBloom[$bloomKey]['marcas'][] = $marca;
}

$stmtParametros = $pdo->prepare("
  SELECT DISTINCT
    p.parametro_id,
    p.clave,
    p.nombre,
    p.unidad,
    COALESCE(l.limite_texto, p.limite_texto_default) AS limite_texto,
    COALESCE(l.valor_min, p.valor_min_default) AS valor_min,
    COALESCE(l.valor_max, p.valor_max_default) AS valor_max,
    p.decimales,
    p.orden
  FROM lab_parametros p
  INNER JOIN lab_resultados res ON res.parametro_id = p.parametro_id
  INNER JOIN lab_reporte_muestras rm ON rm.reporte_muestra_id = res.reporte_muestra_id
  INNER JOIN lab_reportes r ON r.reporte_id = rm.reporte_id
  LEFT JOIN lab_reporte_limites l ON l.reporte_id = r.reporte_id AND l.parametro_id = p.parametro_id
  WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersMeses})
    AND p.activo = 1
  GROUP BY p.parametro_id, p.clave, p.nombre, p.unidad, limite_texto, valor_min, valor_max, p.decimales, p.orden
  ORDER BY p.orden ASC, p.parametro_id ASC
");
$stmtParametros->execute($mesKeys);
$parametros = $stmtParametros->fetchAll();

$filas = [];
foreach ($parametros as $parametro) {
  $unidad = trim((string)($parametro['unidad'] ?? ''));
  $nombre = (string)$parametro['nombre'];
  if ($unidad !== '') {
    $nombre .= ' (' . $unidad . ')';
  }

  $filas['param:' . (int)$parametro['parametro_id']] = [
    'key' => 'param:' . (int)$parametro['parametro_id'],
    'tipo' => 'parametro',
    'id' => (int)$parametro['parametro_id'],
    'nombre' => $nombre,
    'limite' => (string)($parametro['limite_texto'] ?? ''),
    'decimales' => (int)($parametro['decimales'] ?? 2),
    'valores' => [],
  ];
}

$stmtComportamientoTipos = $pdo->prepare("
  SELECT DISTINCT c.condicion, c.aspecto
  FROM lab_comportamiento c
  INNER JOIN lab_reporte_muestras rm ON rm.reporte_muestra_id = c.reporte_muestra_id
  INNER JOIN lab_reportes r ON r.reporte_id = rm.reporte_id
  WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersMeses})
  ORDER BY FIELD(c.condicion, 'FUERA', 'DENTRO'), FIELD(c.aspecto, 'COLOR', 'SABOR')
");
$stmtComportamientoTipos->execute($mesKeys);
$comportamientoTipos = $stmtComportamientoTipos->fetchAll();

foreach ($comportamientoTipos as $tipo) {
  $condicion = (string)$tipo['condicion'];
  $aspecto = (string)$tipo['aspecto'];
  $condicionLabel = (string)($condicionesComportamiento[$condicion] ?? $condicion);
  $aspectoLabel = (string)($aspectosComportamiento[$aspecto] ?? ucfirst(strtolower($aspecto)));
  $key = 'comp:' . $condicion . ':' . $aspecto;

  $filas[$key] = [
    'key' => $key,
    'tipo' => 'comportamiento',
    'condicion' => $condicion,
    'aspecto' => $aspecto,
    'nombre' => $aspectoLabel . ' - ' . $condicionLabel,
    'limite' => 'Comportamiento',
    'decimales' => 0,
    'valores' => [],
  ];
}

$alertas = 0;

$stmtResultados = $pdo->prepare("
  SELECT
    DATE_FORMAT(r.fecha, '%Y-%m') AS mes_key,
    rm.marca_id,
    res.parametro_id,
    AVG(res.valor) AS valor,
    MAX(res.fuera_limite) AS fuera_limite,
    COUNT(*) AS muestras
  FROM lab_resultados res
  INNER JOIN lab_reporte_muestras rm ON rm.reporte_muestra_id = res.reporte_muestra_id
  INNER JOIN lab_reportes r ON r.reporte_id = rm.reporte_id
  WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersMeses})
    AND rm.marca_id IS NOT NULL
  GROUP BY mes_key, rm.marca_id, res.parametro_id
");
$stmtResultados->execute($mesKeys);
foreach ($stmtResultados->fetchAll() as $row) {
  $rowKey = 'param:' . (int)$row['parametro_id'];
  if (!isset($filas[$rowKey])) {
    continue;
  }

  $marcaId = (int)$row['marca_id'];
  $mesKey = (string)$row['mes_key'];
  $fuera = (int)($row['fuera_limite'] ?? 0) === 1;
  if ($fuera) {
    $alertas++;
  }

  $filas[$rowKey]['valores'][$marcaId][$mesKey] = [
    'valor' => (float)$row['valor'],
    'estado_key' => $fuera ? 'fuera' : 'dentro',
    'estado' => $fuera ? 'Fuera' : 'En rango',
    'muestras' => (int)($row['muestras'] ?? 0),
  ];
}

$stmtComportamiento = $pdo->prepare("
  SELECT
    DATE_FORMAT(r.fecha, '%Y-%m') AS mes_key,
    rm.marca_id,
    c.condicion,
    c.aspecto,
    AVG(c.valor) AS valor,
    COUNT(*) AS muestras
  FROM lab_comportamiento c
  INNER JOIN lab_reporte_muestras rm ON rm.reporte_muestra_id = c.reporte_muestra_id
  INNER JOIN lab_reportes r ON r.reporte_id = rm.reporte_id
  WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersMeses})
    AND rm.marca_id IS NOT NULL
  GROUP BY mes_key, rm.marca_id, c.condicion, c.aspecto
");
$stmtComportamiento->execute($mesKeys);
foreach ($stmtComportamiento->fetchAll() as $row) {
  $rowKey = 'comp:' . (string)$row['condicion'] . ':' . (string)$row['aspecto'];
  if (!isset($filas[$rowKey])) {
    continue;
  }

  $filas[$rowKey]['valores'][(int)$row['marca_id']][(string)$row['mes_key']] = [
    'valor' => (float)$row['valor'],
    'estado_key' => 'neutral',
    'estado' => 'Dato',
    'muestras' => (int)($row['muestras'] ?? 0),
  ];
}

$stmtTendenciaMeses = $pdo->prepare("
  SELECT
    DATE_FORMAT(fecha, '%Y-%m') AS mes_key,
    MAX(fecha) AS fecha_referencia
  FROM lab_reportes
  GROUP BY mes_key
  ORDER BY mes_key DESC
  LIMIT {$tendenciaMesesLimite}
");
$stmtTendenciaMeses->execute();
$tendenciaMeses = array_reverse($stmtTendenciaMeses->fetchAll());
$tendenciaMesKeys = array_map(static fn(array $row): string => (string)$row['mes_key'], $tendenciaMeses);

$tendenciasSeries = [];
foreach ($marcas as $marca) {
  $tendenciasSeries[(int)$marca['id']] = [
    'marca_id' => (int)$marca['id'],
    'nombre' => (string)$marca['nombre'],
    'bloom' => array_fill_keys($tendenciaMesKeys, null),
    'viscosidad' => array_fill_keys($tendenciaMesKeys, null),
  ];
}

if (!empty($tendenciaMesKeys) && !empty($marcaIds)) {
  $placeholdersTendenciaMeses = implode(',', array_fill(0, count($tendenciaMesKeys), '?'));
  $placeholdersMarcas = implode(',', array_fill(0, count($marcaIds), '?'));
  $stmtTendencias = $pdo->prepare("
    SELECT
      DATE_FORMAT(r.fecha, '%Y-%m') AS mes_key,
      rm.marca_id,
      p.clave,
      AVG(res.valor) AS valor
    FROM lab_resultados res
    INNER JOIN lab_parametros p ON p.parametro_id = res.parametro_id
    INNER JOIN lab_reporte_muestras rm ON rm.reporte_muestra_id = res.reporte_muestra_id
    INNER JOIN lab_reportes r ON r.reporte_id = rm.reporte_id
    WHERE DATE_FORMAT(r.fecha, '%Y-%m') IN ({$placeholdersTendenciaMeses})
      AND rm.marca_id IN ({$placeholdersMarcas})
      AND p.clave IN ('bloom', 'viscosidad')
    GROUP BY mes_key, rm.marca_id, p.clave
    ORDER BY mes_key ASC
  ");
  $stmtTendencias->execute(array_merge($tendenciaMesKeys, $marcaIds));
  foreach ($stmtTendencias->fetchAll() as $row) {
    $marcaId = (int)$row['marca_id'];
    $mesKey = (string)$row['mes_key'];
    $clave = (string)$row['clave'];
    if (!isset($tendenciasSeries[$marcaId], $tendenciasSeries[$marcaId][$clave])) {
      continue;
    }

    $tendenciasSeries[$marcaId][$clave][$mesKey] = isset($row['valor']) ? (float)$row['valor'] : null;
  }
}

$tendencias = [
  'labels' => array_map(static function (array $row) use ($monthName): string {
    return $monthName((string)$row['mes_key']);
  }, $tendenciaMeses),
  'meses' => array_map(static function (array $row) use ($monthName): array {
    return [
      'key' => (string)$row['mes_key'],
      'label' => $monthName((string)$row['mes_key']),
      'fecha_referencia' => (string)($row['fecha_referencia'] ?? ''),
    ];
  }, $tendenciaMeses),
  'series' => array_values(array_map(static function (array $serie) use ($tendenciaMesKeys): array {
    return [
      'marca_id' => (int)$serie['marca_id'],
      'nombre' => (string)$serie['nombre'],
      'bloom' => array_values(array_map(
        static fn(string $mesKey): ?float => $serie['bloom'][$mesKey],
        $tendenciaMesKeys
      )),
      'viscosidad' => array_values(array_map(
        static fn(string $mesKey): ?float => $serie['viscosidad'][$mesKey],
        $tendenciaMesKeys
      )),
    ];
  }, $tendenciasSeries)),
];

$totalReportes = array_sum(array_map(static fn(array $row): int => (int)$row['reportes'], $mesesResumen));

return [
  'titulo' => (string)($config['titulo'] ?? 'PULSO DE MERCADO / Competencia'),
  'meses' => $mesesResumen,
  'marcas' => $marcas,
  'gruposBloom' => array_values($gruposBloom),
  'filas' => array_values($filas),
  'tendencias' => $tendencias,
  'kpis' => [
    'marcas' => count($marcas),
    'blooms' => count($gruposBloom),
    'parametros' => count($filas),
    'reportes' => $totalReportes,
    'alertas' => $alertas,
  ],
  'meta' => [
    'meses' => $mesesLimite,
    'tendenciaMeses' => $tendenciaMesesLimite,
    'intervaloActualizacion' => (int)($config['intervalo_actualizacion_ms'] ?? $appConfig['intervalo_actualizacion'] ?? 300000),
  ],
  'version' => max(
    @filemtime(__FILE__) ?: time(),
    @filemtime(__DIR__ . '/config.php') ?: time(),
    @filemtime(__DIR__ . '/index.php') ?: time()
  ),
];
