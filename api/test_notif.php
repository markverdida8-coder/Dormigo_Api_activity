<?php
require_once "db.php";
try {
    $stmt = $pdo->query("SELECT * FROM notifications");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(["success" => true, "data" => $cols]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>