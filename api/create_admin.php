<?php
require_once 'db.php';
$email = 'admin@dormigo.com';
$password = 'AdminPassword123!';
$hashed = password_hash($password, PASSWORD_DEFAULT);
$fullName = 'System Administrator';

try {
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email)");
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if ($admin) {
        $upd = $pdo->prepare("UPDATE users SET password = :password, user_type = 'ADMIN'::user_type_enum WHERE user_id = :user_id");
        $upd->execute(['password' => $hashed, 'user_id' => $admin['user_id']]);
        echo json_encode(["success" => true, "message" => "Admin account updated successfully.", "email" => $email, "password" => $password]);
    } else {
        $ins = $pdo->prepare("INSERT INTO users (full_name, email, password, user_type) VALUES (:full_name, :email, :password, 'ADMIN'::user_type_enum)");
        $ins->execute(['full_name' => $fullName, 'email' => $email, 'password' => $hashed]);
        echo json_encode(["success" => true, "message" => "Admin account updated successfully.", "email" => $email, "password" => $password]);
    }
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
