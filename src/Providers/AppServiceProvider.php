<?php
namespace App\Providers;

use App\Core\Container;
use App\Database\Connection;
use App\Security\JWT;
use function App\Core\env;

class AppServiceProvider {
    public function register(Container $c): void {
        $c->bind(Connection::class, function() { return new Connection(); });
        $c->bind(JWT::class, function() { return new JWT(env('JWT_SECRET','secret'), (int)env('JWT_TTL_MIN', 1440)); });
    }
}
