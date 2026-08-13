<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
$requestedYear = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$requestedWeek = filter_input(INPUT_GET, 'semana', FILTER_VALIDATE_INT);
$hasRequestedWeek = is_int($requestedYear)
  && is_int($requestedWeek)
  && rhValidWeek($requestedYear, $requestedWeek);
$selectedYear = $hasRequestedWeek ? $requestedYear : (int)$now->format('o');
$selectedWeek = $hasRequestedWeek ? $requestedWeek : (int)$now->format('W');
$weeklyRecords = rhLoadWeeklyRecords();
$savedData = rhLoadData($selectedYear, $selectedWeek);

// Sin una semana solicitada, mostrar la semana actual solo después de capturarla.
// Antes de eso se conserva en pantalla el último registro semanal disponible.
if (!$hasRequestedWeek && $savedData === []) {
  $currentMonday = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), 1)->setTime(0, 0);
  $latestRecordedMonday = null;
  foreach ($weeklyRecords as $weekKey => $weeklyRecord) {
    if (!is_array($weeklyRecord) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$weekKey, $matches) !== 1) {
      continue;
    }
    $recordYear = (int)$matches[1];
    $recordWeek = (int)$matches[2];
    if (!rhValidWeek($recordYear, $recordWeek)) {
      continue;
    }
    $recordMonday = $now->setISODate($recordYear, $recordWeek, 1)->setTime(0, 0);
    if ($recordMonday > $currentMonday || ($latestRecordedMonday !== null && $recordMonday <= $latestRecordedMonday)) {
      continue;
    }
    $selectedYear = $recordYear;
    $selectedWeek = $recordWeek;
    $savedData = $weeklyRecord;
    $latestRecordedMonday = $recordMonday;
  }
}
$config = rhMergeData($config, $savedData);
$config['periodo'] = 'Semana ' . $selectedWeek . ' · ' . $selectedYear;
$config['actualizado'] = $savedData !== [] ? (string)($savedData['actualizado'] ?? 'Sin fecha') : 'Sin captura';
$weekRangeLabel = rhWeekRangeLabel($selectedYear, $selectedWeek);
$registeredWeeks = array_slice(rhRegisteredWeeks($weeklyRecords), 0, 8);
$selectedMonday = $now->setISODate($selectedYear, $selectedWeek, 1)->setTime(0, 0);
$selectedMonth = $selectedMonday->format('Y-m');
$monthlyAbsences = 0.0;
$hasMonthlyAbsenceData = false;
$latestMonthlyHeadcount = null;
$latestMonthlyDate = null;
foreach ($weeklyRecords as $weekKey => $weeklyRecord) {
  if (!is_array($weeklyRecord) || preg_match('/^(\d{4})-W(\d{2})$/', (string)$weekKey, $matches) !== 1) {
    continue;
  }
  $recordYear = (int)$matches[1];
  $recordWeek = (int)$matches[2];
  if (!rhValidWeek($recordYear, $recordWeek)) {
    continue;
  }
  $recordMonday = $now->setISODate($recordYear, $recordWeek, 1)->setTime(0, 0);
  if ($recordMonday->format('Y-m') !== $selectedMonth || $recordMonday > $selectedMonday) {
    continue;
  }
  $absenceCount = $weeklyRecord['indicadores']['ausentismo_faltas'] ?? null;
  if (is_numeric($absenceCount)) {
    $monthlyAbsences += (float)$absenceCount;
    $hasMonthlyAbsenceData = true;
  }
  $headcount = $weeklyRecord['personal']['total']['value'] ?? null;
  if (is_numeric($headcount) && ($latestMonthlyDate === null || $recordMonday > $latestMonthlyDate)) {
    $latestMonthlyHeadcount = (float)$headcount;
    $latestMonthlyDate = $recordMonday;
  }
}
$selectedHeadcount = $config['personal']['total']['value'] ?? null;
$absenceDenominator = is_numeric($selectedHeadcount) && (float)$selectedHeadcount > 0
  ? (float)$selectedHeadcount
  : $latestMonthlyHeadcount;
$config['indicadores']['ausentismo'] = $hasMonthlyAbsenceData && is_numeric($absenceDenominator) && (float)$absenceDenominator > 0
  ? ($monthlyAbsences / (float)$absenceDenominator) * 100
  : null;
$config['indicadores']['ausentismo_faltas_acumuladas'] = $hasMonthlyAbsenceData ? $monthlyAbsences : null;
$captureMode = isset($_GET['capture']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$number = static fn($value): string => is_numeric($value) ? number_format((float)$value, 0, '.', ',') : '—';
$percent = static fn($value): string => is_numeric($value) ? number_format((float)$value, 1, '.', ',') . '%' : '—';
$genderPercent = static function ($count, $total, $otherCount = null) use ($percent): string {
  if (!is_numeric($count)) {
    return '—';
  }
  $denominator = is_numeric($total) && (float)$total > 0
    ? (float)$total
    : ((float)$count + (is_numeric($otherCount) ? (float)$otherCount : 0.0));
  return $denominator > 0 ? $percent(((float)$count / $denominator) * 100) : '—';
};
$money = static fn($value): string => is_numeric($value) ? '$' . number_format((float)$value, 0, '.', ',') : '—';
$personnel = (array)($config['personal'] ?? []);
$genderTotal = (array)($config['genero_total'] ?? []);
$vacancies = (array)($config['vacantes'] ?? []);
$indicators = (array)($config['indicadores'] ?? []);
$blacklist = (array)($config['lista_negra'] ?? []);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $e($config['titulo'] ?? 'Reporte General de RH') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root {
      --ink: #0f172a;
      --muted: #64748b;
      --line: #dbe3ee;
      --soft: #f8fafc;
      --accent: #2563eb;
      --accent-dark: #1d4ed8;
      --accent-soft: #dbeafe;
      --navy: #334155;
      --danger: #c94436;
      --warning: #facc15;
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: #eef3f8; color: var(--ink); font-family: Inter, system-ui, sans-serif; }
    .rh-report { width: min(1180px, calc(100% - 32px)); margin: 18px auto 36px; }
    .back-link { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 12px; padding: 9px 13px; border: 1px solid var(--line); border-radius: 999px; color: #334155; background: #fff; font-size: 13px; font-weight: 800; text-decoration: none; }
    .rh-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 20px 24px; border: 1px solid var(--line); border-radius: 20px; background: #fff; box-shadow: 0 12px 30px rgba(15, 23, 42, .07); }
    .rh-title-wrap { display: flex; align-items: center; gap: 15px; }
    .rh-title-icon { display: grid; place-items: center; width: 54px; height: 54px; border-radius: 16px; color: #fff; background: linear-gradient(145deg, var(--accent), var(--accent-dark)); font-size: 23px; }
    h1 { margin: 0; font-size: clamp(26px, 4vw, 40px); line-height: 1; font-weight: 900; }
    .rh-subtitle { margin: 7px 0 0; color: var(--muted); font-size: 14px; font-weight: 700; }
    .rh-meta { text-align: right; }
    .rh-period { display: block; font-size: 14px; font-weight: 900; }
    .rh-week-range { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 800; }
    .rh-updated { display: block; margin-top: 5px; color: var(--muted); font-size: 12px; font-weight: 700; }
    .capture-status { display: inline-flex; margin-top: 5px; padding: 4px 8px; border-radius: 999px; font-size: 10px; font-weight: 900; text-transform: uppercase; }
    .capture-status.is-ready { color: #166534; background: #dcfce7; }
    .capture-status.is-pending { color: #92400e; background: #fef3c7; }
    .week-picker { display: flex; align-items: end; justify-content: flex-end; gap: 6px; margin-top: 9px; }
    .week-picker label { display: flex; flex-direction: column; gap: 3px; color: var(--muted); font-size: 9px; font-weight: 900; text-align: left; text-transform: uppercase; }
    .week-picker input { width: 72px; height: 30px; padding: 4px 7px; border: 1px solid var(--line); border-radius: 8px; font: inherit; font-size: 12px; font-weight: 800; }
    .week-picker .year-input { width: 78px; }
    .week-picker button { height: 30px; padding: 0 10px; border: 0; border-radius: 8px; color: #fff; background: var(--accent); font: inherit; font-size: 11px; font-weight: 900; cursor: pointer; }
    .week-history { display: flex; align-items: center; gap: 6px; margin-top: 8px; padding: 8px 10px; overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; background: #fff; }
    .week-history > span { margin-right: 3px; color: var(--muted); font-size: 10px; font-weight: 900; text-transform: uppercase; white-space: nowrap; }
    .week-history a { padding: 5px 8px; border: 1px solid var(--line); border-radius: 999px; color: #475569; background: var(--soft); font-size: 10px; font-weight: 900; text-decoration: none; white-space: nowrap; }
    .week-history a.is-active { color: #fff; border-color: var(--accent); background: var(--accent); }
    .section { margin-top: 16px; }
    .section-heading { display: flex; align-items: center; gap: 9px; margin: 0 0 7px; color: #334155; font-size: 18px; font-weight: 900; text-transform: uppercase; }
    .section-heading i { color: var(--accent); }
    .card { min-width: 0; border: 2px solid var(--line); border-radius: 16px; background: #fff; overflow: hidden; }
    .workforce-grid { display: grid; grid-template-columns: 1.15fr repeat(3, minmax(0, 1fr)); gap: 10px; }
    .total-card { display: grid; grid-template-columns: 1fr 1.25fr; min-height: 196px; border-color: var(--accent); }
    .total-main { display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 18px; color: #fff; text-align: center; background: linear-gradient(145deg, var(--accent), var(--accent-dark)); }
    .metric-icon { margin-bottom: 8px; font-size: 23px; opacity: .9; }
    .metric-label { font-size: 14px; line-height: 1.15; font-weight: 900; text-transform: uppercase; }
    .metric-value { margin-top: 8px; font-size: clamp(40px, 5vw, 58px); line-height: .95; font-weight: 900; }
    .gender-summary { display: grid; grid-template-rows: 1fr 1fr; }
    .gender-item { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px; }
    .gender-item + .gender-item { border-top: 1px solid var(--line); }
    .gender-name { display: flex; align-items: center; gap: 7px; color: var(--muted); font-size: 12px; font-weight: 900; text-transform: uppercase; }
    .gender-name i { color: var(--accent); }
    .gender-value { font-size: 25px; font-weight: 900; }
    .group-card { display: flex; flex-direction: column; min-height: 196px; }
    .group-main { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex: 1; padding: 18px; color: #fff; background: var(--navy); }
    .group-main .metric-value { margin: 0; font-size: 43px; }
    .group-genders { display: grid; grid-template-columns: 1fr 1fr; border-top: 1px solid var(--line); }
    .group-genders .gender-item { display: block; padding: 12px 14px; }
    .group-genders .gender-item + .gender-item { border-top: 0; border-left: 1px solid var(--line); }
    .group-genders .gender-value { display: block; margin-top: 5px; font-size: 22px; }
    .vacancy-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .simple-card { display: grid; grid-template-columns: 74px 1fr auto; align-items: center; gap: 14px; min-height: 106px; padding: 16px 20px; }
    .simple-icon { display: grid; place-items: center; width: 62px; height: 62px; border-radius: 15px; color: var(--accent); background: var(--accent-soft); font-size: 25px; }
    .simple-card.is-critical { border-color: #efb2ad; }
    .simple-card.is-critical .simple-icon { color: var(--danger); background: #fee2e2; }
    .simple-title { color: var(--muted); font-size: 13px; font-weight: 900; text-transform: uppercase; }
    .simple-caption { margin-top: 5px; color: #94a3b8; font-size: 12px; font-weight: 700; }
    .simple-value { font-size: 42px; line-height: 1; font-weight: 900; white-space: nowrap; }
    .indicator-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .indicator-card { min-height: 134px; padding: 17px; }
    .indicator-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .indicator-head span { color: var(--muted); font-size: 12px; line-height: 1.2; font-weight: 900; text-transform: uppercase; }
    .indicator-head i { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; color: var(--accent); background: var(--accent-soft); }
    .indicator-value { margin-top: 20px; font-size: clamp(30px, 4vw, 42px); line-height: 1; font-weight: 900; white-space: nowrap; }
    .indicator-unit { margin-left: 4px; color: var(--muted); font-size: 13px; font-weight: 800; }
    .indicator-note { display: block; margin-top: 7px; color: var(--muted); font-size: 11px; font-weight: 800; }
    .blacklist-card { min-height: 128px; }
    .blacklist-empty { display: flex; min-height: 126px; align-items: center; justify-content: center; gap: 12px; padding: 20px; color: var(--muted); font-weight: 800; }
    .blacklist-empty i { color: #94a3b8; font-size: 27px; }
    .blacklist-table { width: 100%; border-collapse: collapse; }
    .blacklist-table th, .blacklist-table td { padding: 12px 16px; border-bottom: 1px solid var(--line); text-align: left; }
    .blacklist-table th { color: var(--muted); background: var(--soft); font-size: 11px; text-transform: uppercase; }
    .status-badge { display: inline-flex; padding: 5px 9px; border-radius: 999px; color: #991b1b; background: #fee2e2; font-size: 11px; font-weight: 900; }
    @media (max-width: 900px) {
      .workforce-grid { grid-template-columns: 1fr 1fr; }
      .total-card { grid-column: 1 / -1; }
      .indicator-grid { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 590px) {
      .rh-report { width: min(100% - 18px, 1180px); margin-top: 9px; }
      .rh-header { align-items: flex-start; padding: 16px; }
      .rh-meta { display: none; }
      .rh-title-icon { width: 45px; height: 45px; }
      .workforce-grid, .vacancy-grid, .indicator-grid { grid-template-columns: 1fr; }
      .total-card { grid-column: auto; grid-template-columns: 1fr; }
      .gender-summary { grid-template-columns: 1fr 1fr; grid-template-rows: auto; }
      .gender-item + .gender-item { border-top: 0; border-left: 1px solid var(--line); }
      .simple-card { grid-template-columns: 58px 1fr auto; padding: 14px; }
      .simple-icon { width: 52px; height: 52px; }
    }
    <?php if ($captureMode): ?>
    body { background: #fff; }
    .rh-report { width: 1120px; margin: 8px auto; }
    .back-link { display: none; }
    .rh-header { padding: 14px 18px; box-shadow: none; }
    .week-picker { display: none; }
    .week-history { display: none; }
    .rh-title-icon { width: 46px; height: 46px; }
    .section { margin-top: 10px; }
    .total-card, .group-card { min-height: 170px; }
    .simple-card { min-height: 92px; }
    .indicator-card { min-height: 112px; }
    .indicator-value { margin-top: 13px; }
    <?php endif; ?>
  </style>
</head>
<body>
  <main class="rh-report">
    <a class="back-link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Regresar al inicio</a>

    <header class="rh-header">
      <div class="rh-title-wrap">
        <span class="rh-title-icon"><i class="fa-solid fa-people-group"></i></span>
        <div>
          <h1><?= $e($config['titulo'] ?? 'Reporte General de RH') ?></h1>
          <p class="rh-subtitle"><?= $e($config['subtitulo'] ?? '') ?></p>
        </div>
      </div>
      <div class="rh-meta">
        <span class="rh-period"><?= $e($config['periodo'] ?? '') ?></span>
        <span class="rh-week-range"><?= $e($weekRangeLabel) ?></span>
        <span class="capture-status <?= $savedData !== [] ? 'is-ready' : 'is-pending' ?>"><?= $savedData !== [] ? 'Semana capturada' : 'Pendiente' ?></span>
        <span class="rh-updated"><?= ($config['actualizado'] ?? '') === 'Sin captura' ? 'Sin captura registrada' : 'Actualizado ' . $e($config['actualizado'] ?? '') ?></span>
        <form class="week-picker" method="get">
          <label>Semana<input type="number" name="semana" min="1" max="53" value="<?= $e($selectedWeek) ?>"></label>
          <label>Año<input class="year-input" type="number" name="anio" min="2020" max="2100" value="<?= $e($selectedYear) ?>"></label>
          <button type="submit">Consultar</button>
        </form>
      </div>
    </header>

    <?php if ($registeredWeeks !== []): ?>
      <nav class="week-history" aria-label="Semanas registradas">
        <span>Semanas registradas</span>
        <?php foreach ($registeredWeeks as $registeredWeek): ?>
          <a class="<?= (int)$registeredWeek['anio'] === $selectedYear && (int)$registeredWeek['semana'] === $selectedWeek ? 'is-active' : '' ?>" href="?anio=<?= $e($registeredWeek['anio']) ?>&amp;semana=<?= $e($registeredWeek['semana']) ?>">S<?= $e($registeredWeek['semana']) ?> · <?= $e($registeredWeek['anio']) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <section class="section">
      <h2 class="section-heading"><i class="fa-solid fa-users"></i> Personal</h2>
      <div class="workforce-grid">
        <article class="card total-card">
          <div class="total-main">
            <i class="fa-solid <?= $e($personnel['total']['icon'] ?? 'fa-users') ?> metric-icon"></i>
            <span class="metric-label"><?= $e($personnel['total']['label'] ?? 'Personal total') ?></span>
            <strong class="metric-value"><?= $number($personnel['total']['value'] ?? null) ?></strong>
          </div>
          <div class="gender-summary">
            <div class="gender-item">
              <span class="gender-name"><i class="fa-solid fa-person"></i> Hombres</span>
              <strong class="gender-value"><?= $genderPercent($genderTotal['hombres'] ?? null, $personnel['total']['value'] ?? null, $genderTotal['mujeres'] ?? null) ?></strong>
            </div>
            <div class="gender-item">
              <span class="gender-name"><i class="fa-solid fa-person-dress"></i> Mujeres</span>
              <strong class="gender-value"><?= $genderPercent($genderTotal['mujeres'] ?? null, $personnel['total']['value'] ?? null, $genderTotal['hombres'] ?? null) ?></strong>
            </div>
          </div>
        </article>

        <?php foreach (['administrativo', 'operativo_directo', 'operativo_indirecto'] as $groupKey): $group = (array)($personnel[$groupKey] ?? []); ?>
          <article class="card group-card">
            <div class="group-main">
              <div>
                <i class="fa-solid <?= $e($group['icon'] ?? 'fa-user') ?> metric-icon"></i>
                <div class="metric-label"><?= $e($group['label'] ?? $groupKey) ?></div>
              </div>
              <strong class="metric-value"><?= $number($group['value'] ?? null) ?></strong>
            </div>
            <div class="group-genders">
              <div class="gender-item">
                <span class="gender-name">Hombres</span>
                <strong class="gender-value"><?= $genderPercent($group['hombres'] ?? null, $group['value'] ?? null, $group['mujeres'] ?? null) ?></strong>
              </div>
              <div class="gender-item">
                <span class="gender-name">Mujeres</span>
                <strong class="gender-value"><?= $genderPercent($group['mujeres'] ?? null, $group['value'] ?? null, $group['hombres'] ?? null) ?></strong>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="section">
      <h2 class="section-heading"><i class="fa-solid fa-user-plus"></i> Vacantes</h2>
      <div class="vacancy-grid">
        <article class="card simple-card">
          <span class="simple-icon"><i class="fa-solid fa-briefcase"></i></span>
          <div><div class="simple-title">Vacantes totales</div><div class="simple-caption">Posiciones abiertas</div></div>
          <strong class="simple-value"><?= $number($vacancies['total'] ?? null) ?></strong>
        </article>
        <article class="card simple-card is-critical">
          <span class="simple-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
          <div><div class="simple-title">Vacantes críticas</div><div class="simple-caption">Requieren atención prioritaria</div></div>
          <strong class="simple-value"><?= $number($vacancies['criticas'] ?? null) ?></strong>
        </article>
      </div>
    </section>

    <section class="section">
      <h2 class="section-heading"><i class="fa-solid fa-chart-simple"></i> Indicadores</h2>
      <div class="indicator-grid">
        <article class="card indicator-card"><div class="indicator-head"><span>Tiempo extra</span><i class="fa-solid fa-clock"></i></div><div class="indicator-value"><?= $number($indicators['tiempo_extra_horas'] ?? null) ?><small class="indicator-unit">hrs</small></div></article>
        <article class="card indicator-card"><div class="indicator-head"><span>Costo tiempo extra</span><i class="fa-solid fa-money-bill-wave"></i></div><div class="indicator-value"><?= $money($indicators['tiempo_extra_costo'] ?? null) ?></div></article>
        <article class="card indicator-card"><div class="indicator-head"><span>Ausentismo mensual</span><i class="fa-solid fa-user-clock"></i></div><div class="indicator-value"><?= $percent($indicators['ausentismo'] ?? null) ?></div><small class="indicator-note"><?php if (is_numeric($indicators['ausentismo_faltas_acumuladas'] ?? null) && is_numeric($absenceDenominator)): ?><?= $number($indicators['ausentismo_faltas_acumuladas']) ?> faltas / <?= $number($absenceDenominator) ?> empleados<?php else: ?>Sin faltas capturadas<?php endif; ?></small></article>
        <article class="card indicator-card"><div class="indicator-head"><span>Rotación</span><i class="fa-solid fa-people-arrows-left-right"></i></div><div class="indicator-value"><?= $percent($indicators['rotacion'] ?? null) ?></div></article>
      </div>
    </section>

    <section class="section">
      <h2 class="section-heading"><i class="fa-solid fa-user-slash"></i> Lista negra</h2>
      <div class="card blacklist-card">
        <?php if ($blacklist === []): ?>
          <div class="blacklist-empty"><i class="fa-regular fa-folder-open"></i><span>Sin registros para mostrar</span></div>
        <?php else: ?>
          <table class="blacklist-table">
            <thead><tr><th>Nombre</th><th>Motivo</th><th>Fecha</th><th>Estatus</th></tr></thead>
            <tbody>
              <?php foreach ($blacklist as $row): ?>
                <tr><td><?= $e($row['nombre'] ?? '—') ?></td><td><?= $e($row['motivo'] ?? '—') ?></td><td><?= $e($row['fecha'] ?? '—') ?></td><td><span class="status-badge"><?= $e($row['estatus'] ?? 'Registrado') ?></span></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </section>
  </main>
</body>
</html>
