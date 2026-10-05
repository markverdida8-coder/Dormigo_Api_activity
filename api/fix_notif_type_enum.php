<?php
require_once 'db.php';
try {
    // Add 'VERIFICATION' to notif_type_enum if not present
    $pdo->exec("ALTER TYPE notif_type_enum ADD VALUE IF NOT EXISTS 'VERIFICATION'");
    echo json_encode(["success" => true, "message" => "notif_type_enum updated with 'VERIFICATION'."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
