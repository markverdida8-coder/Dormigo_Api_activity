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
$userType = 'LANDLORD';

if (empty($fullName) || empty($email) || empty($password)) {
    echo json_encode(["success" => false, "message" => "Please fill in all required fields."]);
    exit();
}

try {
    // Check if email exists
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
    $landlordId = $newUser['user_id'];

    $documentPath = '';
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/verifications/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $fileName = 'landlord_verif_' . $landlordId . '_' . time() . '.pdf';
        $targetPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $targetPath)) {
            $documentPath = 'uploads/verifications/' . $fileName;
        }
    }

    if (empty($documentPath)) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Verification document (PDF) upload failed."]);
        exit();
    }

    // Insert landlord verification record
    $vStmt = $pdo->prepare("INSERT INTO landlord_verifications (landlord_id, document_path, verification_status) VALUES (:landlord_id, :document_path, 'PENDING')");
    $vStmt->execute([
        'landlord_id' => $landlordId,
        'document_path' => $documentPath
    ]);
    $verificationId = $pdo->lastInsertId();

    // Notify Admin (user_id = 3)
    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (3, 'New Verification Request', 'New landlord verification request submitted.', 'VERIFICATION', ?)");
    $nStmt->execute([$verificationId]);

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Landlord account created and verification submitted successfully.",
        "user" => $newUser
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
