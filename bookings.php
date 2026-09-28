<?php
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

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("INSERT INTO bookings (user_id, room_id, move_in_date, duration_months, status, agreed_monthly_rent, agreed_total_amount, message_to_landlord)
                            VALUES (:user_id, :room_id, :move_in_date, :duration_months, :status::booking_status_enum, :agreed_monthly_rent, :agreed_total_amount, :message_to_landlord) RETURNING booking_id");
    $stmt->execute([
        'user_id' => $data['user_id'],
        'room_id' => $data['room_id'],
        'move_in_date' => $data['move_in_date'],
        'duration_months' => $data['duration_months'],
        'status' => strtoupper($data['status'] ?? 'PENDING'),
        'agreed_monthly_rent' => $data['agreed_monthly_rent'],
        'agreed_total_amount' => $data['agreed_total_amount'],
        'message_to_landlord' => $data['message_to_landlord'] ?? ''
    ]);
    echo json_encode(["success" => true, "message" => "Booking request submitted successfully.", "booking_id" => $stmt->fetchColumn()]);

} elseif ($method === 'PATCH') {

    $data = json_decode(file_get_contents("php://input"), true);

    $fields = [];
    $params = [];

    $params['booking_id'] = $data['booking_id'];

    if (isset($data['status'])) {
        $fields[] = "status = :status::booking_status_enum";
        $params['status'] = strtoupper($data['status']);
    }

    if (isset($data['initial_payment_completed'])) {
        $fields[] = "initial_payment_completed = :initial_payment_completed";
        $params['initial_payment_completed'] =
                $data['initial_payment_completed'];
    }

    if (isset($data['next_due_date'])) {
        $fields[] = "next_due_date = :next_due_date";
        $params['next_due_date'] =
                $data['next_due_date'];
    }

    if (empty($fields)) {

        echo json_encode([
            "success" => false,
            "message" => "Nothing to update."
        ]);

        exit;
    }

    $sql =
            "UPDATE bookings
             SET " . implode(", ", $fields) . "
             WHERE booking_id = :booking_id";

    $stmt = $pdo->prepare($sql);

    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Booking updated successfully."
    ]);
}