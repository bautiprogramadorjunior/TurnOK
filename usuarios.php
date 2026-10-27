<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_user_management();
$pdo = db();
$userId = (int) $user['id'];

$rolesCatalog = $pdo->query('SELECT id, nombre, descripcion FROM roles WHERE activo = 1 ORDER BY id')->fetchAll();
$cargosCatalog = $pdo->query('SELECT id, nombre FROM cargos WHERE activo = 1 ORDER BY nombre')->fetchAll();
$coursesCatalog = $pdo->query(
    "SELECT cu.id, cu.anio, cu.division, cu.turno, e.nombre AS especialidad
     FROM cursos cu LEFT JOIN especialidades e ON e.id = cu.especialidad_id
     WHERE cu.activo = 1 ORDER BY cu.anio, e.nombre, cu.division, cu.turno"
)->fetchAll();
$spacesCatalog = $pdo->query("SELECT id, nombre FROM espacios WHERE activo = 1 ORDER BY nombre")->fetchAll();

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'create_user') {
            $dni = preg_replace('/\D+/', '', post_string('dni')) ?? '';
            $name = post_string('nombre');
            $surname = post_string('apellido');
            $email = mb_strtolower(post_string('email'));
            $phone = post_string('telefono');
            $roleIds = post_int_array('rol_ids');
            $cargoIds = post_int_array('cargo_ids');
            $courseId = post_int('curso_id');
            $preceptorCourseIds = post_int_array('preceptor_curso_ids');
            $managerSpaceIds = post_int_array('encargado_espacio_ids');
            $managerShift = post_string('encargado_turno');

            if (strlen($dni) < 7 || strlen($dni) > 15 || $name === '' || $surname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Completá DNI, nombre, apellido y correo electrónico.');
            }

            $roleNames = [];
            if ($roleIds) {
                $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
                $roleStmt = $pdo->prepare("SELECT id, nombre FROM roles WHERE activo = 1 AND id IN ($placeholders)");
                $roleStmt->execute($roleIds);
                $validRoles = $roleStmt->fetchAll();
                if (count($validRoles) !== count($roleIds)) {
                    throw new RuntimeException('Uno de los roles seleccionados no es válido.');
                }
                $roleNames = array_column($validRoles, 'nombre');
            }

            $cargoNames = [];
            if ($cargoIds) {
                $placeholders = implode(',', array_fill(0, count($cargoIds), '?'));
                $cargoCheck = $pdo->prepare("SELECT id, nombre FROM cargos WHERE activo = 1 AND id IN ($placeholders)");
                $cargoCheck->execute($cargoIds);
                $validCargos = $cargoCheck->fetchAll();
                if (count($validCargos) !== count($cargoIds)) {
                    throw new RuntimeException('Uno de los cargos seleccionados no es válido.');
                }
                $cargoNames = array_column($validCargos, 'nombre');
            }

            $isPreceptor = in_array('Preceptor', $cargoNames, true);
            if (!$roleIds && !$cargoIds) {
                throw new RuntimeException('Seleccioná al menos un rol o un cargo institucional.');
            }
            if ($isPreceptor && $roleIds) {
                throw new RuntimeException('El cargo Preceptor funciona sin roles generales. Dejá los roles sin seleccionar.');
            }
            if ($isPreceptor && count($cargoNames) !== 1) {
                throw new RuntimeException('El cargo Preceptor debe asignarse por separado, sin combinarlo con otros cargos.');
            }

            if ($isPreceptor && !$preceptorCourseIds) {
                throw new RuntimeException('Un preceptor debe estar asociado al menos a un curso.');
            }

            if (in_array('Alumno', $roleNames, true) && !$courseId) {
                throw new RuntimeException('Todo usuario con rol Alumno debe tener un curso actual.');
            }

            $pdo->beginTransaction();
            $insert = $pdo->prepare(
                'INSERT INTO usuarios (dni, nombre, apellido, email, telefono)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([$dni, $name, $surname, $email, $phone !== '' ? $phone : null]);
            $newUserId = (int) $pdo->lastInsertId();

            $roleInsert = $pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?, ?)');
            foreach ($roleIds as $roleId) {
                $roleInsert->execute([$newUserId, $roleId]);
            }

            if ($cargoIds) {
                if (in_array('Encargado de Espacio', $cargoNames, true) && (!$managerSpaceIds || !in_array($managerShift, ['TM', 'TT'], true))) {
                    throw new RuntimeException('Un encargado debe tener al menos un espacio y un turno asignado.');
                }
                $cargoInsert = $pdo->prepare('INSERT INTO usuario_cargos (usuario_id, cargo_id, activo, desde) VALUES (?, ?, 1, CURDATE())');
                foreach ($cargoIds as $cargoId) {
                    $cargoInsert->execute([$newUserId, $cargoId]);
                }
            }

            if (in_array('Alumno', $roleNames, true)) {
                $courseCheck = $pdo->prepare('SELECT id FROM cursos WHERE id = ? AND activo = 1');
                $courseCheck->execute([$courseId]);
                if (!$courseCheck->fetchColumn()) {
                    throw new RuntimeException('El curso seleccionado no es válido.');
                }
                $courseInsert = $pdo->prepare('INSERT INTO alumno_curso (usuario_id, curso_id, anio_lectivo) VALUES (?, ?, YEAR(CURDATE()))');
                $courseInsert->execute([$newUserId, $courseId]);
            }

            if (in_array('Preceptor', $cargoNames, true)) {
                $placeholders = implode(',', array_fill(0, count($preceptorCourseIds), '?'));
                $courseCheck = $pdo->prepare("SELECT COUNT(*) FROM cursos WHERE activo = 1 AND id IN ($placeholders)");
                $courseCheck->execute($preceptorCourseIds);
                if ((int) $courseCheck->fetchColumn() !== count($preceptorCourseIds)) {
                    throw new RuntimeException('Uno de los cursos de preceptoría no es válido.');
                }
                $occupiedCheck = $pdo->prepare("SELECT COUNT(*) FROM preceptor_curso WHERE curso_id IN ($placeholders)");
                $occupiedCheck->execute($preceptorCourseIds);
                if ((int) $occupiedCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Uno de los cursos ya tiene un preceptor asignado. Cada curso puede tener un único preceptor.');
                }
                $preceptorInsert = $pdo->prepare('INSERT INTO preceptor_curso (usuario_id, curso_id) VALUES (?, ?)');
                foreach ($preceptorCourseIds as $assignedCourseId) {
                    $preceptorInsert->execute([$newUserId, $assignedCourseId]);
                }
            }

            if (in_array('Encargado de Espacio', $cargoNames, true)) {
                $placeholders = implode(',', array_fill(0, count($managerSpaceIds), '?'));
                $spaceCheck = $pdo->prepare("SELECT COUNT(*) FROM espacios WHERE activo = 1 AND id IN ($placeholders)");
                $spaceCheck->execute($managerSpaceIds);
                if ((int) $spaceCheck->fetchColumn() !== count($managerSpaceIds)) {
                    throw new RuntimeException('Uno de los espacios asignados no es válido.');
                }
                $spaceInsert = $pdo->prepare('INSERT INTO encargados_espacios (espacio_id, usuario_id, turno, activo) VALUES (?, ?, ?, 1)');
                foreach ($managerSpaceIds as $assignedSpaceId) {
                    $spaceInsert->execute([$assignedSpaceId, $newUserId, $managerShift]);
                }
            }

            audit($pdo, $userId, 'PRECARGA_USUARIO', 'usuarios', $newUserId, ['roles' => $roleNames, 'cargos' => $cargoNames]);
            $pdo->commit();
            flash('success', 'La persona fue precargada. Ya puede ingresar con su DNI y crear una contraseña.');
        } elseif ($action === 'assign_preceptor_courses') {
            $targetId = post_int('usuario_id');
            $courseIds = post_int_array('preceptor_curso_ids');
            if (!$targetId || $targetId === $userId) {
                throw new RuntimeException('Seleccioná una persona válida.');
            }
            if (post_string('requiere_cursos') === '1' && !$courseIds) {
                throw new RuntimeException('Seleccioná al menos un curso para asignar la preceptoría.');
            }

            if ($courseIds) {
                $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
                $courseCheck = $pdo->prepare("SELECT COUNT(*) FROM cursos WHERE activo = 1 AND id IN ($placeholders)");
                $courseCheck->execute($courseIds);
                if ((int) $courseCheck->fetchColumn() !== count($courseIds)) {
                    throw new RuntimeException('Uno de los cursos seleccionados no es válido.');
                }

                $userRoleCheck = $pdo->prepare('SELECT COUNT(*) FROM usuario_roles WHERE usuario_id = ?');
                $userRoleCheck->execute([$targetId]);
                if ((int) $userRoleCheck->fetchColumn() > 0) {
                    throw new RuntimeException('El preceptor no puede tener roles generales. Elegí una cuenta sin rol o creá una nueva desde Usuarios.');
                }

                $spaceManagerCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM usuario_cargos uc
                     JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Encargado de Espacio' AND c.activo = 1
                     WHERE uc.usuario_id = ? AND uc.activo = 1
                       AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                       AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())"
                );
                $spaceManagerCheck->execute([$targetId]);
                if ((int) $spaceManagerCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Esta cuenta está limitada a la gestión de espacios y no puede tener funciones de preceptoría.');
                }
                $otherCargoCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM usuario_cargos uc
                     JOIN cargos c ON c.id = uc.cargo_id AND c.activo = 1 AND c.nombre <> 'Preceptor'
                     WHERE uc.usuario_id = ? AND uc.activo = 1
                       AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                       AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())"
                );
                $otherCargoCheck->execute([$targetId]);
                if ((int) $otherCargoCheck->fetchColumn() > 0) {
                    throw new RuntimeException('El cargo Preceptor debe asignarse por separado, sin combinarlo con otros cargos.');
                }

                $occupiedCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM preceptor_curso
                     WHERE curso_id IN ($placeholders) AND usuario_id <> ?"
                );
                $occupiedCheck->execute([...$courseIds, $targetId]);
                if ((int) $occupiedCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Uno de los cursos ya tiene un preceptor asignado. Cada curso puede tener un único preceptor.');
                }
            }

            $targetCheck = $pdo->prepare('SELECT id, activo FROM usuarios WHERE id = ?');
            $targetCheck->execute([$targetId]);
            $preceptorTarget = $targetCheck->fetch();
            if (!$preceptorTarget || !(int) $preceptorTarget['activo']) {
                throw new RuntimeException('La cuenta seleccionada ya no existe.');
            }

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM preceptor_curso WHERE usuario_id = ?')->execute([$targetId]);
            $preceptorCargo = $pdo->query("SELECT id FROM cargos WHERE nombre = 'Preceptor' AND activo = 1 LIMIT 1")->fetchColumn();

            if ($courseIds && !$preceptorCargo) {
                throw new RuntimeException('El cargo Preceptor no está disponible en la base de datos.');
            }

            if ($courseIds) {
                $assignCargo = $pdo->prepare(
                    'INSERT INTO usuario_cargos (usuario_id, cargo_id, activo, desde, hasta)
                     VALUES (?, ?, 1, CURDATE(), NULL)
                     ON DUPLICATE KEY UPDATE activo = 1, desde = CURDATE(), hasta = NULL'
                );
                $assignCargo->execute([$targetId, (int) $preceptorCargo]);
                $assignCourse = $pdo->prepare('INSERT INTO preceptor_curso (usuario_id, curso_id) VALUES (?, ?)');
                foreach ($courseIds as $courseId) {
                    $assignCourse->execute([$targetId, $courseId]);
                }
            } else {
                $removeCargo = $pdo->prepare(
                    "UPDATE usuario_cargos uc
                     JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Preceptor'
                     SET uc.activo = 0, uc.hasta = CURDATE()
                     WHERE uc.usuario_id = ? AND uc.activo = 1"
                );
                $removeCargo->execute([$targetId]);
            }

            audit($pdo, $userId, $courseIds ? 'ASIGNAR_PRECEPTORIA' : 'QUITAR_PRECEPTORIA', 'usuarios', $targetId, ['cursos' => $courseIds]);
            $pdo->commit();
            flash('success', $courseIds ? 'Se actualizaron los cursos de preceptoría.' : 'Se quitó la asignación de preceptoría.');
        } elseif ($action === 'delete_user') {
            $targetId = post_int('usuario_id');
            if (!$targetId || $targetId === $userId) {
                throw new RuntimeException('No podés eliminar tu propia cuenta.');
            }

            $pdo->beginTransaction();
            $targetStmt = $pdo->prepare('SELECT id, nombre, apellido, activo FROM usuarios WHERE id = ? FOR UPDATE');
            $targetStmt->execute([$targetId]);
            $target = $targetStmt->fetch();
            if (!$target) {
                throw new RuntimeException('La cuenta que intentás eliminar ya no existe.');
            }

            $targetIsActiveRector = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM usuarios u
                 JOIN usuario_roles ur ON ur.usuario_id = u.id
                 JOIN roles r ON r.id = ur.rol_id AND r.nombre = 'Directivo' AND r.activo = 1
                 JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
                    AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                    AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
                 JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Rector' AND c.activo = 1
                 WHERE u.id = ? AND u.activo = 1"
            );
            $targetIsActiveRector->execute([$targetId]);
            if ((int) $targetIsActiveRector->fetchColumn() > 0) {
                $activeRectors = (int) $pdo->query(
                    "SELECT COUNT(DISTINCT u.id)
                     FROM usuarios u
                     JOIN usuario_roles ur ON ur.usuario_id = u.id
                     JOIN roles r ON r.id = ur.rol_id AND r.nombre = 'Directivo' AND r.activo = 1
                     JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
                        AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                        AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
                     JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Rector' AND c.activo = 1
                     WHERE u.activo = 1"
                )->fetchColumn();
                if ($activeRectors <= 1) {
                    throw new RuntimeException('No se puede eliminar al único Rector activo. Asigná primero otro Directivo con cargo de Rector.');
                }
            }

            $meetingHistory = $pdo->prepare('SELECT COUNT(*) FROM reunion_participantes WHERE usuario_id = ?');
            $meetingHistory->execute([$targetId]);
            if ((int) $meetingHistory->fetchColumn() > 0) {
                throw new RuntimeException('La cuenta tiene convocatorias o asistencias registradas. Desactivala para conservar ese historial.');
            }

            audit($pdo, $userId, 'ELIMINAR_USUARIO', 'usuarios', $targetId, [
                'nombre' => $target['nombre'],
                'apellido' => $target['apellido'],
                'activo' => (int) $target['activo'],
            ]);
            $delete = $pdo->prepare('DELETE FROM usuarios WHERE id = ?');
            $delete->execute([$targetId]);
            $pdo->commit();
            flash('success', 'La cuenta fue eliminada.');
        } elseif ($action === 'toggle_user') {
            $targetId = post_int('usuario_id');
            $active = post_string('activo');
            if (!$targetId || !in_array($active, ['0', '1'], true) || $targetId === $userId) {
                throw new RuntimeException('No se puede modificar el estado de esa cuenta.');
            }
            if ($active === '0') {
                $rectorCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM usuarios u
                     JOIN usuario_roles ur ON ur.usuario_id = u.id
                     JOIN roles r ON r.id = ur.rol_id AND r.nombre = 'Directivo' AND r.activo = 1
                     JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
                        AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                        AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
                     JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Rector' AND c.activo = 1
                     WHERE u.id = ? AND u.activo = 1"
                );
                $rectorCheck->execute([$targetId]);
                if ((int) $rectorCheck->fetchColumn() > 0) {
                    $activeRectors = (int) $pdo->query(
                        "SELECT COUNT(DISTINCT u.id)
                         FROM usuarios u
                         JOIN usuario_roles ur ON ur.usuario_id = u.id
                         JOIN roles r ON r.id = ur.rol_id AND r.nombre = 'Directivo' AND r.activo = 1
                         JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
                            AND (uc.desde IS NULL OR uc.desde <= CURDATE())
                            AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
                         JOIN cargos c ON c.id = uc.cargo_id AND c.nombre = 'Rector' AND c.activo = 1
                         WHERE u.activo = 1"
                    )->fetchColumn();
                    if ($activeRectors <= 1) {
                        throw new RuntimeException('No se puede desactivar al único Rector activo. Asigná primero otro Directivo con cargo de Rector.');
                    }
                }
            }
            $stmt = $pdo->prepare('UPDATE usuarios SET activo = ? WHERE id = ?');
            $stmt->execute([(int) $active, $targetId]);
            audit($pdo, $userId, 'CAMBIO_ESTADO_USUARIO', 'usuarios', $targetId, ['activo' => (int) $active]);
            flash('success', (int) $active ? 'La cuenta fue activada.' : 'La cuenta fue desactivada.');
        } elseif ($action === 'reset_access') {
            $targetId = post_int('usuario_id');
            if (!$targetId || $targetId === $userId) {
                throw new RuntimeException('No podés reiniciar tu propio acceso desde esta pantalla.');
            }
            $stmt = $pdo->prepare('UPDATE usuarios SET password_hash = NULL, registro_completo = 0, fecha_registro = NULL WHERE id = ?');
            $stmt->execute([$targetId]);
            audit($pdo, $userId, 'REINICIO_ACCESO', 'usuarios', $targetId);
            flash('success', 'El acceso fue reiniciado. La persona deberá crear una nueva contraseña con su DNI.');
        }
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $action === 'delete_user' && $exception->getCode() === '23000'
            ? 'No se puede eliminar porque la cuenta tiene trámites, reservas, reuniones u otra información institucional vinculada. Desactivala para conservar ese historial.'
            : ($exception->getCode() === '23000'
                ? 'Ya existe una persona con ese DNI o correo electrónico.'
                : 'No se pudo guardar la información en la base de datos.');
        flash('danger', $message);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo completar la operación.');
    }

    redirect('usuarios.php');
}

$filter = trim((string) ($_GET['q'] ?? ''));
$params = [];
$where = '';
if ($filter !== '') {
    $where = 'WHERE u.dni LIKE ? OR u.nombre LIKE ? OR u.apellido LIKE ? OR u.email LIKE ?';
    $like = '%' . $filter . '%';
    $params = [$like, $like, $like, $like];
}

$stmt = $pdo->prepare(
    "SELECT u.*,
            GROUP_CONCAT(DISTINCT r.nombre ORDER BY r.nombre SEPARATOR ' · ') AS roles,
            GROUP_CONCAT(DISTINCT c.nombre ORDER BY c.nombre SEPARATOR ' · ') AS cargos,
            cu.anio, cu.division, cu.turno, esp.nombre AS especialidad
     FROM usuarios u
     LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
     LEFT JOIN roles r ON r.id = ur.rol_id
     LEFT JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
     LEFT JOIN cargos c ON c.id = uc.cargo_id
     LEFT JOIN alumno_curso ac ON ac.usuario_id = u.id AND ac.anio_lectivo = YEAR(CURDATE())
     LEFT JOIN cursos cu ON cu.id = ac.curso_id
     LEFT JOIN especialidades esp ON esp.id = cu.especialidad_id
     $where
     GROUP BY u.id, cu.id, esp.id
     ORDER BY u.activo DESC, u.apellido, u.nombre"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$preceptorAssignments = [];
$preceptorCourseOwners = [];
$assignmentStmt = $pdo->query('SELECT usuario_id, curso_id FROM preceptor_curso');
foreach ($assignmentStmt->fetchAll() as $assignment) {
    $assignedUserId = (int) $assignment['usuario_id'];
    $assignedCourseId = (int) $assignment['curso_id'];
    $preceptorAssignments[$assignedUserId][] = $assignedCourseId;
    $preceptorCourseOwners[$assignedCourseId] = $assignedUserId;
}

$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$pendingAccess = (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE password_hash IS NULL AND activo = 1')->fetchColumn();
$activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM usuarios WHERE activo = 1')->fetchColumn();
$students = (int) $pdo->query("SELECT COUNT(DISTINCT ur.usuario_id) FROM usuario_roles ur JOIN roles r ON r.id = ur.rol_id WHERE r.nombre = 'Alumno'")->fetchColumn();

$pageTitle = 'Usuarios';
$activePage = 'usuarios';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div><h2>Gestión de usuarios</h2><p>La institución precarga los datos; las personas completan su acceso con DNI.</p></div>
    <div class="page-actions">
        <button class="btn" type="button" data-dialog-open="assignPreceptor"><?= icon('users', 17) ?> Asignar preceptoría</button>
        <button class="btn btn-primary" type="button" data-dialog-open="newUser"><?= icon('plus', 17) ?> Precargar usuario</button>
    </div>
</section>

<section class="stats-grid">
    <article class="stat-card"><div class="stat-top"><span>TOTAL</span><span class="stat-icon"><?= icon('users', 19) ?></span></div><strong><?= $totalUsers ?></strong><p>Personas registradas</p></article>
    <article class="stat-card"><div class="stat-top"><span>ACTIVAS</span><span class="stat-icon"><?= icon('check', 19) ?></span></div><strong><?= $activeUsers ?></strong><p>Cuentas habilitadas</p></article>
    <article class="stat-card"><div class="stat-top"><span>PRIMER ACCESO</span><span class="stat-icon"><?= icon('clock', 19) ?></span></div><strong><?= $pendingAccess ?></strong><p>Sin contraseña todavía</p></article>
    <article class="stat-card"><div class="stat-top"><span>ALUMNOS</span><span class="stat-icon"><?= icon('user', 19) ?></span></div><strong><?= $students ?></strong><p>Con rol Alumno</p></article>
</section>

<form class="filter-bar" method="get"><div class="search-box"><?= icon('search', 18) ?><input type="search" name="q" value="<?= e($filter) ?>" placeholder="Buscar por DNI, nombre o correo"></div><button class="btn" type="submit">Buscar</button><?php if ($filter !== ''): ?><a class="btn btn-ghost" href="<?= e(url('usuarios.php')) ?>">Limpiar</a><?php endif; ?></form>

<?php if (!$users): ?>
<section class="empty-state"><div class="empty-icon"><?= icon('users', 30) ?></div><h2>No se encontraron usuarios</h2><p>Probá con otro criterio o precargá una nueva persona.</p></section>
<?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Persona</th><th>Contacto</th><th>Roles y cargos</th><th>Curso</th><th>Acceso</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($users as $listedUser): ?>
<?php
$listedUserId = (int) $listedUser['id'];
$listedUserCargos = explode(' · ', (string) ($listedUser['cargos'] ?? ''));
$isPreceptor = in_array('Preceptor', $listedUserCargos, true) || !empty($preceptorAssignments[$listedUserId]);
?>
<tr>
<td><span class="cell-title"><?= e($listedUser['apellido'] . ', ' . $listedUser['nombre']) ?></span><span class="cell-subtitle">DNI <?= e($listedUser['dni']) ?></span></td>
<td><span class="cell-title"><?= e($listedUser['email']) ?></span><span class="cell-subtitle"><?= e($listedUser['telefono'] ?: 'Sin teléfono') ?></span></td>
<td><span class="cell-title"><?= e($listedUser['roles'] ?: 'Sin rol') ?></span><span class="cell-subtitle"><?= e($listedUser['cargos'] ?: 'Sin cargo') ?></span></td>
<td><?php if ($listedUser['anio']): ?><?= e(course_label($listedUser)) ?><?php else: ?><span class="muted">No corresponde</span><?php endif; ?></td>
<td><span class="status <?= $listedUser['password_hash'] ? 'status-success' : 'status-warning' ?>"><?= $listedUser['password_hash'] ? 'Completado' : 'Pendiente' ?></span></td>
<td><span class="status <?= (int) $listedUser['activo'] ? 'status-success' : 'status-danger' ?>"><?= (int) $listedUser['activo'] ? 'Activa' : 'Inactiva' ?></span></td>
<td class="actions-cell">
<?php if ((int) $listedUser['id'] !== $userId): ?>
<?php if ($isPreceptor): ?><button class="btn btn-sm" type="button" data-dialog-open="preceptorUser<?= (int) $listedUser['id'] ?>">Cursos</button><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="usuario_id" value="<?= (int) $listedUser['id'] ?>"><input type="hidden" name="activo" value="<?= (int) $listedUser['activo'] ? '0' : '1' ?>"><button class="btn btn-sm <?= (int) $listedUser['activo'] ? 'btn-danger' : 'btn-success' ?>" type="submit" data-confirm="¿Confirmar el cambio de estado de esta cuenta?"><?= (int) $listedUser['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
<?php if ($listedUser['password_hash']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset_access"><input type="hidden" name="usuario_id" value="<?= (int) $listedUser['id'] ?>"><button class="btn btn-sm" type="submit" data-confirm="La persona deberá crear una nueva contraseña. ¿Continuar?">Reiniciar acceso</button></form><?php endif; ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="usuario_id" value="<?= (int) $listedUser['id'] ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="Esta acción elimina la cuenta permanentemente. Si tiene historial institucional, la base impedirá borrarla. ¿Eliminar de todos modos?">Eliminar</button></form>
<?php else: ?><span class="muted">Tu cuenta</span><?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<?php foreach ($users as $listedUser): ?>
<?php
$listedUserId = (int) $listedUser['id'];
$listedUserCargos = explode(' · ', (string) ($listedUser['cargos'] ?? ''));
$isPreceptor = in_array('Preceptor', $listedUserCargos, true) || !empty($preceptorAssignments[$listedUserId]);
?>
<?php if ((int) $listedUser['id'] !== $userId && $isPreceptor): ?>
<dialog class="modal" id="preceptorUser<?= (int) $listedUser['id'] ?>">
    <header class="modal-header">
        <div><h3>Asignación de preceptoría</h3><p><?= e($listedUser['nombre'] . ' ' . $listedUser['apellido']) ?></p></div>
        <button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button>
    </header>
    <form method="post" class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_preceptor_courses">
        <input type="hidden" name="usuario_id" value="<?= (int) $listedUser['id'] ?>">
        <div class="form-group">
            <label>Cursos a cargo como preceptor</label>
            <div class="checkbox-grid">
                <?php foreach ($coursesCatalog as $course): ?>
                    <?php $courseId = (int) $course['id']; ?>
                    <?php $assignedToOther = isset($preceptorCourseOwners[$courseId]) && $preceptorCourseOwners[$courseId] !== (int) $listedUser['id']; ?>
                    <label class="check-option"><input type="checkbox" name="preceptor_curso_ids[]" value="<?= $courseId ?>" <?= in_array($courseId, $preceptorAssignments[(int) $listedUser['id']] ?? [], true) ? 'checked' : '' ?> <?= $assignedToOther ? 'disabled' : '' ?>> <?= e(course_label($course)) ?><?= $assignedToOther ? ' · ya asignado' : '' ?></label>
                <?php endforeach; ?>
            </div>
            <small class="muted">Al guardar cursos se habilita el cargo Preceptor. Si no seleccionás ninguno, se quita la asignación.</small>
        </div>
        <div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar asignación</button></div>
    </form>
</dialog>
<?php endif; ?>
<?php endforeach; ?>

<dialog class="modal" id="assignPreceptor">
    <header class="modal-header">
        <div><h3>Asignar preceptoría</h3><p>Elegí a la persona y los cursos que tendrá a cargo.</p></div>
        <button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button>
    </header>
    <form method="post" class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_preceptor_courses">
        <input type="hidden" name="requiere_cursos" value="1">
        <div class="form-group">
            <label for="preceptorUserId">Persona</label>
            <select class="select-control" id="preceptorUserId" name="usuario_id" required>
                <option value="">Seleccionar persona…</option>
                <?php foreach ($users as $listedUser): ?>
                    <?php
                    $listedUserId = (int) $listedUser['id'];
                    $listedUserCargos = explode(' · ', (string) ($listedUser['cargos'] ?? ''));
                    if (
                        $listedUserId === $userId
                        || !(int) $listedUser['activo']
                        || in_array('Preceptor', $listedUserCargos, true)
                        || !empty($preceptorAssignments[$listedUserId])
                        || trim((string) ($listedUser['cargos'] ?? '')) !== ''
                        || !empty($listedUser['roles'])
                    ) {
                        continue;
                    }
                    ?>
                    <option value="<?= $listedUserId ?>"><?= e($listedUser['apellido'] . ', ' . $listedUser['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Cursos a cargo</label>
            <div class="checkbox-grid">
                <?php foreach ($coursesCatalog as $course): ?>
                    <?php $courseId = (int) $course['id']; ?>
                    <?php $courseAlreadyAssigned = isset($preceptorCourseOwners[$courseId]); ?>
                    <label class="check-option"><input type="checkbox" name="preceptor_curso_ids[]" value="<?= $courseId ?>" <?= $courseAlreadyAssigned ? 'disabled' : '' ?>> <?= e(course_label($course)) ?><?= $courseAlreadyAssigned ? ' · ya asignado' : '' ?></label>
                <?php endforeach; ?>
            </div>
            <small class="muted">Cada curso puede tener un único preceptor asignado. Si un curso ya tiene responsable, primero quitá esa asignación desde su botón Cursos.</small>
        </div>
        <div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar asignación</button></div>
    </form>
</dialog>

<dialog class="modal" id="newUser" <?= ($_GET['action'] ?? '') === 'new' ? 'data-auto-open' : '' ?>><header class="modal-header"><div><h3>Precargar una persona</h3><p>No se crea una contraseña. La persona la definirá al ingresar por primera vez con su DNI.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header><form method="post" class="modal-body"><?= csrf_field() ?><input type="hidden" name="action" value="create_user"><div class="form-grid">
<div class="form-group"><label>DNI</label><input class="input-control" name="dni" inputmode="numeric" pattern="[0-9]{7,15}" maxlength="15" required placeholder="Sin puntos"></div><div class="form-group"><label>Correo institucional</label><input class="input-control" type="email" name="email" maxlength="150" required></div><div class="form-group"><label>Nombre</label><input class="input-control" name="nombre" maxlength="80" required></div><div class="form-group"><label>Apellido</label><input class="input-control" name="apellido" maxlength="80" required></div><div class="form-group full"><label>Teléfono</label><input class="input-control" name="telefono" maxlength="30"></div>
<div class="form-group full"><label>Roles generales (opcional si la persona tendrá un cargo institucional)</label><small class="muted">Elegí un rol solo si también necesita sus funciones generales. Preceptor debe tener únicamente ese cargo y cursos asignados.</small><div class="checkbox-grid"><?php foreach ($rolesCatalog as $role): ?><label class="check-option"><input type="checkbox" name="rol_ids[]" value="<?= (int) $role['id'] ?>" data-role-name="<?= e($role['nombre']) ?>"> <?= e($role['nombre']) ?></label><?php endforeach; ?></div></div>
<div class="form-group full"><label>Cargos institucionales (opcional)</label><small class="muted">Se necesita al menos un rol general o un cargo. El cargo por sí solo no agrega permisos, salvo las funciones específicas implementadas.</small><div class="checkbox-grid" style="max-height:240px;overflow:auto"><?php foreach ($cargosCatalog as $cargo): ?><label class="check-option"><input type="checkbox" name="cargo_ids[]" value="<?= (int) $cargo['id'] ?>"> <?= e($cargo['nombre']) ?></label><?php endforeach; ?></div></div>
<div class="form-group full"><label>Curso actual (obligatorio para alumnos)</label><select class="select-control" name="curso_id"><option value="">No corresponde / seleccionar…</option><?php foreach ($coursesCatalog as $course): ?><option value="<?= (int) $course['id'] ?>"><?= e(course_label($course)) ?></option><?php endforeach; ?></select></div>
<div class="form-group full"><label>Cursos a cargo como preceptor (solo si corresponde)</label><div class="checkbox-grid" style="max-height:190px;overflow:auto"><?php foreach ($coursesCatalog as $course): ?><?php $courseId = (int) $course['id']; ?><?php $courseAlreadyAssigned = isset($preceptorCourseOwners[$courseId]); ?><label class="check-option"><input type="checkbox" name="preceptor_curso_ids[]" value="<?= $courseId ?>" <?= $courseAlreadyAssigned ? 'disabled' : '' ?>> <?= e(course_label($course)) ?><?= $courseAlreadyAssigned ? ' · ya asignado' : '' ?></label><?php endforeach; ?></div><small class="muted">Cada curso puede tener un único preceptor. Los roles generales deben quedar sin seleccionar.</small></div>
<div class="form-group full"><label>Espacios a cargo (solo si corresponde)</label><div class="checkbox-grid"><?php foreach ($spacesCatalog as $space): ?><label class="check-option"><input type="checkbox" name="encargado_espacio_ids[]" value="<?= (int) $space['id'] ?>"> <?= e($space['nombre']) ?></label><?php endforeach; ?></div></div>
<div class="form-group"><label>Turno como encargado</label><select class="select-control" name="encargado_turno"><option value="">No corresponde</option><option value="TM">Turno Mañana (TM)</option><option value="TT">Turno Tarde (TT)</option></select></div>
</div><div class="form-actions"><button class="btn" type="button" data-dialog-close>Cancelar</button><button class="btn btn-primary" type="submit">Guardar precarga</button></div></form></dialog>
<script>document.querySelectorAll('[data-auto-open]').forEach((dialog)=>dialog.showModal());</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
