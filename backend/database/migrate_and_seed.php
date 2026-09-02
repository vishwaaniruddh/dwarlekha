<?php
/**
 * Multi-Tenant Database Migration & Seeder Script
 * Database: society_management_program
 * Architecture: SAR Enterprise Multi-Tenant MCS Architecture
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$pass = '';

echo "========================================================\n";
echo "Starting Multi-Tenant Society ERP Database Migration\n";
echo "========================================================\n";

try {
    // 1. Connect to MySQL server
    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✓ Connected to MySQL Server at {$host}:{$port}\n";

    // 2. Read and run schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new Exception("Schema file not found: {$schemaFile}");
    }

    $pdo->exec("DROP DATABASE IF EXISTS `society_management_program`");
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "✓ Created database `society_management_program` & tables from schema.sql\n";

    $pdo->exec("USE `society_management_program`");

    // Clean all existing data
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = [
        'cities', 'states', 'zones', 'countries',
        'user_tokens', 'users', 'role_permissions', 'permissions', 'roles', 
        'audit_logs', 'notices', 'facility_bookings', 'amenities', 'complaints', 
        'invoices', 'visitors', 'unit_occupancies', 'vehicles', 'resident_documents', 
        'family_members', 'residents', 'units', 'unit_types', 'towers', 'societies'
    ];
    foreach ($tables as $tbl) {
        $pdo->exec("TRUNCATE TABLE `{$tbl}`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 3. Seed Multiple Society Tenants (Tenant 1, Tenant 2, Tenant 3)
    $stmtSoc = $pdo->prepare("INSERT INTO `societies` 
        (`id`, `society_code`, `name`, `tagline`, `address_line1`, `address_line2`, `address`, `city`, `state`, `pincode`, `country`, `zone_id`, `zone`, `total_units`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtSoc->execute([
        1, 'EMR-01', 'Emerald Heights Residences', 'Ultra-Luxury Smart Community', 
        'Sector 42, Palm Boulevard', 'Silicon Valley', 'Sector 42, Palm Boulevard, Silicon Valley', 
        'Bengaluru Urban', 'Karnataka', '560001', 'India', 2, 'South', 240
    ]);
    $stmtSoc->execute([
        2, 'SPH-02', 'Sapphire Palms Estate', 'Premium Gated Living', 
        'Plot 88, Golf Course Road', 'Cyber City', 'Plot 88, Golf Course Road, Cyber City', 
        'Gurugram', 'Haryana', '122002', 'India', 1, 'North', 120
    ]);
    $stmtSoc->execute([
        3, 'PBR-01', 'Paranjape Blue Ridge', 'Integrated Tech Township',
        'Blue Ridge Town Pune, Phase 1', 'Hinjewadi Rajiv Gandhi Infotech Park, Hinjawadi', 'Blue Ridge Town Pune, Phase 1, Hinjewadi Rajiv Gandhi Infotech Park, Hinjawadi',
        'Pune', 'Maharashtra', '411057', 'India', 4, 'West', 350
    ]);
    echo "✓ Seeded Multiple Tenant Societies: [EMR-01] Emerald Heights, [SPH-02] Sapphire Palms & [PBR-01] Paranjape Blue Ridge\n";

    // 3.1 Seed Standard & Custom Unit Types Catalog
    $unitTypesData = [
        [null, '1RK Studio Suite', 'teal', '350 - 500 sq.ft.', 450, 'Compact studio apartments, single professionals, or senior suites', 1],
        [null, '1BHK Smart Compact', 'blue', '650 - 750 sq.ft.', 700, 'Single professionals and small families', 1],
        [null, '2BHK Luxury Suite', 'indigo', '1,050 - 1,250 sq.ft.', 1150, 'Standard luxury urban family unit', 1],
        [null, '3BHK Royal Grande', 'purple', '1,650 - 1,950 sq.ft.', 1800, 'Spacious master living for large families', 1],
        [null, '4BHK Sky Penthouse', 'teal', '2,400 - 3,200 sq.ft.', 2800, 'Ultra-luxury duplex with private terrace', 1],
        [null, '4 BHK Duplex Villa', 'orange', '3,500 - 4,500 sq.ft.', 4000, 'Independent villas with private garden & portico', 1],
        [null, 'Commercial Retail Shop', 'orange', '200 - 1,500 sq.ft.', 600, 'Ground-floor commercial retail, doctor clinic, or convenience store', 1],
        [null, 'Service Apartment / Suite', 'teal', '400 - 650 sq.ft.', 500, 'Executive corporate stay or furnished service apartment', 1]
    ];
    $stmtUT = $pdo->prepare("INSERT INTO `unit_types` (`society_id`, `type_name`, `badge_color`, `typical_area`, `standard_sqft`, `use_case`, `is_system_standard`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($unitTypesData as $ut) {
        $stmtUT->execute($ut);
    }
    echo "✓ Seeded Standard Unit Types Catalog\n";

    // 4. Seed Towers for Society 1 (Emerald Heights)
    $towersData = [
        ['T-A', 'Tower A (Sapphire)', 15, 60, 'A'],
        ['T-B', 'Tower B (Emerald)', 15, 60, 'B'],
        ['T-C', 'Tower C (Diamond)', 18, 72, 'C'],
        ['T-V', 'Imperial Villas', 3, 48, 'V']
    ];

    $towerIdMap = [];
    $stmtTower = $pdo->prepare("INSERT INTO `towers` (`society_id`, `tower_code`, `name`, `total_floors`, `total_units`) VALUES (?, ?, ?, ?, ?)");
    foreach ($towersData as $t) {
        $stmtTower->execute([1, $t[0], $t[1], $t[2], $t[3]]);
        $towerIdMap[$t[4]] = (int)$pdo->lastInsertId();
    }

    // Seed Towers for Society 2 (Sapphire Palms)
    $stmtTower->execute([2, 'T-ALPHA', 'Alpha Wing', 10, 60]);
    $towerIdMap['Alpha'] = (int)$pdo->lastInsertId();
    $stmtTower->execute([2, 'T-BETA', 'Beta Wing', 10, 60]);
    $towerIdMap['Beta'] = (int)$pdo->lastInsertId();

    echo "✓ Seeded Towers for Tenant Societies\n";

    // 4.1 Seed RBAC Roles
    $roles = [
        ['sar_platform_admin', 'SAR Master Platform Admin (Parent)', 'Parent Software Company master root access across all society tenants, billing, and system operations.', 'purple', 1],
        ['sar_support', 'SAR Platform Support Lead (Parent)', 'Parent Software Company technical and support lead across client societies.', 'blue', 1],
        ['super_admin', 'Estate Director (Society Admin)', 'Full unrestricted master access across all modules within the society tenant.', 'purple', 1],
        ['facility_manager', 'Facility & Operations Manager', 'Operations lead for residents, facility tickets, complaints SLA, amenities, and general broadcasts.', 'blue', 1],
        ['security_guard', 'Security & Gate Officer', 'Access strictly to gate perimeter, visitor entry/checkout, vehicle pass verification, and emergency notices.', 'green', 1],
        ['finance_manager', 'Finance & Accounts Officer', 'Full management over maintenance billing, payment collection, receipts, and financial ledger.', 'orange', 1],
        ['resident', 'Resident (Owner / Tenant)', 'Self-service portal to view unit details, pay maintenance bills, log maintenance tickets, book amenities, and issue gate passes.', 'teal', 1]
    ];

    $stmtRole = $pdo->prepare("INSERT INTO `roles` (`role_code`, `name`, `description`, `badge_color`, `is_system`) VALUES (?, ?, ?, ?, ?)");
    $roleIdMap = [];
    foreach ($roles as $r) {
        $stmtRole->execute([$r[0], $r[1], $r[2], $r[3], $r[4]]);
        $roleIdMap[$r[0]] = (int)$pdo->lastInsertId();
    }
    echo "✓ Seeded " . count($roles) . " Master RBAC Roles\n";

    // 4.2 Seed Granular Permissions Catalog
    $permissions = [
        ['dashboard.view', 'Dashboard', 'View', 'View overview dashboard telemetry and KPIs'],
        ['dashboard.metrics', 'Dashboard', 'Metrics', 'View financial and operational metrics summary'],
        ['residents.view', 'Residents & Flats', 'View', 'View flats directory and resident profiles'],
        ['residents.create', 'Residents & Flats', 'Create', 'Onboard new residents and assign units'],
        ['residents.manage', 'Residents & Flats', 'Manage', 'Update resident details, vehicles and lease data'],
        ['visitors.view', 'Gate & Visitors', 'View', 'View real-time visitor logs and gate activity'],
        ['visitors.create', 'Gate & Visitors', 'Create', 'Generate gate entry passes and pre-approve guests'],
        ['visitors.checkout', 'Gate & Visitors', 'Checkout', 'Mark visitors as checked out at security gates'],
        ['visitors.manage', 'Gate & Visitors', 'Manage', 'Modify visitor pass details and security flags'],
        ['billing.view', 'Billing & Ledger', 'View', 'View maintenance invoices, ledgers and payment receipts'],
        ['billing.create', 'Billing & Ledger', 'Create', 'Generate maintenance bills and add manual invoices'],
        ['billing.pay', 'Billing & Ledger', 'Pay', 'Record payments and settle outstanding invoices'],
        ['billing.manage', 'Billing & Ledger', 'Manage', 'Override bill parameters and generate financial reports'],
        ['helpdesk.view', 'Helpdesk Tickets', 'View', 'View maintenance tickets and service requests'],
        ['helpdesk.create', 'Helpdesk Tickets', 'Create', 'Submit new maintenance complaints and issues'],
        ['helpdesk.update_status', 'Helpdesk Tickets', 'Update Status', 'Assign duty engineers and update complaint status'],
        ['helpdesk.manage', 'Helpdesk Tickets', 'Manage', 'Full lifecycle control and priority escalation over tickets'],
        ['amenities.view', 'Amenities', 'View', 'View clubhouse facilities, schedules and availability'],
        ['amenities.book', 'Amenities', 'Book', 'Reserve slots for sports courts, pool and banquet hall'],
        ['amenities.manage', 'Amenities', 'Manage', 'Configure amenity timings, pricing and maintenance blocks'],
        ['notices.view', 'Notices & Broadcast', 'View', 'Read community announcements and emergency broadcasts'],
        ['notices.create', 'Notices & Broadcast', 'Create', 'Draft and publish society broadcasts'],
        ['notices.manage', 'Notices & Broadcast', 'Manage', 'Pin, edit and remove society announcements'],
        ['users.view', 'User Management', 'View', 'View user accounts, roles and status directory'],
        ['users.create', 'User Management', 'Create', 'Provision new staff, manager and resident user logins'],
        ['users.edit', 'User Management', 'Edit', 'Modify user profile, permissions, and role assignment'],
        ['users.delete', 'User Management', 'Delete', 'Suspend or delete user login credentials'],
        ['roles.view', 'RBAC Roles', 'View', 'Inspect role definitions and permission matrices'],
        ['roles.manage', 'RBAC Roles', 'Manage', 'Create custom roles and customize permission assignments'],
        ['audit.view', 'Audit Trail', 'View', 'Inspect administrative security audit logs and session history']
    ];

    $stmtPerm = $pdo->prepare("INSERT INTO `permissions` (`permission_code`, `module`, `action`, `description`) VALUES (?, ?, ?, ?)");
    $permIdMap = [];
    foreach ($permissions as $p) {
        $stmtPerm->execute([$p[0], $p[1], $p[2], $p[3]]);
        $permIdMap[$p[0]] = (int)$pdo->lastInsertId();
    }
    echo "✓ Seeded " . count($permissions) . " Granular Permissions\n";

    // 4.3 Link Role-Permissions
    $rolePermissionsConfig = [
        'sar_platform_admin' => array_keys($permIdMap),
        'sar_support' => array_keys($permIdMap),
        'super_admin' => array_keys($permIdMap),
        'facility_manager' => [
            'dashboard.view', 'dashboard.metrics',
            'residents.view', 'residents.create', 'residents.manage',
            'visitors.view', 'visitors.create', 'visitors.checkout',
            'billing.view', 'billing.create',
            'helpdesk.view', 'helpdesk.create', 'helpdesk.update_status', 'helpdesk.manage',
            'amenities.view', 'amenities.book', 'amenities.manage',
            'notices.view', 'notices.create', 'notices.manage',
            'users.view', 'audit.view'
        ],
        'security_guard' => [
            'dashboard.view',
            'visitors.view', 'visitors.create', 'visitors.checkout', 'visitors.manage',
            'helpdesk.view', 'helpdesk.create',
            'notices.view'
        ],
        'finance_manager' => [
            'dashboard.view', 'dashboard.metrics',
            'residents.view',
            'billing.view', 'billing.create', 'billing.pay', 'billing.manage',
            'notices.view',
            'audit.view'
        ],
        'resident' => [
            'dashboard.view',
            'visitors.view', 'visitors.create',
            'billing.view', 'billing.pay',
            'helpdesk.view', 'helpdesk.create',
            'amenities.view', 'amenities.book',
            'notices.view'
        ]
    ];

    $stmtRolePerm = $pdo->prepare("INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (?, ?)");
    $totalRolePerms = 0;
    foreach ($rolePermissionsConfig as $roleCode => $permCodes) {
        $roleId = $roleIdMap[$roleCode];
        foreach ($permCodes as $code) {
            if (isset($permIdMap[$code])) {
                $stmtRolePerm->execute([$roleId, $permIdMap[$code]]);
                $totalRolePerms++;
            }
        }
    }
    echo "✓ Linked {$totalRolePerms} Role-Permission assignments\n";

    // 4.4 Seed Core Staff & Admin Accounts
    $coreUsers = [
        ['SAR-0001', null, 1, 'Aniruddh Vishwakarma', 'vishwaaniruddh@gmail.com', 'rootroot', 'sar_platform_admin', '7021889883', null, 'Active', 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'],
        ['SAR-0002', null, 1, 'SAR Platform Support Lead', 'sar.support@sartech.io', 'support123', 'sar_support', '+91 99000-00002', null, 'Active', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150&auto=format&fit=crop&q=80'],
        ['USR-1001', 1, 0, 'Seraphina Thorne', 'seraphina@emerald.net', 'admin123', 'super_admin', '+91 98200-00001', null, 'Active', 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'],
        ['USR-1002', 1, 0, 'Marcus Ops', 'marcus.ops@emerald.net', 'manager123', 'facility_manager', '+91 98200-00002', null, 'Active', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150&auto=format&fit=crop&q=80'],
        ['USR-1003', 1, 0, 'Rajesh Gatekeeper', 'gate1.security@emerald.net', 'guard123', 'security_guard', '+91 98200-00003', null, 'Active', 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=150&auto=format&fit=crop&q=80'],
        ['USR-1004', 1, 0, 'Elena Rostova', 'accounts@emerald.net', 'finance123', 'finance_manager', '+91 98200-00004', null, 'Active', 'https://images.unsplash.com/photo-1580489944761-15a19d654956?w=150&auto=format&fit=crop&q=80'],
        ['USR-2001', 2, 0, 'Ankit Verma', 'ankit.verma@sapphire.net', 'admin123', 'super_admin', '+91 98770-00001', 'Alpha-101', 'Active', 'https://images.unsplash.com/photo-1522075469751-3a6694fb2f61?w=150&auto=format&fit=crop&q=80']
    ];

    $stmtUser = $pdo->prepare("INSERT INTO `users` 
        (`user_code`, `society_id`, `is_parent_user`, `full_name`, `email`, `password_hash`, `role_id`, `phone`, `unit_code`, `status`, `avatar_url`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($coreUsers as $u) {
        $passwordHash = password_hash($u[5], PASSWORD_BCRYPT);
        $roleId = $roleIdMap[$u[6]];
        $stmtUser->execute([
            $u[0], $u[1], $u[2], $u[3], $u[4], $passwordHash, $roleId, $u[7], $u[8], $u[9], $u[10]
        ]);
    }
    echo "✓ Seeded Core Administrative & Staff Accounts\n";

    // 5. Seed Names
    $ownerNames = [
        "Marcus Vance", "Sophia Chen-Miller", "Dr. Alexander Wright", "Hiroshi Tanaka",
        "Rajesh Singhania", "Elena Rostova", "Vikramaditya Roy", "Meera Deshmukh",
        "David Goldstein", "Ananya Verma", "Carlos Rodriguez", "Sunil Narang",
        "Priya Kapoor", "Arjun Singhal", "Sarah Jenkins", "Aditya Sen",
        "Pooja Batra", "Nikhil Chawla", "Dr. Alistair Finch", "Neha Bansal",
        "Karan Malhotra", "Deepika Roy", "Siddharth Rao", "Aarti Menon"
    ];

    $tenantNames = [
        "Liam O'Connor", "Amara Patel", "Chloe Dupont", "Rahul Verma",
        "Emily Zhao", "Sanjay Gupta", "Tarun Rao", "Zoya Khan",
        "Naveen Joshi", "Kavita Nair", "Lucas Bennett", "Rohan Sharma",
        "Tanya Oberoi", "Ishaan Mehra", "Farhan Akhtar", "Simran Kaur"
    ];

    $seedResidentsMap = [
        "A-1002" => ["owner" => "Marcus Vance", "tenant" => null, "status" => "Occupied (Owner)", "phone" => "+91 98201-23890", "dues" => "Paid", "veh" => ["Tesla Model S", "EMR-8821", "Car", "P-1002", "RFID-EMR-1002"]],
        "B-0404" => ["owner" => "Sophia Chen-Miller", "tenant" => null, "status" => "Occupied (Owner)", "phone" => "+91 98202-87210", "dues" => "Paid", "veh" => ["Porsche Macan", "EMR-3301", "Car", "P-0404", "RFID-EMR-0404"]],
        "C-1401" => ["owner" => "Vikramaditya Roy", "tenant" => "Liam O'Connor", "status" => "Occupied (Tenant)", "phone" => "+91 98203-90144", "dues" => "Pending", "veh" => ["BMW i4", "EMR-7742", "EV 4W", "P-1401", "RFID-EMR-1401"]],
        "V-008"  => ["owner" => "Dr. Alexander Wright", "tenant" => null, "status" => "Occupied (Owner)", "phone" => "+91 98204-43177", "dues" => "Paid", "veh" => ["Range Rover", "EMR-0080", "Car", "V-008-A", "RFID-EMR-0080"]],
        "A-0201" => ["owner" => "Rajesh Singhania", "tenant" => "Amara Patel", "status" => "Occupied (Tenant)", "phone" => "+91 98205-66281", "dues" => "Overdue", "veh" => ["Audi TT", "EMR-1290", "Car", "P-0201", "RFID-EMR-1290"]],
        "C-0803" => ["owner" => "Hiroshi Tanaka", "tenant" => null, "status" => "Occupied (Owner)", "phone" => "+91 98206-31092", "dues" => "Paid", "veh" => ["Lexus RX", "EMR-6604", "Car", "P-0803", "RFID-EMR-6604"]]
    ];

    $stmtUnit = $pdo->prepare("INSERT INTO `units` 
        (`society_id`, `tower_id`, `unit_code`, `floor_number`, `unit_type`, `occupancy_status`, `maintenance_status`, `owner_name`, `tenant_name`, `contact_phone`, `contact_email`) 
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $stmtResident = $pdo->prepare("INSERT INTO `residents` 
        (`society_id`, `user_id`, `unit_id`, `resident_type`, `is_primary_contact`, `move_in_date`, `verification_status`, `verified_by_user_id`) 
        VALUES (1, ?, ?, ?, 1, '2023-01-15', 'Approved', 3)");

    $stmtOccupancy = $pdo->prepare("INSERT INTO `unit_occupancies` 
        (`unit_id`, `resident_id`, `occupancy_type`, `is_primary`) 
        VALUES (?, ?, ?, 1)");

    $stmtFamily = $pdo->prepare("INSERT INTO `family_members` 
        (`resident_id`, `full_name`, `relation`, `phone`, `age`, `gender`, `photo_url`) 
        VALUES (?, ?, ?, ?, ?, ?, ?)");

    $stmtDoc = $pdo->prepare("INSERT INTO `resident_documents` 
        (`resident_id`, `document_type`, `document_number`, `file_url`) 
        VALUES (?, ?, ?, ?)");

    $stmtVehicle = $pdo->prepare("INSERT INTO `vehicles` 
        (`society_id`, `unit_id`, `resident_id`, `vehicle_number`, `vehicle_type`, `make_model`, `parking_slot_number`, `rfid_sticker_tag`, `pass_status`) 
        VALUES (1, ?, ?, ?, ?, ?, ?, ?, 'Valid')");

    $flatIndex = 0;
    $totalUnitsInserted = 0;
    $residentRoleId = $roleIdMap['resident'];
    $defaultResidentPasswordHash = password_hash('resident123', PASSWORD_BCRYPT);

    foreach ($towersData as $t) {
        $prefix = $t[4];
        $towerId = $towerIdMap[$prefix];
        $floors = $t[2];
        $flatsPerFloor = ($prefix === 'V') ? 16 : 4;

        for ($f = 1; $f <= $floors; $f++) {
            for ($unit = 1; $unit <= $flatsPerFloor; $unit++) {
                $floorStr = $f < 10 ? "0{$f}" : "{$f}";
                $unitStr = $unit < 10 ? "0{$unit}" : "{$unit}";
                $villaNum = ($f - 1) * $flatsPerFloor + $unit;

                $flatNumber = ($prefix === 'V') 
                    ? "V-" . str_pad($villaNum, 3, '0', STR_PAD_LEFT) 
                    : "{$prefix}-{$floorStr}{$unitStr}";

                $unitType = ($prefix === 'V') 
                    ? '4 BHK Duplex Villa' 
                    : ($f > 12 ? '4 BHK Sky Penthouse' : ($unit % 2 === 0 ? '3 BHK Royal Luxe' : '2 BHK Smart Suite'));

                $status = "Occupied (Owner)";
                $dues = "Paid";
                $owner = null;
                $tenant = null;
                $phone = null;
                $email = null;
                $vehInfo = null;

                if (isset($seedResidentsMap[$flatNumber])) {
                    $seed = $seedResidentsMap[$flatNumber];
                    $status = $seed['status'];
                    $owner = $seed['owner'];
                    $tenant = $seed['tenant'];
                    $phone = $seed['phone'];
                    $dues = $seed['dues'];
                    $email = strtolower(preg_replace('/[^a-z]/', '.', ($tenant ?: $owner))) . '@emerald.net';
                    $vehInfo = $seed['veh'];
                } else {
                    $flatIndex++;
                    $mod = $flatIndex % 11;
                    $owner = $ownerNames[$flatIndex % count($ownerNames)];
                    $dues = ($flatIndex % 8 === 0) ? "Pending" : (($flatIndex % 19 === 0) ? "Overdue" : "Paid");

                    if ($mod === 3 || $mod === 9) {
                        $status = "Vacant";
                        $owner = ($flatIndex % 2 === 0) ? $ownerNames[($flatIndex + 2) % count($ownerNames)] : "Unsold / Builder";
                        $tenant = null;
                        $dues = "N/A";
                    } elseif ($mod === 2 || $mod === 5 || $mod === 8) {
                        $status = "Occupied (Tenant)";
                        $owner = $ownerNames[($flatIndex + 5) % count($ownerNames)];
                        $tenant = $tenantNames[$flatIndex % count($tenantNames)];
                        $phone = "+91 98" . (10000000 + ($flatIndex * 71329) % 90000000);
                        $email = strtolower(preg_replace('/[^a-z]/', '.', $tenant)) . '@emerald.net';
                        $vehInfo = [($flatIndex % 2 === 0 ? "BMW 3 Series" : "Honda City"), "EMR-" . (1000 + $flatIndex), "Car", "P-{$flatNumber}", "RFID-" . (1000 + $flatIndex)];
                    } else {
                        $phone = "+91 98" . (10000000 + ($flatIndex * 71329) % 90000000);
                        $email = strtolower(preg_replace('/[^a-z]/', '.', $owner)) . '@emerald.net';
                        if ($flatIndex % 3 === 0) {
                            $vehInfo = ["Mercedes C-Class", "EMR-" . (2000 + $flatIndex), "Car", "P-{$flatNumber}", "RFID-" . (2000 + $flatIndex)];
                        } elseif ($flatIndex % 2 === 0) {
                            $vehInfo = ["Ather 450X", "EMR-" . (3000 + $flatIndex), "EV 2W", "P-{$flatNumber}-B", "RFID-" . (3000 + $flatIndex)];
                        }
                    }
                }

                // Insert Unit
                $stmtUnit->execute([
                    $towerId,
                    $flatNumber,
                    $f,
                    $unitType,
                    $status,
                    $dues,
                    $owner,
                    $tenant,
                    $phone,
                    $email
                ]);
                $unitDbId = (int)$pdo->lastInsertId();
                $totalUnitsInserted++;

                // Insert Resident & Link if occupied
                if ($status !== 'Vacant') {
                    $activeName = ($status === 'Occupied (Tenant)') ? $tenant : $owner;
                    $activeRole = ($status === 'Occupied (Tenant)') ? 'Tenant' : 'Owner';
                    $userCode = 'USR-' . (5000 + $totalUnitsInserted);

                    // Create Resident User Account
                    $stmtUser->execute([
                        $userCode, 1, 0, $activeName, $email, $defaultResidentPasswordHash,
                        $residentRoleId, $phone, $flatNumber, 'Active',
                        'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80'
                    ]);
                    $residentUserDbId = (int)$pdo->lastInsertId();

                    // Create Resident Record
                    $stmtResident->execute([
                        $residentUserDbId,
                        $unitDbId,
                        $activeRole
                    ]);
                    $residentDbId = (int)$pdo->lastInsertId();

                    $pdo->exec("UPDATE `users` SET `resident_id` = {$residentDbId} WHERE `id` = {$residentUserDbId}");

                    $stmtOccupancy->execute([$unitDbId, $residentDbId, $activeRole]);

                    // Seed Family Members
                    $stmtFamily->execute([
                        $residentDbId,
                        $activeName . " (Spouse)",
                        'Spouse',
                        '+91 98200-' . rand(10000, 99999),
                        rand(28, 48),
                        ($flatIndex % 2 === 0 ? 'Female' : 'Male'),
                        null
                    ]);
                    if ($totalUnitsInserted % 2 === 0) {
                        $stmtFamily->execute([
                            $residentDbId,
                            "Junior " . explode(' ', $activeName)[0],
                            'Child',
                            null,
                            rand(6, 17),
                            'Male',
                            null
                        ]);
                    }

                    // Seed Resident Documents
                    $stmtDoc->execute([
                        $residentDbId,
                        'Aadhaar',
                        'XXXX-XXXX-' . rand(1000, 9999),
                        'https://emerald.residences.io/docs/aadhaar_' . $residentDbId . '.pdf'
                    ]);
                    if ($activeRole === 'Tenant') {
                        $stmtDoc->execute([
                            $residentDbId,
                            'Rental Agreement',
                            'LEASE-2026-' . str_pad($totalUnitsInserted, 4, '0', STR_PAD_LEFT),
                            'https://emerald.residences.io/docs/lease_' . $residentDbId . '.pdf'
                        ]);
                    } else {
                        $stmtDoc->execute([
                            $residentDbId,
                            'Sale Deed',
                            'DEED-REG-' . str_pad($totalUnitsInserted, 4, '0', STR_PAD_LEFT),
                            'https://emerald.residences.io/docs/deed_' . $residentDbId . '.pdf'
                        ]);
                    }

                    // Seed Vehicle
                    if ($vehInfo) {
                        $stmtVehicle->execute([
                            $unitDbId,
                            $residentDbId,
                            $vehInfo[1],
                            $vehInfo[2] ?? 'Car',
                            $vehInfo[0],
                            $vehInfo[3] ?? "P-{$flatNumber}",
                            $vehInfo[4] ?? ("RFID-" . rand(10000, 99999))
                        ]);
                    }
                }
            }
        }
    }
    echo "✓ Seeded {$totalUnitsInserted} Flats, Residents, Family Members, Documents & Vehicles for Society 1 [Emerald Heights]\n";

    // 11. Seed Society 2 (Sapphire Palms) Sample Unit & Resident
    $stmtUnit2 = $pdo->prepare("INSERT INTO `units` 
        (`society_id`, `tower_id`, `unit_code`, `floor_number`, `unit_type`, `occupancy_status`, `maintenance_status`, `owner_name`, `contact_phone`, `contact_email`) 
        VALUES (2, ?, 'Alpha-101', 1, '3BHK Royal Grande', 'Occupied (Owner)', 'Paid', 'Ankit Verma', '+91 98770-00001', 'ankit.verma@sapphire.net')");
    $stmtUnit2->execute([$towerIdMap['Alpha']]);
    $u2Id = (int)$pdo->lastInsertId();

    $stmtResident2 = $pdo->prepare("INSERT INTO `residents` 
        (`society_id`, `user_id`, `unit_id`, `resident_type`, `is_primary_contact`, `move_in_date`, `verification_status`) 
        VALUES (2, 7, ?, 'Owner', 1, '2023-06-01', 'Approved')");
    $stmtResident2->execute([$u2Id]);
    $r2Id = (int)$pdo->lastInsertId();

    $pdo->exec("UPDATE `users` SET `resident_id` = {$r2Id} WHERE `id` = 7");
    $stmtOccupancy->execute([$u2Id, $r2Id, 'Owner']);

    $stmtVehicle2 = $pdo->prepare("INSERT INTO `vehicles` 
        (`society_id`, `unit_id`, `resident_id`, `vehicle_number`, `vehicle_type`, `make_model`, `parking_slot_number`, `rfid_sticker_tag`, `pass_status`) 
        VALUES (2, ?, ?, 'HR-26-AB-1234', 'Car', 'Audi A4', 'P-101', 'RFID-SPH-101', 'Valid')");
    $stmtVehicle2->execute([$u2Id, $r2Id]);
    echo "✓ Seeded Society 2 [Sapphire Palms] Unit & Resident\n";

    // 12. Seed Visitors
    $visitors = [
        ['VIS-001', 1, 'Liam Sterling', 'Guest', '+91 98110-44211', 'A-1002', 'Marcus Vance', '10:45 AM Today', null, 'Inside', 'Family Brunch', 'Gate 1 (North)', 'KA-01-MJ-9921', 'PASS-8821'],
        ['VIS-002', 1, 'Amazon Delivery (Ravi)', 'Delivery', '+91 98220-77332', 'B-0404', 'Sophia Chen-Miller', '11:15 AM Today', null, 'Inside', 'Package Drop', 'Gate 2 (East)', 'KA-04-E-1002', 'PASS-1044'],
        ['VIS-003', 1, 'Uber Driver (Santosh)', 'Cab/Ride', '+91 98330-88443', 'C-1401', 'Vikramaditya Roy', '11:30 AM Today', null, 'Inside', 'Airport Drop', 'Gate 1 (North)', 'KA-05-AB-7721', 'PASS-9901'],
        ['VIS-004', 1, 'Dr. Alistair Finch', 'Guest', '+91 98440-99554', 'V-008', 'Dr. Alexander Wright', '09:00 AM Today', '11:00 AM Today', 'Checked Out', 'Medical Consult', 'Gate 1 (North)', 'Walk-in', 'PASS-4411'],
        ['VIS-005', 1, 'Urban Company AC Tech', 'Service/Contractor', '+91 98550-11665', 'A-0201', 'Rajesh Singhania', '08:30 AM Today', '10:15 AM Today', 'Checked Out', 'Quarterly AC Service', 'Gate 3 (Service)', 'KA-02-ZZ-4402', 'PASS-7730'],
        ['VIS-006', 1, 'Swiggy Genie (Aman)', 'Delivery', '+91 98660-22776', 'C-0803', 'Hiroshi Tanaka', '11:40 AM Today', null, 'Inside', 'Grocery Delivery', 'Gate 2 (East)', 'Walk-in', 'PASS-5520'],
        ['VIS-201', 2, 'Pooja Sharma', 'Guest', '+91 98770-33887', 'Alpha-101', 'Ankit Verma', '10:00 AM Today', null, 'Inside', 'Personal Visit', 'Main Gate', 'HR-26-AB-1234', 'PASS-2001']
    ];

    $stmtVisitor = $pdo->prepare("INSERT INTO `visitors` 
        (`visitor_code`, `society_id`, `name`, `visitor_type`, `phone`, `flat_visiting`, `host_name`, `check_in_time`, `check_out_time`, `status`, `purpose`, `gate_number`, `vehicle_number`, `pass_code`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($visitors as $v) {
        $stmtVisitor->execute([$v[0], $v[1], $v[2], $v[3], $v[4], $v[5], $v[6], $v[7], $v[8], $v[9], $v[10], $v[11], $v[12], $v[13]]);
    }
    echo "✓ Seeded Multi-Tenant Gate Visitors\n";

    // 13. Seed Invoices
    $invoices = [
        ['INV-2026-8801', 1, 'A-1002', 'Marcus Vance', 'August 2026', 4200.00, 2940.00, 450.00, 500.00, 310.00, '15 Aug 2026', '10 Aug 2026', 'Paid', 'UPI (HDFC AutoPay)'],
        ['INV-2026-8802', 1, 'B-0404', 'Sophia Chen-Miller', 'August 2026', 3800.00, 2660.00, 400.00, 450.00, 290.00, '15 Aug 2026', '12 Aug 2026', 'Paid', 'Credit Card (Apple Pay)'],
        ['INV-2026-8803', 1, 'C-1401', 'Vikramaditya Roy', 'August 2026', 4500.00, 3150.00, 480.00, 520.00, 350.00, '15 Aug 2026', null, 'Pending', null],
        ['INV-2026-8804', 1, 'V-008', 'Dr. Alexander Wright', 'August 2026', 6500.00, 4550.00, 650.00, 750.00, 550.00, '15 Aug 2026', '08 Aug 2026', 'Paid', 'Net Banking (ICICI)'],
        ['INV-2026-8805', 1, 'A-0201', 'Rajesh Singhania', 'July 2026', 4200.00, 2940.00, 450.00, 500.00, 310.00, '15 Jul 2026', null, 'Overdue', null],
        ['INV-2026-8806', 1, 'C-0803', 'Hiroshi Tanaka', 'August 2026', 3800.00, 2660.00, 400.00, 450.00, 290.00, '15 Aug 2026', '14 Aug 2026', 'Paid', 'UPI (Google Pay)'],
        ['INV-2026-9901', 2, 'Alpha-101', 'Ankit Verma', 'August 2026', 5000.00, 3500.00, 500.00, 600.00, 400.00, '15 Aug 2026', '11 Aug 2026', 'Paid', 'Net Banking']
    ];

    $stmtInvoice = $pdo->prepare("INSERT INTO `invoices` 
        (`invoice_number`, `society_id`, `flat_number`, `resident_name`, `month_period`, `amount`, `base_maintenance`, `water_charges`, `sinking_fund`, `clubhouse_fee`, `due_date`, `paid_date`, `status`, `payment_method`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($invoices as $inv) {
        $stmtInvoice->execute([$inv[0], $inv[1], $inv[2], $inv[3], $inv[4], $inv[5], $inv[6], $inv[7], $inv[8], $inv[9], $inv[10], $inv[11], $inv[12], $inv[13]]);
    }
    echo "✓ Seeded Invoices & Financial Ledger\n";

    // 14. Seed Helpdesk Complaints
    $complaints = [
        ['TKT-501', 1, 'Main Line Water Pressure Drop', 'Plumbing', 'A-1002', 'Marcus Vance', 'Critical', 'Assigned', 'Facility Chief Engineer', 'Water pressure below 1.5 bar on 10th floor line.', '2 hrs ago'],
        ['TKT-502', 1, 'EV Fast-Charger Bay #3 Off-Grid', 'Electrical', 'B-0404', 'Sophia Chen-Miller', 'High', 'In Progress', 'Tesla Fleet Tech Support', 'Station #3 giving intermittent ground fault error.', '5 hrs ago'],
        ['TKT-503', 1, 'Central HVAC Air Duct Whistling', 'HVAC', 'C-1401', 'Vikramaditya Roy', 'Medium', 'Open', 'Duty Engineer', 'Audible hum from ceiling damper in master hallway.', 'Yesterday'],
        ['TKT-504', 1, 'Elevator #2 Glass Scuff Polishing', 'Elevator', 'V-008', 'Dr. Alexander Wright', 'Low', 'Resolved', 'Schindler Lift Specialist', 'Routine cosmetic polish complete.', '2 days ago'],
        ['TKT-601', 2, 'Main Gate Barrier Sensor Lag', 'Security', 'Alpha-101', 'Ankit Verma', 'Medium', 'Open', 'Security Tech', 'Boom barrier delay.', '1 hr ago']
    ];

    $stmtTicket = $pdo->prepare("INSERT INTO `complaints` 
        (`ticket_code`, `society_id`, `title`, `category`, `flat_number`, `reported_by`, `priority`, `status`, `assigned_to`, `description`, `created_at_label`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($complaints as $c) {
        $stmtTicket->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $c[8], $c[9], $c[10]]);
    }
    echo "✓ Seeded Helpdesk Complaints\n";

    // 15. Seed Amenities & Bookings
    $amenities = [
        ['AM-1', 1, 'Heated Olympic Lap Pool', 'Sports & Aquatic', 0, 30, 8, '06:00 AM - 10:00 PM', 'Available', 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?w=600&auto=format&fit=crop&q=80'],
        ['AM-2', 1, 'Championship Tennis Court', 'Racquet Sports', 250, 4, 4, '06:00 AM - 09:00 PM', 'Full', 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?w=600&auto=format&fit=crop&q=80'],
        ['AM-3', 1, 'Skyline Banquet & Ballroom', 'Social & Events', 2500, 150, 0, '10:00 AM - 11:30 PM', 'Available', 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?w=600&auto=format&fit=crop&q=80'],
        ['AM-4', 1, '4K Dolby Atmos Cinema Suite', 'Entertainment', 500, 18, 0, '02:00 PM - 12:00 AM', 'Available', 'https://images.unsplash.com/photo-1489599849927-2ee91cede3ba?w=600&auto=format&fit=crop&q=80'],
        ['AM-201', 2, 'Infinity Pool & Lounge', 'Aquatics', 0, 20, 5, '06:00 AM - 10:00 PM', 'Available', 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?w=600&auto=format&fit=crop&q=80']
    ];

    $stmtAmenity = $pdo->prepare("INSERT INTO `amenities` 
        (`amenity_code`, `society_id`, `name`, `category`, `hourly_rate`, `capacity`, `current_occupancy`, `operating_hours`, `status`, `image_url`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $amenityIdMap = [];
    foreach ($amenities as $am) {
        $stmtAmenity->execute([$am[0], $am[1], $am[2], $am[3], $am[4], $am[5], $am[6], $am[7], $am[8], $am[9]]);
        $amenityIdMap[$am[0]] = (int)$pdo->lastInsertId();
    }

    $bookings = [
        ['BK-901', 1, $amenityIdMap['AM-2'], 'Sophia Chen-Miller', 'Today, 04:00 PM - 06:00 PM', 'Private Match', 500.00],
        ['BK-902', 1, $amenityIdMap['AM-3'], 'Marcus Vance', 'Saturday, 07:00 PM - 11:00 PM', 'Anniversary Soiree', 10000.00],
        ['BK-903', 1, $amenityIdMap['AM-4'], 'Vikramaditya Roy', 'Friday, 08:00 PM - 11:00 PM', 'Film Screening', 1500.00]
    ];

    $stmtBooking = $pdo->prepare("INSERT INTO `facility_bookings` 
        (`booking_code`, `society_id`, `amenity_id`, `resident_name`, `time_slot`, `purpose`, `amount_paid`, `status`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, 'Confirmed')");

    foreach ($bookings as $b) {
        $stmtBooking->execute([$b[0], $b[1], $b[2], $b[3], $b[4], $b[5], $b[6]]);
    }
    echo "✓ Seeded Amenities & Reservations\n";

    // 16. Seed Notices
    $notices = [
        ['NTC-101', 1, 'Annual General Body Meeting (AGM 2026)', 'Community Event', 'Urgent', 'The 2026 Annual General Body meeting is scheduled for September 15th at 06:30 PM in the Skyline Banquet Hall.', 'Today, 09:30 AM', 1],
        ['NTC-102', 1, 'High-Speed Fiber Grid Scheduled Maintenance', 'Maintenance', 'Normal', 'Core switch firmware upgrade tonight between 02:00 AM - 04:00 AM.', 'Yesterday, 04:15 PM', 0],
        ['NTC-103', 1, 'Gate 2 Facial-Recognition Biometric Lane Active', 'Security Alert', 'High', 'AI-powered fast-lane gate access is now fully operational at East Gate.', '2 days ago', 0],
        ['NTC-201', 2, 'Welcome to Sapphire Palms Smart Living', 'General', 'Normal', 'All residents are invited to download the estate app.', 'Today', 1]
    ];

    $stmtNotice = $pdo->prepare("INSERT INTO `notices` 
        (`notice_code`, `society_id`, `title`, `category`, `priority`, `content`, `date_label`, `is_pinned`) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($notices as $n) {
        $stmtNotice->execute([$n[0], $n[1], $n[2], $n[3], $n[4], $n[5], $n[6], $n[7]]);
    }
    echo "✓ Seeded Notices & Broadcasts\n";

    // 17. Seed Audit Logs
    $auditLogs = [
        [1, 'USER_LOGIN', 'users', 'USR-1001', 'Seraphina Thorne', '127.0.0.1', 'Estate Director logged into web console'],
        [1, 'ROLE_ASSIGNED', 'roles', 'super_admin', 'System Seeder', '127.0.0.1', 'Assigned Super Admin role to Seraphina Thorne'],
        [1, 'GATE_PASS_ISSUED', 'visitors', 'VIS-001', 'Rajesh Gatekeeper', '127.0.0.1', 'Issued Gate 1 entrance pass for Liam Sterling (A-1002)'],
        [1, 'INVOICE_GENERATED', 'invoices', 'INV-2026-8801', 'Elena Rostova', '127.0.0.1', 'Generated monthly maintenance invoice for A-1002']
    ];

    $stmtAudit = $pdo->prepare("INSERT INTO `audit_logs` (`society_id`, `action`, `entity_type`, `entity_id`, `actor_name`, `ip_address`, `details`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($auditLogs as $a) {
        $stmtAudit->execute([$a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6]]);
    }
    echo "✓ Seeded Initial Security Audit Logs\n";

    // 18. Seed Geographic Master Tables
    require __DIR__ . '/seed_geo_data.php';

    echo "========================================================\n";
    echo "✅ MULTI-TENANT MIGRATION & SEEDING COMPLETE!\n";
    echo "========================================================\n";

} catch (Exception $e) {
    echo "\n❌ MIGRATION FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

