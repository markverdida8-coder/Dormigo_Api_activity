<?php
require_once 'db.php';
require_once '../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['email']) || trim($data['email']) === '') {
    echo json_encode(["success" => false, "message" => "Email address is required."]);
    exit();
}

$email = trim($data['email']);

try {
    $stmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE LOWER(email) = LOWER(:email)");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(["success" => false, "message" => "No account found with this email address."]);
        exit();
    }

    $userId = $user['user_id'];

    // Invalidate old tokens for this user
    $delStmt = $pdo->prepare("DELETE FROM password_reset_tokens WHERE user_id = :user_id");
    $delStmt->execute(['user_id' => $userId]);

    // Generate 6-digit OTP
    $otpCode = sprintf('%06d', mt_rand(0, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 300); // 5 minutes

    $insStmt = $pdo->prepare("INSERT INTO password_reset_tokens (user_id, email, otp_code, expires_at, used) VALUES (:user_id, :email, :otp_code, :expires_at, false)");
    $insStmt->execute([
        'user_id' => $userId,
        'email' => $email,
        'otp_code' => $otpCode,
        'expires_at' => $expiresAt
    ]);

    // Send email via PHPMailer using Gmail SMTP
    $mail = new PHPMailer(true);

    // Server settings
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'dormigo.system@gmail.com';
    $mail->Password   = 'your_gmail_app_password';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Recipients
    $mail->setFrom('dormigo.system@gmail.com', 'Dormigo Team');
    $mail->addAddress($email, $user['full_name']);

    // Content
    $mail->isHTML(false);
    $mail->Subject = 'Dormigo Password Reset Code';
    $mail->Body    = "Hello,\n\nYou requested to reset your Dormigo password.\n\nYour verification code is:\n\n" . $otpCode . "\n\nThis code expires in 5 minutes.\n\nIf you did not request this, simply ignore this email.\n\nDormigo Team";

    $mail->send();

    echo json_encode([
        "success" => true,
        "message" => "Verification code sent to your email."
    ]);
} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => "Email sending failed: " . ($mail->ErrorInfo ?? $e->getMessage())
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
