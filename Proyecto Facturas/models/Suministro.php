<?php
declare(strict_types=1);

/**
 * Suministros del usuario (cada NIS) y la tarifa que tiene asignada cada uno.
 */
final class Suministro
{
    public function __construct(private PDO $db) {}

    /** Suministros del usuario, con la tarifa y el subsidio que tengan elegidos. */
    public function obtenerPorUsuario(int $usuario_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, nis, tipo_tarifa_id, nivel_subsidio_id
             FROM suministros
             WHERE usuario_id = :uid
             ORDER BY nis"
        );
        $stmt->execute([':uid' => $usuario_id]);
        return $stmt->fetchAll();
    }

    /** Categorías de tarifa agrupadas por grupo (T1, ELECTRODEP...), para armar el desplegable. */
    public function tarifasPorGrupo(): array
    {
        $filas = $this->db->query("SELECT id, grupo, nombre FROM tipos_tarifa ORDER BY id")->fetchAll();

        $grupos = [];
        foreach ($filas as $fila) {
            $grupos[$fila['grupo']][] = $fila;
        }
        return $grupos;
    }

    /** Niveles de subsidio que el usuario puede elegir (NO_APLICA es interno y no se ofrece). */
    public function nivelesElegibles(): array
    {
        return $this->db
            ->query("SELECT id, nombre FROM niveles_subsidio WHERE codigo <> 'NO_APLICA' ORDER BY id")
            ->fetchAll();
    }

    /**
     * Guarda la tarifa y el subsidio de un suministro. null = sin elegir.
     * El UPDATE incluye usuario_id: nadie puede modificar suministros ajenos.
     * Si el id de tarifa o de subsidio no existe, la clave foránea lo rechaza.
     */
    public function actualizarTarifa(int $id, int $usuario_id, ?int $tipo_tarifa_id, ?int $nivel_subsidio_id): bool
    {
        try {
            $stmt = $this->db->prepare(
                "UPDATE suministros
                 SET tipo_tarifa_id = :tipo, nivel_subsidio_id = :nivel
                 WHERE id = :id AND usuario_id = :uid"
            );
            // Con bindValue se indica el tipo de dato: si el valor es null hay que usar PARAM_NULL para que llegue
            // como NULL de SQL ("sin elegir") y no como 0 o como texto vacío.
            $stmt->bindValue(':tipo', $tipo_tarifa_id, $tipo_tarifa_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':nivel', $nivel_subsidio_id, $nivel_subsidio_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $usuario_id, PDO::PARAM_INT);

            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error al guardar la tarifa del suministro: " . $e->getMessage());
            return false;
        }
    }
}
