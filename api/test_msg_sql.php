<?php
require_once "db.php";
$userId = 5;

try {
    $sql = "SELECT
                c.other_user_id,
                u.full_name AS other_user_name,
                u.user_type AS other_user_type,
                m.message_id,
                COALESCE(m.house_id, (SELECT house_id FROM boarding_houses WHERE landlord_id = u.user_id OR landlord_id = :user_id LIMIT 1), 0) AS house_id,
                COALESCE((SELECT house_name FROM boarding_houses WHERE house_id = COALESCE(m.house_id, (SELECT house_id FROM boarding_houses WHERE landlord_id = u.user_id OR landlord_id = :user_id LIMIT 1))), 'Boarding House') AS house_name,
                m.message_text,
                m.created_at,
                COALESCE(unread.unread_count, 0) AS unread_count,
                CASE WHEN COALESCE(unread.unread_count, 0) > 0 THEN false ELSE true END AS is_read
            FROM (
                SELECT DISTINCT ON (
                    CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END
                )
                    message_id,
                    CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END AS other_user_id
                FROM messages
                WHERE sender_id = :user_id OR receiver_id = :user_id
                ORDER BY CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END, created_at DESC
            ) c
            JOIN messages m ON c.message_id = m.message_id
            JOIN users u ON c.other_user_id = u.user_id
            LEFT JOIN (
                SELECT sender_id AS other_user_id, COUNT(*) AS unread_count
                FROM messages
                WHERE receiver_id = :user_id AND is_read = false
                GROUP BY sender_id
            ) unread ON c.other_user_id = unread.other_user_id
            ORDER BY m.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $userId]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["success" => true, "data" => $data]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "error" => $e->getMessage()]);
}
?>