<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'landlord_verifications'");
echo json_encode(["columns" => $stmt->fetchAll()]);
?>
