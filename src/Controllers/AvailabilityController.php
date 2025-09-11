<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;
use function App\Core\env;

class AvailabilityController {
    public function __construct(private Connection $db){}

    public function check(Request $r): Response {
        $pid = (int)($r->params['id'] ?? 0);
        $start = $r->query['start'] ?? null;
        $days = (int)($r->query['days'] ?? 7);
        if (!$start) return Response::json(['message'=>'start required'],422);
        $end = date('Y-m-d', strtotime("$start +".($days-1)." days"));
        $buffer = (int)env('BUFFER_DAYS',1);
        $endBuf = date('Y-m-d', strtotime("$end +{$buffer} days"));

        $pdo = $this->db->pdo();
        $sql = "SELECT iu.id
                FROM inventory_units iu
                WHERE iu.product_id = :pid
                  AND NOT EXISTS (
                    SELECT 1 FROM availability_blocks ab
                    WHERE ab.inventory_unit_id = iu.id
                      AND ab.start_date <= :end_buf
                      AND ab.end_date   >= :start
                  )
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid'=>$pid, ':end_buf'=>$endBuf, ':start'=>$start]);
        $unit = $stmt->fetch();
        return Response::json([
            'product_id'=>$pid,
            'start_date'=>$start,
            'end_date'=>$end,
            'buffer_days'=>$buffer,
            'available'=> (bool)$unit
        ]);
    }
}
