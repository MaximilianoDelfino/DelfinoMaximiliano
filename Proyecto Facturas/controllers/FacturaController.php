<?php
declare(strict_types=1);

// Controlador de facturas. Igual que AuthController: se elige la acción con ?accion=..., se valida lo
// recibido, se llama al modelo y se redirige al dashboard con ?exito=, ?error= o ?info= + una clave
// (el dashboard traduce esa clave a un mensaje; ver $mensajes en dashboard.php).
require_once '../config/conexion.php';
require_once '../models/Sesion.php';
require_once '../models/Factura.php';
require_once '../models/SincronizadorCEG.php';
require_once '../models/Suministro.php';
require_once '../models/LecturaMedidor.php';
require_once '../models/LectorFacturaPdf.php';
require_once '../models/CuentaCeg.php';

Sesion::proteger();

$accion = $_GET['accion'] ?? '';

$db = Conexion::obtener();

$facturaModel = new Factura($db);
$lecturaModel = new LecturaMedidor($db);
$suministroModel = new Suministro($db);
$lectorPdf = new LectorFacturaPdf();
$cuentaCegModel = new CuentaCeg($db);

// El id del usuario sale SIEMPRE de la sesión y nunca de un campo del formulario: si saliera del
// formulario, cualquiera podría cambiarlo y tocar datos ajenos.
$usuario_id_local = (int)Sesion::usuario()['id'];

if ($accion === 'carga_manual' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $datos = [
        'usuario_id' => $usuario_id_local,

        // "a ?: b" (operador Elvis): si lo de la izquierda es vacío ('') se usa null, para guardar NULL en la base.
        'comprobante' => trim($_POST['comprobante'] ?? '') ?: null,

        'periodo' => $_POST['periodo'] ?: null,

        'nis' => $_POST['nis'] ?: null,

        // OJO: empty("0") es true en PHP, así que cargar 0 kWh en este formulario se guarda como "sin dato" (null).
        'kwh_consumidos' => !empty($_POST['kwh_consumidos']) ? (int)$_POST['kwh_consumidos'] : null,

        'monto_vencimiento_1' => trim($_POST['monto_vencimiento_1']),
        'monto_vencimiento_2' => !empty(trim($_POST['monto_vencimiento_2'])) ? trim($_POST['monto_vencimiento_2']) : null,
        
        'fecha_vencimiento_1' => trim($_POST['fecha_vencimiento_1']),
        'fecha_vencimiento_2' => $_POST['fecha_vencimiento_2'] ?: null,
    ];

    if ($datos['comprobante'] === null){
        header('Location: ../views/dashboard.php?error=faltan_datos');
        exit;
    }

    if ($facturaModel->registrar($datos)){
        header('Location: ../views/dashboard.php?exito=manual');
    }else{
        header('Location: ../views/dashboard.php?error=bd');
    }
    exit;
}

// El sincronizador lanza RuntimeException ('api_caida', 'api_respuesta_invalida'); se captura abajo y se
// convierte en ?error=<código>, que el dashboard muestra como mensaje.
try{
    if ($accion === 'sincronizar_ceg' && $_SERVER['REQUEST_METHOD'] === 'POST'){
        $usuario_ceg = trim($_POST['numero_usuario_ceg'] ?? '');
        if ($usuario_ceg === ''){
            header('Location: ../views/dashboard.php?error=faltan_datos');
            exit;
        }

        // El número de usuario de la CEG son solo dígitos: se rechaza cualquier otra cosa antes de armar la URL de la API.
        if (!ctype_digit($usuario_ceg)){
            header('Location: ../views/dashboard.php?error=datos_invalidos');
            exit;
        }

        // Descargar varios PDF puede tardar más que el límite normal de PHP (30 s): se amplía a 3 minutos.
        set_time_limit(180);

        $sincronizador = new SincronizadorCEG();

        $facturasPendientes = $sincronizador->consultarDeudaAPI($usuario_ceg,$usuario_id_local);

        // Se recuerda el número de usuario de la CEG y si la persona autoriza la sincronización diaria.
        $cuentaCegModel->guardar($usuario_id_local, $usuario_ceg, isset($_POST['auto_sync']));

        // Pagos: las facturas de esta cuenta que ya no figuran como pendientes en la CEG.
        $facturaModel->reconciliarPagos($usuario_id_local, $usuario_ceg, array_column($facturasPendientes, 'comprobante'));
        
        if (empty($facturasPendientes)){
            header('Location: ../views/dashboard.php?info=sin_resultados');
            exit;
        }

        $errores = false;

        foreach($facturasPendientes as $recibe){
            $guardar = $facturaModel->registrar($recibe);
            
            if(!$guardar){$errores = true;}
        }

        // Con las facturas ya guardadas, se leen los PDF de las que todavía no tienen sus datos reales.
        // Desde el panel se leen pocas por vez para no dejar la página esperando; el resto queda para
        // la próxima sincronización o para la tarea programada.
        $pdf = $lectorPdf->leerPendientes($facturaModel, $usuario_id_local, 6);

        if ($errores){
            header('Location: ../views/dashboard.php?error=sincronizacion_parcial');
        }elseif ($pdf['fallidas'] > 0 || $pdf['quedan']){
            header('Location: ../views/dashboard.php?info=sincronizado_pdf_pendiente');
        }else{
            header('Location: ../views/dashboard.php?exito=sincronizado');
        }
    }
}catch(RuntimeException $e){
    header('Location: ../views/dashboard.php?error=' . urlencode($e->getMessage()));
    exit;
}

/** Entrega el PDF de una factura propia (para verlo dentro de ver_factura.php o descargarlo). */
if ($accion === 'ver_pdf' && $_SERVER['REQUEST_METHOD'] === 'GET'){
    $factura_id = (int)($_GET['factura_id'] ?? 0);
    $factura = $factura_id > 0 ? $facturaModel->obtenerUna($factura_id, $usuario_id_local) : null;
    $nombre = $factura !== null ? $lectorPdf->nombreArchivo((string)$factura['comprobante']) : null;

    if ($nombre === null){
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Factura no encontrada.';
        exit;
    }

    try{
        $pdf = $lectorPdf->descargar($nombre);
    }catch (RuntimeException $e){
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No se pudo obtener el PDF de la CEG. Intenta de nuevo más tarde.';
        exit;
    }

    $disposicion = isset($_GET['descargar']) ? 'attachment' : 'inline';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');           // solo se puede incrustar en páginas del mismo sitio
    header('Cache-Control: private, max-age=300');   // el PDF trae datos personales: nada de cachés compartidas
    echo $pdf;
    exit;
}

if ($accion === 'cargar_kwh' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $factura_id = (int)($_POST['factura_id'] ?? 0);
    // filter_var(..., FILTER_VALIDATE_INT) devuelve el entero, o false si no es un entero válido. Por eso se
    // compara con !== false (el 0 es un valor válido y "0 == false" sería engañoso).
    $kwh = filter_var($_POST['kwh'] ?? '', FILTER_VALIDATE_INT);

    if ($factura_id > 0 && $kwh !== false && $kwh >= 0){
        $actualizado = $facturaModel->actualizarConsumo($factura_id, $kwh, $usuario_id_local);

        if ($actualizado){
            header('Location: ../views/dashboard.php?exito=actualizacion_exitosa');
        }else{
            header('Location: ../views/dashboard.php?error=actualizacion_fallida');
        }
    }else{
       header('Location: ../views/dashboard.php?error=datos_invalidos');
   }
    exit;
}

if ($accion === 'cambiar_estado' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $factura_id = (int)($_POST['factura_id'] ?? 0);
    $estado = $_POST['estado'] ?? '';

    if ($factura_id > 0 && in_array($estado, ['pendiente','paga'], true)){
        if ($facturaModel->cambiarEstado($factura_id, $estado,$usuario_id_local)){
            header('Location: ../views/dashboard.php?exito=estado_actualizado');    
            exit;
        }else{
            header('Location: ../views/dashboard.php?error=estado_fallido');    
            exit;
        }
    }else{
        header('Location: ../views/dashboard.php?error=datos_invalidos');    
        exit;
    }
}

if ($accion === 'cambiar_fecha_pago' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $factura_id = (int)($_POST['factura_id'] ?? 0);
    $fecha = trim((string)($_POST['fecha_pago'] ?? ''));

    // Validación estricta de la fecha: el "!" pone la hora en cero y getLastErrors() detecta fechas imposibles
    // (p. ej. 2026-02-31), que PHP corregiría en silencio. Además no se aceptan fechas futuras (<= hoy).
    $dt = DateTime::createFromFormat('!Y-m-d', $fecha);
    $errores = DateTime::getLastErrors();
    $fecha_valida = $dt !== false && ($errores === false || $errores['warning_count'] === 0 && $errores['error_count'] === 0) && $fecha <= date('Y-m-d');

    if($factura_id > 0 && $fecha_valida){
        if ($facturaModel->cambiarFechaPago($factura_id, $fecha, $usuario_id_local)){
            header('Location: ../views/dashboard.php?exito=fecha_actualizada');
        }else{
            header('Location: ../views/dashboard.php?error=fecha_fallida');
        }
    }else{
        header('Location: ../views/dashboard.php?error=fecha_invalida');
    }
    exit;
}

if ($accion === 'guardar_lectura' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $suministro_id = (int)($_POST['suministro_id'] ?? 0);
    $lectura = filter_var($_POST['lectura'] ?? '', FILTER_VALIDATE_INT);
    $periodo = LecturaMedidor::normalizarPeriodo((string)($_POST['periodo'] ?? ''));
    $nuevoPunto = isset($_POST['nuevo_punto']);

    if ($suministro_id > 0 && $lectura !== false && $lectura >= 0 && $periodo !== null){
        $resultado = $lecturaModel->guardar($usuario_id_local, $suministro_id, $periodo, $lectura, $nuevoPunto);

        // match es como un switch que devuelve un valor y compara de forma estricta; "default" cubre el resto.
        // El texto resultante se arma como ?exito=... o ?error=... para el dashboard.
        $destino = match ($resultado) {
            'base'  => 'exito=lectura_base',
            'ok'    => 'exito=lectura_guardada',
            'menor' => 'error=lectura_menor',
            default => 'error=lectura_fallida',
        };
        // El ancla lleva a la tarjeta donde se ve el importe calculado
        header('Location: ../views/dashboard.php?' . $destino . '#lectura_' . $suministro_id);
    }else{
        header('Location: ../views/dashboard.php?error=datos_invalidos');
    }
    exit;
}

if ($accion === 'guardar_suministro' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $suministro_id = (int)($_POST['suministro_id'] ?? 0);
    // un desplegable "Sin elegir" llega vacío y se guarda como NULL
    $tipo  = ($_POST['tipo_tarifa_id'] ?? '')    === '' ? null : filter_var($_POST['tipo_tarifa_id'], FILTER_VALIDATE_INT);
    $nivel = ($_POST['nivel_subsidio_id'] ?? '') === '' ? null : filter_var($_POST['nivel_subsidio_id'], FILTER_VALIDATE_INT);

    if ($suministro_id > 0 && $tipo !== false && $nivel !== false){
        if ($suministroModel->actualizarTarifa($suministro_id, $usuario_id_local, $tipo, $nivel)){
            header('Location: ../views/dashboard.php?exito=suministro_guardado');
        }else{
            header('Location: ../views/dashboard.php?error=suministro_fallido');
        }
    }else{
        header('Location: ../views/dashboard.php?error=datos_invalidos');
    }
    exit;
}
// Si ninguna acción coincidió, se vuelve al panel.
header('Location: ../views/dashboard.php');
exit;