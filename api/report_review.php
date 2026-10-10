<?php
require_once 'db.php';
require_once 'auth_helper.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed."]);
    exit();
}

$authUser = authenticateUser($pdo);

if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Forbidden: Only landlords can report reviews."]);
    exit();
}

$landlordId = (int)$authUser['user_id'];

$data = json_decode(file_get_contents("php://input"), true);
$reviewId = (int)($data['review_id'] ?? 0);
$reason = trim($data['reason'] ?? '');
$description = trim($data['description'] ?? '');

$validReasons = [
    "Suspected fake review",
    "Harassment or abusive language",
    "Spam or unrelated content",
    "False or misleading information",
    "Other"
];

if ($reviewId <= 0 || empty($reason) || !in_array($reason, $validReasons, true)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Valid review ID and a valid reporting reason are required."]);
    exit();
}

try {
    // Verify review exists and belongs to a boarding house owned by this landlord
    $chkStmt = $pdo->prepare("
        SELECT r.review_id, r.house_id, bh.landlord_id
        FROM reviews r
        JOIN boarding_houses bh ON r.house_id = bh.house_id
        WHERE r.review_id = ?
    ");
    $chkStmt->execute([$reviewId]);
    $review = $chkStmt->fetch();

    if (!$review) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Review not found."]);
        exit();
    }

    if ((int)$review['landlord_id'] !== $landlordId) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: You do not own the property associated with this review."]);
        exit();
    }

    // Check duplicate report
    $dup = $pdo->prepare("SELECT 1 FROM review_reports WHERE landlord_id = ? AND review_id = ?");
    $dup->execute([$landlordId, $reviewId]);
    if ($dup->fetch()) {
        http_response_code(409);
        echo json_encode(["success" => false, "message" => "You have already reported this review."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO review_reports (review_id, landlord_id, reason, description, status)
            VALUES (:r_id, :l_id, :reason, :desc, 'PENDING')
            RETURNING report_id
        ");
        $stmt->execute([
            'r_id' => $reviewId,
            'l_id' => $landlordId,
            'reason' => $reason,
            'desc' => $description
        ]);
        $reportId = $stmt->fetchColumn();

        echo json_encode([
            "success" => true,
            "message" => "Review reported successfully. Our administration will investigate.",
            "report_id" => $reportId
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505' || strpos($e->getMessage(), 'unique_landlord_review_report') !== false) {
            http_response_code(409);
            echo json_encode(["success" => false, "message" => "You have already reported this review."]);
            exit();
        }
        throw $e;
    }

} catch (PDOException $e) {
    error_log("Database error in report_review.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "An unexpected error occurred. Please try again later."]);
}
?>
