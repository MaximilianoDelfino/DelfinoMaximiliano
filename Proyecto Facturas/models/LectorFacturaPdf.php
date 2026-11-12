<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php'; // composer require smalot/pdfparser

// FLUJO COMPLETO: descargar() el PDF de la CEG -> extraerTexto() lo pasa a texto -> parsear() extrae los
// números con expresiones regulares y los VALIDA (los importes deben sumar) -> Factura::guardarDetallePdf()
// lo guarda. procesarFactura() encadena esos pasos; leerPendientes() lo hace para todas las facturas
// que faltan, con tope y pausas para no saturar a la CEG.
/**
 * Descarga el PDF de una factura de la CEG y extrae los datos reales (kWh, lecturas, cargos, mora).
 * Solo se guardan números: no se conservan nombre ni dirección del titular.
 */
class LectorFacturaPdf
{
    private const URL_PDF    = 'https://apiceg.ceg.org.ar:8886/GetFile?filename=';
    private const TOLERANCIA = 0.05; // diferencia admitida por los redondeos de la CEG (pesos)
    /** Ejecutable de poppler. Si no está en el Path, poné la ruta completa, p. ej. 'C:\\poppler\\Library\\bin\\pdftotext.exe'. */
    private const PDFTOTEXT = 'C:\poppler\poppler-26.09.0\Library\bin\pdftotext.exe';
    private const MAX_INTENTOS = 3;      // después de tres fallos se deja de insistir con ese PDF
    private const PAUSA_US     = 500000; // 0,5 s entre descargas: no saturar a la CEG

    /** "FAC-14120813/1" -> "FAC-14120813", o null si no tiene el formato esperado. */
    public function nombreArchivo(string $comprobante): ?string
    {
        // Quita el sufijo "/1" del final ("FAC-14120813/1" -> "FAC-14120813"). Después solo se acepta
        // "FAC-" + dígitos: así nada raro puede colarse en la URL de descarga.
        $base = preg_replace('~/\d+$~', '', trim($comprobante));
        return preg_match('/^FAC-\d+$/', (string)$base) === 1 ? $base : null;
    }

    /** Descarga el PDF. Lanza RuntimeException('pdf_descarga') si falla. */
    public function descargar(string $nombre): string
    {
        // CURLOPT_RETURNTRANSFER: curl_exec() devuelve el contenido como string en vez de imprimirlo.
        // CURLOPT_CAINFO: certificados para validar el HTTPS de la CEG (igual que en SincronizadorCEG).
        $ch = curl_init(self::URL_PDF . urlencode($nombre . '.pdf'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CAINFO         => __DIR__ . '/cacert_ceg.pem',
        ]);
        $pdf  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Todo PDF válido empieza con los bytes "%PDF": así se descarta una página de error devuelta con HTTP 200.
        if ($pdf === false || $http !== 200 || !str_starts_with($pdf, '%PDF')) {
            error_log('CEG PDF: descarga fallida (HTTP ' . $http . ') para ' . $nombre);
            throw new RuntimeException('pdf_descarga');
        }
        return $pdf;
    }

    /**
     * Texto plano del PDF. Primero prueba con pdftotext -layout (cada fila queda en una línea) y,
     * si no está instalado, usa smalot. Lanza RuntimeException('pdf_ilegible') si no se puede leer.
     */
    public function extraerTexto(string $pdf): string
    {
        $texto = $this->extraerConPdftotext($pdf);
        if ($texto !== null) {
            return $texto;
        }
        try {
            return (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        } catch (\Throwable $e) {
            error_log('CEG PDF: no se pudo leer: ' . $e->getMessage());
            throw new RuntimeException('pdf_ilegible');
        }
    }

    /** Devuelve el texto en modo layout, o null si pdftotext no está disponible o falla. */
    private function extraerConPdftotext(string $pdf): ?string
    {
        // pdftotext (programa externo de poppler) lee un archivo, así que el PDF se escribe en un temporal.
        // Se lo llama con proc_open y una lista de argumentos (sin pasar por la shell: no hay inyección de comandos).
        // "-layout" respeta la disposición de columnas; "-" manda el resultado a la salida estándar.
        // Los descriptores [1 => pipe, 2 => pipe] capturan stdout (1) y stderr (2). Si falla, devuelve null
        // y extraerTexto() recurre a la librería smalot.
        $tmp = tempnam(sys_get_temp_dir(), 'ceg');
        if ($tmp === false || file_put_contents($tmp, $pdf) === false) {
            return null;
        }
        $texto = null;
        $proc = @proc_open(
            [self::PDFTOTEXT, '-layout', $tmp, '-'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            $salida = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $codigo = proc_close($proc);
            if ($codigo === 0 && is_string($salida) && $salida !== '') {
                $texto = $salida;
            }
        }
        @unlink($tmp);
        return $texto;
    }

    /**
     * Extrae y valida los datos. Lanza 'pdf_ilegible' si falta algo esencial y 'pdf_no_cuadra'
     * si los importes no suman (en ese caso no se guarda nada).
     *
     * @param string $numero dígitos del comprobante ("14120813"); si se indica debe aparecer en el texto
     */
    public function parsear(string $texto, string $numero = ''): array
    {
        // IDEA CENTRAL del parser: en vez de recorrer el PDF línea por línea, se aplanan todos los espacios y saltos
        // de línea a un único espacio y se buscan los datos con expresiones regulares (preg_match). Así el resultado
        // no depende de cómo cada extractor corte las líneas.
        // Todo en una sola línea: no depende de cómo el extractor corte las líneas.
        $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        // Se descartan textos sueltos que algunos PDF traen metidos entre las filas (ruta del archivo, etc.).
        $texto = (string)preg_replace('~(?:file|https?):///?\S+~u', ' ', $texto);
        $t = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $texto));  

        if ($numero !== '' && !str_contains($t, $numero)) {
            throw new RuntimeException('pdf_ilegible');
        }

        // Cargo fijo y tramos de energía, con su ponderación entre el cuadro actual y el anterior.
        // El nombre del tramo (Primeros, Siguientes, 2dos.Siguientes...) puede variar: lo que manda
        // es la unidad ("Dias" = cargo fijo, "$/kWh" = energía).
        // Esta regex reconoce cada línea de detalle de la factura (cargo fijo y tramos de energía). Esquema:
        //   (20/31) x (PRECIO_ACTUAL) + (11/31 x PRECIO_ANTERIOR) = PONDERADO  NOMBRE  UNIDADES  UNIDAD  OTRO_VALOR  IMPORTE
        // Grupos que se usan: 1-2 peso del mes actual, 3 precio actual, 6 precio anterior, 7 precio ponderado,
        // 8 nombre del concepto, 9 unidades (kWh o días), 10 unidad ("Dias" = cargo fijo, "$/kWh" = energía),
        // 12 importe (el grupo 11 no se usa).
        //  - [\d.,]+  = número con puntos de miles y coma decimal ("2.815,90").
        //  - (.{1,40}?) = el nombre del concepto, lo más corto posible (el "?" lo hace "perezoso").
        //  - ~u = la expresión trabaja en UTF-8.
        $patron = '~\((\d+)/(\d+)\) x \(([\d.,]+)\) \+ \((\d+)/(\d+) x ([\d.,]+)\) = ([\d.,]+) '
                . '(.{1,40}?) ([\d.,]+) (Dias|\$/kWh) ([\d.,]+) ([\d.,]+)~u';
        if (preg_match_all($patron, $t, $m, PREG_SET_ORDER) < 1) {
            error_log('CEG PDF: no se encontraron las líneas de detalle. Texto leído (primeros 600 caracteres): '
                . preg_replace('/[^\x20-\x7E]/', '?', substr($t, 0, 600)));
            throw new RuntimeException('pdf_ilegible');
        }

        // Por cada línea de detalle se guarda el concepto y se acumulan dos totales:
        //  - $sumaConceptos: se compara después contra el básico del PDF (validación);
        //  - $kwh: solo suma las líneas en "$/kWh"; la línea en "Dias" es el cargo fijo y no tiene kWh.
        $conceptos = [];
        $kwh = 0.0;
        $sumaConceptos = 0.0;
        foreach ($m as $l) {
            $conceptos[] = [
                'concepto'        => trim($l[8]),
                'peso_actual'     => $l[1] . '/' . $l[2],
                'precio_actual'   => $this->num($l[3]),
                'precio_anterior' => $this->num($l[6]),
                'ponderado'       => $this->num($l[7]),
                'unidades'        => $this->num($l[9]),
                'importe'         => $this->num($l[12]),
            ];
            $sumaConceptos += $this->num($l[12]);
            if ($l[10] === '$/kWh') {
                $kwh += $this->num($l[9]);
            }
        }

        // Totales del PDF, cada uno con su regex; buscar() devuelve el primer grupo como número, o null si no está.
        $basico = $this->buscar('~TOTAL BASICO-CARGOS POR ENERGIA ([\d.,]+)~u', $t);
        $tasa   = $this->buscar('~Tasa Municipal \S+ [\d.,]+ % ([\d.,]+)~u', $t);
        // (?<!- ) es un "lookbehind negativo": no toma el texto si justo antes hay "- ". Sirve para no confundir
        // este IVA (el del consumo) con la línea "Cargos - I.V.A Consumidor Final" (IVA de los cargos propios).
        $iva    = $this->buscar('~(?<!- )I\.V\.A Consumidor Final [\d.,]+ % ([\d.,]+)~u', $t);
        $total  = $this->buscar('~TOTAL A PAGAR HASTA EL \d{1,2}/\d{1,2}/\d{4} \$([\d.,]+)~u', $t);
        if ($basico === null || $tasa === null || $iva === null || $total === null) {
            error_log('CEG PDF: faltan campos: ' . json_encode(
                ['basico' => $basico, 'tasa' => $tasa, 'iva' => $iva, 'total' => $total]
            ));
            throw new RuntimeException('pdf_ilegible');
        }

        // Cargos propios de la cooperativa ("Cargo - Mora por Facturas anteriores", "Cargo - A.C. (...)", etc.).
        // Su IVA va en una sola línea al final ("Cargos - I.V.A Consumidor Final").
        $mora = 0.0;
        $otrosCargos = [];
        if (preg_match_all('~Cargo - (.{1,60}?) \$ ([\d.,]+)~u', $t, $x, PREG_SET_ORDER)) {
            foreach ($x as $c) {
                // stripos(...) === 0: el texto EMPIEZA con "Mora" (posición 0, sin distinguir mayúsculas).
                // Se compara con === porque stripos devuelve false si no lo encuentra y 0 == false en PHP.
                if (stripos($c[1], 'Mora') === 0) {
                    $mora += $this->num($c[2]);
                } else {
                    $otrosCargos[] = ['concepto' => 'Cargo - ' . trim($c[1]), 'importe' => $this->num($c[2])];
                }
            }
        }
        $sumaOtros = array_sum(array_column($otrosCargos, 'importe'));
        $ivaMora = $this->buscar('~Cargos - I\.V\.A Consumidor Final [\d.,]+ % ([\d.,]+)~u', $t) ?? 0.0;
        $dev     = $this->buscar('~Dev\.Calidad Servicio [^$]*\$ (-?[\d.,]+)~u', $t) ?? 0.0;

        // Control de coherencia, la defensa contra leer mal el PDF:
        //   básico = suma de los conceptos
        //   total  = básico + tasa municipal + IVA + mora + otros cargos + IVA de cargos + devolución por calidad
        // Si algo no cuadra (más allá de TOLERANCIA, $0,05 por redondeos de la CEG) se lanza 'pdf_no_cuadra' y NO se
        // guarda nada: es preferible no tener el dato a guardar uno mal leído.
        // Validación: los conceptos suman el básico y todo suma el total.
        if (abs($sumaConceptos - $basico) > self::TOLERANCIA
            || abs($basico + $tasa + $iva + $mora + $sumaOtros + $ivaMora + $dev - $total) > self::TOLERANCIA) {
            error_log('CEG PDF: los importes no cuadran');
            throw new RuntimeException('pdf_no_cuadra');
        }

        foreach ($otrosCargos as $c) {
            $conceptos[] = ['concepto' => $c['concepto'], 'importe' => $c['importe']];
        }
        
        // Opcionales: según el extractor pueden no aparecer.
        $lecturaAnt = $lecturaAct = null;
        // Busca dos pares "fecha + lectura del medidor" seguidos (anterior y actual), p. ej.
        //   "01/08/2026 24.200,35 01/09/2026 24.350,10".
        // [\d.]*\d,\d+ = número con miles opcionales y coma decimal. num() lo convierte a float.
        if (preg_match('~(\d{1,2}/\d{1,2}/\d{4}) ([\d.]*\d,\d+) (\d{1,2}/\d{1,2}/\d{4}) ([\d.]*\d,\d+)~u', $t, $e)) {
            $lecturaAnt = $this->num($e[2]);
            $lecturaAct = $this->num($e[4]);
        }
        $dias = preg_match('~DIAS LECTURA (\d+)~u', $t, $d) ? (int)$d[1] : null;

        return [
            'kwh'              => round($kwh, 2),
            'lectura_anterior' => $lecturaAnt,
            'lectura_actual'   => $lecturaAct,
            'dias_lectura'     => $dias,
            'basico'           => $basico,
            'tasa_municipal'   => $tasa,
            'iva'              => $iva,
            'mora'             => $mora,
            'iva_mora'         => $ivaMora,
            'dev_calidad'      => $dev,
            'total'            => $total,
            'conceptos'        => $conceptos,
        ];
    }

    /** "2.815,90" -> 2815.90 ; "-0,02" -> -0.02 */
    private function num(string $s): float
    {
        return (float)str_replace(',', '.', str_replace('.', '', $s));
    }

    private function buscar(string $patron, string $t): ?float
    {
        return preg_match($patron, $t, $m) === 1 ? $this->num($m[1]) : null;
    }

    /**
     * Descarga, lee y guarda el PDF de una factura. Devuelve null si salió bien o el código de error.
     * La usan el botón "Leer PDF" del panel y la tarea programada.
     */
    public function procesarFactura(Factura $modelo, array $factura, int $usuario_id): ?string
    {
        try {
            $nombre = $this->nombreArchivo((string)$factura['comprobante']);
            if ($nombre === null) {
                return 'pdf_descarga';
            }
            $texto = $this->extraerTexto($this->descargar($nombre));
            // substr($nombre, 4) quita el prefijo "FAC-" y deja solo los dígitos. parsear() exige encontrarlos dentro
            // del texto: comprueba que el PDF leído corresponde de verdad a esa factura.
            $datos = $this->parsear($texto, substr($nombre, 4));
            // Convención del método: devuelve null si todo salió bien, o un código de error ('pdf_bd', 'pdf_descarga', ...).
            return $modelo->guardarDetallePdf((int)$factura['id'], $usuario_id, $datos) ? null : 'pdf_bd';
        } catch (RuntimeException $e) {
            error_log('PDF CEG: ' . $e->getMessage());
            return in_array($e->getMessage(), ['pdf_descarga', 'pdf_ilegible', 'pdf_no_cuadra'], true)
                ? $e->getMessage() : 'pdf_descarga';
        }
    }

    /**
     * Lee los PDF de las facturas de un usuario que todavía no se leyeron. La usan la sincronización
     * del panel y la tarea programada.
     *
     * @param int           $maximo   tope de PDF por llamada; los que queden se leen en la próxima sincronización
     * @param callable|null $alFallar function(array $factura, string $codigoError): void
     * @return array{leidas:int, fallidas:int, quedan:bool}
     */
    public function leerPendientes(Factura $modelo, int $usuario_id, int $maximo = 12, ?callable $alFallar = null): array
    {
        $leidas = 0;
        $fallidas = 0;
        $quedan = false;
        $descargasFallidasSeguidas = 0;

        foreach ($modelo->obtenerPorUsuario($usuario_id) as $factura) {
            // Se saltean: las facturas ya leídas y las que ya fallaron MAX_INTENTOS veces (para no insistir con un PDF roto).
            if (!empty($factura['pdf_leido_en']) || (int)($factura['pdf_intentos'] ?? 0) >= self::MAX_INTENTOS) {
                continue;
            }
            if ($this->nombreArchivo((string)$factura['comprobante']) === null) {
                continue; // cargada a mano: no tiene PDF en la CEG
            }
            // Tope por llamada: desde el panel son pocas (el usuario espera la página); el resto queda para la próxima
            // sincronización o para el cron. 'quedan' se lo avisa a quien llamó.
            if ($leidas + $fallidas >= $maximo) {
                $quedan = true;
                break;
            }

            usleep(self::PAUSA_US);
            $error = $this->procesarFactura($modelo, $factura, $usuario_id);
            if ($error === null) {
                $leidas++;
                $descargasFallidasSeguidas = 0;
                continue;
            }

            $fallidas++;
            $modelo->registrarIntentoPdf((int)$factura['id'], $usuario_id);
            if ($alFallar !== null) {
                $alFallar($factura, $error);
            }
            // Corte de seguridad: tres descargas fallidas SEGUIDAS indican que la CEG no responde; se corta para no
            // seguir golpeando el servidor. Cualquier éxito (o error de otro tipo) reinicia el contador.
            $descargasFallidasSeguidas = $error === 'pdf_descarga' ? $descargasFallidasSeguidas + 1 : 0;
            if ($descargasFallidasSeguidas >= 3) {
                $quedan = true; // la CEG no responde: se reintenta en la próxima sincronización
                break;
            }
        }
        return ['leidas' => $leidas, 'fallidas' => $fallidas, 'quedan' => $quedan];
    }
}