<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$reports = require __DIR__ . '/../config/reports_registry.php';
$reports = array_values(array_filter($reports, fn($r) => !isset($r['enabled']) || $r['enabled'] === true));

function e(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Centro de Reportes</title>

  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%);
      color: #0f172a;
    }

    .page {
      max-width: 1400px;
      margin: 0 auto;
      padding: 32px 24px 48px;
    }

    .hero {
      margin-bottom: 32px;
    }

    .hero h1 {
      font-size: 2.4rem;
      font-weight: 800;
      margin-bottom: 10px;
      background: linear-gradient(135deg, #0f172a 0%, #475569 100%);
      -webkit-background-clip: text;
      background-clip: text;
      color: transparent;
    }

    .hero p {
      color: #64748b;
      font-size: 1rem;
      max-width: 850px;
      line-height: 1.6;
    }

    .toolbar {
      display: flex;
      gap: 14px;
      align-items: center;
      flex-wrap: wrap;
      margin-bottom: 24px;
    }

    .search-box {
      position: relative;
      flex: 1;
      min-width: 260px;
    }

    .search-box i {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: #94a3b8;
    }

    .search-box input {
      width: 100%;
      padding: 12px 16px 12px 42px;
      border-radius: 999px;
      border: 1px solid #dbe2ea;
      background: #ffffff;
      font-size: 0.95rem;
      outline: none;
      transition: all 0.2s ease;
    }

    .search-box input:focus {
      border-color: #10b981;
      box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.10);
    }

    .count-badge {
      background: #ffffff;
      border: 1px solid #dbe2ea;
      color: #475569;
      border-radius: 999px;
      padding: 10px 14px;
      font-size: 0.85rem;
      font-weight: 600;
      white-space: nowrap;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
      gap: 22px;
    }

    .card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 24px;
      padding: 22px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
      transition: all 0.25s ease;
      display: flex;
      flex-direction: column;
      min-height: 220px;
    }

    .card:hover {
      transform: translateY(-4px);
      box-shadow: 0 18px 38px rgba(15, 23, 42, 0.10);
      border-color: #cbd5e1;
    }

    .card-top {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 18px;
    }

    .icon-wrap {
      width: 54px;
      height: 54px;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 1.35rem;
      box-shadow: 0 10px 20px rgba(0, 0, 0, 0.08);
      flex-shrink: 0;
    }

    .card h2 {
      font-size: 1.1rem;
      font-weight: 700;
      line-height: 1.25;
      margin-bottom: 4px;
    }

    .slug {
      color: #94a3b8;
      font-size: 0.8rem;
      font-weight: 600;
    }

    .description {
      color: #475569;
      font-size: 0.94rem;
      line-height: 1.6;
      margin-bottom: 18px;
      flex: 1;
    }

    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      margin-top: auto;
    }

    .status {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.82rem;
      color: #64748b;
      font-weight: 600;
    }

    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #10b981;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      border-radius: 999px;
      padding: 10px 16px;
      font-size: 0.9rem;
      font-weight: 700;
      color: white;
      transition: opacity 0.2s ease;
    }

    .btn:hover {
      opacity: 0.9;
    }

    .empty {
      grid-column: 1 / -1;
      background: #ffffff;
      border: 1px dashed #cbd5e1;
      border-radius: 22px;
      padding: 40px 24px;
      text-align: center;
      color: #64748b;
    }

    .empty i {
      font-size: 2.4rem;
      color: #cbd5e1;
      margin-bottom: 12px;
    }

    .footer-note {
      margin-top: 28px;
      color: #64748b;
      font-size: 0.85rem;
      line-height: 1.6;
    }

    @media (max-width: 768px) {
      .page {
        padding: 20px 16px 36px;
      }

      .hero h1 {
        font-size: 1.8rem;
      }

      .grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
  <div class="page">
    <section class="hero">
      <h1><i class="fas fa-table-columns" style="margin-right: 10px;"></i>Centro de Reportes</h1>
      <p>
        Selecciona el reporte que deseas consultar. Este índice te permite centralizar todos los dashboards
        y crecer el sistema sin depender de archivos sueltos.
      </p>
    </section>

    <section class="toolbar">
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" id="searchReports" placeholder="Buscar reporte...">
      </div>

      <div class="count-badge">
        <span id="reportCount"><?= count($reports) ?></span> reporte(s)
      </div>
    </section>

    <section class="grid" id="reportsGrid">
      <?php foreach ($reports as $report): ?>
        <article class="card report-card"
          data-search="<?= e(mb_strtolower(($report['title'] ?? '') . ' ' . ($report['description'] ?? '') . ' ' . ($report['slug'] ?? ''))) ?>">
          <div class="card-top">
            <div class="icon-wrap" style="background: <?= e($report['color'] ?? '#10b981') ?>;">
              <i class="fas <?= e($report['icon'] ?? 'fa-chart-line') ?>"></i>
            </div>
            <div>
              <h2><?= e($report['title'] ?? 'Reporte') ?></h2>
              <div class="slug"><?= e($report['slug'] ?? '') ?></div>
            </div>
          </div>

          <div class="description">
            <?= e($report['description'] ?? 'Sin descripción.') ?>
          </div>

          <div class="actions">
            <div class="status">
              <span class="status-dot"></span>
              Disponible
            </div>

            <a class="btn" href="<?= e($report['url'] ?? '#') ?>" style="background: <?= e($report['color'] ?? '#10b981') ?>;">
              Ver reporte
              <i class="fas fa-arrow-right"></i>
            </a>
          </div>
        </article>
      <?php endforeach; ?>

      <div class="empty" id="emptyState" style="display:none;">
        <i class="fas fa-folder-open"></i>
        <div>No se encontraron reportes con ese criterio.</div>
      </div>
    </section>
  </div>

  <script>
    (function() {
      const input = document.getElementById('searchReports');
      const cards = Array.from(document.querySelectorAll('.report-card'));
      const count = document.getElementById('reportCount');
      const empty = document.getElementById('emptyState');

      function filterReports() {
        const term = (input.value || '').trim().toLowerCase();
        let visible = 0;

        cards.forEach(card => {
          const haystack = card.dataset.search || '';
          const show = haystack.includes(term);
          card.style.display = show ? '' : 'none';
          if (show) visible++;
        });

        count.textContent = visible;
        empty.style.display = visible === 0 ? '' : 'none';
      }

      input.addEventListener('input', filterReports);
    })();
  </script>
</body>

</html>
