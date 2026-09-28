<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "user_id is required."]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM student_verifications WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $verif = $stmt->fetch();

    if ($verif) {
        echo json_encode(["success" => true, "data" => $verif]);
    } else {
        echo json_encode(["success" => true, "data" => ["verification_status" => "NOT_SUBMITTED"]]);
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
