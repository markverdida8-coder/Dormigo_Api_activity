<?php
require_once 'db.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS deleted_conversations (
            user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            other_user_id INTEGER NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
            deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, other_user_id)
        );
    ");
    echo json_encode(["success" => true, "message" => "deleted_conversations table created successfully."]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
