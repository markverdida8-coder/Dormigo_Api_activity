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
    }
    $sql .= " ORDER BY r.review_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["success" => true, "data" => $stmt->fetchAll()]);

} elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $stmt = $pdo->prepare("INSERT INTO reviews (user_id, house_id, rating, comment) VALUES (:user_id, :house_id, :rating, :comment) RETURNING review_id");
    $stmt->execute([
        'user_id' => $data['user_id'],
        'house_id' => $data['house_id'],
        'rating' => $data['rating'],
        'comment' => $data['comment'] ?? null
    ]);
    echo json_encode(["success" => true, "message" => "Review submitted successfully.", "review_id" => $stmt->fetchColumn()]);
}
?>
