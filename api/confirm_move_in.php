<?php
require_once 'db.php';
require_once 'auth_helper.php';
require_once 'payment_model.php';

header('Content-Type: application/json; charset=UTF-8');

function confirmMoveInRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function confirmMoveInRollback(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'POST') {
    header('Allow: POST, OPTIONS');
    confirmMoveInRespond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$authUser = authenticateUser($pdo);
if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
    confirmMoveInRespond(403, [
        'success' => false,
        'message' => 'Only landlords can confirm move-in.'
    ]);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    confirmMoveInRespond(400, [
        'success' => false,
        'message' => 'A valid JSON request body is required.'
    ]);
}

$bookingId = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT);
if ($bookingId === false || $bookingId === null || $bookingId <= 0) {
    confirmMoveInRespond(400, [
        'success' => false,
        'message' => 'A valid booking_id is required.'
    ]);
}

$landlordId = (int)$authUser['user_id'];

try {
    $pdo->beginTransaction();

    $bookingStmt = $pdo->prepare(
        'SELECT booking_id, room_id, status::text AS booking_status,
                move_in_date,
                (move_in_date + (duration_months * INTERVAL \'1 month\'))::date AS rental_end_date,
                (move_in_date <= CURRENT_DATE) AS move_in_reached,
                (
                    (move_in_date + (duration_months * INTERVAL \'1 month\'))::date > CURRENT_DATE
                ) AS rental_period_valid
         FROM bookings
         WHERE booking_id = :booking_id
         FOR UPDATE'
    );
    $bookingStmt->execute(['booking_id' => $bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }

    $roomStmt = $pdo->prepare(
        'SELECT r.room_id, r.capacity, r.status::text AS room_status,
                bh.landlord_id
         FROM rooms r
         JOIN boarding_houses bh ON bh.house_id = r.house_id
         WHERE r.room_id = :room_id
         FOR UPDATE OF r, bh'
    );
    $roomStmt->execute(['room_id' => $booking['room_id']]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }

    if ((int)$room['landlord_id'] !== $landlordId) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }

    if (strtoupper((string)$booking['booking_status']) !== 'APPROVED') {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'Only approved bookings can be activated.'
        ]);
    }

    if (
        !in_array($booking['move_in_reached'], [true, 't', '1', 1], true)
        || !in_array($booking['rental_period_valid'], [true, 't', '1', 1], true)
    ) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'The booking is outside its valid move-in period.'
        ]);
    }

    $roomStatus = strtoupper((string)$room['room_status']);
    if (!in_array($roomStatus, ['AVAILABLE', 'OCCUPIED'], true)) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'The room is not available for move-in.'
        ]);
    }

    $hasPaymentType = paymentTypeColumnExists($pdo);
    $initialPaymentTypeExpression = paymentTypeExpression($hasPaymentType);
    $paymentStmt = $pdo->prepare(
        "SELECT p.payment_id
         FROM payments p
         WHERE p.booking_id = :booking_id
           AND {$initialPaymentTypeExpression} = 'INITIAL'
           AND UPPER(p.status::text) IN ('PAID', 'CONFIRMED')
           AND p.verified_at IS NOT NULL
         ORDER BY p.verified_at
         LIMIT 1
         FOR UPDATE"
    );
    $paymentStmt->execute(['booking_id' => $bookingId]);
    if (!$paymentStmt->fetchColumn()) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'The initial payment has not been confirmed.'
        ]);
    }

    $capacityStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM bookings other_booking
         WHERE other_booking.room_id = :room_id
           AND other_booking.booking_id <> :booking_id
           AND UPPER(other_booking.status::text) IN ('APPROVED', 'ACTIVE')
           AND other_booking.move_in_date < CAST(:candidate_end AS date)
           AND (
               other_booking.move_in_date
               + (other_booking.duration_months * INTERVAL '1 month')
           )::date > CAST(:candidate_start AS date)"
    );
    $capacityStmt->execute([
        'room_id' => $booking['room_id'],
        'booking_id' => $bookingId,
        'candidate_end' => $booking['rental_end_date'],
        'candidate_start' => $booking['move_in_date']
    ]);
    $conflictingReservations = (int)$capacityStmt->fetchColumn();
    $capacity = (int)$room['capacity'];

    if ($capacity <= 0 || $conflictingReservations >= $capacity) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'The room has no remaining capacity for this booking period.'
        ]);
    }

    $activateStmt = $pdo->prepare(
        "UPDATE bookings
         SET status = 'ACTIVE'::booking_status_enum
         WHERE booking_id = :booking_id
           AND status::text = 'APPROVED'
         RETURNING booking_id"
    );
    $activateStmt->execute(['booking_id' => $bookingId]);
    if (!$activateStmt->fetchColumn()) {
        confirmMoveInRollback($pdo);
        confirmMoveInRespond(409, [
            'success' => false,
            'message' => 'The booking is no longer approved for move-in.'
        ]);
    }

    $activeOccupancyStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM bookings
         WHERE room_id = :room_id
           AND UPPER(status::text) = 'ACTIVE'
           AND move_in_date <= CURRENT_DATE
           AND (
               move_in_date + (duration_months * INTERVAL '1 month')
           )::date > CURRENT_DATE"
    );
    $activeOccupancyStmt->execute(['room_id' => $booking['room_id']]);
    $activeOccupancy = (int)$activeOccupancyStmt->fetchColumn();
    $updatedRoomStatus = $activeOccupancy >= $capacity ? 'OCCUPIED' : 'AVAILABLE';

    $updateRoomStmt = $pdo->prepare(
        'UPDATE rooms
         SET status = CAST(:room_status AS room_status_enum)
         WHERE room_id = :room_id'
    );
    $updateRoomStmt->execute([
        'room_status' => $updatedRoomStatus,
        'room_id' => $booking['room_id']
    ]);

    $pdo->commit();
    confirmMoveInRespond(200, [
        'success' => true,
        'message' => 'Move-in confirmed successfully.',
        'data' => [
            'booking_id' => (int)$bookingId,
            'status' => 'ACTIVE',
            'room_status' => $updatedRoomStatus
        ]
    ]);
} catch (Throwable $e) {
    confirmMoveInRollback($pdo);
    error_log('Confirm move-in failed: ' . get_class($e));
    confirmMoveInRespond(500, [
        'success' => false,
        'message' => 'Unable to confirm move-in. Please try again later.'
    ]);
}
?>
