<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');   // don't show in browser
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');

// Optional: catch fatal errors too
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
    echo json_encode(['error' => 'Internal Server Error']);
});

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
