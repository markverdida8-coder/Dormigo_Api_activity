<?php
require_once 'db.php';
require_once 'auth_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$authUser = authenticateUser($pdo);

if (strtoupper($authUser['user_type'] ?? '') !== 'STUDENT') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden: Only students can view eligible review properties."]);
    exit();
}

$user_id = (int)$authUser['user_id'];

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
