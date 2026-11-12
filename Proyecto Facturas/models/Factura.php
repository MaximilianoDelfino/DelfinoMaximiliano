<?php
declare(strict_types=1);

// Modelo de facturas: único lugar con SQL de la tabla facturas. Casi todos los métodos reciben $usuario_id
// y lo ponen en el WHERE: así una persona nunca puede leer ni modificar facturas de otra.
class Factura{
    private PDO $db;

    public function __construct(PDO $conexion)
    {
        $this->db = $conexion;
    }

    public function obtenerPorUsuario(int $usuario_id): array{
        $sql = "SELECT * FROM facturas WHERE usuario_id = :uid ORDER BY fecha_vencimiento_1 DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uid' => $usuario_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function registrar(array $datos): bool{
        $sql =  "INSERT INTO facturas(
                usuario_id, suministro_id, comprobante, periodo, nis, kwh_consumidos, monto_vencimiento_1,
                monto_vencimiento_2, fecha_vencimiento_1, fecha_vencimiento_2, numero_usuario_ceg, visto_pendiente_en
                ) VALUES (
                :usuario_id, :suministro_id, :comprobante, :periodo, :nis, :kwh_consumidos, :monto_vencimiento_1,
                :monto_vencimiento_2, :fecha_vencimiento_1, :fecha_vencimiento_2, :numero_usuario_ceg, :visto)
                // "Upsert": la tabla tiene clave única (usuario_id, comprobante). Si la factura ya existe, no se duplica:
                //  - se refrescan importes y vencimientos con lo que informa la CEG;
                //  - COALESCE(a, b) conserva el valor ya guardado y usa el nuevo solo si el guardado es NULL;
                //  - NO se tocan kwh, estado ni fecha_pago: son datos de la persona o del PDF y una sincronización no debe pisarlos;
                //  - ausente_desde = NULL: la factura volvió a figurar como pendiente, así que se cancela cualquier sospecha de pago.
                ON DUPLICATE KEY UPDATE
                    suministro_id = COALESCE(suministro_id, VALUES(suministro_id)),
                    monto_vencimiento_1 = VALUES(monto_vencimiento_1),
                    monto_vencimiento_2 = VALUES(monto_vencimiento_2),
                    fecha_vencimiento_1 = VALUES(fecha_vencimiento_1),
                    fecha_vencimiento_2 = VALUES(fecha_vencimiento_2),
                    numero_usuario_ceg = COALESCE(VALUES(numero_usuario_ceg), numero_usuario_ceg),
                    visto_pendiente_en = VALUES(visto_pendiente_en),
                    ausente_desde = NULL";
        try {
            // El NIS identifica el suministro (el medidor). Si es la primera vez que aparece, se crea solo.
            $suministro_id = $this->obtenerOCrearSuministro((int)$datos['usuario_id'], $datos['nis'] ?? null);

            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':usuario_id' => $datos['usuario_id'],
                ':suministro_id' => $suministro_id,
                ':comprobante' => $datos['comprobante'] ?? null,
                ':periodo' => $datos['periodo'] ?? null,
                ':nis' => $datos['nis'] ?? null,
                ':kwh_consumidos' => $datos['kwh_consumidos'] ?? null,
                ':monto_vencimiento_1' => $datos['monto_vencimiento_1'],
                ':monto_vencimiento_2' => $datos['monto_vencimiento_2'] ?? null,
                ':fecha_vencimiento_1' => $datos['fecha_vencimiento_1'],
                ':fecha_vencimiento_2' => $datos['fecha_vencimiento_2'] ?? null,
                ':numero_usuario_ceg' => $datos['numero_usuario_ceg'] ?? null,
                ':visto' => (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
                                ->format('Y-m-d H:i:s'),
            ]);
        } catch (PDOException $e) {
            error_log("Error al registrar factura: " . $e->getMessage());
            return false;
        }
    }

    private function obtenerOCrearSuministro(int $usuario_id, ?string $nis): ?int{
        $nis = trim((string)$nis);

        if ($nis === ''){
            return null;
        }

        $stmt = $this->db->prepare("SELECT id FROM suministros WHERE usuario_id = :uid AND nis = :nis");
        $stmt->execute([':uid' => $usuario_id, ':nis' => $nis]);
        $id = $stmt->fetchColumn();

        if ($id !== false){
            return (int)$id;
        }

        $stmt = $this->db->prepare("INSERT INTO suministros (usuario_id, nis) VALUES (:uid, :nis)");
        $stmt->execute([':uid' => $usuario_id, ':nis' => $nis]);
        
        return (int)$this->db->lastInsertId();
    }

    public function actualizarConsumo(int $id, int $kwh, int $usuario_id):bool{
        try{
            $query = "UPDATE facturas SET kwh_consumidos = :kwh WHERE id = :id AND usuario_id = :uid";
            $stmt = $this->db->prepare($query);

            $stmt->bindValue(':kwh', $kwh, PDO::PARAM_INT);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $usuario_id, PDO::PARAM_INT);

            // rowCount() = filas modificadas: da 0 si la factura no existe, no es del usuario o el valor ya era el mismo.
            return $stmt->execute() && $stmt->rowCount() > 0;
        } catch (PDOException $e){
            error_log("Error al actualizar consumo: " . $e->getMessage());
            return false;
        }
    }

    public function cambiarEstado(int $id, string $estado, int $usuario_id): bool{
        if (!in_array($estado, ['pendiente', 'paga'], true)){
            return false;
        }

        // Marcar PAGA: COALESCE conserva la fecha de pago si ya había una; si no, usa la de hoy (CURDATE()).
        // Marcar PENDIENTE: la fecha de pago se borra solo si fue DETECTADA automáticamente (pago_detectado = 1);
        // si la cargó la persona a mano se conserva. MySQL evalúa las asignaciones de izquierda a derecha:
        // por eso fecha_pago (que mira pago_detectado) va ANTES de poner pago_detectado = 0.
        $sql = $estado === 'paga'
                ? "UPDATE facturas SET estado = :estado, fecha_pago = COALESCE(fecha_pago, CURDATE())
                   WHERE id = :id AND usuario_id = :uid"
                : "UPDATE facturas SET estado = :estado, fecha_pago = IF(pago_detectado = 1, NULL, fecha_pago),
                   ausente_desde = NULL, pago_detectado = 0
                   WHERE id = :id AND usuario_id = :uid";
        try{
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':estado', $estado, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $usuario_id, PDO::PARAM_INT);

            return $stmt->execute();
        }catch(PDOException $e){
            error_log("Error al cambiar estado: " . $e->getMessage());
            return false;
        }
    }

    public function cambiarFechaPago(int $id, string $fecha, int $usuario_id): bool{
        try{
            $sql = "UPDATE facturas SET fecha_pago = :fecha, pago_detectado = 0
                    WHERE id = :id AND usuario_id = :uid AND estado = 'paga'";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':fecha', $fecha, PDO::PARAM_STR);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->bindValue(':uid', $usuario_id, PDO::PARAM_INT);

            return $stmt->execute();
        }catch (PDOException $e){
            error_log('Error al actualizar la fecha de pago.' . $e->getMessage());
            return false;
        }
    }

        public function obtenerUna(int $id, int $usuario_id): ?array{
        $stmt = $this->db->prepare("SELECT * FROM facturas WHERE id = :id AND usuario_id = :uid");
        $stmt->execute([':id' => $id, ':uid' => $usuario_id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila === false ? null : $fila;
    }

    /** Guarda los datos reales leídos del PDF (kWh con decimales, lecturas, básico, mora y el detalle). */
    public function guardarDetallePdf(int $id, int $usuario_id, array $d): bool{
        try{
            $stmt = $this->db->prepare(
                "UPDATE facturas
                 SET kwh_consumidos = :kwh, lectura_anterior = :lant, lectura_actual = :lact,
                     dias_lectura = :dias, basico = :basico, mora = :mora,
                     detalle_pdf = :detalle, pdf_leido_en = NOW()
                 WHERE id = :id AND usuario_id = :uid"
            );
            return $stmt->execute([
                ':kwh'     => $d['kwh'],
                ':lant'    => $d['lectura_anterior'],
                ':lact'    => $d['lectura_actual'],
                ':dias'    => $d['dias_lectura'],
                ':basico'  => $d['basico'],
                ':mora'    => $d['mora'],
                // El desglose de conceptos leído del PDF se guarda como JSON en una sola columna (detalle_pdf).
                // JSON_UNESCAPED_UNICODE evita que las tildes se guarden como \u00e1.
                ':detalle' => json_encode($d['conceptos'], JSON_UNESCAPED_UNICODE),
                ':id'      => $id,
                ':uid'     => $usuario_id,
            ]);
        } catch (PDOException $e){
            error_log("Error al guardar detalle del PDF: " . $e->getMessage());
            return false;
        }
    }
    
    /** Cuenta un intento fallido de leer el PDF, para que la tarea programada no insista siempre. */
    public function registrarIntentoPdf(int $id, int $usuario_id): void{
        try{
            $stmt = $this->db->prepare(
                "UPDATE facturas SET pdf_intentos = pdf_intentos + 1 WHERE id = :id AND usuario_id = :uid"
            );
            $stmt->execute([':id' => $id, ':uid' => $usuario_id]);
        } catch (PDOException $e){
            error_log("Error al registrar el intento de lectura del PDF: " . $e->getMessage());
        }
    }

    /**
     * Detecta pagos por desaparición del listado de pendientes de la CEG. Para las facturas de esa cuenta que
     * seguían pendientes y ya no aparecen: la primera vez se anota cuándo dejaron de verse (ausente_desde);
     * si en un chequeo posterior (1 hora o más) siguen ausentes, se confirma el pago con esa fecha.
     * Se piden dos ausencias para no dar por pagas facturas por una respuesta vacía o rota de la API.
     *
     * @param string[] $comprobantesVistos comprobantes que la CEG listó ahora para esa cuenta
     * @return array{marcadas:int, confirmadas:int}
     */
    public function reconciliarPagos(int $usuario_id, string $numero_usuario_ceg, array $comprobantesVistos): array
    {
        $zona = new DateTimeZone('America/Argentina/Buenos_Aires');
        $ahora = new DateTimeImmutable('now', $zona);
        $resultado = ['marcadas' => 0, 'confirmadas' => 0];

        try {
            $sql = "SELECT id, ausente_desde FROM facturas
                    WHERE usuario_id = :uid AND numero_usuario_ceg = :num AND estado = 'pendiente'";
            $params = [':uid' => $usuario_id, ':num' => $numero_usuario_ceg];

            // Se arma "AND comprobante NOT IN (:c0, :c1, ...)" con un marcador por comprobante: así la lista de largo
            // variable sigue usando consultas preparadas (sin pegar valores dentro del SQL).
            // Si la CEG no listó ninguna, no se agrega el NOT IN y todas las pendientes quedan como "ausentes".
            $vistos = array_values(array_unique($comprobantesVistos));
            if ($vistos !== []) {
                $marcas = [];
                foreach ($vistos as $i => $comprobante) {
                    $marcas[] = ':c' . $i;
                    $params[':c' . $i] = $comprobante;
                }
                $sql .= " AND comprobante NOT IN (" . implode(',', $marcas) . ")";
            }

            $ausentes = $this->db->prepare($sql);
            $ausentes->execute($params);

            $observar = $this->db->prepare(
                "UPDATE facturas SET ausente_desde = :ahora WHERE id = :id AND usuario_id = :uid"
            );
            // pago_detectado = 1 marca que la fecha de pago es una ESTIMACIÓN (el día en que la factura dejó de aparecer
            // en la CEG), no una fecha que la persona haya cargado. Solo se activa si no había fecha de pago previa.
            // Las asignaciones se evalúan de izquierda a derecha: pago_detectado mira fecha_pago antes de completarla.
            $confirmar = $this->db->prepare(
                "UPDATE facturas
                 SET pago_detectado = IF(fecha_pago IS NULL, 1, 0),
                     fecha_pago = COALESCE(fecha_pago, DATE(ausente_desde)),
                     estado = 'paga'
                 WHERE id = :id AND usuario_id = :uid"
            );

            foreach ($ausentes->fetchAll(PDO::FETCH_ASSOC) as $factura) {
                if ($factura['ausente_desde'] === null) {
                    $observar->execute([
                        ':ahora' => $ahora->format('Y-m-d H:i:s'),
                        ':id' => $factura['id'],
                        ':uid' => $usuario_id,
                    ]);
                    $resultado['marcadas']++;
                    continue;
                }

                $desde = new DateTimeImmutable((string)$factura['ausente_desde'], $zona);
                // Regla de las dos ausencias: la primera vez solo se anota (ausente_desde); recién si una hora o más después
                // sigue ausente se confirma el pago. Evita dar por pagas facturas por una respuesta vacía o rota de la API.
                if ($desde->modify('+1 hour') <= $ahora) {
                    $confirmar->execute([':id' => $factura['id'], ':uid' => $usuario_id]);
                    $resultado['confirmadas']++;
                }
            }
        } catch (PDOException $e) {
            error_log('Error al reconciliar pagos: ' . $e->getMessage());
        }

        return $resultado;
    }
}