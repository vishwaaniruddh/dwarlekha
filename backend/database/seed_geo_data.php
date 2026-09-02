<?php
/**
 * Geographic Master Database Seeder
 * Populates Countries, Zones, States, and Cities for Multi-Tenant ERP
 */

require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

try {
    $pdo = Database::getConnection();
    echo "Connected to database...\n";

    // 1. Create tables if not exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `countries` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(100) NOT NULL,
          `iso_code` VARCHAR(10) NULL,
          `phone_code` VARCHAR(10) NULL,
          `currency` VARCHAR(10) DEFAULT 'INR',
          `status` VARCHAR(20) DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `zones` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `name` VARCHAR(50) NOT NULL,
          `code` VARCHAR(20) NULL,
          `status` VARCHAR(20) DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `states` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `country_id` INT NOT NULL,
          `zone_id` INT NULL,
          `name` VARCHAR(100) NOT NULL,
          `state_code` VARCHAR(10) NULL,
          `status` VARCHAR(20) DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_state_country` (`country_id`),
          INDEX `idx_state_zone` (`zone_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `cities` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `state_id` INT NOT NULL,
          `country_id` INT NOT NULL,
          `zone_id` INT NULL,
          `name` VARCHAR(100) NOT NULL,
          `status` VARCHAR(20) DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_city_state` (`state_id`),
          INDEX `idx_city_country` (`country_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $pdo->exec("TRUNCATE TABLE `cities`");
    $pdo->exec("TRUNCATE TABLE `states`");
    $pdo->exec("TRUNCATE TABLE `zones`");
    $pdo->exec("TRUNCATE TABLE `countries`");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // 2. Seed Countries
    $countries = [
        [1, 'India', 'IN', '+91', 'INR', 'active'],
        [2, 'United States', 'US', '+1', 'USD', 'active'],
        [3, 'United Arab Emirates', 'AE', '+971', 'AED', 'active'],
        [4, 'United Kingdom', 'GB', '+44', 'GBP', 'active'],
        [5, 'Singapore', 'SG', '+65', 'SGD', 'active'],
        [6, 'Canada', 'CA', '+1', 'CAD', 'active'],
        [7, 'Australia', 'AU', '+61', 'AUD', 'active']
    ];
    $stmtC = $pdo->prepare("INSERT INTO `countries` (`id`, `name`, `iso_code`, `phone_code`, `currency`, `status`) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($countries as $c) {
        $stmtC->execute($c);
    }
    echo "✓ Seeded " . count($countries) . " countries\n";

    // 3. Seed Zones (Indian Geographic Regions)
    $zones = [
        [1, 'North', 'NORTH', 'active'],
        [2, 'South', 'SOUTH', 'active'],
        [3, 'East', 'EAST', 'active'],
        [4, 'West', 'WEST', 'active'],
        [5, 'Central', 'CENTRAL', 'active'],
        [6, 'North East', 'NE', 'active']
    ];
    $stmtZ = $pdo->prepare("INSERT INTO `zones` (`id`, `name`, `code`, `status`) VALUES (?, ?, ?, ?)");
    foreach ($zones as $z) {
        $stmtZ->execute($z);
    }
    echo "✓ Seeded " . count($zones) . " zones\n";

    // 4. Seed States (36 Indian States/UTs + Key International States)
    $statesData = [
        // India (Country 1)
        // South Zone (2)
        [1, 1, 2, 'Karnataka', 'KA'],
        [2, 1, 2, 'Tamil Nadu', 'TN'],
        [3, 1, 2, 'Telangana', 'TS'],
        [4, 1, 2, 'Andhra Pradesh', 'AP'],
        [5, 1, 2, 'Kerala', 'KL'],
        [6, 1, 2, 'Puducherry', 'PY'],
        [7, 1, 2, 'Lakshadweep', 'LD'],
        [8, 1, 2, 'Andaman and Nicobar Islands', 'AN'],

        // North Zone (1)
        [9, 1, 1, 'Delhi', 'DL'],
        [10, 1, 1, 'Haryana', 'HR'],
        [11, 1, 1, 'Punjab', 'PB'],
        [12, 1, 1, 'Rajasthan', 'RJ'],
        [13, 1, 1, 'Uttar Pradesh', 'UP'],
        [14, 1, 1, 'Himachal Pradesh', 'HP'],
        [15, 1, 1, 'Uttarakhand', 'UK'],
        [16, 1, 1, 'Jammu and Kashmir', 'JK'],
        [17, 1, 1, 'Ladakh', 'LA'],
        [18, 1, 1, 'Chandigarh', 'CH'],

        // West Zone (4)
        [19, 1, 4, 'Maharashtra', 'MH'],
        [20, 1, 4, 'Gujarat', 'GJ'],
        [21, 1, 4, 'Goa', 'GA'],
        [22, 1, 4, 'Dadra and Nagar Haveli and Daman and Diu', 'DN'],

        // Central Zone (5)
        [23, 1, 5, 'Madhya Pradesh', 'MP'],
        [24, 1, 5, 'Chhattisgarh', 'CG'],

        // East Zone (3)
        [25, 1, 3, 'West Bengal', 'WB'],
        [26, 1, 3, 'Bihar', 'BR'],
        [27, 1, 3, 'Odisha', 'OD'],
        [28, 1, 3, 'Jharkhand', 'JH'],

        // North East Zone (6)
        [29, 1, 6, 'Assam', 'AS'],
        [30, 1, 6, 'Sikkim', 'SK'],
        [31, 1, 6, 'Meghalaya', 'ML'],
        [32, 1, 6, 'Manipur', 'MN'],
        [33, 1, 6, 'Nagaland', 'NL'],
        [34, 1, 6, 'Tripura', 'TR'],
        [35, 1, 6, 'Mizoram', 'MZ'],
        [36, 1, 6, 'Arunachal Pradesh', 'AR'],

        // UAE (Country 3)
        [37, 3, 4, 'Dubai Emirate', 'DXB'],
        [38, 3, 4, 'Abu Dhabi Emirate', 'AUH'],
        [39, 3, 4, 'Sharjah Emirate', 'SHJ'],
        [40, 3, 4, 'Ajman Emirate', 'AJM'],
        [41, 3, 4, 'Ras Al Khaimah', 'RAK'],

        // USA (Country 2)
        [42, 2, 4, 'California', 'CA'],
        [43, 2, 5, 'Texas', 'TX'],
        [44, 2, 3, 'New York', 'NY'],
        [45, 2, 2, 'Florida', 'FL'],
        [46, 2, 1, 'Washington', 'WA'],
        [47, 2, 1, 'Illinois', 'IL'],

        // UK (Country 4)
        [48, 4, 1, 'Greater London', 'LDN'],
        [49, 4, 1, 'West Midlands', 'WMD'],
        [50, 4, 1, 'Greater Manchester', 'MAN'],
        [51, 4, 1, 'Scotland', 'SCT'],

        // Singapore (Country 5)
        [52, 5, 2, 'Central Region', 'SGP-C'],
        [53, 5, 2, 'East Region', 'SGP-E'],
        [54, 5, 2, 'West Region', 'SGP-W'],
        [55, 5, 2, 'North Region', 'SGP-N']
    ];

    $stmtS = $pdo->prepare("INSERT INTO `states` (`id`, `country_id`, `zone_id`, `name`, `state_code`, `status`) VALUES (?, ?, ?, ?, ?, 'active')");
    foreach ($statesData as $s) {
        $stmtS->execute($s);
    }
    echo "✓ Seeded " . count($statesData) . " states/provinces\n";

    // 5. Seed Cities Catalog (Top Cities for each state)
    $citiesMap = [
        // Karnataka (1)
        1 => ['Bengaluru Urban', 'Bengaluru Rural', 'Mysuru', 'Mangaluru', 'Hubballi-Dharwad', 'Belagavi', 'Kalaburagi', 'Davanagere', 'Ballari', 'Shivamogga', 'Tumakuru', 'Udupi', 'Hassan', 'Bidar', 'Mandya', 'Chikkamagaluru', 'Kolar', 'Bagalkote', 'Vijayapura', 'Raichur'],
        // Tamil Nadu (2)
        2 => ['Chennai', 'Coimbatore', 'Madurai', 'Tiruchirappalli', 'Salem', 'Tiruppur', 'Erode', 'Vellore', 'Tirunelveli', 'Thoothukudi', 'Dindigul', 'Thanjavur', 'Ranipet', 'Kanchipuram', 'Nagercoil', 'Hosur', 'Chengalpattu'],
        // Telangana (3)
        3 => ['Hyderabad', 'Secunderabad', 'Warangal', 'Nizamabad', 'Karimnagar', 'Khammam', 'Ramagundam', 'Mahbubnagar', 'Nalgonda', 'Adilabad', 'Siddipet', 'Miryalaguda', 'Suryapet', 'Mancherial'],
        // Andhra Pradesh (4)
        4 => ['Visakhapatnam', 'Vijayawada', 'Guntur', 'Nellore', 'Kurnool', 'Rajahmundry', 'Tirupati', 'Kakinada', 'Kadapa', 'Anantapur', 'Eluru', 'Vizianagaram', 'Ongole', 'Srikakulam', 'Chittoor'],
        // Kerala (5)
        5 => ['Kochi', 'Thiruvananthapuram', 'Kozhikode', 'Thrissur', 'Kollam', 'Palakkad', 'Alappuzha', 'Kannur', 'Kottayam', 'Malappuram', 'Kasaragod', 'Pathanamthitta', 'Idukki', 'Wayanad'],
        // Puducherry (6)
        6 => ['Puducherry', 'Karaikal', 'Mahe', 'Yanam'],
        // Lakshadweep (7)
        7 => ['Kavaratti', 'Agatti', 'Andrott', 'Minicoy'],
        // Andaman (8)
        8 => ['Port Blair', 'Havelock Island', 'Diglipur', 'Mayabunder'],

        // Delhi (9)
        9 => ['New Delhi', 'Central Delhi', 'South Delhi', 'North Delhi', 'East Delhi', 'West Delhi', 'Dwarka', 'Rohini', 'Saket', 'Connaught Place', 'Vasant Kunj', 'Janakpuri', 'Lajpat Nagar', 'Pitampura', 'Karol Bagh'],
        // Haryana (10)
        10 => ['Gurugram', 'Faridabad', 'Panipat', 'Ambala', 'Yamunanagar', 'Rohtak', 'Hisar', 'Karnal', 'Sonipat', 'Panchkula', 'Bhiwani', 'Sirsa', 'Bahadurgarh', 'Jind', 'Thanesar', 'Kaithal', 'Rewari', 'Palwal'],
        // Punjab (11)
        11 => ['Ludhiana', 'Amritsar', 'Jalandhar', 'Patiala', 'Bathinda', 'Mohali (SAS Nagar)', 'Hoshiarpur', 'Batala', 'Pathankot', 'Moga', 'Abohar', 'Malerkotla', 'Khanna', 'Phagwara', 'Muktsar'],
        // Rajasthan (12)
        12 => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota', 'Bikaner', 'Ajmer', 'Bhilwara', 'Alwar', 'Sikar', 'Bharatpur', 'Pali', 'Sri Ganganagar', 'Kishangarh', 'Barmer', 'Hanumangarh', 'Beawar'],
        // Uttar Pradesh (13)
        13 => ['Noida', 'Greater Noida', 'Lucknow', 'Kanpur', 'Ghaziabad', 'Agra', 'Varanasi', 'Meerut', 'Prayagraj (Allahabad)', 'Bareilly', 'Aligarh', 'Moradabad', 'Gorakhpur', 'Saharanpur', 'Firozabad', 'Jhansi', 'Muzaffarnagar', 'Mathura', 'Ayodhya', 'Hapur'],
        // Himachal Pradesh (14)
        14 => ['Shimla', 'Dharamshala', 'Solan', 'Mandi', 'Kullu', 'Manali', 'Baddi', 'Bilaspur', 'Hamirpur', 'Una', 'Chamba', 'Nahan'],
        // Uttarakhand (15)
        15 => ['Dehradun', 'Haridwar', 'Roorkee', 'Haldwani', 'Rishikesh', 'Rudrapur', 'Kashipur', 'Nainital', 'Mussoorie', 'Pithoragarh', 'Kotdwar'],
        // Jammu and Kashmir (16)
        16 => ['Srinagar', 'Jammu', 'Anantnag', 'Baramulla', 'Udhampur', 'Kathua', 'Sopore', 'Rajouri', 'Poonch'],
        // Ladakh (17)
        17 => ['Leh', 'Kargil'],
        // Chandigarh (18)
        18 => ['Chandigarh'],

        // Maharashtra (19)
        19 => ['Mumbai', 'Pune', 'Nagpur', 'Thane', 'Nashik', 'Chhatrapati Sambhaji Nagar', 'Navi Mumbai', 'Solapur', 'Kolhapur', 'Amravati', 'Pimpri-Chinchwad', 'Kalyan-Dombivli', 'Vasai-Virar', 'Mira-Bhayandar', 'Bhiwandi', 'Akola', 'Panvel', 'Ulhasnagar', 'Jalgaon', 'Latur', 'Dhule', 'Ahmednagar', 'Chandrapur', 'Nanded'],
        // Gujarat (20)
        20 => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot', 'Bhavnagar', 'Jamnagar', 'Gandhinagar', 'Junagadh', 'Anand', 'Navsari', 'Morbi', 'Nadiad', 'Surendranagar', 'Bharuch', 'Mehsana', 'Bhuj', 'Porbandar', 'Valsad', 'Vapi'],
        // Goa (21)
        21 => ['Panaji', 'Margao', 'Vasco da Gama', 'Mapusa', 'Ponda', 'Bicholim', 'Curchorem'],
        // Dadra & Nagar Haveli (22)
        22 => ['Daman', 'Diu', 'Silvassa'],

        // Madhya Pradesh (23)
        23 => ['Indore', 'Bhopal', 'Jabalpur', 'Gwalior', 'Ujjain', 'Sagar', 'Dewas', 'Satna', 'Ratlam', 'Rewa', 'Murwara (Katni)', 'Singrauli', 'Burhanpur', 'Khandwa', 'Morena', 'Bhind', 'Chhindwara', 'Guna'],
        // Chhattisgarh (24)
        24 => ['Raipur', 'Bhilai', 'Bilaspur', 'Korba', 'Durg', 'Rajnandgaon', 'Jagdalpur', 'Raigarh', 'Ambikapur', 'Dhamtari'],

        // West Bengal (25)
        25 => ['Kolkata', 'Howrah', 'Durgapur', 'Asansol', 'Siliguri', 'Bardhaman', 'Kharagpur', 'Bidhannagar', 'Newtown', 'Haldia', 'Malda', 'Baharampur', 'Habra', 'Kanchrapara', 'Naihati'],
        // Bihar (26)
        26 => ['Patna', 'Gaya', 'Bhagalpur', 'Muzaffarpur', 'Purnia', 'Darbhanga', 'Bihar Sharif', 'Arrah', 'Begusarai', 'Katihar', 'Munger', 'Chhapra', 'Danapur', 'Saharsa', 'Sasaram', 'Hajipur', 'Dehri'],
        // Odisha (27)
        27 => ['Bhubaneswar', 'Cuttack', 'Rourkela', 'Berhampur', 'Sambalpur', 'Puri', 'Balasore', 'Bhadrak', 'Baripada', 'Jharsuguda', 'Jeypore'],
        // Jharkhand (28)
        28 => ['Ranchi', 'Jamshedpur', 'Dhanbad', 'Bokaro Steel City', 'Deoghar', 'Phusro', 'Hazaribagh', 'Giridih', 'Ramgarh', 'Medininagar', 'Chirkunda'],

        // Assam (29)
        29 => ['Guwahati', 'Silchar', 'Dibrugarh', 'Jorhat', 'Nagaon', 'Tinsukia', 'Tezpur', 'Bongaigaon', 'Dhubri', 'Diphu', 'North Lakhimpur', 'Karimganj', 'Sivasagar', 'Goalpara', 'Barpeta'],
        // Sikkim (30)
        30 => ['Gangtok', 'Namchi', 'Gyalshing', 'Mangan'],
        // Meghalaya (31)
        31 => ['Shillong', 'Tura', 'Nongstoin', 'Jowai', 'Baghmara'],
        // Manipur (32)
        32 => ['Imphal', 'Thoubal', 'Bishnupur', 'Churachandpur', 'Ukhrul'],
        // Nagaland (33)
        33 => ['Kohima', 'Dimapur', 'Mokokchung', 'Tuensang', 'Wokha'],
        // Tripura (34)
        34 => ['Agartala', 'Dharmanagar', 'Udaipur', 'Kailashahar', 'Belonia'],
        // Mizoram (35)
        35 => ['Aizawl', 'Lunglei', 'Champhai', 'Serchhip', 'Kolasib'],
        // Arunachal Pradesh (36)
        36 => ['Itanagar', 'Naharlagun', 'Pasighat', 'Tawang', 'Ziro'],

        // UAE (37-41)
        37 => ['Dubai City', 'Downtown Dubai', 'Dubai Marina', 'Business Bay', 'Jumeirah', 'Palm Jumeirah', 'Deira', 'Bur Dubai'],
        38 => ['Abu Dhabi City', 'Al Ain', 'Al Dhafra', 'Yas Island', 'Saadiyat Island'],
        39 => ['Sharjah City', 'Khor Fakkan', 'Kalba', 'Al Dhaid'],
        40 => ['Ajman City', 'Masfout', 'Manama'],
        41 => ['Ras Al Khaimah City', 'Al Jazirah Al Hamra'],

        // USA (42-47)
        42 => ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose', 'Sacramento', 'Oakland', 'Irvine', 'Palo Alto'],
        43 => ['Houston', 'Dallas', 'Austin', 'San Antonio', 'Fort Worth', 'El Paso', 'Plano'],
        44 => ['New York City', 'Buffalo', 'Rochester', 'Yonkers', 'Syracuse', 'Albany', 'White Plains'],
        45 => ['Miami', 'Orlando', 'Tampa', 'Jacksonville', 'Fort Lauderdale', 'St. Petersburg'],
        46 => ['Seattle', 'Bellevue', 'Tacoma', 'Spokane', 'Redmond', 'Everett'],
        47 => ['Chicago', 'Aurora', 'Naperville', 'Joliet', 'Rockford', 'Springfield'],

        // UK (48-51)
        48 => ['City of London', 'Westminster', 'Camden', 'Greenwich', 'Kensington', 'Islington'],
        49 => ['Birmingham', 'Coventry', 'Wolverhampton', 'Solihull'],
        50 => ['Manchester', 'Salford', 'Bolton', 'Stockport', 'Oldham'],
        51 => ['Edinburgh', 'Glasgow', 'Aberdeen', 'Dundee'],

        // Singapore (52-55)
        52 => ['Downtown Core', 'Marina Bay', 'Orchard', 'Novena', 'Bukit Timah'],
        53 => ['Tampines', 'Bedok', 'Pasir Ris', 'Changi'],
        54 => ['Jurong East', 'Jurong West', 'Clementi', 'Bukit Batok'],
        55 => ['Woodlands', 'Yishun', 'Sembawang']
    ];

    $stmtCity = $pdo->prepare("INSERT INTO `cities` (`state_id`, `country_id`, `zone_id`, `name`, `status`) VALUES (?, ?, ?, ?, 'active')");
    $totalCitiesCount = 0;

    $stateMeta = [];
    foreach ($statesData as $s) {
        $stateMeta[$s[0]] = ['country_id' => $s[1], 'zone_id' => $s[2]];
    }

    foreach ($citiesMap as $stateId => $cities) {
        $meta = $stateMeta[$stateId] ?? ['country_id' => 1, 'zone_id' => 2];
        foreach ($cities as $cityName) {
            $stmtCity->execute([
                $stateId,
                $meta['country_id'],
                $meta['zone_id'],
                $cityName
            ]);
            $totalCitiesCount++;
        }
    }

    echo "✓ Seeded {$totalCitiesCount} cities across all states and countries!\n";
    echo "========================================================\n";
    echo "Geographic Master Seeding Completed Successfully!\n";
    echo "========================================================\n";

} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
