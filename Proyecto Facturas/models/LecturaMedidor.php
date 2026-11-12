<?php
declare(strict_types=1);

/**
 * Lecturas del medidor (número acumulado del contador) de un suministro.
 *
 * El PRIMER valor que se ingresa no es un consumo: queda como punto de partida. Desde la lectura
 * siguiente, consumo = lectura actual - punto de partida del periodo. Al empezar un periodo nuevo,
 * el punto de partida es la última lectura del periodo anterior.
 *
 * El importe a pagar NO se guarda: se calcula al mostrarlo (EstimadorKwh::importePorConsumo),
 * así siempre refleja los cuadros tarifarios y el coeficiente vigentes.
 */
final class LecturaMedidor
{
    public function __construct(private PDO $db) {}

    /**
     * Valida y normaliza un periodo "M/AAAA" (acepta "10/2026", "10-2026" o "010/2026"... con mes 1-12).
     * Devuelve el formato que usa la tabla de facturas ("10/2026") o null si no es válido.
     */
    public static function normalizarPeriodo(string $periodo): ?string
    {
        if (!preg_match('~^\s*(\d{1,2})\s*[/-]\s*(\d{4})\s*$~', $periodo, $m)) {
            return null;
        }
        $mes  = (int)$m[1];
        $anio = (int)$m[2];
        if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2100) {
            return null;
        }
        return $mes . '/' . $anio;
    }

    /**
     * Periodo al que corresponden los kWh que todavía no se facturaron: el mes siguiente al de la
     * última factura del suministro. Sin facturas, el mes actual.
     */
    public function periodoSugerido(int $usuario_id, int $suministro_id): string
    {
        $stmt = $this->db->prepare(
            "SELECT periodo FROM facturas
             WHERE usuario_id = :uid AND suministro_id = :sid AND periodo IS NOT NULL"
        );
        $stmt->execute([':uid' => $usuario_id, ':sid' => $suministro_id]);

        $ultimo = null; // meses desde el año 0, para poder comparar y sumar 1
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $periodo) {
            $normal = self::normalizarPeriodo((string)$periodo);
            if ($normal === null) {
                continue;
            }
            [$mes, $anio] = array_map('intval', explode('/', $normal));
            $n = $anio * 12 + $mes - 1;
            $ultimo = $ultimo === null ? $n : max($ultimo, $n);
        }

        if ($ultimo === null) {
            return (new DateTimeImmutable('now'))->format('n/Y');
        }
        $siguiente = $ultimo + 1;
        // Vuelve de "meses desde el año 0" al formato M/AAAA: el resto de dividir por 12, más 1, es el mes (1-12)
        // y el cociente entero es el año. Ej.: octubre/2026 -> siguiente = 2026*12 + 10 -> mes 11, año 2026.
        return (($siguiente % 12) + 1) . '/' . intdiv($siguiente, 12);
    }

    /** Meses desde el año 0 de un periodo "M/AAAA" normalizado; sirve para ordenar y comparar. */
    private static function ordinal(string $periodo): int
    {
        [$mes, $anio] = array_map('intval', explode('/', $periodo));
        return $anio * 12 + $mes - 1;
    }

    /**
     * Guarda la lectura del medidor de un periodo. Resultados:
     *  'base'  -> quedó como punto de partida (primer valor, o "nuevo punto de partida"): todavía sin consumo
     *  'ok'    -> lectura guardada; el consumo del periodo ya se puede calcular
     *  'menor' -> la lectura es menor que el punto de partida (el contador no retrocede)
     *  'error' -> suministro ajeno o fallo de la base de datos
     *
     * El punto de partida se elige por orden de confianza: 1) "nuevo punto de partida" pedido por la persona;
     * 2) lectura final de la factura del periodo anterior (dato oficial de la CEG, leído del PDF);
     * 3) última lectura cargada de un periodo anterior; 4) el primer valor de todos.
     */
    public function guardar(int $usuario_id, int $suministro_id, string $periodo, float $lectura, bool $nuevoPunto = false): string
    {
        try {
            $propio = $this->db->prepare("SELECT 1 FROM suministros WHERE id = :sid AND usuario_id = :uid");
            $propio->execute([':sid' => $suministro_id, ':uid' => $usuario_id]);
            if ($propio->fetchColumn() === false) {
                return 'error';
            }

            $todas = $this->db->prepare(
                "SELECT periodo, lectura_inicial, lectura_actual, origen_inicial
                 FROM lecturas_medidor WHERE suministro_id = :sid"
            );
            $todas->execute([':sid' => $suministro_id]);
            $filas = $todas->fetchAll();

            $delPeriodo = null;   // fila de este periodo, si existe
            $previa = null;       // fila del periodo más reciente anterior a éste
            $ordenPeriodo = self::ordinal($periodo);
            foreach ($filas as $fila) {
                $orden = self::ordinal((string)$fila['periodo']);
                if ($orden === $ordenPeriodo) {
                    $delPeriodo = $fila;
                } elseif ($orden < $ordenPeriodo
                    && ($previa === null || $orden > self::ordinal((string)$previa['periodo']))) {
                    $previa = $fila;
                }
            }

            // Lectura final de la factura del periodo ANTERIOR (orden - 1), si ya se leyó su PDF: es el dato oficial.
            $deFactura = $this->lecturaDeFactura($usuario_id, $suministro_id, $ordenPeriodo - 1);

            // Cascada de decisión del punto de partida (de mayor a menor confianza, ver el docblock de guardar()):
            // 1) nuevo punto pedido -> 2) ya había uno "nuevo" en el periodo -> 3) lectura de la factura anterior
            // -> 4) última lectura de un periodo anterior -> 5) lo ya guardado en el periodo -> 6) primer valor de todos.
            // $origen se guarda para poder explicarle a la persona, en pantalla, de dónde salió el punto de partida.
            if ($nuevoPunto) {
                $inicial = $lectura;                                     // cambio de medidor, etc.
                $origen = 'nuevo';
                $resultado = 'base';
            } elseif ($delPeriodo !== null && $delPeriodo['origen_inicial'] === 'nuevo') {
                $inicial = (float)$delPeriodo['lectura_inicial'];        // se respeta el punto que se pidió
                $origen = 'nuevo';
                $resultado = 'ok';
            } elseif ($deFactura !== null) {
                $inicial = $deFactura;                                   // dato oficial de la factura anterior
                $origen = 'factura';
                $resultado = 'ok';
            } elseif ($previa !== null) {
                $inicial = (float)$previa['lectura_actual'];             // arranca donde terminó el periodo anterior
                $origen = 'anterior';
                $resultado = 'ok';
            } elseif ($delPeriodo !== null) {
                $inicial = (float)$delPeriodo['lectura_inicial'];        // mismo periodo, sin mejor dato
                $origen = (string)$delPeriodo['origen_inicial'];
                $resultado = 'ok';
            } else {
                $inicial = $lectura;                                     // primer valor de todos
                $origen = 'base';
                $resultado = 'base';
            }

            if ($lectura < $inicial) {
                // El punto de partida de la factura trae decimales (24200,35) y el medidor muestra enteros (24200):
                // una lectura entera apenas por debajo se toma como "sin consumo todavía".
                if ($lectura >= floor($inicial)) {
                    $lectura = $inicial;
                } else {
                    return 'menor';
                }
            }

            // Upsert: la tabla tiene clave única (suministro, periodo). Si ya había una lectura de ese periodo se
            // actualiza; si no, se inserta. La base además exige lectura_actual >= lectura_inicial (CHECK).
            $stmt = $this->db->prepare(
                "INSERT INTO lecturas_medidor (usuario_id, suministro_id, periodo, lectura_inicial, lectura_actual, origen_inicial)
                 VALUES (:uid, :sid, :periodo, :inicial, :actual, :origen)
                 ON DUPLICATE KEY UPDATE
                     lectura_inicial = VALUES(lectura_inicial),
                     lectura_actual  = VALUES(lectura_actual),
                     origen_inicial  = VALUES(origen_inicial)"
            );
            $ok = $stmt->execute([
                ':uid'     => $usuario_id,
                ':sid'     => $suministro_id,
                ':periodo' => $periodo,
                ':inicial' => $inicial,
                ':actual'  => $lectura,
                ':origen'  => $origen,
            ]);
            return $ok ? $resultado : 'error';
        } catch (PDOException $e) {
            error_log("Error al guardar la lectura del medidor: " . $e->getMessage());
            return 'error';
        }
    }

    /** Lectura final (la de la factura) del periodo con ese número ordinal, si ya se leyó el PDF. */
    private function lecturaDeFactura(int $usuario_id, int $suministro_id, int $ordinalPeriodo): ?float
    {
        $stmt = $this->db->prepare(
            "SELECT periodo, lectura_actual FROM facturas
             WHERE usuario_id = :uid AND suministro_id = :sid
               AND lectura_actual IS NOT NULL AND periodo IS NOT NULL"
        );
        $stmt->execute([':uid' => $usuario_id, ':sid' => $suministro_id]);
        foreach ($stmt->fetchAll() as $factura) {
            $normal = self::normalizarPeriodo((string)$factura['periodo']);
            if ($normal !== null && self::ordinal($normal) === $ordinalPeriodo) {
                return (float)$factura['lectura_actual'];
            }
        }
        return null;
    }

    /** Última lectura actualizada de cada suministro del usuario, indexada por suministro_id. */
    public function ultimasPorUsuario(int $usuario_id): array
    {
        $stmt = $this->db->prepare(
            "SELECT suministro_id, periodo, lectura_inicial, lectura_actual, origen_inicial, actualizada_en
             FROM lecturas_medidor
             WHERE usuario_id = :uid
             ORDER BY actualizada_en DESC, id DESC"
        );
        $stmt->execute([':uid' => $usuario_id]);

        $porSuministro = [];
        foreach ($stmt->fetchAll() as $fila) {
            $porSuministro[(int)$fila['suministro_id']] ??= $fila; // la primera es la más reciente
        }
        return $porSuministro;
    }
}
