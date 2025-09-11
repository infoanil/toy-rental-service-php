<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use function App\Core\env;

class AdminController {
    public function __construct(private Connection $db){}

    private function isAdmin(array $user): bool { return ($user['role'] ?? '') === 'admin'; }

    public function orders(Request $r): Response {
        if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);
        $status = $r->query['status'] ?? null;
        $sql = "SELECT id,user_id,status,total_due,placed_at FROM orders";
        $params = [];
        if ($status) { $sql .= " WHERE status=?"; $params[]=$status; }
        $sql .= " ORDER BY id DESC LIMIT 100";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return Response::json($stmt->fetchAll());
    }

    public function confirm(Request $r): Response {
        if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);
        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try{
            // Fetch items for the order
            $items = $pdo->prepare("SELECT oi.id, oi.product_id, oi.start_date, oi.end_date FROM order_items oi WHERE oi.order_id=?");
            $items->execute([$id]);
            $rows = $items->fetchAll();
            if (!$rows) throw new \Exception('No items');

            $buffer = (int)env('BUFFER_DAYS',1);
            foreach ($rows as $it) {
                $endBuf = date('Y-m-d', strtotime($it['end_date'] . " +{$buffer} days"));
                // Pick one free unit FOR UPDATE to avoid race
                $lock = $pdo->prepare("SELECT iu.id FROM inventory_units iu
                        WHERE iu.product_id = :pid
                        AND NOT EXISTS (
                            SELECT 1 FROM availability_blocks ab
                            WHERE ab.inventory_unit_id = iu.id
                              AND ab.start_date <= :end_buf
                              AND ab.end_date   >= :start
                        )
                        LIMIT 1 FOR UPDATE");
                $lock->execute([':pid'=>$it['product_id'], ':end_buf'=>$endBuf, ':start'=>$it['start_date']]);
                $unit = $lock->fetch();
                if (!$unit) throw new \Exception('No free unit for product '.$it['product_id']);
                $pdo->prepare("UPDATE order_items SET inventory_unit_id=? WHERE id=?")->execute([$unit['id'],$it['id']]);
                $pdo->prepare("INSERT INTO availability_blocks(inventory_unit_id,start_date,end_date,type,order_id) VALUES (?,?,?,?,?)")
                    ->execute([$unit['id'],$it['start_date'],$endBuf,'RENTAL',$id]);
            }
            $pdo->prepare("UPDATE orders SET status='CONFIRMED' WHERE id=?")->execute([$id]);
            $pdo->commit();
            return Response::json(['order_id'=>$id,'status'=>'CONFIRMED']);
        } catch(\Throwable $e){
            $pdo->rollBack();
            return Response::json(['message'=>'Confirm failed','error'=>$e->getMessage()],400);
        }
    }

    public function markDelivered(Request $r): Response {
        if (!$this->isAdmin($r->user)) return Response::json(['message'=>'Forbidden'],403);
        $id = (int)($r->params['id'] ?? 0);
        $this->db->pdo()->prepare("UPDATE orders SET status='DELIVERED' WHERE id=? AND status='CONFIRMED'")->execute([$id]);
        return Response::json(['order_id'=>$id,'status'=>'DELIVERED']);
    }
}
