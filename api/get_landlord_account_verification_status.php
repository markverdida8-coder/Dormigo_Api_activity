<?php
require_once 'db.php';
$landlord_id = isset($_GET['landlord_id']) ? (int)$_GET['landlord_id'] : 0;
if ($landlord_id <= 0) {
    echo json_encode(["success" => false, "message" => "landlord_id required."]);
    exit();
}
try {
    $stmt = $pdo->prepare("SELECT verification_status, rejection_reason, document_path FROM landlord_verifications WHERE landlord_id = :landlord_id ORDER BY verification_id DESC LIMIT 1");
    $stmt->execute(['landlord_id' => $landlord_id]);
    $verif = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($verif) {
        echo json_encode(["success" => true, "data" => $verif]);
    } else {
        echo json_encode(["success" => true, "data" => ["verification_status" => "NOT_SUBMITTED"]]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
