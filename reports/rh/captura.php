<?php

declare(strict_types=1);

session_start();

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
$now = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
$defaultWeekDate = $now;
$selectedYear = filter_input(INPUT_GET, 'anio', FILTER_VALIDATE_INT);
$selectedWeek = filter_input(INPUT_GET, 'semana', FILTER_VALIDATE_INT);
$selectedYear = is_int($selectedYear) ? $selectedYear : (int)$defaultWeekDate->format('o');
$selectedWeek = is_int($selectedWeek) ? $selectedWeek : (int)$defaultWeekDate->format('W');
if (!rhValidWeek($selectedYear, $selectedWeek)) {
  $selectedYear = (int)$defaultWeekDate->format('o');
  $selectedWeek = (int)$defaultWeekDate->format('W');
}
$saved = rhLoadData($selectedYear, $selectedWeek);
$recordExists = $saved !== [];
$weekRangeLabel = rhWeekRangeLabel($selectedYear, $selectedWeek);
$registeredWeeks = array_slice(rhRegisteredWeeks(rhLoadWeeklyRecords()), 0, 12);
$values = rhMergeData($config, $saved);
$error = '';
$success = isset($_GET['guardado']);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

if (!isset($_SESSION['rh_csrf']) || !is_string($_SESSION['rh_csrf'])) {
  $_SESSION['rh_csrf'] = bin2hex(random_bytes(24));
}

$cleanNumber = static function ($value, float $min = 0, ?float $max = null): ?float {
  if ($value === null || trim((string)$value) === '') {
    return null;
  }
  $normalized = str_replace(',', '', trim((string)$value));
  if (!is_numeric($normalized)) {
    throw new InvalidArgumentException('Todos los valores deben ser numéricos.');
  }
  $number = (float)$normalized;
  if ($number < $min || ($max !== null && $number > $max)) {
    throw new InvalidArgumentException($max === null
      ? 'Los valores no pueden ser negativos.'
      : 'Los porcentajes deben estar entre 0 y 100.');
  }
  return $number;
};
$cleanCount = static function ($value) use ($cleanNumber): ?float {
  $number = $cleanNumber($value);
  if ($number !== null && floor($number) !== $number) {
    throw new InvalidArgumentException('Las cantidades de personas deben ser números enteros.');
  }
  return $number;
};
$cleanText = static function ($value, int $maxLength = 120): string {
  $text = trim((string)$value);
  return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength) : substr($text, 0, $maxLength);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)$_SESSION['rh_csrf'], $token)) {
      throw new RuntimeException('La sesión del formulario expiró. Recarga la página e intenta nuevamente.');
    }
    $postYear = filter_var($_POST['anio'] ?? null, FILTER_VALIDATE_INT);
    $postWeek = filter_var($_POST['semana'] ?? null, FILTER_VALIDATE_INT);
    if (!is_int($postYear) || !is_int($postWeek) || !rhValidWeek($postYear, $postWeek)) {
      throw new InvalidArgumentException('La semana seleccionada no es válida.');
    }
    $selectedYear = $postYear;
    $selectedWeek = $postWeek;

    $blacklist = [];
    $names = (array)($_POST['lista_nombre'] ?? []);
    $reasons = (array)($_POST['lista_motivo'] ?? []);
    $dates = (array)($_POST['lista_fecha'] ?? []);
    $statuses = (array)($_POST['lista_estatus'] ?? []);
    $rowCount = max(count($names), count($reasons), count($dates), count($statuses));
    for ($index = 0; $index < $rowCount; $index++) {
      $name = $cleanText($names[$index] ?? '', 100);
      $reason = $cleanText($reasons[$index] ?? '', 180);
      $date = $cleanText($dates[$index] ?? '', 10);
      $status = $cleanText($statuses[$index] ?? 'Registrado', 40);
      if ($name === '' && $reason === '' && $date === '') {
        continue;
      }
      $blacklist[] = [
        'nombre' => $name,
        'motivo' => $reason,
        'fecha' => $date,
        'estatus' => $status !== '' ? $status : 'Registrado',
      ];
    }

    $data = [
      'anio' => $selectedYear,
      'semana' => $selectedWeek,
      'periodo' => 'Semana ' . $selectedWeek . ' · ' . $selectedYear,
      'actualizado' => $now->format('d/m/Y H:i'),
      'personal' => [
        'total' => ['value' => $cleanCount($_POST['personal_total'] ?? null)],
        'administrativo' => [
          'value' => $cleanCount($_POST['administrativo_total'] ?? null),
          'hombres' => $cleanCount($_POST['administrativo_hombres'] ?? null),
          'mujeres' => $cleanCount($_POST['administrativo_mujeres'] ?? null),
        ],
        'operativo_directo' => [
          'value' => $cleanCount($_POST['operativo_directo_total'] ?? null),
          'hombres' => $cleanCount($_POST['operativo_directo_hombres'] ?? null),
          'mujeres' => $cleanCount($_POST['operativo_directo_mujeres'] ?? null),
        ],
        'operativo_indirecto' => [
          'value' => $cleanCount($_POST['operativo_indirecto_total'] ?? null),
          'hombres' => $cleanCount($_POST['operativo_indirecto_hombres'] ?? null),
          'mujeres' => $cleanCount($_POST['operativo_indirecto_mujeres'] ?? null),
        ],
      ],
      'genero_total' => [
        'hombres' => $cleanCount($_POST['personal_hombres'] ?? null),
        'mujeres' => $cleanCount($_POST['personal_mujeres'] ?? null),
      ],
      'vacantes' => [
        'total' => $cleanCount($_POST['vacantes_total'] ?? null),
        'criticas' => $cleanCount($_POST['vacantes_criticas'] ?? null),
      ],
      'indicadores' => [
        'tiempo_extra_horas' => $cleanNumber($_POST['tiempo_extra_horas'] ?? null),
        'tiempo_extra_costo' => $cleanNumber($_POST['tiempo_extra_costo'] ?? null),
        'ausentismo_faltas' => $cleanCount($_POST['ausentismo_faltas'] ?? null),
        'rotacion' => $cleanNumber($_POST['rotacion'] ?? null, 0, 100),
      ],
      'lista_negra' => $blacklist,
    ];

    $validateGenderTotals = static function (string $label, array $group): void {
      $total = $group['value'] ?? null;
      $men = $group['hombres'] ?? null;
      $women = $group['mujeres'] ?? null;
      if (is_numeric($total) && is_numeric($men) && is_numeric($women) && (float)$total !== (float)$men + (float)$women) {
        throw new InvalidArgumentException("En {$label}, hombres más mujeres debe coincidir con el total.");
      }
    };
    $validateGenderTotals('personal total', [
      'value' => $data['personal']['total']['value'],
      'hombres' => $data['genero_total']['hombres'],
      'mujeres' => $data['genero_total']['mujeres'],
    ]);
    $validateGenderTotals('personal administrativo', $data['personal']['administrativo']);
    $validateGenderTotals('operativo directo', $data['personal']['operativo_directo']);
    $validateGenderTotals('operativo indirecto', $data['personal']['operativo_indirecto']);
    $personnelTotal = $data['personal']['total']['value'];
    $administrativeTotal = $data['personal']['administrativo']['value'];
    $directTotal = $data['personal']['operativo_directo']['value'];
    $indirectTotal = $data['personal']['operativo_indirecto']['value'];
    if (is_numeric($personnelTotal) && is_numeric($administrativeTotal) && is_numeric($directTotal) && is_numeric($indirectTotal)
      && (float)$personnelTotal !== (float)$administrativeTotal + (float)$directTotal + (float)$indirectTotal) {
      throw new InvalidArgumentException('Administrativo + operativo directo + operativo indirecto debe coincidir con el personal total.');
    }
    rhSaveData($selectedYear, $selectedWeek, $data);
    $_SESSION['rh_csrf'] = bin2hex(random_bytes(24));
    header('Location: captura.php?' . http_build_query([
      'anio' => $selectedYear,
      'semana' => $selectedWeek,
      'guardado' => 1,
    ]));
    exit;
  } catch (Throwable $exception) {
    $error = $exception->getMessage();
    if (isset($data) && is_array($data)) {
      $values = rhMergeData($config, $data);
    }
  }
}

$field = static function (array $source, string $key): string {
  $value = $source[$key] ?? null;
  return is_numeric($value) ? (string)$value : '';
};
$personal = (array)($values['personal'] ?? []);
$personalTotal = (array)($personal['total'] ?? []);
$administrative = (array)($personal['administrativo'] ?? []);
$directOperative = (array)($personal['operativo_directo'] ?? []);
$indirectOperative = (array)($personal['operativo_indirecto'] ?? []);
$genderTotal = (array)($values['genero_total'] ?? []);
$vacancies = (array)($values['vacantes'] ?? []);
$indicators = (array)($values['indicadores'] ?? []);
$blacklist = (array)($values['lista_negra'] ?? []);
if ($blacklist === []) {
  $blacklist = [['nombre' => '', 'motivo' => '', 'fecha' => '', 'estatus' => 'Registrado']];
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Captura | Reporte General de RH</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    :root { --ink:#0f172a; --muted:#64748b; --line:#dbe3ee; --accent:#2563eb; --accent-dark:#1d4ed8; --soft:#f8fafc; }
    * { box-sizing:border-box; }
    body { margin:0; color:var(--ink); background:#eef3f8; font-family:Inter,system-ui,sans-serif; }
    .page { width:min(1040px,calc(100% - 28px)); margin:20px auto 44px; }
    .topbar { display:flex; justify-content:space-between; align-items:center; gap:14px; margin-bottom:12px; }
    .link { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border:1px solid var(--line); border-radius:999px; color:#334155; background:#fff; font-size:13px; font-weight:800; text-decoration:none; }
    .header { display:flex; align-items:center; gap:15px; padding:20px 22px; border:1px solid var(--line); border-radius:19px; background:#fff; box-shadow:0 12px 30px rgba(15,23,42,.07); }
    .header-icon { display:grid; place-items:center; width:52px; height:52px; border-radius:15px; color:#fff; background:linear-gradient(145deg,var(--accent),var(--accent-dark)); font-size:22px; }
    h1 { margin:0; font-size:clamp(25px,4vw,38px); font-weight:900; }
    .header p { margin:5px 0 0; color:var(--muted); font-size:13px; font-weight:700; }
    .notice { margin-top:12px; padding:13px 15px; border-radius:12px; font-size:13px; font-weight:800; }
    .notice.success { color:#166534; border:1px solid #86efac; background:#dcfce7; }
    .notice.error { color:#991b1b; border:1px solid #fca5a5; background:#fee2e2; }
    .week-selector { display:flex; align-items:end; gap:10px; margin-top:12px; padding:14px 16px; border:1px solid var(--line); border-radius:15px; background:#fff; }
    .week-selector .field { width:150px; }
    .week-selector .registered-field { width:190px; }
    .load-week { min-height:43px; padding:10px 15px; border:0; border-radius:10px; color:#fff; background:#334155; font:inherit; font-size:13px; font-weight:900; cursor:pointer; }
    .week-context { margin-left:auto; color:var(--muted); font-size:13px; font-weight:800; }
    .week-context strong { color:var(--accent); }
    form { margin-top:14px; }
    .panel { margin-top:12px; padding:18px; border:1px solid var(--line); border-radius:17px; background:#fff; }
    .panel h2 { display:flex; align-items:center; gap:9px; margin:0 0 14px; color:#334155; font-size:17px; font-weight:900; text-transform:uppercase; }
    .panel h2 i { color:var(--accent); }
    .grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:13px; }
    .grid.two { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .grid.personnel-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .subpanel { padding:14px; border:1px solid var(--line); border-radius:14px; background:var(--soft); }
    .subpanel h3 { margin:0 0 12px; font-size:13px; font-weight:900; text-transform:uppercase; }
    .fields { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field.span-all { grid-column:1/-1; }
    label { color:#475569; font-size:12px; font-weight:800; }
    input, select { width:100%; min-height:43px; padding:10px 11px; border:1px solid #cbd5e1; border-radius:10px; color:var(--ink); background:#fff; font:inherit; font-size:14px; outline:none; }
    input:focus, select:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(37,99,235,.12); }
    .input-suffix { position:relative; }
    .input-suffix input { padding-right:34px; }
    .input-suffix span { position:absolute; right:12px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:12px; font-weight:900; pointer-events:none; }
    .blacklist-row { display:grid; grid-template-columns:1.25fr 1.7fr .8fr .8fr auto; gap:9px; align-items:end; padding:10px; border:1px solid var(--line); border-radius:12px; background:var(--soft); }
    .blacklist-row + .blacklist-row { margin-top:9px; }
    .remove-row { width:42px; height:42px; border:0; border-radius:10px; color:#991b1b; background:#fee2e2; cursor:pointer; }
    .add-row { margin-top:10px; padding:9px 13px; border:1px dashed var(--accent); border-radius:10px; color:var(--accent); background:#fff; font:inherit; font-size:13px; font-weight:900; cursor:pointer; }
    .actions { position:sticky; bottom:10px; display:flex; justify-content:flex-end; gap:10px; margin-top:14px; padding:13px; border:1px solid var(--line); border-radius:15px; background:rgba(255,255,255,.96); box-shadow:0 10px 28px rgba(15,23,42,.12); }
    .save { padding:12px 19px; border:0; border-radius:999px; color:#fff; background:linear-gradient(145deg,var(--accent),var(--accent-dark)); font:inherit; font-size:14px; font-weight:900; cursor:pointer; }
    @media(max-width:780px) { .grid,.grid.two { grid-template-columns:1fr; } .blacklist-row { grid-template-columns:1fr 1fr; } .blacklist-row .wide { grid-column:1/-1; } .remove-row { width:100%; } }
    @media(max-width:480px) { .topbar { align-items:stretch; flex-direction:column; } .link { justify-content:center; } .header { padding:16px; } .fields,.blacklist-row { grid-template-columns:1fr; } .week-selector { align-items:stretch; flex-direction:column; } .week-selector .field { width:100%; } .week-context { margin-left:0; } }
  </style>
</head>
<body>
  <main class="page">
    <div class="topbar">
      <a class="link" href="../index.php"><i class="fa-solid fa-arrow-left"></i> Centro de reportes</a>
      <a class="link" href="index.php?anio=<?= $e($selectedYear) ?>&amp;semana=<?= $e($selectedWeek) ?>" target="_blank"><i class="fa-solid fa-chart-column"></i> Ver reporte</a>
    </div>
    <header class="header">
      <span class="header-icon"><i class="fa-solid fa-pen-to-square"></i></span>
      <div><h1>Captura del Reporte de RH</h1><p>Los datos guardados reemplazarán la información visible en el reporte.</p></div>
    </header>

    <form class="week-selector" method="get">
      <div class="field"><label for="semana_selector">Semana del año</label><input id="semana_selector" type="number" name="semana" min="1" max="53" required value="<?= $e($selectedWeek) ?>"></div>
      <div class="field"><label for="anio_selector">Año</label><input id="anio_selector" type="number" name="anio" min="2020" max="2100" required value="<?= $e($selectedYear) ?>"></div>
      <button class="load-week" type="submit"><i class="fa-solid fa-folder-open"></i> Cargar semana</button>
      <?php if ($registeredWeeks !== []): ?>
        <div class="field registered-field"><label for="semana_registrada">Semanas registradas</label><select id="semana_registrada" onchange="if(this.value) window.location.href=this.value"><option value="">Seleccionar</option><?php foreach ($registeredWeeks as $registeredWeek): ?><option value="?anio=<?= $e($registeredWeek['anio']) ?>&amp;semana=<?= $e($registeredWeek['semana']) ?>" <?= (int)$registeredWeek['anio'] === $selectedYear && (int)$registeredWeek['semana'] === $selectedWeek ? 'selected' : '' ?>>Semana <?= $e($registeredWeek['semana']) ?> · <?= $e($registeredWeek['anio']) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="week-context"><?= $recordExists ? 'Editando registro existente' : 'Nuevo registro' ?>: <strong>semana <?= $e($selectedWeek) ?> de <?= $e($selectedYear) ?></strong><br><?= $e($weekRangeLabel) ?></div>
    </form>

    <?php if ($success): ?><div class="notice success"><i class="fa-solid fa-circle-check"></i> Semana <?= $e($selectedWeek) ?> guardada correctamente. Si vuelves a guardarla, se actualizará el mismo registro.</div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><i class="fa-solid fa-triangle-exclamation"></i> <?= $e($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= $e($_SESSION['rh_csrf']) ?>">
      <input type="hidden" name="anio" value="<?= $e($selectedYear) ?>">
      <input type="hidden" name="semana" value="<?= $e($selectedWeek) ?>">

      <section class="panel">
        <h2><i class="fa-solid fa-users"></i> Personal</h2>
        <div class="grid personnel-grid">
          <div class="subpanel">
            <h3>Personal total</h3>
            <div class="fields">
              <div class="field"><label>Total</label><input type="number" min="0" step="1" name="personal_total" value="<?= $e($field($personalTotal, 'value')) ?>"></div>
              <div class="field"><label>Hombres</label><input type="number" min="0" step="1" name="personal_hombres" value="<?= $e($field($genderTotal, 'hombres')) ?>"></div>
              <div class="field"><label>Mujeres</label><input type="number" min="0" step="1" name="personal_mujeres" value="<?= $e($field($genderTotal, 'mujeres')) ?>"></div>
            </div>
          </div>
          <div class="subpanel">
            <h3>Administrativo</h3>
            <div class="fields">
              <div class="field"><label>Total</label><input type="number" min="0" step="1" name="administrativo_total" value="<?= $e($field($administrative, 'value')) ?>"></div>
              <div class="field"><label>Hombres</label><input type="number" min="0" step="1" name="administrativo_hombres" value="<?= $e($field($administrative, 'hombres')) ?>"></div>
              <div class="field"><label>Mujeres</label><input type="number" min="0" step="1" name="administrativo_mujeres" value="<?= $e($field($administrative, 'mujeres')) ?>"></div>
            </div>
          </div>
          <div class="subpanel">
            <h3>Operativo directo</h3>
            <div class="fields">
              <div class="field"><label>Total</label><input type="number" min="0" step="1" name="operativo_directo_total" value="<?= $e($field($directOperative, 'value')) ?>"></div>
              <div class="field"><label>Hombres</label><input type="number" min="0" step="1" name="operativo_directo_hombres" value="<?= $e($field($directOperative, 'hombres')) ?>"></div>
              <div class="field"><label>Mujeres</label><input type="number" min="0" step="1" name="operativo_directo_mujeres" value="<?= $e($field($directOperative, 'mujeres')) ?>"></div>
            </div>
          </div>
          <div class="subpanel">
            <h3>Operativo indirecto</h3>
            <div class="fields">
              <div class="field"><label>Total</label><input type="number" min="0" step="1" name="operativo_indirecto_total" value="<?= $e($field($indirectOperative, 'value')) ?>"></div>
              <div class="field"><label>Hombres</label><input type="number" min="0" step="1" name="operativo_indirecto_hombres" value="<?= $e($field($indirectOperative, 'hombres')) ?>"></div>
              <div class="field"><label>Mujeres</label><input type="number" min="0" step="1" name="operativo_indirecto_mujeres" value="<?= $e($field($indirectOperative, 'mujeres')) ?>"></div>
            </div>
          </div>
        </div>
      </section>

      <section class="panel">
        <h2><i class="fa-solid fa-user-plus"></i> Vacantes</h2>
        <div class="grid two">
          <div class="field"><label>Vacantes totales</label><input type="number" min="0" step="1" name="vacantes_total" value="<?= $e($field($vacancies, 'total')) ?>"></div>
          <div class="field"><label>Vacantes críticas</label><input type="number" min="0" step="1" name="vacantes_criticas" value="<?= $e($field($vacancies, 'criticas')) ?>"></div>
        </div>
      </section>

      <section class="panel">
        <h2><i class="fa-solid fa-chart-simple"></i> Indicadores</h2>
        <div class="grid two">
          <div class="field"><label>Tiempo extra</label><div class="input-suffix"><input type="number" min="0" step="0.01" name="tiempo_extra_horas" value="<?= $e($field($indicators, 'tiempo_extra_horas')) ?>"><span>hrs</span></div></div>
          <div class="field"><label>Costo de tiempo extra</label><div class="input-suffix"><input type="number" min="0" step="0.01" name="tiempo_extra_costo" value="<?= $e($field($indicators, 'tiempo_extra_costo')) ?>"><span>$</span></div></div>
          <div class="field"><label>Faltas de la semana</label><input type="number" min="0" step="1" name="ausentismo_faltas" value="<?= $e($field($indicators, 'ausentismo_faltas')) ?>"></div>
          <div class="field"><label>Rotación</label><div class="input-suffix"><input type="number" min="0" max="100" step="0.1" name="rotacion" value="<?= $e($field($indicators, 'rotacion')) ?>"><span>%</span></div></div>
        </div>
      </section>

      <section class="panel">
        <h2><i class="fa-solid fa-user-slash"></i> Lista negra</h2>
        <div id="blacklistRows">
          <?php foreach ($blacklist as $row): ?>
            <div class="blacklist-row">
              <div class="field wide"><label>Nombre</label><input name="lista_nombre[]" maxlength="100" value="<?= $e($row['nombre'] ?? '') ?>"></div>
              <div class="field wide"><label>Motivo</label><input name="lista_motivo[]" maxlength="180" value="<?= $e($row['motivo'] ?? '') ?>"></div>
              <div class="field"><label>Fecha</label><input type="date" name="lista_fecha[]" value="<?= $e($row['fecha'] ?? '') ?>"></div>
              <div class="field"><label>Estatus</label><select name="lista_estatus[]"><option value="Registrado" <?= ($row['estatus'] ?? '') === 'Registrado' ? 'selected' : '' ?>>Registrado</option><option value="En revisión" <?= ($row['estatus'] ?? '') === 'En revisión' ? 'selected' : '' ?>>En revisión</option><option value="Liberado" <?= ($row['estatus'] ?? '') === 'Liberado' ? 'selected' : '' ?>>Liberado</option></select></div>
              <button class="remove-row" type="button" title="Eliminar renglón"><i class="fa-solid fa-trash"></i></button>
            </div>
          <?php endforeach; ?>
        </div>
        <button class="add-row" id="addBlacklistRow" type="button"><i class="fa-solid fa-plus"></i> Agregar persona</button>
      </section>

      <div class="actions"><a class="link" href="index.php?anio=<?= $e($selectedYear) ?>&amp;semana=<?= $e($selectedWeek) ?>" target="_blank">Vista previa</a><button class="save" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $recordExists ? 'Actualizar' : 'Guardar' ?> semana <?= $e($selectedWeek) ?></button></div>
    </form>
  </main>
  <template id="blacklistTemplate">
    <div class="blacklist-row">
      <div class="field wide"><label>Nombre</label><input name="lista_nombre[]" maxlength="100"></div>
      <div class="field wide"><label>Motivo</label><input name="lista_motivo[]" maxlength="180"></div>
      <div class="field"><label>Fecha</label><input type="date" name="lista_fecha[]"></div>
      <div class="field"><label>Estatus</label><select name="lista_estatus[]"><option>Registrado</option><option>En revisión</option><option>Liberado</option></select></div>
      <button class="remove-row" type="button" title="Eliminar renglón"><i class="fa-solid fa-trash"></i></button>
    </div>
  </template>
  <script>
    const rows = document.getElementById('blacklistRows');
    document.getElementById('addBlacklistRow').addEventListener('click', () => {
      rows.append(document.getElementById('blacklistTemplate').content.cloneNode(true));
    });
    rows.addEventListener('click', (event) => {
      const button = event.target.closest('.remove-row');
      if (!button) return;
      const row = button.closest('.blacklist-row');
      if (rows.querySelectorAll('.blacklist-row').length === 1) {
        row.querySelectorAll('input').forEach(input => input.value = '');
        row.querySelector('select').value = 'Registrado';
        return;
      }
      row.remove();
    });
  </script>
</body>
</html>
