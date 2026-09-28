<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$status = isset($_GET['status']) ? strtoupper(trim($_GET['status'])) : '';

try {
    $sql = "SELECT sv.*, u.full_name, u.email, u.phone
            FROM student_verifications sv
            JOIN users u ON sv.user_id = u.user_id";

    $params = [];
    if (!empty($status)) {
        $sql .= " WHERE sv.verification_status = :status";
        $params['status'] = $status;
    }

    $sql .= " ORDER BY sv.submitted_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $verifications = $stmt->fetchAll();

    echo json_encode(["success" => true, "data" => $verifications]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
