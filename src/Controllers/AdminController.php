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

    // Confirm an order (inventory check removed)
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
            $itemsStmt = $pdo->prepare("SELECT id FROM order_items WHERE order_id=?");
            $itemsStmt->execute([$id]);
            $items = $itemsStmt->fetchAll();

            // Skip inventory allocation completely
            foreach ($items as $item) {
                $pdo->prepare("UPDATE order_items SET inventory_unit_id=NULL WHERE id=?")
                    ->execute([$item['id']]);
            }

            // Update order status to CONFIRMED
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

// Delete a single order
public function deleteOrder(Request $r): Response {
    if (!$this->isAdmin($r->user)) {
        return Response::json(['message'=>'Forbidden'],403);
    }

    $id = (int)($r->params['id'] ?? 0);
    if (!$id) {
        return Response::json(['message'=>'Order ID required'], 400);
    }

    $pdo = $this->db->pdo();

    // Optional: check if order exists
    $stmt = $pdo->prepare("SELECT id FROM orders WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return Response::json(['message'=>'Order not found'], 404);
    }

    // Delete order items first (foreign key safety)
    $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$id]);

    // Delete the order
    $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);

    return Response::json(['message'=>"Order $id deleted successfully"]);
}

public function deleteOrders(Request $r): Response {
    if (!$this->isAdmin($r->user)) {
        return Response::json(['message'=>'Forbidden'],403);
    }

    $ids = $r->body['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        return Response::json(['message'=>'Array of order IDs required'], 400);
    }

    $pdo = $this->db->pdo();

    // Delete order items first
    $inPlaceholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM order_items WHERE order_id IN ($inPlaceholders)")->execute($ids);

    // Delete orders
    $pdo->prepare("DELETE FROM orders WHERE id IN ($inPlaceholders)")->execute($ids);

    return Response::json(['message'=> count($ids) . " orders deleted successfully"]);
}

// In AdminController.php
public function users(Request $r): Response {
    if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);

    $stmt = $this->db->pdo()->query("SELECT id, name, email, role FROM users ORDER BY id DESC");
    return Response::json($stmt->fetchAll());
}

// Delete a user
public function deleteUser(Request $r): Response {
    if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);

    $id = (int)($r->params['id'] ?? 0);
    $stmt = $this->db->pdo()->prepare("DELETE FROM users WHERE id=? AND role!='admin'");
    $stmt->execute([$id]);

    return Response::json(['message'=>"User {$id} deleted"]);
}

// Dashboard stats
public function stats(Request $r): Response {
    if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);

    $pdo = $this->db->pdo();

    $products = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $orders   = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    $users    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

    return Response::json([
        'totalProducts' => (int)$products,
        'totalOrders'   => (int)$orders,
        'totalUsers'    => (int)$users,
    ]);
}

// AdminController.php
public function updateUser(Request $request, Response $response, array $args)
{
    $userId = $args['id'] ?? null;
    if (!$userId) {
        return $response->json(['message' => 'User ID required'], 400);
    }

    $user = $this->db->table('users')->find($userId);
    if (!$user) {
        return $response->json(['message' => 'User not found'], 404);
    }

    $data = $request->getBody(); // expect name, email, role

    // Validate data
    if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        return $response->json(['message' => 'Invalid email'], 400);
    }
    if (isset($data['role']) && !in_array($data['role'], ['User','Admin'])) {
        return $response->json(['message' => 'Invalid role'], 400);
    }

    $updatedUser = $this->db->table('users')->update($userId, [
        'name'  => $data['name'] ?? $user['name'],
        'email' => $data['email'] ?? $user['email'],
        'role'  => $data['role'] ?? $user['role'],
    ]);

    return $response->json(['message' => 'User updated', 'user' => $updatedUser]);
}


}
