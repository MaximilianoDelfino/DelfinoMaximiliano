<?php
declare(strict_types=1);

/**
 * Clase de conexión a la base de datos usando PDO.
 *
 * Devuelve SIEMPRE la misma conexión (patrón singleton simple),
 * así no abrimos una conexión nueva en cada consulta.
 *
 * Para pasar a PostgreSQL: cambiar el DSN de 'mysql:' a 'pgsql:'
 * y el puerto por defecto a 5432. El resto del código no cambia.
 */
final class Conexion
{
    // ===== EDITÁ ESTOS DATOS SEGÚN TU ENTORNO =====
    private const HOST    = '127.0.0.1';
    private const PUERTO  = '3306';
    private const BASE    = 'sistemafacturas';
    private const USUARIO = 'root';
    private const CLAVE   = 'root';
    private const CHARSET = 'utf8mb4';
    // ==============================================

    private static ?PDO $pdo = null;

    // Constructor privado: nadie puede hacer "new Conexion()".
    private function __construct() {}

    /**
     * Devuelve la conexión PDO (la crea la primera vez que se llama).
     */
    public static function obtener(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                self::HOST,
                self::PUERTO,
                self::BASE,
                self::CHARSET
            );

            $opciones = [
                // Lanza excepciones ante errores (mejor para aprender a depurar).
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Los resultados vienen como arrays asociativos.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Consultas preparadas reales (no emuladas).
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pdo = new PDO($dsn, self::USUARIO, self::CLAVE, $opciones);
            } catch (PDOException $e) {
                // En un proyecto real NO se muestra el mensaje al usuario.
                // Acá lo dejamos visible para que puedan corregir la config.
                exit('Error de conexión: ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}
