<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class OrderController {
    public function __construct(private Connection $db){}

    public function list(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $stmt = $this->db->pdo()->prepare("SELECT id,status,total_due,placed_at FROM orders WHERE user_id=? ORDER BY id DESC");
        $stmt->execute([$uid]);
        return Response::json($stmt->fetchAll());
    }
    public function show(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $o = $pdo->prepare("SELECT * FROM orders WHERE id=? AND user_id=?");
        $o->execute([$id,$uid]);
        $order = $o->fetch();
        if (!$order) return Response::json(['message'=>'Not found'],404);
        $it = $pdo->prepare("SELECT oi.*, p.title FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE order_id=?");
        $it->execute([$id]);
        $order['items'] = $it->fetchAll();
        return Response::json($order);
    }
    public function cancel(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->prepare("UPDATE orders SET status='CANCELLED' WHERE id=? AND user_id=? AND status='PLACED'")->execute([$id,$uid]);
        return Response::json(['message'=>'Cancelled if eligible']);
    }
}
