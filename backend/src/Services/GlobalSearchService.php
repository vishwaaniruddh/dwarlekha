<?php
namespace App\Services;

use App\Config\Database;
use PDO;

class GlobalSearchService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?: Database::getConnection();
    }

    /**
     * Resolve string or numeric society identifier to integer ID
     */
    private function resolveSocietyId($societyParam): int {
        if (empty($societyParam)) {
            return 0;
        }
        $str = strtoupper(trim((string)$societyParam));
        if (in_array($str, ['ALL', 'GLOBAL', '0', 'SAR-HQ', ''], true)) {
            return 0;
        }
        if (is_numeric($societyParam)) {
            return (int)$societyParam;
        }

        $stmt = $this->db->prepare("SELECT id FROM societies WHERE society_code = ? OR name = ? LIMIT 1");
        $stmt->execute([$societyParam, $societyParam]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    /**
     * Unified Global Search across Societies, Persons (Owners/Tenants/Gatekeepers/Staff), and Vehicles
     */
    public function search(string $query, array $options = []): array {
        $q = trim($query);
        if ($q === '') {
            return [
                'query' => '',
                'counts' => [
                    'all' => 0,
                    'societies' => 0,
                    'persons' => 0,
                    'vehicles' => 0
                ],
                'results' => [
                    'societies' => [],
                    'persons' => [],
                    'vehicles' => []
                ]
            ];
        }

        $societyId = $this->resolveSocietyId($options['society_id'] ?? null);
        $type = strtolower($options['type'] ?? 'all'); // 'all', 'societies', 'persons', 'vehicles'
        $limit = max(1, min(100, (int)($options['limit'] ?? 50)));

        $societies = ($type === 'all' || $type === 'societies' || $type === 'society') ? $this->searchSocieties($q, $limit) : [];
        $persons = ($type === 'all' || $type === 'persons' || $type === 'person') ? $this->searchPersons($q, $societyId, $limit) : [];
        $vehicles = ($type === 'all' || $type === 'vehicles' || $type === 'vehicle') ? $this->searchVehicles($q, $societyId, $limit) : [];

        $totalCount = count($societies) + count($persons) + count($vehicles);

        return [
            'query' => $q,
            'counts' => [
                'all' => $totalCount,
                'societies' => count($societies),
                'persons' => count($persons),
                'vehicles' => count($vehicles)
            ],
            'results' => [
                'societies' => $societies,
                'persons' => $persons,
                'vehicles' => $vehicles
            ]
        ];
    }

    /**
     * 1. Search Societies
     */
    public function searchSocieties(string $query, int $limit = 50): array {
        $term = '%' . $query . '%';
        $sql = "SELECT s.id, s.society_code, s.name, s.legal_name, s.registration_number, 
                       s.address, s.city, s.state, s.pincode, s.contact_email, s.contact_phone,
                       s.total_units, s.is_active, s.logo_url
                FROM societies s
                WHERE s.is_deleted = 0 
                  AND (s.name LIKE ? OR s.legal_name LIKE ? OR s.society_code LIKE ? 
                       OR s.city LIKE ? OR s.state LIKE ? OR s.registration_number LIKE ? 
                       OR s.contact_phone LIKE ? OR s.contact_email LIKE ?)
                ORDER BY s.id ASC
                LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$term, $term, $term, $term, $term, $term, $term, $term]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'result_type' => 'society',
                'title' => $r['name'],
                'subtitle' => ($r['city'] ? $r['city'] . ', ' : '') . ($r['state'] ?? 'India'),
                'society_id' => (int)$r['id'],
                'society_code' => $r['society_code'],
                'society_name' => $r['name'],
                'registration_number' => $r['registration_number'],
                'total_units' => (int)$r['total_units'],
                'contact_phone' => $r['contact_phone'],
                'contact_email' => $r['contact_email'],
                'address' => $r['address'],
                'city' => $r['city'],
                'state' => $r['state'],
                'is_active' => (bool)$r['is_active'],
                'logo_url' => $r['logo_url']
            ];
        }, $rows);
    }

    /**
     * Helper to merge & deduplicate person records
     */
    private function mergePersonRecord(array &$personMap, array &$dedupIndex, array $data): void {
        $phoneClean = preg_replace('/[^0-9]/', '', (string)($data['phone'] ?? ''));
        $phoneSuffix = strlen($phoneClean) >= 8 ? substr($phoneClean, -10) : '';
        $nameNorm = strtolower(preg_replace('/[^a-z0-9]/', '', (string)($data['name'] ?? '')));
        $unitNorm = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string)($data['unit_code'] ?? '')));
        $socId = (int)($data['society_id'] ?? 0);

        $matchedKey = null;
        if (!empty($data['user_id']) && isset($dedupIndex['uid_' . $data['user_id']])) {
            $matchedKey = $dedupIndex['uid_' . $data['user_id']];
        } elseif (!empty($data['resident_id']) && isset($dedupIndex['res_' . $data['resident_id']])) {
            $matchedKey = $dedupIndex['res_' . $data['resident_id']];
        } elseif (!empty($phoneSuffix) && isset($dedupIndex['p_' . $socId . '_' . $phoneSuffix])) {
            $matchedKey = $dedupIndex['p_' . $socId . '_' . $phoneSuffix];
        } elseif (!empty($unitNorm) && !empty($nameNorm) && isset($dedupIndex['un_' . $socId . '_' . $unitNorm . '_' . $nameNorm])) {
            $matchedKey = $dedupIndex['un_' . $socId . '_' . $unitNorm . '_' . $nameNorm];
        }

        if ($matchedKey && isset($personMap[$matchedKey])) {
            $existing = &$personMap[$matchedKey];

            // Merge roles
            if (!empty($data['role_label']) && !in_array($data['role_label'], $existing['roles_list'], true)) {
                $existing['roles_list'][] = $data['role_label'];
            }
            if (!empty($data['badge']) && !in_array($data['badge'], $existing['badges'], true)) {
                $existing['badges'][] = $data['badge'];
            }

            // Enrich missing fields
            if (empty($existing['user_id']) && !empty($data['user_id'])) $existing['user_id'] = (int)$data['user_id'];
            if (empty($existing['resident_id']) && !empty($data['resident_id'])) $existing['resident_id'] = (int)$data['resident_id'];
            if (empty($existing['unit_id']) && !empty($data['unit_id'])) $existing['unit_id'] = (int)$data['unit_id'];
            if (empty($existing['unit_code']) && !empty($data['unit_code'])) $existing['unit_code'] = $data['unit_code'];
            if (empty($existing['avatar_url']) && !empty($data['avatar_url'])) $existing['avatar_url'] = $data['avatar_url'];
            if (empty($existing['phone']) && !empty($data['phone'])) $existing['phone'] = $data['phone'];
            if (empty($existing['email']) && !empty($data['email'])) $existing['email'] = $data['email'];
            if (empty($existing['user_code']) && !empty($data['user_code'])) $existing['user_code'] = $data['user_code'];
            if (empty($existing['tower_name']) && !empty($data['tower_name'])) $existing['tower_name'] = $data['tower_name'];

            // Synthesize combined role label
            $existing['role_label'] = implode(' • ', $existing['roles_list']);
            return;
        }

        // New canonical record
        $primaryKey = 'person_' . uniqid();
        $data['roles_list'] = [ $data['role_label'] ];
        $data['badges'] = [ $data['badge'] ?? strtoupper($data['person_category'] ?? 'PERSON') ];
        $personMap[$primaryKey] = $data;

        // Register mapping index
        if (!empty($data['user_id'])) {
            $dedupIndex['uid_' . $data['user_id']] = $primaryKey;
        }
        if (!empty($data['resident_id'])) {
            $dedupIndex['res_' . $data['resident_id']] = $primaryKey;
        }
        if (!empty($phoneSuffix)) {
            $dedupIndex['p_' . $socId . '_' . $phoneSuffix] = $primaryKey;
        }
        if (!empty($unitNorm) && !empty($nameNorm)) {
            $dedupIndex['un_' . $socId . '_' . $unitNorm . '_' . $nameNorm] = $primaryKey;
        }
    }

    /**
     * 2. Search Persons (Owners, Tenants, Gatekeepers/Guards, Staff, Contacts, Units) - Deduplicated
     */
    public function searchPersons(string $query, int $societyId = 0, int $limit = 50): array {
        $term = '%' . $query . '%';
        $cleanPhone = preg_replace('/[^0-9]/', '', $query);
        $phoneTerm = $cleanPhone !== '' ? '%' . $cleanPhone . '%' : $term;

        $personMap = [];
        $dedupIndex = [];

        // A. Search Registered Residents (Owners & Tenants)
        $resSql = "SELECT r.id as resident_id, r.society_id, r.resident_type, r.verification_status,
                          u.id as user_id, u.full_name, u.email, u.phone, u.avatar_url, u.user_code,
                          un.id as unit_id, un.unit_code, un.floor_number, un.owner_name, un.contact_phone as owner_phone, un.contact_email as owner_email,
                          t.name as tower_name,
                          s.name as society_name, s.society_code
                   FROM residents r
                   JOIN units un ON r.unit_id = un.id
                   LEFT JOIN towers t ON un.tower_id = t.id
                   LEFT JOIN users u ON r.user_id = u.id
                   LEFT JOIN societies s ON r.society_id = s.id
                   WHERE r.is_deleted = 0 AND un.is_deleted = 0";

        $resParams = [];
        if ($societyId > 0) {
            $resSql .= " AND r.society_id = ?";
            $resParams[] = $societyId;
        }

        $resSql .= " AND (
            u.full_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.user_code LIKE ?
            OR un.owner_name LIKE ? OR un.contact_phone LIKE ? OR un.contact_email LIKE ? OR un.unit_code LIKE ?
        ) LIMIT {$limit}";

        $resParams = array_merge($resParams, [
            $term, $phoneTerm, $term, $term,
            $term, $phoneTerm, $term, $term
        ]);

        $stmt = $this->db->prepare($resSql);
        $stmt->execute($resParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $name = $row['full_name'] ?: ($row['owner_name'] ?: 'Resident');
            $phone = $row['phone'] ?: ($row['owner_phone'] ?: '');
            $email = $row['email'] ?: ($row['owner_email'] ?: '');
            $isOwner = $row['resident_type'] === 'Owner';

            $this->mergePersonRecord($personMap, $dedupIndex, [
                'id' => (int)$row['resident_id'],
                'resident_id' => (int)$row['resident_id'],
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'unit_id' => (int)$row['unit_id'],
                'result_type' => 'person',
                'person_category' => $isOwner ? 'owner' : 'tenant',
                'badge' => $isOwner ? 'OWNER' : 'TENANT',
                'role_label' => $isOwner ? 'Flat Owner' : 'Resident Tenant',
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'unit_code' => $row['unit_code'],
                'tower_name' => $row['tower_name'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'society_code' => $row['society_code'],
                'avatar_url' => $row['avatar_url'],
                'status' => $row['verification_status'] ?: 'Active',
                'user_code' => $row['user_code']
            ]);
        }

        // B. Search Users (Gatekeepers, Guards, Admins, Managers, Staff)
        $userSql = "SELECT u.id as user_id, u.society_id, u.is_parent_user, u.full_name, u.email, u.phone, 
                           u.unit_code, u.status as user_status, u.avatar_url, u.user_code,
                           r.role_code, r.name as role_name, r.badge_color,
                           s.name as society_name, s.society_code
                    FROM users u
                    JOIN roles r ON u.role_id = r.id
                    LEFT JOIN societies s ON u.society_id = s.id
                    WHERE u.is_deleted = 0";

        $userParams = [];
        if ($societyId > 0) {
            $userSql .= " AND (u.society_id = ? OR u.is_parent_user = 1)";
            $userParams[] = $societyId;
        }

        $userSql .= " AND (
            u.full_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.unit_code LIKE ? OR u.user_code LIKE ? OR r.name LIKE ?
        ) LIMIT {$limit}";

        $userParams = array_merge($userParams, [$term, $phoneTerm, $term, $term, $term, $term]);

        $stmt = $this->db->prepare($userSql);
        $stmt->execute($userParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $roleCode = strtolower($row['role_code'] ?? '');
            $personCat = 'staff';
            $badge = 'STAFF';

            if ($roleCode === 'security_guard' || str_contains($roleCode, 'guard') || str_contains($roleCode, 'gate')) {
                $personCat = 'gatekeeper';
                $badge = 'GUARD';
            } elseif ($roleCode === 'resident') {
                $personCat = 'resident';
                $badge = 'RESIDENT';
            } elseif ($row['is_parent_user'] || str_contains($roleCode, 'admin') || str_contains($roleCode, 'director')) {
                $personCat = 'admin';
                $badge = 'ADMIN';
            }

            $this->mergePersonRecord($personMap, $dedupIndex, [
                'id' => (int)$row['user_id'],
                'user_id' => (int)$row['user_id'],
                'resident_id' => null,
                'unit_id' => null,
                'result_type' => 'person',
                'person_category' => $personCat,
                'badge' => $badge,
                'role_label' => $row['role_name'],
                'name' => $row['full_name'],
                'phone' => $row['phone'],
                'email' => $row['email'],
                'unit_code' => $row['unit_code'],
                'tower_name' => null,
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'] ?: ($row['is_parent_user'] ? 'Global Platform' : ''),
                'society_code' => $row['society_code'],
                'avatar_url' => $row['avatar_url'],
                'status' => $row['user_status'],
                'user_code' => $row['user_code']
            ]);
        }

        // C. Search Units Directly (For Units with Owner metadata not yet registered in residents)
        $unitSql = "SELECT un.id as unit_id, un.society_id, un.unit_code, un.floor_number, 
                           un.owner_name, un.contact_phone as owner_phone, un.contact_email as owner_email,
                           un.occupancy_status,
                           t.name as tower_name,
                           s.name as society_name, s.society_code
                    FROM units un
                    LEFT JOIN towers t ON un.tower_id = t.id
                    LEFT JOIN societies s ON un.society_id = s.id
                    WHERE un.is_deleted = 0";

        $unitParams = [];
        if ($societyId > 0) {
            $unitSql .= " AND un.society_id = ?";
            $unitParams[] = $societyId;
        }

        $unitSql .= " AND (
            un.owner_name LIKE ? OR un.contact_phone LIKE ? OR un.contact_email LIKE ? 
            OR un.unit_code LIKE ? OR REPLACE(un.unit_code, '-', '') LIKE ?
        ) LIMIT {$limit}";

        $unitParams = array_merge($unitParams, [$term, $phoneTerm, $term, $term, $term]);

        $stmt = $this->db->prepare($unitSql);
        $stmt->execute($unitParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (empty($row['owner_name']) && empty($row['owner_phone'])) {
                continue;
            }

            $this->mergePersonRecord($personMap, $dedupIndex, [
                'id' => (int)$row['unit_id'],
                'unit_id' => (int)$row['unit_id'],
                'resident_id' => null,
                'user_id' => null,
                'result_type' => 'person',
                'person_category' => 'owner',
                'badge' => 'OWNER',
                'role_label' => 'Property Owner',
                'name' => $row['owner_name'] ?: ('Flat ' . $row['unit_code'] . ' Owner'),
                'phone' => $row['owner_phone'] ?: '',
                'email' => $row['owner_email'] ?: '',
                'unit_code' => $row['unit_code'],
                'tower_name' => $row['tower_name'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'society_code' => $row['society_code'],
                'avatar_url' => null,
                'status' => $row['occupancy_status'] ?: 'Active',
                'user_code' => null
            ]);
        }

        // D. Search Family Members
        $famSql = "SELECT fm.id as member_id, fm.full_name as member_name, fm.relation, fm.phone as member_phone,
                          r.id as resident_id, r.society_id, r.resident_type,
                          un.id as unit_id, un.unit_code, t.name as tower_name,
                          s.name as society_name, s.society_code
                   FROM family_members fm
                   JOIN residents r ON fm.resident_id = r.id
                   JOIN units un ON r.unit_id = un.id
                   LEFT JOIN towers t ON un.tower_id = t.id
                   LEFT JOIN societies s ON r.society_id = s.id
                   WHERE fm.is_deleted = 0 AND r.is_deleted = 0";

        $famParams = [];
        if ($societyId > 0) {
            $famSql .= " AND r.society_id = ?";
            $famParams[] = $societyId;
        }

        $famSql .= " AND (fm.full_name LIKE ? OR fm.phone LIKE ?) LIMIT {$limit}";
        $famParams = array_merge($famParams, [$term, $phoneTerm]);

        $stmt = $this->db->prepare($famSql);
        $stmt->execute($famParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->mergePersonRecord($personMap, $dedupIndex, [
                'id' => (int)$row['member_id'],
                'resident_id' => (int)$row['resident_id'],
                'unit_id' => (int)$row['unit_id'],
                'user_id' => null,
                'result_type' => 'person',
                'person_category' => 'family',
                'badge' => 'FAMILY',
                'role_label' => 'Family Member (' . ($row['relation'] ?: 'Relative') . ')',
                'name' => $row['member_name'],
                'phone' => $row['member_phone'],
                'email' => '',
                'unit_code' => $row['unit_code'],
                'tower_name' => $row['tower_name'],
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'society_code' => $row['society_code'],
                'avatar_url' => null,
                'status' => 'Active',
                'user_code' => null
            ]);
        }

        // E. Search Society Contacts & Helplines
        $contSql = "SELECT sc.*, s.name as society_name, s.society_code
                    FROM society_contacts sc
                    LEFT JOIN societies s ON sc.society_id = s.id
                    WHERE sc.is_deleted = 0";

        $contParams = [];
        if ($societyId > 0) {
            $contSql .= " AND sc.society_id = ?";
            $contParams[] = $societyId;
        }

        $contSql .= " AND (
            sc.name LIKE ? OR sc.phone LIKE ? OR sc.alternate_phone LIKE ? OR sc.email LIKE ? OR sc.designation LIKE ? OR sc.category LIKE ?
        ) LIMIT {$limit}";

        $contParams = array_merge($contParams, [$term, $phoneTerm, $phoneTerm, $term, $term, $term]);

        $stmt = $this->db->prepare($contSql);
        $stmt->execute($contParams);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cat = $row['contact_type'] === 'service_vendor' ? 'vendor' : ($row['contact_type'] === 'emergency' ? 'emergency' : 'management');
            $badge = strtoupper($cat);
            $this->mergePersonRecord($personMap, $dedupIndex, [
                'id' => (int)$row['id'],
                'resident_id' => null,
                'unit_id' => null,
                'user_id' => null,
                'result_type' => 'person',
                'person_category' => $cat,
                'badge' => $badge,
                'role_label' => $row['designation'] ?: ($row['category'] . ' Officer'),
                'name' => $row['name'],
                'phone' => $row['phone'],
                'alternate_phone' => $row['alternate_phone'],
                'email' => $row['email'],
                'unit_code' => $row['unit_or_office'],
                'tower_name' => null,
                'society_id' => (int)$row['society_id'],
                'society_name' => $row['society_name'],
                'society_code' => $row['society_code'],
                'avatar_url' => $row['avatar_url'],
                'status' => $row['status'] ?: 'Active',
                'user_code' => null
            ]);
        }

        $uniqueList = array_values($personMap);
        return array_slice($uniqueList, 0, $limit);
    }

    /**
     * 3. Search Vehicles (by Number, Model, Owner/Tenant, Flat)
     */
    public function searchVehicles(string $query, int $societyId = 0, int $limit = 50): array {
        $cleanQuery = strtoupper(str_replace([' ', '-', '.'], '', $query));
        $term = '%' . $query . '%';
        $compactTerm = '%' . $cleanQuery . '%';

        $sql = "SELECT v.id, v.society_id, v.unit_id, v.resident_id, v.vehicle_number, 
                       v.vehicle_type, v.make_model, v.parking_slot_number, v.pass_status, v.rfid_sticker_tag,
                       un.unit_code, un.floor_number, un.owner_name, un.contact_phone as unit_owner_phone,
                       t.name as tower_name,
                       r.resident_type,
                       usr.id as user_id, usr.full_name as resident_name, usr.phone as resident_phone, usr.avatar_url,
                       s.name as society_name, s.society_code
                FROM vehicles v
                JOIN units un ON v.unit_id = un.id
                LEFT JOIN towers t ON un.tower_id = t.id
                LEFT JOIN residents r ON v.resident_id = r.id
                LEFT JOIN users usr ON r.user_id = usr.id
                LEFT JOIN societies s ON v.society_id = s.id
                WHERE v.is_deleted = 0 AND un.is_deleted = 0";

        $params = [];
        if ($societyId > 0) {
            $sql .= " AND v.society_id = ?";
            $params[] = $societyId;
        }

        $sql .= " AND (
            REPLACE(REPLACE(REPLACE(v.vehicle_number, ' ', ''), '-', ''), '.', '') LIKE ?
            OR v.vehicle_number LIKE ?
            OR v.make_model LIKE ?
            OR v.parking_slot_number LIKE ?
            OR v.rfid_sticker_tag LIKE ?
            OR un.unit_code LIKE ?
            OR REPLACE(un.unit_code, '-', '') LIKE ?
            OR un.owner_name LIKE ?
            OR usr.full_name LIKE ?
            OR usr.phone LIKE ?
            OR un.contact_phone LIKE ?
        )
        ORDER BY v.id DESC
        LIMIT {$limit}";

        $params = array_merge($params, [
            $compactTerm, $term, $term, $term, $term, $term, $term,
            $term, $term, $term, $term
        ]);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function($r) {
            $ownerName = $r['resident_name'] ?: ($r['owner_name'] ?: 'Resident');
            $ownerPhone = $r['resident_phone'] ?: ($r['unit_owner_phone'] ?: '');

            return [
                'id' => (int)$r['id'],
                'result_type' => 'vehicle',
                'vehicle_number' => strtoupper($r['vehicle_number']),
                'vehicle_type' => $r['vehicle_type'] ?: 'Car',
                'make_model' => $r['make_model'] ?: 'Vehicle',
                'parking_slot' => $r['parking_slot_number'],
                'pass_status' => $r['pass_status'] ?: 'Valid',
                'rfid_tag' => $r['rfid_sticker_tag'],
                'unit_id' => (int)$r['unit_id'],
                'unit_code' => $r['unit_code'],
                'tower_name' => $r['tower_name'],
                'resident_id' => $r['resident_id'] ? (int)$r['resident_id'] : null,
                'user_id' => $r['user_id'] ? (int)$r['user_id'] : null,
                'resident_name' => $ownerName,
                'resident_phone' => $ownerPhone,
                'resident_type' => $r['resident_type'] ?: 'Owner',
                'society_id' => (int)$r['society_id'],
                'society_name' => $r['society_name'],
                'society_code' => $r['society_code']
            ];
        }, $rows);
    }

    /**
     * 4. Full Master Dossier & History for Entities (Person, Vehicle, Society)
     */
    public function getEntityDossier(string $type, int $id, array $params = []): array {
        $type = strtolower($type);

        if ($type === 'person') {
            return $this->getPersonDossier($id, $params);
        } elseif ($type === 'vehicle') {
            return $this->getVehicleDossier($id);
        } elseif ($type === 'society') {
            return $this->getSocietyDossier($id);
        }

        return ['error' => 'Unknown entity type'];
    }

    /**
     * Person Master Dossier (Profile, Unit Specs, Vehicles, Family, Documents, Billing Ledger, Gate Visits, Tickets)
     */
    private function getPersonDossier(int $id, array $params = []): array {
        $userId = !empty($params['user_id']) ? (int)$params['user_id'] : null;
        $residentId = !empty($params['resident_id']) ? (int)$params['resident_id'] : $id;
        $unitId = !empty($params['unit_id']) ? (int)$params['unit_id'] : null;

        // 1. Fetch Person Base Details
        $person = null;
        $unit = null;
        $society = null;

        if ($residentId > 0) {
            $stmt = $this->db->prepare("
                SELECT r.*, u.full_name, u.email, u.phone, u.avatar_url, u.user_code, u.status as user_status,
                       un.unit_code, un.floor_number, un.unit_type, un.sqft_area, un.occupancy_status, un.maintenance_status,
                       t.name as tower_name,
                       s.id as society_id, s.name as society_name, s.society_code, s.city, s.address
                FROM residents r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN units un ON r.unit_id = un.id
                LEFT JOIN towers t ON un.tower_id = t.id
                LEFT JOIN societies s ON r.society_id = s.id
                WHERE r.id = ? AND r.is_deleted = 0
            ");
            $stmt->execute([$residentId]);
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($person) {
                $unitId = $unitId ?: (int)($person['unit_id'] ?? 0);
                $userId = $userId ?: (int)($person['user_id'] ?? 0);
            }
        }

        if (!$person && $userId > 0) {
            $stmt = $this->db->prepare("
                SELECT u.*, r.id as resident_id, r.resident_type, r.verification_status,
                       un.id as unit_id, un.unit_code, un.floor_number, un.unit_type, un.sqft_area, un.occupancy_status, un.maintenance_status,
                       t.name as tower_name,
                       s.id as society_id, s.name as society_name, s.society_code, s.city, s.address,
                       ro.name as role_name, ro.role_code
                FROM users u
                LEFT JOIN residents r ON u.resident_id = r.id OR (r.user_id = u.id AND r.is_deleted = 0)
                LEFT JOIN units un ON r.unit_id = un.id OR un.unit_code = u.unit_code
                LEFT JOIN towers t ON un.tower_id = t.id
                LEFT JOIN societies s ON u.society_id = s.id
                LEFT JOIN roles ro ON u.role_id = ro.id
                WHERE u.id = ? AND u.is_deleted = 0
            ");
            $stmt->execute([$userId]);
            $person = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($person && !empty($person['unit_id'])) {
                $unitId = (int)$person['unit_id'];
            }
        }

        if (!$person && $unitId > 0) {
            $stmt = $this->db->prepare("
                SELECT un.*, t.name as tower_name,
                       s.id as society_id, s.name as society_name, s.society_code, s.city, s.address
                FROM units un
                LEFT JOIN towers t ON un.tower_id = t.id
                LEFT JOIN societies s ON un.society_id = s.id
                WHERE un.id = ? AND un.is_deleted = 0
            ");
            $stmt->execute([$unitId]);
            $unitRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($unitRow) {
                $person = [
                    'full_name' => $unitRow['owner_name'] ?: ('Owner of ' . $unitRow['unit_code']),
                    'email' => $unitRow['contact_email'],
                    'phone' => $unitRow['contact_phone'],
                    'resident_type' => 'Owner',
                    'verification_status' => 'Approved',
                    'unit_code' => $unitRow['unit_code'],
                    'floor_number' => $unitRow['floor_number'],
                    'unit_type' => $unitRow['unit_type'],
                    'sqft_area' => $unitRow['sqft_area'],
                    'occupancy_status' => $unitRow['occupancy_status'],
                    'tower_name' => $unitRow['tower_name'],
                    'society_id' => $unitRow['society_id'],
                    'society_name' => $unitRow['society_name'],
                    'society_code' => $unitRow['society_code']
                ];
            }
        }

        if (!$person) {
            return ['error' => 'Person not found'];
        }

        $socId = (int)($person['society_id'] ?? 0);
        $unitCode = $person['unit_code'] ?? '';

        // 2. Vehicles
        $vehicles = [];
        if ($unitId > 0 || $residentId > 0) {
            $vStmt = $this->db->prepare("
                SELECT * FROM vehicles 
                WHERE is_deleted = 0 AND (unit_id = ? OR (resident_id = ? AND resident_id > 0))
                ORDER BY id DESC
            ");
            $vStmt->execute([$unitId ?: 0, $residentId ?: 0]);
            $vehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3. Family Members
        $family = [];
        if ($residentId > 0) {
            $fStmt = $this->db->prepare("
                SELECT * FROM family_members 
                WHERE resident_id = ? AND is_deleted = 0 
                ORDER BY id ASC
            ");
            $fStmt->execute([$residentId]);
            $family = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 4. KYC Documents
        $documents = [];
        if ($residentId > 0) {
            $dStmt = $this->db->prepare("
                SELECT id, document_type, document_number, file_url, verification_status, uploaded_at 
                FROM resident_documents 
                WHERE resident_id = ? AND is_deleted = 0 
                ORDER BY id DESC
            ");
            $dStmt->execute([$residentId]);
            $documents = $dStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 5. Billing & Invoices History (Financial Ledger)
        $invoices = [];
        if ($unitId > 0 || !empty($unitCode)) {
            $iStmt = $this->db->prepare("
                SELECT id, invoice_number, month_period, amount, due_date, paid_date, status, payment_method, receipt_url, created_at
                FROM invoices
                WHERE is_deleted = 0 AND (unit_id = ? OR (flat_number = ? AND society_id = ?))
                ORDER BY id DESC
                LIMIT 15
            ");
            $iStmt->execute([$unitId ?: 0, $unitCode, $socId]);
            $invoices = $iStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 6. Visitor Gate History
        $visitors = [];
        if ($unitId > 0 || !empty($unitCode)) {
            $visStmt = $this->db->prepare("
                SELECT id, visitor_code, name, phone, visitor_type, purpose, check_in_time, check_out_time, status, approval_status, gate_number, vehicle_number, pass_code, photo_url, created_at
                FROM visitors
                WHERE is_deleted = 0 AND (unit_id = ? OR (flat_visiting LIKE ? AND society_id = ?))
                ORDER BY id DESC
                LIMIT 15
            ");
            $visStmt->execute([$unitId ?: 0, '%' . $unitCode . '%', $socId]);
            $visitors = $visStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 7. Complaints & Helpdesk Tickets
        $complaints = [];
        if ($unitId > 0 || !empty($unitCode)) {
            $cStmt = $this->db->prepare("
                SELECT id, ticket_code, title, category, priority, status, assigned_to, resolution_notes, action_taken, resolved_at, created_at
                FROM complaints
                WHERE is_deleted = 0 AND (unit_id = ? OR (flat_number = ? AND society_id = ?))
                ORDER BY id DESC
                LIMIT 15
            ");
            $cStmt->execute([$unitId ?: 0, $unitCode, $socId]);
            $complaints = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            'type' => 'person',
            'profile' => [
                'name' => $person['full_name'] ?: ($person['owner_name'] ?? 'Resident'),
                'email' => $person['email'] ?? '',
                'phone' => $person['phone'] ?? '',
                'avatar_url' => $person['avatar_url'] ?? null,
                'user_code' => $person['user_code'] ?? null,
                'resident_type' => $person['resident_type'] ?? 'Owner',
                'verification_status' => $person['verification_status'] ?? 'Approved',
                'user_id' => $userId,
                'resident_id' => $residentId
            ],
            'unit' => [
                'unit_id' => $unitId,
                'unit_code' => $unitCode,
                'tower_name' => $person['tower_name'] ?? '',
                'floor_number' => $person['floor_number'] ?? '',
                'unit_type' => $person['unit_type'] ?? '2 BHK',
                'sqft_area' => $person['sqft_area'] ?? '1050',
                'occupancy_status' => $person['occupancy_status'] ?? 'Occupied',
                'maintenance_status' => $person['maintenance_status'] ?? 'Paid'
            ],
            'society' => [
                'society_id' => $socId,
                'society_name' => $person['society_name'] ?? '',
                'society_code' => $person['society_code'] ?? '',
                'city' => $person['city'] ?? '',
                'address' => $person['address'] ?? ''
            ],
            'vehicles' => $vehicles,
            'family_members' => $family,
            'documents' => $documents,
            'invoices' => $invoices,
            'visitors' => $visitors,
            'complaints' => $complaints
        ];
    }

    /**
     * Vehicle Master Dossier
     */
    private function getVehicleDossier(int $id): array {
        $stmt = $this->db->prepare("
            SELECT v.*, un.unit_code, un.floor_number, un.owner_name, un.contact_phone as owner_phone,
                   t.name as tower_name,
                   r.resident_type,
                   usr.full_name as resident_name, usr.phone as resident_phone, usr.email as resident_email, usr.avatar_url,
                   s.name as society_name, s.society_code, s.city
            FROM vehicles v
            JOIN units un ON v.unit_id = un.id
            LEFT JOIN towers t ON un.tower_id = t.id
            LEFT JOIN residents r ON v.resident_id = r.id
            LEFT JOIN users usr ON r.user_id = usr.id
            LEFT JOIN societies s ON v.society_id = s.id
            WHERE v.id = ? AND v.is_deleted = 0
        ");
        $stmt->execute([$id]);
        $veh = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$veh) {
            return ['error' => 'Vehicle not found'];
        }

        // Recent visitor entries associated with this vehicle plate
        $cleanPlate = preg_replace('/[^A-Z0-9]/', '', strtoupper($veh['vehicle_number']));
        $vStmt = $this->db->prepare("
            SELECT id, visitor_code, name, phone, visitor_type, purpose, check_in_time, check_out_time, status, gate_number, created_at
            FROM visitors
            WHERE is_deleted = 0 AND (
                REPLACE(REPLACE(REPLACE(vehicle_number, ' ', ''), '-', ''), '.', '') LIKE ?
                OR unit_id = ?
            )
            ORDER BY id DESC
            LIMIT 10
        ");
        $vStmt->execute(['%' . $cleanPlate . '%', $veh['unit_id']]);
        $movements = $vStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'type' => 'vehicle',
            'vehicle' => $veh,
            'owner' => [
                'name' => $veh['resident_name'] ?: ($veh['owner_name'] ?: 'Resident'),
                'phone' => $veh['resident_phone'] ?: ($veh['owner_phone'] ?: ''),
                'email' => $veh['resident_email'] ?: '',
                'avatar_url' => $veh['avatar_url'] ?: null,
                'resident_type' => $veh['resident_type'] ?: 'Owner'
            ],
            'unit' => [
                'unit_id' => (int)$veh['unit_id'],
                'unit_code' => $veh['unit_code'],
                'tower_name' => $veh['tower_name'],
                'parking_slot' => $veh['parking_slot_number']
            ],
            'society' => [
                'society_id' => (int)$veh['society_id'],
                'society_name' => $veh['society_name'],
                'society_code' => $veh['society_code']
            ],
            'movements' => $movements
        ];
    }

    /**
     * Society Master Dossier
     */
    private function getSocietyDossier(int $id): array {
        $stmt = $this->db->prepare("
            SELECT s.*, 
                   COUNT(DISTINCT t.id) as total_towers,
                   COUNT(DISTINCT u.id) as total_units_count,
                   SUM(CASE WHEN u.occupancy_status LIKE '%Occupied%' OR u.occupancy_status = 'Owner' OR u.occupancy_status = 'Rented' THEN 1 ELSE 0 END) as occupied_count,
                   SUM(CASE WHEN u.occupancy_status = 'Vacant' OR u.occupancy_status IS NULL THEN 1 ELSE 0 END) as vacant_count
            FROM societies s
            LEFT JOIN towers t ON s.id = t.society_id AND t.is_deleted = 0
            LEFT JOIN units u ON s.id = u.society_id AND u.is_deleted = 0
            WHERE s.id = ? AND s.is_deleted = 0
            GROUP BY s.id
        ");
        $stmt->execute([$id]);
        $soc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$soc) {
            return ['error' => 'Society not found'];
        }

        // Contacts / Helplines
        $cStmt = $this->db->prepare("
            SELECT * FROM society_contacts 
            WHERE society_id = ? AND is_deleted = 0 
            ORDER BY is_emergency DESC, category ASC
        ");
        $cStmt->execute([$id]);
        $contacts = $cStmt->fetchAll(PDO::FETCH_ASSOC);

        // Towers
        $tStmt = $this->db->prepare("
            SELECT t.id, t.name, t.total_floors, COUNT(u.id) as units_count 
            FROM towers t 
            LEFT JOIN units u ON t.id = u.tower_id AND u.is_deleted = 0
            WHERE t.society_id = ? AND t.is_deleted = 0 
            GROUP BY t.id
        ");
        $tStmt->execute([$id]);
        $towers = $tStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'type' => 'society',
            'society' => $soc,
            'contacts' => $contacts,
            'towers' => $towers
        ];
    }
}
