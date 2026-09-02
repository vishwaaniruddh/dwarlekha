<?php
namespace App\Models;

use PDO;

class Amenity extends BaseModel {
    public function getAll(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();
        
        $sql = "SELECT a.*, s.name AS society_name, s.society_code AS society_code 
                FROM amenities a 
                LEFT JOIN societies s ON a.society_id = s.id 
                WHERE a.is_deleted = 0";
        $params = [];

        if ($societyId > 0) {
            $sql .= " AND a.society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " ORDER BY a.id DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $amenities = $stmt->fetchAll();

        // Fetch bookings for these amenities
        $sqlBookings = "SELECT b.*, a.amenity_code 
            FROM facility_bookings b 
            JOIN amenities a ON b.amenity_id = a.id 
            WHERE b.is_deleted = 0";
        $bookingParams = [];

        if ($societyId > 0) {
            $sqlBookings .= " AND b.society_id = ?";
            $bookingParams[] = $societyId;
        }

        $sqlBookings .= " ORDER BY b.id DESC";
        $stmtBookings = $this->db->prepare($sqlBookings);
        $stmtBookings->execute($bookingParams);

        $bookingsByAmenity = [];
        while ($b = $stmtBookings->fetch()) {
            $bookingsByAmenity[$b['amenity_code']][] = [
                'id' => $b['booking_code'],
                'slot' => $b['time_slot'],
                'user' => $b['resident_name'],
                'type' => $b['purpose'],
                'amountPaid' => (float)($b['amount_paid'] ?? 0),
                'status' => $b['status']
            ];
        }

        return array_map(function($a) use ($bookingsByAmenity) {
            return $this->formatRow($a, $bookingsByAmenity[$a['amenity_code']] ?? []);
        }, $amenities);
    }

    public function findById(string $codeOrId): ?array {
        $stmt = $this->db->prepare("SELECT a.*, s.name AS society_name, s.society_code AS society_code 
            FROM amenities a 
            LEFT JOIN societies s ON a.society_id = s.id 
            WHERE (a.amenity_code = ? OR a.id = ?) AND a.is_deleted = 0 LIMIT 1");
        $stmt->execute([$codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
        $row = $stmt->fetch();
        return $row ? $this->formatRow($row) : null;
    }

    public function create(array $data, ?int $societyId = null): int {
        $societyId = $societyId ?: ($data['society_id'] ?? ($data['societyId'] ?? $this->getSocietyId()));
        $code = $data['amenity_code'] ?? ($data['id'] ?? ('AMN-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $data['name'] ?? 'FAC'), 0, 4)) . '-' . rand(10, 99)));

        $mediaJson = null;
        if (!empty($data['media'])) {
            $mediaJson = is_string($data['media']) ? $data['media'] : json_encode($data['media']);
        }

        $stmt = $this->db->prepare("INSERT INTO amenities 
            (amenity_code, society_id, name, category, hourly_rate, capacity, current_occupancy, operating_hours, status, location, description, rules, image_url, media, is_deleted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

        $stmt->execute([
            $code,
            $societyId,
            $data['name'],
            $data['category'] ?? 'Clubhouse',
            (float)($data['hourly_rate'] ?? ($data['hourlyRate'] ?? 0)),
            (int)($data['capacity'] ?? 20),
            (int)($data['current_occupancy'] ?? ($data['currentOccupancy'] ?? 0)),
            $data['operating_hours'] ?? ($data['operatingHours'] ?? '06:00 AM - 10:00 PM'),
            $data['status'] ?? 'Available',
            $data['location'] ?? 'Clubhouse',
            $data['description'] ?? '',
            $data['rules'] ?? '',
            $data['image_url'] ?? ($data['image'] ?? ''),
            $mediaJson
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(string $codeOrId, array $data): bool {
        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : (!empty($data['societyId']) ? (int)$data['societyId'] : null);

        $mediaJson = null;
        if (isset($data['media'])) {
            $mediaJson = is_string($data['media']) ? $data['media'] : json_encode($data['media']);
        }

        $sql = "UPDATE amenities SET 
            name = ?, 
            category = ?, 
            hourly_rate = ?, 
            capacity = ?, 
            current_occupancy = ?, 
            operating_hours = ?, 
            status = ?, 
            location = ?, 
            description = ?, 
            rules = ?, 
            image_url = ?";
        
        $params = [
            $data['name'],
            $data['category'] ?? 'Clubhouse',
            (float)($data['hourly_rate'] ?? ($data['hourlyRate'] ?? 0)),
            (int)($data['capacity'] ?? 20),
            (int)($data['current_occupancy'] ?? ($data['currentOccupancy'] ?? 0)),
            $data['operating_hours'] ?? ($data['operatingHours'] ?? '06:00 AM - 10:00 PM'),
            $data['status'] ?? 'Available',
            $data['location'] ?? 'Clubhouse',
            $data['description'] ?? '',
            $data['rules'] ?? '',
            $data['image_url'] ?? ($data['image'] ?? '')
        ];

        if ($mediaJson !== null) {
            $sql .= ", media = ?";
            $params[] = $mediaJson;
        }

        if ($societyId !== null && $societyId > 0) {
            $sql .= ", society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " WHERE (amenity_code = ? OR id = ?) AND is_deleted = 0";
        $params[] = $codeOrId;
        $params[] = is_numeric($codeOrId) ? (int)$codeOrId : 0;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function delete(string $codeOrId): bool {
        // Strict Soft Delete per AGENTS.md Directive 4
        $stmt = $this->db->prepare("UPDATE amenities SET is_deleted = 1, deleted_at = NOW() WHERE (amenity_code = ? OR id = ?) AND is_deleted = 0");
        return $stmt->execute([$codeOrId, is_numeric($codeOrId) ? (int)$codeOrId : 0]);
    }

    public function bookSlot(string $amenityCode, array $bookingData, ?int $societyId = null): string {
        $societyId = $societyId ?: $this->getSocietyId();
        $stmtAm = $this->db->prepare("SELECT id FROM amenities WHERE (amenity_code = ? OR id = ?) AND is_deleted = 0 LIMIT 1");
        $stmtAm->execute([$amenityCode, is_numeric($amenityCode) ? (int)$amenityCode : 0]);
        $amenityId = $stmtAm->fetchColumn();

        if (!$amenityId) {
            throw new \Exception("Amenity {$amenityCode} not found.");
        }

        $code = 'BK-' . rand(900, 999);
        $stmt = $this->db->prepare("INSERT INTO facility_bookings 
            (booking_code, society_id, amenity_id, resident_name, time_slot, purpose, amount_paid, status, is_deleted) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Confirmed', 0)");

        $stmt->execute([
            $code,
            $societyId,
            $amenityId,
            $bookingData['user'] ?? ($bookingData['resident_name'] ?? 'Resident'),
            $bookingData['slot'] ?? ($bookingData['time_slot'] ?? 'Tomorrow, 06:00 PM - 08:00 PM'),
            $bookingData['type'] ?? ($bookingData['purpose'] ?? 'Private Slot'),
            (float)($bookingData['amountPaid'] ?? ($bookingData['amount_paid'] ?? 0))
        ]);

        return $code;
    }

    private function formatRow(array $a, array $bookings = []): array {
        $mediaList = [];
        if (!empty($a['media'])) {
            $decoded = is_string($a['media']) ? json_decode($a['media'], true) : $a['media'];
            if (is_array($decoded)) {
                $mediaList = $decoded;
            }
        }

        // If no media JSON but has image_url, add it as default image
        if (empty($mediaList) && !empty($a['image_url'])) {
            $mediaList[] = [
                'url' => $a['image_url'],
                'type' => 'image',
                'name' => $a['name'] . ' Cover'
            ];
        }

        return [
            'id' => $a['amenity_code'],
            'amenity_code' => $a['amenity_code'],
            'dbId' => (int)$a['id'],
            'societyId' => (int)$a['society_id'],
            'society_id' => (int)$a['society_id'],
            'societyName' => $a['society_name'] ?? 'Society Tenant',
            'name' => $a['name'],
            'category' => $a['category'],
            'hourlyRate' => (float)$a['hourly_rate'],
            'hourly_rate' => (float)$a['hourly_rate'],
            'capacity' => (int)$a['capacity'],
            'currentOccupancy' => (int)$a['current_occupancy'],
            'current_occupancy' => (int)$a['current_occupancy'],
            'operatingHours' => $a['operating_hours'],
            'operating_hours' => $a['operating_hours'],
            'status' => $a['status'] ?: 'Available',
            'location' => $a['location'] ?: 'Clubhouse',
            'description' => $a['description'] ?: '',
            'rules' => $a['rules'] ?: '',
            'image' => $a['image_url'],
            'image_url' => $a['image_url'],
            'media' => $mediaList,
            'bookings' => $bookings,
            'createdAt' => $a['created_at']
        ];
    }
}
