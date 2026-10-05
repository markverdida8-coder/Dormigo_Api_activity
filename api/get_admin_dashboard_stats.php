<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

try {
    $stats = [];

    // USERS (Students and Landlords)
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $userCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['students'] = $userCounts['STUDENT'] ?? 0;
    $stats['landlords'] = $userCounts['LANDLORD'] ?? 0;

    $stats['boarding_houses'] = $pdo->query("SELECT COUNT(*) FROM boarding_houses")->fetchColumn();
    $stats['available_rooms'] = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'AVAILABLE'")->fetchColumn();

    // STUDENT VERIFICATIONS
    $stmt = $pdo->query("SELECT verification_status, COUNT(*) as count FROM student_verifications GROUP BY verification_status");
    $svCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['pending_students'] = $svCounts['PENDING'] ?? 0;
    $stats['verified_students'] = $svCounts['VERIFIED'] ?? ($svCounts['APPROVED'] ?? 0);
    $stats['rejected_students'] = $svCounts['REJECTED'] ?? 0;

    // LANDLORD VERIFICATIONS
    $stmt = $pdo->query("SELECT verification_status, COUNT(*) as count FROM landlord_verifications GROUP BY verification_status");
    $lvCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['pending_landlords'] = $lvCounts['PENDING'] ?? 0;
    $stats['verified_landlords'] = $lvCounts['VERIFIED'] ?? ($lvCounts['APPROVED'] ?? 0);
    $stats['rejected_landlords'] = $lvCounts['REJECTED'] ?? 0;

    // LATEST STUDENT VERIFICATIONS
    $vSql = "SELECT v.verification_id, v.verification_status, v.submitted_at, v.student_id_path,
                    u.full_name, u.email, u.phone, u.profile_image
             FROM student_verifications v
             JOIN users u ON v.student_id = u.user_id
             ORDER BY v.submitted_at DESC";
    $stats['recent_student_verifications'] = $pdo->query($vSql)->fetchAll();

    // LATEST LANDLORD VERIFICATIONS
    $vSql2 = "SELECT v.verification_id, v.verification_status, v.submitted_at, v.document_path,
                    u.full_name, u.email, u.phone, u.profile_image
             FROM landlord_verifications v
             JOIN users u ON v.landlord_id = u.user_id
             ORDER BY v.submitted_at DESC";
    $stats['recent_landlord_verifications'] = $pdo->query($vSql2)->fetchAll();

    echo json_encode(["success" => true, "data" => $stats]);

} catch (Exception $e) {
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
