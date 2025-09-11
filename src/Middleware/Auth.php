<?php
namespace App\Middleware;
use App\Core\{Request,Response,Container};
use App\Security\JWT;
use function App\Core\env;

class Auth {
    public function __construct(private ?JWT $jwt=null){}
    public function __invoke(Request $req, callable $next): Response {
        $jwt = $this->jwt ?? (new \App\Security\JWT(env('JWT_SECRET','secret'), (int)env('JWT_TTL_MIN',1440)));
        $token = $req->bearerToken();
        if (!$token) return Response::json(['message'=>'Unauthorized'],401);
        $payload = $jwt->decode($token);
        if (!$payload) return Response::json(['message'=>'Invalid token'],401);
        $req->user = $payload;
        return $next($req);
    }
}
