<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$pdo = db();
$userId = (int) $user['id'];

if (is_post()) {
    verify_csrf();
    $action = post_string('action');

    try {
        if ($action === 'update_contact') {
            $phone = post_string('telefono');
            if (mb_strlen($phone) > 30) {
                throw new RuntimeException('El teléfono es demasiado largo.');
            }
            $stmt = $pdo->prepare('UPDATE usuarios SET telefono = ? WHERE id = ?');
            $stmt->execute([$phone !== '' ? $phone : null, $userId]);
            audit($pdo, $userId, 'ACTUALIZACION_PERFIL', 'usuarios', $userId, ['campo' => 'telefono']);
            current_user(true);
            flash('success', 'Tu teléfono fue actualizado.');
        } elseif ($action === 'change_password') {
            $current = post_string('password_actual');
            $new = post_string('password_nueva');
            $confirm = post_string('password_confirmar');

            $stmt = $pdo->prepare('SELECT password_hash FROM usuarios WHERE id = ?');
            $stmt->execute([$userId]);
            $hash = (string) $stmt->fetchColumn();
            if (!password_verify($current, $hash)) {
                throw new RuntimeException('La contraseña actual no es correcta.');
            }
            if ($new !== $confirm) {
                throw new RuntimeException('Las contraseñas nuevas no coinciden.');
            }
            if (strlen($new) < 8 || !preg_match('/[A-ZÁÉÍÓÚÑ]/u', $new) || !preg_match('/[a-záéíóúñ]/u', $new) || !preg_match('/\d/', $new)) {
                throw new RuntimeException('La nueva contraseña debe tener al menos 8 caracteres, mayúscula, minúscula y número.');
            }
            $update = $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
            $update->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
            audit($pdo, $userId, 'CAMBIO_PASSWORD', 'usuarios', $userId);
            flash('success', 'La contraseña fue actualizada.');
        }
    } catch (RuntimeException $exception) {
        flash('danger', $exception->getMessage());
    }
    redirect('perfil.php');
}

$courseStmt = $pdo->prepare(
    "SELECT cu.anio, cu.division, cu.turno, e.nombre AS especialidad
     FROM alumno_curso ac JOIN cursos cu ON cu.id = ac.curso_id
     LEFT JOIN especialidades e ON e.id = cu.especialidad_id
     WHERE ac.usuario_id = ? AND ac.anio_lectivo = YEAR(CURDATE()) LIMIT 1"
);
$courseStmt->execute([$userId]);
$course = $courseStmt->fetch() ?: null;

$attendanceHistory = [];
if (has_role('Alumno')) {
    $attendanceStmt = $pdo->prepare(
        "SELECT pa.fecha, pa.estado, cu.anio, cu.division, cu.turno, e.nombre AS especialidad
         FROM preceptoria_asistencias pa
         JOIN alumno_curso ac ON ac.usuario_id = pa.alumno_id
            AND ac.curso_id = pa.curso_id
            AND ac.anio_lectivo = YEAR(CURDATE())
         JOIN cursos cu ON cu.id = pa.curso_id
         LEFT JOIN especialidades e ON e.id = cu.especialidad_id
         WHERE pa.alumno_id = ? AND YEAR(pa.fecha) = YEAR(CURDATE())
         ORDER BY pa.fecha DESC, cu.anio, cu.division"
    );
    $attendanceStmt->execute([$userId]);
    $attendanceHistory = $attendanceStmt->fetchAll();
}

$pageTitle = 'Mi perfil';
$activePage = 'perfil';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div><h2>Datos de la cuenta</h2><p>Información institucional, roles y seguridad de acceso.</p></div></section>
<section class="profile-grid">
    <article class="panel profile-card">
        <div class="profile-avatar"><?= e(mb_strtoupper(mb_substr($user['nombre'], 0, 1) . mb_substr($user['apellido'], 0, 1))) ?></div>
        <h2><?= e($user['nombre'] . ' ' . $user['apellido']) ?></h2>
        <p>DNI <?= e($user['dni']) ?></p>
        <div class="tag-list"><?php foreach ($user['roles'] as $role): ?><span class="tag"><?= e($role) ?></span><?php endforeach; ?></div>
        <?php if ($user['cargos']): ?><div class="section-divider"></div><div class="tag-list"><?php foreach ($user['cargos'] as $cargo): ?><span class="tag"><?= e($cargo) ?></span><?php endforeach; ?></div><?php endif; ?>
    </article>

    <div class="stack">
        <article class="panel"><header class="panel-header"><div><h3>Información institucional</h3><p>Los datos principales solo pueden ser modificados por la institución.</p></div></header><div class="panel-body"><div class="detail-list"><div class="detail-item"><span>Nombre completo</span><strong><?= e($user['apellido'] . ', ' . $user['nombre']) ?></strong></div><div class="detail-item"><span>DNI</span><strong><?= e($user['dni']) ?></strong></div><div class="detail-item"><span>Correo</span><strong><?= e($user['email']) ?></strong></div><div class="detail-item"><span>Curso actual</span><strong><?= $course ? e(course_label($course)) : 'No corresponde' ?></strong></div><div class="detail-item"><span>Registro completado</span><strong><?= e(format_date($user['fecha_registro'], true)) ?></strong></div><div class="detail-item"><span>Cuenta</span><strong><?= (int) $user['activo'] ? 'Activa' : 'Inactiva' ?></strong></div></div></div></article>

        <article class="panel"><header class="panel-header"><div><h3>Datos de contacto</h3><p>Actualizá el teléfono asociado a tu perfil.</p></div></header><form method="post" class="panel-body"><?= csrf_field() ?><input type="hidden" name="action" value="update_contact"><div class="form-group"><label>Teléfono</label><input class="input-control" name="telefono" maxlength="30" value="<?= e($user['telefono'] ?? '') ?>" placeholder="Ej.: 11 1234 5678"></div><div class="form-actions"><button class="btn btn-primary" type="submit">Guardar teléfono</button></div></form></article>

        <article class="panel"><header class="panel-header"><div><h3>Seguridad</h3><p>Cambiá tu contraseña de acceso.</p></div></header><form method="post" class="panel-body"><?= csrf_field() ?><input type="hidden" name="action" value="change_password"><div class="form-grid"><div class="form-group full"><label>Contraseña actual</label><input class="input-control" type="password" name="password_actual" autocomplete="current-password" required></div><div class="form-group"><label>Nueva contraseña</label><input class="input-control" type="password" name="password_nueva" minlength="8" autocomplete="new-password" required></div><div class="form-group"><label>Repetir nueva contraseña</label><input class="input-control" type="password" name="password_confirmar" minlength="8" autocomplete="new-password" required></div></div><div class="form-actions"><button class="btn btn-primary" type="submit">Cambiar contraseña</button></div></form></article>
    </div>
</section>
<?php if (has_role('Alumno')): ?>
<section class="panel" style="margin-top:18px">
    <header class="panel-header">
        <div><h3>Mi asistencia</h3><p>Solo vos podés consultar tus registros de asistencia del ciclo lectivo actual.</p></div>
    </header>
    <div class="panel-body">
        <?php if (!$attendanceHistory): ?>
            <p class="muted">Todavía no hay asistencias registradas para tu curso en este ciclo lectivo.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Fecha</th><th>Curso</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($attendanceHistory as $attendance): ?>
                        <tr>
                            <td><?= e(format_date($attendance['fecha'])) ?></td>
                            <td><?= e(course_label($attendance)) ?></td>
                            <td><span class="status <?= e(status_class($attendance['estado'])) ?>"><?= e($attendance['estado']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
