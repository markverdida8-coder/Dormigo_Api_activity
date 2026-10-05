<?php
require_once 'db.php';

function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

function authenticateUser($pdo) {
    $rawToken = getBearerToken();
    if (empty($rawToken)) {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Unauthorized: Missing authentication token."]);
        exit();
    }

    $tokenHash = hash('sha256', $rawToken);

    try {
        $stmt = $pdo->prepare("
            SELECT u.user_id, u.user_type, u.email, u.full_name, t.expires_at
            FROM auth_tokens t
            JOIN users u ON t.user_id = u.user_id
            WHERE t.token_hash = :hash
        ");
        $stmt->execute(['hash' => $tokenHash]);
        $record = $stmt->fetch();

        if (!$record) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Unauthorized: Invalid authentication token."]);
            exit();
        }

        // Check expiration
        if (strtotime($record['expires_at']) < time()) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Unauthorized: Authentication token has expired."]);
            exit();
        }

        return [
            "user_id" => (int)$record['user_id'],
            "user_type" => $record['user_type'],
            "email" => $record['email'],
            "full_name" => $record['full_name']
        ];

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Authentication database error: " . $e->getMessage()]);
        exit();
    }
}
?>
