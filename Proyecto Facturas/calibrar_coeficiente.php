<?php
declare(strict_types=1);

// Herramienta de calibración (guardar en la raíz del proyecto, junto a index.php).
//
// Para cada factura que YA tiene los kWh reales cargados, compara el importe real
// con el que daría el cuadro tarifario para ese consumo. El cociente es el
// "coeficiente adicional" (impuestos y otros conceptos). Con un valor estable se
// actualiza el suministro:   UPDATE suministros SET coef_adicional = 1.2345 WHERE id = ...;
//
// Abrir en el navegador (con la sesión iniciada): http://localhost:8000/calibrar_coeficiente.php
// Solo funciona con facturas cuyo suministro ya tiene tarifa asignada y cuyo cuadro
// tarifario cubre la fecha de la factura.

require_once 'config/conexion.php';
require_once 'models/Sesion.php';
require_once 'models/EstimadorKwh.php';

Sesion::proteger('views/login.php');

$db = Conexion::obtener();
$usuario_id = (int)Sesion::usuario()['id'];
$estimador = new EstimadorKwh($db);

$stmt = $db->prepare(
    "SELECT id, suministro_id, comprobante, periodo, monto_vencimiento_1, kwh_consumidos
     FROM facturas
     WHERE usuario_id = :uid AND kwh_consumidos IS NOT NULL
     ORDER BY fecha_vencimiento_1"
);
$stmt->execute([':uid' => $usuario_id]);

// Salida en texto plano (sin HTML): es una herramienta de uso interno, para leer una tabla en el navegador.
header('Content-Type: text/plain; charset=utf-8');
echo "suministro | comprobante | periodo | kWh reales | importe | neto del cuadro | coeficiente implicito\n";

foreach ($stmt->fetchAll() as $f) {
    $neto = $estimador->netoPorConsumo((int)$f['id'], $usuario_id, (int)$f['kwh_consumidos']);

    if ($neto === null || $neto <= 0) {
        echo "{$f['suministro_id']} | {$f['comprobante']} | {$f['periodo']} | {$f['kwh_consumidos']} | "
           . "sin tarifa asignada, sin cuadro vigente para esa fecha o tarifa no estimable\n";
        continue;
    }

    $importe = (float)$f['monto_vencimiento_1'];
    printf(
        "%s | %s | %s | %d | %.2f | %.2f | %.4f\n",
        (string)$f['suministro_id'],
        $f['comprobante'],
        $f['periodo'],
        (int)$f['kwh_consumidos'],
        $importe,
        $neto,
        // coeficiente implícito = importe real de la factura / importe "neto" que daría el cuadro para esos kWh.
        // Cuanto más estable sea entre facturas, mejor representa a impuestos y otros conceptos.
        $importe / $neto
    );
}
