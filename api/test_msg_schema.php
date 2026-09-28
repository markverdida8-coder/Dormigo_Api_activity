<?php
require_once "db.php";
try {
    $stmt = $pdo->query("SELECT column_name, column_default, data_type FROM information_schema.columns WHERE table_name = 'messages'");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "columns" => $cols]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>