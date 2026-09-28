<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS advance_months INTEGER DEFAULT 1;
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS deposit_months INTEGER DEFAULT 1;
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS other_fees NUMERIC(10,2) DEFAULT 0.00;
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS other_fees_description VARCHAR(255) DEFAULT '';
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS deposit_refund_policy TEXT DEFAULT '';
    ");
    echo json_encode(["success" => true, "message" => "Rooms migration completed successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
