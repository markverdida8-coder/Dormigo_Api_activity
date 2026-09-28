<?php
require_once 'db.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS landlord_verifications (
            verification_id SERIAL PRIMARY KEY,
            landlord_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            document_path VARCHAR(500) NOT NULL,
            verification_status VARCHAR(50) DEFAULT 'PENDING',
            rejection_reason TEXT DEFAULT NULL,
            reviewed_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL,
            reviewed_at TIMESTAMP DEFAULT NULL,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
    echo json_encode(["success" => true, "message" => "landlord_verifications table created successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
