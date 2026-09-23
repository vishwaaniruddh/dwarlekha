<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

try {
    $db = Database::getConnection();

    $sql = "CREATE TABLE IF NOT EXISTS `society_contacts` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `society_id` INT NOT NULL,
      `contact_type` ENUM('management', 'emergency', 'service_vendor', 'staff') NOT NULL DEFAULT 'management',
      `category` VARCHAR(100) NOT NULL DEFAULT 'General',
      `name` VARCHAR(150) NOT NULL,
      `designation` VARCHAR(150) NULL,
      `phone` VARCHAR(30) NOT NULL,
      `alternate_phone` VARCHAR(30) NULL,
      `email` VARCHAR(100) NULL,
      `avatar_url` TEXT NULL,
      `unit_or_office` VARCHAR(100) NULL,
      `availability_hours` VARCHAR(100) NULL DEFAULT '24x7',
      `remark` TEXT NULL,
      `is_emergency` TINYINT(1) NOT NULL DEFAULT 0,
      `is_verified` TINYINT(1) NOT NULL DEFAULT 1,
      `display_order` INT NOT NULL DEFAULT 0,
      `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
      `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
      `deleted_at` TIMESTAMP NULL DEFAULT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX `idx_soc_contact_type` (`society_id`, `contact_type`, `is_deleted`),
      INDEX `idx_soc_emergency` (`society_id`, `is_emergency`, `is_deleted`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $db->exec($sql);
    echo "Table `society_contacts` created or already exists.\n";

    // Seed initial comprehensive standard directory records for society_id = 1 (Emerald Heights) if empty
    $count = (int)$db->query("SELECT COUNT(*) FROM society_contacts WHERE society_id = 1 AND is_deleted = 0")->fetchColumn();
    if ($count === 0) {
        $sampleContacts = [
            // 1. Management & Office Bearers
            [
                'society_id' => 1,
                'contact_type' => 'management',
                'category' => 'Executive Committee',
                'name' => 'Rajeshwar Singhania',
                'designation' => 'President / Chairman',
                'phone' => '+91 98201 11223',
                'alternate_phone' => '+91 22 2654 0001',
                'email' => 'president.emerald@dwarlekha.com',
                'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Flat A-1402',
                'availability_hours' => '10:00 AM – 06:00 PM (Mon–Fri)',
                'remark' => 'Oversees overall society governance, legal compliance, and major redevelopment initiatives.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 1
            ],
            [
                'society_id' => 1,
                'contact_type' => 'management',
                'category' => 'Executive Committee',
                'name' => 'Meera Deshmukh',
                'designation' => 'Secretary',
                'phone' => '+91 98334 22334',
                'alternate_phone' => null,
                'email' => 'secretary.emerald@dwarlekha.com',
                'avatar_url' => 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Flat B-0804',
                'availability_hours' => '05:00 PM – 08:00 PM (Mon–Sat)',
                'remark' => 'Handles resident onboarding, AGM/SGM circulars, NOC issuance, and vendor contracts.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 2
            ],
            [
                'society_id' => 1,
                'contact_type' => 'management',
                'category' => 'Accounts & Audit',
                'name' => 'Ketan Parekh (CA)',
                'designation' => 'Treasurer / Accountant',
                'phone' => '+91 98199 44556',
                'alternate_phone' => null,
                'email' => 'accounts.emerald@dwarlekha.com',
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Society Office (Clubhouse GF)',
                'availability_hours' => '11:00 AM – 04:00 PM (Tue, Thu, Sat)',
                'remark' => 'Manages maintenance billing, GL accounting, GST filings, and sinking fund investments.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 3
            ],
            [
                'society_id' => 1,
                'contact_type' => 'management',
                'category' => 'Estate & Developer',
                'name' => 'Vikramaditya Roy',
                'designation' => 'Builder / Developer Representative',
                'phone' => '+91 99200 88990',
                'alternate_phone' => '+91 22 6120 5000',
                'email' => 'builder.liaison@emeralddevelopments.com',
                'avatar_url' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Developer Sales Gallery',
                'availability_hours' => '10:00 AM – 07:00 PM (All Days)',
                'remark' => 'Liaison for structural warranty, handover documentation, parking allotment, and occupancy certificates.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 4
            ],
            [
                'society_id' => 1,
                'contact_type' => 'management',
                'category' => 'Facility Operations',
                'name' => 'Rameshwar Kulkarni',
                'designation' => 'Estate Facility Manager',
                'phone' => '+91 98920 33112',
                'alternate_phone' => null,
                'email' => 'facility.manager@emeraldheights.in',
                'avatar_url' => 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Estate Management Cabin, Tower A GF',
                'availability_hours' => '09:00 AM – 07:00 PM (Daily)',
                'remark' => 'Day-to-day estate operations, DG set management, clubhouse bookings, and AMC supervision.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 5
            ],

            // 2. Emergency Services
            [
                'society_id' => 1,
                'contact_type' => 'emergency',
                'category' => 'Gate & Perimeter',
                'name' => 'Main Security Control Room',
                'designation' => 'Gate 1 (North Command)',
                'phone' => '+91 22 2890 0100',
                'alternate_phone' => 'Ext 100 / 101',
                'email' => 'security@emeraldheights.in',
                'avatar_url' => null,
                'unit_or_office' => 'North Gatehouse',
                'availability_hours' => '24x7 365 Days',
                'remark' => '24/7 Gate security dispatch, barrier control, and CCTV incident escalation.',
                'is_emergency' => 1,
                'is_verified' => 1,
                'display_order' => 10
            ],
            [
                'society_id' => 1,
                'contact_type' => 'emergency',
                'category' => 'Lift / Elevator SOS',
                'name' => 'Otis Elevator 24/7 SOS Helpline',
                'designation' => 'Emergency Lift Rescue',
                'phone' => '1800 120 6847',
                'alternate_phone' => '+91 98200 99881',
                'email' => 'emergency.service@otis.com',
                'avatar_url' => null,
                'unit_or_office' => 'AMC Contract #OTIS-EMR-2026',
                'availability_hours' => '24x7 Emergency Response',
                'remark' => 'Immediate response for trapped passengers or elevator breakdown across all towers.',
                'is_emergency' => 1,
                'is_verified' => 1,
                'display_order' => 11
            ],
            [
                'society_id' => 1,
                'contact_type' => 'emergency',
                'category' => 'Law Enforcement',
                'name' => 'Local Police Station (Jurisdiction)',
                'designation' => 'Police Station Desk',
                'phone' => '112',
                'alternate_phone' => '+91 22 2888 1234',
                'email' => 'police.station@mahapolice.gov.in',
                'avatar_url' => null,
                'unit_or_office' => 'Sector 14 Police Station',
                'availability_hours' => '24x7',
                'remark' => 'National Emergency 112 / Local station control for law and order assistance.',
                'is_emergency' => 1,
                'is_verified' => 1,
                'display_order' => 12
            ],
            [
                'society_id' => 1,
                'contact_type' => 'emergency',
                'category' => 'Fire & Rescue',
                'name' => 'City Fire Brigade EMT',
                'designation' => 'Fire Command Station',
                'phone' => '101',
                'alternate_phone' => '+91 22 2307 6111',
                'email' => null,
                'avatar_url' => null,
                'unit_or_office' => 'West Zone Fire Depot',
                'availability_hours' => '24x7',
                'remark' => 'Hydrant and high-rise tender unit with hydraulic snorkel support.',
                'is_emergency' => 1,
                'is_verified' => 1,
                'display_order' => 13
            ],
            [
                'society_id' => 1,
                'contact_type' => 'emergency',
                'category' => 'Medical Trauma',
                'name' => 'Apex Super-Specialty Hospital',
                'designation' => 'Trauma & Cardiac Ambulance',
                'phone' => '108',
                'alternate_phone' => '+91 22 6888 9999',
                'email' => 'emergency@apexhospital.org',
                'avatar_url' => null,
                'unit_or_office' => '1.2 km from Society Main Gate',
                'availability_hours' => '24x7 Emergency Trauma ICU',
                'remark' => 'Tie-up for prioritized society resident admission and ICU ambulance dispatch.',
                'is_emergency' => 1,
                'is_verified' => 1,
                'display_order' => 14
            ],

            // 3. Local Utility Vendors & Verified Technicians
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Plumbing',
                'name' => 'Santosh Sharma (Master Plumber)',
                'designation' => 'Society Verified Plumber',
                'phone' => '+91 98670 12345',
                'alternate_phone' => '+91 98670 54321',
                'email' => null,
                'avatar_url' => 'https://images.unsplash.com/photo-1540569014015-19a7be504e3a?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'Shop 4, Society Commercial Arcade',
                'availability_hours' => '08:00 AM – 09:00 PM',
                'remark' => 'Leak repairs, concealed pipeline fitting, pressure pump tuning, RO filter connection.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 20
            ],
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Electrical',
                'name' => 'Imran Ansari Electricals',
                'designation' => 'Licensed Wireman / Electrician',
                'phone' => '+91 98192 78901',
                'alternate_phone' => null,
                'email' => null,
                'avatar_url' => 'https://images.unsplash.com/photo-1506794778202-cad84cf45f1d?w=150&auto=format&fit=crop&q=80',
                'unit_or_office' => 'On-call Society Technician',
                'availability_hours' => '08:30 AM – 10:00 PM',
                'remark' => 'MCB tripping, short circuits, geyser / fan installation, inverter battery servicing.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 21
            ],
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Carpentry',
                'name' => 'Gopal Suthar & Sons',
                'designation' => 'Master Carpenter & Woodwork',
                'phone' => '+91 98211 45678',
                'alternate_phone' => null,
                'email' => null,
                'avatar_url' => null,
                'unit_or_office' => 'Sector 12 Woodcraft',
                'availability_hours' => '09:00 AM – 08:00 PM',
                'remark' => 'Door lock replacement, modular kitchen repair, furniture restoration, hinge replacement.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 22
            ],
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Appliances & AC',
                'name' => 'CoolTech Air Conditioning & Refrigeration',
                'designation' => 'HVAC & Appliance Specialist',
                'phone' => '+91 98900 66778',
                'alternate_phone' => '+91 22 2844 5500',
                'email' => 'service@cooltechsolutions.in',
                'avatar_url' => null,
                'unit_or_office' => 'Authorized Multi-Brand Center',
                'availability_hours' => '09:00 AM – 08:00 PM',
                'remark' => 'AC gas charging, jet pump chemical cleaning, washing machine and refrigerator repair.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 23
            ],
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Pest Control',
                'name' => 'EcoShield Pest Management',
                'designation' => 'Herbal & Odorless Pest Control',
                'phone' => '+91 98205 11998',
                'alternate_phone' => '1800 200 4455',
                'email' => 'support@ecoshieldpest.com',
                'avatar_url' => null,
                'unit_or_office' => 'Society AMC Partner',
                'availability_hours' => '08:00 AM – 06:00 PM',
                'remark' => 'Termite piping treatment, bedbug eradication, odorless cockroach gel application.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 24
            ],
            [
                'society_id' => 1,
                'contact_type' => 'service_vendor',
                'category' => 'Internet & Broadband',
                'name' => 'Airtel & Jio Fiber Society Desk',
                'designation' => 'Dedicated Fiber Support Engineer',
                'phone' => '+91 98700 33445',
                'alternate_phone' => '198 / 199',
                'email' => 'fiber.emerald@jio.com',
                'avatar_url' => null,
                'unit_or_office' => 'MDF Telecom Room (Basement B1)',
                'availability_hours' => '08:00 AM – 09:00 PM',
                'remark' => 'Direct engineer dispatch for optical fiber cuts, router replacements, and static IP setup.',
                'is_emergency' => 0,
                'is_verified' => 1,
                'display_order' => 25
            ]
        ];

        $stmt = $db->prepare("INSERT INTO society_contacts (
            society_id, contact_type, category, name, designation, phone, alternate_phone, email, 
            avatar_url, unit_or_office, availability_hours, remark, is_emergency, is_verified, display_order, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

        foreach ($sampleContacts as $sc) {
            $stmt->execute([
                $sc['society_id'],
                $sc['contact_type'],
                $sc['category'],
                $sc['name'],
                $sc['designation'],
                $sc['phone'],
                $sc['alternate_phone'],
                $sc['email'],
                $sc['avatar_url'],
                $sc['unit_or_office'],
                $sc['availability_hours'],
                $sc['remark'],
                $sc['is_emergency'],
                $sc['is_verified'],
                $sc['display_order']
            ]);
        }
        echo "Seeded " . count($sampleContacts) . " standard directory records for Emerald Heights.\n";
    }

} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
