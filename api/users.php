<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['user_id'])) {
        $userId = (int)$_GET['user_id'];
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, user_type, profile_image FROM public.users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(["success" => true, "data" => [$user]]);
        } else {
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
    } else {
        $sql = "SELECT user_id, full_name, email, phone, user_type, profile_image FROM public.users WHERE 1=1";
        $params = [];

        if (isset($_GET['search']) && !empty(trim($_GET['search']))) {
            $sql .= " AND (LOWER(full_name) LIKE LOWER(:search) OR LOWER(email) LIKE LOWER(:search))";
            $params['search'] = '%' . trim($_GET['search']) . '%';
        }

        if (isset($_GET['user_type']) && !empty(trim($_GET['user_type']))) {
            $sql .= " AND user_type = :user_type::user_type_enum";
            $params['user_type'] = strtoupper(trim($_GET['user_type']));
        }

        if (isset($_GET['exclude_user_id'])) {
            $sql .= " AND user_id != :exclude_user_id";
            $params['exclude_user_id'] = (int)$_GET['exclude_user_id'];
        }

        $sql .= " ORDER BY full_name ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        echo json_encode(["success" => true, "data" => $users]);
    }
} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['user_id'])) {
        echo json_encode(["success" => false, "message" => "User ID is required."]);
        exit();
    }
    $userId = (int)$data['user_id'];
    $fields = [];
    $params = ['user_id' => $userId];

    if (isset($data['full_name'])) {
        $fields[] = "full_name = :full_name";
        $params['full_name'] = trim($data['full_name']);
    }
    if (isset($data['email'])) {
        $fields[] = "email = :email";
        $params['email'] = trim($data['email']);
    }
    if (isset($data['phone'])) {
        $fields[] = "phone = :phone";
        $params['phone'] = trim($data['phone']);
    }

    if (empty($fields)) {
        echo json_encode(["success" => false, "message" => "No fields provided to update."]);
        exit();
    }

    $sql = "UPDATE users SET " . implode(", ", $fields) . " WHERE user_id = :user_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(["success" => true, "message" => "User profile updated successfully."]);
} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['user_id'])) {
        echo json_encode(["success" => false, "message" => "User ID is required."]);
        exit();
    }
    $userId = (int)$data['user_id'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$userId, $userId]);
        $pdo->prepare("DELETE FROM bookings WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM boarding_houses WHERE landlord_id = ?")->execute([$userId]);
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Account deleted successfully."]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
