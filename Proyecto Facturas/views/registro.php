<?php
declare(strict_types=1);

require_once '../models/Sesion.php';

Sesion::iniciar();

// Quien ya tiene sesión no necesita registrarse.
if (Sesion::autenticado()){
    header('Location: dashboard.php');
    exit;
}

// Mensajes y datos del intento anterior (se usan una sola vez).
$errores = $_SESSION['registro_errores'] ?? [];
$viejo   = $_SESSION['registro_viejo'] ?? [];
unset($_SESSION['registro_errores'], $_SESSION['registro_viejo']);

function clase_campo(array $errores, string $campo): string
{
    return isset($errores[$campo]) ? ' is-invalid' : '';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear cuenta</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 380px;">
    <div class="card shadow-sm mt-5">
        <div class="card-body p-4">
            <h1 class="h4 text-center mb-4">Crear cuenta</h1>

            <form method="post" action="../controllers/AuthController.php?accion=registro" novalidate>
                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre</label>
                    <input type="text" name="nombre" id="nombre" maxlength="100" required autofocus
                           class="form-control<?= clase_campo($errores, 'nombre') ?>"
                           value="<?= htmlspecialchars($viejo['nombre'] ?? '') ?>">
                    <?php if (isset($errores['nombre'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errores['nombre']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email" maxlength="150" required
                           class="form-control<?= clase_campo($errores, 'email') ?>"
                           value="<?= htmlspecialchars($viejo['email'] ?? '') ?>">
                    <?php if (isset($errores['email'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errores['email']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" name="password" id="password" minlength="8" required
                           class="form-control<?= clase_campo($errores, 'password') ?>"
                           autocomplete="new-password">
                    <?php if (isset($errores['password'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errores['password']) ?></div>
                    <?php else: ?>
                        <div class="form-text">Mínimo 8 caracteres.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-3">
                    <label for="confirmacion" class="form-label">Repetir contraseña</label>
                    <input type="password" name="confirmacion" id="confirmacion" required
                           class="form-control<?= clase_campo($errores, 'confirmacion') ?>"
                           autocomplete="new-password">
                    <?php if (isset($errores['confirmacion'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errores['confirmacion']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary w-100">Crear cuenta</button>
            </form>
        </div>
    </div>
    <p class="text-center mt-3 small">
        ¿Ya tenés cuenta? <a href="login.php">Iniciar sesión</a>
    </p>
</div>
</body>
</html>
