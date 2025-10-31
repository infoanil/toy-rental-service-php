<?php
declare(strict_types=1);

// ---------------------------
// CORS CONFIGURATION
// ---------------------------
$allowedOrigin = 'http://localhost:3000';
$allowedMethods = 'GET, POST, PUT, DELETE, OPTIONS';
$allowedHeaders = 'Content-Type, Authorization, X-Requested-With';
$allowCredentials = 'true';

// Always set CORS headers
header("Access-Control-Allow-Origin: $allowedOrigin");
header("Access-Control-Allow-Methods: $allowedMethods");
header("Access-Control-Allow-Headers: $allowedHeaders");
header("Access-Control-Allow-Credentials: $allowCredentials");

// Handle preflight requests (very important)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204); // No content
    exit();
}

// ---------------------------
// ERROR HANDLING
// ---------------------------
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');

set_exception_handler(function(Throwable $e) {
    $msg = sprintf(
        "[%s] %s in %s:%d\nStack trace:\n%s\n",
        date('c'),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($msg);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Internal Server Error']);
});

// ---------------------------
// BOOTSTRAP APP
// ---------------------------
require __DIR__ . '/../vendor/autoload.php';

use App\Core\{Env,Container,Router,Request,Response};
use App\Providers\AppServiceProvider;

Env::load(__DIR__ . '/../.env');

$container = new Container();
(new AppServiceProvider())->register($container);

$request = Request::capture();
$router  = new Router($container);

// Load routes
require __DIR__ . '/../routes/api.php';

$response = $router->dispatch($request);
$response->send();
