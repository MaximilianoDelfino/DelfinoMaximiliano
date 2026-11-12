<?php
declare(strict_types=1);

// Tarea programada: sincroniza con la CEG las cuentas que activaron la sincronización automática
// y lee el PDF de las facturas nuevas. Solo se ejecuta desde la consola.
//
//   Windows: C:\php\php.exe "C:\...\Proyecto Facturas\cron\sincronizar.php"
//   Linux:   0 8 * * * /usr/bin/php /ruta/al/proyecto/cron/sincronizar.php

// PHP_SAPI = cómo se está ejecutando PHP. Solo se permite desde la consola ("cli"), que es como lo lanza
// el cron / Programador de tareas. Si alguien lo pide por navegador responde 404, como si no existiera.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('America/Argentina/Buenos_Aires');

$raiz    = dirname(__DIR__);
$dirLogs = $raiz . '/logs';
if (!is_dir($dirLogs)) {
    mkdir($dirLogs, 0775, true);
}
// Los errores de PHP se anotan en logs/php_errors.log en vez de mostrarse (no hay nadie mirando una pantalla).
ini_set('log_errors', '1');
ini_set('error_log', $dirLogs . '/php_errors.log');

require_once $raiz . '/config/conexion.php';
require_once $raiz . '/models/Factura.php';
require_once $raiz . '/models/CuentaCeg.php';
require_once $raiz . '/models/SincronizadorCEG.php';
require_once $raiz . '/models/LectorFacturaPdf.php';

const MAX_PDFS_POR_CUENTA = 12;     // por ejecución
const PAUSA_US            = 500000; // 0,5 s entre pedidos: no saturar a la CEG

// Escribe cada mensaje con fecha y hora en logs/cron.log y también lo muestra por consola.
// FILE_APPEND agrega al final; LOCK_EX evita que dos escrituras simultáneas se mezclen.
// "global $dirLogs" usa la variable definida arriba (dentro de una función no se ve sola).
function registrar_log(string $mensaje): void
{
    global $dirLogs;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $mensaje . PHP_EOL;
    file_put_contents($dirLogs . '/cron.log', $linea, FILE_APPEND | LOCK_EX);
    echo $linea;
}

// Una sola ejecución a la vez.
// flock con LOCK_NB intenta tomar un "candado" sobre cron.lock SIN esperar. Si otra ejecución lo tiene
// (una corrida anterior que se alargó), esta termina. El sistema operativo libera el candado solo cuando
// el proceso termina, incluso si falla.
$candado = fopen($dirLogs . '/cron.lock', 'c');
if ($candado === false || !flock($candado, LOCK_EX | LOCK_NB)) {
    registrar_log('Ya hay otra ejecución en curso: se cancela esta.');
    exit(0);
}

$db            = Conexion::obtener();
$facturaModel  = new Factura($db);
$cuentaModel   = new CuentaCeg($db);
$sincronizador = new SincronizadorCEG();
$lector        = new LectorFacturaPdf();

$cuentas = $cuentaModel->automaticas();
registrar_log('Inicio: ' . count($cuentas) . ' cuenta(s) con sincronización automática.');
$huboErrores = false;

// Cada cuenta se procesa por separado y dentro de un try/catch: el error de una no frena a las demás.
foreach ($cuentas as $cuenta) {
    $cuentaId = (int)$cuenta['id'];
    $uid      = (int)$cuenta['usuario_id'];
    // En el log solo van identificadores internos, no el número de usuario de la CEG.
    $etiqueta = 'cuenta ' . $cuentaId . ' (usuario ' . $uid . ')';

    try {
        // 1) Listado de facturas
        $facturas = $sincronizador->consultarDeudaAPI((string)$cuenta['numero_usuario_ceg'], $uid);

        // Pagos: facturas de esta cuenta que ya no figuran como pendientes en la CEG.
        $pagos = $facturaModel->reconciliarPagos($uid, (string)$cuenta['numero_usuario_ceg'], array_column($facturas, 'comprobante'));
        if ($pagos['marcadas'] > 0 || $pagos['confirmadas'] > 0) {
            registrar_log($etiqueta . ": pagos detectados: {$pagos['confirmadas']} confirmado(s), {$pagos['marcadas']} en observación.");
        }
        
        $guardadas = 0;
        foreach ($facturas as $f) {
            if ($facturaModel->registrar($f)) {
                $guardadas++;
            }
        }

        // 2) PDF de las facturas que todavía no se leyeron
        $pdf = $lector->leerPendientes($facturaModel, $uid, MAX_PDFS_POR_CUENTA, function (array $factura, string $error) use ($etiqueta) {
            registrar_log($etiqueta . ': factura id ' . $factura['id'] . ' sin leer (' . $error . ')');
        });

        $cuentaModel->registrarResultado($cuentaId, $pdf['fallidas'] === 0 ? 'ok' : 'ok_con_pdf_fallidos');
        registrar_log($etiqueta . ": {$guardadas} factura(s) del listado, PDF leídos {$pdf['leidas']}, con error {$pdf['fallidas']}"
            . ($pdf['quedan'] ? ', quedan PDF por leer' : '') . '.');
    // Dos niveles de captura: RuntimeException = errores esperados de la CEG (caída, formato inesperado);
    // Throwable = cualquier otro error del programa, que se registra como 'error_interno'.
    } catch (RuntimeException $e) {
        $huboErrores = true;
        $cuentaModel->registrarResultado($cuentaId, $e->getMessage());
        registrar_log($etiqueta . ': error de la CEG (' . $e->getMessage() . ')');
    } catch (Throwable $e) {
        $huboErrores = true;
        $cuentaModel->registrarResultado($cuentaId, 'error_interno');
        registrar_log($etiqueta . ': error interno: ' . $e->getMessage());
    }

    usleep(PAUSA_US);
}

registrar_log('Fin.');
// Código de salida: 0 = todo bien, 1 = hubo errores. El programador de tareas puede usarlo para avisar.
exit($huboErrores ? 1 : 0);