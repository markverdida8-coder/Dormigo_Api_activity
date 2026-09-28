<?php
require_once 'db.php';

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['email']) || !isset($data['otp_code']) || !isset($data['new_password'])) {
    echo json_encode(["success" => false, "message" => "Email, OTP code, and new password are required."]);
    exit();
}

$email = trim($data['email']);
$otpCode = trim($data['otp_code']);
$newPassword = $data['new_password'];

if (strlen($newPassword) < 8) {
    echo json_encode(["success" => false, "message" => "Password must be at least 8 characters long."]);
    exit();
}

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

    // Hash new password securely
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();

    // Update user password
    $updStmt = $pdo->prepare("UPDATE users SET password = :password WHERE user_id = :user_id");
    $updStmt->execute([
        'password' => $hashedPassword,
        'user_id' => $token['user_id']
    ]);

    // Mark token as used
    $useStmt = $pdo->prepare("UPDATE password_reset_tokens SET used = true WHERE id = :id");
    $useStmt->execute(['id' => $token['id']]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Password reset successfully. You can now log in."
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
