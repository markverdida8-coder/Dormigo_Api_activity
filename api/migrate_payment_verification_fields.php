<?php
require_once 'db.php';
try {
    // Add SUBMITTED and REJECTED to payment_status_enum
    $pdo->exec("ALTER TYPE payment_status_enum ADD VALUE IF NOT EXISTS 'SUBMITTED';");
    $pdo->exec("ALTER TYPE payment_status_enum ADD VALUE IF NOT EXISTS 'REJECTED';");

    // Add verification columns to payments table
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS proof_image VARCHAR(500) NULL;");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) NULL;");
    $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS verified_at TIMESTAMP NULL;");

    echo json_encode(["success" => true, "message" => "Payment verification fields migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
