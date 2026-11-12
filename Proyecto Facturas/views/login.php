<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container" style="max-width: 380px;">
    <div class="card shadow-sm mt-5">
        <div class="card-body p-4">
            <h1 class="h4 text-center mb-4">Iniciar sesión</h1>

            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger py-2">
                    <?php 
                    if ($_GET['error'] === 'vacio'){
                        echo 'Por favor complete todos los campos.';
                    }else{
                        echo 'Email o contraseña incorrectos.';
                    }
                    ?>
                </div>
            <?php endif; ?>

            <form method="post" action="../controllers/AuthController.php?accion=login">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" name="email" id="email"
                           class="form-control" required autofocus
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Contraseña</label>
                    <input type="password" name="password" id="password"
                           class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">Ingresar</button>
            </form>
            <p class="text-center mt-3 mb-0 small">
            <a href="registro.php">Crear cuenta</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
