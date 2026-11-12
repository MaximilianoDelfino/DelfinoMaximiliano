<?php
declare(strict_types=1);

/**
 * Maneja la sesión del usuario logueado.
 * Todos los métodos son estáticos: se usan como Sesion::metodo().
 */
final class Sesion
{
    /** Arranca la sesión si todavía no está iniciada. */
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** Guarda al usuario en la sesión (se llama después de un login correcto). */
    public static function login(array $usuario): void
    {
        self::iniciar();
        // Cambia el ID de sesión para evitar "fijación de sesión".
        session_regenerate_id(true);
        $_SESSION['usuario'] = $usuario;
    }

    /** ¿Hay un usuario logueado? */
    public static function autenticado(): bool
    {
        self::iniciar();
        return isset($_SESSION['usuario']);
    }

    /** Devuelve los datos del usuario logueado (o null). */
    public static function usuario(): ?array
    {
        self::iniciar();
        return $_SESSION['usuario'] ?? null;
    }

    /**
     * Protege una página: si no hay sesión, redirige al login.
     * Se llama al principio de cada página privada.
     */
    public static function proteger(string $destino = '../views/login.php'): void
    {
        if (!self::autenticado()) {
            header('Location: ' . $destino);
            exit;
        }
    }

    /** Cierra la sesión por completo. */
    public static function cerrar(): void
    {
        self::iniciar();
        $_SESSION = [];
        session_destroy();
    }
}
