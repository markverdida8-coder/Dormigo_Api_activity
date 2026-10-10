<?php
require_once 'db.php';
require_once 'password_reset_security.php';

$data = json_decode(file_get_contents('php://input'), true);
if (
    !is_array($data)
    || !isset($data['email'], $data['otp_code'], $data['new_password'])
    || !is_string($data['email'])
    || !is_string($data['otp_code'])
    || !is_string($data['new_password'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'Email, OTP code, and new password are required.'
    ]);
    exit();
}

$email = strtolower(trim($data['email']));
$otpCode = trim($data['otp_code']);
$newPassword = $data['new_password'];
if ($email === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Email, OTP code, and new password are required.'
    ]);
    exit();
}

if (strlen($newPassword) < 8) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
    exit();
}

try {
    $pdo->beginTransaction();
    $token = passwordResetCheckOtp($pdo, $email, $otpCode);
    if (!$token) {
        $pdo->commit();
        echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        exit();
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($hashedPassword)) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Unable to reset the password. Please try again later.'
        ]);
        exit();
    }

    $updateUser = $pdo->prepare(
        'UPDATE users
         SET password = :password
         WHERE user_id = :user_id
         RETURNING user_id'
    );
    $updateUser->execute([
        'password' => $hashedPassword,
        'user_id' => $token['user_id']
    ]);
    if ($updateUser->fetchColumn() === false) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Unable to reset the password. Please try again later.'
        ]);
        exit();
    }

    $consumeToken = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used = TRUE
         WHERE id = :id AND used IS DISTINCT FROM TRUE AND attempts < 5
         RETURNING id'
    );
    $consumeToken->execute(['id' => $token['id']]);
    if ($consumeToken->fetchColumn() === false) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Invalid verification code.']);
        exit();
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Password reset successfully. You can now log in.'
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password reset operation failed.');
    echo json_encode([
        'success' => false,
        'message' => 'Unable to reset the password. Please try again later.'
    ]);
}
?>
