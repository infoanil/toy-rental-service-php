<?php
namespace App\Security;

class JWT {
    public function __construct(private string $secret, private int $ttlMin=1440){}
    public function encode(array $payload): string {
        $header = ['typ'=>'JWT','alg'=>'HS256'];
        $payload['exp'] = time() + ($this->ttlMin*60);
        $segments = [
            $this->b64(json_encode($header)),
            $this->b64(json_encode($payload))
        ];
        $signing_input = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing_input, $this->secret, true);
        $segments[] = $this->b64($signature);
        return implode('.', $segments);
    }
    public function decode(string $jwt): ?array {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $s] = $parts;
        $signature = $this->b64d($s);
        $valid = hash_equals($signature, hash_hmac('sha256', "$h.$p", $this->secret, true));
        if (!$valid) return null;
        $payload = json_decode($this->b64d($p), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) return null;
        return $payload;
    }
    private function b64(string $d): string { return rtrim(strtr(base64_encode($d), '+/','-_'), '='); }
    private function b64d(string $d): string { return base64_decode(strtr($d, '-_','+/')); }
}
