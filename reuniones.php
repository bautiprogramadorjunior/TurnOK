<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['Alumno', 'Profesor', 'Administrativo', 'Directivo']);
$pdo = db();
$userId = (int) $user['id'];
$isRequester = has_any_role(['Alumno', 'Profesor']);
$isManager = can_manage_institution();

function insert_meeting_participant(PDO $pdo, int $meetingId, int $participantId, string $confirmation = 'Pendiente'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO reunion_participantes (reunion_id, usuario_id, confirmacion)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE confirmacion = VALUES(confirmacion)'
    );
    $stmt->execute([$meetingId, $participantId, $confirmation]);
}

function create_meeting_notification(PDO $pdo, int $userId, int $meetingId, string $title, string $date): void
{
    notify_user(
        $pdo,
        $userId,
        'reunion_asignada',
        'Nueva reunión asignada',
        'Fuiste convocado/a a “' . $title . '” para el ' . format_date($date) . '.',
        'reuniones',
        $meetingId
    );
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'request_meeting') {
            if (!$isRequester) {
                throw new RuntimeException('Tu perfil no puede solicitar reuniones.');
            }

            $recipientId = post_int('destinatario_id');
            $reason = post_string('motivo');
            if (!$recipientId || $recipientId === $userId || mb_strlen($reason) < 10) {
                throw new RuntimeException('Elegí un destinatario y explicá el motivo con al menos 10 caracteres.');
            }

            $recipientCheck = $pdo->prepare(
                'SELECT COUNT(*) FROM usuario_cargos uc JOIN usuarios u ON u.id = uc.usuario_id
                 WHERE uc.usuario_id = ? AND uc.activo = 1 AND u.activo = 1
                   AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                   AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())'
            );
            $recipientCheck->execute([$recipientId]);
            if (!(int) $recipientCheck->fetchColumn()) {
                throw new RuntimeException('El destinatario ya no tiene un cargo institucional activo.');
            }

            $stmt = $pdo->prepare('INSERT INTO solicitudes_reuniones (solicitante_id, destinatario_id, motivo) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $recipientId, $reason]);
            $requestId = (int) $pdo->lastInsertId();
            audit($pdo, $userId, 'SOLICITUD_REUNION', 'solicitudes_reuniones', $requestId);
            flash('success', 'La solicitud de reunión fue enviada.');
        } elseif ($action === 'respond_request') {
            $requestId = post_int('solicitud_id');
            $decision = post_string('decision');
            $comment = post_string('comentario');
            if (!$requestId || !in_array($decision, ['Aceptada', 'Rechazada'], true)) {
                throw new RuntimeException('La respuesta no es válida.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM solicitudes_reuniones WHERE id = ? FOR UPDATE');
            $lock->execute([$requestId]);
            $request = $lock->fetch();
            if (!$request || (int) $request['destinatario_id'] !== $userId || $request['estado'] !== 'Pendiente') {
                throw new RuntimeException('La solicitud ya fue respondida o no te corresponde.');
            }

            if ($decision === 'Rechazada') {
                if (mb_strlen($comment) < 5) {
                    throw new RuntimeException('Explicá brevemente el motivo del rechazo.');
                }
                $update = $pdo->prepare("UPDATE solicitudes_reuniones SET estado = 'Rechazada', comentario = ? WHERE id = ?");
                $update->execute([$comment, $requestId]);
                audit($pdo, $userId, 'RECHAZO_SOLICITUD_REUNION', 'solicitudes_reuniones', $requestId);
                $pdo->commit();
                flash('success', 'La solicitud fue rechazada.');
            } else {
                $title = post_string('titulo');
                $description = post_string('descripcion');
                $date = post_string('fecha');
                $start = post_string('hora_inicio');
                $end = post_string('hora_fin');
                $place = post_string('lugar');

                if ($title === '' || !valid_date($date) || !valid_time($start) || !valid_time($end) || $end <= $start || $place === '') {
                    throw new RuntimeException('Para aceptar, completá título, fecha, horario y lugar correctamente.');
                }
                if ($date < date('Y-m-d')) {
                    throw new RuntimeException('La reunión no puede programarse en una fecha pasada.');
                }

                $typeId = (int) $pdo->query("SELECT id FROM tipos_reunion WHERE nombre = 'Personal' LIMIT 1")->fetchColumn();
                $meeting = $pdo->prepare(
                    'INSERT INTO reuniones (titulo, descripcion, tipo_reunion_id, fecha, hora_inicio, hora_fin, lugar, creador_id, solicitud_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $meeting->execute([$title, $description !== '' ? $description : null, $typeId, $date, $start, $end, $place, $userId, $requestId]);
                $meetingId = (int) $pdo->lastInsertId();

                insert_meeting_participant($pdo, $meetingId, (int) $request['solicitante_id']);
                insert_meeting_participant($pdo, $meetingId, $userId, 'Confirmada');

                $update = $pdo->prepare("UPDATE solicitudes_reuniones SET estado = 'Aceptada', comentario = ? WHERE id = ?");
                $update->execute([$comment !== '' ? $comment : null, $requestId]);

                notify_user(
                    $pdo,
                    (int) $request['solicitante_id'],
                    'solicitud_reunion_aceptada',
                    'Solicitud de reunión aceptada',
                    'Tu reunión “' . $title . '” fue programada para el ' . format_date($date) . ' a las ' . format_time($start) . '.',
                    'reuniones',
                    $meetingId
                );
                audit($pdo, $userId, 'ACEPTACION_SOLICITUD_REUNION', 'solicitudes_reuniones', $requestId, ['reunion_id' => $meetingId]);
                audit($pdo, $userId, 'CREACION_REUNION', 'reuniones', $meetingId, ['origen' => 'solicitud']);
                $pdo->commit();
                flash('success', 'La solicitud fue aceptada y la reunión quedó creada.');
            }
        } elseif ($action === 'create_meeting') {
            if (!$isManager) {
                throw new RuntimeException('Solo administrativos y directivos pueden crear reuniones institucionales.');
            }

            $title = post_string('titulo');
            $description = post_string('descripcion');
            $typeId = post_int('tipo_reunion_id');
            $date = post_string('fecha');
            $start = post_string('hora_inicio');
            $end = post_string('hora_fin');
            $place = post_string('lugar');

            if ($title === '' || !$typeId || !valid_date($date) || !valid_time($start) || !valid_time($end) || $end <= $start || $place === '') {
                throw new RuntimeException('Completá todos los datos obligatorios de la reunión.');
            }
            if ($date < date('Y-m-d')) {
                throw new RuntimeException('La reunión no puede programarse en una fecha pasada.');
            }

            $typeStmt = $pdo->prepare('SELECT nombre FROM tipos_reunion WHERE id = ?');
            $typeStmt->execute([$typeId]);
            $typeName = $typeStmt->fetchColumn();
            if (!$typeName) {
                throw new RuntimeException('El tipo de reunión no existe.');
            }

            $participantIds = [];
            $courseIds = post_int_array('curso_ids');
            $roleIds = post_int_array('rol_ids');
            $individualIds = post_int_array('participante_ids');

            if ($typeName === 'Personal') {
                if (!$individualIds) {
                    throw new RuntimeException('Seleccioná al menos una persona participante.');
                }
                $participantIds = $individualIds;
            } elseif ($typeName === 'Por Curso' || $typeName === 'Varios Cursos') {
                if (!$courseIds || ($typeName === 'Por Curso' && count($courseIds) !== 1)) {
                    throw new RuntimeException($typeName === 'Por Curso' ? 'Seleccioná exactamente un curso.' : 'Seleccioná uno o más cursos.');
                }
            } elseif ($typeName === 'Masiva') {
                if (!$roleIds) {
                    throw new RuntimeException('Seleccioná al menos un grupo por rol.');
                }
            }

            $pdo->beginTransaction();
            $meeting = $pdo->prepare(
                'INSERT INTO reuniones (titulo, descripcion, tipo_reunion_id, fecha, hora_inicio, hora_fin, lugar, creador_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $meeting->execute([$title, $description !== '' ? $description : null, $typeId, $date, $start, $end, $place, $userId]);
            $meetingId = (int) $pdo->lastInsertId();

            if ($typeName === 'Por Curso' || $typeName === 'Varios Cursos') {
                $rel = $pdo->prepare('INSERT INTO reunion_cursos (reunion_id, curso_id) VALUES (?, ?)');
                foreach ($courseIds as $courseId) {
                    $rel->execute([$meetingId, $courseId]);
                }
                $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                $participants = $pdo->prepare(
                    "SELECT DISTINCT usuario_id FROM alumno_curso
                     WHERE anio_lectivo = YEAR(CURDATE()) AND curso_id IN ($placeholders)"
                );
                $participants->execute($courseIds);
                $participantIds = array_map('intval', $participants->fetchAll(PDO::FETCH_COLUMN));
            } elseif ($typeName === 'Masiva') {
                $group = $pdo->prepare('INSERT INTO reunion_grupos (reunion_id, descripcion, rol_id) SELECT ?, CONCAT("Todos: ", nombre), id FROM roles WHERE id = ?');
                foreach ($roleIds as $roleId) {
                    $group->execute([$meetingId, $roleId]);
                }
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $participants = $pdo->prepare(
                    "SELECT DISTINCT ur.usuario_id
                     FROM usuario_roles ur JOIN usuarios u ON u.id = ur.usuario_id AND u.activo = 1
                     WHERE ur.rol_id IN ($placeholders)"
                );
                $participants->execute($roleIds);
                $participantIds = array_map('intval', $participants->fetchAll(PDO::FETCH_COLUMN));
            }

            $participantIds = array_values(array_unique($participantIds));
            insert_meeting_participant($pdo, $meetingId, $userId, 'Confirmada');
            foreach ($participantIds as $participantId) {
                if ($participantId === $userId) {
                    continue;
                }
                insert_meeting_participant($pdo, $meetingId, $participantId);
                create_meeting_notification($pdo, $participantId, $meetingId, $title, $date);
            }

            audit($pdo, $userId, 'CREACION_REUNION', 'reuniones', $meetingId, ['tipo' => $typeName, 'participantes' => count($participantIds)]);
            $pdo->commit();
            flash('success', 'La reunión fue creada y las personas convocadas recibieron una notificación.');
        } elseif ($action === 'confirm_attendance') {
            $meetingId = post_int('reunion_id');
            $confirmation = post_string('confirmacion');
            if (!$meetingId || !in_array($confirmation, ['Confirmada', 'Rechazada'], true)) {
                throw new RuntimeException('La confirmación no es válida.');
            }
            $stmt = $pdo->prepare('UPDATE reunion_participantes SET confirmacion = ? WHERE reunion_id = ? AND usuario_id = ?');
            $stmt->execute([$confirmation, $meetingId, $userId]);
            if ($stmt->rowCount() < 1) {
                throw new RuntimeException('No figurás como participante individual de esta reunión.');
            }
            audit($pdo, $userId, 'CONFIRMACION_REUNION', 'reuniones', $meetingId, ['confirmacion' => $confirmation]);
            flash('success', 'Tu respuesta fue registrada.');
        } elseif ($action === 'mark_attendance') {
            $meetingId = post_int('reunion_id');
            $participantId = post_int('participante_id');
            $attendance = post_string('asistencia_real');
            if (!$meetingId || !$participantId || !in_array($attendance, ['0', '1'], true)) {
                throw new RuntimeException('El registro de asistencia no es válido.');
            }
            $ownerCheck = $pdo->prepare('SELECT creador_id FROM reuniones WHERE id = ?');
            $ownerCheck->execute([$meetingId]);
            $creatorId = (int) $ownerCheck->fetchColumn();
            if (!$isManager && $creatorId !== $userId) {
                throw new RuntimeException('No tenés permisos para registrar la asistencia.');
            }
            $stmt = $pdo->prepare('UPDATE reunion_participantes SET asistencia_real = ? WHERE reunion_id = ? AND usuario_id = ?');
            $stmt->execute([(int) $attendance, $meetingId, $participantId]);
            audit($pdo, $userId, 'ASISTENCIA_REUNION', 'reuniones', $meetingId, ['participante_id' => $participantId, 'asistencia' => (int) $attendance]);
            flash('success', 'La asistencia fue actualizada.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect('reuniones.php');
}

$destinations = $pdo->query(
    "SELECT u.id, u.nombre, u.apellido,
            GROUP_CONCAT(DISTINCT c.nombre ORDER BY c.nombre SEPARATOR ' · ') AS cargos
     FROM usuarios u
     JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
        AND (uc.desde IS NULL OR uc.desde <= CURDATE())
        AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
     JOIN cargos c ON c.id = uc.cargo_id AND c.activo = 1
     WHERE u.activo = 1
     GROUP BY u.id
     ORDER BY u.apellido, u.nombre"
)->fetchAll();

$types = $pdo->query('SELECT id, nombre FROM tipos_reunion ORDER BY id')->fetchAll();
$courses = $pdo->query(
    "SELECT cu.id, cu.anio, cu.division, cu.turno, e.nombre AS especialidad
     FROM cursos cu LEFT JOIN especialidades e ON e.id = cu.especialidad_id
     WHERE cu.activo = 1 ORDER BY cu.anio, e.nombre, cu.division, cu.turno"
)->fetchAll();
$roles = $pdo->query('SELECT id, nombre FROM roles WHERE activo = 1 ORDER BY nombre')->fetchAll();
$people = $pdo->query('SELECT id, nombre, apellido, dni FROM usuarios WHERE activo = 1 ORDER BY apellido, nombre')->fetchAll();

$incomingStmt = $pdo->prepare(
    "SELECT sr.*, u.nombre AS solicitante_nombre, u.apellido AS solicitante_apellido
     FROM solicitudes_reuniones sr JOIN usuarios u ON u.id = sr.solicitante_id
     WHERE sr.destinatario_id = ? ORDER BY FIELD(sr.estado,'Pendiente','Aceptada','Rechazada'), sr.fecha_solicitud DESC"
);
$incomingStmt->execute([$userId]);
$incomingRequests = $incomingStmt->fetchAll();

$outgoingRequests = [];
if ($isRequester) {
    $outStmt = $pdo->prepare(
        "SELECT sr.*, u.nombre AS destinatario_nombre, u.apellido AS destinatario_apellido
         FROM solicitudes_reuniones sr JOIN usuarios u ON u.id = sr.destinatario_id
         WHERE sr.solicitante_id = ? ORDER BY sr.fecha_solicitud DESC"
    );
    $outStmt->execute([$userId]);
    $outgoingRequests = $outStmt->fetchAll();
}

if ($isManager) {
    $meetingStmt = $pdo->query(
        "SELECT r.*, tr.nombre AS tipo_nombre, cr.nombre AS creador_nombre, cr.apellido AS creador_apellido,
                rp.confirmacion AS mi_confirmacion
         FROM reuniones r
         JOIN tipos_reunion tr ON tr.id = r.tipo_reunion_id
         JOIN usuarios cr ON cr.id = r.creador_id
         LEFT JOIN reunion_participantes rp ON rp.reunion_id = r.id AND rp.usuario_id = $userId
         ORDER BY r.fecha DESC, r.hora_inicio DESC"
    );
} else {
    $meetingStmt = $pdo->prepare(
        "SELECT DISTINCT r.*, tr.nombre AS tipo_nombre, cr.nombre AS creador_nombre, cr.apellido AS creador_apellido,
                rp.confirmacion AS mi_confirmacion
         FROM reuniones r
         JOIN tipos_reunion tr ON tr.id = r.tipo_reunion_id
         JOIN usuarios cr ON cr.id = r.creador_id
         LEFT JOIN reunion_participantes rp ON rp.reunion_id = r.id AND rp.usuario_id = :u1
         WHERE r.creador_id = :u2 OR rp.usuario_id IS NOT NULL
         ORDER BY r.fecha DESC, r.hora_inicio DESC"
    );
    $meetingStmt->execute(['u1' => $userId, 'u2' => $userId]);
}
$meetings = $meetingStmt->fetchAll();

$participantsByMeeting = [];
if ($meetings) {
    $meetingIds = array_map(static fn(array $meeting): int => (int) $meeting['id'], $meetings);
    $placeholders = implode(',', array_fill(0, count($meetingIds), '?'));
    $partStmt = $pdo->prepare(
        "SELECT rp.*, u.nombre, u.apellido
         FROM reunion_participantes rp JOIN usuarios u ON u.id = rp.usuario_id
         WHERE rp.reunion_id IN ($placeholders)
         ORDER BY u.apellido, u.nombre"
    );
    $partStmt->execute($meetingIds);
    foreach ($partStmt->fetchAll() as $participant) {
        $participantsByMeeting[(int) $participant['reunion_id']][] = $participant;
    }
}

$pendingIncoming = count(array_filter($incomingRequests, static fn(array $item): bool => $item['estado'] === 'Pendiente'));
$upcomingCount = count(array_filter($meetings, static fn(array $item): bool => $item['fecha'] >= date('Y-m-d')));

$pageTitle = 'Reuniones';
$activePage = 'reuniones';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div><h2>Reuniones presenciales</h2><p>Solicitudes, agenda, confirmaciones y asistencia en un mismo lugar.</p></div>
    <div class="page-actions">
        <?php if ($isRequester): ?><button class="btn" type="button" data-dialog-open="requestMeeting"><?= icon('calendar', 17) ?> Solicitar reunión</button><?php endif; ?>
        <?php if ($isManager): ?><button class="btn btn-primary" type="button" data-dialog-open="createMeeting"><?= icon('plus', 17) ?> Crear reunión</button><?php endif; ?>
    </div>
</section>

<section class="stats-grid">
    <article class="stat-card"><div class="stat-top"><span>PRÓXIMAS</span><span class="stat-icon"><?= icon('calendar', 19) ?></span></div><strong><?= $upcomingCount ?></strong><p>Reuniones desde hoy</p></article>
    <article class="stat-card"><div class="stat-top"><span>SOLICITUDES RECIBIDAS</span><span class="stat-icon"><?= icon('file', 19) ?></span></div><strong><?= $pendingIncoming ?></strong><p>Pendientes de respuesta</p></article>
    <article class="stat-card"><div class="stat-top"><span>MIS SOLICITUDES</span><span class="stat-icon"><?= icon('clock', 19) ?></span></div><strong><?= count($outgoingRequests) ?></strong><p>Historial de pedidos</p></article>
    <article class="stat-card"><div class="stat-top"><span>AGENDA TOTAL</span><span class="stat-icon"><?= icon('users', 19) ?></span></div><strong><?= count($meetings) ?></strong><p>Reuniones visibles</p></article>
</section>

<?php if ($incomingRequests): ?>
<article class="panel" style="margin-bottom:18px">
<header class="panel-header"><div><h3>Solicitudes recibidas</h3><p>Pedidos dirigidos a vos por tu cargo institucional</p></div></header>
<div class="panel-body flush"><div class="table-wrap" style="border:0;border-radius:0"><table><thead><tr><th>Solicitante</th><th>Motivo</th><th>Fecha</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
<?php foreach ($incomingRequests as $request): ?><tr><td><span class="cell-title"><?= e($request['solicitante_apellido'] . ', ' . $request['solicitante_nombre']) ?></span></td><td><?= e($request['motivo']) ?></td><td><?= e(format_date($request['fecha_solicitud'], true)) ?></td><td><span class="status <?= e(status_class($request['estado'])) ?>"><?= e($request['estado']) ?></span></td><td><?php if ($request['estado'] === 'Pendiente'): ?><button class="btn btn-sm btn-primary" type="button" data-dialog-open="respondRequest<?= (int) $request['id'] ?>">Responder</button><?php else: ?><span class="muted"><?= e($request['comentario'] ?: 'Procesada') ?></span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div></div></article>
<?php endif; ?>

<article class="panel">
<header class="panel-header"><div><h3>Agenda de reuniones</h3><p>Convocatorias y reuniones creadas por vos</p></div></header>
<div class="panel-body">
<div class="filter-bar"><div class="search-box"><?= icon('search', 18) ?><input type="search" placeholder="Buscar por título, lugar o tipo" data-table-filter="#meetingsTable"></div></div>
<?php if (!$meetings): ?><div class="empty-state"><div class="empty-icon"><?= icon('calendar', 30) ?></div><h2>No hay reuniones</h2><p>Las reuniones asignadas o creadas aparecerán en esta agenda.</p></div><?php else: ?>
<div class="table-wrap"><table id="meetingsTable"><thead><tr><th>Reunión</th><th>Fecha y hora</th><th>Lugar</th><th>Tipo</th><th>Mi respuesta</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($meetings as $meeting): ?>
<tr><td><span class="cell-title">#<?= (int) $meeting['id'] ?> · <?= e($meeting['titulo']) ?></span><span class="cell-subtitle">Creada por <?= e($meeting['creador_nombre'] . ' ' . $meeting['creador_apellido']) ?></span></td><td><span class="cell-title"><?= e(format_date($meeting['fecha'])) ?></span><span class="cell-subtitle"><?= e(format_time($meeting['hora_inicio'])) ?> a <?= e(format_time($meeting['hora_fin'])) ?></span></td><td><?= e($meeting['lugar']) ?></td><td><span class="status status-info"><?= e($meeting['tipo_nombre']) ?></span></td><td><?php if ($meeting['mi_confirmacion']): ?><span class="status <?= e(status_class($meeting['mi_confirmacion'])) ?>"><?= e($meeting['mi_confirmacion']) ?></span><?php elseif ((int) $meeting['creador_id'] === $userId): ?><span class="status status-success">Organizador</span><?php else: ?><span class="muted">—</span><?php endif; ?></td><td class="actions-cell"><button class="btn btn-sm" type="button" data-dialog-open="meetingDetail<?= (int) $meeting['id'] ?>">Ver detalle</button></td></tr>
<?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</div></article>

<?php if ($outgoingRequests): ?>
<article class="panel" style="margin-top:18px"><header class="panel-header"><div><h3>Mis solicitudes de reunión</h3><p>Seguimiento de pedidos enviados</p></div></header><div class="panel-body"><div class="list">
<?php foreach ($outgoingRequests as $request): ?><div class="list-item"><span class="list-icon"><?= icon('calendar', 18) ?></span><div class="list-copy"><strong><?= e($request['destinatario_apellido'] . ', ' . $request['destinatario_nombre']) ?></strong><p><?= e($request['motivo']) ?><?php if ($request['comentario']): ?> · Respuesta: <?= e($request['comentario']) ?><?php endif; ?></p></div><span class="status <?= e(status_class($request['estado'])) ?>"><?= e($request['estado']) ?></span></div><?php endforeach; ?>
</div></div></article>
<?php endif; ?>

<?php if ($isRequester): ?>
<dialog class="modal" id="requestMeeting" <?= ($_GET['action'] ?? '') === 'request' ? 'data-auto-open' : '' ?>><header class="modal-header"><div><h3>Solicitar una reunión</h3><p>Elegí a una persona que tenga un cargo institucional activo.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="request_meeting"><div class="form-grid"><div class="form-group full"><label>Destinatario</label><select class="select-control" name="destinatario_id" required><option value="">Seleccionar persona y cargo…</option><?php foreach ($destinations as $destination): ?><?php if ((int) $destination['id'] === $userId) continue; ?><option value="<?= (int) $destination['id'] ?>"><?= e($destination['apellido'] . ', ' . $destination['nombre'] . ' · ' . $destination['cargos']) ?></option><?php endforeach; ?></select></div><div class="form-group full"><label>Motivo</label><textarea class="textarea-control" name="motivo" minlength="10" required placeholder="Explicá el tema que necesitás conversar."></textarea></div></div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Enviar solicitud</button></div></form></dialog>
<?php endif; ?>

<?php if ($isManager): ?>
<dialog class="modal" id="createMeeting" <?= ($_GET['action'] ?? '') === 'create' ? 'data-auto-open' : '' ?>><header class="modal-header"><div><h3>Crear reunión institucional</h3><p>La reunión será presencial y notificará a todas las personas convocadas.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_meeting"><div class="form-grid">
<div class="form-group full"><label>Título</label><input class="input-control" name="titulo" maxlength="200" required></div><div class="form-group full"><label>Descripción</label><textarea class="textarea-control" name="descripcion"></textarea></div>
<div class="form-group"><label>Tipo</label><select class="select-control" id="meetingType" name="tipo_reunion_id" data-conditional-source required><option value="">Seleccionar…</option><?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>" data-name="<?= e($type['nombre']) ?>"><?= e($type['nombre']) ?></option><?php endforeach; ?></select></div><div class="form-group"><label>Fecha</label><input class="input-control" type="date" name="fecha" min="<?= e(date('Y-m-d')) ?>" required></div><div class="form-group"><label>Hora de inicio</label><input class="input-control" type="time" name="hora_inicio" required></div><div class="form-group"><label>Hora de fin</label><input class="input-control" type="time" name="hora_fin" required></div><div class="form-group full"><label>Lugar físico</label><input class="input-control" name="lugar" maxlength="200" required placeholder="Ej.: Aula Magna, planta baja"></div>
<div class="form-group full hidden" data-meeting-target="Personal"><label>Participantes individuales</label><div class="checkbox-grid" style="max-height:220px;overflow:auto"><?php foreach ($people as $person): ?><?php if ((int) $person['id'] === $userId) continue; ?><label class="check-option"><input type="checkbox" name="participante_ids[]" value="<?= (int) $person['id'] ?>"> <?= e($person['apellido'] . ', ' . $person['nombre'] . ' · DNI ' . $person['dni']) ?></label><?php endforeach; ?></div></div>
<div class="form-group full hidden" data-meeting-target="Cursos"><label>Cursos convocados</label><div class="checkbox-grid" style="max-height:220px;overflow:auto"><?php foreach ($courses as $course): ?><label class="check-option"><input type="checkbox" name="curso_ids[]" value="<?= (int) $course['id'] ?>"> <?= e(course_label($course)) ?></label><?php endforeach; ?></div></div>
<div class="form-group full hidden" data-meeting-target="Masiva"><label>Grupos por rol</label><div class="checkbox-grid"><?php foreach ($roles as $role): ?><label class="check-option"><input type="checkbox" name="rol_ids[]" value="<?= (int) $role['id'] ?>"> Todos: <?= e($role['nombre']) ?></label><?php endforeach; ?></div></div>
</div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Crear y notificar</button></div></form></dialog>
<?php endif; ?>

<?php foreach ($incomingRequests as $request): ?><?php if ($request['estado'] !== 'Pendiente') continue; ?>
<dialog class="modal" id="respondRequest<?= (int) $request['id'] ?>"><header class="modal-header"><div><h3>Responder solicitud</h3><p><?= e($request['solicitante_nombre'] . ' ' . $request['solicitante_apellido']) ?> · <?= e($request['motivo']) ?></p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="respond_request"><input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>"><div class="form-grid"><div class="form-group full"><label>Decisión</label><select class="select-control" name="decision" id="decision<?= (int) $request['id'] ?>" data-request-decision><option value="Aceptada">Aceptar y programar</option><option value="Rechazada">Rechazar solicitud</option></select></div><div class="form-group full"><label>Título de la reunión</label><input class="input-control" name="titulo" value="Reunión con <?= e($request['solicitante_nombre'] . ' ' . $request['solicitante_apellido']) ?>"></div><div class="form-group full"><label>Descripción</label><textarea class="textarea-control" name="descripcion"><?= e($request['motivo']) ?></textarea></div><div class="form-group"><label>Fecha</label><input class="input-control" type="date" name="fecha" min="<?= e(date('Y-m-d')) ?>"></div><div class="form-group"><label>Horario</label><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><input class="input-control" type="time" name="hora_inicio"><input class="input-control" type="time" name="hora_fin"></div></div><div class="form-group full"><label>Lugar físico</label><input class="input-control" name="lugar"></div><div class="form-group full"><label>Comentario para el solicitante</label><textarea class="textarea-control" name="comentario" placeholder="Obligatorio al rechazar; opcional al aceptar."></textarea></div></div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar respuesta</button></div></form></dialog>
<?php endforeach; ?>

<?php foreach ($meetings as $meeting): $meetingParticipants = $participantsByMeeting[(int) $meeting['id']] ?? []; ?>
<dialog class="modal" id="meetingDetail<?= (int) $meeting['id'] ?>"><header class="modal-header"><div><h3><?= e($meeting['titulo']) ?></h3><p><?= e($meeting['tipo_nombre']) ?> · <?= e(format_date($meeting['fecha'])) ?> · <?= e(format_time($meeting['hora_inicio'])) ?> a <?= e(format_time($meeting['hora_fin'])) ?></p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><div class="modal-body"><div class="detail-list"><div class="detail-item"><span>Lugar</span><strong><?= e($meeting['lugar']) ?></strong></div><div class="detail-item"><span>Organiza</span><strong><?= e($meeting['creador_nombre'] . ' ' . $meeting['creador_apellido']) ?></strong></div></div><?php if ($meeting['descripcion']): ?><div class="section-divider"></div><div class="form-group"><label>Descripción</label><p class="muted"><?= nl2br(e($meeting['descripcion'])) ?></p></div><?php endif; ?>
<?php if ($meeting['mi_confirmacion'] === 'Pendiente'): ?><div class="section-divider"></div><form method="post" class="actions-cell"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_attendance"><input type="hidden" name="reunion_id" value="<?= (int) $meeting['id'] ?>"><button class="btn btn-success" type="submit" name="confirmacion" value="Confirmada"><?= icon('check', 16) ?> Confirmar asistencia</button><button class="btn btn-danger" type="submit" name="confirmacion" value="Rechazada"><?= icon('x', 16) ?> No asistiré</button></form><?php endif; ?>
<div class="section-divider"></div><div class="form-group"><label>Participantes (<?= count($meetingParticipants) ?>)</label><div class="list"><?php foreach ($meetingParticipants as $participant): ?><div class="list-item"><span class="list-icon"><?= icon('user', 17) ?></span><div class="list-copy"><strong><?= e($participant['apellido'] . ', ' . $participant['nombre']) ?></strong><p>Confirmación: <?= e($participant['confirmacion']) ?> · Asistencia: <?= $participant['asistencia_real'] === null ? 'Sin registrar' : ((int) $participant['asistencia_real'] ? 'Presente' : 'Ausente') ?></p></div><?php if ($isManager || (int) $meeting['creador_id'] === $userId): ?><form method="post" class="actions-cell"><?= csrf_field() ?><input type="hidden" name="action" value="mark_attendance"><input type="hidden" name="reunion_id" value="<?= (int) $meeting['id'] ?>"><input type="hidden" name="participante_id" value="<?= (int) $participant['usuario_id'] ?>"><button class="btn btn-sm btn-success" type="submit" name="asistencia_real" value="1">Presente</button><button class="btn btn-sm btn-danger" type="submit" name="asistencia_real" value="0">Ausente</button></form><?php endif; ?></div><?php endforeach; ?></div></div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cerrar</button></div></div></dialog>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-auto-open]').forEach((dialog)=>dialog.showModal());
const typeSelect=document.getElementById('meetingType');
function updateMeetingFields(){if(!typeSelect)return;const name=typeSelect.options[typeSelect.selectedIndex]?.dataset.name||'';document.querySelectorAll('[data-meeting-target]').forEach((el)=>{const target=el.dataset.meetingTarget;const show=target===name||(target==='Cursos'&&(name==='Por Curso'||name==='Varios Cursos'));el.classList.toggle('hidden',!show);el.querySelectorAll('input').forEach((input)=>input.disabled=!show);});}
typeSelect?.addEventListener('change',updateMeetingFields);updateMeetingFields();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
