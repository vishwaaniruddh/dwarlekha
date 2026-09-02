<?php
namespace App\Models;

use PDO;

class Society extends BaseModel {
    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM societies WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByCode(string $code): ?array {
        $stmt = $this->db->prepare("SELECT * FROM societies WHERE society_code = ? LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getAll(): array {
        $stmt = $this->db->query("SELECT * FROM societies ORDER BY id ASC");
        return $stmt->fetchAll();
    }

    public function create(array $data): int {
        $stmt = $this->db->prepare("INSERT INTO societies 
            (society_code, name, registration_number, address_line1, address_line2, address, city, state, pincode, country, zone_id, zone, contact_email, contact_phone, logo_url, currency, timezone, is_active, tagline, total_units) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['society_code'],
            $data['name'],
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
            $data['logo_url'] ?? null,
            $data['currency'] ?? 'INR',
            $data['timezone'] ?? 'Asia/Kolkata',
            isset($data['is_active']) ? (int)$data['is_active'] : 1,
            $data['tagline'] ?? null,
            $data['total_units'] ?? 0
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [];

        $allowed = [
            'name', 'society_code', 'registration_number', 
            'address_line1', 'address_line2', 'address', 
            'city', 'state', 'pincode', 'country', 'zone_id', 'zone',
            'contact_email', 'contact_phone', 'logo_url', 
            'currency', 'timezone', 'is_active', 'tagline', 'total_units'
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
        $sql = "UPDATE societies SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }
}
