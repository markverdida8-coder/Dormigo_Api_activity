<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS cash_enabled BOOLEAN DEFAULT TRUE;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS gcash_qr_code VARCHAR(500) DEFAULT NULL;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS gcash_updated_at TIMESTAMP DEFAULT NULL;
    ");
    echo json_encode(["success" => true, "message" => "Boarding house payment methods migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
