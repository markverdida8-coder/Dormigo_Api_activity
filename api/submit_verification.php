<?php
require_once 'db.php';
global $pdo;

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$house_id = isset($_POST['house_id']) ? (int)$_POST['house_id'] : 0;
$client_landlord_id = isset($_POST['landlord_id']) ? (int)$_POST['landlord_id'] : 0;

if ($house_id <= 0) {
    echo json_encode(["success" => false, "message" => "Valid house_id is required."]);
    exit();
}

try {
    // 1. Query boarding house to get its actual landlord_id (Do not trust client landlord_id blindly)
    $houseStmt = $pdo->prepare("SELECT house_id, landlord_id FROM boarding_houses WHERE house_id = ?");
    $houseStmt->execute([$house_id]);
    $house = $houseStmt->fetch();

    if (!$house) {
        echo json_encode(["success" => false, "message" => "Boarding house not found."]);
        exit();
    }

    $actual_landlord_id = (int)$house['landlord_id'];

    if ($client_landlord_id > 0 && $client_landlord_id !== $actual_landlord_id) {
        echo json_encode(["success" => false, "message" => "Unauthorized: Provided landlord_id does not own this property."]);
        exit();
    }

    // 2. Check landlord account verification status in landlord_verifications
    $verifStmt = $pdo->prepare("SELECT verification_status FROM landlord_verifications WHERE landlord_id = ? ORDER BY submitted_at DESC LIMIT 1");
    $verifStmt->execute([$actual_landlord_id]);
    $verifRec = $verifStmt->fetch();

    $vStatus = $verifRec ? strtoupper(trim($verifRec['verification_status'])) : '';

    if ($vStatus !== 'VERIFIED' && $vStatus !== 'APPROVED') {
        echo json_encode([
            "success" => false,
            "message" => "Your landlord account must be verified by an administrator before you can submit a boarding house for verification."
        ]);
        exit();
    }

    // 3. Handle file uploads only if verified
    $uploadDir = '../uploads/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $valid_id_path = null;
    $proof_document_path = null;

    if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
        $fileName = 'valid_id_' . $house_id . '_' . time() . '.jpg';
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $targetPath)) {
            $valid_id_path = 'uploads/' . $fileName;
        }
    }

    if (isset($_FILES['proof_document']) && $_FILES['proof_document']['error'] === UPLOAD_ERR_OK) {
        $fileName = 'proof_' . $house_id . '_' . time() . '.jpg';
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['proof_document']['tmp_name'], $targetPath)) {
            $proof_document_path = 'uploads/' . $fileName;
        }
    }

    $stmt = $pdo->prepare("SELECT verification_id FROM boarding_house_verification WHERE house_id = :house_id");
    $stmt->execute(['house_id' => $house_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $updateSql = "UPDATE boarding_house_verification SET verification_status = 'PENDING', rejection_reason = NULL, reviewed_by = NULL, reviewed_at = NULL, submitted_at = CURRENT_TIMESTAMP";
        $params = ['house_id' => $house_id];

        if ($valid_id_path) {
            $updateSql .= ", valid_id_path = :valid_id";
            $params['valid_id'] = $valid_id_path;
        }
        if ($proof_document_path) {
            $updateSql .= ", proof_document_path = :proof";
            $params['proof'] = $proof_document_path;
        }
        $updateSql .= " WHERE house_id = :house_id";

        $update = $pdo->prepare($updateSql);
        $update->execute($params);
    } else {
        $insert = $pdo->prepare("INSERT INTO boarding_house_verification (house_id, valid_id_path, proof_document_path, verification_status) VALUES (:house_id, :valid_id, :proof, 'PENDING')");
        $insert->execute([
            'house_id' => $house_id,
            'valid_id' => $valid_id_path,
            'proof' => $proof_document_path
        ]);
    }

    echo json_encode(["success" => true, "message" => "Verification submitted successfully. Status: PENDING"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
