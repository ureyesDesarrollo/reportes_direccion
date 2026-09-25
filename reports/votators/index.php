<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$report = require __DIR__ . '/build_report.php';
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$rangeRows = static function (array $field): array {
  $rule = (array)($field['rule'] ?? []);
  if (($rule['modo'] ?? '') !== 'bandas') return [];
  $grouped = ['verde' => [], 'amarillo' => [], 'rojo' => []];
  foreach ((array)($rule['bandas'] ?? []) as $band) {
    $status = (string)($band['estado'] ?? '');
    $legend = trim((string)($band['leyenda'] ?? ''));
    if (isset($grouped[$status]) && $legend !== '') {
      $grouped[$status][] = $legend;
    }
  }
  $rows = [];
  foreach (['verde', 'amarillo', 'rojo'] as $status) {
    if ($grouped[$status] === []) continue;
    $rows[] = ['status' => $status, 'value' => implode(' / ', array_values(array_unique($grouped[$status])))];
  }
  return $rows;
};

$renderValue = static function (array $field) use ($e): string {
  $value = trim((string)($field['formatted'] ?? '—')) ?: '—';
  $unit = trim((string)($field['unit'] ?? ''));
  return '<strong class="vc-value">' . $e($value) . ($unit !== '' && $value !== '—' ? ' <small>' . $e($unit) . '</small>' : '') . '</strong>';
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($report['titulo']) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="../../assets/css/dashboard.css">
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; background: #08131b; }
    body { color: #f4f7fa; font-family: Inter, Arial, Helvetica, sans-serif; }
    .votators-report {
      --vc-page: #08131b;
      --vc-panel: #13212b;
      --vc-inner: #1b2b36;
      --vc-line: #314451;
      --vc-text: #f4f7fa;
      --vc-muted: #cbd8e2;
      --vc-red: #c94436;
      --vc-yellow: #facc15;
      --vc-green: #2e8b57;
      --vc-gray: #64748b;
      display: grid;
      grid-template-rows: auto auto minmax(0, 1fr) auto;
      gap: 10px;
      width: 100%;
      min-height: 100vh;
      padding: 10px;
      background: var(--vc-page);
    }
    .vc-header { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
    .vc-heading { display: flex; align-items: center; gap: 12px; }
    .vc-heading-icon { display: grid; width: 42px; height: 42px; place-items: center; border-radius: 12px; color: #08131b; background: #f4f7fa; font-size: 21px; }
    .vc-header h1, .vc-header p, .vc-unit h2 { margin: 0; }
    .vc-header h1 { font-size: clamp(27px, 2.2vw, 40px); line-height: 1; }
    .vc-header p { margin-top: 4px; color: var(--vc-muted); font-size: clamp(13px, 1vw, 17px); }
    .vc-header-actions { display: flex; align-items: center; gap: 9px; }
    .vc-count, .vc-back { padding: 8px 13px; border-radius: 999px; font-size: 13px; font-weight: 800; white-space: nowrap; }
    .vc-count { color: #7dd3fc; background: #203957; }
    .vc-back { color: #f4f7fa; background: #1b2b36; text-decoration: none; }
    .vc-warning { padding: 7px 10px; border-radius: 8px; color: #111827; background: var(--vc-yellow); font-weight: 800; }
    .vc-top-sensors { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; min-width: 0; }
    .vc-temperature { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 14px; min-width: 0; padding: 10px 16px; border: 1px solid #2563eb; border-radius: 14px; background: #163354; }
    .vc-temperature-icon { display: grid; width: 46px; height: 46px; place-items: center; border-radius: 12px; color: #082f49; background: #7dd3fc; font-size: 25px; transition: color .2s ease, background-color .2s ease; }
    .vc-temperature-copy { min-width: 0; }
    .vc-temperature-source { display: block; margin-bottom: 2px; color: #7dd3fc; font-size: 11px; font-weight: 900; letter-spacing: .09em; text-transform: uppercase; }
    .vc-temperature-title { display: block; overflow: hidden; color: #f8fafc; font-size: clamp(16px, 1.15vw, 22px); font-weight: 900; text-overflow: ellipsis; white-space: nowrap; }
    .vc-temperature-reading { min-width: 150px; text-align: right; }
    .vc-temperature-value { display: block; color: #fff; font-size: clamp(28px, 2.4vw, 42px); font-variant-numeric: tabular-nums; font-weight: 900; line-height: .95; white-space: nowrap; }
    .vc-temperature-value small { font-size: .48em; }
    .vc-temperature-time { display: block; margin-top: 4px; color: #cbd8e2; font-size: 11px; white-space: nowrap; }
    .vc-temperature-warning { grid-column: 2 / -1; color: #fde68a; font-size: 11px; font-weight: 700; }
    .vc-temperature.status-verde { border-color: #43a36d; background: var(--vc-green); }
    .vc-temperature.status-amarillo { border-color: #facc15; color: #111827; background: var(--vc-yellow); }
    .vc-temperature.status-rojo { border-color: #e35d50; background: var(--vc-red); }
    .vc-temperature.status-gris { border-color: #2563eb; background: #163354; }
    .vc-temperature.status-verde .vc-temperature-icon { color: #14532d; background: rgba(255,255,255,.82); }
    .vc-temperature.status-amarillo .vc-temperature-icon { color: #713f12; background: rgba(255,255,255,.72); }
    .vc-temperature.status-rojo .vc-temperature-icon { color: #7f1d1d; background: rgba(255,255,255,.82); }
    .vc-temperature.status-amarillo .vc-temperature-source,
    .vc-temperature.status-amarillo .vc-temperature-title,
    .vc-temperature.status-amarillo .vc-temperature-value,
    .vc-temperature.status-amarillo .vc-temperature-time { color: #111827; }
    .vc-temperature-ranges { display: flex; flex-wrap: wrap; gap: 4px 12px; margin-top: 5px; font-size: 11px; }
    .vc-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); grid-template-rows: minmax(0, 1fr); gap: 10px; min-height: 0; }
    .vc-unit { min-width: 0; overflow: hidden; display: flex; flex-direction: column; padding: 9px; border: 1px solid var(--vc-line); border-radius: 15px; background: var(--vc-panel); }
    .vc-unit-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: -9px -9px 9px; padding: 9px 12px; }
    .vc-unit-head, .vc-field { transition: background-color .2s ease, color .2s ease; }
    .vc-unit-head h2 { font-size: clamp(32px, 2.3vw, 42px); }
    .vc-unit-head.status-verde { background: var(--vc-green); }
    .vc-unit-head.status-amarillo { color: #111827; background: var(--vc-yellow); }
    .vc-unit-head.status-rojo { background: var(--vc-red); }
    .vc-unit-head.status-gris { background: var(--vc-gray); }
    .vc-state { padding: 5px 10px; border-radius: 999px; color: inherit; background: rgba(255,255,255,.22); font-size: 12px; font-weight: 800; }
    .vc-fields { display: grid; grid-template-columns: minmax(0, 1fr); gap: 7px; flex: 1 1 auto; min-height: 0; }
    .vc-field { min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 3px; padding: 10px 12px; border-radius: 11px; background: var(--vc-inner); }
    .vc-field.status-verde { background: var(--vc-green); }
    .vc-field.status-amarillo { color: #111827; background: var(--vc-yellow); }
    .vc-field.status-rojo { background: var(--vc-red); }
    .vc-field.status-gris { background: #334653; }
    .vc-label { font-size: clamp(21px, 1.55vw, 27px); font-weight: 800; }
    .vc-value { max-width: 100%; overflow: hidden; font-size: clamp(28px, 2.45vw, 42px); font-variant-numeric: tabular-nums; line-height: 1; text-overflow: ellipsis; white-space: nowrap; }
    .vc-value small { font-size: .52em; }
    .vc-ranges { display: grid; grid-template-columns: repeat(3, minmax(0, auto)); align-items: center; gap: 4px 9px; margin-top: 5px; padding-top: 6px; border-top: 1px solid rgba(255,255,255,.3); font-size: clamp(10px, .72vw, 12px); }
    .vc-field.status-amarillo .vc-ranges { border-top-color: rgba(17,24,39,.25); }
    .vc-range { min-width: 0; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
    .vc-range i, .vc-legend i { flex: 0 0 auto; width: 8px; height: 8px; border-radius: 50%; }
    .vc-range .verde, .vc-legend .verde { background: var(--vc-green); }
    .vc-range .amarillo, .vc-legend .amarillo { background: var(--vc-yellow); }
    .vc-range .rojo, .vc-legend .rojo { background: var(--vc-red); }
    .vc-no-range { margin-top: 5px; color: #dce6ed; font-size: 11px; }
    .vc-secondary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 6px; margin-top: 7px; }
    .vc-mini { min-width: 0; padding: 7px 5px; border-radius: 9px; text-align: center; background: var(--vc-inner); }
    .vc-mini strong { display: block; overflow: hidden; font-size: clamp(16px, 1.25vw, 22px); font-variant-numeric: tabular-nums; text-overflow: ellipsis; white-space: nowrap; }
    .vc-mini strong small { font-size: .65em; }
    .vc-mini span { display: block; margin-top: 3px; color: var(--vc-muted); font-size: clamp(14px, 1vw, 17px); }
    .vc-read-time { display: flex; align-items: center; justify-content: flex-end; gap: 5px; margin-top: 6px; color: var(--vc-muted); font-size: 11px; }
    .vc-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; color: var(--vc-muted); font-size: 12px; }
    .vc-legend { display: flex; flex-wrap: wrap; gap: 12px; }
    .vc-legend span { display: inline-flex; align-items: center; gap: 5px; }
    body.capture-mode .votators-report { padding: 6px; gap: 6px; }
    body.capture-mode .vc-top-sensors { gap: 6px; }
    body.capture-mode .vc-back { display: none; }
    body.capture-mode .vc-grid { gap: 6px; }
    body.capture-mode .vc-unit { padding: 7px; border-radius: 11px; }
    body.capture-mode .vc-unit-head { margin: -7px -7px 7px; padding: 7px 10px; }
    body.capture-mode .vc-field { padding: 7px 9px; }
    @media (max-width: 1200px) {
      .vc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-rows: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 820px) {
      .votators-report { min-height: 0; }
      .vc-top-sensors { grid-template-columns: 1fr; }
      .vc-grid { grid-template-columns: 1fr; grid-template-rows: none; }
      .vc-unit:nth-child(n) { grid-column: auto; grid-row: auto; }
      .vc-unit { min-height: 390px; }
    }
    @media (max-width: 520px) {
      .votators-report { padding: 7px; }
      .vc-heading-icon, .vc-count { display: none; }
      .vc-header { align-items: flex-start; }
      .vc-temperature { gap: 8px; padding: 9px 10px; }
      .vc-temperature-title { overflow: visible; font-size: 16px; line-height: 1.12; white-space: normal; }
      .vc-temperature-reading { min-width: 120px; }
      .vc-temperature-value { font-size: 27px; }
      .vc-back { padding: 7px 10px; }
      .vc-fields { grid-template-columns: 1fr; }
      .vc-field { min-height: 105px; }
      .vc-secondary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .vc-unit { min-height: 0; }
      .vc-footer { align-items: flex-start; flex-direction: column; }
    }
  </style>
  <script src="../../assets/js/display-mode.js?v=<?= urlencode((string)(@filemtime(__DIR__ . '/../../assets/js/display-mode.js') ?: time())) ?>"></script>
</head>
<body class="<?= $captureMode ? 'capture-mode' : '' ?>">
  <main class="votators-report">
    <header class="vc-header">
      <div class="vc-heading"><span class="vc-heading-icon"><i class="fa-solid fa-sliders"></i></span><div><h1><?= $e($report['titulo']) ?></h1><p><?= $e($report['subtitulo']) ?> · actualización cada 1 s</p></div></div>
      <div class="vc-header-actions"><span class="vc-count">4 equipos</span><a class="vc-back" href="../"><i class="fa-solid fa-arrow-left"></i> Regresar</a></div>
    </header>
    <section class="vc-top-sensors" aria-label="Temperatura de alimentación de Votators">
    <?php $feedTemperature = (array)($report['temperatura_alimentacion'] ?? []); ?>
    <?php $feedStatus = in_array($feedTemperature['statusKey'] ?? '', ['verde', 'amarillo', 'rojo'], true) ? (string)$feedTemperature['statusKey'] : 'gris'; $feedRows = $rangeRows($feedTemperature); ?>
    <section class="vc-temperature status-<?= $e($feedStatus) ?>" data-live-sensor="feed" aria-label="Temperatura de entrada del tanque de alimentación de Votators 3 y 4">
      <span class="vc-temperature-icon"><i class="fa-solid fa-temperature-three-quarters"></i></span>
      <div class="vc-temperature-copy"><span class="vc-temperature-source">Sensor AVEVA</span><span class="vc-temperature-title">TEMP. ENTRADA TANQUE ALIMENTACIÓN V3 Y V4</span>
      <?php if ($feedRows !== []): ?><div class="vc-temperature-ranges"><?php foreach ($feedRows as $row): ?><span class="vc-range"><i class="<?= $e($row['status']) ?>"></i><?= $e($row['value']) ?></span><?php endforeach; ?></div><?php endif; ?></div>
      <div class="vc-temperature-reading"><strong class="vc-temperature-value"><?= $e($feedTemperature['formatted'] ?? '—') ?><?php if (($feedTemperature['formatted'] ?? '—') !== '—'): ?> <small><?= $e($feedTemperature['unit'] ?? '°C') ?></small><?php endif; ?></strong><span class="vc-temperature-time">Última lectura <?= $e($feedTemperature['fecha'] ?? '—') ?></span></div>
      <?php if (trim((string)($report['temperatura_warning'] ?? '')) !== ''): ?><span class="vc-temperature-warning"><?= $e($report['temperatura_warning']) ?></span><?php endif; ?>
    </section>
    </section>
    <?php if (trim((string)($report['warning'] ?? '')) !== ''): ?><div class="vc-warning"><?= $e($report['warning']) ?></div><?php endif; ?>

    <section class="vc-grid" aria-label="Estado de los Votators">
      <?php foreach ((array)$report['equipos'] as $equipment):
        $equipmentStatus = in_array($equipment['statusKey'] ?? '', ['verde', 'amarillo', 'rojo'], true) ? (string)$equipment['statusKey'] : 'gris';
      ?>
        <article class="vc-unit" data-equipment-key="<?= $e($equipment['key']) ?>" aria-label="<?= $e($equipment['label']) ?>, <?= $e($equipment['statusLabel']) ?>">
          <header class="vc-unit-head status-<?= $e($equipmentStatus) ?>"><h2><?= $e($equipment['label']) ?></h2><span class="vc-state"><?= $e($equipment['statusLabel']) ?></span></header>
          <div class="vc-fields">
            <?php foreach ((array)$equipment['principales'] as $field):
              $fieldStatus = in_array($field['statusKey'] ?? '', ['verde', 'amarillo', 'rojo'], true) ? (string)$field['statusKey'] : 'gris';
              $rows = $rangeRows((array)$field);
            ?>
              <div class="vc-field status-<?= $e($fieldStatus) ?>" data-field-key="<?= $e($field['key']) ?>">
                <span class="vc-label"><?= $e($field['label']) ?></span>
                <?= $renderValue((array)$field) ?>
                <?php if ($rows !== []): ?><div class="vc-ranges"><?php foreach ($rows as $row): ?><span class="vc-range"><i class="<?= $e($row['status']) ?>"></i><?= $e($row['value']) ?></span><?php endforeach; ?></div><?php else: ?><span class="vc-no-range">Lectura sin rango definido</span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="vc-secondary">
            <?php foreach ((array)$equipment['secundarios'] as $field): ?><div class="vc-mini" data-field-key="<?= $e($field['key']) ?>"><?= $renderValue((array)$field) ?><span><?= $e($field['label']) ?></span></div><?php endforeach; ?>
          </div>
          <div class="vc-read-time"><i class="fa-regular fa-clock"></i> Última lectura <?= $e($equipment['ultimaLectura']) ?></div>
        </article>
      <?php endforeach; ?>
    </section>

    <footer class="vc-footer"><div class="vc-legend"><span><i class="verde"></i>Verde (objetivo)</span><span><i class="amarillo"></i>Amarillo</span><span><i class="rojo"></i>Rojo</span><span>Gris: sin rango o sin dato</span></div><span class="vc-general-reading">Lectura general <?= $e($report['ultima_lectura']) ?></span></footer>
  </main>
  <script>
    (() => {
      const intervalMs = <?= (int)$report['intervalo_actualizacion_ms'] ?>;
      const captureMode = <?= $captureMode ? 'true' : 'false' ?>;
      let updating = false;

      const statusClasses = ['status-verde', 'status-amarillo', 'status-rojo', 'status-gris'];
      const setStatus = (element, status) => {
        if (!element) return;
        element.classList.remove(...statusClasses);
        element.classList.add(`status-${statusClasses.includes(`status-${status}`) ? status : 'gris'}`);
      };
      const setValue = (container, field) => {
        const value = container?.querySelector('.vc-value');
        if (!value) return;
        value.replaceChildren(document.createTextNode(field.formatted || '—'));
        if (field.unit && field.formatted !== '—') {
          const unit = document.createElement('small');
          unit.textContent = ` ${field.unit}`;
          value.append(unit);
        }
      };

      const applyLiveData = (data) => {
        Object.entries(data.equipment || {}).forEach(([equipmentKey, equipment]) => {
          const card = document.querySelector(`[data-equipment-key="${CSS.escape(equipmentKey)}"]`);
          if (!card) return;
          const header = card.querySelector('.vc-unit-head');
          setStatus(header, equipment.statusKey);
          const state = header?.querySelector('.vc-state');
          if (state) state.textContent = equipment.statusLabel || 'Sin datos';

          Object.entries(equipment.fields || {}).forEach(([fieldKey, field]) => {
            const fieldCard = card.querySelector(`[data-field-key="${CSS.escape(fieldKey)}"]`);
            if (!fieldCard) return;
            if (fieldCard.classList.contains('vc-field')) setStatus(fieldCard, field.statusKey);
            setValue(fieldCard, field);
          });

          const readTime = card.querySelector('.vc-read-time');
          if (readTime) {
            const icon = document.createElement('i');
            icon.className = 'fa-regular fa-clock';
            readTime.replaceChildren(icon, document.createTextNode(` Última lectura ${equipment.timestamp || '—'}`));
          }
        });

        const feedCard = document.querySelector('[data-live-sensor="feed"]');
        const feedTemperature = data.feedTemperature || {};
        setStatus(feedCard, feedTemperature.statusKey);
        const feedTemperatureValue = feedCard?.querySelector('.vc-temperature-value');
        if (feedTemperatureValue) {
          feedTemperatureValue.replaceChildren(document.createTextNode(feedTemperature.formatted || '—'));
          if (feedTemperature.unit && feedTemperature.formatted !== '—') {
            const unit = document.createElement('small');
            unit.textContent = ` ${feedTemperature.unit}`;
            feedTemperatureValue.append(unit);
          }
        }
        const feedTemperatureTime = feedCard?.querySelector('.vc-temperature-time');
        if (feedTemperatureTime) feedTemperatureTime.textContent = `Última lectura ${feedTemperature.timestamp || '—'}`;

        const generalReading = document.querySelector('.vc-general-reading');
        if (generalReading) generalReading.textContent = `Lectura general ${data.timestamp || '—'}`;
      };

      const refreshDashboard = async () => {
        if (captureMode || updating || document.hidden) return;
        updating = true;
        try {
          const response = await fetch(`live.php?_=${Date.now()}`, { cache: 'no-store' });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          const data = await response.json();
          if (data.ok) window.requestAnimationFrame(() => applyLiveData(data));
        } catch (error) {
          console.debug('Actualización silenciosa pendiente.', error);
        } finally {
          updating = false;
        }
      };

      if (!captureMode) {
        refreshDashboard();
        window.setInterval(refreshDashboard, intervalMs);
      }
    })();
  </script>
</body>
</html>
