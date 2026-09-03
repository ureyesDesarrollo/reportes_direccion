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
);

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
);

CREATE TABLE IF NOT EXISTS energy_meta (
  meta_key TEXT PRIMARY KEY,
  meta_value TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
