<?php
require_once 'db.php';
$stmt = $pdo->query("SELECT COUNT(*) FROM messages");
$count = $stmt->fetchColumn();
echo json_encode(["message_count" => $count, "messages" => $pdo->query("SELECT * FROM messages")->fetchAll()]);
?>
