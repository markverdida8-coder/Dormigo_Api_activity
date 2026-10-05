<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'get_mute_status') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $otherUserId = (int)($_GET['other_user_id'] ?? 0);
        if ($userId <= 0 || $otherUserId <= 0) {
            echo json_encode(["success" => false, "message" => "user_id and other_user_id required."]);
            exit();
        }
        $stmt = $pdo->prepare("SELECT mute_until FROM muted_conversations WHERE user_id = :user_id AND other_user_id = :other_user_id AND mute_until > CURRENT_TIMESTAMP");
        $stmt->execute(['user_id' => $userId, 'other_user_id' => $otherUserId]);
        $muteUntil = $stmt->fetchColumn();

        echo json_encode([
            "success" => true,
            "is_muted" => !empty($muteUntil),
            "mute_until" => $muteUntil ? $muteUntil : null
        ]);
        exit();
    }

    if (isset($_GET['user_id']) && isset($_GET['other_user_id'])) {
        // Fetch full message thread between two users, filtered by user's cleared_at timestamp
        $userId = (int)$_GET['user_id'];
        $otherUserId = (int)$_GET['other_user_id'];

        // Automatically mark incoming unread messages as read for this receiver
        $markRead = $pdo->prepare("UPDATE messages SET is_read = true WHERE receiver_id = :user_id AND sender_id = :other_user_id AND is_read = false");
        $markRead->execute(['user_id' => $userId, 'other_user_id' => $otherUserId]);

        // Also mark CHAT notifications for this user as read
        $markNotif = $pdo->prepare("UPDATE notifications SET is_read = true WHERE user_id = :user_id AND type = 'CHAT' AND reference_id = :other_user_id");
        $markNotif->execute(['user_id' => $userId, 'other_user_id' => $otherUserId]);

        $stmt = $pdo->prepare("SELECT m.*,
                                      u1.full_name AS sender_name,
                                      u2.full_name AS receiver_name
                               FROM messages m
                               JOIN users u1 ON m.sender_id = u1.user_id
                               JOIN users u2 ON m.receiver_id = u2.user_id
                               WHERE ((m.sender_id = :u1 AND m.receiver_id = :u2) OR (m.sender_id = :u2 AND m.receiver_id = :u1))
                                 AND m.created_at > COALESCE((SELECT dc.deleted_at FROM deleted_conversations dc WHERE dc.user_id = :u1 AND dc.other_user_id = :u2), '1970-01-01'::timestamp)
                               ORDER BY m.created_at ASC");
        $stmt->execute(['u1' => $userId, 'u2' => $otherUserId]);
        echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);

    } elseif (isset($_GET['user_id'])) {
        // Fetch list of recent chat conversations for a user with computed unread count, read status, and is_muted
        $userId = (int)$_GET['user_id'];

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
                    CASE WHEN COALESCE(unread.unread_count, 0) > 0 THEN false ELSE true END AS is_read,
                    COALESCE((SELECT TRUE FROM muted_conversations mc WHERE mc.user_id = :user_id AND mc.other_user_id = c.other_user_id AND mc.mute_until > CURRENT_TIMESTAMP), FALSE) AS is_muted
                FROM (
                    SELECT DISTINCT ON (
                        CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END
                    )
                        message_id,
                        CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END AS other_user_id
                    FROM messages m_inner
                    WHERE (sender_id = :user_id OR receiver_id = :user_id)
                      AND m_inner.created_at > COALESCE((SELECT dc.deleted_at FROM deleted_conversations dc WHERE dc.user_id = :user_id AND dc.other_user_id = CASE WHEN sender_id = :user_id THEN receiver_id ELSE sender_id END), '1970-01-01'::timestamp)
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
        echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);
    } else {
        echo json_encode(["success" => false, "message" => "user_id is required."]);
    }

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? ($_POST['action'] ?? '');

    if ($action === 'mute_conversation') {
        $userId = (int)($data['user_id'] ?? 0);
        $otherUserId = (int)($data['other_user_id'] ?? 0);
        $hours = (int)($data['duration_hours'] ?? 24);

        if ($userId <= 0 || $otherUserId <= 0) {
            echo json_encode(["success" => false, "message" => "user_id and other_user_id are required."]);
            exit();
        }

        if ($hours >= 800000) {
            $muteUntil = '2099-12-31 23:59:59';
        } else {
            $muteUntil = date('Y-m-d H:i:s', time() + ($hours * 3600));
        }

        $stmt = $pdo->prepare("INSERT INTO muted_conversations (user_id, other_user_id, mute_until)
                                VALUES (:user_id, :other_user_id, :mute_until)
                                ON CONFLICT (user_id, other_user_id)
                                DO UPDATE SET mute_until = EXCLUDED.mute_until");
        $stmt->execute([
            'user_id' => $userId,
            'other_user_id' => $otherUserId,
            'mute_until' => $muteUntil
        ]);

        echo json_encode(["success" => true, "message" => "Conversation muted.", "mute_until" => $muteUntil]);
        exit();
    }

    if ($action === 'unmute_conversation') {
        $userId = (int)($data['user_id'] ?? 0);
        $otherUserId = (int)($data['other_user_id'] ?? 0);

        if ($userId <= 0 || $otherUserId <= 0) {
            echo json_encode(["success" => false, "message" => "user_id and other_user_id are required."]);
            exit();
        }

        $stmt = $pdo->prepare("DELETE FROM muted_conversations WHERE user_id = :user_id AND other_user_id = :other_user_id");
        $stmt->execute(['user_id' => $userId, 'other_user_id' => $otherUserId]);

        echo json_encode(["success" => true, "message" => "Conversation unmuted."]);
        exit();
    }

    if (!isset($data['sender_id']) || !isset($data['receiver_id']) || !isset($data['message_text'])) {
        echo json_encode(["success" => false, "message" => "sender_id, receiver_id, and message_text are required."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, house_id, message_text, is_read)
                                VALUES (:sender_id, :receiver_id, :house_id, :message_text, false) RETURNING message_id, created_at");
        $stmt->execute([
            'sender_id' => $data['sender_id'],
            'receiver_id' => $data['receiver_id'],
            'house_id' => $data['house_id'] ?? null,
            'message_text' => trim($data['message_text'])
        ]);

        $result = $stmt->fetch();

        // Send notification ONLY if the receiver has NOT muted the sender
        $muteCheck = $pdo->prepare("SELECT COUNT(*) FROM muted_conversations WHERE user_id = :receiver_id AND other_user_id = :sender_id AND mute_until > CURRENT_TIMESTAMP");
        $muteCheck->execute(['receiver_id' => $data['receiver_id'], 'sender_id' => $data['sender_id']]);
        $isMuted = $muteCheck->fetchColumn() > 0;

        if (!$isMuted) {
            $info = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $info->execute([$data['sender_id']]);
            $senderName = $info->fetchColumn();
            if ($senderName) {
                $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, 'CHAT', ?)");
                $nStmt->execute([$data['receiver_id'], 'New Message', 'You received a new message from ' . $senderName . '.', $data['sender_id']]);
            }
        }

        echo json_encode([
            "success" => true,
            "message" => "Message sent successfully.",
            "message_id" => $result['message_id'],
            "created_at" => $result['created_at']
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $otherUserId = isset($data['other_user_id']) ? (int)$data['other_user_id'] : 0;

    if ($userId <= 0 || $otherUserId <= 0) {
        echo json_encode(["success" => false, "message" => "user_id and other_user_id are required."]);
        exit();
    }

    try {
        // Record user-specific clear point (deleted_at)
        $stmt = $pdo->prepare("INSERT INTO deleted_conversations (user_id, other_user_id, deleted_at)
                                VALUES (:u1, :u2, CURRENT_TIMESTAMP)
                                ON CONFLICT (user_id, other_user_id)
                                DO UPDATE SET deleted_at = CURRENT_TIMESTAMP");
        $stmt->execute(['u1' => $userId, 'u2' => $otherUserId]);

        echo json_encode(["success" => true, "message" => "Conversation cleared for current user."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
