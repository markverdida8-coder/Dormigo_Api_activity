<?php
require_once 'db.php';
try {
    $pdo->exec("ALTER TABLE notifications ADD COLUMN IF NOT EXISTS reference_id INTEGER;");
    echo json_encode(["success" => true, "message" => "Added reference_id to notifications."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
