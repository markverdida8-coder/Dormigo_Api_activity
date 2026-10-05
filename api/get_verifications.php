<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : '';

try {
    $sql = "SELECT lv.*, u.full_name AS landlord_name, u.email AS landlord_email, u.phone AS landlord_phone,
                   adm.full_name AS reviewer_name
            FROM landlord_verifications lv
            JOIN users u ON lv.landlord_id = u.user_id
            LEFT JOIN users adm ON lv.reviewed_by = adm.user_id";

    $params = [];
    $conditions = [];

    if (!empty($status)) {
        $conditions[] = "lv.verification_status = :status";
        $params['status'] = $status;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY lv.submitted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $verifications = $stmt->fetchAll();

    echo json_encode(["success" => true, "data" => $verifications]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
