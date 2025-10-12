<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class OrderController {
    public function __construct(private Connection $db){}

    private function generateOrderNumber(\PDO $pdo): string {
        do {
            $candidate = 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $check = $pdo->prepare("SELECT id FROM orders WHERE order_number=? LIMIT 1");
            $check->execute([$candidate]);
        } while ($check->fetch());
        return $candidate;
    }

    public function create(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $pdo = $this->db->pdo();

        $pdo->beginTransaction();
        try {
            $cartStmt = $pdo->prepare("SELECT id FROM carts WHERE user_id=? LIMIT 1");
            $cartStmt->execute([$uid]);
            $cart = $cartStmt->fetch();
            if (!$cart) {
                throw new \RuntimeException('Cart is empty');
            }
            $cartId = (int)$cart['id'];

            $itemsStmt = $pdo->prepare("
                SELECT ci.product_id, ci.plan_id, ci.start_date, ci.end_date, pp.price_inr
                FROM cart_items ci
                JOIN product_plans pp ON pp.id = ci.plan_id
                WHERE ci.cart_id = ?
            ");
            $itemsStmt->execute([$cartId]);
            $items = $itemsStmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$items) {
                throw new \RuntimeException('Cart is empty');
            }

            $total = array_sum(array_map(fn($it) => (int)$it['price_inr'], $items));
            $orderNumber = $this->generateOrderNumber($pdo);

            $orderStmt = $pdo->prepare("
                INSERT INTO orders(order_number,user_id,address_id,status,payment_mode,delivery_fee,total_due,placed_at)
                VALUES (?,?,?,?,?,?,?,NOW())
            ");
            $orderStmt->execute([
                $orderNumber,
                $uid,
                null,
                'PLACED',
                'IN_PERSON',
                0,
                $total
            ]);

            $orderId = (int)$pdo->lastInsertId();

            $itemInsert = $pdo->prepare("
                INSERT INTO order_items(order_id,product_id,plan_id,start_date,end_date,item_price)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($items as $item) {
                $itemInsert->execute([
                    $orderId,
                    (int)$item['product_id'],
                    (int)$item['plan_id'],
                    $item['start_date'],
                    $item['end_date'],
                    (int)$item['price_inr']
                ]);
            }

            $pdo->prepare("DELETE FROM cart_items WHERE cart_id=?")->execute([$cartId]);
            $pdo->commit();

            return Response::json([
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'status' => 'PLACED',
                'total_due' => $total
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::json([
                'message' => 'Unable to place order',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function list(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $stmt = $this->db->pdo()->prepare("
            SELECT id,order_number,status,total_due,placed_at
            FROM orders
            WHERE user_id=?
            ORDER BY id DESC
        ");
        $stmt->execute([$uid]);
        return Response::json($stmt->fetchAll());
    }
    public function show(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $o = $pdo->prepare("
            SELECT id, order_number, status, payment_mode, delivery_fee, total_due, placed_at
            FROM orders
            WHERE id=? AND user_id=?
        ");
        $o->execute([$id,$uid]);
        $order = $o->fetch();
        if (!$order) return Response::json(['message'=>'Not found'],404);
        $it = $pdo->prepare("
            SELECT
                oi.id,
                oi.product_id,
                oi.plan_id,
                oi.start_date,
                oi.end_date,
                oi.item_price,
                p.title,
                pp.duration_days
            FROM order_items oi
            JOIN products p ON p.id=oi.product_id
            JOIN product_plans pp ON pp.id=oi.plan_id
            WHERE order_id=?
        ");
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
