<?php
namespace App\Controllers;

use App\Core\{Request, Response};
use App\Database\Connection;

class ProfileController {
    public function __construct(private Connection $db){}

    /**
     * Update user profile (name, phone, address, etc.)
     */
    public function update(Request $r): Response {
        $uid = (int)$r->user['sub'];

        $name    = $r->body['name']    ?? null;
        $email   = $r->body['email']   ?? null; // optional if you allow email change
        $phone   = $r->body['phone']   ?? null;
        $city    = $r->body['city']    ?? null;
        $state   = $r->body['state']   ?? null;
        $zip     = $r->body['zip']     ?? null;
        $address = $r->body['address'] ?? null;
        $avatar  = $r->body['avatar']  ?? null; // URL/path

        // Update user in DB
        $sql = "UPDATE users
                SET name=?, email=?, phone=?, city=?, state=?, zip=?, address=?, avatar=?
                WHERE id=?";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$name, $email, $phone, $city, $state, $zip, $address, $avatar, $uid]);

        // Fetch updated user
        $stmt = $this->db->pdo()->prepare("SELECT id, name, email, phone, city, state, zip, address, avatar FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        return Response::json([
            'message' => 'Profile updated successfully',
            'user'    => $user
        ]);
    }

    /**
     * Upload avatar separately
     */
    public function uploadAvatar(Request $r): Response {
        $uid = (int)$r->user['sub'];

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['message'=>'Avatar file is required'], 422);
        }

        $file   = $_FILES['avatar'];
        $tmp    = $file['tmp_name'];
        $size   = (int)$file['size'];

        // Max 2MB
        $maxBytes = 2 * 1024 * 1024;
        if ($size <= 0 || $size > $maxBytes) {
            return Response::json(['message'=>'File too large (max 2MB)'], 422);
        }

        // Validate MIME type
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

        // Generate unique filename
        $base = bin2hex(random_bytes(16));
        $fname = $base . '.' . $ext;
        $dirAbs = realpath(__DIR__ . '/../../public') . '/uploads/avatars';
        if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

        $destAbs = $dirAbs . '/' . $fname;
        $relPath = '/uploads/avatars/' . $fname;

        if (!move_uploaded_file($tmp, $destAbs)) {
            return Response::json(['message'=>'Failed to move uploaded file'], 500);
        }

        // Delete previous avatar if exists
        $pdo = $this->db->pdo();
        $oldStmt = $pdo->prepare("SELECT avatar FROM users WHERE id=?");
        $oldStmt->execute([$uid]);
        $row = $oldStmt->fetch();
        if ($row && !empty($row['avatar'])) {
            $oldPath = realpath(__DIR__ . '/../../public') . $row['avatar'];
            if (is_string($oldPath) && str_starts_with($row['avatar'], '/uploads/avatars/') && file_exists($oldPath)) {
                @unlink($oldPath);
            }
        }

        // Save new avatar path
        $upd = $pdo->prepare("UPDATE users SET avatar=? WHERE id=?");
        $upd->execute([$relPath, $uid]);

        // Fetch updated user
        $stmt = $pdo->prepare("SELECT id, name, email, phone, city, state, zip, address, avatar FROM users WHERE id=?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        return Response::json([
            'message' => 'Avatar uploaded successfully',
            'avatar'  => $relPath,
            'user'    => $user
        ], 200);
    }
}
