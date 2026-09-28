<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_description VARCHAR(255) DEFAULT 'Monthly Rent';
    ");
    echo json_encode(["success" => true, "message" => "Payments description migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
