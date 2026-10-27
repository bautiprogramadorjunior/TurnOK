<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_roles(['Alumno', 'Profesor', 'Administrativo', 'Directivo']);
$pdo = db();
$userId = (int) $user['id'];
$isRequester = has_any_role(['Alumno', 'Profesor']);
$isManager = can_manage_institution();
$allowedStates = ['Pendiente', 'Aprobado', 'Rechazado', 'Finalizado'];
$allowedPriorities = ['Baja', 'Normal', 'Alta'];

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'create') {
            if (!$isRequester) {
                throw new RuntimeException('Tu perfil no puede crear solicitudes de trámite.');
            }

            $typeId = post_int('tipo_tramite_id');
            $reason = post_string('motivo');
            $priority = post_string('prioridad', 'Normal');

            if (!$typeId || mb_strlen($reason) < 10) {
                throw new RuntimeException('Elegí un tipo y explicá el motivo con al menos 10 caracteres.');
            }
            if (!in_array($priority, $allowedPriorities, true)) {
                throw new RuntimeException('La prioridad seleccionada no es válida.');
            }

            $typeCheck = $pdo->prepare('SELECT id FROM tipos_tramite WHERE id = ? AND activo = 1');
            $typeCheck->execute([$typeId]);
            if (!$typeCheck->fetchColumn()) {
                throw new RuntimeException('El tipo de trámite ya no está disponible.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO solicitudes_tramites (solicitante_id, tipo_tramite_id, motivo, prioridad)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $typeId, $reason, $priority]);
            $requestId = (int) $pdo->lastInsertId();
            audit($pdo, $userId, 'SOLICITUD_TRAMITE', 'solicitudes_tramites', $requestId, ['prioridad' => $priority]);
            flash('success', 'La solicitud de trámite fue enviada.');
        } elseif ($action === 'manage') {
            if (!$isManager) {
                throw new RuntimeException('No tenés permisos para gestionar trámites.');
            }

            $requestId = post_int('solicitud_id');
            $state = post_string('estado');
            $responsibleId = post_int('responsable_id');
            $comments = post_string('comentarios');

            if (!$requestId || !in_array($state, $allowedStates, true)) {
                throw new RuntimeException('Los datos de gestión son inválidos.');
            }

            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT solicitante_id, estado FROM solicitudes_tramites WHERE id = ? FOR UPDATE');
            $lock->execute([$requestId]);
            $request = $lock->fetch();
            if (!$request) {
                throw new RuntimeException('La solicitud no existe.');
            }

            if (!$responsibleId) {
                $responsibleId = $userId;
            }

            $responsibleCheck = $pdo->prepare(
                "SELECT COUNT(*) FROM usuario_roles ur
                 JOIN roles r ON r.id = ur.rol_id
                 JOIN usuarios u ON u.id = ur.usuario_id AND u.activo = 1
                 WHERE ur.usuario_id = ? AND r.nombre IN ('Administrativo','Directivo')"
            );
            $responsibleCheck->execute([$responsibleId]);
            if (!(int) $responsibleCheck->fetchColumn()) {
                throw new RuntimeException('El responsable debe ser administrativo o directivo.');
            }

            $update = $pdo->prepare(
                'UPDATE solicitudes_tramites SET estado = ?, responsable_id = ?, comentarios = ? WHERE id = ?'
            );
            $update->execute([$state, $responsibleId, $comments !== '' ? $comments : null, $requestId]);

            if ($state === 'Aprobado') {
                $date = post_string('turno_fecha');
                $time = post_string('turno_hora');
                $place = post_string('turno_lugar');
                $observations = post_string('turno_observaciones');

                if ($date !== '' || $time !== '' || $place !== '') {
                    if (!valid_date($date) || !valid_time($time) || $place === '') {
                        throw new RuntimeException('Para asignar un turno, completá fecha, hora y lugar correctamente.');
                    }

                    $existing = $pdo->prepare('SELECT id FROM turnos_tramites WHERE solicitud_tramite_id = ? ORDER BY id LIMIT 1');
                    $existing->execute([$requestId]);
                    $turnId = $existing->fetchColumn();
                    if ($turnId) {
                        $turn = $pdo->prepare('UPDATE turnos_tramites SET responsable_id = ?, fecha = ?, hora = ?, lugar = ?, observaciones = ? WHERE id = ?');
                        $turn->execute([$responsibleId, $date, $time, $place, $observations !== '' ? $observations : null, $turnId]);
                    } else {
                        $turn = $pdo->prepare('INSERT INTO turnos_tramites (solicitud_tramite_id, responsable_id, fecha, hora, lugar, observaciones) VALUES (?, ?, ?, ?, ?, ?)');
                        $turn->execute([$requestId, $responsibleId, $date, $time, $place, $observations !== '' ? $observations : null]);
                    }
                }
            }

            if ($state !== $request['estado']) {
                if ($state === 'Aprobado') {
                    notify_user($pdo, (int) $request['solicitante_id'], 'tramite_aprobado', 'Trámite aprobado', 'Tu solicitud de trámite fue aprobada. Revisá el detalle y el turno asignado.', 'solicitudes_tramites', $requestId);
                } elseif ($state === 'Rechazado') {
                    notify_user($pdo, (int) $request['solicitante_id'], 'tramite_rechazado', 'Trámite rechazado', 'Tu solicitud de trámite fue rechazada. Revisá los comentarios del responsable.', 'solicitudes_tramites', $requestId);
                }
            }

            audit($pdo, $userId, 'GESTION_TRAMITE', 'solicitudes_tramites', $requestId, ['estado' => $state, 'responsable_id' => $responsibleId]);
            $pdo->commit();
            flash('success', 'El trámite fue actualizado.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect('tramites.php');
}

$types = $pdo->query('SELECT id, nombre FROM tipos_tramite WHERE activo = 1 ORDER BY nombre')->fetchAll();
$responsibles = [];
if ($isManager) {
    $responsibles = $pdo->query(
        "SELECT DISTINCT u.id, u.nombre, u.apellido
         FROM usuarios u
         JOIN usuario_roles ur ON ur.usuario_id = u.id
         JOIN roles r ON r.id = ur.rol_id
         WHERE u.activo = 1 AND r.nombre IN ('Administrativo','Directivo')
         ORDER BY u.apellido, u.nombre"
    )->fetchAll();
}

$stateFilter = $_GET['estado'] ?? '';
$params = [];
$where = [];
if (!$isManager) {
    $where[] = 'st.solicitante_id = ?';
    $params[] = $userId;
}
if (is_string($stateFilter) && in_array($stateFilter, $allowedStates, true)) {
    $where[] = 'st.estado = ?';
    $params[] = $stateFilter;
}

$sql = "SELECT st.*, tt.nombre AS tipo_nombre,
               s.nombre AS solicitante_nombre, s.apellido AS solicitante_apellido,
               resp.nombre AS responsable_nombre, resp.apellido AS responsable_apellido,
               turn.id AS turno_id, turn.fecha AS turno_fecha, turn.hora AS turno_hora,
               turn.lugar AS turno_lugar, turn.observaciones AS turno_observaciones
        FROM solicitudes_tramites st
        JOIN tipos_tramite tt ON tt.id = st.tipo_tramite_id
        JOIN usuarios s ON s.id = st.solicitante_id
        LEFT JOIN usuarios resp ON resp.id = st.responsable_id
        LEFT JOIN turnos_tramites turn ON turn.id = (
            SELECT t2.id FROM turnos_tramites t2 WHERE t2.solicitud_tramite_id = st.id ORDER BY t2.id DESC LIMIT 1
        )";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= " ORDER BY FIELD(st.estado,'Pendiente','Aprobado','Finalizado','Rechazado'), FIELD(st.prioridad,'Alta','Normal','Baja'), st.fecha_solicitud DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$pageTitle = 'Trámites';
$activePage = 'tramites';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div><h2><?= $isManager ? 'Gestión de trámites' : 'Mis trámites' ?></h2><p><?= $isManager ? 'Revisá solicitudes, asigná responsables y coordiná turnos.' : 'Iniciá solicitudes y seguí cada etapa desde acá.' ?></p></div>
    <div class="page-actions">
        <?php if ($isRequester): ?><button class="btn btn-primary" type="button" data-dialog-open="newTramite"><?= icon('plus', 17) ?> Solicitar trámite</button><?php endif; ?>
    </div>
</section>

<form class="filter-bar" method="get">
    <div class="search-box"><?= icon('search', 18) ?><input type="search" placeholder="Buscar por tipo, persona o motivo" data-table-filter="#tramitesTable"></div>
    <select class="select-control filter-select" name="estado" onchange="this.form.submit()">
        <option value="">Todos los estados</option>
        <?php foreach ($allowedStates as $state): ?><option value="<?= e($state) ?>" <?= $stateFilter === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?>
    </select>
    <?php if ($stateFilter): ?><a class="btn btn-ghost" href="<?= e(url('tramites.php')) ?>">Limpiar</a><?php endif; ?>
</form>

<?php if (!$requests): ?>
    <section class="empty-state"><div class="empty-icon"><?= icon('file', 30) ?></div><h2>No hay trámites para mostrar</h2><p><?= $isRequester ? 'Cuando realices una solicitud vas a poder seguirla en esta pantalla.' : 'No hay solicitudes que coincidan con el filtro actual.' ?></p><?php if ($isRequester): ?><button class="btn btn-primary" type="button" data-dialog-open="newTramite">Crear primera solicitud</button><?php endif; ?></section>
<?php else: ?>
    <div class="table-wrap">
        <table id="tramitesTable">
            <thead><tr><th>Solicitud</th><?php if ($isManager): ?><th>Solicitante</th><?php endif; ?><th>Prioridad</th><th>Estado</th><th>Responsable</th><th>Turno</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><span class="cell-title">#<?= (int) $request['id'] ?> · <?= e($request['tipo_nombre']) ?></span><span class="cell-subtitle"><?= e($request['motivo']) ?> · <?= e(format_date($request['fecha_solicitud'], true)) ?></span></td>
                    <?php if ($isManager): ?><td><span class="cell-title"><?= e($request['solicitante_apellido'] . ', ' . $request['solicitante_nombre']) ?></span></td><?php endif; ?>
                    <td><span class="status <?= e(status_class($request['prioridad'])) ?>"><?= e($request['prioridad']) ?></span></td>
                    <td><span class="status <?= e(status_class($request['estado'])) ?>"><?= e($request['estado']) ?></span></td>
                    <td><?= $request['responsable_nombre'] ? e($request['responsable_apellido'] . ', ' . $request['responsable_nombre']) : '<span class="muted">Sin asignar</span>' ?></td>
                    <td><?php if ($request['turno_id']): ?><span class="cell-title"><?= e(format_date($request['turno_fecha'])) ?> · <?= e(format_time($request['turno_hora'])) ?></span><span class="cell-subtitle"><?= e($request['turno_lugar']) ?></span><?php else: ?><span class="muted">Sin turno</span><?php endif; ?></td>
                    <td class="actions-cell"><button class="btn btn-sm" type="button" data-dialog-open="detailTramite<?= (int) $request['id'] ?>"><?= $isManager ? 'Gestionar' : 'Ver detalle' ?></button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($isRequester): ?>
<dialog class="modal" id="newTramite" <?= ($_GET['action'] ?? '') === 'new' ? 'data-auto-open' : '' ?>>
    <header class="modal-header"><div><h3>Solicitar un trámite</h3><p>La solicitud será enviada al equipo administrativo.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header>
    <form method="post" class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="form-grid">
            <div class="form-group full"><label for="tipo_tramite_id">Tipo de trámite</label><select class="select-control" id="tipo_tramite_id" name="tipo_tramite_id" required><option value="">Seleccionar…</option><?php foreach ($types as $type): ?><option value="<?= (int) $type['id'] ?>"><?= e($type['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="form-group full"><label for="motivo">Motivo o detalle</label><textarea class="textarea-control" id="motivo" name="motivo" minlength="10" maxlength="2000" required placeholder="Explicá brevemente qué necesitás y para qué fecha, si corresponde."></textarea></div>
            <div class="form-group"><label for="prioridad">Prioridad</label><select class="select-control" id="prioridad" name="prioridad"><option>Baja</option><option selected>Normal</option><option>Alta</option></select><span class="form-help">Usá “Alta” solo cuando exista una fecha límite cercana.</span></div>
        </div>
        <div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Enviar solicitud</button></div>
    </form>
</dialog>
<?php endif; ?>

<?php foreach ($requests as $request): ?>
<dialog class="modal" id="detailTramite<?= (int) $request['id'] ?>">
    <header class="modal-header"><div><h3>Trámite #<?= (int) $request['id'] ?></h3><p><?= e($request['tipo_nombre']) ?> · creado el <?= e(format_date($request['fecha_solicitud'], true)) ?></p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header>
    <div class="modal-body">
        <div class="detail-list">
            <div class="detail-item"><span>Solicitante</span><strong><?= e($request['solicitante_nombre'] . ' ' . $request['solicitante_apellido']) ?></strong></div>
            <div class="detail-item"><span>Estado actual</span><strong><?= e($request['estado']) ?></strong></div>
            <div class="detail-item"><span>Prioridad</span><strong><?= e($request['prioridad']) ?></strong></div>
            <div class="detail-item"><span>Responsable</span><strong><?= e(trim(($request['responsable_nombre'] ?? '') . ' ' . ($request['responsable_apellido'] ?? '')) ?: 'Sin asignar') ?></strong></div>
        </div>
        <div class="section-divider"></div>
        <div class="form-group"><label>Motivo</label><p class="muted"><?= nl2br(e($request['motivo'])) ?></p></div>
        <?php if ($request['comentarios']): ?><div class="form-group"><label>Comentarios del responsable</label><p class="muted"><?= nl2br(e($request['comentarios'])) ?></p></div><?php endif; ?>
        <?php if ($request['turno_id']): ?><div class="section-divider"></div><div class="detail-list"><div class="detail-item"><span>Fecha y hora</span><strong><?= e(format_date($request['turno_fecha'])) ?> · <?= e(format_time($request['turno_hora'])) ?></strong></div><div class="detail-item"><span>Lugar</span><strong><?= e($request['turno_lugar']) ?></strong></div></div><?php endif; ?>

        <?php if ($isManager): ?>
            <div class="section-divider"></div>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="manage"><input type="hidden" name="solicitud_id" value="<?= (int) $request['id'] ?>">
                <div class="form-grid">
                    <div class="form-group"><label>Estado</label><select class="select-control" name="estado" required><?php foreach ($allowedStates as $state): ?><option <?= $request['estado'] === $state ? 'selected' : '' ?>><?= e($state) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Responsable</label><select class="select-control" name="responsable_id"><option value="">Asignarme a mí</option><?php foreach ($responsibles as $responsible): ?><option value="<?= (int) $responsible['id'] ?>" <?= (int) ($request['responsable_id'] ?? 0) === (int) $responsible['id'] ? 'selected' : '' ?>><?= e($responsible['apellido'] . ', ' . $responsible['nombre']) ?></option><?php endforeach; ?></select></div>
                    <div class="form-group full"><label>Comentarios</label><textarea class="textarea-control" name="comentarios" placeholder="Dejá una indicación clara para la persona solicitante."><?= e($request['comentarios'] ?? '') ?></textarea></div>
                    <div class="form-group"><label>Fecha de turno</label><input class="input-control" type="date" name="turno_fecha" value="<?= e($request['turno_fecha'] ?? '') ?>"></div>
                    <div class="form-group"><label>Hora de turno</label><input class="input-control" type="time" name="turno_hora" value="<?= e(isset($request['turno_hora']) ? format_time($request['turno_hora']) : '') ?>"></div>
                    <div class="form-group full"><label>Lugar del turno</label><input class="input-control" name="turno_lugar" maxlength="150" value="<?= e($request['turno_lugar'] ?? '') ?>" placeholder="Ej.: Secretaría, planta baja"></div>
                    <div class="form-group full"><label>Observaciones del turno</label><textarea class="textarea-control" name="turno_observaciones"><?= e($request['turno_observaciones'] ?? '') ?></textarea></div>
                </div>
                <div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar cambios</button></div>
            </form>
        <?php else: ?>
            <div class="form-actions"><button class="btn" type="button" data-dialog-close>Cerrar</button></div>
        <?php endif; ?>
    </div>
</dialog>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-auto-open]').forEach((dialog) => dialog.showModal());
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
