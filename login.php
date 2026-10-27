<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_guest();

$error = null;
$step = 'dni';
$candidate = null;
$pdo = null;

try {
    $pdo = db();

    if (isset($_GET['reset'])) {
        unset($_SESSION['login_candidate_id']);
        redirect('login.php');
    }

    $candidateId = $_SESSION['login_candidate_id'] ?? null;
    if ($candidateId) {
        $stmt = $pdo->prepare('SELECT id, dni, nombre, apellido, email, password_hash, registro_completo, activo FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $candidateId]);
        $candidate = $stmt->fetch() ?: null;
        if (!$candidate || !(int) $candidate['activo']) {
            unset($_SESSION['login_candidate_id']);
            $candidate = null;
        }
    }

    if (is_post()) {
        verify_csrf();
        $action = post_string('action');

        if ($action === 'identify') {
            $dni = preg_replace('/\D+/', '', post_string('dni')) ?? '';
            if (strlen($dni) < 7 || strlen($dni) > 15) {
                throw new RuntimeException('Ingresá un DNI válido, sin puntos ni espacios.');
            }

            $stmt = $pdo->prepare('SELECT id, dni, nombre, apellido, email, password_hash, registro_completo, activo FROM usuarios WHERE dni = ? LIMIT 1');
            $stmt->execute([$dni]);
            $candidate = $stmt->fetch() ?: null;

            if (!$candidate || !(int) $candidate['activo']) {
                audit($pdo, null, 'ACCESO_DNI_NO_ENCONTRADO', 'usuarios', null, ['dni_ultimos' => substr($dni, -3)]);
                throw new RuntimeException('Ese DNI no está habilitado en TurnOK. Consultá con la institución.');
            }

            $_SESSION['login_candidate_id'] = (int) $candidate['id'];
            $step = $candidate['password_hash'] === null ? 'register' : 'password';
        } elseif ($action === 'login') {
            if (!$candidate || $candidate['password_hash'] === null) {
                unset($_SESSION['login_candidate_id']);
                throw new RuntimeException('Reiniciá el ingreso con tu DNI.');
            }

            $password = post_string('password');
            if (!password_verify($password, (string) $candidate['password_hash'])) {
                audit($pdo, (int) $candidate['id'], 'LOGIN_FALLIDO', 'usuarios', (int) $candidate['id']);
                throw new RuntimeException('La contraseña no es correcta.');
            }

            if (password_needs_rehash((string) $candidate['password_hash'], PASSWORD_DEFAULT)) {
                $rehash = $pdo->prepare('UPDATE usuarios SET password_hash = ? WHERE id = ?');
                $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $candidate['id']]);
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $candidate['id'];
            unset($_SESSION['login_candidate_id']);
            audit($pdo, (int) $candidate['id'], 'LOGIN', 'usuarios', (int) $candidate['id']);
            redirect('dashboard.php');
        } elseif ($action === 'register') {
            if (!$candidate || $candidate['password_hash'] !== null) {
                unset($_SESSION['login_candidate_id']);
                throw new RuntimeException('El primer acceso ya fue completado. Ingresá nuevamente.');
            }

            $password = post_string('password');
            $confirm = post_string('password_confirm');

            if ($password !== $confirm) {
                throw new RuntimeException('Las contraseñas no coinciden.');
            }
            if (strlen($password) < 8 || !preg_match('/[A-ZÁÉÍÓÚÑ]/u', $password) || !preg_match('/[a-záéíóúñ]/u', $password) || !preg_match('/\d/', $password)) {
                throw new RuntimeException('Usá al menos 8 caracteres, con mayúscula, minúscula y número.');
            }

            $stmt = $pdo->prepare(
                'UPDATE usuarios
                 SET password_hash = ?, registro_completo = 1, fecha_registro = NOW()
                 WHERE id = ? AND password_hash IS NULL'
            );
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), (int) $candidate['id']]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('No se pudo completar el registro. Volvé a intentarlo.');
            }

            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $candidate['id'];
            unset($_SESSION['login_candidate_id']);
            audit($pdo, (int) $candidate['id'], 'REGISTRO_COMPLETO', 'usuarios', (int) $candidate['id']);
            redirect('dashboard.php');
        }
    }

    if ($candidate) {
        $step = $candidate['password_hash'] === null ? 'register' : 'password';
    }
} catch (RuntimeException $exception) {
    $error = $exception->getMessage();
    if ($candidate) {
        $step = $candidate['password_hash'] === null ? 'register' : 'password';
    }
} catch (PDOException $exception) {
    $error = APP_ENV === 'development'
        ? 'No se pudo conectar con MySQL: ' . $exception->getMessage()
        : 'TurnOK no puede conectarse con la base de datos. Revisá la configuración del servidor.';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <title>Acceso institucional · TurnOK</title>
    <link rel="icon" type="image/png" href="<?= e(url('assets/img/logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/login.css')) ?>">
</head>
<body>
<div class="login-page">
    <aside class="login-showcase">
        <div class="showcase-glow glow-one"></div>
        <div class="showcase-glow glow-two"></div>

        <a class="login-brand" href="<?= e(url('index.html')) ?>">
            <img src="<?= e(url('assets/img/logo.png')) ?>" alt="Escudo Otto Krause">
            <span><strong>TURN<span>OK</span></strong><small>Escuela Técnica Otto Krause</small></span>
        </a>

        <div class="showcase-copy">
            <span class="showcase-tag">GESTIÓN INSTITUCIONAL</span>
            <h1>Una escuela conectada,<br><em>sin trámites dispersos.</em></h1>
            <p>Accedé a tus solicitudes, reservas, reuniones y notificaciones desde un entorno único y seguro.</p>
        </div>

        <div class="module-grid">
            <article><span>01</span><strong>Gestión de trámites</strong><small>Seguimiento y turnos</small></article>
            <article><span>02</span><strong>Reserva de espacios</strong><small>Aprobación por turno</small></article>
            <article><span>03</span><strong>Reuniones</strong><small>Agenda institucional</small></article>
            <article><span>04</span><strong>Notificaciones</strong><small>Novedades centralizadas</small></article>
        </div>

        <div class="showcase-metrics">
            <div><strong>6</strong><span>especialidades</span></div>
            <div><strong>4</strong><span>perfiles de acceso</span></div>
            <div><strong>1</strong><span>plataforma central</span></div>
        </div>
    </aside>

    <main class="login-area">
        <div class="login-topbar">
            <a href="<?= e(url('index.html')) ?>">← Volver al inicio</a>
            <span><i></i> Acceso institucional</span>
        </div>

        <section class="login-card" aria-labelledby="login-title">
            <div class="login-card-heading">
                <span class="step-indicator">PASO <?= $step === 'dni' ? '1' : '2' ?> DE 2</span>
                <?php if ($step === 'dni'): ?>
                    <h2 id="login-title">Ingresá a TurnOK</h2>
                    <p>Usá el DNI que fue previamente cargado por la institución.</p>
                <?php elseif ($step === 'register'): ?>
                    <h2 id="login-title">Completá tu primer acceso</h2>
                    <p>Hola, <?= e($candidate['nombre'] ?? '') ?>. Creá una contraseña para activar tu cuenta.</p>
                <?php else: ?>
                    <h2 id="login-title">Bienvenido/a</h2>
                    <p><?= e(($candidate['nombre'] ?? '') . ' ' . ($candidate['apellido'] ?? '')) ?> · DNI <?= e($candidate['dni'] ?? '') ?></p>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="login-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($step === 'dni'): ?>
                <form method="post" autocomplete="off" class="login-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="identify">
                    <label for="dni">Documento Nacional de Identidad</label>
                    <div class="input-wrap">
                        <span>DNI</span>
                        <input id="dni" name="dni" inputmode="numeric" pattern="[0-9]{7,15}" maxlength="15" placeholder="Ingresá tu número, sin puntos" required autofocus>
                    </div>
                    <button class="login-submit" type="submit">Continuar <span>→</span></button>
                </form>
            <?php elseif ($step === 'register'): ?>
                <form method="post" class="login-form" data-password-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="register">
                    <label for="password">Nueva contraseña</label>
                    <div class="input-wrap password-wrap">
                        <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required autofocus>
                        <button type="button" data-toggle-password aria-label="Mostrar contraseña">Ver</button>
                    </div>
                    <div class="password-rules" data-password-rules>
                        <span data-rule="length">8 caracteres</span>
                        <span data-rule="upper">Una mayúscula</span>
                        <span data-rule="lower">Una minúscula</span>
                        <span data-rule="number">Un número</span>
                    </div>
                    <label for="password_confirm">Repetir contraseña</label>
                    <div class="input-wrap password-wrap">
                        <input id="password_confirm" type="password" name="password_confirm" minlength="8" autocomplete="new-password" placeholder="Repetí la contraseña" required>
                    </div>
                    <button class="login-submit" type="submit">Activar cuenta <span>→</span></button>
                    <a class="change-dni" href="?reset=1">Usar otro DNI</a>
                </form>
            <?php else: ?>
                <form method="post" class="login-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="login">
                    <label for="password">Contraseña</label>
                    <div class="input-wrap password-wrap">
                        <input id="password" type="password" name="password" autocomplete="current-password" placeholder="Ingresá tu contraseña" required autofocus>
                        <button type="button" data-toggle-password aria-label="Mostrar contraseña">Ver</button>
                    </div>
                    <button class="login-submit" type="submit">Ingresar <span>→</span></button>
                    <a class="change-dni" href="?reset=1">No soy esta persona</a>
                </form>
            <?php endif; ?>

            <div class="institution-note">
                <strong>¿No figurás en el sistema?</strong>
                <span>TurnOK no permite registros libres. La institución debe precargar tus datos.</span>
            </div>
        </section>

        <p class="login-footer">TurnOK · Sistema interno de la Escuela Técnica Otto Krause</p>
    </main>
</div>
<script src="<?= e(url('assets/js/login.js')) ?>"></script>
</body>
</html>
