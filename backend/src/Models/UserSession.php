<?php
namespace App\Models;

use PDO;

class UserSession extends BaseModel {
    public function createToken(int $userId, int $ttlHours = 72): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $stmt = $this->db->prepare("INSERT INTO user_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $token, $expiresAt]);

        return $token;
    }

    public function findUserByToken(string $token): ?array {
        $stmt = $this->db->prepare("SELECT u.id, u.user_code, u.society_id, u.is_parent_user, u.full_name, u.email, u.role_id, 
                                           u.phone, u.unit_code, u.resident_id, u.status, u.avatar_url,
                                           r.role_code, r.name as role_name, r.badge_color as role_badge_color,
                                           s.name as society_name, s.society_code,
                                           t.expires_at
                                    FROM user_tokens t
                                    JOIN users u ON t.user_id = u.id
                                    JOIN roles r ON u.role_id = r.id
                                    LEFT JOIN societies s ON u.society_id = s.id
                                    WHERE t.token = ? AND t.expires_at > NOW() AND u.status = 'Active'
                                    LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public function revokeToken(string $token): bool {
        $stmt = $this->db->prepare("DELETE FROM user_tokens WHERE token = ?");
        return $stmt->execute([$token]);
    }

    public function revokeAllForUser(int $userId): bool {
        $stmt = $this->db->prepare("DELETE FROM user_tokens WHERE user_id = ?");
        return $stmt->execute([$userId]);
    }
}
