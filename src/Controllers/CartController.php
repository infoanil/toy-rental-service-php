<?php
namespace App\Controllers;

use App\Core\{Request, Response};
use App\Database\Connection;

class CartController {
    public function __construct(private Connection $db) {}

    // Ensure a cart exists for the user
    private function ensureCartId(int $userId): int {
        try {
            $pdo = $this->db->pdo();

            // Check if cart exists
            $stmt = $pdo->prepare("SELECT id FROM carts WHERE user_id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if ($row) return (int)$row['id'];

            // Create new cart if doesn't exist
            $stmt = $pdo->prepare("INSERT INTO carts (user_id) VALUES (?)");
            $stmt->execute([$userId]);
            return (int)$pdo->lastInsertId();

        } catch (\Exception $e) {
            throw new \Exception("Failed to ensure cart: " . $e->getMessage());
        }
    }

    // Get cart items - FIXED VERSION
    public function get(Request $r): Response {
        try {
            if (!isset($r->user['sub'])) {
                return Response::json(['message' => 'User not authenticated'], 401);
            }

            $uid = (int)$r->user['sub'];
            $cid = $this->ensureCartId($uid);

            $sql = "SELECT
                        ci.id,
                        ci.product_id,
                        ci.duration_days,
                        ci.price_inr,
                        ci.start_date,
                        ci.end_date,
                        p.title,
                        p.images_json,
                        p.rental_options_json
                    FROM cart_items ci
                    JOIN products p ON ci.product_id = p.id
                    WHERE ci.cart_id = ?";

            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$cid]);

            $items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Process items to extract first image and format data
            $processedItems = array_map(function($item) {
                // Decode images JSON and get first image
                $images = json_decode($item['images_json'] ?? '[]', true) ?: [];
                $firstImage = !empty($images) ? $images[0] : null;

                // Build full image URL if image path exists
                $imageUrl = null;
                if ($firstImage && !empty($firstImage)) {
                    // If it's already a full URL, use it directly
                    if (strpos($firstImage, 'http') === 0) {
                        $imageUrl = $firstImage;
                    } else {
                        // Otherwise, build the full URL
                        $baseUrl = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8000', '/');
                        $imageUrl = $baseUrl . '/' . ltrim($firstImage, '/');
                    }
                }

                return [
                    'id' => (int)$item['id'],
                    'product_id' => (int)$item['product_id'],
                    'title' => $item['title'],
                    'duration_days' => (int)$item['duration_days'],
                    'price_inr' => (float)$item['price_inr'],
                    'start_date' => $item['start_date'],
                    'end_date' => $item['end_date'],
                    'image_url' => $imageUrl,
                ];
            }, $items);

            return Response::json([
                'success' => true,
                'cart_id' => $cid,
                'items' => $processedItems
            ]);

        } catch (\Exception $e) {
            error_log("CartController get error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'message' => 'Failed to fetch cart items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Add item to cart
    public function addItem(Request $r): Response {
        try {
            if (!isset($r->user['sub'])) {
                return Response::json(['message' => 'User not authenticated'], 401);
            }

            $uid = (int)$r->user['sub'];
            $cid = $this->ensureCartId($uid);

            $pid = (int)($r->body['product_id'] ?? 0);
            $start = $r->body['start_date'] ?? null;
            $optionIndex = isset($r->body['option_index']) ? (int)$r->body['option_index'] : null;

            // Validation
            if (!$pid || !$start || $optionIndex === null) {
                return Response::json([
                    'message' => 'product_id, option_index, and start_date are required'
                ], 422);
            }

            // Fetch product and rental options
            $stmt = $this->db->pdo()->prepare("SELECT rental_options_json FROM products WHERE id = ?");
            $stmt->execute([$pid]);
            $product = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$product) {
                return Response::json(['message' => 'Product not found'], 404);
            }

            // Decode rental options
            $rentalOptions = json_decode($product['rental_options_json'] ?? '[]', true);

            if (!is_array($rentalOptions) || empty($rentalOptions)) {
                return Response::json(['message' => 'No rental options available for this product'], 422);
            }

            if (!isset($rentalOptions[$optionIndex])) {
                return Response::json([
                    'message' => 'Invalid rental option selected',
                    'available_options' => count($rentalOptions)
                ], 422);
            }

            // Get selected plan
            $plan = $rentalOptions[$optionIndex];
            $duration = (int)($plan['days'] ?? 0);
            $price = (float)($plan['price'] ?? 0);

            // Validate plan data
            if ($duration <= 0 || $price <= 0) {
                return Response::json(['message' => 'Invalid rental plan configuration'], 422);
            }

            // Calculate end date
            $startDate = new \DateTime($start);
            $endDate = clone $startDate;
            $endDate->modify("+".($duration - 1)." days");

            $end = $endDate->format('Y-m-d');

            // Insert into cart_items
            $stmt = $this->db->pdo()->prepare("
                INSERT INTO cart_items
                (cart_id, product_id, duration_days, price_inr, start_date, end_date)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $cid,
                $pid,
                $duration,
                $price,
                $start,
                $end
            ]);

            return Response::json([
                'success' => true,
                'message' => 'Item added to cart successfully',
                'cart_item_id' => (int)$this->db->pdo()->lastInsertId()
            ]);

        } catch (\Exception $e) {
            error_log("CartController addItem error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Remove item from cart
    public function removeItem(Request $r): Response {
        try {
            if (!isset($r->user['sub'])) {
                return Response::json(['message' => 'User not authenticated'], 401);
            }

            $uid = (int)$r->user['sub'];
            $cid = $this->ensureCartId($uid);
            $itemId = (int)($r->params['id'] ?? 0);

            if ($itemId <= 0) {
                return Response::json(['message' => 'Invalid item ID'], 422);
            }

            $stmt = $this->db->pdo()->prepare("
                DELETE FROM cart_items
                WHERE id = ? AND cart_id = ?
            ");
            $stmt->execute([$itemId, $cid]);

            if ($stmt->rowCount() === 0) {
                return Response::json(['message' => 'Cart item not found'], 404);
            }

            return Response::json([
                'success' => true,
                'message' => 'Item removed from cart successfully'
            ]);

        } catch (\Exception $e) {
            error_log("CartController removeItem error: " . $e->getMessage());
            return Response::json([
                'success' => false,
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}