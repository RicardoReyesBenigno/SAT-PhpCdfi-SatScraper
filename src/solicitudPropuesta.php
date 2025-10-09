<?php declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php'; 

use GuzzleHttp\Client;
use PhpCfdi\Credentials\Credential;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\StatesVoucherOption;
use PhpCfdi\CfdiSatScraper\Sessions\Fiel\FielSessionManager; 
use PhpCfdi\CfdiSatScraper\Exceptions\SatException;

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json'); 

try {
    if (!isset($_FILES['certificado_cer'], $_FILES['certificado_key'], $_POST['password'], $_POST['fecha_inicio'], $_POST['fecha_final'], $_POST['tipo'])) {
        die(json_encode([
            'exito' => false,
            'mensaje' => 'Faltan parámetros o archivos en la solicitud',
            'errores' => ['Parámetros incompletos']
        ]));
    }

    $password = $_POST['password'];
    $fechaInicio = $_POST['fecha_inicio'];
    $fechaFinal = $_POST['fecha_final'];
    $tipo = $_POST['tipo']; // emitidos | recibidos

    // =============================
    // Guardar archivos temporales
    // =============================
    $storagePath = __DIR__ . '/storage/sat/';
    if (!is_dir($storagePath)) mkdir($storagePath, 0777, true);

    $cerFile = $storagePath . basename($_FILES['certificado_cer']['name']);
    $keyFile = $storagePath . basename($_FILES['certificado_key']['name']);

    move_uploaded_file($_FILES['certificado_cer']['tmp_name'], $cerFile);
    move_uploaded_file($_FILES['certificado_key']['tmp_name'], $keyFile);

    // =============================
    // Crear credencial y validar FIEL
    // =============================
    $credential = Credential::openFiles($cerFile, $keyFile, $password);

    if (! $credential->isFiel()) {
        die(json_encode([
            'exito' => false,
            'mensaje' => 'El certificado no corresponde a una FIEL',
            'errores' => ['Certificado inválido']
        ]));
    }
    if (! $credential->certificate()->validOn()) {
        die(json_encode([
            'exito' => false,
            'mensaje' => 'El certificado no está vigente',
            'errores' => ['Certificado vencido']
        ]));
    }

    // =============================
    // Cliente y gateway SAT
    // =============================
    $insecureClient = new Client([
        'verify' => '/etc/ssl/certs/ca-certificates.crt',
        'timeout' => 600,
        'connect_timeout' => 10,
    ]);
    $gateway = new SatHttpGateway($insecureClient);

    // =============================
    // Crear scraper con FIEL
    // =============================
    $satScraper = new SatScraper(FielSessionManager::create($credential), $gateway);

    // =============================
    // Construir query - METADATA (vigentes y cancelados)
    // =============================
    $query = new QueryByFilters(new DateTimeImmutable($fechaInicio), new DateTimeImmutable($fechaFinal));
    $query
        ->setDownloadType(DownloadType::$tipo())
        ->setStateVoucher(StatesVoucherOption::todos());

    // =============================
    // Ejecutar consulta (solo metadata)
    // =============================
    $list = $satScraper->listByPeriod($query);
    error_log("Número de comprobantes encontrados: " . count($list));

    $items = [];
    foreach ($list as $uuid => $metadata) {
        $estadoRaw = $metadata->estadoComprobante;
        $estado = 'Desconocido';
        $estatusSat = null;

        if ($estadoRaw !== null) {
            $estadoRawLower = strtolower((string)$estadoRaw);
            if (strpos($estadoRawLower, 'vigente') !== false || $estadoRawLower === '1' || $estadoRawLower === 'no cancelado') {
                $estado = 'Vigente';
                $estatusSat = '1';
            } elseif (strpos($estadoRawLower, 'cancelado') !== false || $estadoRawLower === '0') {
                $estado = 'Cancelado';
                $estatusSat = '0';
            } else {
                $estado = $estadoRaw;
            }
        }

        $items[] = [
            'uuid' => $uuid,
            'estatus' => $estatusSat,
            'estatus_descripcion' => $estado,
            'emisor' => [
                'rfc' => $metadata->rfcEmisor,
                'nombre' => $metadata->nombreEmisor,
            ],
            'receptor' => [
                'rfc' => $metadata->rfcReceptor,
                'nombre' => $metadata->nombreReceptor,
            ],
            'fecha_emision' => $metadata->fechaEmision,
            'fecha_certificacion' => $metadata->fechaCertificacion,
            'total' => $metadata->total,
            'efecto_comprobante' => $metadata->efectoComprobante,
            'tipo_comprobante' => $tipo,
        ];
    }
    // ORDENAR POR FECHA DE CERTIFICACIÓN (más recientes primero)
    usort($items, function ($a, $b) {
        return strtotime($b['fecha_certificacion']) <=> strtotime($a['fecha_certificacion']);
    });
    echo json_encode([
        'exito' => true,
        'mensaje' => 'Consulta exitosa - ' . count($items) . ' comprobantes encontrados',
        'errores' => [],
        'items' => $items
    ]);

} catch (SatException $e) {
    http_response_code(400);
    $msg = $e->getMessage();
    $errores = [$msg];

    if (str_contains(strtolower($msg), 'expired')) {
        $userMsg = 'El certificado FIEL ha vencido.';
        $errores[] = 'Certificado vencido';
    } elseif (str_contains(strtolower($msg), 'credential')) {
        $userMsg = 'Credenciales SAT inválidas. Verifique RFC y contraseña.';
        $errores[] = 'Credenciales incorrectas';
    } else {
        $userMsg = 'Error al consultar el SAT: ' . $msg;
    }

    echo json_encode([
        'exito' => false,
        'mensaje' => $userMsg,
        'errores' => $errores
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'exito' => false,
        'mensaje' => 'Error interno del sistema. Contacte al administrador si el problema persiste.',
        'errores' => [$e->getMessage()]
    ]);
} finally {
    // =============================
    // Limpieza de archivos temporales
    // =============================
    if (isset($cerFile) && file_exists($cerFile)) unlink($cerFile);
    if (isset($keyFile) && file_exists($keyFile)) unlink($keyFile);
}
