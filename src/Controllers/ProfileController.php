<?php
namespace App\Controllers;

use App\Core\{Request, Response};
use App\Database\Connection;

class ProfileController {
    public function __construct(private Connection $db){}

    /**
     * Update user profile (partial updates allowed)
     */
    public function update(Request $r): Response {
        $uid = (int)$r->user['sub'];
        $fields = ['name','email','phone','city','state','zip','address','avatar'];
        $updates = [];
        $values = [];

        foreach ($fields as $field) {
            if (isset($r->body[$field])) {
                $updates[] = "$field=?";
                $values[]  = $r->body[$field];
            }
        }

        // Validate required fields
        if (isset($r->body['name']) && empty(trim($r->body['name']))) {
            return Response::json(['message'=>'Name cannot be empty'], 422);
        }

        if (isset($r->body['email']) && !filter_var($r->body['email'], FILTER_VALIDATE_EMAIL)) {
            return Response::json(['message'=>'Invalid email format'], 422);
        }

        if (empty($updates)) {
            return Response::json(['message'=>'No fields to update'], 400);
        }

        $values[] = $uid;
        $sql = "UPDATE users SET " . implode(',', $updates) . " WHERE id=?";

        try {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute($values);

            $stmt = $this->db->pdo()->prepare("SELECT id, name, email, phone, city, state, zip, address, avatar FROM users WHERE id=?");
            $stmt->execute([$uid]);
            $user = $stmt->fetch();

            return Response::json([
                'message' => 'Profile updated successfully',
                'user'    => $user
            ]);
        } catch (\PDOException $e) {
            return Response::json(['message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Upload avatar separately
     */
    public function uploadAvatar(Request $r): Response {
        $uid = (int)$r->user['sub'];

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['message'=>'Avatar file is required'], 422);
        }

        $file = $_FILES['avatar'];
        $tmp  = $file['tmp_name'];
        $size = (int)$file['size'];

        $maxBytes = 2 * 1024 * 1024; // 2MB
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
        $base = bin2hex(random_bytes(16));
        $fname = $base . '.' . $ext;

        $dirAbs = __DIR__ . '/../../public/uploads/avatars';
        if (!is_dir($dirAbs)) { @mkdir($dirAbs, 0775, true); }

        $destAbs = $dirAbs . '/' . $fname;
        $relPath = '/uploads/avatars/' . $fname;

        if (!move_uploaded_file($tmp, $destAbs)) {
            return Response::json(['message'=>'Failed to move uploaded file'], 500);
        }

        try {
            $pdo = $this->db->pdo();

            // Delete previous avatar if exists
            $oldStmt = $pdo->prepare("SELECT avatar FROM users WHERE id=?");
            $oldStmt->execute([$uid]);
            $row = $oldStmt->fetch();
            if ($row && !empty($row['avatar'])) {
                $oldPath = __DIR__ . '/../../public' . $row['avatar'];
                if (str_starts_with($row['avatar'], '/uploads/avatars/') && file_exists($oldPath)) {
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

        } catch (\PDOException $e) {
            return Response::json(['message' => 'Database error: ' . $e->getMessage()], 500);
        }
    }
}
