<?php
require __DIR__ . '/../vendor/autoload.php';
App\Core\Env::load(__DIR__ . '/../.env');
$db = (new App\Database\Connection())->pdo();

$sql = "UPDATE orders o
        JOIN (
          SELECT order_id, MAX(end_date) AS max_end FROM order_items GROUP BY order_id
        ) m ON m.order_id = o.id
        SET o.status='CLOSED'
        WHERE o.status='DELIVERED' AND m.max_end < CURDATE()";
$db->exec($sql);

echo "Closed delivered orders past end_date.\n";
