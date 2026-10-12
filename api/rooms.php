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
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);
    if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only landlords can create rooms."]);
        exit();
    }
    $data = json_decode(file_get_contents("php://input"), true);
    $houseId = filter_var(is_array($data) ? ($data['house_id'] ?? null) : null, FILTER_VALIDATE_INT);
    if ($houseId === false || $houseId === null || $houseId <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid house_id is required."]);
        exit();
    }
    $pdo->beginTransaction();
    $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
    $advisory->execute(['house_id' => (int)$houseId]);
    $houseStmt = $pdo->prepare('SELECT landlord_id FROM boarding_houses WHERE house_id = :house_id FOR UPDATE');
    $houseStmt->execute(['house_id' => (int)$houseId]);
    $house = $houseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$house || (int)$house['landlord_id'] !== (int)$authUser['user_id']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You do not own this property."]);
        exit();
    }
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
    $pdo->commit();
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
    $roomId = filter_var(is_array($data) ? ($data['room_id'] ?? null) : null, FILTER_VALIDATE_INT);

    if ($roomId === false || $roomId === null || $roomId <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid room_id is required."]);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $houseLookup = $pdo->prepare('SELECT house_id FROM rooms WHERE room_id = :room_id');
        $houseLookup->execute(['room_id' => $roomId]);
        $houseId = $houseLookup->fetchColumn();
        if (!$houseId) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Room not found."]);
            exit();
        }
        $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
        $advisory->execute(['house_id' => (int)$houseId]);
        $houseStmt = $pdo->prepare('SELECT house_name, landlord_id FROM boarding_houses WHERE house_id = :house_id FOR UPDATE');
        $houseStmt->execute(['house_id' => (int)$houseId]);
        $lockedHouse = $houseStmt->fetch(PDO::FETCH_ASSOC);
        $chkStmt = $pdo->prepare("
            SELECT r.*
            FROM rooms r
            WHERE r.room_id = :room_id
            FOR UPDATE
        ");
        $chkStmt->execute(['room_id' => $roomId]);
        $oldRoom = $chkStmt->fetch();
        if ($oldRoom && $lockedHouse) {
            $oldRoom['house_name'] = $lockedHouse['house_name'];
            $oldRoom['landlord_id'] = $lockedHouse['landlord_id'];
        }

        if (!$oldRoom || !$lockedHouse) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Room not found."]);
            exit();
        }
        if ((int)$oldRoom['landlord_id'] !== (int)$authUser['user_id']) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "You do not own the property associated with this room."]);
            exit();
        }

        $occupancyStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM bookings
            WHERE room_id = :room_id
              AND UPPER(status::text) = 'ACTIVE'
              AND move_in_date <= CURRENT_DATE
              AND (move_in_date + (duration_months * INTERVAL '1 month'))::date > CURRENT_DATE
        ");
        $occupancyStmt->execute(['room_id' => $roomId]);
        $activeOccupancy = (int)$occupancyStmt->fetchColumn();

        $statusOnlyUpdate = isset($data['status'])
            && !isset($data['room_number'])
            && !isset($data['monthly_rent'])
            && !isset($data['capacity']);
        if ($statusOnlyUpdate) {
            $roomStatus = strtoupper(trim((string)$data['status']));
            if (!in_array($roomStatus, ['AVAILABLE', 'OCCUPIED', 'MAINTENANCE', 'INACTIVE'], true)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Unsupported room status."]);
                exit();
            }
            if (in_array($roomStatus, ['MAINTENANCE', 'INACTIVE'], true) && $activeOccupancy > 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "A room with active tenants cannot be taken out of service."]);
                exit();
            }
            if (in_array($roomStatus, ['AVAILABLE', 'OCCUPIED'], true)) {
                $roomStatus = $activeOccupancy >= (int)$oldRoom['capacity']
                    ? 'OCCUPIED'
                    : 'AVAILABLE';
            }

            $stmt = $pdo->prepare("UPDATE rooms SET status = CAST(:status AS room_status_enum) WHERE room_id = :room_id");
            $stmt->execute([
                'room_id' => $roomId,
                'status' => $roomStatus
            ]);
        } else {
            $capacity = filter_var($data['capacity'] ?? $oldRoom['capacity'], FILTER_VALIDATE_INT);
            if ($capacity === false || $capacity === null || $capacity <= 0 || $capacity < $activeOccupancy) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "Room capacity cannot be lower than active occupancy."]);
                exit();
            }

            $roomStatus = strtoupper((string)($data['status'] ?? $oldRoom['status']));
            if (!in_array($roomStatus, ['AVAILABLE', 'OCCUPIED', 'MAINTENANCE', 'INACTIVE'], true)) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Unsupported room status."]);
                exit();
            }
            if (in_array($roomStatus, ['MAINTENANCE', 'INACTIVE'], true) && $activeOccupancy > 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "A room with active tenants cannot be taken out of service."]);
                exit();
            }
            if (in_array($roomStatus, ['AVAILABLE', 'OCCUPIED'], true)) {
                $roomStatus = $activeOccupancy >= $capacity ? 'OCCUPIED' : 'AVAILABLE';
            }

            $reservationCapacityStmt = $pdo->prepare("
                SELECT EXISTS (
                    SELECT 1
                    FROM bookings candidate
                    WHERE candidate.room_id = :room_id
                      AND UPPER(candidate.status::text) IN ('APPROVED', 'ACTIVE')
                      AND (
                          SELECT COUNT(*)
                          FROM bookings overlapping
                          WHERE overlapping.room_id = candidate.room_id
                            AND UPPER(overlapping.status::text) IN ('APPROVED', 'ACTIVE')
                            AND overlapping.move_in_date
                                < (candidate.move_in_date + (candidate.duration_months * INTERVAL '1 month'))::date
                            AND (overlapping.move_in_date
                                + (overlapping.duration_months * INTERVAL '1 month'))::date
                                > candidate.move_in_date
                      ) > :capacity
                )
            ");
            $reservationCapacityStmt->execute([
                'room_id' => $roomId,
                'capacity' => $capacity
            ]);
            if (in_array($reservationCapacityStmt->fetchColumn(), [true, 't', '1', 1], true)) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(["success" => false, "message" => "Capacity cannot be reduced below overlapping reservations."]);
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE rooms
                SET room_number = :room_number,
                    room_type = :room_type,
                    capacity = :capacity,
                    monthly_rent = :monthly_rent,
                    status = CAST(:status AS room_status_enum),
                    advance_months = :advance_months,
                    deposit_months = :deposit_months,
                    other_fees = :other_fees,
                    other_fees_description = :other_fees_description,
                    deposit_refund_policy = :deposit_refund_policy
                WHERE room_id = :room_id
            ");
            $stmt->execute([
                'room_id' => $roomId,
                'room_number' => $data['room_number'] ?? $oldRoom['room_number'],
                'room_type' => $data['room_type'] ?? $oldRoom['room_type'],
                'capacity' => $capacity,
                'monthly_rent' => $data['monthly_rent'] ?? $oldRoom['monthly_rent'],
                'status' => $roomStatus,
                'advance_months' => $data['advance_months'] ?? $oldRoom['advance_months'],
                'deposit_months' => $data['deposit_months'] ?? $oldRoom['deposit_months'],
                'other_fees' => $data['other_fees'] ?? $oldRoom['other_fees'],
                'other_fees_description' => $data['other_fees_description'] ?? $oldRoom['other_fees_description'],
                'deposit_refund_policy' => $data['deposit_refund_policy'] ?? $oldRoom['deposit_refund_policy']
            ]);

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
                $roomNum = trim((string)$oldRoom['room_number']) !== ''
                    ? trim((string)$oldRoom['room_number'])
                    : trim((string)$oldRoom['room_type']);
                $summaryMsg = "Updated charges for " . $roomNum . " at " . $oldRoom['house_name'] . ":\n• " . implode("\n• ", $changes);

                $stuStmt = $pdo->prepare("
                    SELECT b.user_id, MAX(b.booking_id) AS booking_id
                    FROM bookings b
                    WHERE b.room_id = :room_id
                      AND UPPER(b.status::text) IN ('PENDING', 'APPROVED')
                    GROUP BY b.user_id
                ");
                $stuStmt->execute(['room_id' => $roomId]);
                $students = $stuStmt->fetchAll();

                $nStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, reference_id)
                    VALUES (:user_id, 'Rental Price Updated', :message, 'BOOKING', :booking_id)
                ");
                foreach ($students as $student) {
                    $nStmt->execute([
                        'user_id' => $student['user_id'],
                        'message' => $summaryMsg,
                        'booking_id' => $student['booking_id']
                    ]);
                }
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Room updated successfully."]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Room update failed: ' . get_class($e));
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Unable to update the room. Please try again later."]);
    }

} elseif ($method === 'DELETE') {
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);
    if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Only landlords can delete rooms."]);
        exit();
    }
    $data = json_decode(file_get_contents("php://input"), true);
    $roomId = filter_var(is_array($data) ? ($data['room_id'] ?? null) : null, FILTER_VALIDATE_INT);
    if ($roomId === false || $roomId === null || $roomId <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Valid room_id is required."]);
        exit();
    }
    $pdo->beginTransaction();
    $houseLookup = $pdo->prepare('SELECT house_id FROM rooms WHERE room_id = :room_id');
    $houseLookup->execute(['room_id' => (int)$roomId]);
    $houseId = $houseLookup->fetchColumn();
    if (!$houseId) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Room not found."]);
        exit();
    }
    $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
    $advisory->execute(['house_id' => (int)$houseId]);
    $houseStmt = $pdo->prepare('SELECT landlord_id FROM boarding_houses WHERE house_id = :house_id FOR UPDATE');
    $houseStmt->execute(['house_id' => (int)$houseId]);
    $house = $houseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$house || (int)$house['landlord_id'] !== (int)$authUser['user_id']) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "You do not own this property."]);
        exit();
    }
    $roomLock = $pdo->prepare('SELECT room_id FROM rooms WHERE room_id = :room_id FOR UPDATE');
    $roomLock->execute(['room_id' => (int)$roomId]);
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE room_id = :room_id");
    $stmt->execute(['room_id' => (int)$roomId]);
    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Room deleted successfully."]);
}
?>
