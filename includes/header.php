<?php
declare(strict_types=1);

$user = $user ?? current_user();
$pageTitle = $pageTitle ?? 'Panel';
$activePage = $activePage ?? '';
$unreadCount = 0;

if ($user) {
    $stmtUnread = db()->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0');
    $stmtUnread->execute([(int) $user['id']]);
    $unreadCount = (int) $stmtUnread->fetchColumn();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <title><?= e($pageTitle) ?> · TurnOK</title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/app.css?v=' . filemtime(__DIR__ . '/../assets/css/app.css'))) ?>">
</head>
<body class="app-body">
<div class="app-shell" data-app-shell>
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="icon-button mobile-menu-button" type="button" data-sidebar-toggle aria-label="Abrir menú">
                    <?= icon('menu', 22) ?>
                </button>
                <div>
                    <p class="eyebrow">Escuela Técnica Otto Krause</p>
                    <h1><?= e($pageTitle) ?></h1>
                </div>
            </div>

            <div class="topbar-actions">
                <a class="icon-button notification-button" href="<?= e(url('notificaciones.php')) ?>" aria-label="Notificaciones">
                    <?= icon('bell', 21) ?>
                    <?php if ($unreadCount > 0): ?>
                        <span class="notification-dot" data-unread-count><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a class="user-chip" href="<?= e(url('perfil.php')) ?>">
                    <span class="user-avatar"><?= e(mb_strtoupper(mb_substr($user['nombre'], 0, 1) . mb_substr($user['apellido'], 0, 1))) ?></span>
                    <span class="user-chip-copy">
                        <strong><?= e($user['nombre'] . ' ' . $user['apellido']) ?></strong>
                        <small><?= e($user['roles'][0] ?? $user['cargos'][0] ?? 'Usuario') ?></small>
                    </span>
                </a>
            </div>
        </header>

        <main class="content-area">
            <?php foreach (consume_flashes() as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" data-alert>
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" aria-label="Cerrar" data-alert-close><?= icon('x', 17) ?></button>
                </div>
            <?php endforeach; ?>
