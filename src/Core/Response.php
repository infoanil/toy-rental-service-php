<?php
namespace App\Core;

class Response {
    public int $status;
    public array $headers = ['Content-Type' => 'application/json'];
    public string $body;

    public function __construct($data=null, int $status=200){
        $this->status = $status;
        $this->body = json_encode($data ?? new \stdClass(), JSON_UNESCAPED_SLASHES);
    }
    public function send(): void {
        http_response_code($this->status);
        foreach ($this->headers as $k=>$v) header("$k: $v");
        echo $this->body;
    }
    public static function json($data, int $status=200): self { return new self($data, $status); }
}
