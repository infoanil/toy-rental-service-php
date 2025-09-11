<?php
namespace App\Support;

class Validator {
    public static function required(array $data, array $fields): array {
        $errors = [];
        foreach ($fields as $f) if (!isset($data[$f]) || $data[$f]==='') $errors[$f] = 'required';
        return $errors;
    }
    public static function email(string $email): bool {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}
