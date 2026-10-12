<?php
require_once 'db.php';
require_once 'auth_helper.php';

header('Content-Type: application/json; charset=UTF-8');

function landlordMaintenanceRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function landlordMaintenanceTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'PATCH'], true)) {
    header('Allow: GET, PATCH, OPTIONS');
    landlordMaintenanceRespond(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

$authUser = authenticateUser($pdo);
if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
    landlordMaintenanceRespond(403, [
        'success' => false,
        'message' => 'Forbidden: Only landlords can access maintenance requests.'
    ]);
}
$landlordId = (int)$authUser['user_id'];

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            'SELECT mr.request_id, mr.booking_id, mr.category, mr.description,
                    mr.photo_path, mr.status, mr.resolution_notes,
                    mr.submitted_at, mr.updated_at, r.room_number,
                    bh.house_name, student.full_name AS student_name
             FROM maintenance_requests mr
             JOIN bookings b ON b.booking_id = mr.booking_id
             JOIN rooms r ON r.room_id = b.room_id
             JOIN boarding_houses bh ON bh.house_id = r.house_id
             JOIN users student ON student.user_id = b.user_id
             WHERE bh.landlord_id = :landlord_id
             ORDER BY mr.submitted_at DESC, mr.request_id DESC'
        );
        $stmt->execute(['landlord_id' => $landlordId]);

        landlordMaintenanceRespond(200, [
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    } catch (Throwable $e) {
        error_log('Landlord maintenance request listing failed: ' . get_class($e));
        landlordMaintenanceRespond(500, [
            'success' => false,
            'message' => 'Unable to load maintenance requests. Please try again later.'
        ]);
    }
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    landlordMaintenanceRespond(400, [
        'success' => false,
        'message' => 'A valid JSON request body is required.'
    ]);
}

$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
if ($requestId === false || $requestId === null || $requestId <= 0) {
    landlordMaintenanceRespond(400, [
        'success' => false,
        'message' => 'A valid request_id is required.'
    ]);
}

$targetStatus = $data['status'] ?? null;
if (!is_string($targetStatus)) {
    landlordMaintenanceRespond(400, [
        'success' => false,
        'message' => 'A valid maintenance status is required.'
    ]);
}
$targetStatus = strtoupper(trim($targetStatus));
if (!in_array($targetStatus, ['IN_PROGRESS', 'COMPLETED', 'REJECTED'], true)) {
    landlordMaintenanceRespond(400, [
        'success' => false,
        'message' => 'The requested maintenance status is not supported.'
    ]);
}

$notesProvided = array_key_exists('resolution_notes', $data);
$resolutionNotes = null;
if ($notesProvided && $data['resolution_notes'] !== null) {
    if (!is_string($data['resolution_notes'])) {
        landlordMaintenanceRespond(400, [
            'success' => false,
            'message' => 'Resolution notes must be text.'
        ]);
    }
    $resolutionNotes = trim($data['resolution_notes']);
    if (landlordMaintenanceTextLength($resolutionNotes) > 2000) {
        landlordMaintenanceRespond(400, [
            'success' => false,
            'message' => 'Resolution notes must not exceed 2000 characters.'
        ]);
    }
}
if (
    in_array($targetStatus, ['COMPLETED', 'REJECTED'], true)
    && (!$notesProvided || $resolutionNotes === null || $resolutionNotes === '')
) {
    landlordMaintenanceRespond(400, [
        'success' => false,
        'message' => 'Nonblank resolution notes are required for this status.'
    ]);
}

try {
    $pdo->beginTransaction();

    $requestStmt = $pdo->prepare(
        'SELECT mr.request_id, mr.status, mr.resolution_notes,
                bh.landlord_id, b.user_id AS student_id
         FROM maintenance_requests mr
         JOIN bookings b ON b.booking_id = mr.booking_id
         JOIN rooms r ON r.room_id = b.room_id
         JOIN boarding_houses bh ON bh.house_id = r.house_id
         WHERE mr.request_id = :request_id
         FOR UPDATE OF mr'
    );
    $requestStmt->execute(['request_id' => $requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        $pdo->rollBack();
        landlordMaintenanceRespond(404, [
            'success' => false,
            'message' => 'Maintenance request not found.'
        ]);
    }
    if ((int)$request['landlord_id'] !== $landlordId) {
        $pdo->rollBack();
        landlordMaintenanceRespond(403, [
            'success' => false,
            'message' => 'Forbidden: You do not own the property associated with this request.'
        ]);
    }

    $currentStatus = strtoupper((string)$request['status']);
    $allowedTransitions = [
        'PENDING' => ['IN_PROGRESS', 'REJECTED'],
        'IN_PROGRESS' => ['COMPLETED', 'REJECTED']
    ];
    if (!in_array($targetStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
        $pdo->rollBack();
        landlordMaintenanceRespond(409, [
            'success' => false,
            'message' => 'This maintenance request cannot transition to the requested status.'
        ]);
    }

    $updatedNotes = $notesProvided ? $resolutionNotes : $request['resolution_notes'];
    $update = $pdo->prepare(
        'UPDATE maintenance_requests
         SET status = :status, resolution_notes = :resolution_notes
         WHERE request_id = :request_id AND status = :current_status
         RETURNING request_id'
    );
    $update->execute([
        'status' => $targetStatus,
        'resolution_notes' => $updatedNotes,
        'request_id' => $requestId,
        'current_status' => $currentStatus
    ]);
    if ($update->fetchColumn() === false) {
        $pdo->rollBack();
        landlordMaintenanceRespond(409, [
            'success' => false,
            'message' => 'The maintenance request was updated by another operation. Please reload and try again.'
        ]);
    }

    $updatedRequestStmt = $pdo->prepare(
        'SELECT mr.request_id, mr.booking_id, mr.category, mr.description,
                mr.photo_path, mr.status, mr.resolution_notes,
                mr.submitted_at, mr.updated_at, r.room_number,
                bh.house_name, student.full_name AS student_name
         FROM maintenance_requests mr
         JOIN bookings b ON b.booking_id = mr.booking_id
         JOIN rooms r ON r.room_id = b.room_id
         JOIN boarding_houses bh ON bh.house_id = r.house_id
         JOIN users student ON student.user_id = b.user_id
         WHERE mr.request_id = :request_id
           AND bh.landlord_id = :landlord_id'
    );
    $updatedRequestStmt->execute([
        'request_id' => $requestId,
        'landlord_id' => $landlordId
    ]);
    $updatedRequest = $updatedRequestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$updatedRequest) {
        throw new RuntimeException('Updated maintenance request could not be loaded.');
    }

    $notificationMessage = 'Maintenance request #' . $requestId
        . ' is now ' . $targetStatus . '.';
    if (in_array($targetStatus, ['COMPLETED', 'REJECTED'], true)) {
        $notificationMessage .= ' Resolution notes are available in the maintenance screen.';
    }

    $notification = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type, reference_id)
         VALUES (:student_id, 'Maintenance request updated', :message, 'SYSTEM', :request_id)"
    );
    $notification->execute([
        'student_id' => $request['student_id'],
        'message' => $notificationMessage,
        'request_id' => $requestId
    ]);

    $pdo->commit();
    landlordMaintenanceRespond(200, [
        'success' => true,
        'message' => 'Maintenance request updated successfully.',
        'data' => $updatedRequest
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Landlord maintenance request update failed: ' . get_class($e));
    landlordMaintenanceRespond(500, [
        'success' => false,
        'message' => 'Unable to update maintenance request. Please try again later.'
    ]);
}
?>
