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

if (($verification_id <= 0 && $house_id <= 0) || $admin_id <= 0) {
    echo json_encode(["success" => false, "message" => "Verification ID / House ID and Admin ID are required."]);
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
        $stmt = $pdo->prepare("UPDATE boarding_house_verification SET verification_status = 'VERIFIED', rejection_reason = NULL, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE verification_id = :verification_id RETURNING house_id");
        $stmt->execute(['admin_id' => $admin_id, 'verification_id' => $verification_id]);
        $house_id = $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("UPDATE boarding_house_verification SET verification_status = 'VERIFIED', rejection_reason = NULL, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE house_id = :house_id");
        $stmt->execute(['admin_id' => $admin_id, 'house_id' => $house_id]);
    }

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Boarding house verification approved successfully.", "house_id" => $house_id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
