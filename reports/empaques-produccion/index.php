<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

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

extract($report, EXTR_SKIP);

$cardsPorPagina = $meta['cardsPorPagina'] ?? 9;
$filasPorPagina = $meta['filasPorPagina'] ?? 15;
$toleranciaPct = $meta['toleranciaPct'] ?? 10;
$intervaloActualizacion = $meta['intervaloActualizacion'] ?? 300000;
$fechaDesde = $meta['fechaDesde'] ?? '';
$productosEmpaques = $meta['productos'] ?? [];

/*
|--------------------------------------------------------------------------
| Variables para partials compartidos
|--------------------------------------------------------------------------
*/
$isGrupo = false;
$urlVolverIndex = '../index.php';
$grupoActual = null;
$productoSeleccionado = null;
$productoLabel = null;
$modo = 'consumo'; // empaques-produccion siempre es consumo
$showModeTabs = false;
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
  <?php require __DIR__ . '/../../shared/partials/kpis.php'; ?>
  <?php require __DIR__ . '/../../shared/partials/chart.php'; ?>

  <?php require __DIR__ . '/partials/table_pivot_empaques.php'; ?>

  <?php require __DIR__ . '/../../shared/partials/footer.php'; ?>

  <script>
    window.reportConfig = {
      titulo: <?= json_encode($titulo, JSON_UNESCAPED_UNICODE) ?>,
      anioAnterior: <?= json_encode($anioAnterior) ?>,
      anioActual: <?= json_encode($anioActual) ?>,
      anioPivot: <?= json_encode($anioPivot) ?>,
      ratioBase: <?= json_encode($ratioBase) ?>,
      maxRatio: <?= json_encode($maxRatio) ?>,
      cardsPorPagina: <?= json_encode($cardsPorPagina) ?>,
      filasPorPagina: <?= json_encode($filasPorPagina) ?>,
      toleranciaPct: <?= json_encode($toleranciaPct) ?>,
      intervaloActualizacion: <?= json_encode($intervaloActualizacion) ?>,
      version: <?= json_encode($version) ?>
    };

    window.reportData = {
      reporte: <?= json_encode($reporte, JSON_UNESCAPED_UNICODE) ?>,
      datosAnioAnterior: <?= json_encode($datosAnioAnterior, JSON_UNESCAPED_UNICODE) ?>,
      datosAnioActual: <?= json_encode($datosAnioActual, JSON_UNESCAPED_UNICODE) ?>,
      chartData: <?= json_encode($chartData, JSON_UNESCAPED_UNICODE) ?>,

      semanasCatalogo: <?= json_encode($semanasCatalogo, JSON_UNESCAPED_UNICODE) ?>,
      empaquessCatalogo: <?= json_encode($empaquessCatalogo, JSON_UNESCAPED_UNICODE) ?>,
      empaquesEtiquetas: <?= json_encode($empaquesEtiquetas, JSON_UNESCAPED_UNICODE) ?>,
      matrizEmpaques: <?= json_encode($matrizEmpaques, JSON_UNESCAPED_UNICODE) ?>,
      produccionPivotPorSemana: <?= json_encode($produccionPivotPorSemana, JSON_UNESCAPED_UNICODE) ?>,
      matrizRatioEmpaques: <?= json_encode($matrizRatioEmpaques, JSON_UNESCAPED_UNICODE) ?>,
      ratioBasePorEmpaque: <?= json_encode($ratioBasePorEmpaque, JSON_UNESCAPED_UNICODE) ?>,
      totalesPorSemana: <?= json_encode($totalesPorSemana, JSON_UNESCAPED_UNICODE) ?>,
      produccionPorSemana: <?= json_encode($produccionPorSemana, JSON_UNESCAPED_UNICODE) ?>,
      ratioPorSemana: <?= json_encode($ratioPorSemana, JSON_UNESCAPED_UNICODE) ?>,

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
      meta: <?= json_encode($meta, JSON_UNESCAPED_UNICODE) ?>
    };
  </script>

  <script src="../../assets/js/dashboard.js?v=<?= urlencode((string)$version) ?>"></script>
  <script src="../../assets/js/enzima-produccion.js?v=<?= urlencode((string)$version) ?>"></script>
</body>

</html>
