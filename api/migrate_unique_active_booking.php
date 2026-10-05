<?php
require_once 'db.php';

try {
    $pdo->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_unique_active_student_booking
        ON bookings (user_id)
        WHERE status IN ('PENDING', 'APPROVED');
    ");
    echo json_encode(["success" => true, "message" => "Migration successful: unique active booking index created."]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Migration error: " . $e->getMessage()]);
}
?>
