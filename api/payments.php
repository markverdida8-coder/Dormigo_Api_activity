<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/payment_model.php';

header('Content-Type: application/json; charset=UTF-8');

function paymentRespond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function paymentRollback(PDO $pdo): void
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

function paymentCreateReference(string $paymentMethod, int $paymentId): string
{
    $method = strtoupper($paymentMethod);
    $prefix = in_array($method, ['CASH', 'ONSITE'], true) ? 'CS' : 'GC';
    return sprintf('%s-%s-%06d', $prefix, date('Ymd'), $paymentId);
}

function paymentLoadRequestData(): ?array
{
    if (!empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    header('Allow: GET, POST, PATCH, OPTIONS');
    paymentRespond(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$authUser = authenticateUser($pdo);
$userId = (int)$authUser['user_id'];
$userType = strtoupper((string)$authUser['user_type']);
$hasPaymentType = paymentTypeColumnExists($pdo);
$paymentTypeExpression = paymentTypeExpression($hasPaymentType);

if ($method === 'GET') {
    if (!in_array($userType, ['STUDENT', 'LANDLORD', 'ADMIN'], true)) {
        paymentRespond(403, ['success' => false, 'message' => 'You are not authorized to view payments.']);
    }

    $paymentTypeOutput = $hasPaymentType
        ? ''
        : ', ' . $paymentTypeExpression . ' AS payment_type';
    $sql = "SELECT p.*{$paymentTypeOutput}, {$paymentTypeExpression} AS resolved_payment_type,
                   u.full_name AS tenant_name, bh.house_name, r.room_number, r.room_type
            FROM payments p
            JOIN bookings b ON p.booking_id = b.booking_id
            JOIN users u ON b.user_id = u.user_id
            JOIN rooms r ON b.room_id = r.room_id
            JOIN boarding_houses bh ON r.house_id = bh.house_id";
    $where = [];
    $params = [];
    if ($userType === 'STUDENT') {
        $where[] = 'b.user_id = :authenticated_user_id';
        $params['authenticated_user_id'] = $userId;
    } elseif ($userType === 'LANDLORD') {
        $where[] = 'bh.landlord_id = :authenticated_user_id';
        $params['authenticated_user_id'] = $userId;
    }
    foreach (['payment_id' => 'p.payment_id', 'booking_id' => 'p.booking_id',
              'user_id' => 'b.user_id', 'landlord_id' => 'bh.landlord_id',
              'house_id' => 'bh.house_id', 'date' => 'p.due_date'] as $input => $column) {
        if (isset($_GET[$input])) {
            $parameter = 'filter_' . $input;
            $where[] = $column . ' = :' . $parameter;
            $params[$parameter] = $input === 'date'
                ? trim((string)$_GET[$input])
                : (int)$_GET[$input];
        }
    }
    if (isset($_GET['status'])) {
        $where[] = "p.status = CAST(:filter_status AS payment_status_enum)";
        $params['filter_status'] = strtoupper(trim((string)$_GET['status']));
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.payment_id DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        paymentRespond(200, ['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) {
        error_log('Payment list request failed: ' . get_class($e));
        paymentRespond(500, ['success' => false, 'message' => 'Unable to load payments. Please try again later.']);
    }
}

if ($method === 'POST') {
    if ($userType !== 'STUDENT') {
        paymentRespond(403, ['success' => false, 'message' => 'Only students can submit payments.']);
    }

    $data = paymentLoadRequestData();
    if (!is_array($data)) {
        paymentRespond(400, ['success' => false, 'message' => 'A valid payment request is required.']);
    }
    $bookingId = filter_var($data['booking_id'] ?? null, FILTER_VALIDATE_INT);
    if ($bookingId === false || $bookingId === null || $bookingId <= 0) {
        paymentRespond(400, ['success' => false, 'message' => 'A valid booking_id is required.']);
    }

    $requestedPaymentId = filter_var($data['payment_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($requestedPaymentId === false || $requestedPaymentId < 0) {
        paymentRespond(400, ['success' => false, 'message' => 'A valid payment_id is required.']);
    }

    $paymentMethod = strtoupper(trim((string)($data['payment_method'] ?? 'ONSITE')));
    if (!in_array($paymentMethod, ['ONSITE', 'CASH', 'QR', 'GCASH'], true)) {
        paymentRespond(400, ['success' => false, 'message' => 'Unsupported payment method.']);
    }
    $isQr = in_array($paymentMethod, ['QR', 'GCASH'], true)
        || isset($_FILES['proof_image'])
        || isset($_FILES['receipt_image']);
    $paymentMethod = $isQr ? 'QR' : 'ONSITE';
    $status = $isQr ? 'SUBMITTED' : 'PENDING';
    $transactionRef = $isQr ? trim((string)($data['transaction_ref'] ?? '')) : null;
    if ($isQr && $transactionRef === '') {
        paymentRespond(400, ['success' => false, 'message' => 'GCash transaction reference number is required.']);
    }

    $fileKey = isset($_FILES['proof_image'])
        ? 'proof_image'
        : (isset($_FILES['receipt_image']) ? 'receipt_image' : null);
    $uploadedProof = null;
    if ($fileKey !== null && ($_FILES[$fileKey]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
            paymentRespond(400, ['success' => false, 'message' => 'Receipt screenshot could not be uploaded.']);
        }
        if ((int)$_FILES[$fileKey]['size'] > 10 * 1024 * 1024) {
            paymentRespond(400, ['success' => false, 'message' => 'Receipt screenshot exceeds the 10MB file size limit.']);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo ? finfo_file($finfo, $_FILES[$fileKey]['tmp_name']) : false;
        if ($finfo) {
            finfo_close($finfo);
        }
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            paymentRespond(400, ['success' => false, 'message' => 'Invalid receipt image type.']);
        }
        $uploadedProof = [
            'tmp_name' => $_FILES[$fileKey]['tmp_name'],
            'extension' => $extensions[$mimeType],
            'upload' => is_uploaded_file($_FILES[$fileKey]['tmp_name'])
        ];
    }

    $savedProofPath = null;
    try {
        $pdo->beginTransaction();
        $booking = paymentBookingForUpdate($pdo, (int)$bookingId);
        if (!$booking) {
            paymentRollback($pdo);
            paymentRespond(404, ['success' => false, 'message' => 'Booking not found.']);
        }
        if ((int)$booking['user_id'] !== $userId) {
            paymentRollback($pdo);
            paymentRespond(404, ['success' => false, 'message' => 'Booking not found.']);
        }

        try {
            $snapshot = paymentPricingSnapshot($pdo, (int)$bookingId);
        } catch (RuntimeException $e) {
            paymentRollback($pdo);
            paymentRespond(409, [
                'success' => false,
                'message' => 'Payment is unavailable until the booking pricing snapshot is verified.'
            ]);
        }
        $hasConfirmedInitial = paymentHasConfirmedInitial($pdo, (int)$bookingId, $hasPaymentType);
        $paymentType = $hasConfirmedInitial ? 'MONTHLY' : 'INITIAL';
        $bookingStatus = strtoupper((string)$booking['booking_status']);
        if ($paymentType === 'INITIAL' && $bookingStatus !== 'APPROVED') {
            paymentRollback($pdo);
            paymentRespond(409, ['success' => false, 'message' => 'An initial payment is available only for an approved booking.']);
        }
        if ($paymentType === 'MONTHLY' && $bookingStatus !== 'ACTIVE') {
            paymentRollback($pdo);
            paymentRespond(409, ['success' => false, 'message' => 'Monthly rent is available only for an active booking.']);
        }

        if ($paymentType === 'INITIAL') {
            $period = 0;
            $amount = paymentInitialAmount($snapshot);
            $dueDate = (string)$snapshot['agreed_move_in_date'];
        } else {
            $period = paymentNextMonthlyPeriod(
                $pdo,
                (int)$bookingId,
                (int)$snapshot['advance_months'],
                $hasPaymentType
            );
            if ($period <= (int)$snapshot['advance_months'] + 1) {
                paymentRollback($pdo);
                paymentRespond(409, ['success' => false, 'message' => 'This rental period is already covered by the initial payment.']);
            }
            if ($period > (int)$snapshot['agreed_duration_months']) {
                paymentRollback($pdo);
                paymentRespond(409, ['success' => false, 'message' => 'There are no remaining billable rental periods.']);
            }
            $amount = paymentMonthlyAmount($snapshot);
            $dueDate = paymentDueDateForPeriod(
                (string)$snapshot['agreed_move_in_date'],
                $period,
                (int)$snapshot['payment_due_day']
            );
        }

        $existingPayment = null;
        if ((int)$requestedPaymentId > 0) {
            $existingStmt = $pdo->prepare(
                "SELECT p.*, {$paymentTypeExpression} AS resolved_payment_type
                 FROM payments p
                 WHERE p.payment_id = :payment_id
                   AND p.booking_id = :booking_id
                 FOR UPDATE"
            );
            $existingStmt->execute([
                'payment_id' => (int)$requestedPaymentId,
                'booking_id' => (int)$bookingId
            ]);
            $existingPayment = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingPayment || paymentTypeFromRecord($existingPayment) !== $paymentType) {
                paymentRollback($pdo);
                paymentRespond(404, ['success' => false, 'message' => 'Payment record not found for this booking.']);
            }
            if (!in_array(strtoupper((string)$existingPayment['status']), ['PENDING', 'SUBMITTED'], true)) {
                paymentRollback($pdo);
                paymentRespond(409, ['success' => false, 'message' => 'Only an unverified payment can be resubmitted.']);
            }
            if ($paymentType === 'MONTHLY') {
                $period = (int)$existingPayment['payment_period'];
                if ($period <= (int)$snapshot['advance_months'] + 1
                    || $period > (int)$snapshot['agreed_duration_months']) {
                    paymentRollback($pdo);
                    paymentRespond(409, ['success' => false, 'message' => 'This rental period is not separately billable.']);
                }
                $dueDate = (string)$existingPayment['due_date'];
            }
        }

        $activeDuplicate = paymentActiveForTypeAndPeriod(
            $pdo,
            (int)$bookingId,
            $paymentType,
            $period,
            $hasPaymentType,
            (int)$requestedPaymentId
        );
        if ($activeDuplicate) {
            paymentRollback($pdo);
            paymentRespond(409, ['success' => false, 'message' => 'An active payment already exists for this payment period.']);
        }

        $proofPath = null;
        if ($isQr) {
            $referenceStmt = $pdo->prepare(
                "SELECT payment_id
                 FROM payments
                 WHERE LOWER(BTRIM(transaction_ref)) = LOWER(:transaction_ref)
                   AND UPPER(status::text) NOT IN ('REJECTED', 'FAILED', 'CANCELLED')
                   AND payment_id <> :payment_id
                 LIMIT 1"
            );
            $referenceStmt->execute([
                'transaction_ref' => $transactionRef,
                'payment_id' => (int)$requestedPaymentId
            ]);
            if ($referenceStmt->fetchColumn()) {
                paymentRollback($pdo);
                paymentRespond(409, ['success' => false, 'message' => 'This payment reference has already been submitted.']);
            }
        }

        if ($uploadedProof !== null) {
            $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Unable to prepare payment proof storage.');
            }
            $filename = 'proof_' . bin2hex(random_bytes(16)) . '.' . $uploadedProof['extension'];
            $destination = $uploadDir . $filename;
            $saved = $uploadedProof['upload']
                ? move_uploaded_file($uploadedProof['tmp_name'], $destination)
                : copy($uploadedProof['tmp_name'], $destination);
            if (!$saved) {
                throw new RuntimeException('Unable to store payment proof.');
            }
            $savedProofPath = $destination;
            $proofPath = 'uploads/payment_proofs/' . $filename;
        } elseif ($isQr && $existingPayment === null) {
            paymentRollback($pdo);
            paymentRespond(400, ['success' => false, 'message' => 'Receipt screenshot is required for QR payment submission.']);
        } elseif ($isQr && $existingPayment !== null && empty($existingPayment['proof_image'])) {
            paymentRollback($pdo);
            paymentRespond(400, ['success' => false, 'message' => 'Receipt screenshot is required for QR payment submission.']);
        }

        $description = $paymentType === 'INITIAL'
            ? 'Initial Move-in Payment'
            : 'Monthly Rent';
        if ($existingPayment !== null) {
            $effectiveReference = $isQr
                ? $transactionRef
                : ($existingPayment['transaction_ref'] ?: null);
            $updateSql = 'UPDATE payments
                          SET status = CAST(:status AS payment_status_enum),
                              payment_date = CURRENT_DATE,
                              transaction_ref = COALESCE(:transaction_ref, transaction_ref),
                              proof_image = COALESCE(:proof_image, proof_image),
                              payment_method = :payment_method';
            if ($hasPaymentType) {
                $updateSql .= ', payment_type = CAST(:payment_type AS payment_type_enum)';
            }
            $updateSql .= ' WHERE payment_id = :payment_id';
            $params = [
                'status' => $status,
                'transaction_ref' => $effectiveReference,
                'proof_image' => $proofPath,
                'payment_method' => $paymentMethod,
                'payment_id' => (int)$requestedPaymentId
            ];
            if ($hasPaymentType) {
                $params['payment_type'] = $paymentType;
            }
            $updateStmt = $pdo->prepare($updateSql);
            $updateStmt->execute($params);
            $paymentId = (int)$requestedPaymentId;
            $amount = (float)$existingPayment['amount'];
            $transactionRef = $effectiveReference;
        } else {
            $columns = [
                'booking_id', 'payment_period', 'due_date', 'amount',
                'payment_method', 'payment_date', 'status',
                'transaction_ref', 'payment_description', 'proof_image', 'created_at'
            ];
            $values = [
                ':booking_id', ':payment_period', ':due_date', ':amount',
                ':payment_method', 'CURRENT_DATE',
                'CAST(:status AS payment_status_enum)',
                ':transaction_ref', ':payment_description', ':proof_image',
                'CURRENT_TIMESTAMP'
            ];
            if ($hasPaymentType) {
                $columns[] = 'payment_type';
                $values[] = 'CAST(:payment_type AS payment_type_enum)';
            }
            $insertStmt = $pdo->prepare(
                'INSERT INTO payments (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $values) . ')
                 RETURNING payment_id'
            );
            $insertParams = [
                'booking_id' => (int)$bookingId,
                'payment_period' => $period,
                'due_date' => $dueDate,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'status' => $status,
                'transaction_ref' => $isQr ? $transactionRef : null,
                'payment_description' => $description,
                'proof_image' => $proofPath
            ];
            if ($hasPaymentType) {
                $insertParams['payment_type'] = $paymentType;
            }
            $insertStmt->execute($insertParams);
            $paymentId = (int)$insertStmt->fetchColumn();
            if (!$isQr) {
                $transactionRef = paymentCreateReference($paymentMethod, $paymentId);
                $referenceUpdate = $pdo->prepare(
                    'UPDATE payments SET transaction_ref = :transaction_ref WHERE payment_id = :payment_id'
                );
                $referenceUpdate->execute([
                    'transaction_ref' => $transactionRef,
                    'payment_id' => $paymentId
                ]);
            }
        }

        $notification = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type, reference_id)
             VALUES (:landlord_id, 'Payment Submitted', :message, 'PAYMENT', :payment_id)"
        );
        $notification->execute([
            'landlord_id' => $booking['landlord_id'],
            'message' => $booking['student_name'] . ' submitted a ' . strtolower($paymentType)
                . ' payment of ₱' . number_format($amount, 2, '.', ',')
                . ' for verification.',
            'payment_id' => $paymentId
        ]);

        $pdo->commit();
        paymentRespond(200, [
            'success' => true,
            'message' => 'Payment submitted for verification successfully.',
            'payment_id' => $paymentId,
            'transaction_ref' => $transactionRef,
            'status' => $status
        ]);
    } catch (Throwable $e) {
        paymentRollback($pdo);
        if ($savedProofPath !== null && is_file($savedProofPath)) {
            unlink($savedProofPath);
        }
        if ($e instanceof PDOException && $e->getCode() === '23505') {
            paymentRespond(409, ['success' => false, 'message' => 'An active payment already exists for this payment period.']);
        }
        error_log('Payment submission failed: ' . get_class($e));
        paymentRespond(500, ['success' => false, 'message' => 'Unable to submit payment. Please try again later.']);
    }
}

$data = paymentLoadRequestData();
if (!is_array($data)) {
    paymentRespond(400, ['success' => false, 'message' => 'A valid payment update is required.']);
}
$paymentId = filter_var($data['payment_id'] ?? null, FILTER_VALIDATE_INT);
$requestedStatus = strtoupper(trim((string)($data['status'] ?? '')));
if ($paymentId === false || $paymentId === null || $paymentId <= 0) {
    paymentRespond(400, ['success' => false, 'message' => 'A valid payment_id is required.']);
}
if ($userType !== 'LANDLORD') {
    paymentRespond(403, ['success' => false, 'message' => 'Only landlords can verify or reject payments.']);
}
if (!in_array($requestedStatus, ['PAID', 'REJECTED'], true)) {
    paymentRespond(400, ['success' => false, 'message' => 'Only PAID or REJECTED payment statuses are accepted.']);
}
$rejectionReason = trim((string)($data['rejection_reason'] ?? ''));
if ($requestedStatus === 'REJECTED' && $rejectionReason === '') {
    paymentRespond(400, ['success' => false, 'message' => 'Rejection reason is required when rejecting a payment.']);
}

try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare('SELECT booking_id FROM payments WHERE payment_id = :payment_id');
    $lookup->execute(['payment_id' => (int)$paymentId]);
    $bookingId = $lookup->fetchColumn();
    if (!$bookingId) {
        paymentRollback($pdo);
        paymentRespond(404, ['success' => false, 'message' => 'Payment record not found.']);
    }

    $booking = paymentBookingForUpdate($pdo, (int)$bookingId);
    if (!$booking) {
        paymentRollback($pdo);
        paymentRespond(404, ['success' => false, 'message' => 'Payment record not found.']);
    }

    $selectPayment = $pdo->prepare(
        "SELECT p.*, {$paymentTypeExpression} AS resolved_payment_type
         FROM payments p
         WHERE p.payment_id = :payment_id
           AND p.booking_id = :booking_id
         FOR UPDATE"
    );
    $selectPayment->execute([
        'payment_id' => (int)$paymentId,
        'booking_id' => (int)$bookingId
    ]);
    $payment = $selectPayment->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        paymentRollback($pdo);
        paymentRespond(404, ['success' => false, 'message' => 'Payment record not found.']);
    }
    if ((int)$booking['landlord_id'] !== $userId) {
        paymentRollback($pdo);
        paymentRespond(403, ['success' => false, 'message' => 'You do not own the property associated with this payment.']);
    }

    $currentStatus = strtoupper((string)$payment['status']);
    $methodName = strtoupper(trim((string)$payment['payment_method']));
    $isCash = in_array($methodName, ['CASH', 'ONSITE'], true);
    if (($isCash && $currentStatus !== 'PENDING')
        || (!$isCash && $currentStatus !== 'SUBMITTED')) {
        paymentRollback($pdo);
        paymentRespond(409, ['success' => false, 'message' => 'This payment is no longer awaiting verification.']);
    }
    if ($requestedStatus === 'REJECTED' && $isCash) {
        paymentRollback($pdo);
        paymentRespond(400, ['success' => false, 'message' => 'Cash payments cannot be rejected through proof verification.']);
    }

    $resolvedPaymentType = paymentTypeFromRecord($payment);
    $nextDueDate = null;
    if ($requestedStatus === 'PAID') {
        try {
            $snapshot = paymentPricingSnapshot($pdo, (int)$bookingId);
        } catch (RuntimeException $e) {
            paymentRollback($pdo);
            paymentRespond(409, [
                'success' => false,
                'message' => 'Payment verification is unavailable until the booking pricing snapshot is verified.'
            ]);
        }
    } else {
        $snapshot = null;
    }
    if ($requestedStatus === 'PAID' && $resolvedPaymentType === 'MONTHLY') {
        $period = (int)$payment['payment_period'];
        if (
            $period <= (int)$snapshot['advance_months'] + 1
            || $period > (int)$snapshot['agreed_duration_months']
        ) {
            paymentRollback($pdo);
            paymentRespond(409, ['success' => false, 'message' => 'This rental period is not separately billable.']);
        }
        $nextPeriod = $period + 1;
        if ($nextPeriod <= (int)$snapshot['agreed_duration_months']) {
            $nextDueDate = paymentDueDateForPeriod(
                (string)$snapshot['agreed_move_in_date'],
                $nextPeriod,
                (int)$snapshot['payment_due_day']
            );
        }
    } elseif ($requestedStatus === 'PAID' && $resolvedPaymentType === 'INITIAL') {
        $firstBillablePeriod = (int)$snapshot['advance_months'] + 2;
        if ($firstBillablePeriod <= (int)$snapshot['agreed_duration_months']) {
            $nextDueDate = paymentDueDateForPeriod(
                (string)$snapshot['agreed_move_in_date'],
                $firstBillablePeriod,
                (int)$snapshot['payment_due_day']
            );
        }
    }

    $update = $pdo->prepare(
        "UPDATE payments
         SET status = CAST(:status AS payment_status_enum),
             verified_at = CURRENT_TIMESTAMP,
             rejection_reason = :rejection_reason
         WHERE payment_id = :payment_id"
    );
    $update->execute([
        'status' => $requestedStatus,
        'rejection_reason' => $requestedStatus === 'REJECTED' ? $rejectionReason : null,
        'payment_id' => (int)$paymentId
    ]);

    if ($requestedStatus === 'PAID' && $resolvedPaymentType === 'INITIAL') {
        $initialUpdate = $pdo->prepare(
            'UPDATE bookings SET initial_payment_completed = TRUE WHERE booking_id = :booking_id'
        );
        $initialUpdate->execute(['booking_id' => (int)$bookingId]);
    } elseif ($requestedStatus === 'PAID') {
        $nextDueUpdate = $pdo->prepare(
            'UPDATE bookings SET next_due_date = :next_due_date
             WHERE booking_id = :booking_id'
        );
        $nextDueUpdate->execute([
            'next_due_date' => $nextDueDate,
            'booking_id' => (int)$bookingId
        ]);
    }

    $title = $requestedStatus === 'PAID' ? 'Payment Confirmed' : 'Payment Rejected';
    $message = $requestedStatus === 'PAID'
        ? 'Your payment of ₱' . number_format((float)$payment['amount'], 2, '.', ',')
            . ' has been confirmed by the landlord.'
        : 'Your payment proof was rejected. Reason: ' . $rejectionReason;
    $notification = $pdo->prepare(
        "INSERT INTO notifications (user_id, title, message, type, reference_id)
         VALUES (:student_id, :title, :message, 'PAYMENT', :payment_id)"
    );
    $notification->execute([
        'student_id' => (int)$booking['user_id'],
        'title' => $title,
        'message' => $message,
        'payment_id' => (int)$paymentId
    ]);

    $pdo->commit();
    paymentRespond(200, [
        'success' => true,
        'message' => $requestedStatus === 'PAID'
            ? 'Payment confirmed successfully.'
            : 'Payment rejected successfully.',
        'payment_id' => (int)$paymentId,
        'status' => $requestedStatus
    ]);
} catch (Throwable $e) {
    paymentRollback($pdo);
    error_log('Payment verification failed: ' . get_class($e));
    paymentRespond(500, ['success' => false, 'message' => 'Unable to update payment. Please try again later.']);
}
