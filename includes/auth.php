<?php
declare(strict_types=1);

function current_user(bool $refresh = false): ?array
{
    static $cachedUser = null;
    static $loaded = false;

    if ($refresh) {
        $cachedUser = null;
        $loaded = false;
    }

    if ($loaded) {
        return $cachedUser;
    }

    $loaded = true;
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT u.*,
                GROUP_CONCAT(DISTINCT r.nombre ORDER BY r.nombre SEPARATOR '||') AS roles_text,
                GROUP_CONCAT(DISTINCT c.nombre ORDER BY c.nombre SEPARATOR '||') AS cargos_text
         FROM usuarios u
         LEFT JOIN usuario_roles ur ON ur.usuario_id = u.id
         LEFT JOIN roles r ON r.id = ur.rol_id AND r.activo = 1
         LEFT JOIN usuario_cargos uc ON uc.usuario_id = u.id AND uc.activo = 1
            AND (uc.desde IS NULL OR uc.desde <= CURDATE())
            AND (uc.hasta IS NULL OR uc.hasta >= CURDATE())
         LEFT JOIN cargos c ON c.id = uc.cargo_id AND c.activo = 1
         WHERE u.id = ? AND u.activo = 1
         GROUP BY u.id"
    );
    $stmt->execute([(int) $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user['roles'] = !empty($user['roles_text']) ? explode('||', $user['roles_text']) : [];
    $user['cargos'] = !empty($user['cargos_text']) ? explode('||', $user['cargos_text']) : [];
    $cachedUser = $user;
    return $cachedUser;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('warning', 'Iniciá sesión para continuar.');
        redirect('login.php');
    }
    return $user;
}

function require_guest(): void
{
    if (current_user()) {
        redirect('dashboard.php');
    }
}

function has_role(string $role): bool
{
    $user = current_user();
    return $user !== null && in_array($role, $user['roles'], true);
}

function has_any_role(array $roles): bool
{
    $user = current_user();
    return $user !== null && array_intersect($roles, $user['roles']) !== [];
}

function has_cargo(string $cargo): bool
{
    $user = current_user();
    return $user !== null && in_array($cargo, $user['cargos'], true);
}

function is_space_manager_only(): bool
{
    return has_cargo('Encargado de Espacio');
}

function can_manage_institution(): bool
{
    return !is_space_manager_only() && !has_cargo('Preceptor') && has_any_role(['Administrativo', 'Directivo']);
}

function can_manage_users(): bool
{
    return !is_space_manager_only() && !has_cargo('Preceptor') && has_role('Directivo') && has_cargo('Rector');
}

function deny_access(): never
{
    http_response_code(403);
    $pageTitle = 'Acceso restringido';
    $activePage = '';
    require __DIR__ . '/header.php';
    echo '<section class="empty-state"><div class="empty-icon">' . icon('x', 34) . '</div><h2>No tenés permisos para acceder</h2><p>Esta sección está reservada para otros perfiles institucionales.</p><a class="btn btn-primary" href="' . e(url('dashboard.php')) . '">Volver al panel</a></section>';
    require __DIR__ . '/footer.php';
    exit;
}

function require_user_management(): array
{
    $user = require_roles(['Directivo']);
    if (!can_manage_users()) {
        deny_access();
    }
    return $user;
}

function require_cargo(string $cargo): array
{
    $user = require_login();
    if (is_space_manager_only() || !has_cargo($cargo)) {
        deny_access();
    }
    return $user;
}

function require_roles(array $roles): array
{
    $user = require_login();
    if (is_space_manager_only() || has_cargo('Preceptor') || array_intersect($roles, $user['roles']) === []) {
        deny_access();
    }
    return $user;
}

function sign_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
