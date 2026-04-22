<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ReportHelpers.php';

class ReportEngine
{
  public static function createContext(array $config, array $appConfig, array $dbConfig): array
  {
    $fechaDesde = $config['fecha_desde'];
    $campoFechaMovs = $config['campo_fecha_movs'];
    $modo = $config['modo'] ?? 'consumo';

    return [
      'config' => $config,
      'appConfig' => $appConfig,
      'dbConfig' => $dbConfig,
      'fechaDesde' => $fechaDesde,
      'campoFechaMovs' => $campoFechaMovs,
      'campoFechaMovsSql' => "m.`{$campoFechaMovs}`",
      'weekFields' => buildWeekFields("m.`{$campoFechaMovs}`"),
      'anioActual' => (int)date('Y'),
      'anioAnterior' => (int)date('Y') - 1,
      'modo' => $modo,
      'toleranciaPct' => (float)($config['tolerancia_pct'] ?? 10),
      'cardsPorPagina' => (int)($appConfig['cards_por_pagina'] ?? 9),
      'filasPorPagina' => (int)($appConfig['filas_por_pagina'] ?? 15),
      'intervaloActualizacion' => (int)($appConfig['intervalo_actualizacion'] ?? 300000),
      'pdoMovs' => conectar($dbConfig['movs']),
      'pdoProd' => conectar($dbConfig['prod']),
    ];
  }

  public static function fetchRowsByPeriodo(PDO $pdo, string $sql, array $params = []): array
  {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    while ($row = $stmt->fetch()) {
      $periodo = isset($row['periodo']) ? (int)$row['periodo'] : null;
      if ($periodo !== null) {
        $rows[$periodo] = $row;
      }
    }

    return $rows;
  }

  public static function fetchProductionSeries(PDO $pdo, string $fechaDesde): array
  {
    $sql = "
            SELECT
                YEARWEEK(tar_fecha, 3) AS periodo,
                DATE_FORMAT(tar_fecha, '%x-S%v') AS semana_iso,
                DATE_FORMAT(DATE_SUB(DATE(tar_fecha), INTERVAL WEEKDAY(tar_fecha) DAY), '%Y-%m-%d') AS semana_inicio,
                DATE_FORMAT(DATE_ADD(DATE(tar_fecha), INTERVAL (6 - WEEKDAY(tar_fecha)) DAY), '%Y-%m-%d') AS semana_fin,
                SUM(tar_kilos) AS kilos_producidos
            FROM rev_tarimas
            WHERE tar_fecha >= ?
            AND tar_count_etiquetado > 0
            GROUP BY YEARWEEK(tar_fecha, 3)
            ORDER BY periodo
        ";

    $rows = self::fetchRowsByPeriodo($pdo, $sql, [$fechaDesde]);
    foreach ($rows as &$row) {
      $row['kilos_producidos'] = isset($row['kilos_producidos']) ? (float)$row['kilos_producidos'] : 0.0;
    }
    unset($row);

    return $rows;
  }

  public static function assemblePeriods(array $detallePorPeriodo, array $produccionPorPeriodo, callable $itemBuilder, int $anioAnterior, int $anioActual): array
  {
    $periodos = array_unique(array_merge(array_keys($detallePorPeriodo), array_keys($produccionPorPeriodo)));
    sort($periodos);

    $items = [];
    $datosAnioAnterior = [];
    $datosAnioActual = [];

    foreach ($periodos as $periodo) {
      $row = $detallePorPeriodo[$periodo] ?? [];
      $produccion = $produccionPorPeriodo[$periodo] ?? [];

      $item = $itemBuilder($row, $produccion, $periodo);
      $item['periodo'] = $periodo;
      $item['semana_iso'] = $item['semana_iso'] ?? ($produccion['semana_iso'] ?? (string)$periodo);
      $item['semana_inicio'] = $item['semana_inicio'] ?? ($produccion['semana_inicio'] ?? '');
      $item['semana_fin'] = $item['semana_fin'] ?? ($produccion['semana_fin'] ?? '');
      $item['produccion'] = isset($produccion['kilos_producidos']) ? (float)$produccion['kilos_producidos'] : 0.0;
      $item['semana_label'] = substr((string)$item['semana_iso'], -3);

      $items[] = $item;

      $anioItem = (int)substr((string)$item['semana_iso'], 0, 4);
      if ($anioItem === $anioAnterior) {
        $datosAnioAnterior[] = $item;
      } elseif ($anioItem === $anioActual) {
        $datosAnioActual[] = $item;
      }
    }

    return [
      'items' => $items,
      'datosAnioAnterior' => $datosAnioAnterior,
      'datosAnioActual' => $datosAnioActual,
    ];
  }

  public static function applyTrafficLights(array $items, ?float $ratioBase, float $toleranciaPct, string $modo): array
  {
    foreach ($items as &$item) {
      [$estado, $color, $colorHex] = resolveTrafficLight($item['ratio'] ?? null, $ratioBase, $toleranciaPct, $modo);
      $item['estado'] = $estado;
      $item['color'] = $color;
      $item['colorHex'] = $colorHex;
    }
    unset($item);

    return $items;
  }

  public static function sortByPeriodDesc(array $items): array
  {
    usort($items, fn($a, $b) => $b['periodo'] <=> $a['periodo']);
    return $items;
  }

  public static function maxRatio(array $items): float
  {
    return array_reduce($items, fn(float $carry, array $item): float => max($carry, abs((float)($item['ratio'] ?? 0.0))), 0.0);
  }

  public static function buildBaseResponse(array $meta, array $payload): array
  {
    return array_merge($meta, $payload);
  }
}
