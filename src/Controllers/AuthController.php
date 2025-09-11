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
        $stmt = $pdo->prepare("INSERT INTO users(name,email,phone,password_hash,role,created_at) VALUES (?,?,?,?, 'customer', NOW())");
        $stmt->execute([$r->body['name'],$r->body['email'],$r->body['phone']??null,$hash]);
        return Response::json(['message'=>'Registered']);
    }

    public function login(Request $r): Response {
        $err = Validator::required($r->body, ['email','password']);
        if ($err) return Response::json(['errors'=>$err],422);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id,name,email,role,password_hash FROM users WHERE email=? LIMIT 1");
        $stmt->execute([$r->body['email']]);
        $u = $stmt->fetch();
        if (!$u || !password_verify($r->body['password'], $u['password_hash'])) {
            return Response::json(['message'=>'Invalid credentials'],401);
        }
        $token = $this->jwt->encode(['sub'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]);
        return Response::json(['token'=>$token,'user'=>['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']]]);
    }

    public function me(Request $r): Response {
        return Response::json(['user'=>$r->user]);
    }
}
