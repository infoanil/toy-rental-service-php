<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use App\Support\Validator;

class AddressController {
    public function __construct(private Connection $db){}
    public function list(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $stmt = $this->db->pdo()->prepare("SELECT * FROM addresses WHERE user_id=? ORDER BY id DESC");
        $stmt->execute([$uid]);
        return Response::json($stmt->fetchAll());
    }
    public function store(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $err = Validator::required($r->body, ['line1','city','state','pincode']);
        if ($err) return Response::json(['errors'=>$err],422);
        $stmt = $this->db->pdo()->prepare("INSERT INTO addresses(user_id,line1,city,state,pincode,is_default) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$uid,$r->body['line1'],$r->body['city'],$r->body['state'],$r->body['pincode'], (int)($r->body['is_default']??0)]);
        return Response::json(['id'=>$this->db->pdo()->lastInsertId()]);
    }
}
