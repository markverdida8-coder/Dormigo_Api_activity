<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "user_id is required."]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT bh.house_id, bh.house_name, bh.address
        FROM bookings b
        JOIN rooms r ON b.room_id = r.room_id
        JOIN boarding_houses bh ON r.house_id = bh.house_id
        WHERE b.user_id = :user_id
          AND UPPER(b.status::text) = 'COMPLETED'
          AND bh.house_id NOT IN (
              SELECT house_id FROM reviews WHERE user_id = :user_id2
          )
    ");
    $stmt->execute(['user_id' => $user_id, 'user_id2' => $user_id]);
    $houses = $stmt->fetchAll();

    echo json_encode(["success" => true, "data" => $houses]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
