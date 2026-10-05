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

if ($verification_id <= 0 || $admin_id <= 0) {
    echo json_encode(["success" => false, "message" => "Verification ID and Admin ID are required."]);
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

    $stmt = $pdo->prepare("UPDATE student_verifications SET verification_status = 'VERIFIED', rejection_reason = NULL, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE verification_id = :verification_id RETURNING student_id");
    $stmt->execute(['admin_id' => $admin_id, 'verification_id' => $verification_id]);
    $student_id = $stmt->fetchColumn();

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Verification Approved', 'Your student verification has been approved. You are now a Verified Student.', 'VERIFICATION', ?)");
    $nStmt->execute([$student_id, $verification_id]);

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Student verification approved successfully."]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
