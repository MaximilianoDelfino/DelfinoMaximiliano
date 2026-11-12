<?php
declare(strict_types=1);

// Cliente de la API de la CEG (cooperativa eléctrica). El endpoint CtaCte devuelve en JSON las facturas
// pendientes de un número de usuario. Esta clase las convierte al formato de la tabla facturas.
// Los errores se informan con RuntimeException('api_caida' | 'api_respuesta_invalida'); el controlador
// y el cron las capturan y las traducen a un mensaje.
class SincronizadorCEG{
    private const API_URL = 'https://apiceg.ceg.org.ar:8886/CtaCte?USR=';

    public function consultarDeudaAPI(string $numero_usuario_ceg, Int $usuario_id): array{
        $url = self::API_URL . urlencode($numero_usuario_ceg);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        // cacert_ceg.pem: certificados con los que cURL verifica la identidad del servidor HTTPS de la CEG.
        // Así la verificación SSL queda ACTIVA (no se desactiva para "hacer que ande").
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/cacert_ceg.pem');

        $respuesta = curl_exec($ch);

        // Error de red, DNS, SSL o timeout
        if ($respuesta === false) {
            $detalle = curl_error($ch);
            curl_close($ch);
            error_log('CEG: error de cURL: ' . $detalle);
            throw new RuntimeException('api_caida');
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // El servidor respondió, pero con un error
        if ($httpCode !== 200) {
            error_log('CEG: código HTTP ' . $httpCode);
            throw new RuntimeException('api_caida');
        }

        // json_decode(..., true): devuelve arrays asociativos (con false devolvería objetos).
        $datosJSON = json_decode($respuesta, true);

        // Respondió 200, pero no es la lista de facturas esperada
        if (!is_array($datosJSON)) {
            error_log('CEG: respuesta inesperada: ' . substr($respuesta, 0, 500));
            throw new RuntimeException('api_respuesta_invalida');
        }

        $facturasFormateadas = [];

        // Campos de la API usados: CPBTE = comprobante, PERIODO, NIS, IVCMTO1/IVCMTO2 = importes de los dos
        // vencimientos, VCMTO1/VCMTO2 = fechas de esos vencimientos. "?? ''" evita errores si algún campo no viene.
        // Una factura sin comprobante o con fecha inválida se omite (y se deja registro en el log).
        foreach ($datosJSON as $recibe){
            $comprobante = trim((string)($recibe['CPBTE'] ?? ''));
            if ($comprobante === '') {
                error_log('Factura omitida por falta de comprobante: ' . json_encode($recibe));
                continue;
            }

            $fecha1 = $this->normalizarFecha((string)($recibe['VCMTO1'] ?? ''));
            if ($fecha1 === null) {
                error_log('Factura omitida por fecha inválida: ' . json_encode($recibe));
                continue;
            }

            $facturasFormateadas[] = [
                'usuario_id' => $usuario_id,
                'numero_usuario_ceg' => $numero_usuario_ceg,

                'comprobante' => $comprobante,
                'periodo' => (string)($recibe['PERIODO'] ?? ''),
                'nis' => (string)($recibe['NIS'] ?? ''),

                // El listado de la API no trae el consumo: queda en null y se completa después leyendo el PDF
                // (LectorFacturaPdf), o a mano.
                'kwh_consumidos' => null,

                'monto_vencimiento_1' => $this->normalizarMonto((string)($recibe['IVCMTO1'] ?? '')),
                'monto_vencimiento_2' => !empty($recibe['IVCMTO2']) ? $this->normalizarMonto((string)$recibe['IVCMTO2']) : null,

                'fecha_vencimiento_1' => $fecha1,
                'fecha_vencimiento_2' => $this->normalizarFecha((string)($recibe['VCMTO2'] ?? '')),
            ];
        }
        return $facturasFormateadas;
    }
    
    // La API puede mandar el importe como "1.234,56" (formato local) o "1234.56". Si hay coma, se quitan los
    // puntos de miles y la coma pasa a ser punto decimal, que es lo que entiende MySQL.
    private function normalizarMonto(string $valor): string {
    $valor = trim($valor);
    if ($valor === '') {
        return '0.00';
    }
    if (strpos($valor, ',') !== false) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    return $valor;
    }

    // Convierte "5/9/2026" (día/mes/año sin ceros) a "2026-09-05", el formato de MySQL.
    // El "!" del formato pone la hora en cero (si no, tomaría la hora actual). getLastErrors() detecta fechas
    // imposibles como 31/2/2026, que PHP "corregiría" en silencio. Devuelve null si no es una fecha válida.
    private function normalizarFecha(string $valor): ?string {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        $fecha = DateTime::createFromFormat('!j/n/Y', $valor);
        $errores = DateTime::getLastErrors();
        if ($fecha === false || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }
        return $fecha->format('Y-m-d');
    }
}