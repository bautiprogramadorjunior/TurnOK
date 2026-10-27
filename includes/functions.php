<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return APP_URL !== '' ? APP_URL . ($path !== '' ? '/' . $path : '') : ($path !== '' ? $path : './');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function post_int(string $key): ?int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? null : (int) $value;
}

function post_int_array(string $key): array
{
    $values = $_POST[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn($value) => filter_var($value, FILTER_VALIDATE_INT) ?: null,
        $values
    ))));
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrf_token(), $provided)) {
        http_response_code(419);
        exit('La sesión del formulario venció. Volvé a cargar la página.');
    }
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) ? substr($ip, 0, 45) : null;
}

function audit(PDO $pdo, ?int $userId, string $action, ?string $table = null, ?int $recordId = null, array $detail = []): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO auditoria (usuario_id, accion, tabla_afectada, registro_id, detalle, ip, user_agent)
         VALUES (:usuario_id, :accion, :tabla, :registro_id, :detalle, :ip, :user_agent)'
    );

    $stmt->execute([
        'usuario_id' => $userId,
        'accion' => substr($action, 0, 100),
        'tabla' => $table,
        'registro_id' => $recordId,
        'detalle' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'ip' => client_ip(),
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
    ]);
}

function notify_user(PDO $pdo, int $userId, string $type, string $title, string $message, ?string $refTable = null, ?int $refId = null): void
{
    $allowed = [
        'tramite_aprobado', 'tramite_rechazado', 'reserva_aprobada', 'reserva_rechazada',
        'reunion_creada', 'reunion_asignada', 'solicitud_reunion_aceptada',
    ];

    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Tipo de notificación inválido.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notificaciones (usuario_id, tipo_evento, titulo, mensaje, ref_tabla, ref_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $type, $title, $message, $refTable, $refId]);
}

function format_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
}

function format_time(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return substr($value, 0, 5);
}

function status_class(string $status): string
{
    return match (mb_strtolower($status)) {
        'aprobada', 'aprobado', 'aceptada', 'confirmada', 'finalizado', 'disponible' => 'status-success',
        'rechazada', 'rechazado', 'cancelada', 'cancelado', 'no disponible' => 'status-danger',
        'pendiente', 'normal' => 'status-warning',
        'alta', 'en mantenimiento' => 'status-info',
        default => 'status-neutral',
    };
}

function course_label(array $row): string
{
    $base = $row['anio'] . '° ' . $row['division'] . ' · ' . $row['turno'];
    if (!empty($row['especialidad'])) {
        $base .= ' · ' . $row['especialidad'];
    }
    return $base;
}

function infer_shift(string $startTime): string
{
    return $startTime < '13:30' ? 'TM' : 'TT';
}

function valid_date(string $date): bool
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function valid_time(string $time): bool
{
    return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;
}

function icon(string $name, int $size = 20): string
{
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 19.5z"/><path d="M9 21v-7h6v7"/>',
        'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'building' => '<path d="M3 21h18M6 21V6l6-3 6 3v15M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user' => '<path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'check' => '<path d="m20 6-11 11-5-5"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H3v-4h.09A1.7 1.7 0 0 0 4.6 8.6a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V3h4v.09A1.7 1.7 0 0 0 15.4 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9c.2.37.48.7.85.95.32.22.7.35 1.1.35H21v4h-.09A1.7 1.7 0 0 0 19.4 15z"/>',
    ];

    $path = $paths[$name] ?? $paths['file'];
    return '<svg aria-hidden="true" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}
