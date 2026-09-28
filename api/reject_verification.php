<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'PATCH') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$verification_id = isset($data['verification_id']) ? (int)$data['verification_id'] : 0;
$house_id = isset($data['house_id']) ? (int)$data['house_id'] : 0;
$admin_id = isset($data['admin_id']) ? (int)$data['admin_id'] : 0;
$rejection_reason = isset($data['rejection_reason']) ? trim($data['rejection_reason']) : '';

if (($verification_id <= 0 && $house_id <= 0) || $admin_id <= 0 || empty($rejection_reason)) {
    echo json_encode(["success" => false, "message" => "Verification ID / House ID, Admin ID, and Rejection Reason are required."]);
    exit();
}

try {
    $chk = $pdo->prepare("SELECT user_type FROM users WHERE user_id = :admin_id");
    $chk->execute(['admin_id' => $admin_id]);
    $admin = $chk->fetch();

    if (!$admin || strtoupper($admin['user_type']) !== 'ADMIN') {
        echo json_encode(["success" => false, "message" => "Unauthorized. Admin privileges required."]);
        exit();
    }

    $pdo->beginTransaction();

    if ($verification_id > 0) {
        $stmt = $pdo->prepare("UPDATE boarding_house_verification SET verification_status = 'REJECTED', rejection_reason = :reason, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE verification_id = :verification_id RETURNING house_id");
        $stmt->execute(['reason' => $rejection_reason, 'admin_id' => $admin_id, 'verification_id' => $verification_id]);
        $house_id = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("UPDATE boarding_house_verification SET verification_status = 'REJECTED', rejection_reason = :reason, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE house_id = :house_id");
        $stmt->execute(['reason' => $rejection_reason, 'admin_id' => $admin_id, 'house_id' => $house_id]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Boarding house verification rejected.", "house_id" => $house_id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
