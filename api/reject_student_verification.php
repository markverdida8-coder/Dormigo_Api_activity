<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'PATCH') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);
$verification_id = isset($data['verification_id']) ? (int)$data['verification_id'] : 0;
$admin_id = isset($data['admin_id']) ? (int)$data['admin_id'] : 0;
$rejection_reason = isset($data['rejection_reason']) ? trim($data['rejection_reason']) : '';

if ($verification_id <= 0 || $admin_id <= 0 || empty($rejection_reason)) {
    echo json_encode(["success" => false, "message" => "Verification ID, Admin ID, and Rejection Reason are required."]);
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

    $stmt = $pdo->prepare("UPDATE student_verifications SET verification_status = 'REJECTED', rejection_reason = :reason WHERE verification_id = :verification_id");
    $stmt->execute(['reason' => $rejection_reason, 'verification_id' => $verification_id]);

    echo json_encode(["success" => true, "message" => "Student verification rejected."]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
