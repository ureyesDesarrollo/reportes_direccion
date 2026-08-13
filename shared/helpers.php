<?php

function conectar(array $cfg): PDO
{
    $host = (string)($cfg['host'] ?? 'localhost');
    $port = $cfg['port'] ?? null;
    if ($port === null && preg_match('/^(.+):(\d+)$/', $host, $matches) === 1) {
        $host = $matches[1];
        $port = (int)$matches[2];
    }

    $dsn = "mysql:host={$host};"
        . ($port !== null ? "port={$port};" : '')
        . "dbname={$cfg['dbname']};charset={$cfg['charset']}";
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function n(?float $valor, int $decimales = 2): string
{
    if ($valor === null) return '-';
    return number_format($valor, $decimales, '.', ',');
}

function semaforo(?float $ratio, ?float $ratioBase, float $toleranciaPct): array
{
    if ($ratio === null) return ['Sin dato', 'gris', '#94a3b8'];
    if ($ratioBase === null || $ratioBase <= 0) return ['Sin base', 'gris', '#94a3b8'];

    $limiteAmarillo = $ratioBase * (1 + $toleranciaPct / 100);

    if ($ratio <= $ratioBase) {
        return ['Óptimo', 'verde', '#10b981'];
    } elseif ($ratio <= $limiteAmarillo) {
        return ['Cuidado', 'amarillo', '#f59e0b'];
    }

    return ['Alto', 'rojo', '#ef4444'];
}

/**
 * Cache simple basado en archivos
 */
function getCache(string $key): ?array
{
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $file = $cacheDir . '/' . md5($key) . '.cache';
    if (!file_exists($file)) {
        return null;
    }
    $payload = @file_get_contents($file);
    if ($payload === false || $payload === '') {
        return null;
    }
    $data = @unserialize($payload, ['allowed_classes' => false]);
    if (!is_array($data) || !array_key_exists('expires', $data) || !array_key_exists('value', $data)) {
        return null;
    }
    if ((int)$data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    return is_array($data['value']) ? $data['value'] : null;
}

function setCache(string $key, array $value, int $ttlSeconds = 3600): void
{
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }
    }
    if (!is_writable($cacheDir)) {
        return;
    }
    $file = $cacheDir . '/' . md5($key) . '.cache';
    $data = [
        'value' => $value,
        'expires' => time() + $ttlSeconds,
    ];
    $tempFile = $file . '.tmp.' . getmypid() . '.' . str_replace('.', '', uniqid('', true));
    $written = @file_put_contents($tempFile, serialize($data), LOCK_EX);
    if ($written === false) {
        @unlink($tempFile);
        return;
    }
    if (!@rename($tempFile, $file)) {
        @unlink($tempFile);
    }
}
