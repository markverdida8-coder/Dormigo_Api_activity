<?php
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['full_name']) || !isset($data['email']) || !isset($data['password']) || !isset($data['user_type'])) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
    exit();
}

$fullName = trim($data['full_name']);
$email = trim($data['email']);
$password = trim($data['password']);
$phone = isset($data['phone']) ? trim($data['phone']) : null;
$userType = strtoupper(trim($data['user_type']));

try {
    // Check if email already exists
    $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email)");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        echo json_encode(["success" => false, "message" => "Email is already registered."]);
        exit();
    }

    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, phone, user_type) VALUES (:full_name, :email, :password, :phone, :user_type::user_type_enum) RETURNING user_id, full_name, email, phone, user_type");
    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'password' => $password,
        'phone' => $phone,
        'user_type' => $userType
    ]);

    $newUser = $stmt->fetch();

    echo json_encode([
        "success" => true,
        "message" => "Registration successful.",
        "user" => $newUser
    ]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
