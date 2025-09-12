<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class AdminProductController {
    public function __construct(private Connection $db){}

    private function assertAdmin(Request $r): ?Response {
        if (($r->user['role'] ?? '') !== 'admin') {
            return Response::json(['message'=>'Forbidden'], 403);
        }
        return null;
    }

    // GET /api/admin/products?q=&category=&active=
    public function index(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;

        $pdo = $this->db->pdo();
        $sql = "SELECT p.* , c.name AS category_name
                FROM products p
                JOIN categories c ON c.id=p.category_id
                WHERE 1=1";
        $params = [];
        if (!empty($r->query['q'])) {
            $sql .= " AND (p.title LIKE ? OR p.description LIKE ?)";
            $params[] = '%'.$r->query['q'].'%';
            $params[] = '%'.$r->query['q'].'%';
        }
        if (!empty($r->query['category'])) {
            $sql .= " AND p.category_id = ?";
            $params[] = (int)$r->query['category'];
        }
        if (isset($r->query['active'])) {
            $sql .= " AND p.active = ?";
            $params[] = (int)$r->query['active'];
        }
        $sql .= " ORDER BY p.id DESC LIMIT 100";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return Response::json($st->fetchAll());
    }

    // GET /api/admin/products/{id}
    public function show(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['id'];

        $pdo = $this->db->pdo();
        $p = $pdo->prepare("SELECT * FROM products WHERE id=?");
        $p->execute([$id]);
        $prod = $p->fetch();
        if (!$prod) return Response::json(['message'=>'Not found'],404);

        $plans = $pdo->prepare("SELECT id,duration_days,price_inr FROM product_plans WHERE product_id=? ORDER BY duration_days");
        $plans->execute([$id]);
        $units = $pdo->prepare("SELECT id,code,status,last_sanitized_on FROM inventory_units WHERE product_id=? ORDER BY id DESC");
        $units->execute([$id]);
        $prod['plans'] = $plans->fetchAll();
        $prod['units'] = $units->fetchAll();
        return Response::json($prod);
    }

    // POST /api/admin/products
    // Body JSON:
    // { "title": "...", "slug":"...", "category_id":1, "brand":"...", "age_min":3, "age_max":6,
    //   "description":"...", "actual_price":1500, "discount_price":1200, "images":["a.jpg","b.jpg"], "active":1 }
    public function store(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $b = $r->body;

        foreach (['title','slug','category_id'] as $f) {
            if (!isset($b[$f]) || $b[$f]==='') return Response::json(['message'=>"$f required"],422);
        }

        $imagesJson = null;
        if (!empty($b['images']) && is_array($b['images'])) {
            $imagesJson = json_encode(array_values($b['images']), JSON_UNESCAPED_SLASHES);
        }

        $pdo = $this->db->pdo();
        $sql = "INSERT INTO products
            (title,slug,category_id,brand,age_min,age_max,description,actual_price,discount_price,images_json,active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)";
        $pdo->prepare($sql)->execute([
            $b['title'],
            $b['slug'],
            (int)$b['category_id'],
            $b['brand']      ?? null,
            isset($b['age_min']) ? (int)$b['age_min'] : null,
            isset($b['age_max']) ? (int)$b['age_max'] : null,
            $b['description'] ?? null,
            isset($b['actual_price']) ? (int)$b['actual_price'] : 0,
            isset($b['discount_price']) ? (int)$b['discount_price'] : null,
            $imagesJson,
            isset($b['active']) ? (int)$b['active'] : 1,
        ]);

        $id = (int)$pdo->lastInsertId();
        return Response::json(['id'=>$id,'message'=>'Created'],201);
    }

    // PUT /api/admin/products/{id}
    public function update(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['id'];
        $b = $r->body;

        // Build dynamic update safely
        $fields = [];
        $params = [];

        $map = [
            'title','slug','category_id','brand','age_min','age_max','description',
            'actual_price','discount_price','active'
        ];
        foreach ($map as $f) {
            if (array_key_exists($f, $b)) {
                $fields[] = "$f = ?";
                $params[] = is_null($b[$f]) ? null : (in_array($f, ['category_id','age_min','age_max','actual_price','discount_price','active']) ? (int)$b[$f] : $b[$f]);
            }
        }

        if (array_key_exists('images', $b)) {
            $fields[] = "images_json = ?";
            $params[] = is_array($b['images']) ? json_encode(array_values($b['images']), JSON_UNESCAPED_SLASHES) : null;
        }

        if (!$fields) return Response::json(['message'=>'Nothing to update'],422);

        $params[] = $id;
        $sql = "UPDATE products SET ".implode(',', $fields)." WHERE id=?";
        $this->db->pdo()->prepare($sql)->execute($params);

        return Response::json(['message'=>'Updated']);
    }

    // DELETE /api/admin/products/{id}
    public function destroy(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['id'];
        $this->db->pdo()->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
        return Response::json(['message'=>'Deleted']);
    }

    // POST /api/admin/products/{id}/plans  { "duration_days":7, "price_inr":499 }
    public function addPlan(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $pid = (int)$r->params['id'];
        $days = (int)($r->body['duration_days'] ?? 0);
        $price = (int)($r->body['price_inr'] ?? 0);
        if ($days<=0 || $price<=0) return Response::json(['message'=>'duration_days and price_inr required'],422);
        $this->db->pdo()->prepare("INSERT INTO product_plans(product_id,duration_days,price_inr) VALUES (?,?,?)")
            ->execute([$pid,$days,$price]);
        return Response::json(['message'=>'Plan added','plan_id'=>$this->db->pdo()->lastInsertId()],201);
    }

    // PUT /api/admin/plans/{planId}  { "duration_days":15, "price_inr":799 }
    public function updatePlan(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['planId'];
        $b = $r->body;
        $fields=[]; $params=[];
        if (isset($b['duration_days'])) { $fields[]="duration_days=?"; $params[]=(int)$b['duration_days']; }
        if (isset($b['price_inr']))     { $fields[]="price_inr=?";     $params[]=(int)$b['price_inr']; }
        if (!$fields) return Response::json(['message'=>'Nothing to update'],422);
        $params[] = $id;
        $this->db->pdo()->prepare("UPDATE product_plans SET ".implode(',',$fields)." WHERE id=?")->execute($params);
        return Response::json(['message'=>'Plan updated']);
    }

    // DELETE /api/admin/plans/{planId}
    public function deletePlan(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['planId'];
        $this->db->pdo()->prepare("DELETE FROM product_plans WHERE id=?")->execute([$id]);
        return Response::json(['message'=>'Plan deleted']);
    }

    // POST /api/admin/products/{id}/units  { "code":"TOY-0009" }
    public function addUnit(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $pid = (int)$r->params['id'];
        $code = trim((string)($r->body['code'] ?? ''));
        if ($code==='') return Response::json(['message'=>'code required'],422);
        $this->db->pdo()->prepare("INSERT INTO inventory_units(product_id,code,status) VALUES (?,?, 'AVAILABLE')")
            ->execute([$pid,$code]);
        return Response::json(['message'=>'Unit added','unit_id'=>$this->db->pdo()->lastInsertId()],201);
    }

    // DELETE /api/admin/units/{unitId}
    public function deleteUnit(Request $r): Response {
        if ($x = $this->assertAdmin($r)) return $x;
        $id = (int)$r->params['unitId'];
        $this->db->pdo()->prepare("DELETE FROM inventory_units WHERE id=?")->execute([$id]);
        return Response::json(['message'=>'Unit deleted']);
    }

    // inside namespace and class AdminProductController (same file you already have)

private function productImagesDirAbs(): string {
    $public = realpath(__DIR__ . '/../../public');
    $dir = $public . '/uploads/products';
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return $dir;
}

private function isAllowedImage(string $tmpPath, ?string &$extOut=null): bool {
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmpPath) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) return false;
    $extOut = $allowed[$mime];
    return true;
}

/**
 * POST /api/admin/products/{id}/images
 * Multipart: images[] (one or many)
 * Appends to products.images_json and returns full array.
 */
public function addImages(\App\Core\Request $r): \App\Core\Response {
    if ($x = $this->assertAdmin($r)) return $x;
    $pid = (int)$r->params['id'];

    if (!isset($_FILES['images'])) {
        return \App\Core\Response::json(['message'=>'Send files as images[]'], 422);
    }

    $files = $_FILES['images'];
    // Normalize to arrays
    $count = is_array($files['name']) ? count($files['name']) : 1;
    $paths = [];

    $dirAbs = $this->productImagesDirAbs();
    $public = realpath(__DIR__ . '/../../public');

    for ($i=0; $i<$count; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];
        $size = is_array($files['size']) ? (int)$files['size'][$i] : (int)$files['size'];

        if ($err !== UPLOAD_ERR_OK) continue;
        if ($size <= 0 || $size > 8*1024*1024) continue; // 8MB max

        $ext = null;
        if (!$this->isAllowedImage($tmp, $ext)) continue;

        $fname = bin2hex(random_bytes(16)) . '.' . $ext;
        $destAbs = $dirAbs . '/' . $fname;
        if (!move_uploaded_file($tmp, $destAbs)) continue;

        $paths[] = '/uploads/products/' . $fname;
    }

    if (!$paths) {
        return \App\Core\Response::json(['message'=>'No valid images uploaded'], 422);
    }

    // Merge into DB
    $pdo = $this->db->pdo();
    $st  = $pdo->prepare("SELECT images_json FROM products WHERE id=?");
    $st->execute([$pid]);
    $row = $st->fetch();
    if (!$row) return \App\Core\Response::json(['message'=>'Product not found'],404);

    $existing = [];
    if (!empty($row['images_json'])) {
        $decoded = json_decode($row['images_json'], true);
        if (is_array($decoded)) $existing = $decoded;
    }

    $merged = array_values(array_unique(array_merge($existing, $paths)));

    $upd = $pdo->prepare("UPDATE products SET images_json=? WHERE id=?");
    $upd->execute([ json_encode($merged, JSON_UNESCAPED_SLASHES), $pid ]);

    return \App\Core\Response::json(['message'=>'Images added','images'=>$merged], 200);
}

/**
 * DELETE /api/admin/products/{id}/images?path=/uploads/products/xxx.jpg
 * Removes the image from images_json and deletes the file (only inside /uploads/products).
 */
public function deleteImage(\App\Core\Request $r): \App\Core\Response {
    if ($x = $this->assertAdmin($r)) return $x;
    $pid  = (int)$r->params['id'];
    $path = $r->query['path'] ?? '';
    if (!$path) return \App\Core\Response::json(['message'=>'path query param required'], 422);

    $pdo = $this->db->pdo();
    $st  = $pdo->prepare("SELECT images_json FROM products WHERE id=?");
    $st->execute([$pid]);
    $row = $st->fetch();
    if (!$row) return \App\Core\Response::json(['message'=>'Product not found'],404);

    $images = [];
    if (!empty($row['images_json'])) {
        $decoded = json_decode($row['images_json'], true);
        if (is_array($decoded)) $images = $decoded;
    }

    $new = array_values(array_filter($images, fn($p) => $p !== $path));

    // Update DB first
    $pdo->prepare("UPDATE products SET images_json=? WHERE id=?")
        ->execute([ json_encode($new, JSON_UNESCAPED_SLASHES), $pid ]);

    // Delete file only if it’s under /uploads/products
    if (str_starts_with($path, '/uploads/products/')) {
        $abs = realpath(__DIR__ . '/../../public') . $path;
        if ($abs && file_exists($abs)) { @unlink($abs); }
    }

    return \App\Core\Response::json(['message'=>'Image removed','images'=>$new], 200);
}

}
