<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado']);
    exit;
}

$stmt = db()->prepare('SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0');
$stmt->execute([(int) $user['id']]);

echo json_encode([
    'ok' => true,
    'unread' => (int) $stmt->fetchColumn(),
], JSON_UNESCAPED_UNICODE);
