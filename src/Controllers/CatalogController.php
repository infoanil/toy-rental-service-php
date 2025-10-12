<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class CatalogController {
    public function __construct(private Connection $db){}

    // --- Categories ---
    public function categories(Request $r): Response {
        $rows = $this->db->pdo()->query("SELECT id,name,slug,parent_id FROM categories ORDER BY name")->fetchAll(\PDO::FETCH_ASSOC);
        return Response::json($rows);
    }

    // --- Product List ---
    public function products(Request $r): Response {
        $q   = $r->query['q'] ?? '';
        $cat = $r->query['category'] ?? null;

        $pdo = $this->db->pdo();
        $sql = "SELECT p.*, c.name AS category_name
                FROM products p
                LEFT JOIN categories c ON c.id=p.category_id
                WHERE p.active=1";
        $params = [];

        if ($q) {
            $sql .= " AND (p.title LIKE ? OR p.description LIKE ?)";
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        if ($cat) {
            $sql .= " AND p.category_id = ?";
            $params[] = (int)$cat;
        }

        $sql .= " ORDER BY p.id DESC LIMIT 50";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $products = array_map(function($row) {
            $images = json_decode($row['images_json'] ?? '[]', true) ?: [];
            $rentalOptions = json_decode($row['rental_options_json'] ?? '[]', true) ?: [];

            return [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'category_id' => (int)$row['category_id'],
                'category_name' => $row['category_name'] ?? null,
                'brand' => $row['brand'] ?? null,
                'age_min' => $row['age_min'] !== null ? (int)$row['age_min'] : null,
                'age_max' => $row['age_max'] !== null ? (int)$row['age_max'] : null,
                'description' => $row['description'],
                'actual_price' => (float)$row['actual_price'],
                'discount_price' => $row['discount_price'] !== null ? (float)$row['discount_price'] : null,
                'images' => $images,
                'rentalOptions' => $rentalOptions
            ];
        }, $rows);

        return Response::json($products);
    }

    // --- Single Product ---
    public function product(Request $r): Response {
        $id = (int)($r->params['id'] ?? 0);
        if ($id <= 0) return Response::json(['message'=>'Invalid product ID'],422);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.id=p.category_id
            WHERE p.id=? AND p.active=1
        ");
        $stmt->execute([$id]);
        $p = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$p) return Response::json(['message'=>'Not found'],404);

        $p['images'] = json_decode($p['images_json'] ?? '[]', true) ?: [];
        $p['rentalOptions'] = json_decode($p['rental_options_json'] ?? '[]', true) ?: [];

        unset($p['images_json'], $p['rental_options_json']);

        return Response::json($p);
    }
}
