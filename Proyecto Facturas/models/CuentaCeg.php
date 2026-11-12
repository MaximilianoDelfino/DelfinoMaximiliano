<?php
declare(strict_types=1);

/** Números de usuario de la CEG que cada persona vinculó a su cuenta, y si autoriza la sincronización automática. */
final class CuentaCeg
{
    public function __construct(private PDO $db) {}

    public function guardar(int $usuario_id, string $numero, bool $automatica): bool
    {
        try {
            $stmt = $this->db->prepare(
                // Upsert: la tabla tiene clave única (usuario, número de usuario CEG). Si esa cuenta ya estaba vinculada,
                // el INSERT no duplica y solo se actualiza auto_sync (el consentimiento de sincronización diaria).
                "INSERT INTO cuentas_ceg (usuario_id, numero_usuario_ceg, auto_sync)
                 VALUES (:uid, :num, :auto)
                 ON DUPLICATE KEY UPDATE auto_sync = VALUES(auto_sync)"
            );
            return $stmt->execute([':uid' => $usuario_id, ':num' => $numero, ':auto' => $automatica ? 1 : 0]);
        } catch (PDOException $e) {
            error_log('Error al guardar la cuenta CEG: ' . $e->getMessage());
            return false;
        }
    }

    /** Cuentas con la sincronización automática activada. */
    public function automaticas(): array
    {
        return $this->db
            ->query("SELECT id, usuario_id, numero_usuario_ceg FROM cuentas_ceg WHERE auto_sync = 1 ORDER BY id")
            ->fetchAll();
    }

    public function registrarResultado(int $id, string $resultado): void
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE cuentas_ceg SET ultima_sincronizacion = NOW(), ultimo_resultado = :r WHERE id = :id"
            );
            // La columna ultimo_resultado es varchar(40): se recorta para que un mensaje largo no rompa el UPDATE.
            $stmt->execute([':r' => substr($resultado, 0, 40), ':id' => $id]);
        } catch (PDOException $e) {
            error_log('Error al registrar el resultado de la sincronización: ' . $e->getMessage());
        }
    }
}