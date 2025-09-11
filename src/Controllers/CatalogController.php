<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class CatalogController {
    public function __construct(private Connection $db){}

    public function categories(Request $r): Response {
        $rows = $this->db->pdo()->query("SELECT id,name,slug,parent_id FROM categories ORDER BY name")->fetchAll();
        return Response::json($rows);
    }

    public function products(Request $r): Response {
        $q = $r->query['q'] ?? '';
        $cat = $r->query['category'] ?? null;
        $pdo = $this->db->pdo();
        $sql = "SELECT id,title,slug,category_id,brand,age_min,age_max,images_json FROM products WHERE active=1";
        $params = [];
        if ($q) { $sql .= " AND (title LIKE ? OR description LIKE ?)"; $params[]="%$q%"; $params[]="%$q%"; }
        if ($cat) { $sql .= " AND category_id = ?"; $params[] = (int)$cat; }
        $sql .= " ORDER BY id DESC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return Response::json($stmt->fetchAll());
    }

    public function product(Request $r): Response {
        $id = (int)($r->params['id'] ?? 0);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id=?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if (!$p) return Response::json(['message'=>'Not found'],404);
        $plans = $pdo->prepare("SELECT id,duration_days,price_inr FROM product_plans WHERE product_id=? ORDER BY duration_days");
        $plans->execute([$id]);
        $p['plans'] = $plans->fetchAll();
        return Response::json($p);
    }

    public function plans(Request $r): Response {
        $id = (int)($r->params['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare("SELECT id,duration_days,price_inr FROM product_plans WHERE product_id=? ORDER BY duration_days");
        $stmt->execute([$id]);
        return Response::json($stmt->fetchAll());
    }
}
