<?php

declare(strict_types=1);

function rhDataFile(): string
{
  return __DIR__ . '/data/weekly.json';
}

function rhWeekKey(int $year, int $week): string
{
  return sprintf('%04d-W%02d', $year, $week);
}

function rhValidWeek(int $year, int $week): bool
{
  if ($year < 2020 || $year > 2100 || $week < 1 || $week > 53) {
    return false;
  }
  $date = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
  $date = $date->setISODate($year, $week, 1);
  return (int)$date->format('o') === $year && (int)$date->format('W') === $week;
}

function rhWeekRange(int $year, int $week): array
{
  $monday = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))
    ->setISODate($year, $week, 1)
    ->setTime(0, 0);
  return ['inicio' => $monday, 'fin' => $monday->modify('+6 days')];
}

function rhWeekRangeLabel(int $year, int $week): string
{
  $range = rhWeekRange($year, $week);
  $start = $range['inicio'];
  $end = $range['fin'];
  $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  if ($start->format('Y-m') === $end->format('Y-m')) {
    return $start->format('d') . ' al ' . $end->format('d') . ' de ' . $months[(int)$end->format('n')] . ' de ' . $end->format('Y');
  }
  return $start->format('d') . ' de ' . $months[(int)$start->format('n')]
    . ' al ' . $end->format('d') . ' de ' . $months[(int)$end->format('n')] . ' de ' . $end->format('Y');
}

function rhRegisteredWeeks(array $records): array
{
  $weeks = [];
  foreach ($records as $key => $record) {
    if (!is_array($record) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$key, $matches) !== 1) {
      continue;
    }
    $year = (int)$matches[1];
    $week = (int)$matches[2];
    if (!rhValidWeek($year, $week)) {
      continue;
    }
    $weeks[] = ['key' => (string)$key, 'anio' => $year, 'semana' => $week];
  }
  usort($weeks, static fn(array $a, array $b): int => strcmp($b['key'], $a['key']));
  return $weeks;
}

function rhLoadWeeklyRecords(): array
{
  $file = rhDataFile();
  if (!is_file($file)) {
    return [];
  }

  $contents = @file_get_contents($file);
  if ($contents === false || trim($contents) === '') {
    return [];
  }

  $data = json_decode($contents, true);
  return is_array($data['records'] ?? null) ? $data['records'] : [];
}

function rhLoadData(int $year, int $week): array
{
  $records = rhLoadWeeklyRecords();
  $record = $records[rhWeekKey($year, $week)] ?? [];
  return is_array($record) ? $record : [];
}

function rhSaveData(int $year, int $week, array $data): void
{
  if (!rhValidWeek($year, $week)) {
    throw new InvalidArgumentException('La semana seleccionada no es válida.');
  }
  $directory = dirname(rhDataFile());
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
    throw new RuntimeException('No fue posible crear el directorio de datos de RH.');
  }
  if (!is_writable($directory)) {
    throw new RuntimeException('El directorio de datos de RH no tiene permisos de escritura.');
  }

  $lock = @fopen($directory . '/weekly.lock', 'c');
  if ($lock === false || !@flock($lock, LOCK_EX)) {
    if (is_resource($lock)) {
      @fclose($lock);
    }
    throw new RuntimeException('No fue posible bloquear el registro semanal de RH.');
  }
  $temporary = null;
  try {
    // Año-semana es la llave única: un nuevo guardado actualiza el mismo registro.
    $records = rhLoadWeeklyRecords();
    $records[rhWeekKey($year, $week)] = $data;
    ksort($records);
    $payload = ['version' => 1, 'records' => $records];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
      throw new RuntimeException('No fue posible preparar la información de RH.');
    }

    $temporary = tempnam($directory, 'rh_');
    if ($temporary === false) {
      throw new RuntimeException('No fue posible crear el archivo temporal de RH.');
    }
    if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
      throw new RuntimeException('No fue posible guardar la información de RH.');
    }
    @chmod($temporary, 0664);
    if (!@rename($temporary, rhDataFile())) {
      throw new RuntimeException('No fue posible publicar la información de RH.');
    }
  } finally {
    if (is_string($temporary) && is_file($temporary)) {
      @unlink($temporary);
    }
    @flock($lock, LOCK_UN);
    @fclose($lock);
  }
}

function rhMergeData(array $base, array $saved): array
{
  foreach ($saved as $key => $value) {
    if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
      $base[$key] = rhMergeData($base[$key], $value);
    } else {
      $base[$key] = $value;
    }
  }
  return $base;
}
