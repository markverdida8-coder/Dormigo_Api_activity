<?php
require_once 'db.php';
try {
    $pdo->exec("ALTER TYPE notif_type_enum ADD VALUE IF NOT EXISTS 'BOOKING';");
    $pdo->exec("ALTER TYPE notif_type_enum ADD VALUE IF NOT EXISTS 'CHAT';");
    $pdo->exec("ALTER TYPE notif_type_enum ADD VALUE IF NOT EXISTS 'REVIEW';");
    echo json_encode(["success" => true, "message" => "Updated notif_type_enum"]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
