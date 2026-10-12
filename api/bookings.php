<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/payment_model.php';

header('Content-Type: application/json; charset=UTF-8');

function bookingRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function bookingRollback(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function bookingRecalculateRoomStatus(PDO $pdo, int $roomId, int $capacity): string
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM bookings
         WHERE room_id = :room_id
           AND UPPER(status::text) = 'ACTIVE'
           AND move_in_date <= CURRENT_DATE
           AND (move_in_date + (duration_months * INTERVAL '1 month'))::date > CURRENT_DATE"
    );
    $stmt->execute(['room_id' => $roomId]);
    $activeOccupancy = (int)$stmt->fetchColumn();
    $roomStatus = $activeOccupancy >= $capacity ? 'OCCUPIED' : 'AVAILABLE';

    $update = $pdo->prepare(
        'UPDATE rooms SET status = CAST(:status AS room_status_enum) WHERE room_id = :room_id'
    );
    $update->execute([
        'status' => $roomStatus,
        'room_id' => $roomId
    ]);

    return $roomStatus;
}

function bookingCountOverlappingReservations(
    PDO $pdo,
    int $roomId,
    int $bookingId,
    string $candidateStart,
    string $candidateEnd
): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM bookings other_booking
         WHERE other_booking.room_id = :room_id
           AND other_booking.booking_id <> :booking_id
           AND UPPER(other_booking.status::text) IN ('APPROVED', 'ACTIVE')
           AND other_booking.move_in_date < CAST(:candidate_end AS date)
           AND (other_booking.move_in_date
                + (other_booking.duration_months * INTERVAL '1 month'))::date
                > CAST(:candidate_start AS date)"
    );
    $stmt->execute([
        'room_id' => $roomId,
        'booking_id' => $bookingId,
        'candidate_end' => $candidateEnd,
        'candidate_start' => $candidateStart
    ]);

    return (int)$stmt->fetchColumn();
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH, OPTIONS');
    bookingRespond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$authUser = authenticateUser($pdo);
$userId = (int)$authUser['user_id'];
$userType = strtoupper((string)$authUser['user_type']);

if ($method === 'GET') {
    if (!in_array($userType, ['STUDENT', 'LANDLORD', 'ADMIN'], true)) {
        bookingRespond(403, [
            'success' => false,
            'message' => 'You are not authorized to view bookings.'
        ]);
    }

    try {
        $hasPaymentType = paymentTypeColumnExists($pdo);
        $initialPaymentTypeExpression = paymentTypeExpression($hasPaymentType);
        $where = [];
        $params = [];
        if ($userType === 'STUDENT') {
            $where[] = 'b.user_id = :authenticated_user_id';
            $params['authenticated_user_id'] = $userId;
        } elseif ($userType === 'LANDLORD') {
            $where[] = 'bh.landlord_id = :authenticated_user_id';
            $params['authenticated_user_id'] = $userId;
        }
        if (isset($_GET['booking_id'])) {
            $where[] = 'b.booking_id = :booking_id';
            $params['booking_id'] = $_GET['booking_id'];
        }
        if (isset($_GET['house_id'])) {
            $where[] = 'bh.house_id = :house_id';
            $params['house_id'] = $_GET['house_id'];
        }

        $sql = "SELECT b.booking_id, b.user_id, b.room_id, b.move_in_date,
                       b.duration_months, b.status, b.agreed_monthly_rent,
                       b.agreed_total_amount, b.message_to_landlord, b.created_at,
                       b.next_due_date,
                       EXISTS (
                            SELECT 1
                            FROM payments p
                            WHERE p.booking_id = b.booking_id
                              AND {$initialPaymentTypeExpression} = 'INITIAL'
                              AND UPPER(p.status::text) IN ('PAID', 'CONFIRMED')
                              AND p.verified_at IS NOT NULL
                        ) AS initial_payment_completed,
                       u.full_name, u.email, u.phone, r.room_number, r.room_type,
                       r.advance_months, r.deposit_months, r.utility_deposit,
                       r.other_fees, r.other_fees_description, bh.house_id,
                       bh.house_name, bh.landlord_id, bh.payment_due_day,
                       bh.cash_enabled, bh.gcash_qr_code, bh.gcash_updated_at,
                       COALESCE((
                           SELECT SUM(amount)
                           FROM property_additional_fees
                           WHERE house_id = bh.house_id AND fee_type = 'MONTHLY'
                       ), 0) AS monthly_recurring_fees,
                       COALESCE((
                           SELECT SUM(fixed_amount)
                           FROM property_utilities
                           WHERE house_id = bh.house_id AND charging_method = 'FIXED'
                       ), 0) AS utilities_fixed
                FROM bookings b
                JOIN users u ON b.user_id = u.user_id
                JOIN rooms r ON b.room_id = r.room_id
                JOIN boarding_houses bh ON r.house_id = bh.house_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY b.booking_id DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $snapshotAvailable = paymentSnapshotSchemaReady($pdo);
        foreach ($bookings as &$bookingRecord) {
            $bookingRecord['pricing_snapshot_status'] = $snapshotAvailable
                ? 'REVIEW_REQUIRED'
                : 'SCHEMA_UNAVAILABLE';
            $bookingRecord['initial_payment_amount'] = null;
            $bookingRecord['monthly_payment_amount'] = null;
            $bookingRecord['payment_due_day'] = null;
            if (!$snapshotAvailable) {
                continue;
            }
            try {
                $pricing = paymentPricingSnapshot($pdo, (int)$bookingRecord['booking_id']);
                $bookingRecord['pricing_snapshot_status'] = 'CAPTURED_VERIFIED';
                $bookingRecord['payment_due_day'] = (int)$pricing['payment_due_day'];
                $bookingRecord['initial_payment_amount'] = paymentInitialAmount($pricing);
                $bookingRecord['monthly_payment_amount'] = paymentMonthlyAmount($pricing);
            } catch (RuntimeException $e) {
                // A non-verified snapshot is intentionally not replaced with mutable room prices.
            }
        }
        unset($bookingRecord);
        bookingRespond(200, [
            'success' => true,
            'data' => $bookings
        ]);
    } catch (Throwable $e) {
        error_log('Booking list request failed: ' . get_class($e));
        bookingRespond(500, [
            'success' => false,
            'message' => 'Unable to load bookings. Please try again later.'
        ]);
    }
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'A valid JSON request body is required.'
    ]);
}

$bookingId = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT);
if ($method === 'PATCH' && ($bookingId === false || $bookingId === null || $bookingId <= 0)) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'A valid booking_id is required.'
    ]);
}

if ($method === 'POST') {
    if ($userType !== 'STUDENT') {
        bookingRespond(403, [
            'success' => false,
            'message' => 'Only students can create bookings.'
        ]);
    }

    $roomId = filter_var($data['room_id'] ?? null, FILTER_VALIDATE_INT);
    $durationMonths = filter_var($data['duration_months'] ?? null, FILTER_VALIDATE_INT);
    $moveInDate = $data['move_in_date'] ?? null;
    $monthlyRent = $data['agreed_monthly_rent'] ?? null;
    $totalAmount = $data['agreed_total_amount'] ?? null;
    if (
        $roomId === false || $roomId === null || $roomId <= 0
        || $durationMonths === false || $durationMonths === null || $durationMonths <= 0
        || $durationMonths > 120
        || !is_string($moveInDate)
        || !is_numeric($monthlyRent) || (float)$monthlyRent <= 0
        || !is_numeric($totalAmount) || (float)$totalAmount <= 0
    ) {
        bookingRespond(400, [
            'success' => false,
            'message' => 'Valid room, move-in date, rental duration, and rent values are required.'
        ]);
    }

    $parsedMoveInDate = DateTimeImmutable::createFromFormat('!Y-m-d', $moveInDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$parsedMoveInDate
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $parsedMoveInDate->format('Y-m-d') !== $moveInDate
    ) {
        bookingRespond(400, [
            'success' => false,
            'message' => 'move_in_date must use YYYY-MM-DD format.'
        ]);
    }

    try {
        $pdo->beginTransaction();

        $roomStmt = $pdo->prepare(
            'SELECT r.room_id, r.capacity, r.status::text AS room_status,
                    r.monthly_rent, bh.status::text AS house_status
             FROM rooms r
             JOIN boarding_houses bh ON bh.house_id = r.house_id
             WHERE r.room_id = :room_id
             FOR UPDATE OF r, bh'
        );
        $roomStmt->execute(['room_id' => $roomId]);
        $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
        if (!$room) {
            bookingRollback($pdo);
            bookingRespond(404, [
                'success' => false,
                'message' => 'Room not found.'
            ]);
        }
        if (
            strtoupper((string)$room['house_status']) !== 'ACTIVE'
            || !in_array(strtoupper((string)$room['room_status']), ['AVAILABLE', 'OCCUPIED'], true)
        ) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'This room is not currently available for booking.'
            ]);
        }

        $existingBookingStmt = $pdo->prepare(
            "SELECT booking_id
             FROM bookings
             WHERE user_id = :user_id
               AND UPPER(status::text) IN ('PENDING', 'APPROVED', 'ACTIVE')
             LIMIT 1
             FOR UPDATE"
        );
        $existingBookingStmt->execute(['user_id' => $userId]);
        if ($existingBookingStmt->fetchColumn()) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'You already have a pending, approved, or active booking.'
            ]);
        }

        $endDateStmt = $pdo->prepare(
            "SELECT (CAST(:move_in_date AS date)
                     + (CAST(:duration_months AS integer) * INTERVAL '1 month'))::date"
        );
        $endDateStmt->execute([
            'move_in_date' => $moveInDate,
            'duration_months' => $durationMonths
        ]);
        $rentalEndDate = (string)$endDateStmt->fetchColumn();
        $conflicts = bookingCountOverlappingReservations(
            $pdo,
            (int)$room['room_id'],
            0,
            $moveInDate,
            $rentalEndDate
        );
        if ((int)$room['capacity'] <= 0 || $conflicts >= (int)$room['capacity']) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'The room has no remaining capacity for the requested rental period.'
            ]);
        }

        $insert = $pdo->prepare(
            "INSERT INTO bookings
                (user_id, room_id, move_in_date, duration_months, status,
                 agreed_monthly_rent, agreed_total_amount, message_to_landlord,
                 initial_payment_completed)
             VALUES
                (:user_id, :room_id, :move_in_date, :duration_months,
                 'PENDING'::booking_status_enum, :monthly_rent, :total_amount,
                 :message, FALSE)
             RETURNING booking_id"
        );
        $insert->execute([
            'user_id' => $userId,
            'room_id' => $roomId,
            'move_in_date' => $moveInDate,
            'duration_months' => $durationMonths,
            'monthly_rent' => $monthlyRent,
            'total_amount' => $totalAmount,
            'message' => trim((string)($data['message_to_landlord'] ?? ''))
        ]);
        $newBookingId = (int)$insert->fetchColumn();

        $infoStmt = $pdo->prepare(
            'SELECT u.full_name, bh.landlord_id, r.room_number, r.room_type
             FROM rooms r
             JOIN boarding_houses bh ON bh.house_id = r.house_id
             JOIN users u ON u.user_id = :user_id
             WHERE r.room_id = :room_id'
        );
        $infoStmt->execute([
            'user_id' => $userId,
            'room_id' => $roomId
        ]);
        $info = $infoStmt->fetch(PDO::FETCH_ASSOC);
        if ($info && $info['landlord_id']) {
            $roomLabel = trim((string)$info['room_number']) !== ''
                ? trim((string)$info['room_number'])
                : trim((string)$info['room_type']);
            $notification = $pdo->prepare(
                "INSERT INTO notifications (user_id, title, message, type, reference_id)
                 VALUES (:landlord_id, 'New Booking Request', :message, 'BOOKING', :booking_id)"
            );
            $notification->execute([
                'landlord_id' => $info['landlord_id'],
                'message' => $info['full_name'] . ' requested ' . $roomLabel . '.',
                'booking_id' => $newBookingId
            ]);
        }

        $pdo->commit();
        bookingRespond(201, [
            'success' => true,
            'message' => 'Booking request submitted successfully.',
            'booking_id' => $newBookingId
        ]);
    } catch (Throwable $e) {
        bookingRollback($pdo);
        if ($e instanceof PDOException && $e->getCode() === '23505') {
            bookingRespond(409, [
                'success' => false,
                'message' => 'You already have a pending, approved, or active booking.'
            ]);
        }
        error_log('Booking creation failed: ' . get_class($e));
        bookingRespond(500, [
            'success' => false,
            'message' => 'Unable to submit the booking request. Please try again later.'
        ]);
    }
}

if (array_key_exists('initial_payment_completed', $data)) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'Initial payment status can only be updated by payment verification.'
    ]);
}
foreach (array_keys($data) as $key) {
    if (!in_array($key, ['booking_id', 'status', 'next_due_date'], true)) {
        bookingRespond(400, [
            'success' => false,
            'message' => 'Unsupported booking update field.'
        ]);
    }
}

$requestedStatus = isset($data['status']) ? strtoupper(trim((string)$data['status'])) : null;
$nextDueDate = $data['next_due_date'] ?? null;
if ($requestedStatus === 'ACTIVE') {
    bookingRespond(400, [
        'success' => false,
        'message' => 'Use the Confirm Move-in endpoint to activate an approved booking.'
    ]);
}
if ($requestedStatus !== null && $nextDueDate !== null) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'Status and next_due_date cannot be updated together.'
    ]);
}
if ($requestedStatus === null && $nextDueDate === null) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'A supported booking update is required.'
    ]);
}
if ($nextDueDate !== null) {
    if (!is_string($nextDueDate)) {
        bookingRespond(400, [
            'success' => false,
            'message' => 'next_due_date must use YYYY-MM-DD format.'
        ]);
    }
    $parsedDueDate = DateTimeImmutable::createFromFormat('!Y-m-d', $nextDueDate);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        !$parsedDueDate
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $parsedDueDate->format('Y-m-d') !== $nextDueDate
    ) {
        bookingRespond(400, [
            'success' => false,
            'message' => 'next_due_date must use YYYY-MM-DD format.'
        ]);
    }
}
if ($requestedStatus !== null && !in_array($requestedStatus, ['APPROVED', 'DECLINED', 'REJECTED', 'COMPLETED'], true)) {
    bookingRespond(400, [
        'success' => false,
        'message' => 'Unsupported booking status transition.'
    ]);
}

try {
    if ($requestedStatus === 'APPROVED' && !paymentSnapshotSchemaReady($pdo)) {
        bookingRespond(409, [
            'success' => false,
            'message' => 'Booking approval is unavailable until the pricing snapshot migration is installed.'
        ]);
    }
    $pdo->beginTransaction();
    $houseLookup = $pdo->prepare(
        'SELECT r.house_id
         FROM bookings b
         JOIN rooms r ON r.room_id = b.room_id
         WHERE b.booking_id = :booking_id'
    );
    $houseLookup->execute(['booking_id' => $bookingId]);
    $houseId = $houseLookup->fetchColumn();
    if (!$houseId) {
        bookingRollback($pdo);
        bookingRespond(404, ['success' => false, 'message' => 'Booking not found.']);
    }
    $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
    $advisory->execute(['house_id' => (int)$houseId]);

    $bookingStmt = $pdo->prepare(
        'SELECT booking_id, user_id, room_id, status::text AS booking_status,
                move_in_date,
                (move_in_date + (duration_months * INTERVAL \'1 month\'))::date AS rental_end_date
         FROM bookings
         WHERE booking_id = :booking_id
         FOR UPDATE'
    );
    $bookingStmt->execute(['booking_id' => $bookingId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        bookingRollback($pdo);
        bookingRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }

    if ($nextDueDate !== null) {
        if (
            $userType !== 'STUDENT'
            || (int)$booking['user_id'] !== $userId
        ) {
            bookingRollback($pdo);
            bookingRespond(403, [
                'success' => false,
                'message' => 'You are not authorized to update this booking.'
            ]);
        }
        $hasPaymentType = paymentTypeColumnExists($pdo);
        $latestConfirmedMonthlyPeriod = paymentLatestConfirmedMonthlyPeriod(
            $pdo,
            (int)$bookingId,
            $hasPaymentType
        );
        if ($latestConfirmedMonthlyPeriod <= 0) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'The next due date can only advance after a monthly payment is confirmed.'
            ]);
        }
        $pricingSnapshot = paymentPricingSnapshot($pdo, (int)$bookingId);
        $nextPeriod = $latestConfirmedMonthlyPeriod + 1;
        if ($nextPeriod > (int)$pricingSnapshot['agreed_duration_months']) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'The final rental installment is verified; no further due date is scheduled.'
            ]);
        }
        $expectedDueDate = paymentDueDateForPeriod(
            (string)$pricingSnapshot['agreed_move_in_date'],
            $nextPeriod,
            (int)$pricingSnapshot['payment_due_day']
        );
        if ($nextDueDate !== $expectedDueDate) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'The due date must match the next verified rental installment.'
            ]);
        }
        $dueDateStmt = $pdo->prepare(
            "UPDATE bookings
             SET next_due_date = CAST(:next_due_date AS date)
             WHERE booking_id = :booking_id
               AND user_id = :user_id
               AND COALESCE(initial_payment_completed, FALSE) = TRUE
               AND UPPER(status::text) IN ('APPROVED', 'ACTIVE')
             RETURNING booking_id"
        );
        $dueDateStmt->execute([
            'next_due_date' => $nextDueDate,
            'booking_id' => $bookingId,
            'user_id' => $userId
        ]);
        if (!$dueDateStmt->fetchColumn()) {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'The due date cannot be updated for this booking.'
            ]);
        }
        $pdo->commit();
        bookingRespond(200, [
            'success' => true,
            'message' => 'Booking updated successfully.'
        ]);
    }

    if ($userType !== 'LANDLORD') {
        bookingRollback($pdo);
        bookingRespond(403, [
            'success' => false,
            'message' => 'Only the property owner can update booking status.'
        ]);
    }

    $houseStmt = $pdo->prepare(
        'SELECT house_id, landlord_id, house_name
         FROM boarding_houses
         WHERE house_id = :house_id
         FOR UPDATE'
    );
    $houseStmt->execute(['house_id' => (int)$houseId]);
    $house = $houseStmt->fetch(PDO::FETCH_ASSOC);
    $roomStmt = $pdo->prepare(
        'SELECT room_id, capacity, status::text AS room_status, other_fees
         FROM rooms
         WHERE room_id = :room_id
         FOR UPDATE'
    );
    $roomStmt->execute(['room_id' => $booking['room_id']]);
    $room = $roomStmt->fetch(PDO::FETCH_ASSOC);
    if (!$room || !$house) {
        bookingRollback($pdo);
        bookingRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }
    if ((int)$house['landlord_id'] !== $userId) {
        bookingRollback($pdo);
        bookingRespond(404, [
            'success' => false,
            'message' => 'Booking not found.'
        ]);
    }

    $currentStatus = strtoupper((string)$booking['booking_status']);
    if ($requestedStatus === 'APPROVED' || $requestedStatus === 'DECLINED' || $requestedStatus === 'REJECTED') {
        if ($currentStatus !== 'PENDING') {
            bookingRollback($pdo);
            bookingRespond(409, [
                'success' => false,
                'message' => 'Only pending bookings can be approved or declined.'
            ]);
        }
        if ($requestedStatus === 'APPROVED') {
            if ((float)$room['other_fees'] !== 0.0) {
                bookingRollback($pdo);
                bookingRespond(409, [
                    'success' => false,
                    'message' => 'Room-level other fees must be itemized before this booking can be approved.'
                ]);
            }
            if (
                !in_array(strtoupper((string)$room['room_status']), ['AVAILABLE', 'OCCUPIED'], true)
                || (int)$room['capacity'] <= 0
            ) {
                bookingRollback($pdo);
                bookingRespond(409, [
                    'success' => false,
                    'message' => 'The room is not available for approval.'
                ]);
            }
            $reservationCount = bookingCountOverlappingReservations(
                $pdo,
                (int)$room['room_id'],
                (int)$booking['booking_id'],
                (string)$booking['move_in_date'],
                (string)$booking['rental_end_date']
            );
            if ($reservationCount >= (int)$room['capacity']) {
                bookingRollback($pdo);
                bookingRespond(409, [
                    'success' => false,
                    'message' => 'The room has no remaining capacity for this rental period.'
                ]);
            }
        }
    } elseif ($requestedStatus === 'COMPLETED' && $currentStatus !== 'ACTIVE') {
        bookingRollback($pdo);
        bookingRespond(409, [
            'success' => false,
            'message' => $currentStatus === 'COMPLETED'
                ? 'Completed bookings cannot be changed.'
                : 'Only active bookings can be completed.'
        ]);
    }

    $updateStmt = $pdo->prepare(
        'UPDATE bookings
         SET status = CAST(:status AS booking_status_enum)
         WHERE booking_id = :booking_id
           AND status::text = :current_status
         RETURNING booking_id'
    );
    $updateStmt->execute([
        'status' => $requestedStatus,
        'booking_id' => $bookingId,
        'current_status' => $currentStatus
    ]);
    if (!$updateStmt->fetchColumn()) {
        bookingRollback($pdo);
        bookingRespond(409, [
            'success' => false,
            'message' => 'The booking changed before this update could be applied.'
        ]);
    }
    if ($requestedStatus === 'APPROVED') {
        paymentPricingSnapshot($pdo, (int)$bookingId);
    }

    $updatedRoomStatus = strtoupper((string)$room['room_status']);
    if (
        $requestedStatus === 'APPROVED'
        || $requestedStatus === 'COMPLETED'
    ) {
        if (in_array($updatedRoomStatus, ['AVAILABLE', 'OCCUPIED'], true)) {
            $updatedRoomStatus = bookingRecalculateRoomStatus(
                $pdo,
                (int)$room['room_id'],
                (int)$room['capacity']
            );
        }
    }

    if (in_array($requestedStatus, ['APPROVED', 'DECLINED', 'REJECTED'], true)) {
        $notification = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type, reference_id)
             VALUES (:student_id, :title, :message, 'BOOKING', :booking_id)"
        );
        $wasApproved = $requestedStatus === 'APPROVED';
        $notification->execute([
            'student_id' => $booking['user_id'],
            'title' => $wasApproved ? 'Booking Approved' : 'Booking Declined',
            'message' => $wasApproved
                ? 'Your booking for ' . $house['house_name'] . ' has been approved.'
                : 'Your booking request for ' . $house['house_name'] . ' has been declined.',
            'booking_id' => $bookingId
        ]);
    }

    $pdo->commit();
    bookingRespond(200, [
        'success' => true,
        'message' => 'Booking updated successfully.',
        'data' => [
            'booking_id' => (int)$bookingId,
            'status' => $requestedStatus,
            'room_status' => $updatedRoomStatus
        ]
    ]);
} catch (Throwable $e) {
    bookingRollback($pdo);
    error_log('Booking update failed: ' . get_class($e));
    bookingRespond($requestedStatus === 'APPROVED' ? 409 : 500, [
        'success' => false,
        'message' => $requestedStatus === 'APPROVED'
            ? 'Approval is blocked until all fee terms are valid, categorized, and non-duplicative.'
            : 'Unable to update the booking. Please try again later.'
    ]);
}
?>
