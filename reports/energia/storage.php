<?php

declare(strict_types=1);

function energyDatabaseFile(): string
{
  return __DIR__ . '/data/energia.sqlite';
}

function energyLegacyDataFile(): string
{
  return __DIR__ . '/data/weekly.json';
}

function energyOpenDatabase(): PDO
{
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $directory = dirname(energyDatabaseFile());
  if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('No fue posible crear el directorio de Energía.');
  if (!is_writable($directory)) throw new RuntimeException('El directorio de Energía no tiene permisos de escritura.');
  if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('El soporte de SQLite no está disponible en PHP.');

  $pdo = new PDO('sqlite:' . energyDatabaseFile(), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA busy_timeout = 5000');
  $pdo->exec('PRAGMA foreign_keys = ON');
  $pdo->exec('PRAGMA journal_mode = WAL');
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energy_records (
      period_key TEXT PRIMARY KEY,
      iso_year INTEGER NOT NULL,
      iso_week INTEGER NOT NULL,
      payload TEXT NOT NULL,
      registered_at TEXT NULL,
      updated_at TEXT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (iso_year, iso_week)
    )
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energy_meta (
      meta_key TEXT PRIMARY KEY,
      meta_value TEXT NOT NULL,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
  ");
  energyImportLegacyJson($pdo);
  return $pdo;
}

function energyImportLegacyJson(PDO $pdo): void
{
  $check = $pdo->prepare('SELECT meta_value FROM energy_meta WHERE meta_key = ?');
  $check->execute(['legacy_weekly_json_imported']);
  if ($check->fetchColumn() !== false) return;

  $legacyRecords = [];
  $file = energyLegacyDataFile();
  if (is_file($file)) {
    $contents = @file_get_contents($file);
    if ($contents !== false && trim($contents) !== '') {
      $payload = json_decode($contents, true);
      if (!is_array($payload)) throw new RuntimeException('El archivo histórico de Energía contiene JSON inválido.');
      $legacyRecords = is_array($payload['records'] ?? null) ? $payload['records'] : [];
    }
  }

  $pdo->beginTransaction();
  try {
    $insert = $pdo->prepare("
      INSERT OR IGNORE INTO energy_records
        (period_key, iso_year, iso_week, payload, registered_at, updated_at, modified_at)
      VALUES
        (:period_key, :iso_year, :iso_week, :payload, :registered_at, :updated_at, CURRENT_TIMESTAMP)
    ");
    foreach ($legacyRecords as $key => $record) {
      if (!is_array($record) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$key, $matches) !== 1) continue;
      $year = (int)$matches[1];
      $week = (int)$matches[2];
      if (!energyValidWeek($year, $week)) continue;
      $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
      $insert->execute([
        ':period_key' => (string)$key,
        ':iso_year' => $year,
        ':iso_week' => $week,
        ':payload' => $json,
        ':registered_at' => trim((string)($record['registrado_en'] ?? '')) ?: null,
        ':updated_at' => trim((string)($record['actualizado_en'] ?? '')) ?: null,
      ]);
    }
    $meta = $pdo->prepare('INSERT INTO energy_meta (meta_key, meta_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
    $meta->execute(['legacy_weekly_json_imported', (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function energyWeekKey(int $year, int $week): string
{
  return sprintf('%04d-W%02d', $year, $week);
}

function energyValidWeek(int $year, int $week): bool
{
  if ($year < 2020 || $year > 2100 || $week < 1 || $week > 53) return false;
  $date = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->setISODate($year, $week, 1);
  return (int)$date->format('o') === $year && (int)$date->format('W') === $week;
}

function energyWeekRangeLabel(int $year, int $week): string
{
  $start = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->setISODate($year, $week, 1);
  $end = $start->modify('+6 days');
  $months = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  if ($start->format('Y-m') === $end->format('Y-m')) return $start->format('d') . ' al ' . $end->format('d') . ' de ' . $months[(int)$end->format('n')] . ' de ' . $end->format('Y');
  return $start->format('d') . ' de ' . $months[(int)$start->format('n')] . ' al ' . $end->format('d') . ' de ' . $months[(int)$end->format('n')] . ' de ' . $end->format('Y');
}

function energyLoadRecords(): array
{
  $statement = energyOpenDatabase()->query('SELECT period_key, payload FROM energy_records ORDER BY period_key');
  $records = [];
  foreach ($statement as $row) {
    $record = json_decode((string)($row['payload'] ?? ''), true);
    if (is_array($record)) $records[(string)$row['period_key']] = $record;
  }
  return $records;
}

function energyLoadData(int $year, int $week): array
{
  if (!energyValidWeek($year, $week)) return [];
  $statement = energyOpenDatabase()->prepare('SELECT payload FROM energy_records WHERE period_key = ? LIMIT 1');
  $statement->execute([energyWeekKey($year, $week)]);
  $payload = $statement->fetchColumn();
  if (!is_string($payload) || $payload === '') return [];
  $record = json_decode($payload, true);
  return is_array($record) ? $record : [];
}

function energyRegisteredWeeks(array $records): array
{
  $weeks = [];
  foreach ($records as $key => $record) {
    if (!is_array($record) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$key, $matches) !== 1) continue;
    $year = (int)$matches[1];
    $week = (int)$matches[2];
    if (energyValidWeek($year, $week)) $weeks[] = ['key' => (string)$key, 'anio' => $year, 'semana' => $week];
  }
  usort($weeks, static fn(array $a, array $b): int => strcmp($b['key'], $a['key']));
  return $weeks;
}

function energySaveData(int $year, int $week, array $data): void
{
  if (!energyValidWeek($year, $week)) throw new InvalidArgumentException('La semana seleccionada no es válida.');
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $pdo = energyOpenDatabase();
  $pdo->beginTransaction();
  try {
    $statement = $pdo->prepare("
      INSERT INTO energy_records
        (period_key, iso_year, iso_week, payload, registered_at, updated_at, modified_at)
      VALUES
        (:period_key, :iso_year, :iso_week, :payload, :registered_at, :updated_at, CURRENT_TIMESTAMP)
      ON CONFLICT(period_key) DO UPDATE SET
        payload = excluded.payload,
        registered_at = COALESCE(energy_records.registered_at, excluded.registered_at),
        updated_at = excluded.updated_at,
        modified_at = CURRENT_TIMESTAMP
    ");
    $statement->execute([
      ':period_key' => energyWeekKey($year, $week),
      ':iso_year' => $year,
      ':iso_week' => $week,
      ':payload' => $json,
      ':registered_at' => trim((string)($data['registrado_en'] ?? '')) ?: null,
      ':updated_at' => trim((string)($data['actualizado_en'] ?? '')) ?: null,
    ]);
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function energyMergeData(array $base, array $saved): array
{
  foreach ($saved as $key => $value) {
    $base[$key] = is_array($value) && isset($base[$key]) && is_array($base[$key]) ? energyMergeData($base[$key], $value) : $value;
  }
  return $base;
}
