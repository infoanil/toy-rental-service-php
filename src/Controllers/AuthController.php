<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use App\Support\Validator;
use App\Security\JWT;

class AuthController {
    public function __construct(private Connection $db, private JWT $jwt){}

    public function register(Request $r): Response {
        $err = Validator::required($r->body, ['name','email','password']);
        if ($err) return Response::json(['errors'=>$err],422);
        if (!Validator::email($r->body['email'])) return Response::json(['errors'=>['email'=>'invalid']],422);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$r->body['email']]);
        if ($stmt->fetch()) return Response::json(['message'=>'Email exists'],409);

        $hash = password_hash($r->body['password'], PASSWORD_BCRYPT);

        $name    = $r->body['name'];
        $email   = $r->body['email'];
        $phone   = $r->body['phone']   ?? null;
        $city    = $r->body['city']    ?? null;
        $state   = $r->body['state']   ?? null;
        $zip     = $r->body['zip']     ?? null;
        $address = $r->body['address'] ?? null;
        $avatar  = $r->body['avatar']  ?? null;

        $sql = "INSERT INTO users(name,email,phone,city,state,zip,address,avatar,password_hash,role,created_at)
                VALUES (?,?,?,?,?,?,?,?,?, 'customer', NOW())";
        $pdo->prepare($sql)->execute([$name,$email,$phone,$city,$state,$zip,$address,$avatar,$hash]);

        return Response::json(['message'=>'Registered']);
    }

    public function login(Request $r): Response {
        $err = Validator::required($r->body, ['email','password']);
        if ($err) return Response::json(['errors'=>$err],422);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id,name,email,role,phone,city,state,zip,address,avatar,password_hash
                               FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$r->body['email']]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($r->body['password'], $u['password_hash'])) {
            return Response::json(['message'=>'Invalid credentials'],401);
        }

        // Keep token small; include only essential identity
        $token = $this->jwt->encode(['sub'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]);

        unset($u['password_hash']);
        return Response::json(['token'=>$token,'user'=>$u]);
    }

    public function me(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id,name,email,role,phone,city,state,zip,address,avatar,created_at
                               FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$uid]);
        $u = $stmt->fetch();
        if (!$u) return Response::json(['message'=>'Not found'],404);
        return Response::json(['user'=>$u]);
    }
}
