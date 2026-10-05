<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM amenities ORDER BY amenity_id ASC");
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['amenity_name']) || trim($data['amenity_name']) === '') {
        echo json_encode(["success" => false, "message" => "Amenity name is required."]);
        exit();
    }
    $name = trim($data['amenity_name']);
    try {
        $stmt = $pdo->prepare("INSERT INTO amenities (amenity_name) VALUES (:name) ON CONFLICT (amenity_name) DO UPDATE SET amenity_name = EXCLUDED.amenity_name RETURNING *");
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        echo json_encode(["success" => true, "message" => "Amenity added successfully.", "data" => $row]);
    } catch (Exception $e) {
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
?>
