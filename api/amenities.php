<?php
require_once 'db.php';

$stmt = $pdo->query("SELECT * FROM amenities ORDER BY amenity_id ASC");
echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
?>
