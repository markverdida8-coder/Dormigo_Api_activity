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

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE student_verifications SET verification_status = 'REJECTED', rejection_reason = :reason, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE verification_id = :verification_id RETURNING student_id");
    $stmt->execute(['reason' => $rejection_reason, 'admin_id' => $admin_id, 'verification_id' => $verification_id]);
    $student_id = $stmt->fetchColumn();

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Verification Rejected', 'Your student verification was rejected. Reason: ' || ?, 'VERIFICATION', ?)");
    $nStmt->execute([$student_id, $rejection_reason, $verification_id]);

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Student verification rejected."]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
