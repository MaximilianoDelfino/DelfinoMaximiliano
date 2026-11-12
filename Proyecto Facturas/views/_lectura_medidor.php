<?php
declare(strict_types=1);

// Parcial de vista: tarjeta "Lectura del medidor". Se incluye desde dashboard.php, que ya definió
// $db, $usuario_id y $estimador. Abierto directamente por URL no muestra nada.
if (!isset($db, $usuario_id, $estimador)) {
    exit;
}

require_once __DIR__ . '/../models/Suministro.php';
require_once __DIR__ . '/../models/LecturaMedidor.php';

$lecturaModel = new LecturaMedidor($db);
$suministrosLectura = (new Suministro($db))->obtenerPorUsuario($usuario_id);
$ultimasLecturas = $lecturaModel->ultimasPorUsuario($usuario_id);

// Se indica el índice 1 solo en el primer elemento; PHP numera los siguientes 2, 3, ...
// Así $nombresMes[10] es 'octubre'.
$nombresMes = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
               'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** "10/2026" -> "octubre 2026" */
$mesLegible = static function (string $periodo) use ($nombresMes): string {
    [$mes, $anio] = array_map('intval', explode('/', $periodo));
    return $nombresMes[$mes] . ' ' . $anio;
};
// Ojo con el nombre: no devuelve el mes de la factura sino el mes ANTERIOR al período, que es el
// del segundo cuadro tarifario que interviene en el cálculo (ver EstimadorKwh::ponderar).
/** "10/2026" -> "septiembre 2026" (el otro cuadro que se usa: el del mes anterior) */
$mesDeEmision = static function (string $periodo) use ($nombresMes): string {
    [$mes, $anio] = array_map('intval', explode('/', $periodo));
    $mes--;
    if ($mes === 0) {
        $mes = 12;
        $anio--;
    }
    return $nombresMes[$mes] . ' ' . $anio;
};

/** 24200.35 -> "24.200,35" ; 24200.00 -> "24.200" */
$numero = static fn($v): string => rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Lectura del medidor</h2>
        <p class="text-muted small mb-3">
            Ingresa el número que marca tu medidor. Si ya sincronizaste tus facturas, el punto de partida se toma
            de la lectura de la última factura y el primer valor que ingreses ya calcula el consumo; si no, ese
            primer valor queda como punto de partida. El importe es el de lo consumido hasta hoy, con el cuadro del
            mes del período (primeros 20 días) y el del mes anterior (el resto).
        </p>
        <div class="alert alert-warning py-2 small mb-3">
            <strong>En caso de vivir en zona rural o alejada se recomienda introducir valor inicial manualmente.</strong><br>
            En esas zonas la CEG no siempre toma la lectura exacta del medidor: estima según meses anteriores.
            Para que el cálculo parta del valor real, ingresá la lectura de tu medidor con la casilla
            «Usar esta lectura como nuevo punto de partida» tildada.
        </div>

        <?php if (empty($suministrosLectura)): ?>
            <p class="text-muted mb-0">Todavía no tienes suministros.</p>
        <?php else: ?>
            <?php foreach ($suministrosLectura as $sum):
                $sid = (int)$sum['id'];
                $sugerido = $lecturaModel->periodoSugerido($usuario_id, $sid);
                $lectura = $ultimasLecturas[$sid] ?? null;
                // ¿La última lectura es del período que todavía no tiene factura? Si es de un período ya facturado, el
                // campo se deja vacío para que se cargue la lectura del período nuevo (ver mensaje al final de la tarjeta).
                $esDelPeriodoActual = $lectura !== null && $lectura['periodo'] === $sugerido;
                // Consumo del período = lectura actual − punto de partida. round(..., 2) evita restas con ruido decimal.
                $consumo = $lectura === null ? 0.0 : round((float)$lectura['lectura_actual'] - (float)$lectura['lectura_inicial'], 2);
                
                // La mora solo se suma si el periodo todavía no tiene factura (si no, ya está incluida en ella).
                $moraInfo = ($lectura !== null && $consumo > 0 && $esDelPeriodoActual)
                    ? $estimador->moraPendiente($sid, $usuario_id, (string)$lectura['periodo'])
                    : ['mora' => 0.0, 'detalle' => null];
                // importePorConsumo() devuelve null si falta la tarifa del suministro o algún cuadro tarifario: más abajo
                // la vista distingue esos casos para mostrar el aviso correcto.
                $calculo = ($lectura === null || $consumo <= 0) ? null : $estimador->importePorConsumo(
                    $sid, $usuario_id, (string)$lectura['periodo'], $consumo, $moraInfo['mora']
                );
            ?>
                <div id="lectura_<?= $sid ?>" class="mb-3 pb-3 border-bottom">
                    <form action="../controllers/FacturaController.php?accion=guardar_lectura" method="POST"
                          class="row g-2 align-items-end">
                        <input type="hidden" name="suministro_id" value="<?= $sid ?>">

                        <div class="col-md-2">
                            <div class="small text-muted">NIS</div>
                            <div class="fw-semibold"><?= htmlspecialchars($sum['nis']) ?></div>
                        </div>

                        <div class="col-md-3">
                            <label for="lectura_in_<?= $sid ?>" class="form-label small mb-1">Lectura del medidor (kWh)</label>
                            <input type="number" id="lectura_in_<?= $sid ?>" name="lectura" class="form-control"
                                   min="0" step="1" required
                                   value="<?= $esDelPeriodoActual ? (int)$lectura['lectura_actual'] : '' ?>">
                        </div>

                        <div class="col-md-2">
                            <label for="periodo_lectura_<?= $sid ?>" class="form-label small mb-1">Periodo (mes del consumo)</label>
                            <input type="text" id="periodo_lectura_<?= $sid ?>" name="periodo" class="form-control"
                                   required pattern="\s*\d{1,2}\s*[/-]\s*\d{4}\s*" placeholder="M/AAAA"
                                   value="<?= htmlspecialchars($sugerido) ?>">
                        </div>

                        <div class="col-md-3">
                            <button type="submit" class="btn btn-success w-100">Guardar lectura</button>
                        </div>

                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="nuevo_punto" value="1"
                                       id="nuevo_punto_<?= $sid ?>">
                                <label class="form-check-label small text-muted" for="nuevo_punto_<?= $sid ?>">
                                    Usar esta lectura como nuevo punto de partida (cambio de medidor, zona rural o ciclo)
                                </label>
                            </div>
                        </div>
                    </form>

                    <?php /*
                     * origen_inicial dice de dónde salió el punto de partida (ver LecturaMedidor::guardar): factura / anterior /
                     * nuevo / base. Estos textos se le muestran a la persona.
                     */ ?>
                    <?php if ($lectura !== null):
                        $origenes = [
                            'factura'  => 'tomado de la lectura de la factura del período anterior (en zonas rurales la CEG puede haberla estimado)',
                            'anterior' => 'tomado de tu lectura anterior',
                            'nuevo'    => 'nuevo punto de partida que indicaste',
                            'base'     => 'primer valor que cargaste',
                        ];
                        $origenTexto = $origenes[$lectura['origen_inicial'] ?? 'base'] ?? '';
                    ?>
                        <div class="mt-2">
                            <?php if ($consumo <= 0): ?>
                                <div class="text-muted">
                                    Punto de partida guardado: <?= $numero($lectura['lectura_inicial']) ?> kWh.
                                    Todavía no hay consumo: ingresa la próxima lectura para calcular el importe.
                                </div>
                            <?php elseif ($calculo !== null): ?>
                                <div>
                                    <span class="text-muted small">Consumido hasta hoy: <?= $numero($consumo) ?> kWh · Importe a la fecha:</span>
                                    <span class="fs-5 fw-bold">$ <?= number_format($calculo['importe'], 2, ',', '.') ?></span>
                                </div>
                                <div class="text-muted small">
                                    Es lo que costaría lo consumido hasta ahora (incluye el cargo fijo completo y los impuestos).
                                    No es una proyección del total del ciclo.
                                </div>
                                <?php if ($moraInfo['mora'] > 0): ?>
                                    <div class="text-muted small">
                                        Incluye $ <?= number_format($moraInfo['mora'], 2, ',', '.') ?> de mora
                                        (más IVA de $ <?= number_format($calculo['iva_mora'], 2, ',', '.') ?>)
                                        <?= htmlspecialchars((string)$moraInfo['detalle']) ?>.
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($sum['tipo_tarifa_id'] === null): ?>
                                <div class="text-warning small">
                                    Elige la tarifa y el subsidio de este suministro (tarjeta de arriba) para calcular el importe.
                                </div>
                            <?php else: ?>
                                <div class="text-warning small">
                                    No se pudo calcular: faltan los cuadros tarifarios de
                                    <?= htmlspecialchars($mesLegible((string)$lectura['periodo'])) ?> o de
                                    <?= htmlspecialchars($mesDeEmision((string)$lectura['periodo'])) ?>.
                                </div>
                            <?php endif; ?>
                            <div class="text-muted small">
                                Periodo <?= htmlspecialchars($mesLegible((string)$lectura['periodo'])) ?>
                                (cuadros de <?= htmlspecialchars($mesLegible((string)$lectura['periodo'])) ?> y <?= htmlspecialchars($mesDeEmision((string)$lectura['periodo'])) ?>):
                                punto de partida <?= $numero($lectura['lectura_inicial']) ?> (<?= htmlspecialchars($origenTexto) ?>),
                                lectura actual <?= $numero($lectura['lectura_actual']) ?>
                                · actualizada el <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$lectura['actualizada_en']))) ?>.
                                <?php if (!$esDelPeriodoActual): ?>
                                    Ese periodo ya tiene factura: ingresa la lectura del periodo nuevo.
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
