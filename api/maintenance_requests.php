<?php
require_once 'db.php';
require_once 'auth_helper.php';

header('Content-Type: application/json; charset=UTF-8');

function maintenanceRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function maintenanceTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST, OPTIONS');
    maintenanceRespond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$authUser = authenticateUser($pdo);
if (strtoupper($authUser['user_type'] ?? '') !== 'STUDENT') {
    maintenanceRespond(403, [
        'success' => false,
        'message' => 'Forbidden: Only students can access maintenance requests.'
    ]);
}

$studentId = (int)$authUser['user_id'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            'SELECT mr.request_id, mr.booking_id, mr.category, mr.description,
                    mr.status, mr.photo_path, mr.resolution_notes,
                    mr.submitted_at, mr.updated_at, r.room_number, bh.house_name
             FROM maintenance_requests mr
             JOIN bookings b ON b.booking_id = mr.booking_id
             JOIN rooms r ON r.room_id = b.room_id
             JOIN boarding_houses bh ON bh.house_id = r.house_id
             WHERE b.user_id = :student_id
             ORDER BY mr.submitted_at DESC, mr.request_id DESC'
        );
        $stmt->execute(['student_id' => $studentId]);

        maintenanceRespond(200, [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Throwable $e) {
        error_log('Student maintenance request listing failed: ' . get_class($e));
        maintenanceRespond(500, [
            'success' => false,
            'message' => 'Unable to load maintenance requests. Please try again later.'
        ]);
    }
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'A valid JSON request body is required.'
    ]);
}

$bookingId = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT);
if ($bookingId === false || $bookingId === null || $bookingId <= 0) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'A valid booking_id is required.'
    ]);
}

$category = $data['category'] ?? null;
$description = $data['description'] ?? null;
if (!is_string($category) || !is_string($description)) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'Category and description are required.'
    ]);
}

$category = strtoupper(trim($category));
$description = trim($description);
$allowedCategories = [
    'PLUMBING',
    'ELECTRICAL',
    'FURNITURE',
    'APPLIANCE',
    'STRUCTURAL',
    'CLEANLINESS',
    'OTHER'
];

if ($category === '' || maintenanceTextLength($category) > 50) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'Category must contain 1 to 50 characters.'
    ]);
}
if (!in_array($category, $allowedCategories, true)) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'Category is not supported.'
    ]);
}
if ($description === '' || preg_match('//u', $description) !== 1 || maintenanceTextLength($description) > 2000) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'Description must contain 1 to 2000 characters.'
    ]);
}

if (
    array_key_exists('photo_path', $data)
    && $data['photo_path'] !== null
    && $data['photo_path'] !== ''
) {
    maintenanceRespond(400, [
        'success' => false,
        'message' => 'Photo uploads are not supported yet.'
    ]);
}

try {
    $pdo->beginTransaction();

    $bookingStmt = $pdo->prepare(
        "SELECT b.booking_id,
                bh.landlord_id,
                r.room_number,
                bh.house_name,
                b.status::text AS booking_status,
                (
                    b.move_in_date <= CURRENT_DATE
                    AND (b.move_in_date + (b.duration_months * INTERVAL '1 month'))::date > CURRENT_DATE
                ) AS within_rental_period,
                (
                    COALESCE(b.initial_payment_completed, FALSE)
                    OR EXISTS (
                        SELECT 1
                        FROM payments p
                        WHERE p.booking_id = b.booking_id
                          AND UPPER(p.status::text) IN ('PAID', 'CONFIRMED')
                    )
                ) AS initial_payment_completed
         FROM bookings b
         JOIN rooms r ON r.room_id = b.room_id
         JOIN boarding_houses bh ON bh.house_id = r.house_id
         WHERE b.booking_id = :booking_id
           AND b.user_id = :student_id
         FOR SHARE OF b"
    );
    $bookingStmt->execute([
        'booking_id' => $bookingId,
        'student_id' => $studentId
    ]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        $pdo->rollBack();
        maintenanceRespond(404, [
            'success' => false,
            'message' => 'Eligible booking not found.'
        ]);
    }

    $withinRentalPeriod = in_array($booking['within_rental_period'], [true, 't', '1', 1], true);
    $initialPaymentCompleted = in_array($booking['initial_payment_completed'], [true, 't', '1', 1], true);
    if (
        strtoupper((string)$booking['booking_status']) !== 'ACTIVE'
        || !$withinRentalPeriod
        || !$initialPaymentCompleted
    ) {
        $pdo->rollBack();
        maintenanceRespond(409, [
            'success' => false,
            'message' => 'This booking is not currently eligible for a maintenance request.'
        ]);
    }

    $insert = $pdo->prepare(
        "INSERT INTO maintenance_requests
            (booking_id, category, description, photo_path, status)
         VALUES (:booking_id, :category, :description, NULL, 'PENDING')
         RETURNING request_id, booking_id, category, description, status,
                   photo_path, resolution_notes, submitted_at, updated_at"
    );
    $insert->execute([
        'booking_id' => $bookingId,
        'category' => $category,
        'description' => $description
    ]);
    $request = $insert->fetch(PDO::FETCH_ASSOC);

    $roomLabel = trim((string)$booking['room_number']);
    if ($roomLabel === '') {
        $roomLabel = 'Room';
    }
    $houseName = trim((string)$booking['house_name']);
    if ($houseName === '') {
        $houseName = 'boarding house';
    }

    $notification = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type, reference_id)
         VALUES (:landlord_id, 'New maintenance request', :message, 'SYSTEM', :request_id)"
    );
    $notification->execute([
        'landlord_id' => $booking['landlord_id'],
        'message' => 'Maintenance request #' . (int)$request['request_id']
            . ' (' . $category . ') for ' . $roomLabel . ' at ' . $houseName . '.',
        'request_id' => (int)$request['request_id']
    ]);

    $pdo->commit();
    maintenanceRespond(201, [
        'success' => true,
        'message' => 'Maintenance request submitted successfully.',
        'data' => $request
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Student maintenance request submission failed: ' . get_class($e));
    maintenanceRespond(500, [
        'success' => false,
        'message' => 'Unable to submit maintenance request. Please try again later.'
    ]);
}
?>
