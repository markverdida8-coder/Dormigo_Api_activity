<?php
require_once 'db.php';

$email = 'mark@gmail.com';

// 1. Forgot password
$ch = curl_init('http://localhost/Dormigo_Backend/api/forgot_password.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['email' => $email]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res1 = curl_exec($ch);
echo "Forgot Password Res: " . $res1 . "\n";

// Fetch OTP from DB
$stmt = $pdo->prepare("SELECT otp_code FROM password_reset_tokens WHERE email = ? AND used = false ORDER BY id DESC LIMIT 1");
$stmt->execute([$email]);
$otp = $stmt->fetchColumn();
echo "Fetched OTP: " . $otp . "\n";

// 2. Verify OTP
$ch2 = curl_init('http://localhost/Dormigo_Backend/api/verify_otp.php');
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'otp_code' => $otp]));
curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res2 = curl_exec($ch2);
echo "Verify OTP Res: " . $res2 . "\n";

// 3. Reset Password
$ch3 = curl_init('http://localhost/Dormigo_Backend/api/reset_password.php');
curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch3, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'otp_code' => $otp, 'new_password' => 'NewPassword123!']));
curl_setopt($ch3, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res3 = curl_exec($ch3);
echo "Reset Password Res: " . $res3 . "\n";

// 4. Login
$ch4 = curl_init('http://localhost/Dormigo_Backend/api/login.php');
curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch4, CURLOPT_POSTFIELDS, json_encode(['email' => $email, 'password' => 'NewPassword123!']));
curl_setopt($ch4, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res4 = curl_exec($ch4);
echo "Login Res: " . $res4 . "\n";
?>
