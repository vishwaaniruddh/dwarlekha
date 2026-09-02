<?php
require_once __DIR__ . '/../src/Config/Database.php';

use App\Config\Database;

$db = Database::getConnection();

$societyId = 3; // Paranjape Blue Ridge

$check = $db->prepare("SELECT COUNT(*) FROM amenities WHERE society_id = ? AND is_deleted = 0");
$check->execute([$societyId]);
$count = (int)$check->fetchColumn();

if ($count === 0) {
    $seedData = [
        [
            'code' => 'AMN-POOL-01',
            'name' => 'Olympic Infinity Swimming Pool',
            'category' => 'Sports',
            'hourly_rate' => 0.00,
            'capacity' => 40,
            'current_occupancy' => 8,
            'operating_hours' => '06:00 AM - 10:00 PM',
            'status' => 'Available',
            'location' => 'Clubhouse Level 1 (Outdoor Deck)',
            'description' => 'Temperature controlled 50m infinity lap pool with dedicated toddler splash pool, sun loungers, and certified lifeguard on duty.',
            'rules' => '1. Proper nylon/lycra swimwear mandatory.\n2. Shower before entering.\n3. Children under 12 must be accompanied by an adult.\n4. No glassware near pool deck.',
            'image_url' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?w=600&auto=format&fit=crop&q=80',
            'media' => json_encode([
                [
                    'url' => 'https://images.unsplash.com/photo-1576013551627-0cc20b96c2a7?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Pool Deck Day View'
                ],
                [
                    'url' => 'https://images.unsplash.com/photo-1566073771259-6a8506099945?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Clubhouse Poolside Evening'
                ],
                [
                    'url' => 'https://commondatastorage.googleapis.com/gtv-videos-bucket/sample/ForBiggerBlazes.mp4',
                    'type' => 'video',
                    'name' => 'Poolside Tour Video'
                ]
            ])
        ],
        [
            'code' => 'AMN-GYM-01',
            'name' => 'High-Performance Cardio & Fitness Gym',
            'category' => 'Wellness',
            'hourly_rate' => 0.00,
            'capacity' => 30,
            'current_occupancy' => 14,
            'operating_hours' => '05:30 AM - 11:00 PM',
            'status' => 'Available',
            'location' => 'Tower B Podium Level',
            'description' => 'Fully equipped gym with LifeFitness treadmills, cross-trainers, free weights, Olympic barbells, kettlebells, and certified personal trainers.',
            'rules' => '1. Clean gym shoes mandatory.\n2. Wipe equipment after use.\n3. Re-rack weights after workout.\n4. Locker keys must be returned.',
            'image_url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=600&auto=format&fit=crop&q=80',
            'media' => json_encode([
                [
                    'url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Cardio Zone'
                ],
                [
                    'url' => 'https://images.unsplash.com/photo-1571902943202-507ec2618e8f?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Strength Training Deck'
                ]
            ])
        ],
        [
            'code' => 'AMN-CLUB-01',
            'name' => 'Royal Banquet & Party Hall',
            'category' => 'Events',
            'hourly_rate' => 500.00,
            'capacity' => 120,
            'current_occupancy' => 0,
            'operating_hours' => '09:00 AM - 11:30 PM',
            'status' => 'Available',
            'location' => 'Main Clubhouse Block A',
            'description' => 'Air-conditioned luxury banquet hall with stage, Bose surround sound audio system, ambient ceiling chandeliers, attached catering pantry and dining area.',
            'rules' => '1. Prior booking approval required.\n2. Music volume strictly regulated after 10 PM.\n3. Cleaning deposit refundable upon handover.',
            'image_url' => 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?w=600&auto=format&fit=crop&q=80',
            'media' => json_encode([
                [
                    'url' => 'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Banquet Seating'
                ],
                [
                    'url' => 'https://images.unsplash.com/photo-1464366400600-7168b8af9bc3?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Stage & Dining Setup'
                ]
            ])
        ],
        [
            'code' => 'AMN-BADM-01',
            'name' => 'Synthetic Indoor Badminton Court',
            'category' => 'Sports',
            'hourly_rate' => 150.00,
            'capacity' => 8,
            'current_occupancy' => 4,
            'operating_hours' => '06:00 AM - 10:30 PM',
            'status' => 'Available',
            'location' => 'Sports Arena Hall 2',
            'description' => 'BWF standard wooden sprung synthetic mat court with LED floodlights and electronic scoring display.',
            'rules' => '1. Non-marking gum sole shoes mandatory.\n2. Max 60 mins per booking slot.\n3. Bring your own racquets and shuttles.',
            'image_url' => 'https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?w=600&auto=format&fit=crop&q=80',
            'media' => json_encode([
                [
                    'url' => 'https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?w=800&auto=format&fit=crop&q=80',
                    'type' => 'image',
                    'name' => 'Badminton Court 1'
                ]
            ])
        ]
    ];

    $stmt = $db->prepare("INSERT INTO amenities 
        (amenity_code, society_id, name, category, hourly_rate, capacity, current_occupancy, operating_hours, status, location, description, rules, image_url, media, is_deleted) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");

    foreach ($seedData as $s) {
        $stmt->execute([
            $s['code'],
            $societyId,
            $s['name'],
            $s['category'],
            $s['hourly_rate'],
            $s['capacity'],
            $s['current_occupancy'],
            $s['operating_hours'],
            $s['status'],
            $s['location'],
            $s['description'],
            $s['rules'],
            $s['image_url'],
            $s['media']
        ]);
    }
    echo "Seeded " . count($seedData) . " amenities for society_id = $societyId.\n";
} else {
    echo "Amenities already exist for society_id = $societyId ($count found).\n";
}
