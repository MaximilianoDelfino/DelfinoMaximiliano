<?php
declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once('../models/Sesion.php');
require_once('../config/conexion.php');
require_once('../models/Factura.php');
require_once('../models/EstimadorKwh.php');

// Esta página es privada: si no hay sesión, redirige al login.
Sesion::proteger();

$usuario = Sesion::usuario();
$usuario_id = (int)$usuario['id'];

$db = Conexion::obtener();
$facturaModel = new Factura($db);
$estimador = new EstimadorKwh($db);
$historial = $facturaModel->obtenerPorUsuario($usuario_id);

// Textos de los avisos. Tras cada acción, los controladores redirigen con ?exito=clave, ?error=clave o
// ?info=clave. Solo se muestran claves que existan en este mapa: nunca se imprime texto crudo de la URL.
$mensajes = [
    'exito' => [
        'manual' => 'Factura cargada.',
        'sincronizado' => 'Facturas sincronizadas correctamente.',
        'actualizacion_exitosa' => 'Consumo actualizado correctamente.',
        'estado_actualizado' => 'Estado de la factura actualizado.',
        'fecha_actualizada' => 'Fecha de pago actualizada con éxito.',
        'suministro_guardado' => 'Tarifa del suministro guardada.',
        'lectura_guardada' => 'Lectura del medidor guardada.',
        'lectura_base' => 'Primer valor guardado como punto de partida. Desde la próxima lectura se calculan los kWh consumidos y el importe.',
        ],
    'error' => [
        'bd' => 'No se pudo guardar la factura.',
        'faltan_datos' => 'Faltan datos obligatorios.',
        'datos_invalidos' => 'Los datos enviados no son válidos.',
        'sincronizacion_parcial' => 'La sincronización terminó con errores.',
        'actualizacion_fallida' => 'No se pudo actualizar el consumo.',
        'api_caida' => 'No se pudo conectar con la CEG. Intenta de nuevo más tarde.',
        'api_respuesta_invalida' => 'La CEG respondió con un formato inesperado.',
        'estado_fallido' => 'No se pudo cambiar el estado de la factura.',
        'fecha_invalida' => 'La fecha introducida no fue válida.',
        'fecha_fallida' => 'No se pudo actualizar la fecha de pago.',
        'suministro_fallido' => 'No se pudo guardar la tarifa del suministro.',
        'lectura_fallida' => 'No se pudo guardar la lectura del medidor.',
        'lectura_menor' => 'La lectura no puede ser menor que el punto de partida. Si cambiaste el medidor, marca "nuevo punto de partida".',
        ],
    'info' => [
        'sin_resultados' => 'No hay facturas pendientes en la API.',
        'sincronizado_pdf_pendiente' => 'Facturas sincronizadas. Faltan leer algunos PDF: se completa en la próxima sincronización.',
    ],
];
// Tipo de aviso -> clase de Bootstrap (alert-success verde, alert-danger rojo, alert-warning amarillo).
$estilos = ['exito' => 'success', 'error' => 'danger', 'info' => 'warning'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Facturas CEG</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1">Facturas CEG</span>
        <div class="d-flex align-items-center gap-3">
            <span class="text-light small"><?= htmlspecialchars($usuario['nombre']) ?></span>
            <a href="../controllers/AuthController.php?accion=logout" class="btn btn-outline-light btn-sm">Cerrar sesión</a>
        </div>
    </div>
</nav>

<main class="container pb-5">

    <?php foreach ($estilos as $tipo => $clase):
        $clave = $_GET[$tipo] ?? '';
        if (is_string($clave) && isset($mensajes[$tipo][$clave])): ?>
            <div class="alert alert-<?= $clase ?>" role="alert">
                <?= htmlspecialchars($mensajes[$tipo][$clave]) ?>
            </div>
    <?php endif; endforeach; ?>

    <div class="row g-4 mb-4">

        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 card-title">Sincronizar con la CEG</h2>
                    <p class="text-muted small">Trae tus facturas pendientes de forma automática.</p>
                    <form action="../controllers/FacturaController.php?accion=sincronizar_ceg" method="POST">
                        <div class="mb-3">
                            <label for="numero_usuario_ceg" class="form-label">Número de usuario</label>
                            <input type="number" class="form-control" id="numero_usuario_ceg" name="numero_usuario_ceg" required placeholder="00000">
                            <div class="form-text">Figura en tu factura.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="auto_sync" id="auto_sync" value="1">
                            <label class="form-check-label small" for="auto_sync">
                                Sincronizar automáticamente todos los días
                            </label>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Sincronizar</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 card-title mb-3">Cargar una factura manualmente</h2>
                    <form action="../controllers/FacturaController.php?accion=carga_manual" method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="nis" class="form-label">NIS</label>
                                <input type="text" class="form-control" id="nis" name="nis">
                            </div>
                            <div class="col-md-6">
                                <label for="comprobante" class="form-label">N° de comprobante</label>
                                <input type="text" class="form-control" id="comprobante" name="comprobante" required>
                            </div>
                            <div class="col-md-6">
                                <label for="periodo" class="form-label">Periodo</label>
                                <input type="text" class="form-control" id="periodo" name="periodo" placeholder="Ej: 4/2026">
                            </div>
                            <div class="col-md-6">
                                <label for="kwh_consumidos" class="form-label">kWh consumidos</label>
                                <input type="number" class="form-control" id="kwh_consumidos" name="kwh_consumidos">
                            </div>

                            <div class="col-md-6">
                                <label for="fecha_vencimiento_1" class="form-label">1º vencimiento</label>
                                <input type="date" class="form-control mb-2" id="fecha_vencimiento_1" name="fecha_vencimiento_1" required>
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" class="form-control" name="monto_vencimiento_1" placeholder="Monto" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="fecha_vencimiento_2" class="form-label">2º vencimiento</label>
                                <input type="date" class="form-control mb-2" id="fecha_vencimiento_2" name="fecha_vencimiento_2">
                                <div class="input-group">
                                    <span class="input-group-text">$</span>
                                    <input type="number" step="0.01" class="form-control" name="monto_vencimiento_2" placeholder="Monto">
                                </div>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success mt-4">Guardar factura</button>
                    </form>
                </div>
                <?php /*
                 * Los parciales (_lectura_medidor.php y _suministros.php) se incluyen acá y usan las variables ya definidas
                 * arriba ($db, $usuario_id, $estimador). Abiertos directamente por URL no muestran nada.
                 */ ?>
                <?php include __DIR__ . '/_lectura_medidor.php'; ?>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/_suministros.php'; ?>
    <!-- Historial -->
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Historial de facturas</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Periodo</th>
                        <th>Comprobante</th>
                        <th>Vencimiento</th>
                        <th class="text-end">Monto</th>
                        <th>Consumo</th>
                        <th class="text-center">Estado</th>
                        <th>Fecha de pago</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($historial)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                Todavía no hay facturas. Sincroniza con la CEG o cárgalas manualmente.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($historial as $factura):
                            $paga = ($factura['estado'] ?? 'pendiente') === 'paga';

                            // La CEG tiene dos vencimientos (el 2º con recargo). Fecha de referencia: la de pago si la factura está
                            // paga; si no, hoy. Si esa fecha ya pasó el 1º vencimiento y existe el 2º, es el 2º el que rige.
                            $referencia = ($paga && !empty($factura['fecha_pago'])) ? $factura['fecha_pago'] : date('Y-m-d');

                            $usa_segundo = $referencia > $factura['fecha_vencimiento_1']
                                && $factura['fecha_vencimiento_2'] !== null
                                && $factura['monto_vencimiento_2'] !== null;

                            $fecha_vigente = $usa_segundo ? $factura['fecha_vencimiento_2'] : $factura['fecha_vencimiento_1'];
                            $monto_vigente = $usa_segundo ? $factura['monto_vencimiento_2'] : $factura['monto_vencimiento_1'];
                            // Solo se estima si NO hay kWh reales: cuando el PDF ya se leyó (o se cargaron a mano), se muestran los reales.
                            $estimado = $factura['kwh_consumidos'] === null
                            ? $estimador->estimar((int)$factura['id'], $usuario_id)
                            : null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($factura['periodo'] ?? 'N/A') ?></td>
                            <td>
                                <?= htmlspecialchars($factura['comprobante'] ?? 'S/N') ?>
                                <?php if (preg_match('~^FAC-\d+(/\d+)?$~', (string)$factura['comprobante'])): ?>
                                    <a href="ver_factura.php?id=<?= (int)$factura['id'] ?>" class="btn btn-sm btn-outline-secondary d-block mt-1">Ver PDF</a>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= htmlspecialchars(date('d/m/Y', strtotime((string)$fecha_vigente))) ?>
                                <small class="text-muted d-block"><?= $usa_segundo ? '2º vencimiento' : '1º vencimiento' ?></small>
                            </td>

                            <td class="text-end">$ <?= htmlspecialchars(number_format((float)$monto_vigente, 2, ',', '.')) ?></td>

                            <td>
                                <?php if ($factura['kwh_consumidos'] === null): ?>
                                    <form action="../controllers/FacturaController.php?accion=cargar_kwh" method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
                                        <input type="number" name="kwh" class="form-control form-control-sm" style="max-width: 100px;" placeholder="kWh" min="0" required>
                                        <button type="submit" class="btn btn-sm btn-outline-success">Cargar</button>
                                    </form>
                                <?php if ($estimado !== null): ?>
                                <small class="text-muted d-block">≈ <?= (int)$estimado ?> kWh (estimado)</small>
                                <?php endif; ?>
                                <?php else: ?>
                                    <?php /*
                                     * number_format(x, 2, ',', '.') da formato argentino ("1.234,50"); los dos rtrim quitan los ceros y la coma
                                     * sobrantes ("147,00" -> "147"). htmlspecialchars escapa el texto para evitar XSS.
                                     */ ?>
                                    <?= htmlspecialchars(rtrim(rtrim(number_format((float)$factura['kwh_consumidos'], 2, ',', '.'), '0'), ',')) ?> kWh
                                <?php endif; ?>
                                <?php if (empty($factura['pdf_leido_en']) && (int)($factura['pdf_intentos'] ?? 0) >= 3): ?>
                                    <small class="text-muted d-block">No se pudo leer el PDF de esta factura.</small>
                                <?php endif; ?>
                            </td>
                            <?php
                                // Texto del cuadro de confirmación (confirm() de JavaScript), distinto según se vaya a marcar PAGA o PENDIENTE.
                                $periodoTxt = (string)($factura['periodo'] ?? 'sin período');
                                if ($paga) {
                                    $mensajeEstado = "¿Marcar la factura del período {$periodoTxt} como PENDIENTE?";
                                    if (!empty($factura['pago_detectado'])) {
                                        $mensajeEstado .= "\n\nSe borrará la fecha de pago detectada automáticamente.";
                                    }
                                } else {
                                    $mensajeEstado = "¿Marcar la factura del período {$periodoTxt} como PAGA?";
                                    if (empty($factura['fecha_pago'])) {
                                        $mensajeEstado .= "\n\nSe guardará la fecha de hoy (" . date('d/m/Y') . ") como fecha de pago. Podés cambiarla después.";
                                    }
                                    $mensajeEstado .= "\n\nLa fecha de pago se usa para estimar la mora.";
                                }
                            ?>
                            <td class="text-center">
                                <?php /*
                                 * onsubmit + confirm(): el formulario solo se envía si la persona acepta. json_encode convierte el texto PHP
                                 * en un literal JavaScript válido y htmlspecialchars lo hace seguro para ir dentro de un atributo HTML.
                                 */ ?>
                                <form action="../controllers/FacturaController.php?accion=cambiar_estado" method="POST"
                                      class="m-0 d-flex justify-content-center"
                                      onsubmit="return confirm(<?= htmlspecialchars(json_encode($mensajeEstado, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>);">
                                    <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
                                    <input type="hidden" name="estado" value="<?= $paga ? 'pendiente' : 'paga' ?>">
                                    <button type="submit"
                                            class="btn btn-sm rounded-pill <?= $paga ? 'btn-success' : 'btn-warning' ?>"
                                            style="min-width: 6.5rem;"
                                            title="<?= $paga ? 'Paga. Clic para marcarla como pendiente' : 'Pendiente. Clic para marcarla como paga' ?>">
                                        <?= $paga ? 'Paga' : 'Pendiente' ?>
                                    </button>
                                </form>
                            </td>
                            <!-- Fecha de pago -->
                            <td>
                                <?php if ($paga): ?>
                                    <form action="../controllers/FacturaController.php?accion=cambiar_fecha_pago" method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
                                        <input type="date" name="fecha_pago" class="form-control form-control-sm" style="max-width: 150px;"
                                            value="<?= htmlspecialchars((string)($factura['fecha_pago'] ?? '')) ?>"
                                            max="<?= date('Y-m-d') ?>" required>
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Guardar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
</body>
</html>