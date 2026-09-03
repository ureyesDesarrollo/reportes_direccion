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
    CREATE TABLE IF NOT EXISTS energy_monthly_records (
      period_key TEXT PRIMARY KEY,
      year INTEGER NOT NULL,
      month INTEGER NOT NULL,
      payload TEXT NOT NULL,
      registered_at TEXT NULL,
      updated_at TEXT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (year, month)
    )
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energy_meta (
      meta_key TEXT PRIMARY KEY,
      meta_value TEXT NOT NULL,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
  ");
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS energy_receipts (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      service_key TEXT NOT NULL,
      company TEXT NOT NULL COLLATE NOCASE,
      receipt_date TEXT NOT NULL,
      period_start TEXT NOT NULL,
      period_end TEXT NOT NULL,
      reference TEXT NULL,
      quantity REAL NULL,
      amount REAL NOT NULL,
      production_kg REAL NULL,
      production_start TEXT NULL,
      production_end TEXT NULL,
      registered_at TEXT NOT NULL,
      updated_at TEXT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      modified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE (service_key, company, period_start, period_end)
    )
  ");
  energyEnsureReceiptsCompanySchema($pdo);
  energyEnsureReceiptsNullableQuantitySchema($pdo);
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_energy_receipts_date ON energy_receipts(receipt_date)');
  energyImportLegacyJson($pdo);
  return $pdo;
}

function energyEnsureReceiptsNullableQuantitySchema(PDO $pdo): void
{
  $columns = $pdo->query('PRAGMA table_info(energy_receipts)')->fetchAll();
  foreach ($columns as $column) {
    if ((string)($column['name'] ?? '') === 'quantity' && (int)($column['notnull'] ?? 0) === 0) return;
  }

  $pdo->beginTransaction();
  try {
    $pdo->exec('ALTER TABLE energy_receipts RENAME TO energy_receipts_quantity_required');
    $pdo->exec("
      CREATE TABLE energy_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key TEXT NOT NULL,
        company TEXT NOT NULL COLLATE NOCASE,
        receipt_date TEXT NOT NULL,
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        reference TEXT NULL,
        quantity REAL NULL,
        amount REAL NOT NULL,
        production_kg REAL NULL,
        production_start TEXT NULL,
        production_end TEXT NULL,
        registered_at TEXT NOT NULL,
        updated_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (service_key, company, period_start, period_end)
      )
    ");
    $pdo->exec("
      INSERT INTO energy_receipts
        (id, service_key, company, receipt_date, period_start, period_end, reference, quantity, amount,
         production_kg, production_start, production_end, registered_at, updated_at, created_at, modified_at)
      SELECT
        id, service_key, company, receipt_date, period_start, period_end, reference, quantity, amount,
        production_kg, production_start, production_end, registered_at, updated_at, created_at, modified_at
      FROM energy_receipts_quantity_required
    ");
    $pdo->exec('DROP TABLE energy_receipts_quantity_required');
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function energyEnsureReceiptsCompanySchema(PDO $pdo): void
{
  $columns = $pdo->query('PRAGMA table_info(energy_receipts)')->fetchAll();
  foreach ($columns as $column) {
    if ((string)($column['name'] ?? '') === 'company') return;
  }

  $pdo->beginTransaction();
  try {
    $pdo->exec('ALTER TABLE energy_receipts RENAME TO energy_receipts_legacy');
    $pdo->exec("
      CREATE TABLE energy_receipts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_key TEXT NOT NULL,
        company TEXT NOT NULL COLLATE NOCASE,
        receipt_date TEXT NOT NULL,
        period_start TEXT NOT NULL,
        period_end TEXT NOT NULL,
        reference TEXT NULL,
        quantity REAL NULL,
        amount REAL NOT NULL,
        production_kg REAL NULL,
        production_start TEXT NULL,
        production_end TEXT NULL,
        registered_at TEXT NOT NULL,
        updated_at TEXT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        modified_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (service_key, company, period_start, period_end)
      )
    ");
    $pdo->exec("
      INSERT INTO energy_receipts
        (id, service_key, company, receipt_date, period_start, period_end, reference, quantity, amount,
         production_kg, production_start, production_end, registered_at, updated_at, created_at, modified_at)
      SELECT
        id, service_key, 'Progel', receipt_date, period_start, period_end, reference, quantity, amount,
        production_kg, production_start, production_end, registered_at, updated_at, created_at, modified_at
      FROM energy_receipts_legacy
    ");
    $pdo->exec('DROP TABLE energy_receipts_legacy');
    $pdo->commit();
  } catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $exception;
  }
}

function energyLoadReceipts(?int $year = null): array
{
  $pdo = energyOpenDatabase();
  if ($year !== null) {
    $statement = $pdo->prepare("SELECT * FROM energy_receipts WHERE substr(receipt_date, 1, 4) = ? ORDER BY receipt_date DESC, id DESC");
    $statement->execute([sprintf('%04d', $year)]);
  } else {
    $statement = $pdo->query('SELECT * FROM energy_receipts ORDER BY receipt_date DESC, id DESC');
  }
  return $statement->fetchAll();
}

function energyLoadReceipt(int $id): array
{
  if ($id < 1) return [];
  $statement = energyOpenDatabase()->prepare('SELECT * FROM energy_receipts WHERE id = ? LIMIT 1');
  $statement->execute([$id]);
  $receipt = $statement->fetch();
  return is_array($receipt) ? $receipt : [];
}

function energySaveReceipt(array $receipt): int
{
  $pdo = energyOpenDatabase();
  $id = is_numeric($receipt['id'] ?? null) ? (int)$receipt['id'] : 0;
  $parameters = [
    ':service_key' => (string)$receipt['service_key'],
    ':company' => (string)$receipt['company'],
    ':receipt_date' => (string)$receipt['receipt_date'],
    ':period_start' => (string)$receipt['period_start'],
    ':period_end' => (string)$receipt['period_end'],
    ':reference' => trim((string)($receipt['reference'] ?? '')) ?: null,
    ':quantity' => is_numeric($receipt['quantity'] ?? null) ? (float)$receipt['quantity'] : null,
    ':amount' => (float)$receipt['amount'],
    ':production_kg' => is_numeric($receipt['production_kg'] ?? null) ? (float)$receipt['production_kg'] : null,
    ':production_start' => trim((string)($receipt['production_start'] ?? '')) ?: null,
    ':production_end' => trim((string)($receipt['production_end'] ?? '')) ?: null,
    ':registered_at' => (string)$receipt['registered_at'],
    ':updated_at' => trim((string)($receipt['updated_at'] ?? '')) ?: null,
  ];

  try {
    if ($id > 0) {
      $parameters[':id'] = $id;
      $statement = $pdo->prepare("
        UPDATE energy_receipts SET
          service_key = :service_key, company = :company, receipt_date = :receipt_date,
          period_start = :period_start, period_end = :period_end,
          reference = :reference, quantity = :quantity, amount = :amount,
          production_kg = :production_kg, production_start = :production_start,
          production_end = :production_end, updated_at = :updated_at,
          modified_at = CURRENT_TIMESTAMP
        WHERE id = :id
      ");
      $statement->execute($parameters);
      if ($statement->rowCount() === 0 && energyLoadReceipt($id) === []) throw new RuntimeException('El recibo que intentas editar ya no existe.');
      return $id;
    }

    $statement = $pdo->prepare("
      INSERT INTO energy_receipts
        (service_key, company, receipt_date, period_start, period_end, reference, quantity, amount,
         production_kg, production_start, production_end, registered_at, updated_at, modified_at)
      VALUES
        (:service_key, :company, :receipt_date, :period_start, :period_end, :reference, :quantity, :amount,
         :production_kg, :production_start, :production_end, :registered_at, :updated_at, CURRENT_TIMESTAMP)
    ");
    $statement->execute($parameters);
    return (int)$pdo->lastInsertId();
  } catch (PDOException $exception) {
    if ((string)$exception->getCode() === '23000' || strpos($exception->getMessage(), 'UNIQUE constraint failed') !== false) {
      throw new RuntimeException('Ya existe un recibo de ese servicio, empresa y periodo. Puedes editar el registro existente.');
    }
    throw $exception;
  }
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

function energyMonthKey(int $year, int $month): string
{
  return sprintf('%04d-%02d', $year, $month);
}

function energyValidMonth(int $year, int $month): bool
{
  return $year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12;
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

function energyLoadMonthlyRecords(): array
{
  $statement = energyOpenDatabase()->query('SELECT period_key, payload FROM energy_monthly_records ORDER BY period_key');
  $records = [];
  foreach ($statement as $row) {
    $record = json_decode((string)($row['payload'] ?? ''), true);
    if (is_array($record)) $records[(string)$row['period_key']] = $record;
  }
  return $records;
}

function energyLoadMonthlyData(int $year, int $month): array
{
  if (!energyValidMonth($year, $month)) return [];
  $statement = energyOpenDatabase()->prepare('SELECT payload FROM energy_monthly_records WHERE period_key = ? LIMIT 1');
  $statement->execute([energyMonthKey($year, $month)]);
  $payload = $statement->fetchColumn();
  if (!is_string($payload) || $payload === '') return [];
  $record = json_decode($payload, true);
  return is_array($record) ? $record : [];
}

function energyRegisteredMonths(array $records): array
{
  $months = [];
  foreach ($records as $key => $record) {
    if (!is_array($record) || preg_match('/^(\d{4})-(\d{2})$/', (string)$key, $matches) !== 1) continue;
    $year = (int)$matches[1];
    $month = (int)$matches[2];
    if (energyValidMonth($year, $month)) $months[] = ['key' => (string)$key, 'anio' => $year, 'mes' => $month];
  }
  usort($months, static fn(array $a, array $b): int => strcmp($b['key'], $a['key']));
  return $months;
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

function energySaveMonthlyData(int $year, int $month, array $data): void
{
  if (!energyValidMonth($year, $month)) throw new InvalidArgumentException('El mes seleccionado no es válido.');
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  $pdo = energyOpenDatabase();
  $pdo->beginTransaction();
  try {
    $statement = $pdo->prepare("
      INSERT INTO energy_monthly_records
        (period_key, year, month, payload, registered_at, updated_at, modified_at)
      VALUES
        (:period_key, :year, :month, :payload, :registered_at, :updated_at, CURRENT_TIMESTAMP)
      ON CONFLICT(period_key) DO UPDATE SET
        payload = excluded.payload,
        registered_at = COALESCE(energy_monthly_records.registered_at, excluded.registered_at),
        updated_at = excluded.updated_at,
        modified_at = CURRENT_TIMESTAMP
    ");
    $statement->execute([
      ':period_key' => energyMonthKey($year, $month),
      ':year' => $year,
      ':month' => $month,
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
