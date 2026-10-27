<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_cargo('Preceptor');
$pdo = db();
$userId = (int) $user['id'];
$selectedDate = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : date('Y-m-d');

if (!valid_date($selectedDate) || $selectedDate > date('Y-m-d')) {
    flash('danger', 'La fecha seleccionada no es válida o está en el futuro.');
    redirect('preceptoria.php');
}

$assignedCourseStmt = $pdo->prepare(
    'SELECT cu.id FROM preceptor_curso pc
     JOIN cursos cu ON cu.id = pc.curso_id AND cu.activo = 1
     WHERE pc.usuario_id = ?'
);
$assignedCourseStmt->execute([$userId]);
$assignedCourseIds = array_map('intval', $assignedCourseStmt->fetchAll(PDO::FETCH_COLUMN));

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        $courseId = post_int('curso_id');
        if (!$courseId || !in_array($courseId, $assignedCourseIds, true)) {
            throw new RuntimeException('Ese curso no está asignado a tu cuenta.');
        }

        if ($action === 'save_attendance') {
            $date = post_string('fecha');
            $attendance = $_POST['attendance'] ?? [];
            $allowedStatuses = ['Presente', 'Ausente', 'Tarde', 'Justificado'];

            if (!valid_date($date) || $date > date('Y-m-d')) {
                throw new RuntimeException('La fecha de asistencia debe ser válida y no puede estar en el futuro.');
            }
            if (!is_array($attendance)) {
                throw new RuntimeException('Los datos de asistencia no son válidos.');
            }

            $studentCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM alumno_curso ac
                 JOIN usuarios u ON u.id = ac.usuario_id AND u.activo = 1
                 WHERE ac.usuario_id = ? AND ac.curso_id = ? AND ac.anio_lectivo = YEAR(CURDATE())
                   AND EXISTS (
                       SELECT 1 FROM usuario_roles ur
                       JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.nombre = 'Alumno'
                       WHERE ur.usuario_id = ac.usuario_id
                   )"
            );
            $upsert = $pdo->prepare(
                'INSERT INTO preceptoria_asistencias (curso_id, alumno_id, fecha, estado, registrado_por)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE estado = VALUES(estado), registrado_por = VALUES(registrado_por), updated_at = CURRENT_TIMESTAMP'
            );
            $updated = 0;
            $pdo->beginTransaction();
            foreach ($attendance as $studentKey => $status) {
                if (!is_string($status) || $status === '') {
                    continue;
                }
                $studentId = filter_var((string) $studentKey, FILTER_VALIDATE_INT);
                if (!$studentId || !in_array($status, $allowedStatuses, true)) {
                    throw new RuntimeException('Hay un estado o alumno inválido en la asistencia.');
                }
                $studentCheck->execute([(int) $studentId, $courseId]);
                if (!(int) $studentCheck->fetchColumn()) {
                    throw new RuntimeException('Solo podés registrar asistencia de alumnos activos de tus cursos.');
                }
                $upsert->execute([$courseId, (int) $studentId, $date, $status, $userId]);
                $updated++;
            }
            if ($updated === 0) {
                throw new RuntimeException('Seleccioná al menos un estado de asistencia antes de guardar.');
            }
            audit($pdo, $userId, 'PRECEPTORIA_ASISTENCIA', 'preceptoria_asistencias', null, [
                'curso_id' => $courseId,
                'fecha' => $date,
                'alumnos_actualizados' => $updated,
            ]);
            $pdo->commit();
            flash('success', 'Se guardó la asistencia de ' . $updated . ' alumno(s).');
            redirect('preceptoria.php?fecha=' . rawurlencode($date) . '#curso-' . $courseId);
        }

        if ($action === 'add_observation') {
            $studentId = post_int('alumno_id');
            $observation = post_string('observacion');
            if (!$studentId || mb_strlen($observation) < 5 || mb_strlen($observation) > 2000) {
                throw new RuntimeException('La observación debe tener entre 5 y 2000 caracteres.');
            }

            $studentCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM alumno_curso ac
                 JOIN usuarios u ON u.id = ac.usuario_id AND u.activo = 1
                 WHERE ac.usuario_id = ? AND ac.curso_id = ? AND ac.anio_lectivo = YEAR(CURDATE())
                   AND EXISTS (
                       SELECT 1 FROM usuario_roles ur
                       JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.nombre = 'Alumno'
                       WHERE ur.usuario_id = ac.usuario_id
                   )"
            );
            $studentCheck->execute([$studentId, $courseId]);
            if (!(int) $studentCheck->fetchColumn()) {
                throw new RuntimeException('Solo podés registrar observaciones de alumnos activos de tus cursos.');
            }

            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO preceptoria_observaciones (curso_id, alumno_id, preceptor_id, observacion)
                 VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$courseId, $studentId, $userId, $observation]);
            $observationId = (int) $pdo->lastInsertId();
            audit($pdo, $userId, 'PRECEPTORIA_OBSERVACION', 'preceptoria_observaciones', $observationId, [
                'curso_id' => $courseId,
                'alumno_id' => $studentId,
            ]);
            $pdo->commit();
            flash('success', 'La observación quedó registrada para el seguimiento del curso.');
            redirect('preceptoria.php?fecha=' . rawurlencode($selectedDate) . '#curso-' . $courseId);
        }

        throw new RuntimeException('La acción solicitada no es válida.');
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('TurnOK preceptoria error: ' . $exception->getMessage());
        flash('danger', 'No se pudo guardar la información. Revisá que la migración de Preceptoría esté aplicada.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect('preceptoria.php?fecha=' . rawurlencode($selectedDate));
}

$studentsStmt = $pdo->prepare(
    "SELECT cu.id AS curso_id, cu.anio, cu.division, cu.turno, e.nombre AS especialidad,
            u.id AS alumno_id, u.nombre AS alumno_nombre, u.apellido AS alumno_apellido,
            pa.estado AS asistencia_estado
     FROM preceptor_curso pc
     JOIN cursos cu ON cu.id = pc.curso_id AND cu.activo = 1
     LEFT JOIN especialidades e ON e.id = cu.especialidad_id
     LEFT JOIN alumno_curso ac ON ac.curso_id = cu.id AND ac.anio_lectivo = YEAR(CURDATE())
     LEFT JOIN usuarios u ON u.id = ac.usuario_id AND u.activo = 1
       AND EXISTS (
           SELECT 1 FROM usuario_roles ur
           JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.nombre = 'Alumno'
           WHERE ur.usuario_id = u.id
       )
     LEFT JOIN preceptoria_asistencias pa
       ON pa.curso_id = cu.id AND pa.alumno_id = u.id AND pa.fecha = ?
     WHERE pc.usuario_id = ?
     ORDER BY cu.anio, e.nombre, cu.division, cu.turno, u.apellido, u.nombre"
);
$studentsStmt->execute([$selectedDate, $userId]);
$rows = $studentsStmt->fetchAll();

$courses = [];
foreach ($rows as $row) {
    $courseId = (int) $row['curso_id'];
    if (!isset($courses[$courseId])) {
        $courses[$courseId] = [
            'anio' => $row['anio'],
            'division' => $row['division'],
            'turno' => $row['turno'],
            'especialidad' => $row['especialidad'],
            'students' => [],
            'meetings' => [],
            'observations' => [],
        ];
    }
    if ($row['alumno_id'] !== null) {
        $courses[$courseId]['students'][(int) $row['alumno_id']] = $row;
    }
}

$meetingsStmt = $pdo->prepare(
    "SELECT rc.curso_id, r.titulo, r.fecha, r.hora_inicio, r.hora_fin, r.lugar
     FROM preceptor_curso pc
     JOIN reunion_cursos rc ON rc.curso_id = pc.curso_id
     JOIN reuniones r ON r.id = rc.reunion_id AND r.fecha >= CURDATE()
     WHERE pc.usuario_id = ?
     ORDER BY r.fecha, r.hora_inicio
     LIMIT 30"
);
$meetingsStmt->execute([$userId]);
foreach ($meetingsStmt->fetchAll() as $meeting) {
    $courseId = (int) $meeting['curso_id'];
    if (isset($courses[$courseId])) {
        $courses[$courseId]['meetings'][] = $meeting;
    }
}

$observationsStmt = $pdo->prepare(
    "SELECT o.curso_id, o.alumno_id, o.observacion, o.created_at
     FROM preceptoria_observaciones o
     JOIN preceptor_curso pc ON pc.curso_id = o.curso_id AND pc.usuario_id = ?
     JOIN alumno_curso ac ON ac.usuario_id = o.alumno_id AND ac.curso_id = o.curso_id AND ac.anio_lectivo = YEAR(CURDATE())
     JOIN usuarios u ON u.id = o.alumno_id AND u.activo = 1
     WHERE o.curso_id = ?
     ORDER BY o.created_at DESC"
);
foreach ($courses as $courseId => $course) {
    $observationsStmt->execute([$userId, $courseId]);
    foreach ($observationsStmt->fetchAll() as $observation) {
        $studentId = (int) $observation['alumno_id'];
        if (isset($courses[$courseId]['students'][$studentId])) {
            $courses[$courseId]['observations'][$studentId][] = $observation;
        }
    }
}

$todayAttendance = 0;
$courseCount = count($courses);
if ($assignedCourseIds) {
    $placeholders = implode(',', array_fill(0, count($assignedCourseIds), '?'));
    $attendanceCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM preceptoria_asistencias
         WHERE curso_id IN ($placeholders) AND fecha = CURDATE()"
    );
    $attendanceCountStmt->execute($assignedCourseIds);
    $todayAttendance = (int) $attendanceCountStmt->fetchColumn();
}

$pageTitle = 'Preceptoría';
$activePage = 'preceptoria';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div>
        <h2>Seguimiento de tus cursos</h2>
        <p>Registrá asistencia, consultá reuniones y anotá observaciones de alumnos en tus cursos asignados.</p>
    </div>
    <?php if ($courses): ?><div class="page-actions">
        <a class="btn btn-primary" href="#asistencia">Asistencia por fecha</a>
        <a class="btn" href="#observaciones">Observaciones</a>
        <a class="btn" href="#reuniones-curso">Reuniones</a>
    </div><?php endif; ?>
</section>

<section class="stats-grid" aria-label="Resumen de preceptoría">
    <article class="stat-card"><div class="stat-top"><span>CURSOS A CARGO</span><span class="stat-icon"><?= icon('users', 19) ?></span></div><strong><?= $courseCount ?></strong><p>Asignados a tu cuenta</p></article>
    <article class="stat-card"><div class="stat-top"><span>ASISTENCIAS DE HOY</span><span class="stat-icon"><?= icon('check', 19) ?></span></div><strong><?= $todayAttendance ?></strong><p>Alumnos con estado registrado</p></article>
    <article class="stat-card"><div class="stat-top"><span>REUNIONES PRÓXIMAS</span><span class="stat-icon"><?= icon('calendar', 19) ?></span></div><strong><?= array_sum(array_map(static fn(array $course): int => count($course['meetings']), $courses)) ?></strong><p>Convocatorias de tus cursos</p></article>
</section>

<?php if (!$courses): ?>
    <section class="empty-state">
        <div class="empty-icon"><?= icon('users', 30) ?></div>
        <h2>No tenés cursos asignados</h2>
        <p>Pedile al Directivo con cargo de Rector que revise tu asignación de cursos.</p>
    </section>
<?php else: ?>
    <section class="panel" id="asistencia" style="margin-bottom:18px">
        <header class="panel-header"><div><h3>Asistencia por fecha</h3><p>Elegí el día y consultá o actualizá el registro de cada curso asignado.</p></div></header>
        <div class="panel-body">
            <form class="filter-bar" method="get">
                <label class="form-group" for="attendanceDate"><span>Fecha de asistencia</span><input class="input-control" id="attendanceDate" type="date" name="fecha" value="<?= e($selectedDate) ?>" max="<?= e(date('Y-m-d')) ?>"></label>
                <button class="btn btn-primary" type="submit">Ver asistencia</button>
            </form>
            <?php foreach ($courses as $courseId => $course): ?>
                <article class="panel" id="curso-<?= (int) $courseId ?>" style="margin:14px 0">
                    <header class="panel-header">
                        <div><h4><?= e(course_label($course)) ?></h4><p><?= count($course['students']) ?> <?= count($course['students']) === 1 ? 'alumno activo' : 'alumnos activos' ?> · <?= e(format_date($selectedDate)) ?></p></div>
                    </header>
                    <div class="panel-body">
                        <?php if (!$course['students']): ?>
                            <p class="muted">Todavía no hay alumnos activos inscriptos en este curso para el ciclo lectivo actual.</p>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="save_attendance">
                                <input type="hidden" name="curso_id" value="<?= (int) $courseId ?>">
                                <input type="hidden" name="fecha" value="<?= e($selectedDate) ?>">
                                <div class="table-wrap">
                                    <table>
                                        <thead><tr><th>Apellido</th><th>Nombre</th><th>Estado guardado</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($course['students'] as $studentId => $student): ?>
                                            <tr>
                                                <td><?= e($student['alumno_apellido']) ?></td>
                                                <td><?= e($student['alumno_nombre']) ?></td>
                                                <td>
                                                    <select class="select-control" name="attendance[<?= (int) $studentId ?>]">
                                                        <option value="">Sin registrar</option>
                                                        <?php foreach (['Presente', 'Ausente', 'Tarde', 'Justificado'] as $status): ?>
                                                            <option value="<?= e($status) ?>" <?= $student['asistencia_estado'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar asistencia de este curso</button></div>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel" id="observaciones" style="margin-bottom:18px">
        <header class="panel-header"><div><h3>Observaciones por alumno</h3><p>Consultá el historial y agregá observaciones en un espacio separado de la asistencia.</p></div></header>
        <div class="panel-body">
            <p class="muted">Las observaciones son privadas y solo las puede consultar el preceptor actualmente asignado al curso. Se muestra el historial guardado de cada alumno.</p>
            <?php foreach ($courses as $courseId => $course): ?>
                <article class="panel" style="margin:14px 0">
                    <header class="panel-header"><div><h4><?= e(course_label($course)) ?></h4><p><?= count($course['students']) ?> alumnos</p></div></header>
                    <div class="panel-body">
                        <?php if (!$course['students']): ?>
                            <p class="muted">Este curso todavía no tiene alumnos activos.</p>
                        <?php else: ?>
                            <?php foreach ($course['students'] as $studentId => $student): ?>
                                <?php $studentObservations = $course['observations'][$studentId] ?? []; ?>
                                <details class="panel" style="margin:10px 0">
                                    <summary class="panel-header" style="cursor:pointer">
                                        <strong><?= e($student['alumno_apellido'] . ', ' . $student['alumno_nombre']) ?></strong>
                                        <span class="muted"><?= count($studentObservations) ?> observaciones · Ver historial</span>
                                    </summary>
                                    <div class="panel-body">
                                        <?php if ($studentObservations): ?>
                                            <div class="list">
                                                <?php foreach ($studentObservations as $observation): ?>
                                                    <article class="list-item">
                                                        <div class="list-copy"><p><?= nl2br(e($observation['observacion'])) ?></p><small class="muted"><?= e(format_date($observation['created_at'], true)) ?></small></div>
                                                    </article>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="muted">Todavía no hay observaciones registradas para este alumno.</p>
                                        <?php endif; ?>
                                        <div class="section-divider"></div>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="add_observation">
                                            <input type="hidden" name="curso_id" value="<?= (int) $courseId ?>">
                                            <input type="hidden" name="alumno_id" value="<?= (int) $studentId ?>">
                                            <div class="form-group">
                                                <label for="observation-<?= (int) $courseId ?>-<?= (int) $studentId ?>">Nueva observación</label>
                                                <textarea class="textarea-control" id="observation-<?= (int) $courseId ?>-<?= (int) $studentId ?>" name="observacion" minlength="5" maxlength="2000" required></textarea>
                                            </div>
                                            <div class="form-actions"><button class="btn btn-primary" type="submit">Guardar observación</button></div>
                                        </form>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel" id="reuniones-curso">
        <header class="panel-header"><div><h3>Reuniones de tus cursos</h3><p>Próximas convocatorias asociadas a los cursos asignados.</p></div></header>
        <div class="panel-body">
    <?php foreach ($courses as $courseId => $course): ?>
        <article class="panel" style="margin:14px 0">
            <header class="panel-header"><div><h4><?= e(course_label($course)) ?></h4></div></header>
            <div class="panel-body">
                <?php if (!$course['meetings']): ?>
                    <p class="muted">No hay reuniones futuras convocadas para este curso.</p>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($course['meetings'] as $meeting): ?>
                            <div class="list-item">
                                <span class="list-icon"><?= icon('calendar', 18) ?></span>
                                <div class="list-copy"><strong><?= e($meeting['titulo']) ?></strong><p><?= e(format_date($meeting['fecha'])) ?> · <?= e(format_time($meeting['hora_inicio'])) ?>–<?= e(format_time($meeting['hora_fin'])) ?> · <?= e($meeting['lugar']) ?></p></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
