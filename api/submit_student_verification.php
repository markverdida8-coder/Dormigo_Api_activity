<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode(["success" => false, "message" => "user_id is required."]);
    exit();
}

$uploadDir = '../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$student_id_path = null;
if (isset($_FILES['student_id']) && $_FILES['student_id']['error'] === UPLOAD_ERR_OK) {
    $fileName = 'student_id_' . $user_id . '_' . time() . '.jpg';
    $targetPath = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES['student_id']['tmp_name'], $targetPath)) {
        $student_id_path = 'uploads/' . $fileName;
    }
}

try {
    $stmt = $pdo->prepare("SELECT verification_id FROM student_verifications WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $user_id]);
    $existing = $stmt->fetch();

    if ($existing) {
        $update = $pdo->prepare("UPDATE student_verifications SET verification_status = 'PENDING', rejection_reason = NULL, student_id_path = COALESCE(:path, student_id_path), submitted_at = CURRENT_TIMESTAMP WHERE user_id = :user_id");
        $update->execute(['path' => $student_id_path, 'user_id' => $user_id]);
    } else {
        $insert = $pdo->prepare("INSERT INTO student_verifications (user_id, student_id_path, verification_status) VALUES (:user_id, :path, 'PENDING')");
        $insert->execute(['user_id' => $user_id, 'path' => $student_id_path]);
    }

    echo json_encode(["success" => true, "message" => "Student ID submitted for verification successfully. Status: PENDING"]);
} catch (PDOException $e) {
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}
?>
