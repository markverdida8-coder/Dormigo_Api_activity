<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT r.review_id, r.user_id, r.house_id, r.rating, r.comment, r.created_at,
                   u.full_name,
                   sv.verification_status
            FROM reviews r
            JOIN users u ON r.user_id = u.user_id
            JOIN boarding_houses bh ON r.house_id = bh.house_id
            LEFT JOIN student_verifications sv ON u.user_id = sv.student_id
            WHERE (r.is_hidden IS NULL OR r.is_hidden = FALSE)";
    $params = [];

    if (isset($_GET['house_id'])) {
        $sql .= " AND r.house_id = :house_id";
        $params['house_id'] = $_GET['house_id'];
    } elseif (isset($_GET['user_id'])) {
        $sql .= " AND r.user_id = :user_id";
        $params['user_id'] = $_GET['user_id'];
    }
    $sql .= " ORDER BY r.review_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reviews = $stmt->fetchAll();

    $avgRating = 0.0;
    $reviewCount = count($reviews);
    if (!empty($reviews)) {
        $sum = 0;
        foreach ($reviews as $rev) {
            $sum += (int)$rev['rating'];
        }
        $avgRating = round($sum / $reviewCount, 1);
    }

    $formattedReviews = [];
    foreach ($reviews as $rev) {
        $nameParts = explode(' ', trim($rev['full_name']));
        $initial = count($nameParts) > 1 ? mb_substr($nameParts[1], 0, 1) . '.' : '';
        $userName = $nameParts[0] . ' ' . $initial;

        $formattedReviews[] = [
            "user_name" => trim($userName),
            "rating" => (int)$rev['rating'],
            "comment" => $rev['comment'],
            "created_at" => date('M d, Y', strtotime($rev['created_at']))
        ];
    }

    echo json_encode([
        "success" => true,
        "average_rating" => $avgRating,
        "review_count" => $reviewCount,
        "data" => $reviews,
        "reviews" => $formattedReviews
    ]);

} elseif ($method === 'POST') {
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);

    if (strtoupper($authUser['user_type'] ?? '') !== 'STUDENT') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: Only students can submit reviews."]);
        exit();
    }

    $user_id = (int)$authUser['user_id'];

    $data = json_decode(file_get_contents("php://input"), true);
    $house_id = isset($data['house_id']) ? (int)$data['house_id'] : 0;
    $rating = isset($data['rating']) ? (int)$data['rating'] : 5;
    $comment = $data['comment'] ?? null;

    if ($house_id <= 0) {
        echo json_encode(["success" => false, "message" => "House ID is required."]);
        exit();
    }

    try {
        $chk = $pdo->prepare("
            SELECT 1 FROM bookings b
            JOIN rooms r ON b.room_id = r.room_id
            WHERE b.user_id = :user_id AND r.house_id = :house_id AND UPPER(b.status::text) = 'COMPLETED'
            LIMIT 1
        ");
        $chk->execute(['user_id' => $user_id, 'house_id' => $house_id]);
        if (!$chk->fetch()) {
            echo json_encode(["success" => false, "message" => "Reviews are available only after a completed stay/booking."]);
            exit();
        }

        $dup = $pdo->prepare("SELECT 1 FROM reviews WHERE user_id = :user_id AND house_id = :house_id");
        $dup->execute(['user_id' => $user_id, 'house_id' => $house_id]);
        if ($dup->fetch()) {
            echo json_encode(["success" => false, "message" => "You have already submitted a review for this boarding house."]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO reviews (user_id, house_id, rating, comment) VALUES (:user_id, :house_id, :rating, :comment) RETURNING review_id");
            $stmt->execute([
                'user_id' => $user_id,
                'house_id' => $house_id,
                'rating' => $rating,
                'comment' => $comment
            ]);
            $reviewId = $stmt->fetchColumn();
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' || strpos($e->getMessage(), 'unique_student_house_review') !== false) {
                echo json_encode(["success" => false, "message" => "You have already submitted a review for this boarding house."]);
                exit();
            }
            throw $e;
        }

        // Notification - reference_id set to $house_id so tapping opens LandlordReviewsActivity for $house_id
        $info = $pdo->prepare("SELECT u.full_name, bh.landlord_id, bh.house_name FROM boarding_houses bh JOIN users u ON u.user_id = :uid WHERE bh.house_id = :hid");
        $info->execute(['uid' => $user_id, 'hid' => $house_id]);
        $infoData = $info->fetch();
        if ($infoData && $infoData['landlord_id']) {
            $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, 'REVIEW', ?)");
            $nStmt->execute([$infoData['landlord_id'], 'New Review', $infoData['full_name'] . ' left a ' . $rating . '-star review for ' . $infoData['house_name'] . '.', $house_id]);
        }

        echo json_encode(["success" => true, "message" => "Review submitted successfully.", "review_id" => $reviewId]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
