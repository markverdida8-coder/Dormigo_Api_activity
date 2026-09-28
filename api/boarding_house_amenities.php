<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!isset($_GET['house_id'])) {
        echo json_encode(["success" => false, "message" => "House ID is required."]);
        exit();
    }
    $stmt = $pdo->prepare("SELECT a.amenity_id, a.amenity_name
                            FROM boarding_house_amenities bha
                            JOIN amenities a ON bha.amenity_id = a.amenity_id
                            WHERE bha.house_id = :house_id");
    $stmt->execute(['house_id' => $_GET['house_id']]);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("INSERT INTO boarding_house_amenities (house_id, amenity_id) VALUES (:house_id, :amenity_id) ON CONFLICT DO NOTHING");
    $stmt->execute(['house_id' => $data['house_id'], 'amenity_id' => $data['amenity_id']]);
    echo json_encode(["success" => true, "message" => "Amenity added to house."]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("DELETE FROM boarding_house_amenities WHERE house_id = :house_id AND amenity_id = :amenity_id");
    $stmt->execute(['house_id' => $data['house_id'], 'amenity_id' => $data['amenity_id']]);
    echo json_encode(["success" => true, "message" => "Amenity removed from house."]);
}
?>
