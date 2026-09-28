<?php
require_once "db.php";
$user = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetch();
$room = $pdo->query("SELECT r.room_id, r.house_id FROM rooms r JOIN boarding_houses bh ON r.house_id = bh.house_id LIMIT 1")->fetch();
if ($user && $room) {
    $userId = $user['user_id'];
    $roomId = $room['room_id'];
    $houseId = $room['house_id'];

    $pdo->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$userId]);
    $pdo->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$userId]);

    $stmt = $pdo->prepare("INSERT INTO bookings (user_id, room_id, move_in_date, duration_months, status, agreed_monthly_rent, agreed_total_amount) VALUES (?, ?, '2026-09-01', 3, 'COMPLETED'::booking_status_enum, 5000, 15000) RETURNING booking_id");
    $stmt->execute([$userId, $roomId]);
    echo json_encode(["success" => true, "user_id" => $userId, "house_id" => $houseId, "booking_id" => $stmt->fetchColumn()]);
} else {
    echo json_encode(["success" => false, "message" => "No users or rooms found"]);
}
?>
