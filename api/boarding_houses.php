<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $availableOnly = isset($_GET['available_only']) && $_GET['available_only'] == '1';

    $sql = "
        SELECT bh.*,
               COALESCE((SELECT json_agg(hp.photo_path) FROM house_photos hp WHERE hp.house_id = bh.house_id), '[]'::json) AS photo_paths,
               COALESCE(
                   (SELECT lv.verification_status
                    FROM landlord_verifications lv
                    WHERE lv.landlord_id = bh.landlord_id
                    ORDER BY lv.submitted_at DESC LIMIT 1),
                   'NOT_SUBMITTED'
               ) AS verification_status,
               bhv.rejection_reason
        FROM boarding_houses bh
        LEFT JOIN boarding_house_verification bhv ON bh.house_id = bhv.house_id
    ";

    if ($availableOnly) {
        $sql .= " WHERE UPPER(bh.status::text) = 'ACTIVE' AND EXISTS (
            SELECT 1
            FROM rooms r
            WHERE r.house_id = bh.house_id
              AND UPPER(r.status::text) = 'AVAILABLE'
              AND r.capacity > (
                  SELECT COUNT(*)
                  FROM bookings b
                  WHERE b.room_id = r.room_id
                    AND UPPER(b.status::text) = 'APPROVED'
              )
        )";
    }

    $sql .= " ORDER BY bh.house_id DESC";

    $stmt = $pdo->query($sql);
    $houses = $stmt->fetchAll();
    foreach ($houses as &$house) {
        if (is_string($house['photo_paths'])) {
            $house['photo_paths'] = json_decode($house['photo_paths'], true);
        }
        if (!is_array($house['photo_paths'])) {
            $house['photo_paths'] = [];
        }
    }
    echo json_encode(["success" => true, "data" => $houses]);

} elseif ($method === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'upload_photos') {
        $houseId = (int)($_POST['house_id'] ?? 0);
        if ($houseId <= 0 || !isset($_FILES['photos'])) {
            echo json_encode(["success" => false, "message" => "House ID and photos required."]);
            exit();
        }
        $uploadDir = '../uploads/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $photoStmt = $pdo->prepare("INSERT INTO house_photos (house_id, photo_path) VALUES (:house_id, :photo_path)");
        $photoPaths = [];
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                $fileName = 'house_' . $houseId . '_' . time() . '_' . $key . '.jpg';
                $targetFilePath = $uploadDir . $fileName;
                if (move_uploaded_file($tmpName, $targetFilePath)) {
                    $webPath = 'uploads/' . $fileName;
                    $photoStmt->execute(['house_id' => $houseId, 'photo_path' => $webPath]);
                    $photoPaths[] = $webPath;
                }
            }
        }
        echo json_encode(["success" => true, "message" => "Photos uploaded successfully.", "photo_paths" => $photoPaths]);
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'upload_gcash_qr') {
        $houseId = (int)($_POST['house_id'] ?? 0);
        if ($houseId <= 0 || !isset($_FILES['gcash_qr'])) {
            echo json_encode(["success" => false, "message" => "House ID and QR file required."]);
            exit();
        }
        $uploadDir = '../uploads/gcash_qr/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        $tmpName = $_FILES['gcash_qr']['tmp_name'];
        $fileName = 'qr_house_' . $houseId . '_' . time() . '.jpg';
        $targetFilePath = $uploadDir . $fileName;

        if (move_uploaded_file($tmpName, $targetFilePath)) {
            $webPath = 'uploads/gcash_qr/' . $fileName;
            $stmt = $pdo->prepare("UPDATE boarding_houses SET gcash_qr_code = :path, gcash_updated_at = CURRENT_TIMESTAMP WHERE house_id = :house_id");
            $stmt->execute(['path' => $webPath, 'house_id' => $houseId]);

            echo json_encode([
                "success" => true,
                "message" => "GCash QR code uploaded successfully.",
                "gcash_qr_code" => $webPath
            ]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to save QR code image."]);
        }
        exit();
    }

    if (isset($_POST['payload'])) {
        $data = json_decode($_POST['payload'], true);
    } else {
        $data = json_decode(file_get_contents("php://input"), true);
    }

    if (!$data || !isset($data['landlord_id']) || !isset($data['house_name'])) {
        echo json_encode(["success" => false, "message" => "Invalid property details."]);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO boarding_houses (landlord_id, house_name, description, address, house_rules, status, free_electricity, electricity_rate, free_water, water_rate, payment_due_day, advance_months, security_deposit_months, utility_deposit, other_fees)
                                VALUES (:landlord_id, :house_name, :description, :address, :house_rules, :status::house_status_enum, :free_electricity, :electricity_rate, :free_water, :water_rate, :payment_due_day, :advance_months, :security_deposit_months, :utility_deposit, :other_fees) RETURNING house_id");
        $stmt->execute([
            'landlord_id' => $data['landlord_id'],
            'house_name' => $data['house_name'],
            'description' => $data['description'] ?? '',
            'address' => $data['address'] ?? '',
            'house_rules' => $data['house_rules'] ?? '',
            'status' => strtoupper($data['status'] ?? 'ACTIVE'),
            'free_electricity' => filter_var($data['free_electricity'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'electricity_rate' => $data['electricity_rate'] ?? '0.00',
            'free_water' => filter_var($data['free_water'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
            'water_rate' => $data['water_rate'] ?? '0.00',
            'payment_due_day' => (int)($data['payment_due_day'] ?? 1),
            'advance_months' => (int)($data['advance_months'] ?? 1),
            'security_deposit_months' => (int)($data['security_deposit_months'] ?? 1),
            'utility_deposit' => $data['utility_deposit'] ?? '0.00',
            'other_fees' => $data['other_fees'] ?? '0.00'
        ]);
        $houseId = $stmt->fetchColumn();

        $roomStmt = $pdo->prepare("INSERT INTO rooms (house_id, room_number, room_type, capacity, monthly_rent, status, advance_months, deposit_months, utility_deposit, other_fees, other_fees_description, deposit_refund_policy)
                                   VALUES (:house_id, :room_number, :room_type, :capacity, :monthly_rent, :status::room_status_enum, :advance_months, :deposit_months, :utility_deposit, :other_fees, :other_fees_description, :deposit_refund_policy)");

        if (isset($data['rooms']) && is_array($data['rooms'])) {
            foreach ($data['rooms'] as $room) {
                $roomStmt->execute([
                    'house_id' => $houseId,
                    'room_number' => $room['room_number'] ?? 'Room 1',
                    'room_type' => $room['room_type'] ?? 'Bedspace',
                    'capacity' => $room['capacity'] ?? 1,
                    'monthly_rent' => $room['monthly_rent'] ?? 0,
                    'status' => strtoupper($room['status'] ?? 'AVAILABLE'),
                    'advance_months' => $room['advance_months'] ?? 1,
                    'deposit_months' => $room['deposit_months'] ?? 1,
                    'utility_deposit' => $room['utility_deposit'] ?? 0.00,
                    'other_fees' => $room['other_fees'] ?? 0.00,
                    'other_fees_description' => $room['other_fees_description'] ?? '',
                    'deposit_refund_policy' => $room['deposit_refund_policy'] ?? ''
                ]);
            }
        } elseif (isset($data['room'])) {
            $room = $data['room'];
            $roomStmt->execute([
                'house_id' => $houseId,
                'room_number' => $room['room_number'] ?? 'Room 1',
                'room_type' => $room['room_type'] ?? 'Single',
                'capacity' => $room['capacity'] ?? 1,
                'monthly_rent' => $room['monthly_rent'] ?? 0,
                'status' => strtoupper($room['status'] ?? 'AVAILABLE')
            ]);
        }

        if (isset($data['amenity_ids']) && is_array($data['amenity_ids'])) {
            $amenityStmt = $pdo->prepare("INSERT INTO boarding_house_amenities (house_id, amenity_id) VALUES (:house_id, :amenity_id) ON CONFLICT DO NOTHING");
            foreach ($data['amenity_ids'] as $amenityId) {
                $amenityStmt->execute(['house_id' => $houseId, 'amenity_id' => $amenityId]);
            }
        }

        $photoPaths = [];
        if (isset($_FILES['photos'])) {
            $uploadDir = '../uploads/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $photoStmt = $pdo->prepare("INSERT INTO house_photos (house_id, photo_path) VALUES (:house_id, :photo_path)");
            foreach ($_FILES['photos']['tmp_name'] as $key => $tmpName) {
                if ($_FILES['photos']['error'][$key] === UPLOAD_ERR_OK) {
                    $fileName = 'house_' . $houseId . '_' . time() . '_' . $key . '.jpg';
                    $targetFilePath = $uploadDir . $fileName;
                    if (move_uploaded_file($tmpName, $targetFilePath)) {
                        $webPath = 'uploads/' . $fileName;
                        $photoStmt->execute(['house_id' => $houseId, 'photo_path' => $webPath]);
                        $photoPaths[] = $webPath;
                    }
                }
            }
        }

        // Check if owner's landlord account is already VERIFIED/APPROVED to auto-verify this new property
        $vChk = $pdo->prepare("SELECT verification_status FROM landlord_verifications WHERE landlord_id = ? ORDER BY submitted_at DESC LIMIT 1");
        $vChk->execute([$data['landlord_id']]);
        $vRow = $vChk->fetch();
        $lVerifStatus = $vRow ? strtoupper(trim($vRow['verification_status'])) : '';

        if ($lVerifStatus === 'VERIFIED' || $lVerifStatus === 'APPROVED') {
            $insBhVerif = $pdo->prepare("INSERT INTO boarding_house_verification (house_id, verification_status, submitted_at) VALUES (?, 'VERIFIED', CURRENT_TIMESTAMP)");
            $insBhVerif->execute([$houseId]);
        }

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Boarding house saved successfully.",
            "house_id" => $houseId,
            "photo_paths" => $photoPaths
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $houseId = isset($data['house_id']) ? (int)$data['house_id'] : 0;

    if ($houseId <= 0) {
        echo json_encode(["success" => false, "message" => "Valid house_id is required."]);
        exit();
    }

    try {
        $chkHouse = $pdo->prepare("SELECT house_id FROM boarding_houses WHERE house_id = ?");
        $chkHouse->execute([$houseId]);
        if (!$chkHouse->fetch()) {
            echo json_encode(["success" => false, "message" => "Boarding house not found."]);
            exit();
        }

        $chkBookings = $pdo->prepare("
            SELECT 1
            FROM bookings b
            JOIN rooms r ON b.room_id = r.room_id
            WHERE r.house_id = ?
              AND UPPER(b.status::text) IN ('PENDING', 'APPROVED')
            LIMIT 1
        ");
        $chkBookings->execute([$houseId]);
        if ($chkBookings->fetch()) {
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "This property cannot be deleted while it has active bookings."
            ]);
            exit();
        }

        $upd = $pdo->prepare("UPDATE boarding_houses SET status = 'INACTIVE'::house_status_enum WHERE house_id = ?");
        $upd->execute([$houseId]);

        echo json_encode([
            "success" => true,
            "message" => "Property deleted successfully."
        ]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
