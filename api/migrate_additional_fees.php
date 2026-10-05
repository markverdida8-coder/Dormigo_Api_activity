<?php
require_once 'db.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS property_additional_fees (
            fee_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            fee_name VARCHAR(100) NOT NULL,
            fee_type VARCHAR(50) NOT NULL DEFAULT 'ONE_TIME',
            amount NUMERIC(10,2) NOT NULL DEFAULT 0.00,
            description TEXT DEFAULT NULL,
            is_enabled BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- Migrate data from property_one_time_fees if exists
        INSERT INTO property_additional_fees (house_id, fee_name, fee_type, amount, description)
        SELECT house_id, fee_name, 'ONE_TIME', amount, description
        FROM property_one_time_fees
        ON CONFLICT DO NOTHING;

        -- Migrate data from property_monthly_charges if exists
        INSERT INTO property_additional_fees (house_id, fee_name, fee_type, amount, description)
        SELECT house_id, charge_name, 'MONTHLY', monthly_amount, description
        FROM property_monthly_charges
        ON CONFLICT DO NOTHING;
    ");
    echo json_encode(["success" => true, "message" => "property_additional_fees table created and migrated."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
