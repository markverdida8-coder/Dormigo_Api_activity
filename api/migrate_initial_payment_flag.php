<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE bookings ADD COLUMN IF NOT EXISTS initial_payment_completed BOOLEAN DEFAULT false;
    ");
    echo json_encode(["success" => true, "message" => "Bookings initial_payment_completed migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
