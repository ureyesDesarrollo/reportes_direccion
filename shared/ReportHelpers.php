<?php

declare(strict_types=1);

/**
 * ReportHelpers - Funciones compartidas para generar reportes
 *
 * Reduce duplicación de código entre los diferentes tipos de reportes
 * (enzima-produccion, quimico-detalle, quimicos-produccion)
 */

/**
 * Valida columnas dinámicas
 */
function validateReportColumns(string $dateField, string $costField = ''): void
{
  if (!preg_match('/^[A-Za-z0-9_]+$/', $dateField)) {
    throw new RuntimeException('Nombre de columna de fecha inválido.');
  }

  if ($costField && !preg_match('/^[A-Za-z0-9_]+$/', $costField)) {
    throw new RuntimeException('Nombre de columna de costo inválido.');
  }
}

/**
 * Obtiene la expresión SQL para calcular consumo en kg
 */
function getConsumoExpression(): string
{
  return "
        SUM(
            CASE
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('KG','KGS','KILO','KILOS') THEN m.CANT_PROD
                WHEN UPPER(TRIM(m.UNIUSU)) IN ('G','GR','GRAMO','GRAMOS') THEN m.CANT_PROD / 1000
                ELSE m.CANT_PROD
            END
        )
    ";
}

/**
 * Obtiene la expresión SQL para calcular costo promedio
 */
function getCostoExpression(string $campoCosto): string
{
  return "AVG(m.`{$campoCosto}`)";
}

/**
 * Obtiene datos de producción desde la base de datos
 *
 * @param PDO $pdo Conexión a BD de producción
 * @param string $fechaDesde Fecha desde la cual traer datos
 * @param int $anioAnterior Año anterior para comparación
 * @param int $anioActual Año actual
 * @return array Producción por periodo (YEARWEEK)
 */
function fetchProductionData(PDO $pdo, string $fechaDesde, int $anioAnterior, int $anioActual): array
{
  $sql = "
        SELECT
            YEARWEEK(tar_fecha, 3) AS periodo,
            DATE_FORMAT(tar_fecha, '%x-S%v') AS semana_iso,
            DATE_FORMAT(DATE_SUB(DATE(tar_fecha), INTERVAL WEEKDAY(tar_fecha) DAY), '%Y-%m-%d') AS semana_inicio,
            DATE_FORMAT(DATE_ADD(DATE(tar_fecha), INTERVAL (6 - WEEKDAY(tar_fecha)) DAY), '%Y-%m-%d') AS semana_fin,
            SUM(tar_kilos) AS kilos_producidos
        FROM tarimas
        WHERE tar_fecha >= ?
        GROUP BY YEARWEEK(tar_fecha, 3)
        ORDER BY periodo
    ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$fechaDesde]);

  $produccion = [];
  while ($row = $stmt->fetch()) {
    $periodo = (int)$row['periodo'];
    $produccion[$periodo] = $row;
  }

  return $produccion;
}

/**
 * Aplica semáforo a un conjunto de items
 *
 * @param array $items Items que reciben semáforo
 * @param ?float $ratioBase Base para comparación
 * @param float $toleranciaPct Porcentaje de tolerancia
 * @param string $modo Modo (consumo, costo, impacto)
 * @return array Items con semáforo aplicado
 */
function applyTrafficLights(array $items, ?float $ratioBase, float $toleranciaPct, string $modo): array
{
  foreach ($items as &$item) {
    if ($modo === 'impacto') {
      if ($item['ratio'] === null) {
        [$estado, $color, $colorHex] = ['Sin dato', 'gris', '#94a3b8'];
      } elseif ($item['ratio'] <= 0) {
        [$estado, $color, $colorHex] = ['Ahorro', 'verde', '#10b981'];
      } else {
        [$estado, $color, $colorHex] = ['Sobrecosto', 'rojo', '#ef4444'];
      }
    } else {
      [$estado, $color, $colorHex] = semaforo($item['ratio'], $ratioBase, $toleranciaPct);
    }

    $item['estado'] = $estado;
    $item['color'] = $color;
    $item['colorHex'] = $colorHex;
  }

  return $items;
}

/**
 * Separa items por año
 *
 * @param array $items Items con semana_iso
 * @param int $anioAnterior Año anterior
 * @param int $anioActual Año actual
 * @return array ['anterior' => [...], 'actual' => [...]]
 */
function separateByYear(array $items, int $anioAnterior, int $anioActual): array
{
  $anterior = [];
  $actual = [];

  foreach ($items as $item) {
    $anioItem = (int)substr((string)$item['semana_iso'], 0, 4);

    if ($anioItem === $anioAnterior) {
      $anterior[] = $item;
    } elseif ($anioItem === $anioActual) {
      $actual[] = $item;
    }
  }

  return [
    'anterior' => $anterior,
    'actual' => $actual,
  ];
}

/**
 * Ordena items por periodo descendente
 */
function sortByPeriodDesc(array &$items): void
{
  usort($items, fn($a, $b) => $b['periodo'] <=> $a['periodo']);
}

/**
 * Construye chart data desde arrays de datos
 *
 * @param array $datosAnioActual Datos del año actual
 * @param array $datosAnioAnterior Datos del año anterior
 * @param int $anioAnterior Año anterior
 * @param int $anioActual Año actual
 * @param ?float $ratioBase Base para gráfico
 * @return array Chart data para frontend
 */
function buildChartData(
  array $datosAnioActual,
  array $datosAnioAnterior,
  int $anioAnterior,
  int $anioActual,
  ?float $ratioBase
): array {
  return [
    'anioAnterior' => $anioAnterior,
    'anioActual' => $anioActual,
    'ratioBase' => $ratioBase,
    'labels' => array_map(fn($item) => $item['semana_iso'], $datosAnioActual),
    'ratiosActual' => array_map(fn($item) => $item['ratio'], $datosAnioActual),
    'colorsActual' => array_map(fn($item) => $item['colorHex'] ?? '#94a3b8', $datosAnioActual),
    'ratiosBase' => array_map(fn($item) => $item['ratio'], $datosAnioAnterior),
  ];
}

function calculateVariation(?float $actual, ?float $anterior): ?float
{
  if ($anterior === null || $anterior === 0.0) {
    return null;
  }

  return (($actual - $anterior) / abs($anterior)) * 100;
}

/**
 * Construye los campos comunes de semana para consultas SQL
 */
function buildWeekFields(string $dateExpression): string
{
  return "
        YEARWEEK({$dateExpression}, 3) AS periodo,
        DATE_FORMAT({$dateExpression}, '%x-S%v') AS semana_iso,
        DATE_FORMAT(DATE_SUB(DATE({$dateExpression}), INTERVAL WEEKDAY({$dateExpression}) DAY), '%Y-%m-%d') AS semana_inicio,
        DATE_FORMAT(DATE_ADD(DATE({$dateExpression}), INTERVAL (6 - WEEKDAY({$dateExpression})) DAY), '%Y-%m-%d') AS semana_fin
    ";
}

/**
 * Crea la cláusula GROUP BY para los campos de semana
 */
function buildWeekGroupBy(string $dateExpression): string
{
  return "
        YEARWEEK({$dateExpression}, 3),
        DATE_FORMAT({$dateExpression}, '%x-S%v'),
        DATE_FORMAT(DATE_SUB(DATE({$dateExpression}), INTERVAL WEEKDAY({$dateExpression}) DAY), '%Y-%m-%d'),
        DATE_FORMAT(DATE_ADD(DATE({$dateExpression}), INTERVAL (6 - WEEKDAY({$dateExpression})) DAY), '%Y-%m-%d')
    ";
}

/**
 * Crea una lista de placeholders para consultas SQL IN
 */
function createPlaceholders(array $values): string
{
  return implode(',', array_fill(0, count($values), '?'));
}

/**
 * Obtiene filas indexadas por periodo (YEARWEEK)
 */
function fetchRowsByPeriodo(PDO $pdo, string $sql, array $params = []): array
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

/**
 * Resuelve semáforo según modo del reporte
 */
function resolveTrafficLight(?float $valor, ?float $base, float $toleranciaPct, string $modo): array
{
  if ($modo === 'impacto') {
    if ($valor === null) {
      return ['Sin dato', 'gris', '#94a3b8'];
    }

    if ($valor <= 0) {
      return ['Ahorro', 'verde', '#10b981'];
    }

    $limiteAmarillo = $base !== null ? $base * 1.06 : 0;
    if ($valor <= $limiteAmarillo) {
      return ['Cuidado', 'amarillo', '#f59e0b'];
    }

    return ['Sobrecosto', 'rojo', '#ef4444'];
  }

  return semaforo($valor, $base, $toleranciaPct);
}
