<?php
declare(strict_types=1);

require_once '../config/conexion.php';
require_once '../models/Usuario.php';
require_once '../models/Sesion.php';

// Mini-enrutador: un solo archivo atiende varias acciones y se elige con la URL
// (AuthController.php?accion=login | logout | registro).
// Patrón de todo el controlador: validar -> llamar al modelo -> redirigir (nunca imprime HTML).
$accion = $_GET['accion'] ?? '';

// Login y registro solo aceptan POST: una URL escrita a mano (GET) no puede iniciar sesión.
if ($accion === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === ''){
        header('Location: ../views/login.php?error=vacio');
        exit;
    }

    $usuarioModel = new Usuario(Conexion::obtener());
    $datos = $usuarioModel->autenticar($email, $password);

    if ($datos !== null){
        Sesion::login($datos);
        header('Location: ../views/dashboard.php');
        exit;
    }else{
        header('Location: ../views/login.php?error=credenciales');
        exit;
    }
}

if ($accion === 'logout'){
    Sesion::cerrar();
    header('Location: ../views/login.php');
    exit;
}

if ($accion === 'registro' && $_SERVER['REQUEST_METHOD'] === 'POST'){
    $nombre       = $_POST['nombre'] ?? '';
    $email        = $_POST['email'] ?? '';
    $password     = $_POST['password'] ?? '';
    $confirmacion = $_POST['confirmacion'] ?? '';

    $usuarioModel = new Usuario(Conexion::obtener());
    $resultado = $usuarioModel->registrar($nombre, $email, $password, $confirmacion);

    if ($resultado['usuario'] !== null){
        // Cuenta creada: se inicia sesión directamente.
        Sesion::login($resultado['usuario']);
        header('Location: ../views/dashboard.php');
        exit;
    }

    // Patrón "flash": tras redirigir, registro.php lee estos datos una sola vez y los borra (unset).
    // Con errores: se guardan en la sesión (flash) para no exponer datos en la URL.
    // Nunca se devuelven las contraseñas al formulario.
    Sesion::iniciar();
    $_SESSION['registro_errores'] = $resultado['errores'];
    $_SESSION['registro_viejo']   = ['nombre' => trim($nombre), 'email' => trim($email)];
    header('Location: ../views/registro.php');
    exit;
}