<?php
declare(strict_types=1);

/**
 * Representa la lógica de usuarios.
 * Por ahora sólo necesita autenticar (validar email + contraseña).
 */
final class Usuario
{
    private const NOMBRE_MAX   = 100;  // varchar(100) en la tabla
    private const EMAIL_MAX    = 150;  // varchar(150) en la tabla
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 72;   // bcrypt ignora todo lo que pase de 72 bytes
    // Recibe la conexión PDO por el constructor (inyección de dependencia).
    public function __construct(private PDO $pdo) {}

    /**
     * Busca el usuario por email y verifica la contraseña.
     *
     * @return array|null Datos del usuario (sin la contraseña) si es válido, o null.
     */
    public function autenticar(string $email, string $password): ?array
    {
        $sql = 'SELECT id, nombre, email, password
                FROM usuarios
                WHERE email = :email
                LIMIT 1';

        $consulta = $this->pdo->prepare($sql);
        $consulta->execute([':email' => $email]);
        $usuario = $consulta->fetch();

        // password_verify compara la clave escrita contra el hash guardado.
        if ($usuario && password_verify($password, $usuario['password'])) {
            unset($usuario['password']); // no llevamos el hash a la sesión
            return $usuario;
        }

        return null;
    }

    public function registrar(string $nombre, string $email, string $password, string $confirmacion): array
    {
        $nombre = trim($nombre);
        // Se guarda el email en minúsculas para que "Ana@x.com" y "ana@x.com" sean la misma cuenta.
        $email  = mb_strtolower(trim($email));
        $errores = $this->validarRegistro($nombre, $email, $password, $confirmacion);

        // Chequeo amable de email repetido; la garantía real es el UNIQUE de la tabla.
        if (!isset($errores['email']) && $this->emailExiste($email)) {
            $errores['email'] = 'Ya existe una cuenta con ese email.';
        }

        if ($errores !== []) {
            return ['usuario' => null, 'errores' => $errores];
        }

        try {
            $consulta = $this->pdo->prepare(
                'INSERT INTO usuarios (nombre, email, password)
                 VALUES (:nombre, :email, :password)'
            );
            $consulta->execute([
                ':nombre'   => $nombre,
                ':email'    => $email,
                // password_hash: genera un hash con sal aleatoria (hoy bcrypt). La contraseña nunca se guarda en claro
                // y no se puede "des-hashear"; el login la compara con password_verify (ver autenticar()).
                ':password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $e) {
            // 1062 = clave duplicada (dos envíos simultáneos con el mismo email).
            if (($e->errorInfo[1] ?? null) === 1062) {
                return ['usuario' => null, 'errores' => ['email' => 'Ya existe una cuenta con ese email.']];
            }
            throw $e;
        }

        return [
            'usuario' => [
                'id'     => (int)$this->pdo->lastInsertId(),
                'nombre' => $nombre,
                'email'  => $email,
            ],
            'errores' => [],
        ];
    }

    private function validarRegistro(string $nombre, string $email, string $password, string $confirmacion): array
    {
        $errores = [];

        $largoNombre = mb_strlen($nombre);
        if ($largoNombre < 2 || $largoNombre > self::NOMBRE_MAX) {
            $errores['nombre'] = 'El nombre debe tener entre 2 y ' . self::NOMBRE_MAX . ' caracteres.';
        }

        if ($email === '' || mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores['email'] = 'Ingresá un email válido.';
        }

        // strlen cuenta BYTES, a propósito: el límite de bcrypt es de 72 bytes, no de caracteres.
        // (Para nombre y email sí se usa mb_strlen, que cuenta caracteres, tildes incluidas.)
        if (strlen($password) < self::PASSWORD_MIN) {
            $errores['password'] = 'La contraseña debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.';
        } elseif (strlen($password) > self::PASSWORD_MAX) {
            $errores['password'] = 'La contraseña no puede superar los ' . self::PASSWORD_MAX . ' bytes.';
        }

        if (!isset($errores['password']) && $password !== $confirmacion) {
            $errores['confirmacion'] = 'Las contraseñas no coinciden.';
        }

        return $errores;
    }

    private function emailExiste(string $email): bool
    {
        $consulta = $this->pdo->prepare('SELECT 1 FROM usuarios WHERE email = :email LIMIT 1');
        $consulta->execute([':email' => $email]);
        return $consulta->fetchColumn() !== false;
    }
}
