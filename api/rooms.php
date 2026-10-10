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
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);

    if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: Only landlords can update room details."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $roomId = (int)($data['room_id'] ?? 0);

    if ($roomId <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid room_id is required."]);
        exit();
    }

    // Retrieve current room details and property landlord_id before updating
    $chkStmt = $pdo->prepare("
        SELECT r.*, bh.house_name, bh.landlord_id
        FROM rooms r
        JOIN boarding_houses bh ON r.house_id = bh.house_id
        WHERE r.room_id = ?
    ");
    $chkStmt->execute([$roomId]);
    $oldRoom = $chkStmt->fetch();

    if (!$oldRoom) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Room not found."]);
        exit();
    }

    // Ownership check: boarding_houses.landlord_id === authenticated user_id
    if ((int)$oldRoom['landlord_id'] !== (int)$authUser['user_id']) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: You do not own the property associated with this room."]);
        exit();
    }

    if (isset($data['status']) && !isset($data['room_number']) && !isset($data['monthly_rent'])) {
        $stmt = $pdo->prepare("UPDATE rooms SET status = :status::room_status_enum WHERE room_id = :room_id");
        $stmt->execute([
            'room_id' => $roomId,
            'status' => strtoupper($data['status'])
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE rooms SET room_number = :room_number, room_type = :room_type, capacity = :capacity, monthly_rent = :monthly_rent, status = :status::room_status_enum, advance_months = :advance_months, deposit_months = :deposit_months, other_fees = :other_fees, other_fees_description = :other_fees_description, deposit_refund_policy = :deposit_refund_policy WHERE room_id = :room_id");
        $stmt->execute([
            'room_id' => $roomId,
            'room_number' => $data['room_number'] ?? $oldRoom['room_number'],
            'room_type' => $data['room_type'] ?? $oldRoom['room_type'],
            'capacity' => $data['capacity'] ?? $oldRoom['capacity'],
            'monthly_rent' => $data['monthly_rent'] ?? $oldRoom['monthly_rent'],
            'status' => strtoupper($data['status'] ?? $oldRoom['status']),
            'advance_months' => $data['advance_months'] ?? $oldRoom['advance_months'],
            'deposit_months' => $data['deposit_months'] ?? $oldRoom['deposit_months'],
            'other_fees' => $data['other_fees'] ?? $oldRoom['other_fees'],
            'other_fees_description' => $data['other_fees_description'] ?? $oldRoom['other_fees_description'],
            'deposit_refund_policy' => $data['deposit_refund_policy'] ?? $oldRoom['deposit_refund_policy']
        ]);

        // Detect financial charge changes
        $changes = [];
        $oldRent = (float)$oldRoom['monthly_rent'];
        $newRent = isset($data['monthly_rent']) ? (float)$data['monthly_rent'] : $oldRent;
        if (abs($oldRent - $newRent) > 0.01) {
            $changes[] = "Monthly Rent: ₱" . number_format($oldRent, 2, '.', ',') . " → ₱" . number_format($newRent, 2, '.', ',');
        }

        $oldOtherFees = (float)$oldRoom['other_fees'];
        $newOtherFees = isset($data['other_fees']) ? (float)$data['other_fees'] : $oldOtherFees;
        if (abs($oldOtherFees - $newOtherFees) > 0.01) {
            $changes[] = "Move-in Fees: ₱" . number_format($oldOtherFees, 2, '.', ',') . " → ₱" . number_format($newOtherFees, 2, '.', ',');
        }

        if (!empty($changes)) {
            $roomNum = !empty(trim($oldRoom['room_number'])) ? trim($oldRoom['room_number']) : trim($oldRoom['room_type']);
            $houseName = $oldRoom['house_name'];
            $summaryMsg = "Updated charges for " . $roomNum . " at " . $houseName . ":\n• " . implode("\n• ", $changes);

            // Deduplicate affected students by user_id
            $stuStmt = $pdo->prepare("
                SELECT b.user_id, MAX(b.booking_id) AS booking_id
                FROM bookings b
                WHERE b.room_id = ?
                  AND UPPER(b.status::text) IN ('PENDING', 'APPROVED')
                GROUP BY b.user_id
            ");
            $stuStmt->execute([$roomId]);
            $students = $stuStmt->fetchAll();

            $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Rental Price Updated', ?, 'BOOKING', ?)");
            foreach ($students as $stu) {
                $nStmt->execute([$stu['user_id'], $summaryMsg, $stu['booking_id']]);
            }
        }
    }
    echo json_encode(["success" => true, "message" => "Room updated successfully."]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = :room_id");
    $stmt->execute(['room_id' => $data['room_id']]);
    echo json_encode(["success" => true, "message" => "Room deleted successfully."]);
}
?>
