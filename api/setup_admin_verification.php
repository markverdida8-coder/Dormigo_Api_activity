<?php
require_once 'db.php';
try {
    $pdo->exec("ALTER TYPE user_type_enum ADD VALUE IF NOT EXISTS 'ADMIN';");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS boarding_house_verification (
            verification_id SERIAL PRIMARY KEY,
            house_id INTEGER REFERENCES boarding_houses(house_id) ON DELETE CASCADE,
            valid_id_path VARCHAR(255),
            proof_document_path VARCHAR(255),
            verification_status VARCHAR(20) DEFAULT 'PENDING',
            rejection_reason TEXT,
            reviewed_by INTEGER REFERENCES users(user_id),
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reviewed_at TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS student_verifications (
            verification_id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(user_id) ON DELETE CASCADE,
            student_id_path VARCHAR(255),
            verification_status VARCHAR(20) DEFAULT 'PENDING',
            rejection_reason TEXT,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email)");
    $stmt->execute(['email' => 'admin@dormigo.com']);
    if (!$stmt->fetch()) {
        $insert = $pdo->prepare("INSERT INTO users (full_name, email, password, phone, user_type) VALUES (:full_name, :email, :password, :phone, :user_type::user_type_enum)");
        $insert->execute([
            'full_name' => 'System Administrator',
            'email' => 'admin@dormigo.com',
            'password' => 'admin123',
            'phone' => '09123456789',
            'user_type' => 'ADMIN'
        ]);
    }
    echo json_encode(["success" => true, "message" => "Verification tables and admin user set up successfully."]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
