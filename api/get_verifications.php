<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : '';
$house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : 0;

try {
    $sql = "SELECT bhv.*, bh.house_name, bh.address, bh.landlord_id, u.full_name AS landlord_name, u.email AS landlord_email, u.phone AS landlord_phone,
                   adm.full_name AS reviewer_name
            FROM boarding_house_verification bhv
            JOIN boarding_houses bh ON bhv.house_id = bh.house_id
            JOIN users u ON bh.landlord_id = u.user_id
            LEFT JOIN users adm ON bhv.reviewed_by = adm.user_id";

    $params = [];
    $conditions = [];

    if (!empty($status)) {
        $conditions[] = "bhv.verification_status = :status";
        $params['status'] = $status;
    }
    if ($house_id > 0) {
        $conditions[] = "bhv.house_id = :house_id";
        $params['house_id'] = $house_id;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY bhv.submitted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $verifications = $stmt->fetchAll();

    echo json_encode(["success" => true, "data" => $verifications]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
