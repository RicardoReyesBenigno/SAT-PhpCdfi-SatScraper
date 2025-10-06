<?php

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Si tu API está en una subcarpeta, ajusta el prefijo
$route = $uri;

switch ($route) {
    case '/api/solicitud':
        require __DIR__ . '/../src/solicitud.php';
        break;
    case '/api/solicitud-estatus':
        require __DIR__ . '/../src/solicitudEstatus.php';
        break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Ruta no encontrada']);
        break;
}
