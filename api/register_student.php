<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$fullName = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
$email = isset($_POST['email']) ? trim($_POST['email']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';
$phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;
$school = isset($_POST['school']) ? trim($_POST['school']) : null;
$userType = 'STUDENT';

if (empty($fullName) || empty($email) || empty($password)) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
    exit();
}

try {
    $checkStmt = $pdo->prepare("SELECT user_id FROM users WHERE LOWER(email) = LOWER(:email)");
    $checkStmt->execute(['email' => $email]);
    if ($checkStmt->fetch()) {
        echo json_encode(["success" => false, "message" => "Email is already registered."]);
        exit();
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password, phone, user_type) VALUES (:full_name, :email, :password, :phone, :user_type::user_type_enum) RETURNING user_id, full_name, email, phone, user_type");
    $stmt->execute([
        'full_name' => $fullName,
        'email' => $email,
        'password' => $password,
        'phone' => $phone,
        'user_type' => $userType
    ]);
    $newUser = $stmt->fetch();
    $studentId = $newUser['user_id'];

    $documentPath = '';
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/verifications/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = 'student_verif_' . $studentId . '_' . time() . '.jpg';
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetPath)) {
            $documentPath = 'uploads/verifications/' . $fileName;
        }
    }

    if (empty($documentPath)) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Student ID upload failed. Please attach a valid image."]);
        exit();
    }

    $vStmt = $pdo->prepare("INSERT INTO student_verifications (user_id, student_id, student_id_path, verification_status) VALUES (:student_id, :student_id, :document_path, 'PENDING')");
    $vStmt->execute([
        'student_id' => $studentId,
        'document_path' => $documentPath
    ]);
    $verificationId = $pdo->lastInsertId();

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (3, 'New Student Verification Request', 'New student verification request submitted.', 'VERIFICATION', ?)");
    $nStmt->execute([$verificationId]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Student account created and verification submitted successfully.",
        "user" => $newUser
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
