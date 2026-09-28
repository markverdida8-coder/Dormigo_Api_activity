<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS payment_due_day INTEGER DEFAULT 1;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS advance_months INTEGER DEFAULT 1;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS security_deposit_months INTEGER DEFAULT 1;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS utility_deposit NUMERIC(10,2) DEFAULT 0.00;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS other_fees NUMERIC(10,2) DEFAULT 0.00;
    ");
    echo json_encode(["success" => true, "message" => "Boarding houses payment settings migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
