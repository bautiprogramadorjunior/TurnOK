<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$user = current_user();
if ($user) {
    try {
        audit(db(), (int) $user['id'], 'LOGOUT', 'usuarios', (int) $user['id']);
    } catch (Throwable) {
        // El cierre de sesión debe completarse aunque la auditoría no esté disponible.
    }
}

sign_out();
header('Location: ' . url('login.php'));
exit;
