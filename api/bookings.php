<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT b.booking_id, b.user_id, b.room_id, b.move_in_date, b.duration_months, b.status, b.agreed_monthly_rent, b.agreed_total_amount, b.message_to_landlord, b.created_at, b.next_due_date, (b.initial_payment_completed OR EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.booking_id AND p.status IN ('PAID', 'CONFIRMED'))) AS initial_payment_completed, u.full_name, u.email, u.phone, r.room_number, r.room_type, r.advance_months, r.deposit_months, r.utility_deposit, r.other_fees, r.other_fees_description, bh.house_id, bh.house_name, bh.landlord_id, bh.payment_due_day, bh.cash_enabled, bh.gcash_qr_code, bh.gcash_updated_at, COALESCE((SELECT SUM(amount) FROM property_additional_fees WHERE house_id = bh.house_id AND fee_type = 'MONTHLY'), 0) AS monthly_recurring_fees, COALESCE((SELECT SUM(fixed_amount) FROM property_utilities WHERE house_id = bh.house_id AND charging_method = 'FIXED'), 0) AS utilities_fixed
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

    if (!isset($data['user_id'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "user_id is required."]);
        exit();
    }

    try {
        $chk = $pdo->prepare("SELECT booking_id FROM bookings WHERE user_id = :user_id AND status IN ('PENDING', 'APPROVED') LIMIT 1");
        $chk->execute(['user_id' => $data['user_id']]);
        if ($chk->fetch()) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "You already have an active booking. Complete or cancel your current booking before booking another boarding house."
            ]);
            exit();
        }

        $stmt = $pdo->prepare("INSERT INTO bookings (user_id, room_id, move_in_date, duration_months, status, agreed_monthly_rent, agreed_total_amount, message_to_landlord, initial_payment_completed)
                                VALUES (:user_id, :room_id, :move_in_date, :duration_months, :status::booking_status_enum, :agreed_monthly_rent, :agreed_total_amount, :message_to_landlord, false) RETURNING booking_id");
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
        $bookingId = $stmt->fetchColumn();
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' || strpos($e->getMessage(), 'idx_unique_active_student_booking') !== false) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "You already have an active booking. Complete or cancel your current booking before booking another boarding house."
            ]);
            exit();
        }
        throw $e;
    }

    // Get info for notification
    $info = $pdo->prepare("SELECT u.full_name, bh.landlord_id, r.room_number, r.room_type FROM rooms r JOIN boarding_houses bh ON r.house_id = bh.house_id JOIN users u ON u.user_id = :uid WHERE r.room_id = :rid");
    $info->execute(['uid' => $data['user_id'], 'rid' => $data['room_id']]);
    $infoData = $info->fetch();
    if ($infoData && $infoData['landlord_id']) {
        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, 'BOOKING', ?)");
        $rn = !empty(trim($infoData['room_number'])) ? trim($infoData['room_number']) : trim($infoData['room_type']);
        $nStmt->execute([$infoData['landlord_id'], 'New Booking Request', $infoData['full_name'] . ' requested ' . $rn . '.', $bookingId]);
    }

    echo json_encode(["success" => true, "message" => "Booking request submitted successfully.", "booking_id" => $bookingId]);

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['booking_id'])) {
        echo json_encode(["success" => false, "message" => "Booking ID is required."]);
        exit();
    }
    $fields = [];
    $params = ['booking_id' => $data['booking_id']];

    if (isset($data['status'])) {
        $fields[] = "status = :status::booking_status_enum";
        $params['status'] = strtoupper($data['status']);
    }
    if (isset($data['initial_payment_completed'])) {
        $fields[] = "initial_payment_completed = :initial_payment_completed";
        $params['initial_payment_completed'] = filter_var($data['initial_payment_completed'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (isset($data['next_due_date'])) {
        $fields[] = "next_due_date = :next_due_date";
        $params['next_due_date'] = $data['next_due_date'];
    }

    if (!empty($fields)) {
        $sql = "UPDATE bookings SET " . implode(", ", $fields) . " WHERE booking_id = :booking_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        if (isset($data['status'])) {
            $status = strtoupper($data['status']);
            if ($status === 'APPROVED' || $status === 'REJECTED' || $status === 'DECLINED') {
                $info = $pdo->prepare("SELECT b.user_id, bh.house_name FROM bookings b JOIN rooms r ON b.room_id = r.room_id JOIN boarding_houses bh ON r.house_id = bh.house_id WHERE b.booking_id = ?");
                $info->execute([$data['booking_id']]);
                $infoData = $info->fetch();
                if ($infoData) {
                    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, 'BOOKING', ?)");
                    if ($status === 'APPROVED') {
                        $nStmt->execute([$infoData['user_id'], 'Booking Approved', 'Your booking for ' . $infoData['house_name'] . ' has been approved.', $data['booking_id']]);
                    } else {
                        $nStmt->execute([$infoData['user_id'], 'Booking Declined', 'Your booking request for ' . $infoData['house_name'] . ' has been declined.', $data['booking_id']]);
                    }
                }
            }
        }
    }
    echo json_encode(["success" => true, "message" => "Booking updated successfully."]);
}
?>
