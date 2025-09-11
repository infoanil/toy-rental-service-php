<?php
namespace App\Database;
use PDO;
use PDOException;
use function App\Core\env;

class Connection {
    private ?PDO $pdo = null;
    public function pdo(): PDO {
        if ($this->pdo) return $this->pdo;
        $host = env('DB_HOST','127.0.0.1');
        $port = (int)env('DB_PORT',3306);
        $db   = env('DB_DATABASE','toy_rental');
        $user = env('DB_USERNAME','root');
        $pass = env('DB_PASSWORD','');
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        try{
            $this->pdo = new PDO($dsn, $user, $pass, $opts);
            return $this->pdo;
        } catch(PDOException $e){
            http_response_code(500);
            die(json_encode(['error'=>'DB connection failed','detail'=>$e->getMessage()]));
        }
    }
}
