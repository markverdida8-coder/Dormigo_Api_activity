<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sql = "SELECT r.*, u.full_name, bh.house_name
            FROM reviews r
            JOIN users u ON r.user_id = u.user_id
            JOIN boarding_houses bh ON r.house_id = bh.house_id";
    $params = [];

    if (isset($_GET['house_id'])) {
        $sql .= " WHERE r.house_id = :house_id";
        $params['house_id'] = $_GET['house_id'];
    } elseif (isset($_GET['user_id'])) {
        $sql .= " WHERE r.user_id = :user_id";
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
    $data = json_decode(file_get_contents("php://input"), true);
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $house_id = isset($data['house_id']) ? (int)$data['house_id'] : 0;
    $rating = isset($data['rating']) ? (int)$data['rating'] : 5;
    $comment = $data['comment'] ?? null;

    if ($user_id <= 0 || $house_id <= 0) {
        echo json_encode(["success" => false, "message" => "User ID and House ID are required."]);
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

        $stmt = $pdo->prepare("INSERT INTO reviews (user_id, house_id, rating, comment) VALUES (:user_id, :house_id, :rating, :comment) RETURNING review_id");
        $stmt->execute([
            'user_id' => $user_id,
            'house_id' => $house_id,
            'rating' => $rating,
            'comment' => $comment
        ]);
        $reviewId = $stmt->fetchColumn();

        // Notification
        $info = $pdo->prepare("SELECT u.full_name, bh.landlord_id, bh.house_name FROM boarding_houses bh JOIN users u ON u.user_id = :uid WHERE bh.house_id = :hid");
        $info->execute(['uid' => $user_id, 'hid' => $house_id]);
        $infoData = $info->fetch();
        if ($infoData && $infoData['landlord_id']) {
            $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, ?, ?, 'REVIEW', ?)");
            $nStmt->execute([$infoData['landlord_id'], 'New Review', $infoData['full_name'] . ' left a ' . $rating . '-star review for ' . $infoData['house_name'] . '.', $reviewId]);
        }

        echo json_encode(["success" => true, "message" => "Review submitted successfully.", "review_id" => $reviewId]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
