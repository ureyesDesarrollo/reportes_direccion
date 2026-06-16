<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

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

$fechaInicio = $meta['fechaInicio'] ?? date('Y-m-01');
$fechaFin = $meta['fechaFin'] ?? date('Y-m-d');
$empCodeFiltro = $meta['empCodeFiltro'] ?? '';
$filasPorPagina = $meta['filasPorPagina'] ?? 15;
$intervaloActualizacion = $meta['intervaloActualizacion'] ?? 1800000;
$version = $version ?? time();
$isDetalleEmpleado = !empty($detalleEmpleado) && is_array($detalleEmpleado);
$buildPhotoProxyUrl = static function (?string $rawUrl): string {
  $rawUrl = trim((string)$rawUrl);
  if ($rawUrl === '') {
    return '';
  }

  return 'photo.php?url=' . rawurlencode(rtrim(strtr(base64_encode($rawUrl), '+/', '-_'), '='));
};
$baseQuery = [
  'inicio' => $fechaInicio,
  'fin' => $fechaFin,
];
if ($empCodeFiltro !== '') {
  $baseQuery['emp_code'] = $empCodeFiltro;
}
$topBackHref = $isDetalleEmpleado ? ('?' . http_build_query($baseQuery)) : '../index.php';
$topBackLabel = $isDetalleEmpleado ? 'Volver a Hikvision' : 'Regresar al inicio';
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= htmlspecialchars($titulo) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/css/dashboard.css") ?: 0))) ?>">
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)max((int)$version, (int)(@filemtime(__DIR__ . "/../../assets/js/display-mode.js") ?: 0))) ?>"></script>
  <style>
    .filters-shell, .detail-shell, .table-shell, .preview-modal-card {
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
    }
    .filters-shell, .detail-shell { padding: 18px; margin-bottom: 22px; }
    .filters-grid { display:flex; gap:12px; align-items:end; flex-wrap:wrap; }
    .field-group { display:flex; flex-direction:column; gap:6px; min-width:160px; }
    .field-group label { font-size:0.82rem; font-weight:700; color:#475569; }
    .field-group input {
      border:1px solid #dbe2ea; border-radius:12px; padding:10px 12px; font:inherit; background:#fff; color:#0f172a;
    }
    .action-btn {
      border:0; border-radius:999px; padding:11px 16px; font:inherit; font-weight:700; background:#10b981; color:#fff; cursor:pointer;
    }
    .warning-box {
      margin: 14px 0 22px; padding:14px 16px; border-radius:14px; background:#fffbeb; border:1px solid #fcd34d; color:#92400e;
    }
    .table-toolbar, .detail-header {
      padding:16px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
    }
    .table-search { position:relative; min-width:240px; flex:1; max-width:380px; }
    .table-search input { width:100%; border:1px solid #dbe2ea; border-radius:999px; padding:10px 14px 10px 38px; font:inherit; }
    .table-search i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#94a3b8; }
    .status-pill {
      display:inline-flex; align-items:center; justify-content:center; border-radius:999px; padding:6px 10px; font-size:0.78rem; font-weight:700; white-space:nowrap;
    }
    .pagination {
      display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; padding:16px 18px; border-top:1px solid #e2e8f0;
    }
    .pagination-buttons { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .page-btn {
      border:1px solid #dbe2ea; background:#fff; color:#334155; border-radius:10px; min-width:38px; height:38px; font-weight:700; cursor:pointer;
    }
    .page-btn.active { background:#10b981; border-color:#10b981; color:#fff; }
    .detail-back {
      display:inline-flex; align-items:center; gap:8px; text-decoration:none; color:#334155; border:1px solid #dbe2ea; border-radius:999px; padding:10px 14px; font-weight:700; background:#fff;
    }
    .photo-btn {
      display:inline-flex; align-items:center; gap:8px; border:1px solid #dbe2ea; background:#fff; color:#334155; border-radius:999px; padding:7px 12px; font-weight:700; cursor:pointer;
    }
    .preview-modal {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.66);
      display: none;
      align-items: center;
      justify-content: center;
      padding: 22px;
      z-index: 9999;
    }
    .preview-modal.active { display:flex; }
    .preview-modal-card {
      max-width: 900px;
      width: 100%;
      padding: 18px;
      position: relative;
    }
    .preview-modal-close {
      position:absolute;
      right:12px;
      top:12px;
      border:0;
      background:#fff;
      border-radius:999px;
      width:36px;
      height:36px;
      cursor:pointer;
      color:#334155;
      box-shadow:0 4px 12px rgba(15, 23, 42, 0.08);
    }
    .preview-modal img {
      width:100%;
      max-height:70vh;
      object-fit:contain;
      border-radius:12px;
      background:#f8fafc;
      border:1px solid #e2e8f0;
    }
    .preview-meta {
      margin-top:12px;
      color:#64748b;
      font-size:0.9rem;
    }
    @media (min-width: 1800px) {
      .filters-shell, .detail-shell { padding: 24px; margin-bottom: 28px; }
      .filters-grid { gap: 16px; }
      .field-group { min-width: 190px; }
      .field-group label, .status-pill { font-size: 0.9rem; }
      .field-group input, .action-btn, .detail-back, .photo-btn { padding: 12px 16px; }
      .table-toolbar, .detail-header, .pagination { padding: 20px 24px; }
      .table-search { max-width: 520px; }
      .table-search input { padding: 12px 16px 12px 42px; }
      .page-btn { min-width: 44px; height: 44px; }
    }
    @media (max-width: 900px) { .dashboard { padding:22px 16px; } .table-wrapper { overflow-x:auto; } }
  </style>
</head>

<body>
  <div class="dashboard">
    <div class="header">
      <div class="header-left">
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
          <a href="<?= htmlspecialchars($topBackHref) ?>" class="back-btn" style="display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; text-decoration:none; font-weight:700; border:1px solid #e2e8f0; background:#ffffff; color:#334155;">
            <i class="fas fa-arrow-left"></i>
            <?= htmlspecialchars($topBackLabel) ?>
          </a>
        </div>
        <h1><i class="fas fa-camera" style="margin-right:12px;"></i><?= htmlspecialchars($titulo) ?></h1>
        <div class="sub">
          <span><i class="fas fa-door-open"></i> Eventos de acceso válidos</span>
          <span class="badge"><i class="fas fa-clock"></i> Major 5 / Minor 75</span>
          <span class="year-badge"><i class="fas fa-rotate"></i> Actualización: cada <?= n($intervaloActualizacion / 60000, 0) ?> min</span>
        </div>
      </div>
    </div>

    <?php foreach (($meta['warnings'] ?? []) as $warning): ?>
      <div class="warning-box"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars((string)$warning) ?></div>
    <?php endforeach; ?>

    <div class="filters-shell">
      <form method="get" class="filters-grid">
        <div class="field-group">
          <label for="inicio">Desde</label>
          <input type="date" id="inicio" name="inicio" value="<?= htmlspecialchars($fechaInicio) ?>">
        </div>
        <div class="field-group">
          <label for="fin">Hasta</label>
          <input type="date" id="fin" name="fin" value="<?= htmlspecialchars($fechaFin) ?>">
        </div>
        <div class="field-group">
          <label for="emp_code">Empleado</label>
          <input type="text" id="emp_code" name="emp_code" value="<?= htmlspecialchars($empCodeFiltro) ?>" placeholder="No. empleado">
        </div>
        <button type="submit" class="action-btn"><i class="fas fa-filter"></i> Consultar</button>
      </form>
    </div>

    <?php if ($isDetalleEmpleado): ?>
      <div class="detail-shell">
        <div class="detail-header">
          <div>
            <strong style="display:block; margin-bottom:4px; font-size:1.05rem;">Detalle del empleado</strong>
            <span style="color:#64748b; font-size:0.9rem;">
              <?= htmlspecialchars((string)($detalleEmpleado['resumen']['nombre'] ?? '')) ?>
            </span>
          </div>
          <a href="?<?= htmlspecialchars(http_build_query($baseQuery)) ?>" class="detail-back">
            <i class="fas fa-arrow-left"></i>
            Volver al resumen
          </a>
        </div>
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Horario</th>
                <th>Primera</th>
                <th>Última</th>
                <th>Horas netas</th>
                <th>Retardo</th>
                <th>Salida anticipada</th>
                <th>Horas extra</th>
                <th>Horas faltantes</th>
                <th>Fotos</th>
                <th>Semáforo</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($detalleEmpleado['filas'] ?? []) as $fila): ?>
                <?php
                $picturePrimera = $buildPhotoProxyUrl($fila['picture_primera'] ?? '');
                $pictureUltima = $buildPhotoProxyUrl($fila['picture_ultima'] ?? '');
                ?>
                <tr>
                  <td><?= htmlspecialchars((string)($fila['fecha'] ?? '')) ?> (<?= htmlspecialchars((string)($fila['dia_semana'] ?? '')) ?>)</td>
                  <td><?= htmlspecialchars((string)($fila['horario_entrada'] ?? '')) ?> - <?= htmlspecialchars((string)($fila['horario_salida'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['primera'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['ultima'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['horas_netas'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['retardo'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['salida_anticipada'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['horas_extra'] ?? '')) ?></td>
                  <td><?= htmlspecialchars((string)($fila['horas_faltantes'] ?? '')) ?></td>
                  <td>
                    <?php if ($picturePrimera !== ''): ?>
                      <button type="button" class="photo-btn preview-photo-btn" data-photo-url="<?= htmlspecialchars($picturePrimera) ?>" data-photo-title="<?= htmlspecialchars((string)(($fila['nombre'] ?? '') . ' | Primera checada')) ?>">
                        <i class="fas fa-image"></i> Primera
                      </button>
                    <?php endif; ?>
                    <?php if ($pictureUltima !== ''): ?>
                      <button type="button" class="photo-btn preview-photo-btn" data-photo-url="<?= htmlspecialchars($pictureUltima) ?>" data-photo-title="<?= htmlspecialchars((string)(($fila['nombre'] ?? '') . ' | Última checada')) ?>" style="margin-top:6px;">
                        <i class="fas fa-image"></i> Última
                      </button>
                    <?php endif; ?>
                  </td>
                  <td><span class="status-pill" style="background: <?= htmlspecialchars((string)($fila['semaforo_color'] ?? '#94a3b8')) ?>1A; color: <?= htmlspecialchars((string)($fila['semaforo_color'] ?? '#94a3b8')) ?>;"><?= htmlspecialchars((string)($fila['semaforo_label'] ?? '-')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="table-shell">
      <div class="table-toolbar">
        <div>
          <strong style="display:block; margin-bottom:4px;">Resumen por empleado</strong>
          <span style="color:#64748b; font-size:0.9rem;">Semáforo administrativo con checadas válidas de Hikvision.</span>
        </div>
        <div class="table-search">
          <i class="fas fa-search"></i>
          <input type="search" id="hikvisionSearch" placeholder="Buscar empleado o número">
        </div>
      </div>
      <div class="table-wrapper">
        <table class="table" id="hikvisionTable">
          <thead>
            <tr>
              <th>Empleado</th>
              <th>Días</th>
              <th>Horas trabajadas</th>
              <th>Retardo</th>
              <th>Salida anticipada</th>
              <th>Horas extra</th>
              <th>Horas faltantes</th>
              <th>Semáforo</th>
            </tr>
          </thead>
          <tbody id="hikvisionTableBody">
            <?php foreach ($resumen as $row): ?>
              <?php $detailUrl = '?' . http_build_query(array_merge($baseQuery, ['detalle_emp' => $row['emp_code'] ?? ''])); ?>
              <tr data-search="<?= htmlspecialchars(mb_strtolower(trim(($row['nombre'] ?? '') . ' ' . ($row['emp_code'] ?? '')))) ?>">
                <td>
                  <a href="<?= htmlspecialchars($detailUrl) ?>" class="pivot-link-detail" title="Ver detalle del empleado">
                    <strong><?= htmlspecialchars((string)($row['nombre'] ?? '')) ?></strong>
                    <span class="pivot-link-icon"><i class="fas fa-arrow-up-right-from-square"></i></span>
                  </a><br>
                  <span style="color:#64748b; font-size:0.82rem;"><?= htmlspecialchars((string)($row['emp_code'] ?? '')) ?></span>
                </td>
                <td><?= htmlspecialchars((string)($row['dias'] ?? '0')) ?></td>
                <td><?= htmlspecialchars((string)($row['horas_trabajadas'] ?? '00:00')) ?></td>
                <td><?= htmlspecialchars((string)($row['retardo'] ?? '00:00')) ?></td>
                <td><?= htmlspecialchars((string)($row['salida_anticipada'] ?? '00:00')) ?></td>
                <td><?= htmlspecialchars((string)($row['horas_extra'] ?? '00:00')) ?></td>
                <td><?= htmlspecialchars((string)($row['horas_faltantes'] ?? '00:00')) ?></td>
                <td><span class="status-pill" style="background: <?= htmlspecialchars((string)($row['semaforo_color'] ?? '#94a3b8')) ?>1A; color: <?= htmlspecialchars((string)($row['semaforo_color'] ?? '#94a3b8')) ?>;"><?= htmlspecialchars((string)($row['semaforo_label'] ?? '-')) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="pagination">
        <div id="hikvisionCounter" style="color:#64748b; font-size:0.9rem;"></div>
        <div class="pagination-buttons" id="hikvisionPages"></div>
      </div>
    </div>
  </div>

  <div class="preview-modal" id="photoPreviewModal" aria-hidden="true">
    <div class="preview-modal-card">
      <button type="button" class="preview-modal-close" id="photoPreviewClose"><i class="fas fa-times"></i></button>
      <img id="photoPreviewImage" src="" alt="Foto de checada">
      <div class="preview-meta" id="photoPreviewMeta"></div>
    </div>
  </div>

  <script>
    (() => {
      const searchInput = document.getElementById('hikvisionSearch');
      const body = document.getElementById('hikvisionTableBody');
      const counter = document.getElementById('hikvisionCounter');
      const pages = document.getElementById('hikvisionPages');
      const pageSize = <?= json_encode((int)$filasPorPagina) ?>;
      let currentPage = 1;

      const getRows = () => Array.from(body.querySelectorAll('tr'));
      const getFilteredRows = () => {
        const query = (searchInput.value || '').trim().toLowerCase();
        return getRows().filter((row) => {
          const haystack = row.dataset.search || '';
          return query === '' || haystack.includes(query);
        });
      };

      const renderPagination = () => {
        const filtered = getFilteredRows();
        const total = filtered.length;
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        currentPage = Math.min(currentPage, totalPages);

        getRows().forEach((row) => {
          row.style.display = 'none';
        });

        const start = (currentPage - 1) * pageSize;
        const visible = filtered.slice(start, start + pageSize);
        visible.forEach((row) => {
          row.style.display = '';
        });

        counter.textContent = total === 0
          ? 'Sin registros'
          : 'Mostrando ' + (start + 1) + '-' + (start + visible.length) + ' de ' + total + ' registros';

        pages.innerHTML = '';
        const makeButton = (label, page, disabled, active) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'page-btn' + (active ? ' active' : '');
          btn.textContent = label;
          btn.disabled = !!disabled;
          btn.addEventListener('click', () => {
            currentPage = page;
            renderPagination();
          });
          return btn;
        };

        pages.appendChild(makeButton('<', Math.max(1, currentPage - 1), currentPage === 1, false));
        for (let page = 1; page <= totalPages; page++) {
          pages.appendChild(makeButton(String(page), page, false, page === currentPage));
        }
        pages.appendChild(makeButton('>', Math.min(totalPages, currentPage + 1), currentPage === totalPages, false));
      };

      searchInput.addEventListener('input', () => {
        currentPage = 1;
        renderPagination();
      });

      renderPagination();

      const modal = document.getElementById('photoPreviewModal');
      const modalImage = document.getElementById('photoPreviewImage');
      const modalMeta = document.getElementById('photoPreviewMeta');
      const modalClose = document.getElementById('photoPreviewClose');

      document.querySelectorAll('.preview-photo-btn').forEach((button) => {
        button.addEventListener('click', () => {
          modalImage.src = button.dataset.photoUrl || '';
          modalMeta.textContent = button.dataset.photoTitle || '';
          modal.classList.add('active');
          modal.setAttribute('aria-hidden', 'false');
        });
      });

      const closeModal = () => {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        modalImage.src = '';
      };

      modalClose.addEventListener('click', closeModal);
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal();
        }
      });
    })();
  </script>
</body>

</html>
