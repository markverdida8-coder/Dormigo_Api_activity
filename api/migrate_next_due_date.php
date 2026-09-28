<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE bookings ADD COLUMN IF NOT EXISTS next_due_date DATE;
    ");
    echo json_encode(["success" => true, "message" => "Bookings next_due_date migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
