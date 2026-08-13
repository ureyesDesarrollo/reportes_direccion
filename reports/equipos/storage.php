<?php

declare(strict_types=1);

function equipmentDataFile(): string
{
  return __DIR__ . '/data/records.json';
}

function equipmentValidDate(string $date): bool
{
  $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('America/Mexico_City'));
  return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function equipmentLoadRecords(): array
{
  $file = equipmentDataFile();
  if (!is_file($file)) return [];
  $contents = @file_get_contents($file);
  if ($contents === false || trim($contents) === '') return [];
  $payload = json_decode($contents, true);
  return is_array($payload['records'] ?? null) ? $payload['records'] : [];
}

function equipmentLoadData(string $date): array
{
  $record = equipmentLoadRecords()[$date] ?? [];
  return is_array($record) ? $record : [];
}

function equipmentLatestDate(array $records, string $maximumDate): ?string
{
  $dates = array_filter(array_keys($records), static fn($date): bool => is_string($date) && equipmentValidDate($date) && $date <= $maximumDate);
  rsort($dates);
  return isset($dates[0]) ? (string)$dates[0] : null;
}

function equipmentRegisteredDates(array $records): array
{
  $dates = array_filter(array_keys($records), static fn($date): bool => is_string($date) && equipmentValidDate($date));
  rsort($dates);
  return array_values($dates);
}

function equipmentLatestTotals(array $records, string $maximumDate): array
{
  $latestDate = equipmentLatestDate($records, $maximumDate);
  if ($latestDate === null) return [];
  $equipment = $records[$latestDate]['equipos'] ?? [];
  if (!is_array($equipment)) return [];

  $totals = [];
  foreach ($equipment as $key => $item) {
    if (is_array($item) && is_numeric($item['total'] ?? null)) {
      $totals[(string)$key] = (int)$item['total'];
    }
  }
  return $totals;
}

function equipmentSaveData(string $date, array $data): void
{
  if (!equipmentValidDate($date)) throw new InvalidArgumentException('La fecha seleccionada no es válida.');
  $directory = dirname(equipmentDataFile());
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('No fue posible crear el directorio del reporte de equipos.');
  if (!is_writable($directory)) throw new RuntimeException('El directorio del reporte de equipos no tiene permisos de escritura.');
  $lock = @fopen($directory . '/records.lock', 'c');
  if ($lock === false || !@flock($lock, LOCK_EX)) throw new RuntimeException('No fue posible bloquear el registro de equipos.');
  $temporary = null;
  try {
    $records = equipmentLoadRecords();
    $records[$date] = $data;
    ksort($records);
    $json = json_encode(['version' => 1, 'records' => $records], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('No fue posible preparar la información de equipos.');
    $temporary = tempnam($directory, 'equipment_');
    if ($temporary === false || @file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) throw new RuntimeException('No fue posible guardar la información de equipos.');
    @chmod($temporary, 0664);
    if (!@rename($temporary, equipmentDataFile())) throw new RuntimeException('No fue posible publicar la información de equipos.');
  } finally {
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    @flock($lock, LOCK_UN);
    @fclose($lock);
  }
}

function equipmentMergeData(array $base, array $saved): array
{
  foreach ($saved as $key => $value) {
    $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key])
      ? equipmentMergeData($base[$key], $value)
      : $value;
  }
  return $base;
}
