<?php
declare(strict_types=1);

require_once '../models/Sesion.php';
require_once '../config/conexion.php';
require_once '../models/Factura.php';

Sesion::proteger();

$usuario_id = (int)Sesion::usuario()['id'];
$facturaModel = new Factura(Conexion::obtener());
$factura = $facturaModel->obtenerUna((int)($_GET['id'] ?? 0), $usuario_id);

// Una factura que no existe o que es de otra persona: de vuelta al panel.
if ($factura === null){
    header('Location: dashboard.php');
    exit;
}

$urlPdf = '../controllers/FacturaController.php?accion=ver_pdf&factura_id=' . (int)$factura['id'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura <?= htmlspecialchars((string)($factura['comprobante'] ?? '')) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex flex-column vh-100">

<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand mb-0 h6">
            <?= htmlspecialchars((string)($factura['comprobante'] ?? 'Factura')) ?>
            <small class="text-secondary ms-2">Periodo <?= htmlspecialchars((string)($factura['periodo'] ?? 'N/A')) ?></small>
        </span>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars($urlPdf) ?>" target="_blank" rel="noopener">Abrir en otra pestaña</a>
            <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars($urlPdf) ?>&descargar=1">Descargar</a>
            <a class="btn btn-light btn-sm" href="dashboard.php">Volver</a>
        </div>
    </div>
</nav>

<?php /*
 * El navegador no le pide el PDF a la CEG directamente: lo pide a FacturaController (accion=ver_pdf), que
 * verifica que la factura sea del usuario logueado y agrega cabeceras de seguridad.
 */ ?>
<iframe src="<?= htmlspecialchars($urlPdf) ?>" title="Factura en PDF" class="flex-grow-1 border-0 w-100"></iframe>

</body>
</html>