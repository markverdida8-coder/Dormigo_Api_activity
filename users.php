<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (isset($_GET['user_id'])) {
        $userId = (int)$_GET['user_id'];
        $stmt = $pdo->prepare("SELECT user_id, full_name, email, phone, user_type, profile_image FROM users WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch();
        if ($user) {
            echo json_encode(["success" => true, "data" => [$user]]);
        } else {
            echo json_encode(["success" => false, "message" => "User not found."]);
        }
    } else {
        $stmt = $pdo->query("SELECT user_id, full_name, email, phone, user_type, profile_image FROM users ORDER BY user_id DESC");
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
}
?>
