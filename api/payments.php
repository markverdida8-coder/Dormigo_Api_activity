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
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['booking_id']) || !isset($data['amount'])) {
        echo json_encode(["success" => false, "message" => "booking_id and amount are required."]);
        exit();
    }

    $transactionRef = $data['transaction_ref'] ?? null;
    if (empty($transactionRef) || $transactionRef === 'null' || strpos($transactionRef, 'QR-') === 0 || strpos($transactionRef, 'BHF-') === 0) {
        $transactionRef = generatePaymentReference($pdo, $data['payment_method'] ?? 'CASH');
    }

    $stmt = $pdo->prepare("INSERT INTO payments (booking_id, payment_period, due_date, amount, payment_method, payment_date, status, transaction_ref, payment_description)
                            VALUES (:booking_id, :payment_period, :due_date, :amount, :payment_method, :payment_date, :status::payment_status_enum, :transaction_ref, :payment_description) RETURNING payment_id");
    $stmt->execute([
        'booking_id' => $data['booking_id'],
        'payment_period' => $data['payment_period'] ?? 1,
        'due_date' => $data['due_date'] ?? date('Y-m-d'),
        'amount' => $data['amount'],
        'payment_method' => $data['payment_method'] ?? 'CASH',
        'payment_date' => $data['payment_date'] ?? null,
        'status' => strtoupper($data['status'] ?? 'PENDING'),
        'transaction_ref' => $transactionRef,
        'payment_description' => $data['payment_description'] ?? 'Monthly Rent'
    ]);
    $paymentId = $stmt->fetchColumn();

    $info = $pdo->prepare("SELECT u.full_name, bh.landlord_id FROM bookings b JOIN users u ON b.user_id = u.user_id JOIN rooms r ON b.room_id = r.room_id JOIN boarding_houses bh ON r.house_id = bh.house_id WHERE b.booking_id = ?");
    $info->execute([$data['booking_id']]);
    $infoData = $info->fetch();
    if ($infoData && $infoData['landlord_id']) {
        $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Payment Submitted', ?, 'PAYMENT', ?)");
        $fmtAmount = number_format((float)$data['amount'], 2, '.', ',');
        $nStmt->execute([$infoData['landlord_id'], $infoData['full_name'] . ' submitted a payment of ₱' . $fmtAmount . '.', $paymentId]);
    }

    echo json_encode(["success" => true, "message" => "Payment created successfully.", "payment_id" => $paymentId, "transaction_ref" => $transactionRef]);

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['payment_id'])) {
        echo json_encode(["success" => false, "message" => "payment_id is required."]);
        exit();
    }

    // Retrieve existing reference & method
    $refStmt = $pdo->prepare("SELECT transaction_ref, payment_method FROM payments WHERE payment_id = ?");
    $refStmt->execute([$data['payment_id']]);
    $existing = $refStmt->fetch();

    $transactionRef = $data['transaction_ref'] ?? ($existing['transaction_ref'] ?? null);
    if (empty($transactionRef) || $transactionRef === 'null' || strpos($transactionRef, 'QR-') === 0 || strpos($transactionRef, 'BHF-') === 0) {
        $method = $existing['payment_method'] ?? 'CASH';
        $transactionRef = generatePaymentReference($pdo, $method);
    }

    $stmt = $pdo->prepare("UPDATE payments SET status = :status::payment_status_enum, payment_date = :payment_date, transaction_ref = :transaction_ref WHERE payment_id = :payment_id");
    $stmt->execute([
        'payment_id' => $data['payment_id'],
        'status' => strtoupper($data['status'] ?? 'PENDING'),
        'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
        'transaction_ref' => $transactionRef
    ]);

    if (strtoupper($data['status'] ?? '') === 'PAID' || strtoupper($data['status'] ?? '') === 'CONFIRMED') {
        $info = $pdo->prepare("SELECT b.user_id FROM payments p JOIN bookings b ON p.booking_id = b.booking_id WHERE p.payment_id = ?");
        $info->execute([$data['payment_id']]);
        $infoData = $info->fetch();
        if ($infoData) {
            $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Payment Confirmed', 'Your payment has been confirmed.', 'PAYMENT', ?)");
            $nStmt->execute([$infoData['user_id'], $data['payment_id']]);
        }
    }
    echo json_encode(["success" => true, "message" => "Payment updated successfully.", "transaction_ref" => $transactionRef]);
}
?>
