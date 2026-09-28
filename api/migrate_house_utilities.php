<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS free_electricity BOOLEAN DEFAULT false;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS electricity_rate NUMERIC(10,2) DEFAULT 0.00;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS free_water BOOLEAN DEFAULT false;
        ALTER TABLE boarding_houses ADD COLUMN IF NOT EXISTS water_rate NUMERIC(10,2) DEFAULT 0.00;
    ");
    echo json_encode(["success" => true, "message" => "Boarding houses utilities migration completed successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
