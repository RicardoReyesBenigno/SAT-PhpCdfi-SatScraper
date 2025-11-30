<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use PhpCfdi\Credentials\Credential;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Contracts\ResourceDownloadHandlerInterface;
use PhpCfdi\CfdiSatScraper\ResourceType;
use PhpCfdi\CfdiSatScraper\Exceptions\SatException;
use PhpCfdi\CfdiSatScraper\Exceptions\ResourceDownloadError;
use PhpCfdi\CfdiSatScraper\Exceptions\ResourceDownloadResponseError;
use PhpCfdi\CfdiSatScraper\Exceptions\ResourceDownloadRequestExceptionError;
use Psr\Http\Message\ResponseInterface;

// CORS básico
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: *');
    exit;
}

/**
 * Parser robusto de CFDI 3.3 / 4.0 usando solo SimpleXML
 */
function parseCfdiXml(string $xmlString): array
{
    $out = [
        'serie' => '',
        'folio' => '',
        'metodo_pago' => '',
        'forma_pago' => '',
        'uso_cfdi' => '',
        'moneda' => '',
        'subtotal' => 0.0,
        'descuento' => 0.0,
        'total_num' => 0.0,
        'traslado_iva_16' => 0.0,
        'traslado_iva_8' => 0.0,
        'total_imp_trasladado' => 0.0,
        'es_pago' => false,
        'pagos_num' => 0,
    ];

    try {
        $xml = @simplexml_load_string($xmlString);
        if (!$xml) {
            return $out;
        }

        // namespaces
        $xml->registerXPathNamespace('cfdi40', 'http://www.sat.gob.mx/cfd/4');
        $xml->registerXPathNamespace('cfdi33', 'http://www.sat.gob.mx/cfd/3');
        $xml->registerXPathNamespace('pago10', 'http://www.sat.gob.mx/Pagos');
        $xml->registerXPathNamespace('pago20', 'http://www.sat.gob.mx/Pagos20');

        // Comprobante (detectamos 4.0 o 3.3)
        $n = $xml->xpath('/cfdi40:Comprobante');
        $ns = 'cfdi40';
        if (!$n) {
            $n = $xml->xpath('/cfdi33:Comprobante');
            $ns = $n ? 'cfdi33' : $ns;
        }

        if ($n) {
            $c = $n[0];
            $attr = function (string $k) use ($c): string {
                return (string) ($c[$k] ?? '');
            };

            $out['serie']       = $attr('Serie') ?: $attr('serie');
            $out['folio']       = $attr('Folio') ?: $attr('folio');
            $out['metodo_pago'] = $attr('MetodoPago') ?: $attr('metodoDePago');
            $out['forma_pago']  = $attr('FormaPago') ?: $attr('formaDePago');
            $out['moneda']      = $attr('Moneda') ?: '';
            $out['subtotal']    = (float) ($attr('SubTotal') ?: 0);
            $out['descuento']   = (float) ($attr('Descuento') ?: 0);
            $out['total_num']   = (float) ($attr('Total') ?: 0);

            $tipoComp = $attr('TipoDeComprobante') ?: '';
            $out['es_pago'] = (strtoupper($tipoComp) === 'P');
        }

        // Receptor -> UsoCFDI
        $n = $xml->xpath(sprintf('/%s:Comprobante/%s:Receptor', $ns, $ns));
        if ($n) {
            $out['uso_cfdi'] = (string) ($n[0]['UsoCFDI'] ?? $n[0]['usoCFDI'] ?? '');
        }

        // Count pagos (pago10/pago20)
        $pagos10 = $xml->xpath('//pago10:Pagos/pago10:Pago');
        $pagos20 = $xml->xpath('//pago20:Pagos/pago20:Pago');
        $out['pagos_num'] =
            (is_array($pagos10) ? count($pagos10) : 0)
            + (is_array($pagos20) ? count($pagos20) : 0);
        if ($out['pagos_num'] > 0) {
            $out['es_pago'] = true;
        }

        // Impuestos trasladados IVA (002)
        // 1) Preferir nivel Comprobante (evita doble conteo)
        $top = $xml->xpath(sprintf(
            '/%s:Comprobante/%s:Impuestos/%s:Traslados/%s:Traslado',
            $ns,
            $ns,
            $ns,
            $ns
        ));
        $useConceptLevel = !($top && count($top) > 0);
        $tras = $top;

        // 2) Si no hay a nivel global, caer a nivel concepto
        if ($useConceptLevel) {
            $tras = $xml->xpath(sprintf(
                '//%s:Concepto/%s:Impuestos/%s:Traslados/%s:Traslado',
                $ns,
                $ns,
                $ns,
                $ns
            ));
        }

        if ($tras) {
            foreach ($tras as $t) {
                $tasa = (float) ($t['TasaOCuota'] ?? 0);
                $imp  = (float) ($t['Importe'] ?? 0);

                if (abs($tasa - 0.16) < 1e-6) {
                    $out['traslado_iva_16'] += $imp;
                } elseif (abs($tasa - 0.08) < 1e-6) {
                    $out['traslado_iva_8'] += $imp;
                }
            }
        }

        $out['total_imp_trasladado'] = $out['traslado_iva_16'] + $out['traslado_iva_8'];
    } catch (\Throwable $e) {
        // devolvemos defaults
    }

    return $out;
}

/**
 * Handler que guarda XMLs en memoria y errores por UUID
 */
final class MemoryXmlCollector implements ResourceDownloadHandlerInterface
{
    private array $xmlByUuid = [];
    private array $errorsByUuid = [];

    public function onSuccess(string $uuid, string $content, ResponseInterface $response): void
    {
        // $content ya es el body; lo guardamos directo
        $this->xmlByUuid[$uuid] = $content;
    }

    public function onError(ResourceDownloadError $error): void
    {
        if ($error instanceof ResourceDownloadRequestExceptionError) {
            $this->errorsByUuid[$error->getUuid()] = "Request error: " . $error->getMessage();
        } elseif ($error instanceof ResourceDownloadResponseError) {
            $this->errorsByUuid[$error->getUuid()] = "Response error: " . $error->getMessage();
        } else {
            $this->errorsByUuid[$error->getUuid()] = "Download error: " . $error->getMessage();
        }
    }

    public function getXmlMap(): array
    {
        return $this->xmlByUuid;
    }

    public function getError(string $uuid): ?string
    {
        return $this->errorsByUuid[$uuid] ?? null;
    }
}

try {
    // =============================
    // Validación de parámetros
    // =============================
    $requiredFiles = isset($_FILES['certificado_cer'], $_FILES['certificado_key']);
    $requiredPost  = isset($_POST['password'], $_POST['fecha_inicio'], $_POST['fecha_final'], $_POST['tipo']);

    if (!$requiredFiles || !$requiredPost) {
        echo json_encode([
            'exito' => false,
            'mensaje' => 'Faltan parámetros o archivos en la solicitud',
            'errores' => ['Se requieren: certificado_cer, certificado_key, password, fecha_inicio, fecha_final, tipo']
        ]);
        exit;
    }

    $password     = (string) $_POST['password'];
    $fechaInicio  = new DateTimeImmutable((string) $_POST['fecha_inicio']);
    $fechaFinal   = new DateTimeImmutable((string) $_POST['fecha_final']);
    $tipoStr      = strtolower((string) $_POST['tipo']); // 'emitidos'|'recibidos'
    $detallado    = isset($_POST['detallado']) ? filter_var($_POST['detallado'], FILTER_VALIDATE_BOOLEAN) : false;

    // OJO: aquí ahora se lee "max_detalles" que es lo que mandas desde Postman
    $maxDetallado = isset($_POST['max_detalles']) ? max(1, (int) $_POST['max_detalles']) : 50;

    $concurrency  = isset($_POST['concurrencia']) ? max(1, (int) $_POST['concurrencia']) : 25;
    $downloadType = ($tipoStr === 'emitidos') ? DownloadType::emitidos() : DownloadType::recibidos();

    // =============================
    // Guardar archivos temporales
    // =============================
    $storagePath = __DIR__ . '/storage/sat/';
    if (!is_dir($storagePath)) {
        @mkdir($storagePath, 0777, true);
    }

    $cerFile = $storagePath . uniqid('fiel_', true) . '.cer';
    $keyFile = $storagePath . uniqid('fiel_', true) . '.key';
    if (
        !@move_uploaded_file($_FILES['certificado_cer']['tmp_name'], $cerFile) ||
        !@move_uploaded_file($_FILES['certificado_key']['tmp_name'], $keyFile)
    ) {
        echo json_encode([
            'exito' => false,
            'mensaje' => 'No se pudieron guardar los archivos de la FIEL',
            'errores' => ['move_uploaded_file falló']
        ]);
        exit;
    }

    // =============================
    // Crear credencial FIEL
    // =============================
    $credential = Credential::openFiles($cerFile, $keyFile, $password);
    if (!$credential->isFiel()) {
        echo json_encode([
            'exito' => false,
            'mensaje' => 'El certificado no corresponde a una FIEL',
            'errores' => ['Certificado inválido']
        ]);
        exit;
    }
    if (!$credential->certificate()->validOn()) {
        echo json_encode([
            'exito' => false,
            'mensaje' => 'El certificado no está vigente',
            'errores' => ['Certificado vencido']
        ]);
        exit;
    }

    // =============================
    // Cliente y gateway SAT
    // =============================
    $http = new Client([
        'verify' => '/etc/ssl/certs/ca-certificates.crt',
        'timeout' => 600,
        'connect_timeout' => 15,
    ]);
    $gateway    = new SatHttpGateway($http);
    $satScraper = new SatScraper(
        \PhpCfdi\CfdiSatScraper\Sessions\Fiel\FielSessionManager::create($credential),
        $gateway
    );

    // =============================
    // Consulta de METADATA por periodo
    // =============================
    $query = new QueryByFilters($fechaInicio, $fechaFinal);
    $query->setDownloadType($downloadType);
    $list = $satScraper->listByPeriod($query);

    $items = [];
    $uuidsForDetail = [];

    foreach ($list as $k => $meta) {
        $uuid = method_exists($meta, 'uuid') ? $meta->uuid() : (string) $k;

        $get = function (string $name) use ($meta) {
            if (method_exists($meta, 'get')) {
                return $meta->get($name);
            }
            // acceso tipo propiedad por si acaso
            return $meta->{$name} ?? null;
        };

        // Normalizar estatus SAT
        $estadoRaw = (string) ($get('estadoComprobante') ?? '');
        $estadoLower = strtolower($estadoRaw);
        $estatusSat = null;
        $estadoDesc = 'Desconocido';

        if ($estadoRaw !== '') {
            if (
                str_contains($estadoLower, 'vigente') ||
                $estadoLower === '1' ||
                str_contains($estadoLower, 'no cancelado')
            ) {
                $estatusSat = '1';
                $estadoDesc = 'Vigente';
            } elseif (str_contains($estadoLower, 'cancel') || $estadoLower === '0') {
                $estatusSat = '0';
                $estadoDesc = 'Cancelado';
            } else {
                $estadoDesc = $estadoRaw;
            }
        }

        // Row base
        $row = [
            'uuid' => $uuid,
            'estatus' => $estatusSat,
            'estatus_descripcion' => $estadoDesc,
            'emisor' => [
                'rfc' => (string) $get('rfcEmisor'),
                'nombre' => (string) $get('nombreEmisor'),
            ],
            'receptor' => [
                'rfc' => (string) $get('rfcReceptor'),
                'nombre' => (string) $get('nombreReceptor'),
            ],
            'fecha_emision' => (string) $get('fechaEmision'),
            'fecha_certificacion' => (string) $get('fechaCertificacion'),
            'total' => (string) $get('total'),
            'efecto_comprobante' => (string) $get('efectoComprobante'), // Ingreso/Egreso/Pago/Nomina
            'tipo_comprobante' => ($tipoStr === 'emitidos') ? 'Emitidos' : 'Recibidos',

            // slots para detallado
            'serie' => '',
            'folio' => '',
            'metodo_pago' => '',
            'forma_pago' => '',
            'uso_cfdi' => '',
            'moneda' => '',
            'subtotal' => 0.0,
            'descuento' => 0.0,
            'total_num' => 0.0,
            'traslado_iva_16' => 0.0,
            'traslado_iva_8' => 0.0,
            'total_imp_trasladado' => 0.0,
            'es_pago' => false,
            'pagos_num' => 0,
        ];

        // === Fallback: rellenar algunos campos desde METADATA (sirve sobre todo para EMITIDOS) ===
        $serieMeta   = (string) ($get('serie') ?? '');
        $folioMeta   = (string) ($get('folio') ?? '');
        $monedaMeta  = (string) ($get('moneda') ?? '');
        $formaMeta   = (string) ($get('formaPago') ?? '');
        $metodoMeta  = (string) ($get('metodoPago') ?? '');
        $usoMeta     = (string) ($get('usoCFDI') ?? $get('usoCfdi') ?? '');

        $subMeta     = (float) ($get('subtotal') ?? 0);
        $descMeta    = (float) ($get('descuento') ?? 0);
        $totalMeta   = (float) ($get('total') ?? 0);

        if ($serieMeta !== '') {
            $row['serie'] = $serieMeta;
        }
        if ($folioMeta !== '') {
            $row['folio'] = $folioMeta;
        }
        if ($monedaMeta !== '') {
            $row['moneda'] = $monedaMeta;
        }
        if ($formaMeta !== '') {
            $row['forma_pago'] = $formaMeta;
        }
        if ($metodoMeta !== '') {
            $row['metodo_pago'] = $metodoMeta;
        }
        if ($usoMeta !== '') {
            $row['uso_cfdi'] = $usoMeta;
        }
        if ($subMeta > 0) {
            $row['subtotal'] = $subMeta;
        }
        if ($descMeta > 0) {
            $row['descuento'] = $descMeta;
        }
        if ($totalMeta > 0) {
            $row['total_num'] = $totalMeta;
        }

        $items[$uuid] = $row;

        // Lista acotada de UUIDs para intentar descarga de XML (detallado)
        if ($detallado && count($uuidsForDetail) < $maxDetallado) {
            $uuidsForDetail[] = $uuid;
        }
    }

    // =============================
    // DESCARGA DE XML (detallado)
    // =============================
    $descargados = 0;
    if ($detallado && count($uuidsForDetail) > 0) {
        // creamos una lista acotada por UUIDs para no bajar todo
        $subList = $satScraper->listByUuids($uuidsForDetail, $downloadType);

        $collector = new MemoryXmlCollector();
        $downloadedUuids = $satScraper
            ->resourceDownloader(ResourceType::xml(), $subList, $concurrency)
            ->download($collector);

        $xmlMap = $collector->getXmlMap();
        $descargados = is_array($downloadedUuids) ? count($downloadedUuids) : 0;

        foreach ($uuidsForDetail as $uuid) {
            if (!isset($items[$uuid])) {
                continue;
            }

            if (isset($xmlMap[$uuid])) {
                $extras = parseCfdiXml($xmlMap[$uuid]);
                // merge: lo que venga desde XML sobreescribe lo del metadata
                $items[$uuid] = array_merge($items[$uuid], $extras);
            } else {
                $err = $collector->getError($uuid) ?: 'No se pudo descargar el XML de este UUID';
                $items[$uuid]['detalle_error'] = $err;
            }
        }
    }

    // =============================
    // Ordenar por fecha de certificación desc
    // =============================
    $items = array_values($items);
    usort(
        $items,
        fn($a, $b) => strtotime($b['fecha_certificacion'] ?? '') <=> strtotime($a['fecha_certificacion'] ?? '')
    );

    echo json_encode([
        'exito' => true,
        'mensaje' => 'Consulta exitosa - ' . count($items) . ' comprobantes encontrados',
        'errores' => [],
        'items' => $items,
        'detallado' => $detallado,
        'descargas_xml' => $descargados,
    ]);
} catch (SatException $e) {
    http_response_code(400);
    echo json_encode([
        'exito' => false,
        'mensaje' => 'Error al consultar el SAT: ' . $e->getMessage(),
        'errores' => [$e->getMessage()],
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'exito' => false,
        'mensaje' => 'Error interno del sistema',
        'errores' => [$e->getMessage()],
    ]);
} finally {
    if (isset($cerFile) && file_exists($cerFile)) {
        @unlink($cerFile);
    }
    if (isset($keyFile) && file_exists($keyFile)) {
        @unlink($keyFile);
    }
}
