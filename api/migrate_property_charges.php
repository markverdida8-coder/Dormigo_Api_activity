<?php
require_once 'db.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS property_deposits (
            deposit_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            deposit_name VARCHAR(100) NOT NULL,
            deposit_type VARCHAR(50) NOT NULL, -- 'MONTHS' or 'FIXED'
            amount NUMERIC(10,2) NOT NULL, -- Holds number of months or fixed peso amount
            description TEXT DEFAULT NULL,
            is_enabled BOOLEAN DEFAULT TRUE,
            applies_to VARCHAR(50) DEFAULT 'ALL_ROOMS',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS property_monthly_charges (
            charge_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            charge_name VARCHAR(100) NOT NULL,
            monthly_amount NUMERIC(10,2) NOT NULL,
            description TEXT DEFAULT NULL,
            is_enabled BOOLEAN DEFAULT TRUE,
            applies_to VARCHAR(50) DEFAULT 'ALL_ROOMS',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS property_utilities (
            utility_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            utility_name VARCHAR(100) NOT NULL,
            charging_method VARCHAR(50) NOT NULL, -- 'FREE', 'FIXED_MONTHLY', 'CONSUMPTION_BASED'
            fixed_amount NUMERIC(10,2) DEFAULT 0.00,
            is_enabled BOOLEAN DEFAULT TRUE,
            applies_to VARCHAR(50) DEFAULT 'ALL_ROOMS',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS property_one_time_fees (
            fee_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            fee_name VARCHAR(100) NOT NULL,
            amount NUMERIC(10,2) NOT NULL,
            description TEXT DEFAULT NULL,
            is_enabled BOOLEAN DEFAULT TRUE,
            applies_to VARCHAR(50) DEFAULT 'ALL_ROOMS',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS property_optional_services (
            service_id SERIAL PRIMARY KEY,
            house_id INTEGER NOT NULL REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            service_name VARCHAR(100) NOT NULL,
            amount NUMERIC(10,2) NOT NULL,
            charge_frequency VARCHAR(50) DEFAULT 'MONTHLY', -- 'MONTHLY' or 'ONE_TIME'
            description TEXT DEFAULT NULL,
            is_enabled BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        -- Backward Compatibility Migration
        INSERT INTO property_deposits (house_id, deposit_name, deposit_type, amount, applies_to)
        SELECT house_id, 'Advance Payment', 'MONTHS', advance_months, 'ALL_ROOMS'
        FROM boarding_houses WHERE advance_months > 0;

        INSERT INTO property_deposits (house_id, deposit_name, deposit_type, amount, applies_to)
        SELECT house_id, 'Security Deposit', 'MONTHS', security_deposit_months, 'ALL_ROOMS'
        FROM boarding_houses WHERE security_deposit_months > 0;

        INSERT INTO property_deposits (house_id, deposit_name, deposit_type, amount, applies_to)
        SELECT house_id, 'Utility Deposit', 'FIXED', utility_deposit, 'ALL_ROOMS'
        FROM boarding_houses WHERE utility_deposit > 0;

        INSERT INTO property_one_time_fees (house_id, fee_name, amount, applies_to)
        SELECT house_id, 'Other Fees', other_fees, 'ALL_ROOMS'
        FROM boarding_houses WHERE other_fees > 0;

        INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount, applies_to)
        SELECT house_id, 'Electricity', CASE WHEN free_electricity = TRUE THEN 'FREE' ELSE 'FIXED_MONTHLY' END, electricity_rate, 'ALL_ROOMS'
        FROM boarding_houses;

        INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount, applies_to)
        SELECT house_id, 'Water', CASE WHEN free_water = TRUE THEN 'FREE' ELSE 'FIXED_MONTHLY' END, water_rate, 'ALL_ROOMS'
        FROM boarding_houses;

    ");
    echo json_encode(["success" => true, "message" => "Property charges tables created and data migrated successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
