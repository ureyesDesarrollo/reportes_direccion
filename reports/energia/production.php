<?php

declare(strict_types=1);

require_once __DIR__ . '/../../shared/helpers.php';

function energyProductionPeriod(int $year, int $week, DateTimeZone $timezone): array
{
  $start = (new DateTimeImmutable('now', $timezone))->setISODate($year, $week, 1)->setTime(7, 0, 0);
  $end = $start->modify('+7 days');

  return ['inicio' => $start, 'fin' => $end];
}

function energyLoadProductionKg(int $year, int $week, array $databaseConfig, DateTimeZone $timezone): array
{
  $period = energyProductionPeriod($year, $week, $timezone);
  $result = [
    'kg' => null,
    'inicio' => $period['inicio']->format('Y-m-d H:i:s'),
    'fin' => $period['fin']->format('Y-m-d H:i:s'),
    'error' => '',
  ];

  try {
    $pdo = conectar($databaseConfig);
    $statement = $pdo->prepare("
      SELECT COALESCE(SUM(t.tar_kilos), 0)
      FROM rev_tarimas t
      WHERE t.tar_fecha >= ?
        AND t.tar_fecha < ?
        AND COALESCE(t.tar_count_etiquetado, 0) > 0
    ");
    $statement->execute([$result['inicio'], $result['fin']]);
    $value = $statement->fetchColumn();
    $result['kg'] = is_numeric($value) ? (float)$value : null;
  } catch (Throwable $exception) {
    $result['error'] = 'No fue posible consultar los kilogramos producidos para el corte semanal.';
  }

  return $result;
}

