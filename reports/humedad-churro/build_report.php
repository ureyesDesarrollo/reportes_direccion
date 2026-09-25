<?php

declare(strict_types=1);

$config = $config ?? require __DIR__ . '/config.php';
$timezone = new DateTimeZone((string)($config['timezone'] ?? 'Etc/GMT+6'));
$now = new DateTimeImmutable('now', $timezone);
$monthNames = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$secadores = array_values(array_filter(array_map('intval', (array)($config['secadores'] ?? [])), static fn(int $id): bool => $id > 0));
$rule = (array)($config['semaforo'] ?? []);

$periodosValidos = ['dia', 'semana', 'mes'];
$periodo = isset($_GET['periodo']) && in_array((string)$_GET['periodo'], $periodosValidos, true) ? (string)$_GET['periodo'] : 'dia';
$fechaSeleccionada = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fecha'] ?? '')) === 1 ? (string)$_GET['fecha'] : $now->format('Y-m-d');
$semanaSeleccionada = preg_match('/^(\d{4})-W(\d{2})$/', (string)($_GET['semana'] ?? ''), $weekMatch) === 1 ? (string)$_GET['semana'] : $now->format('o-\WW');
$mesSeleccionado = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) === 1 ? (string)$_GET['mes'] : $now->format('Y-m');

if ($periodo === 'semana') {
  preg_match('/^(\d{4})-W(\d{2})$/', $semanaSeleccionada, $weekMatch);
  $inicio = $now->setISODate((int)$weekMatch[1], (int)$weekMatch[2], 1)->setTime(0, 0);
  $fin = $inicio->modify('+1 week');
  $periodoLabel = 'Semana ' . $inicio->format('W') . ' · ' . $inicio->format('d/m/Y') . '–' . $fin->modify('-1 day')->format('d/m/Y');
} elseif ($periodo === 'mes') {
  $inicio = new DateTimeImmutable($mesSeleccionado . '-01 00:00:00', $timezone);
  $fin = $inicio->modify('first day of next month');
  $periodoLabel = ucfirst($monthNames[(int)$inicio->format("n")] ?? "mes") . " " . $inicio->format("Y");
} else {
  $inicio = new DateTimeImmutable($fechaSeleccionada . ' 00:00:00', $timezone);
  $fin = $inicio->modify('+1 day');
  $periodoLabel = $inicio->format('d/m/Y');
}

$emptyCard = static fn(int $secador): array => [
  'secador' => $secador,
  'valor' => null,
  'valor_formateado' => '—',
  'fuera_operacion' => false,
  'estado_key' => 'gris',
  'estado_label' => 'Sin dato',
  'fecha' => '—',
  'lecturas' => 0,
  'promedio' => null,
  'serie' => [],
];

$cards = [];
foreach ($secadores as $secador) $cards[$secador] = $emptyCard($secador);

$evaluate = static function (?float $valor, bool $fueraOperacion) use ($rule): array {
  if ($fueraOperacion) return ['gris', 'Fuera de operación'];
  if ($valor === null) return ['gris', 'Sin dato'];
  $verdeMin = isset($rule["verde_min"]) ? (float)$rule["verde_min"] : null;
  $amarilloMin = isset($rule["amarillo_min"]) ? (float)$rule["amarillo_min"] : null;
  if ($verdeMin !== null && $valor >= $verdeMin) return ["verde", "En objetivo"];
  if ($amarilloMin !== null && $valor >= $amarilloMin) return ["amarillo", "Alerta"];
  return ['rojo', 'Crítico'];
};


$warning = '';
try {
  $db = (array)($config['conexion'] ?? []);
  $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', (string)($db['host'] ?? ''), (int)($db['port'] ?? 3306), (string)($db['dbname'] ?? ''), (string)($db['charset'] ?? 'utf8mb4'));
  $pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => max(1, (int)($db['timeout'] ?? 3)),
  ]);

  $placeholders = implode(',', array_fill(0, count($secadores), '?'));
  $sql = "SELECT id, secador, hum_ultima, estado_fo, creado_en FROM verificacion_secado WHERE secador IN ({$placeholders}) AND creado_en >= ? AND creado_en < ? ORDER BY creado_en ASC, id ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute(array_merge($secadores, [$inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s')]));
  $rowsByDryer = array_fill_keys($secadores, []);
  foreach ($stmt->fetchAll() as $row) {
    $secador = (int)($row['secador'] ?? 0);
    if (isset($rowsByDryer[$secador])) $rowsByDryer[$secador][] = $row;
  }

  foreach ($rowsByDryer as $secador => $rows) {
    if ($rows === []) continue;
    $last = $rows[count($rows) - 1];
    $fueraOperacion = ((int)($last['estado_fo'] ?? 0)) === 1;
    $valor = is_numeric($last['hum_ultima'] ?? null) ? (float)$last['hum_ultima'] : null;
    [$estadoKey, $estadoLabel] = $evaluate($valor, $fueraOperacion);
    $lastDate = new DateTimeImmutable((string)$last['creado_en'], $timezone);

    $validValues = [];
    $series = [];
    if ($periodo === 'dia') {
      foreach ($rows as $row) {
        if (((int)($row["estado_fo"] ?? 0)) === 1 || !is_numeric($row["hum_ultima"] ?? null) || (float)$row["hum_ultima"] < 0) continue;
        $pointDate = new DateTimeImmutable((string)$row['creado_en'], $timezone);
        $pointValue = (float)$row['hum_ultima'];
        $validValues[] = $pointValue;
        $series[] = ['label' => $pointDate->format('H:i'), 'valor' => $pointValue];
      }
    } else {
      $buckets = [];
      foreach ($rows as $row) {
        if (((int)($row["estado_fo"] ?? 0)) === 1 || !is_numeric($row["hum_ultima"] ?? null) || (float)$row["hum_ultima"] < 0) continue;
        $pointDate = new DateTimeImmutable((string)$row['creado_en'], $timezone);
        $bucket = $pointDate->format('Y-m-d');
        $buckets[$bucket][] = (float)$row['hum_ultima'];
        $validValues[] = (float)$row['hum_ultima'];
      }
      foreach ($buckets as $bucket => $values) {
        $series[] = ['label' => (new DateTimeImmutable($bucket, $timezone))->format('d/m'), 'valor' => array_sum($values) / count($values)];
      }
    }

    $cards[$secador] = [
      'secador' => $secador,
      'valor' => $valor,
      'valor_formateado' => $fueraOperacion ? 'FO' : ($valor !== null ? number_format($valor, 2, '.', ',') : '—'),
      'fuera_operacion' => $fueraOperacion,
      'estado_key' => $estadoKey,
      'estado_label' => $estadoLabel,
      'fecha' => $lastDate->format('d/m/Y H:i'),
      'lecturas' => count($validValues),
      'promedio' => $validValues !== [] ? array_sum($validValues) / count($validValues) : null,
      'serie' => $series,
    ];
  }
} catch (Throwable $exception) {
  error_log('[humedad-churro] ' . $exception->getMessage());
  $warning = 'No fue posible consultar la verificación de secado en este momento.';
}

return [
  'titulo' => (string)($config['titulo'] ?? 'Humedad Churro'),
  'subtitulo' => (string)($config['subtitulo'] ?? ''),
  'tarjetas' => array_values($cards),
  'semaforo' => $rule,
  'warning' => $warning,
  'periodo' => $periodo,
  'periodo_label' => $periodoLabel,
  'fecha_seleccionada' => $fechaSeleccionada,
  'semana_seleccionada' => $semanaSeleccionada,
  'mes_seleccionado' => $mesSeleccionado,
  'intervalo_actualizacion_ms' => max(10000, (int)($config['intervalo_actualizacion_ms'] ?? 120000)),
];
