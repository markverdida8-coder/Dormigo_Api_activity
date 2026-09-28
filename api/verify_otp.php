<?php
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['email']) || !isset($data['otp_code'])) {
    echo json_encode(["success" => false, "message" => "Email and OTP code are required."]);
    exit();
}

$email = trim($data['email']);
$otpCode = trim($data['otp_code']);

try {
    $stmt = $pdo->prepare("SELECT * FROM password_reset_tokens WHERE LOWER(email) = LOWER(:email) AND otp_code = :otp_code AND used = false ORDER BY id DESC LIMIT 1");
    $stmt->execute(['email' => $email, 'otp_code' => $otpCode]);
    $token = $stmt->fetch();

    if (!$token) {
        echo json_encode(["success" => false, "message" => "Invalid verification code."]);
        exit();
    }

    if (strtotime($token['expires_at']) < time()) {
        echo json_encode(["success" => false, "message" => "Verification code has expired."]);
        exit();
    }

    echo json_encode([
        "success" => true,
        "message" => "OTP verified successfully."
    ]);
} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
