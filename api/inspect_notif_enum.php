<?php
require_once 'db.php';
$enumStmt = $pdo->query("SELECT e.enumlabel FROM pg_enum e JOIN pg_type t ON e.enumtypid = t.oid WHERE t.typname = 'notif_type_enum'");
echo json_encode(["enum_values" => $enumStmt->fetchAll(PDO::FETCH_COLUMN)]);
?>
