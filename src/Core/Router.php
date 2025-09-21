<?php
namespace App\Core;

use Closure;

class Router {
    private array $routes = [];
    public function __construct(private Container $c){}

    public function add(string $method, string $path, $action, array $middleware=[]): void {
        $this->routes[] = compact('method','path','action','middleware');
    }

    public function dispatch(Request $req): Response {
        foreach ($this->routes as $r) {
            if ($req->method !== $r['method']) continue;
            $pattern = '#^' . preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $r['path']) . '$#';
            if (preg_match($pattern, $req->uri, $m)) {
                foreach ($m as $k=>$v) if (!is_int($k)) $req->params[$k]=$v;
                $handler = $this->resolve($r['action']);
                $pipeline = array_reverse($r['middleware']);
                $next = function($rq) use ($handler) { return $handler($rq); };
                foreach ($pipeline as $mw) {
                    $mwCallable = $this->resolve($mw);
                    $prevNext = $next;
                    $next = function($rq) use ($mwCallable, $prevNext) {
                        return $mwCallable($rq, $prevNext);
                    };
                }
                return $next($req);
            }
        }
        return Response::json(['message'=>'Not Found'],404);
    }

private function resolve($action): \Closure {
    // 1) Raw closure
    if ($action instanceof \Closure) return $action;

    // 2) ["Class","method"] callable arrays
    if (is_array($action) && is_callable($action)) {
        return fn($req) => \call_user_func($action, $req);
    }

    // 3) already-callable (function name / invokable object)
    if (is_callable($action)) {
        return $action(...);
    }

    // 4) "FQCN@method" string (e.g., App\Controllers\AuthController@me)
    if (is_string($action) && \str_contains($action, '@')) {
        [$class, $method] = \explode('@', $action, 2);
        $instance = $this->c->make($class);
        return fn($req) => $instance->$method($req);
    }

    // 5) Plain class name string with __invoke middleware (e.g., App\Middleware\Auth::class)
    if (is_string($action) && \class_exists($action)) {
        $instance = $this->c->make($action);
        if (\method_exists($instance, '__invoke')) {
            return fn($req, $next = null) => $instance($req, $next);
        }
    }

    // 6) Log for debugging
    error_log('[Router] Invalid route handler: '.print_r($action, true));
    return fn($req) => \App\Core\Response::json(['message' => 'Invalid route handler'], 500);
}

}
