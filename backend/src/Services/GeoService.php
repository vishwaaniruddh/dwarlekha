<?php
namespace App\Services;

use App\Config\Database;
use PDO;

class GeoService {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function getCountries(): array {
        try {
            $stmt = $this->db->query("SELECT id, name, status FROM countries WHERE status = 'active' ORDER BY id ASC, name ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getZones(): array {
        try {
            $stmt = $this->db->query("SELECT id, name, status FROM zones WHERE status = 'active' ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getStates(?int $countryId = null, ?int $zoneId = null): array {
        try {
            $sql = "SELECT s.id, s.name, s.country_id, s.zone_id, z.name as zone_name, s.status 
                    FROM states s 
                    LEFT JOIN zones z ON s.zone_id = z.id 
                    WHERE s.status = 'active'";
            $params = [];

            if ($countryId !== null && $countryId > 0) {
                $sql .= " AND s.country_id = ?";
                $params[] = $countryId;
            }

            if ($zoneId !== null && $zoneId > 0) {
                $sql .= " AND s.zone_id = ?";
                $params[] = $zoneId;
            }

            $sql .= " ORDER BY s.name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getCities(?int $stateId = null, ?int $countryId = null, ?int $zoneId = null): array {
        try {
            $sql = "SELECT c.id, c.name, c.state_id, c.country_id, c.zone_id, s.name as state_name, z.name as zone_name, c.status 
                    FROM cities c 
                    LEFT JOIN states s ON c.state_id = s.id 
                    LEFT JOIN zones z ON c.zone_id = z.id 
                    WHERE c.status = 'active'";
            $params = [];

            if ($stateId !== null && $stateId > 0) {
                $sql .= " AND c.state_id = ?";
                $params[] = $stateId;
            }

            if ($countryId !== null && $countryId > 0) {
                $sql .= " AND c.country_id = ?";
                $params[] = $countryId;
            }

            if ($zoneId !== null && $zoneId > 0) {
                $sql .= " AND c.zone_id = ?";
                $params[] = $zoneId;
            }

            $sql .= " ORDER BY c.name ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function getGeoLookup(): array {
        return [
            'countries' => $this->getCountries(),
            'zones' => $this->getZones(),
            'states' => $this->getStates(),
            'cities' => $this->getCities()
        ];
    }
}