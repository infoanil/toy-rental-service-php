<?php
namespace App\Core;

final class Env {
    public static function load(string $path): void {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) continue;
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $name = trim($name);
            $value = trim($value);
            if (!isset($_ENV[$name]) && $name !== '') putenv("$name=$value");
        }
    }
}
function env(string $key, $default=null){
    $v = getenv($key);
    return $v === false ? $default : $v;
}
