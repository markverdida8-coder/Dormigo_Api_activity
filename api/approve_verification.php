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

    $stmt = $pdo->prepare("UPDATE landlord_verifications SET verification_status = 'VERIFIED', rejection_reason = NULL, reviewed_by = :admin_id, reviewed_at = CURRENT_TIMESTAMP WHERE verification_id = :verification_id RETURNING landlord_id");
    $stmt->execute(['admin_id' => $admin_id, 'verification_id' => $verification_id]);
    $landlord_id = $stmt->fetchColumn();

    if ($landlord_id) {
        // Automatically propagate VERIFIED status to all boarding houses owned by this landlord
        $bhStmt = $pdo->prepare("SELECT house_id FROM boarding_houses WHERE landlord_id = ?");
        $bhStmt->execute([$landlord_id]);
        $houses = $bhStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($houses as $hId) {
            $checkBh = $pdo->prepare("SELECT verification_id FROM boarding_house_verification WHERE house_id = ?");
            $checkBh->execute([$hId]);
            if ($checkBh->fetch()) {
                $upBh = $pdo->prepare("UPDATE boarding_house_verification SET verification_status = 'VERIFIED', rejection_reason = NULL, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP WHERE house_id = ?");
                $upBh->execute([$admin_id, $hId]);
            } else {
                $insBh = $pdo->prepare("INSERT INTO boarding_house_verification (house_id, verification_status, reviewed_by, reviewed_at, submitted_at) VALUES (?, 'VERIFIED', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                $insBh->execute([$hId, $admin_id]);
            }
        }
    }

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Verification Approved', 'Your landlord verification has been approved. You are now a Verified Landlord and all your properties are verified.', 'VERIFICATION', ?)");
    $nStmt->execute([$landlord_id, $verification_id]);

    $pdo->commit();
    echo json_encode(["success" => true, "message" => "Landlord verification approved successfully. All owned properties automatically verified.", "landlord_id" => $landlord_id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
