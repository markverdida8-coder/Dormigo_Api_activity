<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!isset($_GET['user_id'])) {
        echo json_encode(["success" => false, "message" => "user_id required"]);
        exit();
    }

    $user_id = $_GET['user_id'];

    // Get unread count first
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND is_read = false");
    $stmtCount->execute(['user_id' => $user_id]);
    $unreadCount = $stmtCount->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY is_read ASC, created_at DESC");
    $stmt->execute(['user_id' => $user_id]);
    $notifications = $stmt->fetchAll();

    echo json_encode([
        "success" => true,
        "unread_count" => $unreadCount,
        "data" => $notifications
    ]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['user_id']) || !isset($data['title']) || !isset($data['message']) || !isset($data['type'])) {
        echo json_encode(["success" => false, "message" => "Missing fields"]);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (:user_id, :title, :message, :type, :reference_id)");
    $stmt->execute([
        'user_id' => $data['user_id'],
        'title' => $data['title'],
        'message' => $data['message'],
        'type' => $data['type'],
        'reference_id' => $data['reference_id'] ?? null
    ]);
    echo json_encode(["success" => true]);

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['notification_id'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = true WHERE notification_id = :notification_id");
        $stmt->execute(['notification_id' => $data['notification_id']]);
    } elseif (isset($data['user_id']) && isset($data['type'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = true WHERE user_id = :user_id AND type = :type");
        $stmt->execute(['user_id' => $data['user_id'], 'type' => $data['type']]);
    } elseif (isset($data['user_id'])) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = true WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $data['user_id']]);
    }
    echo json_encode(["success" => true]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (isset($data['notification_id'])) {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE notification_id = :notification_id");
        $stmt->execute(['notification_id' => $data['notification_id']]);
    }
    echo json_encode(["success" => true]);
}
?>