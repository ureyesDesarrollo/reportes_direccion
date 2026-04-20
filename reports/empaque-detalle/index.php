<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Evitar caché en navegador
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Cargar configuración y helpers
$appConfig = require __DIR__ . '/../../config/app.php';
$dbConfig = require __DIR__ . '/../../config/database.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/../../shared/helpers.php';

try {
  $report = require __DIR__ . '/build_report.php';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<h1>Error al generar el reporte</h1>';
  echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
  exit;
}

// Exponer variables del reporte
extract($report, EXTR_SKIP);

/*
|--------------------------------------------------------------------------
| Variables extra para la vista
|--------------------------------------------------------------------------
*/
$cardsPorPagina = $meta['cardsPorPagina'] ?? 9;
$filasPorPagina = $meta['filasPorPagina'] ?? 15;
$toleranciaPct = $meta['toleranciaPct'] ?? 10;
$intervaloActualizacion = $meta['intervaloActualizacion'] ?? 300000;
$fechaDesde = $meta['fechaDesde'] ?? '';

$productosEmpaques = $meta['productos'] ?? [];
$productoSeleccionado = $config['producto_seleccionado'] ?? ($meta['productoSeleccionado'] ?? null);
$productoLabel = $config['producto_label'] ?? ($_GET['productoLabel'] ?? $productoSeleccionado);
$modo = $config['modo'] ?? ($meta['modo'] ?? 'consumo');

$metricaTitulo = $metricaTitulo ?? ($meta['metricaTitulo'] ?? ($modo === 'costo' ? 'Costo Promedio' : ($modo === 'impacto' ? 'Impacto Total' : 'Consumo')));
$metricaUnidad = $metricaUnidad ?? ($meta['metricaUnidad'] ?? ($modo === 'consumo' ? 'kg' : '$'));

/*
|--------------------------------------------------------------------------
| Qué se muestra según el modo
|--------------------------------------------------------------------------
*/
$mostrarGrafica = true;
$mostrarCardsBase = $modo !== 'impacto';
$mostrarCardsActual = true;

/*
|--------------------------------------------------------------------------
| Alias para reutilizar parciales existentes
|--------------------------------------------------------------------------
*/
$totalQuimicosAnioAnterior = $totalMetricaAnioAnterior ?? 0;
$totalProduccionAnioAnterior = $totalProduccionAnioAnterior ?? 0;
$totalQuimicosAnioActual = $totalMetricaAnioActual ?? 0;
$totalProduccionAnioActual = $totalProduccionAnioActual ?? 0;
$variacionQuimicos = $variacionMetrica ?? null;

/*
|--------------------------------------------------------------------------
| Normalizar arrays para cards/table existentes
|--------------------------------------------------------------------------
*/
$normalizarFilas = static function (array $filas): array {
  foreach ($filas as &$fila) {
    if (!array_key_exists('quimicos', $fila) && array_key_exists('metrica', $fila)) {
      $fila['quimicos'] = $fila['metrica'];
    }
  }
  unset($fila);

  return $filas;
};

$reporte = $normalizarFilas($reporte ?? []);
$datosAnioAnterior = $normalizarFilas($datosAnioAnterior ?? []);
$datosAnioActual = $normalizarFilas($datosAnioActual ?? []);

/*
|--------------------------------------------------------------------------
| Variables para partials compartidos
|--------------------------------------------------------------------------
*/
$isGrupo = false;
$urlVolverIndex = '../index.php';
$grupoActual = null; // no aplica para productos individuales
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">

  <title><?= htmlspecialchars($titulo) ?></title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)$version) ?>">

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>

<body>
  <?php require __DIR__ . '/../../shared/partials/header.php'; ?>
  <?php if ($modo !== 'impacto'): ?>
    <?php require __DIR__ . '/../../shared/partials/kpis.php'; ?>
  <?php endif; ?>

  <?php if ($mostrarGrafica): ?>
    <?php require __DIR__ . '/../../shared/partials/chart.php'; ?>
  <?php endif; ?>

  <?php if ($mostrarCardsBase): ?>
    <?php require __DIR__ . '/../../shared/partials/cards_base.php'; ?>
  <?php endif; ?>

  <?php if ($mostrarCardsActual): ?>
    <?php require __DIR__ . '/../../shared/partials/cards_actual.php'; ?>
  <?php endif; ?>

  <?php require __DIR__ . '/../../shared/partials/filters.php'; ?>
  <?php require __DIR__ . '/../../shared/partials/table.php'; ?>
  <?php require __DIR__ . '/../../shared/partials/footer.php'; ?>

  <script>
    window.reportConfig = {
      titulo: <?= json_encode($titulo, JSON_UNESCAPED_UNICODE) ?>,
      productoSeleccionado: <?= json_encode($productoSeleccionado, JSON_UNESCAPED_UNICODE) ?>,
      productoLabel: <?= json_encode($productoLabel, JSON_UNESCAPED_UNICODE) ?>,
      modo: <?= json_encode($modo, JSON_UNESCAPED_UNICODE) ?>,
      metricaTitulo: <?= json_encode($metricaTitulo, JSON_UNESCAPED_UNICODE) ?>,
      metricaUnidad: <?= json_encode($metricaUnidad, JSON_UNESCAPED_UNICODE) ?>,
      anioAnterior: <?= json_encode($anioAnterior) ?>,
      anioActual: <?= json_encode($anioActual) ?>,
      ratioBase: <?= json_encode($ratioBase) ?>,
      maxRatio: <?= json_encode($maxRatio) ?>,
      cardsPorPagina: <?= json_encode($cardsPorPagina) ?>,
      filasPorPagina: <?= json_encode($filasPorPagina) ?>,
      toleranciaPct: <?= json_encode($toleranciaPct) ?>,
      intervaloActualizacion: <?= json_encode($intervaloActualizacion) ?>,
      version: <?= json_encode($version) ?>,
      mostrarGrafica: <?= json_encode($mostrarGrafica) ?>,
      mostrarCardsBase: <?= json_encode($mostrarCardsBase) ?>
    };

    window.reportData = {
      reporte: <?= json_encode($reporte, JSON_UNESCAPED_UNICODE) ?>,
      datosAnioAnterior: <?= json_encode($datosAnioAnterior, JSON_UNESCAPED_UNICODE) ?>,
      datosAnioActual: <?= json_encode($datosAnioActual, JSON_UNESCAPED_UNICODE) ?>,
      chartData: <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>,
      kpis: {
        totalQuimicosAnioAnterior: <?= json_encode($totalQuimicosAnioAnterior) ?>,
        totalProduccionAnioAnterior: <?= json_encode($totalProduccionAnioAnterior) ?>,
        totalQuimicosAnioActual: <?= json_encode($totalQuimicosAnioActual) ?>,
        totalProduccionAnioActual: <?= json_encode($totalProduccionAnioActual) ?>,
        ratioPromedioAnioActual: <?= json_encode($ratioPromedioAnioActual) ?>,
        variacionQuimicos: <?= json_encode($variacionQuimicos) ?>,
        variacionProduccion: <?= json_encode($variacionProduccion) ?>,
        variacionRatio: <?= json_encode($variacionRatio) ?>
      },
      limites: {
        verde: <?= json_encode($limiteVerde) ?>,
        amarillo: <?= json_encode($limiteAmarillo) ?>
      },
      meta: <?= json_encode(array_merge($meta, [
              'modo' => $modo,
              'productoSeleccionado' => $productoSeleccionado,
              'productoLabel' => $productoLabel,
              'metricaTitulo' => $metricaTitulo,
              'metricaUnidad' => $metricaUnidad,
            ]), JSON_UNESCAPED_UNICODE) ?>
    };
  </script>

  <script src="../../assets/js/dashboard.js?v=<?= urlencode((string)$version) ?>"></script>
  <script src="../../assets/js/enzima-produccion.js?v=<?= urlencode((string)$version) ?>"></script>
</body>

</html>
