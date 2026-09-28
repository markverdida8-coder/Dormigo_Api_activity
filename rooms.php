<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['house_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM rooms WHERE house_id = :house_id ORDER BY room_id ASC");
        $stmt->execute(['house_id' => $_GET['house_id']]);
    } else {
        $stmt = $pdo->query("SELECT * FROM rooms ORDER BY room_id ASC");
    }
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("INSERT INTO rooms (house_id, room_number, room_type, capacity, monthly_rent, status)
                            VALUES (:house_id, :room_number, :room_type, :capacity, :monthly_rent, :status::room_status_enum) RETURNING room_id");
    $stmt->execute([
        'house_id' => $data['house_id'],
        'room_number' => $data['room_number'],
        'room_type' => $data['room_type'],
        'capacity' => $data['capacity'],
        'monthly_rent' => $data['monthly_rent'],
        'status' => strtoupper($data['status'] ?? 'AVAILABLE')
    ]);
    echo json_encode(["success" => true, "message" => "Room created successfully.", "room_id" => $stmt->fetchColumn()]);

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("UPDATE rooms SET room_number = :room_number, room_type = :room_type, capacity = :capacity, monthly_rent = :monthly_rent, status = :status::room_status_enum WHERE room_id = :room_id");
    $stmt->execute([
        'room_id' => $data['room_id'],
        'room_number' => $data['room_number'],
        'room_type' => $data['room_type'],
        'capacity' => $data['capacity'],
        'monthly_rent' => $data['monthly_rent'],
        'status' => strtoupper($data['status'])
    ]);
    echo json_encode(["success" => true, "message" => "Room updated successfully."]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = :room_id");
    $stmt->execute(['room_id' => $data['room_id']]);
    echo json_encode(["success" => true, "message" => "Room deleted successfully."]);
}
?>
