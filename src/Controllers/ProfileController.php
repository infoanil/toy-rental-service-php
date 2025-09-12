<?php
namespace App\Controllers;

use App\Core\{Request,Response};
use App\Database\Connection;

class ProfileController {
    public function __construct(private Connection $db){}

    public function update(Request $r): Response {
        $uid = (int)$r->user['sub'];

        $phone   = $r->body['phone']   ?? null;
        $city    = $r->body['city']    ?? null;
        $state   = $r->body['state']   ?? null;
        $zip     = $r->body['zip']     ?? null;
        $address = $r->body['address'] ?? null;
        $avatar  = $r->body['avatar']  ?? null; // optional URL/path

        $sql = "UPDATE users SET phone=?, city=?, state=?, zip=?, address=?, avatar=? WHERE id=?";
        $this->db->pdo()->prepare($sql)->execute([$phone,$city,$state,$zip,$address,$avatar,$uid]);

        return Response::json(['message'=>'Updated']);
    }

    public function uploadAvatar(Request $r): Response {
        $uid = (int)$r->user['sub'];

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['message'=>'avatar file is required'], 422);
        }

        $file   = $_FILES['avatar'];
        $tmp    = $file['tmp_name'];
        $size   = (int)$file['size'];
        $name   = $file['name'];

        // 2 MB max
        $maxBytes = 2 * 1024 * 1024;
        if ($size <= 0 || $size > $maxBytes) {
            return Response::json(['message'=>'File too large (max 2MB)'], 422);
        }

        // Validate MIME using finfo
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: '';
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!isset($allowed[$mime])) {
            return Response::json(['message'=>'Only jpg, png, webp, gif allowed'], 422);
        }
        $ext = $allowed[$mime];

        // Generate safe unique filename
        $base   = bin2hex(random_bytes(16));
        $fname  = $base . '.' . $ext;
        $dirAbs = realpath(__DIR__ . '/../../public') . '/uploads/avatars';
        if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

        $destAbs = $dirAbs . '/' . $fname;
        $relPath = '/uploads/avatars/' . $fname; // what we store in DB & return

        if (!move_uploaded_file($tmp, $destAbs)) {
            return Response::json(['message'=>'Failed to move uploaded file'], 500);
        }

        // Optional: delete previous avatar if present and inside /uploads/avatars
        $pdo = $this->db->pdo();
        $old = $pdo->prepare("SELECT avatar FROM users WHERE id=?");
        $old->execute([$uid]);
        $row = $old->fetch();
        if ($row && !empty($row['avatar'])) {
            $oldPath = realpath(__DIR__ . '/../../public') . $row['avatar'];
            if (is_string($oldPath) && str_starts_with($row['avatar'], '/uploads/avatars/') && file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Save new avatar path
        $upd = $pdo->prepare("UPDATE users SET avatar=? WHERE id=?");
        $upd->execute([$relPath, $uid]);

        return Response::json([
            'message' => 'Avatar uploaded',
            'avatar'  => $relPath
        ], 200);
    }
}
