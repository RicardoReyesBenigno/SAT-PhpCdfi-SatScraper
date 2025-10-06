<?php declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php'; 
use GuzzleHttp\Client;
use PhpCfdi\Credentials\Credential;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\ResourceType;
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
    $insecureClient = new Client(['verify' => '/etc/ssl/certs/ca-certificates.crt']);
    $gateway = new SatHttpGateway($insecureClient);

    // =============================
    // Crear scraper con FIEL
    // =============================
    $satScraper = new SatScraper(FielSessionManager::create($credential), $gateway);

    // =============================
    // Construir query
    // =============================
    $query = new QueryByFilters(new DateTimeImmutable($fechaInicio), new DateTimeImmutable($fechaFinal));
    $query
        ->setDownloadType(DownloadType::$tipo())
        ->setStateVoucher(StatesVoucherOption::todos());

    // =============================
    // Ejecutar consulta
    // =============================
    $list = $satScraper->listByPeriod($query);

    // =============================
    // Carpeta de descargas
    // =============================
    $downloadPath = __DIR__ . '/storage/downloads/';
    if (!is_dir($downloadPath)) mkdir($downloadPath, 0777, true);

    // Descargar XML
    $downloadedXmlUuids = $satScraper
        ->resourceDownloader(ResourceType::xml(), $list, 10)
        ->saveTo($downloadPath);

    $items = [];
    foreach ($list as $uuid => $metadata) {
        $xmlFile = "{$downloadPath}/{$uuid}.xml";
        if (file_exists($xmlFile)) {
            
            // SOLO LO ESENCIAL - el XML y el estatus
            $xmlContent = file_get_contents($xmlFile);
            
            // Determinar estado (1 = Vigente, 0 = Cancelado)
            $estado = $metadata->estatus;
            $estatusSat = ($estado === 'Vigente' || $estado === '1') ? '1' : '0';
            
            $items[] = [
                'nombre' => "{$uuid}.xml",
                'contenido_base64' => base64_encode($xmlContent),
                'estatus' => $estatusSat, // 1 o 0
            ];
        }
    }

    echo json_encode([
        'exito' => true,
        'mensaje' => 'Consulta exitosa - ' . count($items) . ' comprobantes encontrados',
        'errores' => [],
        'xml_descargados' => $downloadedXmlUuids,
        'items' => $items
    ]);

} catch (SatException $e) {
    http_response_code(400);
    $msg = $e->getMessage();
    $errores = [$msg];

    // Mensajes amigables según contenido
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

    if (isset($downloadPath)) {
        $xmlFiles = glob($downloadPath . '*.xml');
        foreach ($xmlFiles as $file) {
            if (is_file($file)) unlink($file);
        }
    }
}