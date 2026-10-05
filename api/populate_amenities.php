<?php
require_once 'db.php';
try {
    $amenities = [
        'Wi-Fi', 'Air Conditioning', 'Electric Fan', 'Bed', 'Mattress',
        'Cabinet', 'Study Table', 'Chair', 'Parking', 'Laundry Area',
        'Kitchen Access', 'Refrigerator', 'Drinking Water', 'Private Bathroom',
        'Shared Bathroom', 'Hot Shower', 'Balcony', 'Visitors Allowed', 'Pet Friendly'
    ];

    $stmt = $pdo->prepare("INSERT INTO amenities (amenity_name) VALUES (:name) ON CONFLICT (amenity_name) DO NOTHING");
    foreach ($amenities as $name) {
        $stmt->execute(['name' => $name]);
    }

    echo json_encode(["success" => true, "message" => "Master amenities populated successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
