<?php
require_once 'db.php';
try {
    $pdo->exec("
        INSERT INTO boarding_house_verification (house_id, verification_status)
        SELECT bh.house_id, 'PENDING'
        FROM boarding_houses bh
        WHERE NOT EXISTS (
            SELECT 1 FROM boarding_house_verification bhv WHERE bhv.house_id = bh.house_id
        );
    ");
    echo json_encode(["success" => true, "message" => "Synced all existing boarding houses into verification table."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
