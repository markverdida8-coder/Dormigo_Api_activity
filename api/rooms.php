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
    $stmt = $pdo->prepare("INSERT INTO rooms (house_id, room_number, room_type, capacity, monthly_rent, status, advance_months, deposit_months, utility_deposit, other_fees, other_fees_description, deposit_refund_policy)
                            VALUES (:house_id, :room_number, :room_type, :capacity, :monthly_rent, :status::room_status_enum, :advance_months, :deposit_months, :utility_deposit, :other_fees, :other_fees_description, :deposit_refund_policy) RETURNING room_id");
    $stmt->execute([
        'house_id' => $data['house_id'],
        'room_number' => $data['room_number'] ?? 'Room 1',
        'room_type' => $data['room_type'] ?? 'Bedspace',
        'capacity' => $data['capacity'] ?? 1,
        'monthly_rent' => $data['monthly_rent'] ?? 0,
        'status' => strtoupper($data['status'] ?? 'AVAILABLE'),
        'advance_months' => $data['advance_months'] ?? 1,
        'deposit_months' => $data['deposit_months'] ?? 1,
        'utility_deposit' => $data['utility_deposit'] ?? 0.00,
        'other_fees' => $data['other_fees'] ?? 0.00,
        'other_fees_description' => $data['other_fees_description'] ?? '',
        'deposit_refund_policy' => $data['deposit_refund_policy'] ?? ''
    ]);
    echo json_encode(["success" => true, "message" => "Room created successfully.", "room_id" => $stmt->fetchColumn()]);

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['status']) && isset($data['room_id']) && !isset($data['room_number']) && !isset($data['monthly_rent'])) {
        $stmt = $pdo->prepare("UPDATE rooms SET status = :status::room_status_enum WHERE room_id = :room_id");
        $stmt->execute([
            'room_id' => $data['room_id'],
            'status' => strtoupper($data['status'])
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE rooms SET room_number = :room_number, room_type = :room_type, capacity = :capacity, monthly_rent = :monthly_rent, status = :status::room_status_enum, advance_months = :advance_months, deposit_months = :deposit_months, other_fees = :other_fees, other_fees_description = :other_fees_description, deposit_refund_policy = :deposit_refund_policy WHERE room_id = :room_id");
        $stmt->execute([
            'room_id' => $data['room_id'],
            'room_number' => $data['room_number'] ?? 'Room 1',
            'room_type' => $data['room_type'] ?? 'Bedspace',
            'capacity' => $data['capacity'] ?? 1,
            'monthly_rent' => $data['monthly_rent'] ?? 0,
            'status' => strtoupper($data['status'] ?? 'AVAILABLE'),
            'advance_months' => $data['advance_months'] ?? 1,
            'deposit_months' => $data['deposit_months'] ?? 1,
            'other_fees' => $data['other_fees'] ?? 0.00,
            'other_fees_description' => $data['other_fees_description'] ?? '',
            'deposit_refund_policy' => $data['deposit_refund_policy'] ?? ''
        ]);
    }
    echo json_encode(["success" => true, "message" => "Room updated successfully."]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = :room_id");
    $stmt->execute(['room_id' => $data['room_id']]);
    echo json_encode(["success" => true, "message" => "Room deleted successfully."]);
}
?>
