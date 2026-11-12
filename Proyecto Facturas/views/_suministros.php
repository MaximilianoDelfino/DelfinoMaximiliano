<?php
declare(strict_types=1);

// Parcial de vista: tarjeta "Mis suministros". Se incluye desde dashboard.php,
// que ya definió $db y $usuario_id. Abierto directamente por URL no muestra nada.
if (!isset($db, $usuario_id)) {
    exit;
}

require_once __DIR__ . '/../models/Suministro.php';

$suministroModel = new Suministro($db);
$suministros = $suministroModel->obtenerPorUsuario($usuario_id);
$tarifasPorGrupo = $suministroModel->tarifasPorGrupo();
$niveles = $suministroModel->nivelesElegibles();

// Nombre con el que se muestra cada grupo de tarifas en el desplegable
$etiquetasGrupo = [
    'T1' => 'Tarifa 1 (pequeñas demandas)',
    'ELECTRODEP' => 'Electrodependientes',
];
?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 card-title">Mis suministros</h2>
        <p class="text-muted small mb-3">
            Elige la tarifa y el subsidio de cada NIS para que el sistema pueda estimar los kWh de tus facturas.
            Ambos datos figuran en tu factura. Si tu categoría no distingue subsidios, el segundo desplegable no se usa.
        </p>

        <?php if (empty($suministros)): ?>
            <p class="text-muted mb-0">
                Todavía no tienes suministros. Aparecen solos cuando sincronizas o cargas una factura con NIS.
            </p>
        <?php else: ?>
            <?php foreach ($suministros as $sum): ?>
                <form action="../controllers/FacturaController.php?accion=guardar_suministro" method="POST"
                      class="row g-2 align-items-end mb-2">
                    <input type="hidden" name="suministro_id" value="<?= (int)$sum['id'] ?>">

                    <div class="col-md-2">
                        <div class="small text-muted">NIS</div>
                        <div class="fw-semibold"><?= htmlspecialchars($sum['nis']) ?></div>
                    </div>

                    <div class="col-md-5">
                        <label for="tarifa_<?= (int)$sum['id'] ?>" class="form-label small mb-1">Tipo de tarifa</label>
                        <select id="tarifa_<?= (int)$sum['id'] ?>" name="tipo_tarifa_id" class="form-select">
                            <option value="">Sin elegir</option>
                            <?php /*
                             * <optgroup> agrupa las tarifas por grupo (T1, ELECTRODEP) dentro del desplegable.
                             */ ?>
                            <?php foreach ($tarifasPorGrupo as $grupo => $tarifas): ?>
                                <optgroup label="<?= htmlspecialchars($etiquetasGrupo[$grupo] ?? (string)$grupo) ?>">
                                    <?php foreach ($tarifas as $tarifa): ?>
                                        <?php /*
                                         * Se marca "selected" la opción que el suministro ya tiene guardada.
                                         */ ?>
                                        <option value="<?= (int)$tarifa['id'] ?>"
                                            <?= (int)($sum['tipo_tarifa_id'] ?? 0) === (int)$tarifa['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tarifa['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3">
                        <label for="subsidio_<?= (int)$sum['id'] ?>" class="form-label small mb-1">Subsidio</label>
                        <select id="subsidio_<?= (int)$sum['id'] ?>" name="nivel_subsidio_id" class="form-select">
                            <option value="">Sin elegir</option>
                            <?php foreach ($niveles as $nivel): ?>
                                <option value="<?= (int)$nivel['id'] ?>"
                                    <?= (int)($sum['nivel_subsidio_id'] ?? 0) === (int)$nivel['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nivel['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-success w-100">Guardar</button>
                    </div>
                </form>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
