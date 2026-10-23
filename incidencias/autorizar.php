<?php
// Autorizar o rechazar una solicitud desde el correo del jefe directo, sin iniciar sesión.
// El enlace lleva una firma (requestToken) que solo sirve para esa solicitud y ese jefe.
// Abrir el enlace solo muestra el detalle: la decisión se toma con el botón (POST), así los
// revisores automáticos de correo que abren enlaces no autorizan nada por su cuenta.
declare(strict_types=1);
require __DIR__ . '/../asistencia/bootstrap.php';
require __DIR__ . '/../asistencia/ui.php'; // icon() y los estilos de asistencia (daisyUI)
require __DIR__ . '/lib_vacaciones.php';
require __DIR__ . '/lib_solicitudes.php';

$kind = (string)($_POST['k'] ?? $_GET['k'] ?? '');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$request = in_array($kind, ['vacaciones', 'incidencia'], true) && feature($kind === 'vacaciones' ? 'vacaciones' : 'incidencias')
    ? (listRequests($db, ['kind' => $kind, 'id' => $id])[0] ?? null) : null;
$manager = $request ? directManager($db, $request['user']['id']) : null;
$valid = $request && $manager && $token !== '' && hash_equals(requestToken($kind, $id, $manager['id']), $token);

$done = null;
$error = '';
if ($valid && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $decision = (string)($_POST['decision'] ?? '');
    $result = reviewRequest($db, $kind, $id, $decision, trim((string)($_POST['reason'] ?? '')), $manager['id'], 'jefe');
    // Un segundo envío (doble clic o volver a mandar el formulario) no es un error si ya quedó como se pidió.
    $already = $request['status'] === (['autorizar' => 'autorizada', 'rechazar' => 'rechazada'][$decision] ?? null);
    if (isset($result['error']) && $already) {
        $done = $decision;
    } elseif (isset($result['error'])) {
        $error = $result['error'];
    } else {
        notifyRequest($db, $result['event'], $kind, $id);
        $done = $decision;
        $request = listRequests($db, ['kind' => $kind, 'id' => $id])[0];
    }
}
if (!$valid) http_response_code(404);

[$asked, $the] = $request ? requestPhrases($request['type']) : ['', ''];
$range = $request ? mailRange($request['dates']) : '';
$days = $request ? $request['days'] . ($request['kind'] === 'vacaciones' ? ($request['days'] === 1 ? ' día hábil' : ' días hábiles') : ($request['days'] === 1 ? ' día' : ' días')) : '';
$first = $request ? explode(' ', $request['user']['name'])[0] : '';
$when = fn(?string $v) => $v ? mailDay(substr($v, 0, 10)) : '';
$query = http_build_query(['k' => $kind, 'id' => $id, 't' => $token]);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Autorizar solicitud | Fedele 360</title>
    <link rel="stylesheet" href="../asistencia/<?= asset('app.css') ?>">
    <!-- Botones que no se pueden presionar dos veces y ventanas de la intranet (comun/acciones.js). -->
    <link rel="stylesheet" href="../comun/barra.css?v=<?= (int)filemtime(__DIR__ . '/../comun/barra.css') ?>">
    <script src="../comun/acciones.js?v=<?= (int)filemtime(__DIR__ . '/../comun/acciones.js') ?>" defer></script>
</head>
<body class="min-h-screen bg-base-200 text-base-content font-sans antialiased flex flex-col">
<header class="navbar bg-base-100 border-b border-base-300 px-4 sm:px-[6%] min-h-16">
    <a class="flex items-center gap-3 text-base font-semibold text-base-content" href="../">
        <img src="../tickets/img/logo-chico.png" alt="Fedele" class="h-8 w-auto"><span class="border-l border-base-300 pl-3 text-lg font-bold tracking-tight">Fedele <span class="text-primary font-extrabold">360</span></span>
    </a>
</header>
<main class="w-full max-w-lg mx-auto px-4 py-8 sm:py-12 flex-1">
<?php if (!$valid): ?>
    <section class="card bg-base-100 border border-base-300 shadow-sm"><div class="card-body gap-3">
        <h1 class="text-xl font-bold">Este enlace ya no es válido</h1>
        <p class="text-base-content/65">Puede que la solicitud ya no exista o que la persona tenga otro jefe asignado. Revisa las solicitudes de tu equipo en el sistema.</p>
        <a class="btn btn-primary mt-2" href="equipo.php">Ir a Incidencias de mi equipo</a>
    </div></section>
<?php else: ?>
    <section class="card bg-base-100 border border-base-300 shadow-xl shadow-base-300/40 overflow-hidden">
<?php if ($done === 'autorizar'): ?>
        <div class="bg-success text-success-content px-6 py-5 flex items-center gap-3">
            <?= icon('check', 'size-7 shrink-0') ?>
            <div><h1 class="text-xl font-bold">¡Listo, autorizada!</h1><p class="text-sm opacity-90">Le avisamos a Administración para que la confirme.</p></div>
        </div>
<?php elseif ($done === 'rechazar'): ?>
        <div class="bg-error text-error-content px-6 py-5">
            <h1 class="text-xl font-bold">Solicitud rechazada</h1><p class="text-sm opacity-90">Le avisamos a <?= esc($first) ?> por correo con el motivo.</p>
        </div>
<?php else: ?>
        <div class="bg-neutral text-neutral-content px-6 py-5">
            <div class="text-[11px] font-semibold tracking-widest text-neutral-content/60">SOLICITUD DE TU EQUIPO</div>
            <h1 class="text-xl sm:text-2xl font-bold mt-1"><?= esc($request['user']['name']) ?> solicitó <?= esc($asked) ?></h1>
        </div>
<?php endif; ?>
        <div class="card-body gap-4 p-6">
            <dl class="grid grid-cols-[auto_1fr] gap-x-5 gap-y-2.5 text-sm">
                <dt class="text-base-content/55">Persona</dt><dd class="font-semibold"><?= esc(implode(' · ', array_filter([$request['user']['name'], $request['user']['rep'] ? 'Representante médico' : null, $request['user']['area']]))) ?></dd>
                <dt class="text-base-content/55">Tipo</dt><dd class="font-semibold"><?= esc(REQUEST_TYPES[$request['type']] ?? '') ?></dd>
                <dt class="text-base-content/55">Fechas</dt><dd class="font-semibold"><?= esc($range) ?></dd>
                <dt class="text-base-content/55">Días</dt><dd class="font-semibold"><?= esc($days) ?></dd>
<?php if ($request['comment']): ?>
                <dt class="text-base-content/55">Comentario</dt><dd><?= nl2br(esc($request['comment'])) ?></dd>
<?php endif; ?>
<?php if ($request['file']): ?>
                <dt class="text-base-content/55">Documento</dt><dd><?= esc($request['file']['name']) ?></dd>
<?php endif; ?>
            </dl>
<?php if ($error): ?>
            <div class="alert alert-error alert-soft text-sm" role="alert"><?= esc($error) ?></div>
<?php endif; ?>
<?php if ($request['status'] === 'pendiente'): ?>
            <form method="post" action="autorizar.php?<?= esc($query) ?>" class="flex flex-col gap-2">
                <input type="hidden" name="k" value="<?= esc($kind) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="t" value="<?= esc($token) ?>">
                <button class="btn btn-success btn-lg w-full gap-2" name="decision" value="autorizar"><?= icon('check', 'size-5') ?>Sí, autorizar</button>
                <p class="text-xs text-base-content/55 text-center">Al autorizar, le llega un correo a Administración para la confirmación final.</p>
            </form>
            <details class="rounded-box border border-base-300 group"<?= isset($_GET['r']) || $error ? ' open' : '' ?>>
                <summary class="cursor-pointer list-none px-4 py-3 text-sm font-medium text-error flex items-center justify-between">No la autorizo<?= icon('chevron-down', 'size-4 transition group-open:rotate-180') ?></summary>
                <form method="post" action="autorizar.php?<?= esc($query) ?>" class="px-4 pb-4 flex flex-col gap-2">
                    <input type="hidden" name="k" value="<?= esc($kind) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="t" value="<?= esc($token) ?>">
                    <label><span class="text-sm block mb-1.5">Motivo (se le enviará a <?= esc($first) ?>)</span>
                        <textarea class="textarea w-full" name="reason" rows="3" maxlength="255" required minlength="3" placeholder="Ej. Esa semana hay cierre de mes; elige otras fechas."></textarea></label>
                    <button class="btn btn-error" name="decision" value="rechazar">Rechazar solicitud</button>
                </form>
            </details>
<?php elseif (!$done): ?>
            <div class="alert alert-soft <?= $request['status'] === 'rechazada' ? 'alert-error' : ($request['status'] === 'cancelada' ? '' : 'alert-success') ?> text-sm">
<?php if ($request['status'] === 'autorizada'): ?>
                Ya la autorizaste<?= $request['authorized_at'] ? ' el ' . esc($when($request['authorized_at'])) : '' ?>. Falta la confirmación de Administración.
<?php elseif ($request['status'] === 'aprobada'): ?>
                Esta solicitud ya quedó aprobada.
<?php elseif ($request['status'] === 'rechazada'): ?>
                Esta solicitud fue rechazada<?= $request['reviewed_by'] ? ' por ' . esc($request['reviewed_by']) : '' ?>.
<?php elseif ($request['reviewed_by'] && $request['reviewed_by'] !== $request['user']['name']): ?>
                <?= esc($request['reviewed_by']) ?> canceló esta solicitud.
<?php else: ?>
                <?= esc($first) ?> canceló esta solicitud.
<?php endif; ?>
            </div>
<?php endif; ?>
            <a class="link link-hover text-sm text-base-content/60 text-center" href="equipo.php">Ver todas las solicitudes de mi equipo</a>
        </div>
    </section>
<?php endif; ?>
</main>
</body>
</html>
