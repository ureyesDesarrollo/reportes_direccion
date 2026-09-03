<?php

declare(strict_types=1);

if (!function_exists('loadProjectEnvironment')) {
  /**
   * Carga un archivo .env sencillo sin reemplazar variables ya definidas
   * por Apache, Docker o el sistema operativo.
   */
  function loadProjectEnvironment(string $file): void
  {
    static $loadedFiles = [];

    $resolved = realpath($file);
    if ($resolved === false || !is_file($resolved) || isset($loadedFiles[$resolved])) return;
    $loadedFiles[$resolved] = true;

    $lines = @file($resolved, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return;

    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
      if (strncmp($line, 'export ', 7) === 0) $line = trim(substr($line, 7));

      $separator = strpos($line, '=');
      if ($separator === false) continue;

      $name = trim(substr($line, 0, $separator));
      if (preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) !== 1) continue;
      if (getenv($name) !== false || array_key_exists($name, $_ENV) || array_key_exists($name, $_SERVER)) continue;

      $value = trim(substr($line, $separator + 1));
      $length = strlen($value);
      if ($length >= 2) {
        $first = $value[0];
        $last = $value[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
          $value = substr($value, 1, -1);
        }
      }

      putenv($name . '=' . $value);
      $_ENV[$name] = $value;
      $_SERVER[$name] = $value;
    }
  }
}
