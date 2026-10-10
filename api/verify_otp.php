<?php
require_once 'db.php';
require_once 'password_reset_security.php';

$data = json_decode(file_get_contents('php://input'), true);
if (
    !is_array($data)
    || !isset($data['email'], $data['otp_code'])
    || !is_string($data['email'])
    || !is_string($data['otp_code'])
) {
    echo json_encode(['success' => false, 'message' => 'Email and OTP code are required.']);
    exit();
}

$email = strtolower(trim($data['email']));
$otpCode = trim($data['otp_code']);
if ($email === '') {
    echo json_encode(['success' => false, 'message' => 'Email and OTP code are required.']);
    exit();
}

try {
    $pdo->beginTransaction();
    $token = passwordResetCheckOtp($pdo, $email, $otpCode);
    $pdo->commit();

    if (!$token) {
        echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'OTP verified successfully.'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password reset OTP verification failed.');
    echo json_encode([
        'success' => false,
        'message' => 'Unable to verify the code. Please try again later.'
    ]);
}
?>
