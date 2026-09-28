<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : 0;
if ($house_id <= 0) {
    echo json_encode(["success" => false, "message" => "house_id is required."]);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM boarding_house_verification WHERE house_id = :house_id");
    $stmt->execute(['house_id' => $house_id]);
    $verification = $stmt->fetch();

    if ($verification) {
        echo json_encode(["success" => true, "data" => $verification]);
    } else {
        echo json_encode(["success" => true, "data" => ["verification_status" => "NOT_SUBMITTED"]]);
    }
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
