-- ==============================================================================
-- Database Schema: society_management_program
-- Architecture: 5-Layer Society ERP & Access Control
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- ==============================================================================

CREATE DATABASE IF NOT EXISTS `society_management_program` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE `society_management_program`;

-- 1. Societies Master (Module 01: Multi-Tenancy Master)
CREATE TABLE IF NOT EXISTS `societies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_code` VARCHAR(20) UNIQUE NOT NULL, -- Globally unique code (Rule 1: e.g. EMR-01, SPH-02)
  `name` VARCHAR(150) NOT NULL,
  `registration_number` VARCHAR(100),
  `address_line1` VARCHAR(255),
  `address_line2` VARCHAR(255),
  `address` TEXT,
  `city` VARCHAR(100),
  `state` VARCHAR(100),
  `pincode` VARCHAR(10),
  `country` VARCHAR(100) DEFAULT 'India',
  `zone_id` INT NULL,
  `zone` VARCHAR(50) NULL,
  `contact_email` VARCHAR(100),
  `contact_phone` VARCHAR(20),
  `logo_url` VARCHAR(255),
  `currency` VARCHAR(10) DEFAULT 'INR',
  `timezone` VARCHAR(50) DEFAULT 'Asia/Kolkata',
  `is_active` BOOLEAN DEFAULT TRUE,
  `tagline` VARCHAR(255),
  `total_units` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Towers / Blocks (Rule 2: Unique block name per society)
CREATE TABLE IF NOT EXISTS `towers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NOT NULL,
  `tower_code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `total_floors` INT DEFAULT 1,
  `total_units` INT DEFAULT 0,
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_society_block` (`society_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2.1 Standard & Custom Unit Types Catalog
CREATE TABLE IF NOT EXISTS `unit_types` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NULL, -- NULL for global standards, or society_id for custom types
  `type_name` VARCHAR(50) NOT NULL,
  `badge_color` VARCHAR(20) DEFAULT 'blue',
  `typical_area` VARCHAR(50) NOT NULL,
  `standard_sqft` INT DEFAULT 500,
  `use_case` VARCHAR(255) NOT NULL,
  `is_system_standard` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Units / Flats (Rule 3: Unique unit number per block)
CREATE TABLE IF NOT EXISTS `units` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NOT NULL,
  `tower_id` INT NOT NULL, -- block_id
  `unit_code` VARCHAR(20) NOT NULL, -- unit_number
  `floor_number` INT NOT NULL,
  `unit_type` VARCHAR(50) NOT NULL, -- 1BHK, 2BHK, 3BHK, 4BHK, Penthouse, Villa, etc.
  `sqft_area` DECIMAL(10,2) DEFAULT 1200.00,
  `intercom_number` VARCHAR(20),
  `occupancy_status` ENUM('Owner', 'Rented', 'Vacant', 'Occupied (Owner)', 'Occupied (Tenant)') DEFAULT 'Vacant',
  `maintenance_status` ENUM('Paid', 'Pending', 'Overdue', 'N/A') DEFAULT 'Paid',
  `owner_name` VARCHAR(150),
  `tenant_name` VARCHAR(150),
  `contact_phone` VARCHAR(30),
  `contact_email` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tower_id`) REFERENCES `towers`(`id`) ON DELETE CASCADE,
  INDEX `idx_unit_status` (`occupancy_status`),
  INDEX `idx_unit_tower` (`tower_id`, `floor_number`),
  UNIQUE KEY `uk_block_unit` (`tower_id`, `unit_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. RBAC Roles Master
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NULL, -- NULL indicates default system roles applicable across societies
  `role_code` VARCHAR(50) UNIQUE NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `badge_color` VARCHAR(30) DEFAULT 'blue',
  `is_system` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role_code` (`role_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. RBAC Granular Permissions Master
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `permission_code` VARCHAR(60) UNIQUE NOT NULL,
  `module` VARCHAR(50) NOT NULL,
  `action` VARCHAR(50) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_perm_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Role Permissions Mapping
CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uniq_role_perm` (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. User Management Master (Parent Software Co. + Society Tenants)
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NULL, -- NULL indicates SAR Parent Platform Owner / Software Staff
  `is_parent_user` BOOLEAN DEFAULT FALSE, -- TRUE for SAR software staff, FALSE for society tenant users
  `user_code` VARCHAR(50) UNIQUE NOT NULL,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role_id` INT NOT NULL,
  `phone` VARCHAR(30),
  `unit_code` VARCHAR(30) NULL,
  `resident_id` INT NULL,
  `status` ENUM('Active', 'Inactive', 'Suspended') DEFAULT 'Active',
  `avatar_url` TEXT,
  `last_login_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT,
  INDEX `idx_user_society_email` (`society_id`, `email`),
  INDEX `idx_parent_user` (`is_parent_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. User Auth Tokens / Active Sessions
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(128) UNIQUE NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_token_lookup` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Residents Master
CREATE TABLE IF NOT EXISTS `residents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NOT NULL,
  `user_id` INT NULL,
  `unit_id` INT NOT NULL,
  `resident_type` ENUM('Owner', 'Tenant') NOT NULL DEFAULT 'Owner',
  `is_primary_contact` BOOLEAN DEFAULT TRUE,
  `move_in_date` DATE NOT NULL,
  `move_out_date` DATE NULL,
  `verification_status` ENUM('Pending', 'Approved', 'Rejected') DEFAULT 'Pending',
  `verified_by_user_id` INT NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`verified_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_resident_society` (`society_id`),
  INDEX `idx_resident_unit` (`unit_id`),
  INDEX `idx_resident_user` (`user_id`),
  INDEX `idx_resident_status` (`verification_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Family Members
CREATE TABLE IF NOT EXISTS `family_members` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id` INT NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `relation` VARCHAR(50) NOT NULL, -- Spouse, Child, Parent, Sibling, Other
  `phone` VARCHAR(20) NULL,
  `age` INT NULL,
  `gender` ENUM('Male', 'Female', 'Other') NOT NULL DEFAULT 'Other',
  `photo_url` VARCHAR(255) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE,
  INDEX `idx_family_resident` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Resident Documents (KYC & Tenancy Agreements)
CREATE TABLE IF NOT EXISTS `resident_documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `resident_id` INT NOT NULL,
  `document_type` ENUM('Aadhaar', 'PAN', 'Passport', 'Driving License', 'Voter ID', 'Rental Agreement', 'Sale Deed', 'Electricity Bill', 'Other') NOT NULL DEFAULT 'Aadhaar',
  `document_number` VARCHAR(100) NULL,
  `file_url` VARCHAR(255) NOT NULL,
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE,
  INDEX `idx_doc_resident` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Vehicles Master & RFID Tags
CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NOT NULL,
  `unit_id` INT NOT NULL,
  `resident_id` INT NULL,
  `vehicle_number` VARCHAR(20) NOT NULL,
  `vehicle_type` ENUM('Car', 'Bike', 'EV 2W', 'EV 4W', 'Bicycle', 'Other') NOT NULL DEFAULT 'Car',
  `make_model` VARCHAR(100) NULL,
  `parking_slot_number` VARCHAR(50) NULL,
  `rfid_sticker_tag` VARCHAR(100) UNIQUE NULL,
  `pass_status` ENUM('Valid', 'Expired', 'Revoked') DEFAULT 'Valid',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE SET NULL,
  INDEX `idx_vehicle_society` (`society_id`),
  INDEX `idx_vehicle_unit` (`unit_id`),
  INDEX `idx_vehicle_resident` (`resident_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Unit Occupancy Link Table (Historical & Active Leases)
CREATE TABLE IF NOT EXISTS `unit_occupancies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `unit_id` INT NOT NULL,
  `resident_id` INT NOT NULL,
  `occupancy_type` ENUM('Owner', 'Tenant') NOT NULL,
  `start_date` DATE,
  `end_date` DATE,
  `is_primary` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`unit_id`) REFERENCES `units`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`resident_id`) REFERENCES `residents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Visitors & Gate Passes
CREATE TABLE IF NOT EXISTS `visitors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `visitor_code` VARCHAR(50) UNIQUE NOT NULL,
  `society_id` INT NOT NULL,
  `unit_id` INT,
  `name` VARCHAR(150) NOT NULL,
  `visitor_type` VARCHAR(50) DEFAULT 'Guest', -- Guest, Delivery, Cab/Ride, Service/Contractor
  `phone` VARCHAR(30),
  `flat_visiting` VARCHAR(100) NOT NULL,
  `host_name` VARCHAR(150),
  `check_in_time` VARCHAR(50) NOT NULL,
  `check_out_time` VARCHAR(50),
  `status` ENUM('Inside', 'Checked Out', 'Expected', 'Rejected') DEFAULT 'Inside',
  `purpose` VARCHAR(255),
  `gate_number` VARCHAR(50) DEFAULT 'Gate 1 (North)',
  `vehicle_number` VARCHAR(50) DEFAULT 'Walk-in',
  `pass_code` VARCHAR(50) NOT NULL,
  `photo_url` TEXT NULL,
  `notified_resident_name` VARCHAR(150) NULL,
  `notified_resident_type` VARCHAR(50) NULL,
  `approval_status` ENUM('Auto-Approved', 'Pending Approval', 'Approved', 'Denied') NOT NULL DEFAULT 'Auto-Approved',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Invoices & Billing
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(60) UNIQUE NOT NULL, -- e.g. INV-2026-8801
  `society_id` INT NOT NULL,
  `unit_id` INT,
  `flat_number` VARCHAR(30) NOT NULL,
  `resident_name` VARCHAR(150) NOT NULL,
  `month_period` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(10, 2) NOT NULL,
  `base_maintenance` DECIMAL(10, 2) DEFAULT 0,
  `water_charges` DECIMAL(10, 2) DEFAULT 0,
  `sinking_fund` DECIMAL(10, 2) DEFAULT 0,
  `clubhouse_fee` DECIMAL(10, 2) DEFAULT 0,
  `gst_amount` DECIMAL(10, 2) DEFAULT 0,
  `due_date` VARCHAR(50) NOT NULL,
  `paid_date` VARCHAR(50),
  `status` ENUM('Paid', 'Pending', 'Overdue') DEFAULT 'Pending',
  `payment_method` VARCHAR(50), -- UPI, Net Banking, Credit Card, Cheque, Cash
  `receipt_url` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Helpdesk & Maintenance Tickets
CREATE TABLE IF NOT EXISTS `complaints` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_code` VARCHAR(50) UNIQUE NOT NULL,
  `society_id` INT NOT NULL,
  `unit_id` INT,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(50) NOT NULL, -- Plumbing, Electrical, HVAC, Security, Housekeeping, Elevator
  `flat_number` VARCHAR(30) NOT NULL,
  `reported_by` VARCHAR(150) NOT NULL,
  `priority` ENUM('Low', 'Medium', 'High', 'Critical') DEFAULT 'Medium',
  `status` ENUM('Open', 'Assigned', 'In Progress', 'Resolved', 'Closed') DEFAULT 'Open',
  `assigned_to` VARCHAR(150) DEFAULT 'Duty Engineer',
  `description` TEXT,
  `created_at_label` VARCHAR(50),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Amenities Master
CREATE TABLE IF NOT EXISTS `amenities` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `amenity_code` VARCHAR(50) UNIQUE NOT NULL,
  `society_id` INT NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `category` VARCHAR(50),
  `hourly_rate` DECIMAL(10, 2) DEFAULT 0,
  `capacity` INT DEFAULT 20,
  `current_occupancy` INT DEFAULT 0,
  `operating_hours` VARCHAR(100),
  `status` ENUM('Available', 'Full', 'Maintenance') DEFAULT 'Available',
  `image_url` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. Facility Bookings
CREATE TABLE IF NOT EXISTS `facility_bookings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_code` VARCHAR(50) UNIQUE NOT NULL,
  `society_id` INT NOT NULL,
  `amenity_id` INT NOT NULL,
  `resident_name` VARCHAR(150) NOT NULL,
  `time_slot` VARCHAR(100) NOT NULL,
  `purpose` VARCHAR(150),
  `amount_paid` DECIMAL(10, 2) DEFAULT 0,
  `status` ENUM('Confirmed', 'Pending', 'Cancelled') DEFAULT 'Confirmed',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`amenity_id`) REFERENCES `amenities`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. Notices & Broadcasts
CREATE TABLE IF NOT EXISTS `notices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `notice_code` VARCHAR(50) UNIQUE NOT NULL,
  `society_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `category` VARCHAR(50) DEFAULT 'General', -- General, Security Alert, Maintenance, Community Event, Financial
  `priority` ENUM('Normal', 'High', 'Urgent') DEFAULT 'Normal',
  `content` TEXT NOT NULL,
  `date_label` VARCHAR(50),
  `is_pinned` BOOLEAN DEFAULT FALSE,
  `author_name` VARCHAR(100) DEFAULT 'Society Management Office',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`society_id`) REFERENCES `societies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 20. Audit Trail Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `society_id` INT NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50),
  `entity_id` VARCHAR(50),
  `actor_name` VARCHAR(100) DEFAULT 'Admin',
  `ip_address` VARCHAR(50),
  `details` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 21. Geographic Master: Countries
CREATE TABLE IF NOT EXISTS `countries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `iso_code` VARCHAR(10) NULL,
  `phone_code` VARCHAR(10) NULL,
  `currency` VARCHAR(10) DEFAULT 'INR',
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. Geographic Master: Zones / Regions
CREATE TABLE IF NOT EXISTS `zones` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `code` VARCHAR(20) NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 23. Geographic Master: States / Provinces
CREATE TABLE IF NOT EXISTS `states` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `country_id` INT NOT NULL,
  `zone_id` INT NULL,
  `name` VARCHAR(100) NOT NULL,
  `state_code` VARCHAR(10) NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE SET NULL,
  INDEX `idx_state_country` (`country_id`),
  INDEX `idx_state_zone` (`zone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 24. Geographic Master: Cities / Districts
CREATE TABLE IF NOT EXISTS `cities` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `state_id` INT NOT NULL,
  `country_id` INT NOT NULL,
  `zone_id` INT NULL,
  `name` VARCHAR(100) NOT NULL,
  `status` VARCHAR(20) DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`state_id`) REFERENCES `states`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`country_id`) REFERENCES `countries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE SET NULL,
  INDEX `idx_city_state` (`state_id`),
  INDEX `idx_city_country` (`country_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
