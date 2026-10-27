<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$pdo = db();
$userId = (int) $user['id'];

if (is_post()) {
    verify_csrf();
    $action = post_string('action');
    if ($action === 'mark_all') {
        $stmt = $pdo->prepare('UPDATE notificaciones SET leida = 1, fecha_lectura = COALESCE(fecha_lectura, NOW()) WHERE usuario_id = ? AND leida = 0');
        $stmt->execute([$userId]);
        flash('success', 'Todas las notificaciones fueron marcadas como leídas.');
    } elseif ($action === 'mark_one') {
        $notificationId = post_int('notificacion_id');
        if ($notificationId) {
            $stmt = $pdo->prepare('UPDATE notificaciones SET leida = 1, fecha_lectura = COALESCE(fecha_lectura, NOW()) WHERE id = ? AND usuario_id = ?');
            $stmt->execute([$notificationId, $userId]);
        }
    }
    redirect('notificaciones.php');
}

$filter = $_GET['filtro'] ?? 'todas';
$where = 'usuario_id = ?';
$params = [$userId];
if ($filter === 'no_leidas') {
    $where .= ' AND leida = 0';
}
$stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE $where ORDER BY fecha_envio DESC");
$stmt->execute($params);
$notifications = $stmt->fetchAll();
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0');
$countStmt->execute([$userId]);
$unread = (int) $countStmt->fetchColumn();

$pageTitle = 'Notificaciones';
$activePage = 'notificaciones';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div><h2>Bandeja de notificaciones</h2><p>Novedades de trámites, reservas y reuniones.</p></div>
    <div class="page-actions"><?php if ($unread > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="mark_all"><button class="btn btn-primary" type="submit"><?= icon('check', 17) ?> Marcar todas como leídas</button></form><?php endif; ?></div>
</section>

<div class="filter-bar">
    <a class="btn <?= $filter === 'todas' ? 'btn-primary' : '' ?>" href="<?= e(url('notificaciones.php?filtro=todas')) ?>">Todas</a>
    <a class="btn <?= $filter === 'no_leidas' ? 'btn-primary' : '' ?>" href="<?= e(url('notificaciones.php?filtro=no_leidas')) ?>">No leídas<?= $unread ? ' (' . $unread . ')' : '' ?></a>
</div>

<?php if (!$notifications): ?>
<section class="empty-state"><div class="empty-icon"><?= icon('bell', 31) ?></div><h2>No hay notificaciones</h2><p><?= $filter === 'no_leidas' ? 'Ya leíste todas las novedades.' : 'Las actualizaciones importantes aparecerán en esta bandeja.' ?></p></section>
<?php else: ?>
<section class="notification-list">
<?php foreach ($notifications as $notification): ?>
    <article class="notification-item <?= !(int) $notification['leida'] ? 'unread' : '' ?>">
        <span class="notification-icon"><?= icon(match (true) { str_contains($notification['tipo_evento'], 'tramite') => 'file', str_contains($notification['tipo_evento'], 'reserva') => 'building', default => 'calendar' }, 20) ?></span>
        <div class="notification-copy"><h3><?= e($notification['titulo']) ?></h3><p><?= e($notification['mensaje']) ?></p><?php if ($notification['ref_tabla'] && $notification['ref_id']): ?><small class="muted">Referencia: <?= e($notification['ref_tabla']) ?> #<?= (int) $notification['ref_id'] ?></small><?php endif; ?></div>
        <div><span class="notification-time"><?= e(format_date($notification['fecha_envio'], true)) ?></span><?php if (!(int) $notification['leida']): ?><form method="post" style="margin-top:9px"><?= csrf_field() ?><input type="hidden" name="action" value="mark_one"><input type="hidden" name="notificacion_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-sm" type="submit">Marcar leída</button></form><?php endif; ?></div>
    </article>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
