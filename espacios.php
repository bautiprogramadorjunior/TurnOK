<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$pdo = db();
$userId = (int) $user['id'];
$isProfessor = !has_cargo('Preceptor') && has_role('Profesor');
$isManager = can_manage_institution();
$isAssignedManager = has_cargo('Encargado de Espacio');

if (!$isProfessor && !$isManager && !$isAssignedManager) {
    http_response_code(403);
    $pageTitle = 'Espacios';
    $activePage = 'espacios';
    require __DIR__ . '/includes/header.php';
    echo '<section class="empty-state"><div class="empty-icon">' . icon('x', 32) . '</div><h2>Acceso restringido</h2><p>Las reservas de espacios están disponibles para profesores y encargados.</p></section>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$allowedStates = ['Pendiente', 'Aprobada', 'Rechazada', 'Cancelada'];

function can_process_space(PDO $pdo, int $userId, int $requestId, bool $institutionManager): bool
{
    if ($institutionManager) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM solicitudes_espacios se
         JOIN encargados_espacios ee ON ee.espacio_id = se.espacio_id
            AND ee.usuario_id = ? AND ee.activo = 1
         WHERE se.id = ?
           AND ee.turno = CASE WHEN se.hora_inicio < '13:30:00' THEN 'TM' ELSE 'TT' END"
    );
    $stmt->execute([$userId, $requestId]);
    return (int) $stmt->fetchColumn() > 0;
}

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'create') {
            if (!$isProfessor) {
                throw new RuntimeException('Solo los profesores pueden solicitar espacios.');
            }

            $spaceId = post_int('espacio_id');
            $date = post_string('fecha_uso');
            $start = post_string('hora_inicio');
            $end = post_string('hora_fin');
            $reason = post_string('motivo');

            if (!$spaceId || !valid_date($date) || !valid_time($start) || !valid_time($end) || $end <= $start || mb_strlen($reason) < 10) {
                throw new RuntimeException('Completá espacio, fecha, horario y motivo correctamente.');
            }
            if ($date < date('Y-m-d')) {
                throw new RuntimeException('La fecha de uso no puede ser anterior a hoy.');
            }

            $spaceCheck = $pdo->prepare("SELECT id FROM espacios WHERE id = ? AND activo = 1 AND estado = 'Disponible'");
            $spaceCheck->execute([$spaceId]);
            if (!$spaceCheck->fetchColumn()) {
                throw new RuntimeException('El espacio no está disponible para nuevas solicitudes.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO solicitudes_espacios (espacio_id, solicitante_id, fecha_uso, hora_inicio, hora_fin, motivo)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$spaceId, $userId, $date, $start, $end, $reason]);
            $requestId = (int) $pdo->lastInsertId();
            audit($pdo, $userId, 'SOLICITUD_ESPACIO', 'solicitudes_espacios', $requestId, ['turno' => infer_shift($start)]);
            flash('success', 'La reserva fue enviada al encargado del turno.');
        } elseif ($action === 'process') {
            $requestId = post_int('solicitud_id');
            $state = post_string('estado');
            $comment = post_string('comentario_respuesta');

            if (!$requestId || !in_array($state, ['Aprobada', 'Rechazada'], true)) {
                throw new RuntimeException('La respuesta seleccionada no es válida.');
            }
            if (!can_process_space($pdo, $userId, $requestId, $isManager)) {
                throw new RuntimeException('No sos encargado de ese espacio en el turno solicitado.');
            }
            if ($state === 'Rechazada' && mb_strlen($comment) < 5) {
                throw new RuntimeException('Indicá brevemente el motivo del rechazo.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM solicitudes_espacios WHERE id = ? FOR UPDATE');
            $lock->execute([$requestId]);
            $request = $lock->fetch();
            if (!$request || $request['estado'] !== 'Pendiente') {
                throw new RuntimeException('La solicitud ya fue procesada o no existe.');
            }

            if ($state === 'Aprobada') {
                $conflict = $pdo->prepare(
                    "SELECT COUNT(*) FROM solicitudes_espacios
                     WHERE espacio_id = ? AND fecha_uso = ? AND estado = 'Aprobada' AND id <> ?
                       AND hora_inicio < ? AND hora_fin > ?"
                );
                $conflict->execute([$request['espacio_id'], $request['fecha_uso'], $requestId, $request['hora_fin'], $request['hora_inicio']]);
                if ((int) $conflict->fetchColumn() > 0) {
                    throw new RuntimeException('No se puede aprobar: ya existe una reserva aprobada que se superpone.');
                }
            }

            $update = $pdo->prepare(
                'UPDATE solicitudes_espacios
                 SET estado = ?, aprobador_id = ?, fecha_respuesta = NOW(), comentario_respuesta = ?
                 WHERE id = ?'
            );
            $update->execute([$state, $userId, $comment !== '' ? $comment : null, $requestId]);

            $type = $state === 'Aprobada' ? 'reserva_aprobada' : 'reserva_rechazada';
            $title = $state === 'Aprobada' ? 'Reserva aprobada' : 'Reserva rechazada';
            $message = $state === 'Aprobada'
                ? 'Tu solicitud de espacio fue aprobada para el ' . format_date($request['fecha_uso']) . '.'
                : 'Tu solicitud de espacio fue rechazada. Revisá el comentario del encargado.';
            notify_user($pdo, (int) $request['solicitante_id'], $type, $title, $message, 'solicitudes_espacios', $requestId);
            audit($pdo, $userId, 'GESTION_ESPACIO', 'solicitudes_espacios', $requestId, ['estado' => $state]);
            $pdo->commit();
            flash('success', 'La solicitud fue ' . mb_strtolower($state) . '.');
        } elseif ($action === 'cancel') {
            $requestId = post_int('solicitud_id');
            if (!$requestId || !$isProfessor) {
                throw new RuntimeException('No se pudo cancelar la solicitud.');
            }
            $stmt = $pdo->prepare("UPDATE solicitudes_espacios SET estado = 'Cancelada' WHERE id = ? AND solicitante_id = ? AND estado = 'Pendiente'");
            $stmt->execute([$requestId, $userId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('La solicitud ya no puede cancelarse.');
            }
            audit($pdo, $userId, 'CANCELACION_ESPACIO', 'solicitudes_espacios', $requestId);
            flash('success', 'La solicitud fue cancelada.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect('espacios.php');
}

$spaces = $pdo->query("SELECT id, nombre, descripcion, estado FROM espacios WHERE activo = 1 ORDER BY nombre")->fetchAll();
$stateFilter = $_GET['estado'] ?? '';
$where = [];
$params = [];

if (!$isManager) {
    $parts = [];
    if ($isProfessor) {
        $parts[] = 'se.solicitante_id = :profesor';
        $params['profesor'] = $userId;
    }
    if ($isAssignedManager) {
        $parts[] = "EXISTS (
            SELECT 1 FROM encargados_espacios ee
            WHERE ee.espacio_id = se.espacio_id AND ee.usuario_id = :encargado AND ee.activo = 1
              AND ee.turno = CASE WHEN se.hora_inicio < '13:30:00' THEN 'TM' ELSE 'TT' END
        )";
        $params['encargado'] = $userId;
    }
    $where[] = '(' . implode(' OR ', $parts) . ')';
}
if (is_string($stateFilter) && in_array($stateFilter, $allowedStates, true)) {
    $where[] = 'se.estado = :estado';
    $params['estado'] = $stateFilter;
}

$sql = "SELECT se.*, e.nombre AS espacio_nombre, e.estado AS espacio_estado,
               sol.nombre AS solicitante_nombre, sol.apellido AS solicitante_apellido,
               apr.nombre AS aprobador_nombre, apr.apellido AS aprobador_apellido
        FROM solicitudes_espacios se
        JOIN espacios e ON e.id = se.espacio_id
        JOIN usuarios sol ON sol.id = se.solicitante_id
        LEFT JOIN usuarios apr ON apr.id = se.aprobador_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD(se.estado,'Pendiente','Aprobada','Rechazada','Cancelada'), se.fecha_uso, se.hora_inicio";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Espacios';
$activePage = 'espacios';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div><h2><?= ($isManager || $isAssignedManager) ? 'Reservas de espacios' : 'Mis reservas' ?></h2><p><?= ($isManager || $isAssignedManager) ? 'Aprobá solicitudes según el espacio y el turno asignado.' : 'Reservá espacios disponibles para actividades docentes.' ?></p></div>
    <div class="page-actions"><?php if ($isProfessor): ?><button class="btn btn-primary" type="button" data-dialog-open="newSpaceRequest"><?= icon('plus', 17) ?> Solicitar espacio</button><?php endif; ?></div>
</section>

<form class="filter-bar" method="get">
    <div class="search-box"><?= icon('search', 18) ?><input type="search" placeholder="Buscar por espacio, motivo o solicitante" data-table-filter="#spacesTable"></div>
    <select class="select-control filter-select" name="estado" onchange="this.form.submit()"><option value="">Todos los estados</option><?php foreach ($allowedStates as $state): ?><option value="<?= e($state) ?>" <?= $stateFilter === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?></select>
    <?php if ($stateFilter): ?><a class="btn btn-ghost" href="<?= e(url('espacios.php')) ?>">Limpiar</a><?php endif; ?>
</form>

<?php if (!$requests): ?>
<section class="empty-state"><div class="empty-icon"><?= icon('building', 30) ?></div><h2>No hay reservas para mostrar</h2><p><?= $isProfessor ? 'Podés iniciar una solicitud para un espacio disponible.' : 'No hay solicitudes pendientes para tus espacios y turnos.' ?></p><?php if ($isProfessor): ?><button class="btn btn-primary" type="button" data-dialog-open="newSpaceRequest">Solicitar espacio</button><?php endif; ?></section>
<?php else: ?>
<div class="table-wrap"><table id="spacesTable"><thead><tr><th>Espacio</th><th>Fecha y horario</th><?php if ($isManager || $isAssignedManager): ?><th>Solicitante</th><?php endif; ?><th>Turno</th><th>Estado</th><th>Respuesta</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($requests as $request): ?>
<tr>
    <td><span class="cell-title">#<?= (int) $request['id'] ?> · <?= e($request['espacio_nombre']) ?></span><span class="cell-subtitle"><?= e($request['motivo']) ?></span></td>
    <td><span class="cell-title"><?= e(format_date($request['fecha_uso'])) ?></span><span class="cell-subtitle"><?= e(format_time($request['hora_inicio'])) ?> a <?= e(format_time($request['hora_fin'])) ?></span></td>
    <?php if ($isManager || $isAssignedManager): ?><td><?= e($request['solicitante_apellido'] . ', ' . $request['solicitante_nombre']) ?></td><?php endif; ?>
    <td><span class="status status-info"><?= e(infer_shift($request['hora_inicio'])) ?></span></td>
    <td><span class="status <?= e(status_class($request['estado'])) ?>"><?= e($request['estado']) ?></span></td>
    <td><?php if ($request['aprobador_nombre']): ?><span class="cell-title"><?= e($request['aprobador_apellido'] . ', ' . $request['aprobador_nombre']) ?></span><span class="cell-subtitle"><?= e(format_date($request['fecha_respuesta'], true)) ?></span><?php else: ?><span class="muted">Sin respuesta</span><?php endif; ?></td>
    <td class="actions-cell">
        <button class="btn btn-sm" type="button" data-dialog-open="spaceDetail<?= (int) $request['id'] ?>">Ver</button>
        <?php if ($request['estado'] === 'Pendiente' && can_process_space($pdo, $userId, (int) $request['id'], $isManager)): ?><button class="btn btn-sm btn-primary" type="button" data-dialog-open="spaceProcess<?= (int) $request['id'] ?>">Responder</button><?php endif; ?>
        <?php if ($isProfessor && (int) $request['solicitante_id'] === $userId && $request['estado'] === 'Pendiente'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="¿Cancelar esta solicitud?">Cancelar</button></form><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php if ($isProfessor): ?>
<dialog class="modal" id="newSpaceRequest" <?= ($_GET['action'] ?? '') === 'new' ? 'data-auto-open' : '' ?>>
<header class="modal-header"><div><h3>Solicitar un espacio</h3><p>Solo se muestran espacios activos. La aprobación depende del encargado del turno.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header>
<form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create">
<div class="form-grid">
<div class="form-group full"><label>Espacio</label><select class="select-control" name="espacio_id" required><option value="">Seleccionar…</option><?php foreach ($spaces as $space): ?><option value="<?= (int) $space['id'] ?>" <?= $space['estado'] !== 'Disponible' ? 'disabled' : '' ?>><?= e($space['nombre']) ?><?= $space['estado'] !== 'Disponible' ? ' · ' . e($space['estado']) : '' ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label>Fecha de uso</label><input class="input-control" type="date" name="fecha_uso" min="<?= e(date('Y-m-d')) ?>" required></div>
<div class="form-group"><label>Horario</label><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><input class="input-control" type="time" name="hora_inicio" required><input class="input-control" type="time" name="hora_fin" required></div></div>
<div class="form-group full"><label>Motivo</label><textarea class="textarea-control" name="motivo" minlength="10" maxlength="2000" required placeholder="Actividad, curso involucrado y recursos que vas a utilizar."></textarea></div>
</div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Enviar solicitud</button></div>
</form></dialog>
<?php endif; ?>

<?php foreach ($requests as $request): ?>
<dialog class="modal" id="spaceDetail<?= (int) $request['id'] ?>"><header class="modal-header"><div><h3><?= e($request['espacio_nombre']) ?></h3><p>Solicitud #<?= (int) $request['id'] ?> · <?= e(format_date($request['fecha_solicitud'], true)) ?></p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><div class="modal-body">
<div class="detail-list"><div class="detail-item"><span>Fecha y horario</span><strong><?= e(format_date($request['fecha_uso'])) ?> · <?= e(format_time($request['hora_inicio'])) ?> a <?= e(format_time($request['hora_fin'])) ?></strong></div><div class="detail-item"><span>Turno</span><strong><?= e(infer_shift($request['hora_inicio'])) ?></strong></div><div class="detail-item"><span>Solicitante</span><strong><?= e($request['solicitante_nombre'] . ' ' . $request['solicitante_apellido']) ?></strong></div><div class="detail-item"><span>Estado</span><strong><?= e($request['estado']) ?></strong></div></div>
<div class="section-divider"></div><div class="form-group"><label>Motivo</label><p class="muted"><?= nl2br(e($request['motivo'])) ?></p></div>
<?php if ($request['comentario_respuesta']): ?><div class="form-group"><label>Comentario de respuesta</label><p class="muted"><?= nl2br(e($request['comentario_respuesta'])) ?></p></div><?php endif; ?>
<div class="form-actions"><button class="btn" type="button" data-dialog-close>Cerrar</button></div></div></dialog>

<?php if ($request['estado'] === 'Pendiente' && can_process_space($pdo, $userId, (int) $request['id'], $isManager)): ?>
<dialog class="modal" id="spaceProcess<?= (int) $request['id'] ?>"><header class="modal-header"><div><h3>Responder solicitud #<?= (int) $request['id'] ?></h3><p><?= e($request['espacio_nombre']) ?> · <?= e(format_date($request['fecha_uso'])) ?> · <?= e(format_time($request['hora_inicio'])) ?></p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="process"><input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>"><div class="form-grid"><div class="form-group full"><label>Decisión</label><select class="select-control" name="estado" required><option value="Aprobada">Aprobar reserva</option><option value="Rechazada">Rechazar reserva</option></select></div><div class="form-group full"><label>Comentario</label><textarea class="textarea-control" name="comentario_respuesta" placeholder="Obligatorio al rechazar. También podés dejar indicaciones de uso."></textarea></div></div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar respuesta</button></div></form></dialog>
<?php endif; ?>
<?php endforeach; ?>
<script>document.querySelectorAll('[data-auto-open]').forEach((dialog)=>dialog.showModal());</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
