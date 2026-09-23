<?php
namespace App\Models;

use PDO;

class Dashboard extends BaseModel {
    public function getOverview(?int $societyId = null): array {
        $societyId = ($societyId !== null) ? $societyId : $this->getSocietyId();

        if ($societyId === 0) {
            $totalUnits = (int)$this->db->query("SELECT COUNT(*) FROM units")->fetchColumn();
            $occupiedUnits = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE occupancy_status != 'Vacant'")->fetchColumn();
            $vacantUnits = $totalUnits - $occupiedUnits;
            $activeVisitors = (int)$this->db->query("SELECT COUNT(*) FROM visitors WHERE status = 'Inside'")->fetchColumn();
            $collected = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status = 'Paid'")->fetchColumn();
            $pending = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE status != 'Paid'")->fetchColumn();
            $rate = ($collected + $pending > 0) ? round(($collected / ($collected + $pending)) * 100, 1) : 100.0;
            $pendingTickets = (int)$this->db->query("SELECT COUNT(*) FROM complaints WHERE status != 'Resolved' AND status != 'Closed'")->fetchColumn();

            $towers = $this->db->query("SELECT * FROM towers ORDER BY society_id ASC, id ASC")->fetchAll();
            $visitorModel = new Visitor($this->db);
            $recentVisitors = array_slice($visitorModel->getAll(0), 0, 6);

            $complaintModel = new Complaint($this->db);
            $recentComplaints = array_slice($complaintModel->getAll(0), 0, 5);

            $noticeModel = new Notice($this->db);
            $recentNotices = array_slice($noticeModel->getAll(0), 0, 4);

            $securityOnDuty = (int)$this->db->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE (r.role_code = 'security_guard' OR r.role_code LIKE '%guard%' OR r.role_code LIKE '%security%' OR r.name LIKE '%Security%' OR r.name LIKE '%Guard%') AND u.is_deleted = 0 AND u.status = 'Active'")->fetchColumn();

            return [
                'name' => 'Global Platform View',
                'societyCode' => 'GLOBAL',
                'tagline' => 'Multi-Tenant Master Overview',
                'address' => 'Consolidated Portfolio',
                'totalUnits' => $totalUnits,
                'occupiedUnits' => $occupiedUnits,
                'vacantUnits' => $vacantUnits,
                'securityStaffOnDuty' => $securityOnDuty,
                'activeVisitors' => $activeVisitors,
                'maintenanceCollected' => $rate,
                'totalRevenueMonthly' => $collected,
                'pendingComplaints' => $pendingTickets,
                'towers' => array_map(function($t) {
                    $occupied = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE tower_id = {$t['id']} AND occupancy_status != 'Vacant'")->fetchColumn();
                    return [
                        'id' => $t['tower_code'],
                        'name' => $t['name'],
                        'totalFloors' => (int)$t['total_floors'],
                        'totalFlats' => (int)$t['total_units'],
                        'occupied' => $occupied
                    ];
                }, $towers),
                'visitors' => $recentVisitors,
                'complaints' => $recentComplaints,
                'notices' => $recentNotices
            ];
        }

        // 1. Society Profile
        $stmtSoc = $this->db->prepare("SELECT * FROM societies WHERE id = ? LIMIT 1");
        $stmtSoc->execute([$societyId]);
        $society = $stmtSoc->fetch();

        // 2. Towers
        $stmtTowers = $this->db->prepare("SELECT * FROM towers WHERE society_id = ? ORDER BY id ASC");
        $stmtTowers->execute([$societyId]);
        $towers = $stmtTowers->fetchAll();

        // 3. Unit Counts
        $totalUnits = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND is_deleted = 0")->fetchColumn();
        $occupiedUnits = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND is_deleted = 0 AND occupancy_status != 'Vacant'")->fetchColumn();
        $vacantUnits = $totalUnits - $occupiedUnits;

        // 4. Visitors Inside
        $activeVisitors = (int)$this->db->query("SELECT COUNT(*) FROM visitors WHERE society_id = {$societyId} AND is_deleted = 0 AND status = 'Inside'")->fetchColumn();

        // 5. Billing Totals in ₹
        $collected = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE society_id = {$societyId} AND is_deleted = 0 AND status = 'Paid'")->fetchColumn();
        $pending = (float)$this->db->query("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE society_id = {$societyId} AND is_deleted = 0 AND status != 'Paid'")->fetchColumn();
        $rate = ($collected + $pending > 0) ? round(($collected / ($collected + $pending)) * 100, 1) : 100.0;

        // 6. Pending Complaints
        $pendingTickets = (int)$this->db->query("SELECT COUNT(*) FROM complaints WHERE society_id = {$societyId} AND is_deleted = 0 AND status != 'Resolved' AND status != 'Closed'")->fetchColumn();

        // 7. Active Security on Duty
        $securityOnDuty = (int)$this->db->query("SELECT COUNT(*) FROM users u JOIN roles r ON u.role_id = r.id WHERE u.society_id = {$societyId} AND (r.role_code = 'security_guard' OR r.role_code LIKE '%guard%' OR r.role_code LIKE '%security%' OR r.name LIKE '%Security%' OR r.name LIKE '%Guard%') AND u.is_deleted = 0 AND u.status = 'Active'")->fetchColumn();

        // 8. Recent lists
        $visitorModel = new Visitor($this->db);
        $recentVisitors = array_slice($visitorModel->getAll($societyId), 0, 6);

        $complaintModel = new Complaint($this->db);
        $recentComplaints = array_slice($complaintModel->getAll($societyId), 0, 5);

        $noticeModel = new Notice($this->db);
        $recentNotices = array_slice($noticeModel->getAll($societyId), 0, 4);

        return [
            'name' => $society['name'] ?? 'Society Management',
            'societyCode' => $society['society_code'] ?? '',
            'tagline' => $society['tagline'] ?? '',
            'address' => $society['address'] ?? '',
            'totalUnits' => $totalUnits,
            'occupiedUnits' => $occupiedUnits,
            'vacantUnits' => $vacantUnits,
            'securityStaffOnDuty' => $securityOnDuty,
            'activeVisitors' => $activeVisitors,
            'maintenanceCollected' => $rate,
            'totalRevenueMonthly' => $collected,
            'pendingComplaints' => $pendingTickets,
            'towers' => array_map(function($t) use ($societyId) {
                $occupied = (int)$this->db->query("SELECT COUNT(*) FROM units WHERE society_id = {$societyId} AND tower_id = {$t['id']} AND is_deleted = 0 AND occupancy_status != 'Vacant'")->fetchColumn();
                return [
                    'id' => $t['tower_code'],
                    'name' => $t['name'],
                    'totalFloors' => (int)$t['total_floors'],
                    'totalFlats' => (int)$t['total_units'],
                    'occupied' => $occupied
                ];
            }, $towers),
            'visitors' => $recentVisitors,
            'complaints' => $recentComplaints,
            'notices' => $recentNotices
        ];
    }
}

