<?php
namespace App\Models;

use PDO;

class Society extends BaseModel {
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   COUNT(DISTINCT u.id) AS total_units
            FROM societies s
            LEFT JOIN units u ON s.id = u.society_id AND u.is_deleted = 0
            WHERE s.id = ? AND s.is_deleted = 0
            GROUP BY s.id
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByCode(string $code): ?array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   COUNT(DISTINCT u.id) AS total_units
            FROM societies s
            LEFT JOIN units u ON s.id = u.society_id AND u.is_deleted = 0
            WHERE s.society_code = ? AND s.is_deleted = 0
            GROUP BY s.id
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAll(): array {
        $stmt = $this->db->query("
            SELECT s.*, 
                   COUNT(DISTINCT u.id) AS total_units
            FROM societies s
            LEFT JOIN units u ON s.id = u.society_id AND u.is_deleted = 0
            WHERE s.is_deleted = 0
            GROUP BY s.id
            ORDER BY s.id ASC
        ");
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO societies 
            (society_code, name, legal_name, registration_number, address_line1, address_line2, address, city, state, pincode, country, zone_id, zone, contact_email, contact_phone, bank_name, bank_account_no, bank_ifsc, upi_vpa, logo_url, currency, timezone, is_active, tagline, total_units, is_deleted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)");
        $stmt->execute([
            $data['society_code'],
            $data['name'],
            $data['legal_name'] ?? $data['name'],
            $data['registration_number'] ?? null,
            $data['address_line1'] ?? ($data['address'] ?? null),
            $data['address_line2'] ?? null,
            $data['address'] ?? ($data['address_line1'] ?? null),
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['pincode'] ?? null,
            $data['country'] ?? 'India',
            $data['zone_id'] ?? null,
            $data['zone'] ?? null,
            $data['contact_email'] ?? null,
            $data['contact_phone'] ?? null,
            $data['bank_name'] ?? null,
            $data['bank_account_no'] ?? null,
            $data['bank_ifsc'] ?? null,
            $data['upi_vpa'] ?? null,
            $data['logo_url'] ?? null,
            $data['currency'] ?? 'INR',
            $data['timezone'] ?? 'Asia/Kolkata',
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $data['tagline'] ?? null
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowed = [
            'name', 'legal_name', 'society_code', 'registration_number', 
            'address_line1', 'address_line2', 'address', 
            'city', 'state', 'pincode', 'country', 'zone_id', 'zone',
            'contact_email', 'contact_phone', 'bank_name', 'bank_account_no', 
            'bank_ifsc', 'upi_vpa', 'logo_url', 
            'currency', 'timezone', 'is_active', 'tagline'
        ];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "`{$col}` = ?";
                $params[] = $data[$col];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE societies SET " . implode(', ', $fields) . " WHERE id = ? AND is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("UPDATE societies SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        return $stmt->execute([$id]);
    }
}
