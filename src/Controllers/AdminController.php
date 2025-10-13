<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use function App\Core\env;

class AdminController {
    public function __construct(private Connection $db){}

    private function isAdmin(array $user): bool {
        return ($user['role'] ?? '') === 'admin';
    }

    // List orders
    public function orders(Request $r): Response {
        if (!$this->isAdmin($r->user))
            return Response::json(['message'=>'Forbidden'],403);

        $status = $r->query['status'] ?? null;
        $sql = "SELECT id, order_number, user_id, status, total_due, placed_at FROM orders";
        $params = [];

        if ($status) {
            $sql .= " WHERE status=?";
            $params[] = $status;
        }

        $sql .= " ORDER BY id DESC LIMIT 100";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);

        return Response::json($stmt->fetchAll());
    }

    // Confirm an order
    public function confirm(Request $r): Response {
        if (!$this->isAdmin($r->user))
            return Response::json(['message'=>'Forbidden'],403);

        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            // Fetch order meta
            $metaStmt = $pdo->prepare("SELECT order_number, status FROM orders WHERE id=? LIMIT 1");
            $metaStmt->execute([$id]);
            $meta = $metaStmt->fetch();

            if (!$meta) {
                throw new \Exception('Order not found');
            }

            if ($meta['status'] !== 'PLACED') {
                throw new \Exception('Only orders with status PLACED can be confirmed');
            }

            $orderNumber = $meta['order_number'];

            // Fetch order items
            $itemsStmt = $pdo->prepare("SELECT id, product_id, start_date, end_date FROM order_items WHERE order_id=?");
            $itemsStmt->execute([$id]);
            $items = $itemsStmt->fetchAll();

            if (!$items) {
                throw new \Exception('Order has no items');
            }

            $buffer = (int)env('BUFFER_DAYS',1);

            // Allocate inventory
            foreach ($items as $item) {
                $endBuf = date('Y-m-d', strtotime($item['end_date'] . " +{$buffer} days"));

                $lockStmt = $pdo->prepare("
                    SELECT iu.id
                    FROM inventory_units iu
                    WHERE iu.product_id = :pid
                    AND NOT EXISTS (
                        SELECT 1
                        FROM availability_blocks ab
                        WHERE ab.inventory_unit_id = iu.id
                          AND ab.start_date <= :end_buf
                          AND ab.end_date >= :start
                    )
                    LIMIT 1 FOR UPDATE
                ");
                $lockStmt->execute([
                    ':pid' => $item['product_id'],
                    ':start' => $item['start_date'],
                    ':end_buf' => $endBuf
                ]);

                $unit = $lockStmt->fetch();

                if (!$unit) {
                    throw new \Exception(
                        "No available inventory for product {$item['product_id']} " .
                        "from {$item['start_date']} to {$endBuf}"
                    );
                }

                // Assign unit to order item
                $pdo->prepare("UPDATE order_items SET inventory_unit_id=? WHERE id=?")
                    ->execute([$unit['id'], $item['id']]);

                // Block inventory
                $pdo->prepare("
                    INSERT INTO availability_blocks(
                        inventory_unit_id, start_date, end_date, type, order_id
                    ) VALUES (?, ?, ?, 'RENTAL', ?)
                ")->execute([$unit['id'], $item['start_date'], $endBuf, $id]);
            }

            // Update order status
            $pdo->prepare("UPDATE orders SET status='CONFIRMED' WHERE id=?")->execute([$id]);
            $pdo->commit();

            return Response::json([
                'order_id' => $id,
                'order_number' => $orderNumber,
                'status' => 'CONFIRMED'
            ]);

        } catch (\Throwable $e) {
            $pdo->rollBack();
            return Response::json([
                'message' => 'Confirm failed',
                'error' => $e->getMessage()
            ], 400);
        }
    }

    // Mark order as delivered
    public function markDelivered(Request $r): Response {
        if (!$this->isAdmin($r->user))
            return Response::json(['message'=>'Forbidden'],403);

        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();

        $pdo->prepare("UPDATE orders SET status='DELIVERED' WHERE id=? AND status='CONFIRMED'")->execute([$id]);
        $meta = $pdo->prepare("SELECT order_number FROM orders WHERE id=? LIMIT 1");
        $meta->execute([$id]);
        $orderNumber = $meta->fetchColumn() ?: null;

        return Response::json([
            'order_id'=>$id,
            'order_number'=>$orderNumber,
            'status'=>'DELIVERED'
        ]);
    }
}
