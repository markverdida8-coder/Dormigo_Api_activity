<?php
require_once 'db.php';
try {
    $pdo->exec("
        ALTER TABLE student_verifications ADD COLUMN IF NOT EXISTS student_id INTEGER REFERENCES users(user_id) ON DELETE CASCADE;
        ALTER TABLE student_verifications ADD COLUMN IF NOT EXISTS user_id INTEGER REFERENCES users(user_id) ON DELETE CASCADE;
        ALTER TABLE student_verifications ADD COLUMN IF NOT EXISTS reviewed_by INTEGER REFERENCES users(user_id) ON DELETE SET NULL;
        ALTER TABLE student_verifications ADD COLUMN IF NOT EXISTS reviewed_at TIMESTAMP DEFAULT NULL;

        UPDATE student_verifications SET student_id = user_id WHERE student_id IS NULL AND user_id IS NOT NULL;
        UPDATE student_verifications SET user_id = student_id WHERE user_id IS NULL AND student_id IS NOT NULL;
    ");
    echo json_encode(["success" => true, "message" => "student_verifications schema synchronized successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
