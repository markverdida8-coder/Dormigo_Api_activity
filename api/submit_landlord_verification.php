<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$landlord_id = isset($_POST['landlord_id']) ? (int)$_POST['landlord_id'] : 0;
if ($landlord_id <= 0) {
    echo json_encode(["success" => false, "message" => "landlord_id is required."]);
    exit();
}

$uploadDir = '../uploads/verifications/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$document_path = null;
if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
    $fileName = 'landlord_verif_' . $landlord_id . '_' . time() . '.pdf';
    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES['document']['tmp_name'], $targetPath)) {
        $document_path = 'uploads/verifications/' . $fileName;
    }
}

if (empty($document_path)) {
    echo json_encode(["success" => false, "message" => "Failed to upload verification document."]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT verification_id FROM landlord_verifications WHERE landlord_id = :landlord_id");
    $stmt->execute(['landlord_id' => $landlord_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare("UPDATE landlord_verifications SET verification_status = 'PENDING', rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, document_path = :path, submitted_at = CURRENT_TIMESTAMP WHERE landlord_id = :landlord_id");
        $update->execute(['path' => $document_path, 'landlord_id' => $landlord_id]);
        $verificationId = $existing['verification_id'];
    } else {
        $insert = $pdo->prepare("INSERT INTO landlord_verifications (landlord_id, document_path, verification_status) VALUES (:landlord_id, :path, 'PENDING')");
        $insert->execute(['landlord_id' => $landlord_id, 'path' => $document_path]);
        $verificationId = $pdo->lastInsertId();
    }

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (3, 'New Landlord Verification Request', 'Landlord submitted a verification request.', 'VERIFICATION', ?)");
    $nStmt->execute([$verificationId]);

    echo json_encode(["success" => true, "message" => "Verification document submitted successfully. Status: PENDING"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
