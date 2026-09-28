<?php
require_once 'db.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS muted_conversations (
            user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            other_user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            mute_until TIMESTAMP DEFAULT '2099-12-31 23:59:59',
            PRIMARY KEY (user_id, other_user_id)
        );
    ");
    echo json_encode(["success" => true, "message" => "Muted conversations table created successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
