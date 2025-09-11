<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class CartController {
    public function __construct(private Connection $db){}

    private function ensureCartId(int $userId): int {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT id FROM carts WHERE user_id=?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
        $pdo->prepare("INSERT INTO carts(user_id) VALUES(?)")->execute([$userId]);
        return (int)$pdo->lastInsertId();
    }

    public function get(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $cid = $this->ensureCartId($uid);
        $sql = "SELECT ci.id, p.title, p.id as product_id, pp.id as plan_id, pp.duration_days, pp.price_inr, ci.start_date, ci.end_date
                FROM cart_items ci
                JOIN products p ON ci.product_id=p.id
                JOIN product_plans pp ON ci.plan_id=pp.id
                WHERE ci.cart_id=?";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$cid]);
        return Response::json(['cart_id'=>$cid, 'items'=>$stmt->fetchAll()]);
    }

    public function addItem(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $cid = $this->ensureCartId($uid);
        $pid = (int)($r->body['product_id'] ?? 0);
        $planId = (int)($r->body['plan_id'] ?? 0);
        $start = $r->body['start_date'] ?? null;
        if (!$pid || !$planId || !$start) return Response::json(['message'=>'product_id, plan_id, start_date required'],422);
        $stmt = $this->db->pdo()->prepare("SELECT duration_days FROM product_plans WHERE id=? AND product_id=?");
        $stmt->execute([$planId,$pid]);
        $plan = $stmt->fetch();
        if (!$plan) return Response::json(['message'=>'Invalid plan'],422);
        $end = date('Y-m-d', strtotime("$start +".(((int)$plan['duration_days'])-1)." days"));
        $ins = $this->db->pdo()->prepare("INSERT INTO cart_items(cart_id,product_id,plan_id,start_date,end_date) VALUES (?,?,?,?,?)");
        $ins->execute([$cid,$pid,$planId,$start,$end]);
        return $this->get($r);
    }

    public function removeItem(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $cid = $this->ensureCartId($uid);
        $id = (int)($r->params['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare("DELETE FROM cart_items WHERE id=? AND cart_id=?");
        $stmt->execute([$id,$cid]);
        return $this->get($r);
    }
}
