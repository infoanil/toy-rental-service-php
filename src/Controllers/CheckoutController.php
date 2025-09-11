<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use function App\Core\env;

class CheckoutController {
    public function __construct(private Connection $db){}

    public function checkout(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $addressId = (int)($r->body['address_id'] ?? 0);
        if (!$addressId) return Response::json(['message'=>'address_id required'],422);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try{
            $cidStmt = $pdo->prepare("SELECT id FROM carts WHERE user_id=?");
            $cidStmt->execute([$uid]);
            $cart = $cidStmt->fetch();
            if (!$cart) throw new \Exception('Cart empty');
            $cid = (int)$cart['id'];

            $items = $pdo->prepare("SELECT ci.product_id, ci.plan_id, ci.start_date, ci.end_date, pp.price_inr
                                    FROM cart_items ci
                                    JOIN product_plans pp ON ci.plan_id=pp.id
                                    WHERE ci.cart_id=?");
            $items->execute([$cid]);
            $rows = $items->fetchAll();
            if (!$rows) throw new \Exception('Cart empty');

            $total = array_sum(array_column($rows, 'price_inr')) + (int)env('DELIVERY_FEE',0);
            $pdo->prepare("INSERT INTO orders(user_id,address_id,status,payment_mode,delivery_fee,total_due,placed_at) VALUES (?,?,?,?,?,?,NOW())")
                ->execute([$uid,$addressId,'PLACED','COD',(int)env('DELIVERY_FEE',0),$total]);
            $orderId = (int)$pdo->lastInsertId();

            $oi = $pdo->prepare("INSERT INTO order_items(order_id,product_id,plan_id,start_date,end_date,item_price) VALUES (?,?,?,?,?,?)");
            foreach ($rows as $it) {
                $oi->execute([$orderId,$it['product_id'],$it['plan_id'],$it['start_date'],$it['end_date'],$it['price_inr']]);
            }
            $pdo->prepare("DELETE FROM cart_items WHERE cart_id=?")->execute([$cid]);
            $pdo->commit();
            return Response::json(['order_id'=>$orderId,'status'=>'PLACED','payment_mode'=>'COD','total_due'=>$total]);
        } catch(\Throwable $e){
            $pdo->rollBack();
            return Response::json(['message'=>'Checkout failed','error'=>$e->getMessage()],400);
        }
    }
}
