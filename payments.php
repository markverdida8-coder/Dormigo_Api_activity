<?php
if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PATCH'], true)) {
    require __DIR__ . '/api/bookings.php';
    exit();
}

require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT b.*, u.full_name, u.email, u.phone, r.room_number, r.room_type, bh.house_name, bh.landlord_id
            FROM bookings b
            JOIN users u ON b.user_id = u.user_id
            JOIN rooms r ON b.room_id = r.room_id
            JOIN boarding_houses bh ON r.house_id = bh.house_id";
    $params = [];

    if (isset($_GET['booking_id'])) {
        $sql .= " WHERE b.booking_id = :booking_id";
        $params['booking_id'] = $_GET['booking_id'];
    } elseif (isset($_GET['landlord_id'])) {
        $sql .= " WHERE bh.landlord_id = :landlord_id";
        $params['landlord_id'] = $_GET['landlord_id'];
    } elseif (isset($_GET['house_id'])) {
        $sql .= " WHERE bh.house_id = :house_id";
        $params['house_id'] = $_GET['house_id'];
    }
    $sql .= " ORDER BY b.booking_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
}
?>