<?php
declare(strict_types=1);

/**
 * Estima los kWh de una factura a partir de su importe y de la tarifa de su suministro.
 *
 * Funciona solo con cuadros formados por cargos "invertibles" (cargo fijo y energía
 * por tramos). Si el cuadro tiene otro tipo de cargo (potencia, franjas horarias,
 * créditos), o si falta la tarifa, el cuadro vigente o el suministro, devuelve null.
 *
 * Importante: sigue siendo una ESTIMACIÓN, aunque la cuenta usa las alícuotas exactas (tasa municipal e IVA).
 * Los errores vienen de datos que pueden faltar o no coincidir: cuadros tarifarios sin cargar, una mora
 * supuesta que no es la real o redondeos de la CEG. El coeficiente (coeficiente() y calibrar_coeficiente.php)
 * ya no interviene en estimar(): queda solo como herramienta de calibración.
 *
 * Idea general: el importe de una factura se arma así
 *   total  = básico × (1 + TASA_MUNICIPAL + IVA) + mora × (1 + IVA)
 *   básico = cargo fijo + energía (kWh por tramos, cada tramo con su precio por kWh)
 * estimar() recorre esa cuenta AL REVÉS: dado el total, despeja el básico, resta el cargo fijo y
 * "gasta" lo que queda tramo por tramo hasta saber cuántos kWh lo explican. importePorConsumo() hace
 * la cuenta hacia adelante (de los kWh al importe).
 */
final class EstimadorKwh{
    // La CEG factura cada período con DOS cuadros tarifarios: los primeros 20 días con el cuadro del mes del
    // comprobante y los días restantes con el del mes anterior (ver ponderar()).
    private CONST DIAS_MES_ACTUAL = 20;
    /**
     * Impuestos y otros conceptos sobre el costo del servicio; solo se usa si todavía no hay
     * facturas con kWh reales. Valor medido con dos facturas reales: 1,3961 y 1,3977.
     */
    private const COEF_POR_DEFECTO = 1.397;

    /** Facturas con menos kWh que esto no se usan para calibrar (el cargo fijo y otros conceptos pesan demasiado). */
    private const KWH_MINIMO_CALIBRACION = 30;

    /** true: la mora empieza a contar si se pagó después del 2º vencimiento; false: después del 1º. */
    private const MORA_TRAS_SEGUNDO_VENCIMIENTO = true;

    private const TASA_MUNICIPAL = 0.13; // sobre el básico
    private const IVA            = 0.21; // sobre el básico y sobre la mora
    private const TNA_MORA_POR_DEFECTO = 29.8982; // solo si no hay ninguna fila en tasas_mora
    private array $cacheTnaMora        = [];      // caché por período
    /** Cargos de cada cuadro ya leídos, para no repetir consultas dentro de una misma página. */
    private array $cacheCargos = [];
    /** Coeficiente ya calculado de cada suministro, para no repetir consultas dentro de una misma página. */
    private array $cacheCoef = [];

    public function __construct(private PDO $db) {}

    /**
     * kWh estimados para una factura, o null si no se pueden estimar.
     */
    public function estimar(int $factura_id, int $usuario_id): ?int
    {
        $contexto = $this->contexto($factura_id, $usuario_id);
        if ($contexto === null) {
            return null;
        }
        [$factura, $cargos] = $contexto;

        // total = básico × (1 + tasa + IVA) + mora × (1 + IVA). Se despeja el básico (cargo fijo + energía).
        // Si la factura trae la mora cargada se respeta; si no, se usa la sugerida.
        // Operador ternario (cond ? a : b): si la factura trae mora cargada se usa esa; si no, la sugerida.
        $mora = (float)$factura['mora'] > 0
            ? (float)$factura['mora']
            : $this->moraSugerida($factura_id, $usuario_id);
        $restante = ((float)$factura['monto_vencimiento_1'] - $mora * (1 + self::IVA))
                  / (1 + self::TASA_MUNICIPAL + self::IVA);

        // 2) Se descuenta el cargo fijo y se separan los tramos de energía.
        $tramos = [];
        foreach ($cargos as $cargo) {
            if ($cargo['codigo'] === 'FIJO') {
                $restante -= (float)$cargo['valor'];
            } elseif ($cargo['codigo'] === 'ENERGIA') {
                $tramos[] = $cargo;
            } else {
                return null; // un cargo que no sabemos despejar
            }
        }
        if ($restante < 0 || $tramos === []) {
            return null;
        }

        // 3) Se recorren los tramos de menor a mayor, "gastando" el importe restante.
        $kwh = 0.0;
        foreach ($tramos as $tramo) {
            $precio = (float)$tramo['valor'];
            if ($precio <= 0) {
                return null;
            }
            // Capacidad del tramo = cuántos kWh entran en él (hasta - desde). El último tramo no tiene tope
            // (kwh_hasta es NULL): se usa INF (infinito), así siempre alcanza para lo que reste.
            $capacidad = $tramo['kwh_hasta'] === null
                ? INF
                : (float)((int)$tramo['kwh_hasta'] - (int)$tramo['kwh_desde']);
            $costoTramo = $capacidad * $precio; // INF si el tramo no tiene tope

            // Si lo que queda de plata entra en este tramo: kWh = los ya acumulados + restante / precio.
            // Si no, se llena el tramo completo (suma su capacidad, resta su costo) y se sigue con el siguiente.
            if ($restante <= $costoTramo) {
                return (int)round($kwh + $restante / $precio);
            }
            $kwh += $capacidad;
            $restante -= $costoTramo;
        }

        return null; // el importe supera el último tramo con tope: cuadro inconsistente
    }

    /**
     * Coeficiente (impuestos y otros conceptos) a aplicar a un suministro.
     *
     * Se calibra solo: para cada factura del suministro que ya tiene los kWh REALES
     * cargados se calcula importe real / neto del cuadro, y se toma la MEDIANA.
     * Así, cuanto más facturas cargues con su consumo real, más preciso se vuelve
     * el estimado de las que todavía no lo tienen.
     *
     * Sin ninguna factura calibrable: usa coef_adicional del suministro si se lo
     * cambió a mano (> 1) y, si no, COEF_POR_DEFECTO (1,397).
     */
    // OJO: hoy estimar() ya no usa este coeficiente (despeja con las alícuotas exactas TASA_MUNICIPAL e IVA).
    // Queda como herramienta de calibración (ver calibrar_coeficiente.php).
    public function coeficiente(int $suministro_id, int $usuario_id, float $coefManual): float
    {
        if (isset($this->cacheCoef[$suministro_id])) {
            return $this->cacheCoef[$suministro_id];
        }

        $stmt = $this->db->prepare(
            "SELECT id, monto_vencimiento_1, kwh_consumidos
             FROM facturas
             WHERE suministro_id = :sid AND usuario_id = :uid
               AND kwh_consumidos >= :min"
        );
        $stmt->execute([
            ':sid' => $suministro_id,
            ':uid' => $usuario_id,
            ':min' => self::KWH_MINIMO_CALIBRACION,
        ]);

        $implicitos = [];
        foreach ($stmt->fetchAll() as $f) {
            $neto = $this->netoPorConsumo((int)$f['id'], $usuario_id, (int)$f['kwh_consumidos']);
            if ($neto === null || $neto <= 0) {
                continue; // sin cuadro vigente o tarifa no estimable
            }
            $c = (float)$f['monto_vencimiento_1'] / $neto;
            if ($c >= 1.0 && $c <= 2.0) { // descarta datos absurdos (kWh mal cargados)
                $implicitos[] = $c;
            }
        }

        if ($implicitos !== []) {
            // Se usa la MEDIANA y no el promedio: una factura con un dato erróneo no arrastra el resultado.
            $coef = $this->mediana($implicitos);
        } else {
            $coef = $coefManual > 1.0 ? $coefManual : self::COEF_POR_DEFECTO;
        }

        return $this->cacheCoef[$suministro_id] = $coef;
    }

    private function mediana(array $valores): float
    {
        sort($valores);
        $n = count($valores);
        // Cantidad impar de valores: el del medio. Cantidad par: promedio de los dos centrales.
        $medio = intdiv($n, 2);
        return $n % 2 === 1
            ? (float)$valores[$medio]
            : ((float)$valores[$medio - 1] + (float)$valores[$medio]) / 2;
    }

    /**
     * Importe "neto" que daría el cuadro para un consumo dado (sin conceptos adicionales).
     * Sirve para calibrar el coeficiente: coef = importe real / neto del cuadro.
     */
    public function netoPorConsumo(int $factura_id, int $usuario_id, int $kwh): ?float
    {
        $contexto = $this->contexto($factura_id, $usuario_id);
        if ($contexto === null) {
            return null;
        }
        [, $cargos] = $contexto;

        return $this->netoDesdeCargos($cargos, $kwh);
    }

    /**
     * Reúne los datos de la factura y los cargos del cuadro que le corresponde.
     * Devuelve [factura, cargos] o null si falta algo o no es estimable.
     */
    private function contexto(int $factura_id, int $usuario_id): ?array
    {
        $sql = "SELECT f.monto_vencimiento_1, f.mora, f.periodo, f.suministro_id,
                       s.tipo_tarifa_id, s.nivel_subsidio_id, s.coef_adicional
                FROM facturas f
                JOIN suministros s ON s.id = f.suministro_id
                WHERE f.id = :id AND f.usuario_id = :uid";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $factura_id, ':uid' => $usuario_id]);
        $factura = $stmt->fetch();

        // Sin suministro, o con el suministro todavía sin tarifa asignada
        if ($factura === false || $factura['tipo_tarifa_id'] === null) {
            return null;
        }

        $cargos = $this->cargosDelPeriodo($factura, (string)$factura['periodo']);
        if ($cargos === null) {
            return null;
        }


        return [$factura, $cargos];
    }

        /**
    * Cargos del cuadro ponderado para un suministro y un PERIODO ("M/AAAA", tal como lo informa la CEG).
     * Primeros 20 días con el cuadro del mes del período; el resto con el del mes anterior, cuyos días
     * son el denominador. $suministro necesita tipo_tarifa_id y nivel_subsidio_id.
     */
    private function cargosDelPeriodo(array $suministro, string $periodo): ?array
    {
        $mesConsumo = $this->primerDiaDelPeriodo($periodo);
        if ($mesConsumo === null) {
            return null;
        }
        $mesActual   = $mesConsumo;                     // mes del período: cuadro de los primeros 20 días
        $mesAnterior = $mesConsumo->modify('-1 month'); // cuadro del resto de los días

        $cuadroActual   = $this->buscarCuadro($suministro, $mesActual->format('Y-m-d'));
        $cuadroAnterior = $this->buscarCuadro($suministro, $mesAnterior->format('Y-m-d'));
        if ($cuadroActual === null || $cuadroAnterior === null) {
            return null; // hacen falta los DOS cuadros
        }

        $actuales   = $this->cargosDelCuadro($cuadroActual);
        $anteriores = $this->cargosDelCuadro($cuadroAnterior);
        if ($actuales === [] || $anteriores === []) {
            return null;
        }

        // 't' devuelve la cantidad de días del mes anterior (28 a 31): es el denominador de la ponderación.
        return $this->ponderar($actuales, $anteriores, (int)$mesAnterior->format('t'));
    }

    /** Importe neto de $kwh con los cargos del cuadro ya ponderados. */
    private function netoDesdeCargos(array $cargos, float $kwh): float
    {
        $total = 0.0;
        // $faltan = kWh que todavía hay que asignar a un tramo. Cada cargo ENERGIA toma min(faltan, capacidad)
        // a su precio; el cargo FIJO se suma completo, haya consumo o no.
        $faltan = (float)$kwh;
        foreach ($cargos as $cargo) {
            if ($cargo['codigo'] === 'FIJO') {
                $total += (float)$cargo['valor'];
            } elseif ($cargo['codigo'] === 'ENERGIA' && $faltan > 0) {
                $capacidad = $cargo['kwh_hasta'] === null
                    ? INF
                    : (float)((int)$cargo['kwh_hasta'] - (int)$cargo['kwh_desde']);
                $enTramo = min($faltan, $capacidad);
                $total += $enTramo * (float)$cargo['valor'];
                $faltan -= $enTramo;
            }
        }
        return $total;
    }

    /**
     * Importe estimado a pagar por $kwh consumidos en el PERIODO ("M/AAAA") de un suministro,
     * sin que exista todavía la factura. Mismos cuadros y mismo coeficiente que estimar().
     * Devuelve ['importe', 'neto', 'coef'] o null si no se puede calcular.
     */
    public function importePorConsumo(int $suministro_id, int $usuario_id, string $periodo, float $kwh, float $mora = 0.0): ?array
    {
        if ($kwh < 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT id, tipo_tarifa_id, nivel_subsidio_id, coef_adicional
             FROM suministros WHERE id = :id AND usuario_id = :uid"
        );
        $stmt->execute([':id' => $suministro_id, ':uid' => $usuario_id]);
        $suministro = $stmt->fetch();
        if ($suministro === false || $suministro['tipo_tarifa_id'] === null) {
            return null;
        }

        $cargos = $this->cargosDelPeriodo($suministro, $periodo);
        if ($cargos === null) {
            return null;
        }

        // Cuenta hacia adelante, cada concepto redondeado a centavos como en la factura real:
        // importe = básico + tasa (13 % del básico) + IVA (21 % del básico) + mora + IVA de la mora.
        $basico  = round($this->netoDesdeCargos($cargos, $kwh), 2);
        $tasa    = round($basico * self::TASA_MUNICIPAL, 2);
        $iva     = round($basico * self::IVA, 2);
        $ivaMora = round($mora * self::IVA, 2);
        return [
            'importe'  => round($basico + $tasa + $iva + $mora + $ivaMora, 2),
            'neto'     => $basico,
            'tasa'     => $tasa,
            'iva'      => $iva,
            'mora'     => $mora,
            'iva_mora' => $ivaMora,
        ];
    }

    /**
     * Mezcla los precios de los dos cuadros según los días de cada uno:
     *   precio = (20 / díasDelMesAnterior) × precio del cuadro actual
     *          + ((díasDelMesAnterior − 20) / díasDelMesAnterior) × precio del cuadro anterior
     * Devuelve null si los dos cuadros no tienen la misma estructura de cargos y tramos.
     */
    private function ponderar(array $actuales, array $anteriores, int $diasDelMes): ?array
    {
    // $clave arma un identificador por cargo (p. ej. "ENERGIA|100") para encontrar el MISMO tramo en el otro cuadro.
    // "static fn" es una función flecha: una función anónima de una sola expresión.
    $clave = static fn(array $c): string => $c['codigo'] . '|' . (int)$c['kwh_desde'];

    $indiceAnterior = [];
    foreach ($anteriores as $c) {
        $indiceAnterior[$clave($c)] = $c;
    }

    if (count($actuales) !== count($anteriores)) {
        return null;
    }

    $resultado = [];
    foreach ($actuales as $actual) {
        if ((int)$actual['invertible'] !== 1) {
            return null; // cargo que no se puede despejar
        }

        $previo = $indiceAnterior[$clave($actual)] ?? null;
        if ($previo === null || (int)$previo['invertible'] !== 1) {
            return null;
        }

        // Los tramos deben cubrir el mismo rango de kWh en ambos meses
        $hastaA = $actual['kwh_hasta'] === null ? null : (int)$actual['kwh_hasta'];
        $hastaP = $previo['kwh_hasta'] === null ? null : (int)$previo['kwh_hasta'];
        if ($hastaA !== $hastaP) {
            return null;
        }

        // Ej.: mes anterior de 31 días -> 20/31 del cuadro actual + 11/31 del anterior;
        //      mes anterior de 30 días -> 20/30 + 10/30.
        $pesoActual   = self::DIAS_MES_ACTUAL / $diasDelMes;
        $pesoAnterior = ($diasDelMes - self::DIAS_MES_ACTUAL) / $diasDelMes;

        $actual['valor'] = $pesoActual * (float)$actual['valor']
                         + $pesoAnterior * (float)$previo['valor'];

        $resultado[] = $actual;
    }

    return $resultado;
    }

    /**
     * TNA vencida (%) asignada al PERIODO ("M/AAAA") de la factura que trae la mora: la fila de
     * tasas_mora que cubre el primer día del período; si no hay, la más reciente anterior; si
     * tampoco, TNA_MORA_POR_DEFECTO.
     */
    public function tnaMoraDelPeriodo(string $periodo): float
    {
        if (isset($this->cacheTnaMora[$periodo])) {
            return $this->cacheTnaMora[$periodo];
        }
        $dia = $this->primerDiaDelPeriodo($periodo);
        if ($dia === null) {
            return self::TNA_MORA_POR_DEFECTO;
        }
        $fecha = $dia->format('Y-m-d');

        // ORDER BY (vigente_hasta >= :d2) DESC, vigente_desde DESC LIMIT 1:
        // primero las tasas cuyo rango CUBRE la fecha (la comparación vale 1), después las más recientes.
        // Si ninguna la cubre, se usa la última anterior. Se usan dos marcadores (:d1 y :d2) para la misma fecha
        // porque con consultas preparadas reales no se puede repetir un mismo nombre de marcador.
        $stmt = $this->db->prepare(
            "SELECT tna FROM tasas_mora
             WHERE vigente_desde <= :d1
             ORDER BY (vigente_hasta >= :d2) DESC, vigente_desde DESC
             LIMIT 1"
        );
        $stmt->execute([':d1' => $fecha, ':d2' => $fecha]);
        $tna = $stmt->fetchColumn();

        return $this->cacheTnaMora[$periodo] = $tna !== false ? (float)$tna : self::TNA_MORA_POR_DEFECTO;
    }

    /**
     * Interés de mora: interés simple, TNA vencida / 365 por día de atraso, redondeado hacia arriba
     * al centavo. El IVA de 21 % de la mora se suma aparte.
     */
    public function calcularMora(float $monto, string $vencimiento, string $pago, float $tnaPorc): float
    {
        // Días de atraso: diff() calcula la diferencia entre fechas; "%r%a" = signo + total de días
        // (negativo si se pagó antes del vencimiento, y entonces no hay mora).
        $dias = (int)(new DateTimeImmutable($vencimiento))
            ->diff(new DateTimeImmutable($pago))
            ->format('%r%a');
        if ($dias <= 0) {
            return 0.0;
        }
        // Interés simple diario: monto × (TNA / 100 / 365) × días de atraso. TNA = tasa nominal anual en %.
        $interes = $monto * ($tnaPorc / 100 / 365) * $dias;
        // El round() previo evita que el ruido de los decimales binarios suba un centavo de más.
        return ceil(round($interes * 100, 6)) / 100;
    }

    /**
     * Mora que debería traer una factura: la de la factura inmediata anterior del mismo suministro si
     * se pagó después de su primer vencimiento. La tasa es la del período de la factura que la trae.
     * Devuelve 0 si no hay anterior, no está paga o pagó a término.
     */
    public function moraSugerida(int $factura_id, int $usuario_id): float
    {
        // La tabla facturas se une consigo misma (auto-JOIN): "f" es la factura actual y "p" otra factura del
        // mismo suministro y usuario con vencimiento ANTERIOR. ORDER BY ... DESC LIMIT 1 deja solo la inmediata anterior.
        $stmt = $this->db->prepare(
            "SELECT p.monto_vencimiento_1, p.fecha_vencimiento_1, p.fecha_pago, f.periodo AS periodo_factura
             FROM facturas f
             JOIN facturas p ON p.suministro_id = f.suministro_id
                            AND p.usuario_id = f.usuario_id
                            AND p.fecha_vencimiento_1 < f.fecha_vencimiento_1
             WHERE f.id = :id AND f.usuario_id = :uid
             ORDER BY p.fecha_vencimiento_1 DESC
             LIMIT 1"
        );
        $stmt->execute([':id' => $factura_id, ':uid' => $usuario_id]);
        $previa = $stmt->fetch();
        if ($previa === false || $previa['fecha_pago'] === null) {
            return 0.0;
        }
        return $this->calcularMora(
            (float)$previa['monto_vencimiento_1'],
            (string)$previa['fecha_vencimiento_1'],
            (string)$previa['fecha_pago'],
            $this->tnaMoraDelPeriodo((string)$previa['periodo_factura'])
        );
    }

    private function primerDiaDelPeriodo(string $periodo): ?DateTimeImmutable
    {
    // Acepta "9/2026", "09-2026" y variantes con espacios. Devuelve el día 1 de ese mes como DateTimeImmutable
    // (inmutable: modify() devuelve una copia nueva y no altera el original).
    if (!preg_match('~^\s*(\d{1,2})\s*[/-]\s*(\d{4})\s*$~', $periodo, $m)) {
        return null;
    }
    $mes  = (int)$m[1];
    $anio = (int)$m[2];
    if ($mes < 1 || $mes > 12) {
        return null;
    }
    return new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes));
    }

    /**
     * Cuadro de la categoría del suministro que cubre la fecha de la factura.
     * Si no hay ninguno que la cubra, devuelve null (no se usa un cuadro anterior).
     */
    private function buscarCuadro(array $factura, string $fecha): ?int
    {
        // Busca el cuadro tarifario de la categoría del suministro que está vigente en $fecha:
        //  - (n.codigo = 'NO_APLICA' OR c.nivel_subsidio_id = :nivel): hay categorías que no distinguen subsidio
        //    (NO_APLICA); para las demás el cuadro debe coincidir con el nivel elegido;
        //  - :fecha BETWEEN vigente_desde AND vigente_hasta: el cuadro vigente ese día.
        $sql = "SELECT c.id
                FROM cuadros_tarifarios c
                JOIN niveles_subsidio n ON n.id = c.nivel_subsidio_id
                WHERE c.tipo_tarifa_id = :tipo
                  AND (n.codigo = 'NO_APLICA' OR c.nivel_subsidio_id = :nivel)
                  AND :fecha BETWEEN c.vigente_desde AND c.vigente_hasta
                ORDER BY c.vigente_desde DESC
                LIMIT 1";
        $stmt = $this->db->prepare($sql);

        $nivel = $factura['nivel_subsidio_id'];
        $stmt->bindValue(':tipo', (int)$factura['tipo_tarifa_id'], PDO::PARAM_INT);
        $stmt->bindValue(
            ':nivel',
            $nivel === null ? null : (int)$nivel,
            $nivel === null ? PDO::PARAM_NULL : PDO::PARAM_INT
        );
        // Fecha de referencia: el primer día del mes del cuadro que se busca (lo arma cargosDelPeriodo)
        $stmt->bindValue(':fecha', $fecha, PDO::PARAM_STR);
        $stmt->execute();

        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function cargosDelCuadro(int $cuadro_id): array
    {
        if (!isset($this->cacheCargos[$cuadro_id])) {
            $sql = "SELECT t.codigo, t.invertible, cc.kwh_desde, cc.kwh_hasta, cc.valor
                    FROM cuadro_cargos cc
                    JOIN tipos_cargo t ON t.id = cc.tipo_cargo_id
                    WHERE cc.cuadro_id = :cuadro
                    ORDER BY cc.kwh_desde";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':cuadro' => $cuadro_id]);
            $this->cacheCargos[$cuadro_id] = $stmt->fetchAll();
        }
        return $this->cacheCargos[$cuadro_id];
    }


    /**
     * Mora que traerá el próximo comprobante según cómo se resolvió la última factura del suministro:
     *  - paga fuera de término (fecha detectada o cargada a mano) -> mora hasta la fecha de pago;
     *  - impaga y ya vencida -> mora acumulada hasta hoy (aproximada: sigue creciendo hasta que se pague);
     *  - paga a término, sin facturas o sin fecha de pago -> 0.
     *
     * @return array{mora: float, detalle: ?string}
     */
    public function moraPendiente(int $suministro_id, int $usuario_id, string $periodoLectura): array
    {
        $vacio = ['mora' => 0.0, 'detalle' => null];

        $stmt = $this->db->prepare(
            "SELECT comprobante, estado, fecha_pago, monto_vencimiento_1, fecha_vencimiento_1, fecha_vencimiento_2
             FROM facturas
             WHERE usuario_id = :uid AND suministro_id = :sid AND fecha_vencimiento_1 IS NOT NULL
             ORDER BY fecha_vencimiento_1 DESC
             LIMIT 1"
        );
        $stmt->execute([':uid' => $usuario_id, ':sid' => $suministro_id]);
        $f = $stmt->fetch();
        if ($f === false) {
            return $vacio;
        }

        $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        // Fecha hasta la que NO hay mora: el 2º vencimiento (o el 1º si no existe), según la constante
        // MORA_TRAS_SEGUNDO_VENCIMIENTO. Si se pagó después, o sigue impaga y ya venció, la mora se
        // calcula desde el 1º vencimiento hasta el pago (o hasta hoy).
        $limite = self::MORA_TRAS_SEGUNDO_VENCIMIENTO
            ? (string)($f['fecha_vencimiento_2'] ?? $f['fecha_vencimiento_1'])
            : (string)$f['fecha_vencimiento_1'];

        if ($f['estado'] === 'paga' && $f['fecha_pago'] !== null) {
            if ((string)$f['fecha_pago'] <= $limite) {
                return $vacio;
            }
            $hasta = (string)$f['fecha_pago'];
            $detalle = 'por la factura ' . $f['comprobante'] . ', paga el ' . date('d/m/Y', strtotime($hasta))
                . ' fuera de término';
        } elseif ($f['estado'] === 'pendiente' && $hoy > $limite) {
            $hasta = $hoy;
            $detalle = 'por la factura ' . $f['comprobante'] . ', todavía sin pagar (vencida el '
                . date('d/m/Y', strtotime($limite)) . '; la mora sigue creciendo hasta que se pague)';
        } else {
            return $vacio;
        }

        $mora = $this->calcularMora(
            (float)$f['monto_vencimiento_1'],
            (string)$f['fecha_vencimiento_1'],
            $hasta,
            $this->tnaMoraDelPeriodo($periodoLectura)
        );

        return $mora > 0 ? ['mora' => $mora, 'detalle' => $detalle] : $vacio;
    }
}