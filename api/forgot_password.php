<?php
require_once 'db.php';
require_once '../vendor/autoload.php';
require_once 'password_reset_security.php';

use PHPMailer\PHPMailer\PHPMailer;

function loadBrevoSmtpConfig(): ?array
{
    $configPath = 'C:\\xampp\\private\\dormigo_smtp.env';
    if (!is_readable($configPath)) {
        return null;
    }

    $lines = file($configPath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return null;
    }

    $config = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        if (in_array($key, ['BREVO_SMTP_USERNAME', 'BREVO_SMTP_KEY'], true)) {
            $config[$key] = trim($parts[1]);
        }
    }

    if (empty($config['BREVO_SMTP_USERNAME']) || empty($config['BREVO_SMTP_KEY'])) {
        return null;
    }

    return $config;
}

function passwordResetGenericResponse(): void
{
    echo json_encode([
        'success' => true,
        'message' => 'If an account exists, a verification code will be sent.'
    ]);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data) || !isset($data['email']) || !is_string($data['email']) || trim($data['email']) === '') {
    echo json_encode(['success' => false, 'message' => 'Email address is required.']);
    exit();
}

$email = strtolower(trim($data['email']));
$smtpConfig = loadBrevoSmtpConfig();
if ($smtpConfig === null) {
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process the request. Please try again later.'
    ]);
    exit();
}

try {
    $pdo->beginTransaction();
    $requestAllowed = passwordResetRequestAllowed(
        $pdo,
        $email,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );
    $pdo->commit();

    if (!$requestAllowed) {
        passwordResetGenericResponse();
    }

    $userStmt = $pdo->prepare(
        'SELECT user_id, full_name, email
         FROM users
         WHERE LOWER(email) = :email
         LIMIT 1'
    );
    $userStmt->execute(['email' => $email]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        passwordResetGenericResponse();
    }

    $otpCode = sprintf('%06d', random_int(0, 999999));
    $otpHash = password_hash($otpCode, PASSWORD_DEFAULT);
    if (!is_string($otpHash)) {
        throw new RuntimeException('Unable to hash password reset code.');
    }
    $pdo->beginTransaction();
    $invalidatePrevious = $pdo->prepare(
        'UPDATE password_reset_tokens
         SET used = TRUE
         WHERE user_id = :user_id AND used IS DISTINCT FROM TRUE'
    );
    $invalidatePrevious->execute(['user_id' => $user['user_id']]);

    $insert = $pdo->prepare(
        'INSERT INTO password_reset_tokens
            (user_id, email, otp_code, expires_at, used, attempts)
         VALUES (:user_id, :email, :otp_hash, CURRENT_TIMESTAMP + INTERVAL \'5 minutes\', FALSE, 0)
         RETURNING id'
    );
    $insert->execute([
        'user_id' => $user['user_id'],
        'email' => $user['email'],
        'otp_hash' => $otpHash
    ]);
    $tokenId = $insert->fetchColumn();

    $emailSent = false;
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp-relay.brevo.com';
        $mail->SMTPAuth = true;
        $mail->Username = $smtpConfig['BREVO_SMTP_USERNAME'];
        $mail->Password = $smtpConfig['BREVO_SMTP_KEY'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->SMTPDebug = 0;
        $mail->setFrom('dormigohelp@gmail.com', 'Dormigo Support');
        $mail->addAddress($user['email'], $user['full_name']);
        $mail->isHTML(false);
        $mail->Subject = 'Dormigo Password Reset Code';
        $mail->Body = "Hello,\n\nYou requested to reset your Dormigo password.\n\nYour verification code is:\n\n"
            . $otpCode
            . "\n\nThis code expires in 5 minutes.\n\nIf you did not request this, simply ignore this email.\n\nDormigo Support";
        $emailSent = $mail->send() === true;
    } catch (Throwable $e) {
        $emailSent = false;
    }

    if (!$emailSent) {
        error_log('Password reset email delivery failed.');
        $invalidateNew = $pdo->prepare(
            'UPDATE password_reset_tokens SET used = TRUE WHERE id = :id'
        );
        $invalidateNew->execute(['id' => $tokenId]);
        $pdo->commit();
        passwordResetGenericResponse();
    }

    $pdo->commit();
    passwordResetGenericResponse();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Password reset request failed.');
    echo json_encode([
        'success' => false,
        'message' => 'Unable to process the request. Please try again later.'
    ]);
}
?>
