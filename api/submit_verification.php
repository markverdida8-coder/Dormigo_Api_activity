<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$house_id = isset($_POST['house_id']) ? (int)$_POST['house_id'] : 0;
$landlord_id = isset($_POST['landlord_id']) ? (int)$_POST['landlord_id'] : 0;

if ($house_id <= 0 || $landlord_id <= 0) {
    echo json_encode(["success" => false, "message" => "Valid house_id and landlord_id are required."]);
    exit();
}

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

try {
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
