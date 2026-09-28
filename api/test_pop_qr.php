<?php
require_once "db.php";
try {
    $stmt = $pdo->prepare("UPDATE boarding_houses SET gcash_qr_code = 'uploads/gcash_qr/qr_house_11_test.jpg', gcash_updated_at = CURRENT_TIMESTAMP WHERE house_id = 11");
    $stmt->execute();
    echo json_encode(["success" => true]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>