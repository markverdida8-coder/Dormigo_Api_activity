<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT column_name, data_type, udt_name FROM information_schema.columns WHERE table_name = 'notifications'");
echo json_encode(["columns" => $stmt->fetchAll()]);

$enumStmt = $pdo->query("SELECT e.enumlabel FROM pg_enum e JOIN pg_type t ON e.enumtypid = t.oid WHERE t.typname = 'notification_type_enum'");
echo json_encode(["enum_values" => $enumStmt->fetchAll(PDO::FETCH_COLUMN)]);
?>
