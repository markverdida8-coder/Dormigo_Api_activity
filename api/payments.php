<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

function generatePaymentReference($pdo, $paymentMethod) {
    $methodUpper = strtoupper($paymentMethod ?? '');
    $prefix = 'TX';
    if (strpos($methodUpper, 'GCASH') !== false || strpos($methodUpper, 'QR') !== false) {
        $prefix = 'GC';
    } elseif (strpos($methodUpper, 'CASH') !== false || strpos($methodUpper, 'ONSITE') !== false) {
        $prefix = 'CS';
    }

    $dateStr = date('Ymd');
    $stmt = $pdo->query("SELECT COALESCE(MAX(payment_id), 0) + 1 FROM payments");
    $nextId = (int)$stmt->fetchColumn();

    return sprintf('%s-%s-%06d', $prefix, $dateStr, $nextId);
}

if ($method === 'GET') {
    $sql = "SELECT p.*, u.full_name AS tenant_name, bh.house_name, r.room_number, r.room_type
            FROM payments p
            JOIN bookings b ON p.booking_id = b.booking_id
            JOIN users u ON b.user_id = u.user_id
            JOIN rooms r ON b.room_id = r.room_id
            JOIN boarding_houses bh ON r.house_id = bh.house_id";
    $params = [];

    if (isset($_GET['payment_id'])) {
        $sql .= " WHERE p.payment_id = :payment_id";
        $params['payment_id'] = $_GET['payment_id'];
    } elseif (isset($_GET['booking_id'])) {
        $sql .= " WHERE p.booking_id = :booking_id";
        $params['booking_id'] = $_GET['booking_id'];
    } elseif (isset($_GET['user_id'])) {
        $sql .= " WHERE b.user_id = :user_id";
        $params['user_id'] = $_GET['user_id'];
    } elseif (isset($_GET['landlord_id'])) {
        $sql .= " WHERE bh.landlord_id = :landlord_id";
        $params['landlord_id'] = $_GET['landlord_id'];
    }
    $sql .= " ORDER BY p.payment_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);

} elseif ($method === 'POST') {
    // Support both multipart/form-data and JSON input
    $data = $_POST;
    if (empty($data)) {
        $rawInput = file_get_contents("php://input");
        if (!empty($rawInput)) {
            $data = json_decode($rawInput, true) ?? [];
        }
    }

    if (!isset($data['booking_id'])) {
        echo json_encode(["success" => false, "message" => "booking_id is required."]);
        return;
    }

    $bookingId = (int)$data['booking_id'];
    if ($bookingId <= 0) {
        echo json_encode(["success" => false, "message" => "Valid booking_id is required."]);
        return;
    }

    // SERVER-AUTHORITATIVE AMOUNT DERIVATION (Ignore client-supplied amount tampering)
    $bookingCheck = $pdo->prepare("
        SELECT b.booking_id, b.agreed_monthly_rent, b.initial_payment_completed,
               r.advance_months, r.deposit_months, r.utility_deposit, r.other_fees, bh.house_id
        FROM bookings b
        JOIN rooms r ON b.room_id = r.room_id
        JOIN boarding_houses bh ON r.house_id = bh.house_id
        WHERE b.booking_id = ?
    ");
    $bookingCheck->execute([$bookingId]);
    $bookingInfo = $bookingCheck->fetch();

    if (!$bookingInfo) {
        echo json_encode(["success" => false, "message" => "Booking not found."]);
        return;
    }

    $monthlyRent = (float)$bookingInfo['agreed_monthly_rent'];
    $initialCompleted = (bool)$bookingInfo['initial_payment_completed'];
    $existingPaymentId = (int)($data['payment_id'] ?? 0);

    // Determine authoritative amount
    if ($existingPaymentId > 0) {
        $existingAmtStmt = $pdo->prepare("SELECT amount FROM payments WHERE payment_id = ?");
        $existingAmtStmt->execute([$existingPaymentId]);
        $existingAmtRow = $existingAmtStmt->fetch();
        if ($existingAmtRow && $existingAmtRow['amount'] !== null) {
            $amount = (float)$existingAmtRow['amount'];
        } else {
            if (!$initialCompleted) {
                $other = (float)($bookingInfo['other_fees'] ?? 0);
                $amount = $monthlyRent + $other;
            } else {
                $houseId = (int)$bookingInfo['house_id'];
                $mFeeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM property_additional_fees WHERE house_id = ? AND UPPER(fee_type::text) = 'MONTHLY'");
                $mFeeStmt->execute([$houseId]);
                $monthlyFees = (float)$mFeeStmt->fetchColumn();

                $uFeeStmt = $pdo->prepare("SELECT COALESCE(SUM(fixed_amount), 0) FROM property_utilities WHERE house_id = ? AND UPPER(charging_method::text) = 'FIXED'");
                $uFeeStmt->execute([$houseId]);
                $fixedUtilities = (float)$uFeeStmt->fetchColumn();

                $amount = $monthlyRent + $monthlyFees + $fixedUtilities;
            }
        }
    } else {
        if (!$initialCompleted) {
            $other = (float)($bookingInfo['other_fees'] ?? 0);
            $amount = $monthlyRent + $other;
        } else {
            $houseId = (int)$bookingInfo['house_id'];
            $mFeeStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM property_additional_fees WHERE house_id = ? AND UPPER(fee_type::text) = 'MONTHLY'");
            $mFeeStmt->execute([$houseId]);
            $monthlyFees = (float)$mFeeStmt->fetchColumn();

            $uFeeStmt = $pdo->prepare("SELECT COALESCE(SUM(fixed_amount), 0) FROM property_utilities WHERE house_id = ? AND UPPER(charging_method::text) = 'FIXED'");
            $uFeeStmt->execute([$houseId]);
            $fixedUtilities = (float)$uFeeStmt->fetchColumn();

            $amount = $monthlyRent + $monthlyFees + $fixedUtilities;
        }
    }

    $paymentMethod = strtoupper($data['payment_method'] ?? 'CASH');
    $isQr = ($paymentMethod === 'QR' || $paymentMethod === 'GCASH' || isset($_FILES['proof_image']) || isset($_FILES['receipt_image']));

    // Proof image upload handling
    $proofImagePath = null;
    $fileKey = isset($_FILES['proof_image']) ? 'proof_image' : (isset($_FILES['receipt_image']) ? 'receipt_image' : null);

    if ($fileKey && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES[$fileKey]['tmp_name'];
        $fileSize = $_FILES[$fileKey]['size'];

        if ($fileSize > 10 * 1024 * 1024) {
            echo json_encode(["success" => false, "message" => "Receipt screenshot exceeds the 10MB file size limit."]);
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
        if (!in_array($mimeType, $allowedTypes)) {
            echo json_encode(["success" => false, "message" => "Invalid image type. Please upload a JPEG or PNG receipt screenshot."]);
            return;
        }

        $ext = 'jpg';
        if ($mimeType === 'image/png') $ext = 'png';
        elseif ($mimeType === 'image/webp') $ext = 'webp';

        $uploadDir = __DIR__ . '/../uploads/payment_proofs/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $newFilename = 'proof_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destPath = $uploadDir . $newFilename;

        $saved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $destPath) : copy($tmpPath, $destPath);
        if ($saved) {
            $proofImagePath = 'uploads/payment_proofs/' . $newFilename;
        } else {
            echo json_encode(["success" => false, "message" => "Failed to save uploaded receipt image."]);
            return;
        }
    }

    if ($isQr) {
        // SECURITY RULE: Student QR submission MUST always be SUBMITTED initially
        $status = 'SUBMITTED';

        $transactionRef = trim($data['transaction_ref'] ?? '');
        if (empty($transactionRef)) {
            echo json_encode(["success" => false, "message" => "GCash transaction reference number is required."]);
            return;
        }

        // DUPLICATE PROTECTION: Check if non-empty QR reference already exists
        $checkStmt = $pdo->prepare("SELECT payment_id FROM payments WHERE TRIM(LOWER(transaction_ref)) = TRIM(LOWER(:ref)) AND status NOT IN ('REJECTED', 'CANCELLED', 'FAILED')");
        $checkStmt->execute(['ref' => $transactionRef]);
        $existingRow = $checkStmt->fetch();

        if ($existingRow && ($existingPaymentId <= 0 || (int)$existingRow['payment_id'] !== $existingPaymentId)) {
            echo json_encode(["success" => false, "message" => "This payment reference has already been submitted."]);
            return;
        }

        if (empty($proofImagePath) && $existingPaymentId > 0) {
            $imgStmt = $pdo->prepare("SELECT proof_image FROM payments WHERE payment_id = ?");
            $imgStmt->execute([$existingPaymentId]);
            $existingImgRow = $imgStmt->fetch();
            if ($existingImgRow && !empty($existingImgRow['proof_image'])) {
                $proofImagePath = $existingImgRow['proof_image'];
            }
        }

        if (empty($proofImagePath)) {
            echo json_encode(["success" => false, "message" => "Receipt screenshot is required for QR payment submission."]);
            return;
        }
    } else {
        $status = strtoupper($data['status'] ?? 'PENDING');
        $transactionRef = $data['transaction_ref'] ?? null;
        if (empty($transactionRef) || $transactionRef === 'null' || strpos($transactionRef, 'QR-') === 0 || strpos($transactionRef, 'BHF-') === 0) {
            $transactionRef = generatePaymentReference($pdo, $paymentMethod);
        }
    }

    if ($existingPaymentId > 0) {
        $stmt = $pdo->prepare("UPDATE payments SET status = :status::payment_status_enum, payment_date = :payment_date, transaction_ref = :transaction_ref, proof_image = COALESCE(:proof_image, proof_image), payment_method = :payment_method, amount = :amount WHERE payment_id = :payment_id");
        $stmt->execute([
            'payment_id' => $existingPaymentId,
            'status' => $status,
            'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
            'transaction_ref' => $transactionRef,
            'proof_image' => $proofImagePath,
            'payment_method' => $paymentMethod,
            'amount' => $amount
        ]);
        $paymentId = $existingPaymentId;
    } else {
        $stmt = $pdo->prepare("INSERT INTO payments (booking_id, payment_period, due_date, amount, payment_method, payment_date, status, transaction_ref, payment_description, proof_image)
                                VALUES (:booking_id, :payment_period, :due_date, :amount, :payment_method, :payment_date, :status::payment_status_enum, :transaction_ref, :payment_description, :proof_image) RETURNING payment_id");
        $stmt->execute([
            'booking_id' => $bookingId,
            'payment_period' => $data['payment_period'] ?? 1,
            'due_date' => $data['due_date'] ?? date('Y-m-d'),
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
            'status' => $status,
            'transaction_ref' => $transactionRef,
            'payment_description' => $data['payment_description'] ?? 'Monthly Rent',
            'proof_image' => $proofImagePath
        ]);
        $paymentId = $stmt->fetchColumn();
    }

    $info = $pdo->prepare("SELECT u.full_name, bh.landlord_id FROM bookings b JOIN users u ON b.user_id = u.user_id JOIN rooms r ON b.room_id = r.room_id JOIN boarding_houses bh ON r.house_id = bh.house_id WHERE b.booking_id = ?");
    $info->execute([$bookingId]);
    $infoData = $info->fetch();
    if ($infoData && $infoData['landlord_id']) {
        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Payment Submitted', ?, 'PAYMENT', ?)");
        $fmtAmount = number_format((float)$amount, 2, '.', ',');
        $nStmt->execute([$infoData['landlord_id'], $infoData['full_name'] . ' submitted a payment of ₱' . $fmtAmount . ' for verification.', $paymentId]);
    }

    echo json_encode(["success" => true, "message" => "Payment submitted for verification successfully.", "payment_id" => $paymentId, "transaction_ref" => $transactionRef, "status" => $status]);

} elseif ($method === 'PATCH') {
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);

    if (strtoupper($authUser['user_type']) !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: Only landlords can verify or reject payments."]);
        return;
    }

    $data = $_POST;
    if (empty($data)) {
        $rawInput = file_get_contents("php://input");
        if (!empty($rawInput)) {
            $data = json_decode($rawInput, true) ?? [];
        }
    }

    if (!isset($data['payment_id'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "payment_id is required."]);
        return;
    }

    $paymentId = (int)$data['payment_id'];
    $requestedStatus = strtoupper(trim($data['status'] ?? ''));

    // STRICT TRANSITION RULE: Accept ONLY PAID or REJECTED. Reject CONFIRMED or PENDING target statuses.
    if (!in_array($requestedStatus, ['PAID', 'REJECTED'])) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid target status. Only PAID and REJECTED are accepted."]);
        return;
    }

    // Retrieve payment, booking, and boarding house landlord_id
    $chkStmt = $pdo->prepare("
        SELECT p.*, bh.landlord_id, b.user_id AS student_user_id
        FROM payments p
        JOIN bookings b ON p.booking_id = b.booking_id
        JOIN rooms r ON b.room_id = r.room_id
        JOIN boarding_houses bh ON r.house_id = bh.house_id
        WHERE p.payment_id = ?
    ");
    $chkStmt->execute([$paymentId]);
    $paymentRec = $chkStmt->fetch();

    if (!$paymentRec) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Payment record not found."]);
        return;
    }

    // Ownership check: boarding_houses.landlord_id === authenticated user_id
    if ((int)$paymentRec['landlord_id'] !== (int)$authUser['user_id']) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: You do not own the property associated with this payment."]);
        return;
    }

    $currentStatus = strtoupper($paymentRec['status']);
    $paymentMethod = strtoupper(trim($paymentRec['payment_method'] ?? ''));
    $isCash = ($paymentMethod === 'CASH' || $paymentMethod === 'ONSITE');

    if ($isCash) {
        if ($currentStatus !== 'PENDING') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Cash payment can only be confirmed if its current status is PENDING (current: " . $currentStatus . ")."]);
            return;
        }
        if ($requestedStatus !== 'PAID') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Invalid target status for cash payment. Only PAID is accepted."]);
            return;
        }
        $targetStatus = 'PAID';
    } else {
        if ($currentStatus !== 'SUBMITTED') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Payment can only be verified or rejected if its current status is SUBMITTED (current: " . $currentStatus . ")."]);
            return;
        }

        $rejectionReason = trim($data['rejection_reason'] ?? '');
        if ($requestedStatus === 'REJECTED') {
            if (empty($rejectionReason)) {
                http_response_code(400);
                echo json_encode(["success" => false, "message" => "Rejection reason is required when rejecting a payment."]);
                return;
            }
            $targetStatus = 'REJECTED';
        } else {
            $targetStatus = 'PAID';
        }
    }

    if ($targetStatus === 'PAID') {
        $stmt = $pdo->prepare("UPDATE payments SET status = 'PAID'::payment_status_enum, verified_at = CURRENT_TIMESTAMP, rejection_reason = NULL WHERE payment_id = ?");
        $stmt->execute([$paymentId]);

        $fmtAmount = number_format((float)$paymentRec['amount'], 2, '.', ',');
        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Payment Confirmed', 'Your payment of ₱' || ? || ' has been confirmed by the landlord.', 'PAYMENT', ?)");
        $nStmt->execute([$paymentRec['student_user_id'], $fmtAmount, $paymentId]);

        $updBooking = $pdo->prepare("UPDATE bookings SET initial_payment_completed = true WHERE booking_id = ?");
        $updBooking->execute([$paymentRec['booking_id']]);

    } else { // REJECTED
        $stmt = $pdo->prepare("UPDATE payments SET status = 'REJECTED'::payment_status_enum, verified_at = CURRENT_TIMESTAMP, rejection_reason = ? WHERE payment_id = ?");
        $stmt->execute([$rejectionReason, $paymentId]);

        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Payment Rejected', 'Your payment proof was rejected. Reason: ' || ?, 'PAYMENT', ?)");
        $nStmt->execute([$paymentRec['student_user_id'], $rejectionReason, $paymentId]);
    }

    echo json_encode([
        "success" => true,
        "message" => ($targetStatus === 'PAID' ? "Payment confirmed successfully." : "Payment rejected successfully."),
        "payment_id" => $paymentId,
        "status" => $targetStatus
    ]);
}
?>
