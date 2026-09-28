<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE rooms ADD COLUMN IF NOT EXISTS utility_deposit NUMERIC(10,2) DEFAULT 0.00;
    ");
    echo json_encode(["success" => true, "message" => "Rooms utility_deposit migration completed."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
