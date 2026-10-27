<?php
declare(strict_types=1);

$navItems = [
    ['key' => 'dashboard', 'label' => 'Panel principal', 'href' => 'dashboard.php', 'icon' => 'home', 'show' => true],
    ['key' => 'tramites', 'label' => 'Trámites', 'href' => 'tramites.php', 'icon' => 'file', 'show' => !is_space_manager_only() && has_any_role(['Alumno', 'Profesor', 'Directivo'])],
    ['key' => 'espacios', 'label' => 'Espacios', 'href' => 'espacios.php', 'icon' => 'building', 'show' => !has_cargo('Preceptor') && (has_role('Profesor') || has_cargo('Encargado de Espacio') || can_manage_users())],
    ['key' => 'reuniones', 'label' => 'Reuniones', 'href' => 'reuniones.php', 'icon' => 'calendar', 'show' => !is_space_manager_only() && has_any_role(['Alumno', 'Profesor', 'Directivo'])],
    ['key' => 'preceptoria', 'label' => 'Preceptoría', 'href' => 'preceptoria.php', 'icon' => 'users', 'show' => !is_space_manager_only() && has_cargo('Preceptor')],
    ['key' => 'notificaciones', 'label' => 'Notificaciones', 'href' => 'notificaciones.php', 'icon' => 'bell', 'show' => true],
    ['key' => 'usuarios', 'label' => 'Usuarios', 'href' => 'usuarios.php', 'icon' => 'users', 'show' => can_manage_users()],
];
?>
<aside class="sidebar" data-sidebar>
    <a class="brand" href="<?= e(url('dashboard.php')) ?>">
        <img src="<?= e(url('assets/img/logo.png')) ?>" alt="Escudo Otto Krause">
        <span>
            <strong>TURN<span>OK</span></strong>
            <small>Gestión institucional</small>
        </span>
    </a>

    <nav class="sidebar-nav" aria-label="Navegación principal">
        <p class="sidebar-label">MENÚ</p>
        <?php foreach ($navItems as $item): ?>
            <?php if (!$item['show']) continue; ?>
            <a class="nav-link <?= $activePage === $item['key'] ? 'active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                <?= icon($item['icon'], 20) ?>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <a class="nav-link <?= $activePage === 'perfil' ? 'active' : '' ?>" href="<?= e(url('perfil.php')) ?>">
            <?= icon('user', 20) ?>
            <span>Mi perfil</span>
        </a>
        <a class="nav-link" href="<?= e(url('logout.php')) ?>">
            <?= icon('logout', 20) ?>
            <span>Cerrar sesión</span>
        </a>
        <div class="sidebar-version">TurnOK · v1.0</div>
    </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-backdrop></div>
