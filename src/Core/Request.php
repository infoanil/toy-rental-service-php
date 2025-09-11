<?php
namespace App\Core;

class Request {
    public string $method;
    public string $uri;
    public array $headers;
    public array $query;
    public array $body;
    public array $params = [];
    public ?array $user = null;

    public static function capture(): self {
        $r = new self();
        $r->method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $r->uri     = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $r->headers = function_exists('getallheaders') ? getallheaders() : [];
        $r->query   = $_GET ?? [];
        $input = file_get_contents('php://input');
        $json = json_decode($input, true);
        $r->body = is_array($json) ? $json : $_POST;
        return $r;
    }

    public function bearerToken(): ?string {
        $h = $this->headers['Authorization'] ?? $this->headers['authorization'] ?? null;
        if (!$h) return null;
        if (stripos($h, 'Bearer ') === 0) return substr($h, 7);
        return null;
    }
}
