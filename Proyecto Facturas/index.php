<?php
require_once 'models/Sesion.php';

// Punto de entrada del sistema: no muestra nada, solo decide a dónde mandar al visitante
// según tenga o no una sesión iniciada.
if (Sesion::autenticado()){
    header('Location: views/dashboard.php');
}else{
    header('Location: views/login.php');
}
// exit corta la ejecución: sin él PHP seguiría corriendo después de enviar la redirección.
exit;