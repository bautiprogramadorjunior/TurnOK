<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$pdo = db();
$userId = (int) $user['id'];
$isManager = can_manage_institution();
$isSpaceManagerOnly = is_space_manager_only();
$isPreceptor = has_cargo('Preceptor');
$isProfessor = !$isSpaceManagerOnly && !$isPreceptor && has_role('Profesor');
$isSpaceManager = has_cargo('Encargado de Espacio') || $isManager;

function scalar_query(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

$tramitesCount = 0;
if (!$isSpaceManagerOnly && !$isPreceptor) {
    $tramitesCount = $isManager
        ? scalar_query($pdo, "SELECT COUNT(*) FROM solicitudes_tramites WHERE estado = 'Pendiente'")
        : scalar_query($pdo, "SELECT COUNT(*) FROM solicitudes_tramites WHERE solicitante_id = ? AND estado = 'Pendiente'", [$userId]);
}

if ($isPreceptor) {
    $spacesCount = 0;
} elseif ($isSpaceManager) {
    if ($isManager) {
        $spacesCount = scalar_query($pdo, "SELECT COUNT(*) FROM solicitudes_espacios WHERE estado = 'Pendiente'");
    } else {
        $spacesCount = scalar_query(
            $pdo,
            "SELECT COUNT(DISTINCT se.id)
             FROM solicitudes_espacios se
             JOIN encargados_espacios ee ON ee.espacio_id = se.espacio_id AND ee.usuario_id = ? AND ee.activo = 1
             WHERE se.estado = 'Pendiente'
               AND ee.turno = CASE WHEN se.hora_inicio < '13:30:00' THEN 'TM' ELSE 'TT' END",
            [$userId]
        );
    }
} elseif ($isProfessor) {
    $spacesCount = scalar_query($pdo, "SELECT COUNT(*) FROM solicitudes_espacios WHERE solicitante_id = ? AND estado = 'Pendiente'", [$userId]);
} else {
    $spacesCount = 0;
}

$meetingsCount = 0;
if (!$isSpaceManagerOnly) {
    if ($isPreceptor) {
        $meetingCountStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT r.id)
             FROM preceptor_curso pc
             JOIN reunion_cursos rc ON rc.curso_id = pc.curso_id
             JOIN reuniones r ON r.id = rc.reunion_id
             WHERE pc.usuario_id = ? AND r.fecha >= CURDATE()'
        );
        $meetingCountStmt->execute([$userId]);
        $meetingsCount = (int) $meetingCountStmt->fetchColumn();
    } elseif ($isManager) {
        $meetingsCount = scalar_query($pdo, 'SELECT COUNT(*) FROM reuniones WHERE fecha >= CURDATE()');
    } else {
        $meetingsCount = scalar_query(
            $pdo,
            "SELECT COUNT(DISTINCT r.id)
             FROM reuniones r
             LEFT JOIN reunion_participantes rp ON rp.reunion_id = r.id AND rp.usuario_id = :u1
             LEFT JOIN reunion_cursos rc ON rc.reunion_id = r.id
             LEFT JOIN alumno_curso ac ON ac.curso_id = rc.curso_id AND ac.usuario_id = :u2 AND ac.anio_lectivo = YEAR(CURDATE())
             LEFT JOIN reunion_grupos rg ON rg.reunion_id = r.id
             LEFT JOIN usuario_roles ur ON ur.rol_id = rg.rol_id AND ur.usuario_id = :u3
             WHERE r.fecha >= CURDATE()
               AND (r.creador_id = :u4 OR rp.usuario_id IS NOT NULL OR ac.usuario_id IS NOT NULL OR ur.usuario_id IS NOT NULL)",
            ['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]
        );
    }
}

$unreadCount = scalar_query($pdo, 'SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0', [$userId]);

$upcomingMeetings = [];
if (!$isSpaceManagerOnly) {
    if ($isPreceptor) {
        $meetingStmt = $pdo->prepare(
            "SELECT DISTINCT r.id, r.titulo, r.fecha, r.hora_inicio, r.lugar, tr.nombre AS tipo
             FROM preceptor_curso pc
             JOIN reunion_cursos rc ON rc.curso_id = pc.curso_id
             JOIN reuniones r ON r.id = rc.reunion_id
             JOIN tipos_reunion tr ON tr.id = r.tipo_reunion_id
             WHERE pc.usuario_id = ? AND r.fecha >= CURDATE()
             ORDER BY r.fecha, r.hora_inicio
             LIMIT 5"
        );
        $meetingStmt->execute([$userId]);
    } elseif ($isManager) {
        $meetingStmt = $pdo->query(
            "SELECT r.id, r.titulo, r.fecha, r.hora_inicio, r.lugar, tr.nombre AS tipo
             FROM reuniones r
             JOIN tipos_reunion tr ON tr.id = r.tipo_reunion_id
             WHERE r.fecha >= CURDATE()
             ORDER BY r.fecha, r.hora_inicio
             LIMIT 5"
        );
    } else {
        $meetingStmt = $pdo->prepare(
            "SELECT DISTINCT r.id, r.titulo, r.fecha, r.hora_inicio, r.lugar, tr.nombre AS tipo
             FROM reuniones r
             JOIN tipos_reunion tr ON tr.id = r.tipo_reunion_id
             LEFT JOIN reunion_participantes rp ON rp.reunion_id = r.id AND rp.usuario_id = :u1
             LEFT JOIN reunion_cursos rc ON rc.reunion_id = r.id
             LEFT JOIN alumno_curso ac ON ac.curso_id = rc.curso_id AND ac.usuario_id = :u2 AND ac.anio_lectivo = YEAR(CURDATE())
             LEFT JOIN reunion_grupos rg ON rg.reunion_id = r.id
             LEFT JOIN usuario_roles ur ON ur.rol_id = rg.rol_id AND ur.usuario_id = :u3
             WHERE r.fecha >= CURDATE()
               AND (r.creador_id = :u4 OR rp.usuario_id IS NOT NULL OR ac.usuario_id IS NOT NULL OR ur.usuario_id IS NOT NULL)
             ORDER BY r.fecha, r.hora_inicio
             LIMIT 5"
        );
        $meetingStmt->execute(['u1' => $userId, 'u2' => $userId, 'u3' => $userId, 'u4' => $userId]);
    }
    $upcomingMeetings = $meetingStmt->fetchAll();
}

$notifStmt = $pdo->prepare('SELECT * FROM notificaciones WHERE usuario_id = ? ORDER BY fecha_envio DESC LIMIT 5');
$notifStmt->execute([$userId]);
$recentNotifications = $notifStmt->fetchAll();

if ($isManager) {
    $activityStmt = $pdo->query(
        "SELECT a.accion, a.created_at, u.nombre, u.apellido
         FROM auditoria a
         LEFT JOIN usuarios u ON u.id = a.usuario_id
         ORDER BY a.created_at DESC LIMIT 6"
    );
} else {
    $activityStmt = $pdo->prepare(
        "SELECT a.accion, a.created_at, u.nombre, u.apellido
         FROM auditoria a
         LEFT JOIN usuarios u ON u.id = a.usuario_id
         WHERE a.usuario_id = ? ORDER BY a.created_at DESC LIMIT 6"
    );
    $activityStmt->execute([$userId]);
}
$activity = $activityStmt->fetchAll();

$pageTitle = 'Panel principal';
$activePage = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div>
        <h2>Hola, <?= e($user['nombre']) ?></h2>
        <p><?= $isPreceptor ? 'Accedé al seguimiento, asistencia y reuniones de tus cursos.' : 'Este es el estado actual de tus gestiones en TurnOK.' ?></p>
    </div>
    <div class="page-actions">
        <?php if (!$isSpaceManagerOnly && !$isPreceptor && has_any_role(['Alumno', 'Profesor'])): ?>
            <button class="btn btn-primary" type="button" data-dialog-open="quickActions"><?= icon('plus', 17) ?> Nueva solicitud</button>
        <?php endif; ?>
    </div>
</section>

<section class="stats-grid" aria-label="Resumen">
    <?php if ($isPreceptor): ?>
    <?php
    $preceptorCoursesCount = scalar_query(
        $pdo,
        'SELECT COUNT(*) FROM preceptor_curso pc JOIN cursos cu ON cu.id = pc.curso_id AND cu.activo = 1 WHERE pc.usuario_id = ?',
        [$userId]
    );
    $attendanceTodayCount = scalar_query(
        $pdo,
        'SELECT COUNT(*) FROM preceptoria_asistencias WHERE registrado_por = ? AND fecha = CURDATE()',
        [$userId]
    );
    ?>
    <article class="stat-card"><div class="stat-top"><span>CURSOS A CARGO</span><span class="stat-icon"><?= icon('users', 19) ?></span></div><strong><?= $preceptorCoursesCount ?></strong><p>Asignados a tu cuenta</p></article>
    <article class="stat-card"><div class="stat-top"><span>ASISTENCIAS DE HOY</span><span class="stat-icon"><?= icon('check', 19) ?></span></div><strong><?= $attendanceTodayCount ?></strong><p>Alumnos con estado registrado</p></article>
    <article class="stat-card"><div class="stat-top"><span>REUNIONES PRÓXIMAS</span><span class="stat-icon"><?= icon('calendar', 19) ?></span></div><strong><?= $meetingsCount ?></strong><p>De tus cursos asignados</p></article>
    <?php else: ?>
    <?php if (!$isSpaceManagerOnly): ?><article class="stat-card">
        <div class="stat-top"><span>TRÁMITES PENDIENTES</span><span class="stat-icon"><?= icon('file', 19) ?></span></div>
        <strong><?= $tramitesCount ?></strong>
        <p><?= $isManager ? 'Pendientes de gestión' : 'Solicitudes en revisión' ?></p>
    </article><?php endif; ?>
    <article class="stat-card" style="--stat-glow:rgba(85,201,242,.12)">
        <div class="stat-top"><span>RESERVAS PENDIENTES</span><span class="stat-icon"><?= icon('building', 19) ?></span></div>
        <strong><?= $spacesCount ?></strong>
        <p><?= $isSpaceManager ? 'Pendientes de respuesta' : 'Solicitudes activas' ?></p>
    </article>
    <?php if (!$isSpaceManagerOnly): ?><article class="stat-card" style="--stat-glow:rgba(69,215,146,.12)">
        <div class="stat-top"><span>PRÓXIMAS REUNIONES</span><span class="stat-icon"><?= icon('calendar', 19) ?></span></div>
        <strong><?= $meetingsCount ?></strong>
        <p>Desde hoy en adelante</p>
    </article><?php endif; ?>
    <article class="stat-card" style="--stat-glow:rgba(255,111,126,.12)">
        <div class="stat-top"><span>NOTIFICACIONES</span><span class="stat-icon"><?= icon('bell', 19) ?></span></div>
        <strong><?= $unreadCount ?></strong>
        <p>Mensajes sin leer</p>
    </article>
    <?php endif; ?>
    <?php if ($isPreceptor): ?><article class="stat-card">
        <div class="stat-top"><span>NOTIFICACIONES</span><span class="stat-icon"><?= icon('bell', 19) ?></span></div>
        <strong><?= $unreadCount ?></strong>
        <p>Mensajes sin leer</p>
    </article><?php endif; ?>
</section>

<section class="dashboard-grid">
    <div class="stack">
        <?php if (!$isSpaceManagerOnly): ?><article class="panel">
            <header class="panel-header">
                <div><h3><?= $isPreceptor ? 'Reuniones de tus cursos' : 'Próximas reuniones' ?></h3><p><?= $isPreceptor ? 'Convocatorias para tus cursos asignados' : 'Agenda institucional presencial' ?></p></div>
                <a class="panel-link" href="<?= e(url($isPreceptor ? 'preceptoria.php' : 'reuniones.php')) ?>">VER TODAS →</a>
            </header>
            <div class="panel-body">
                <?php if (!$upcomingMeetings): ?>
                    <div class="empty-state compact"><div class="empty-icon"><?= icon('calendar', 28) ?></div><h3>No hay reuniones próximas</h3><p>Las nuevas convocatorias aparecerán acá.</p></div>
                <?php else: ?>
                    <div class="calendar-list">
                        <?php foreach ($upcomingMeetings as $meeting): ?>
                            <?php $ts = strtotime($meeting['fecha']); ?>
                            <div class="calendar-item">
                                <div class="calendar-date"><strong><?= date('d', $ts) ?></strong><span><?= e(strtoupper(date('M', $ts))) ?></span></div>
                                <div class="calendar-copy"><strong><?= e($meeting['titulo']) ?></strong><p><?= e($meeting['tipo']) ?> · <?= e($meeting['lugar']) ?></p></div>
                                <div class="calendar-meta"><?= e(format_time($meeting['hora_inicio'])) ?><span>Presencial</span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article><?php endif; ?>

        <article class="panel">
            <header class="panel-header"><div><h3>Actividad reciente</h3><p>Acciones registradas en el sistema</p></div></header>
            <div class="panel-body">
                <?php if (!$activity): ?>
                    <p class="muted">Todavía no hay actividad registrada.</p>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($activity as $item): ?>
                            <div class="list-item">
                                <span class="list-icon"><?= icon('clock', 18) ?></span>
                                <div class="list-copy"><strong><?= e(str_replace('_', ' ', mb_strtolower($item['accion']))) ?></strong><p><?= e(trim(($item['nombre'] ?? '') . ' ' . ($item['apellido'] ?? 'Sistema'))) ?></p></div>
                                <span class="list-meta"><?= e(format_date($item['created_at'], true)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </div>

    <aside class="stack">
        <article class="panel">
            <header class="panel-header"><div><h3>Accesos rápidos</h3><p>Funciones según tu perfil</p></div></header>
            <div class="panel-body">
                <div class="quick-grid">
                    <?php if (!$isSpaceManagerOnly && !$isPreceptor && has_any_role(['Alumno', 'Profesor'])): ?>
                        <a class="quick-card" href="<?= e(url('tramites.php?action=new')) ?>"><?= icon('file', 22) ?><strong>Nuevo trámite</strong><span>Iniciar una solicitud</span></a>
                        <a class="quick-card" href="<?= e(url('reuniones.php?action=request')) ?>"><?= icon('calendar', 22) ?><strong>Pedir reunión</strong><span>Contactar un cargo</span></a>
                    <?php endif; ?>
                    <?php if ($isProfessor): ?>
                        <a class="quick-card" href="<?= e(url('espacios.php?action=new')) ?>"><?= icon('building', 22) ?><strong>Reservar espacio</strong><span>Consultar disponibilidad</span></a>
                    <?php endif; ?>
                    <?php if ($isPreceptor): ?>
                        <a class="quick-card" href="<?= e(url('preceptoria.php')) ?>"><?= icon('users', 22) ?><strong>Preceptoría</strong><span>Asistencia y seguimiento de cursos</span></a>
                    <?php endif; ?>
                    <?php if ($isManager): ?>
                        <a class="quick-card" href="<?= e(url('reuniones.php?action=create')) ?>"><?= icon('plus', 22) ?><strong>Crear reunión</strong><span>Convocar participantes</span></a>
                    <?php endif; ?>
                    <?php if (can_manage_users()): ?>
                        <a class="quick-card" href="<?= e(url('usuarios.php')) ?>"><?= icon('users', 22) ?><strong>Usuarios</strong><span>Asignar roles y cargos</span></a>
                    <?php endif; ?>
                    <a class="quick-card" href="<?= e(url('notificaciones.php')) ?>"><?= icon('bell', 22) ?><strong>Notificaciones</strong><span>Revisar novedades</span></a>
                </div>
            </div>
        </article>

        <article class="panel">
            <header class="panel-header"><div><h3>Notificaciones recientes</h3><p>Últimos movimientos</p></div><a class="panel-link" href="<?= e(url('notificaciones.php')) ?>">VER TODAS →</a></header>
            <div class="panel-body">
                <?php if (!$recentNotifications): ?>
                    <div class="empty-state compact"><div class="empty-icon"><?= icon('bell', 27) ?></div><h3>Sin novedades</h3><p>No hay notificaciones todavía.</p></div>
                <?php else: ?>
                    <div class="list">
                        <?php foreach ($recentNotifications as $notification): ?>
                            <div class="list-item">
                                <span class="list-icon"><?= icon('bell', 18) ?></span>
                                <div class="list-copy"><strong><?= e($notification['titulo']) ?></strong><p><?= e($notification['mensaje']) ?></p></div>
                                <?php if (!(int) $notification['leida']): ?><span class="status status-info">Nueva</span><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </aside>
</section>

<?php if (!$isSpaceManagerOnly && has_any_role(['Alumno', 'Profesor'])): ?><dialog class="modal" id="quickActions">
    <header class="modal-header"><div><h3>Nueva solicitud</h3><p>Elegí qué gestión querés iniciar.</p></div><button class="modal-close" type="button" data-dialog-close><?= icon('x', 18) ?></button></header>
    <div class="modal-body">
        <div class="quick-grid">
            <a class="quick-card" href="<?= e(url('tramites.php?action=new')) ?>"><?= icon('file', 22) ?><strong>Solicitar trámite</strong><span>Certificados, constancias y consultas</span></a>
            <a class="quick-card" href="<?= e(url('reuniones.php?action=request')) ?>"><?= icon('calendar', 22) ?><strong>Solicitar reunión</strong><span>Con un cargo institucional</span></a>
            <?php if ($isProfessor): ?><a class="quick-card" href="<?= e(url('espacios.php?action=new')) ?>"><?= icon('building', 22) ?><strong>Solicitar espacio</strong><span>Reserva para actividad docente</span></a><?php endif; ?>
        </div>
    </div>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
