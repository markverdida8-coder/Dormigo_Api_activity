<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT bh.*, COALESCE((SELECT json_agg(hp.photo_path) FROM house_photos hp WHERE hp.house_id = bh.house_id), '[]'::json) AS photo_paths,
                          bhv.verification_status, bhv.rejection_reason
                          FROM boarding_houses bh
                          LEFT JOIN boarding_house_verification bhv ON bh.house_id = bhv.house_id
                          ORDER BY bh.house_id DESC");
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

        $pdo->commit();

        echo json_encode([
            "success" => true,
            "message" => "Boarding house saved successfully.",
            "house_id" => $houseId,
            "photo_paths" => $photoPaths
        ]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }

} elseif ($method === 'PATCH') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['house_id'])) {
        echo json_encode(["success" => false, "message" => "House ID is required."]);
        exit();
    }
    $fields = [];
    $params = ['house_id' => (int)$data['house_id']];

    if (isset($data['house_name'])) {
        $fields[] = "house_name = :house_name";
        $params['house_name'] = $data['house_name'];
    }
    if (isset($data['description'])) {
        $fields[] = "description = :description";
        $params['description'] = $data['description'];
    }
    if (isset($data['address'])) {
        $fields[] = "address = :address";
        $params['address'] = $data['address'];
    }
    if (isset($data['house_rules'])) {
        $fields[] = "house_rules = :house_rules";
        $params['house_rules'] = $data['house_rules'];
    }
    if (isset($data['status'])) {
        $fields[] = "status = :status::house_status_enum";
        $params['status'] = strtoupper($data['status']);
    }
    if (isset($data['free_electricity'])) {
        $fields[] = "free_electricity = :free_electricity";
        $params['free_electricity'] = filter_var($data['free_electricity'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (isset($data['electricity_rate'])) {
        $fields[] = "electricity_rate = :electricity_rate";
        $params['electricity_rate'] = $data['electricity_rate'];
    }
    if (isset($data['free_water'])) {
        $fields[] = "free_water = :free_water";
        $params['free_water'] = filter_var($data['free_water'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (isset($data['water_rate'])) {
        $fields[] = "water_rate = :water_rate";
        $params['water_rate'] = $data['water_rate'];
    }
    if (isset($data['payment_due_day'])) {
        $fields[] = "payment_due_day = :payment_due_day";
        $params['payment_due_day'] = (int)$data['payment_due_day'];
    }
    if (isset($data['advance_months'])) {
        $fields[] = "advance_months = :advance_months";
        $params['advance_months'] = (int)$data['advance_months'];
    }
    if (isset($data['security_deposit_months'])) {
        $fields[] = "security_deposit_months = :security_deposit_months";
        $params['security_deposit_months'] = (int)$data['security_deposit_months'];
    }
    if (isset($data['utility_deposit'])) {
        $fields[] = "utility_deposit = :utility_deposit";
        $params['utility_deposit'] = $data['utility_deposit'];
    }
    if (isset($data['other_fees'])) {
        $fields[] = "other_fees = :other_fees";
        $params['other_fees'] = $data['other_fees'];
    }
    if (isset($data['cash_enabled'])) {
        $fields[] = "cash_enabled = :cash_enabled";
        $params['cash_enabled'] = filter_var($data['cash_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
    }
    if (array_key_exists('gcash_qr_code', $data)) {
        $fields[] = "gcash_qr_code = :gcash_qr_code";
        $params['gcash_qr_code'] = $data['gcash_qr_code'];
        $fields[] = "gcash_updated_at = CURRENT_TIMESTAMP";
    }

    if (!empty($fields)) {
        $sql = "UPDATE boarding_houses SET " . implode(", ", $fields) . " WHERE house_id = :house_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    echo json_encode(["success" => true, "message" => "Boarding house updated successfully."]);

} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!isset($data['house_id'])) {
        echo json_encode(["success" => false, "message" => "House ID is required."]);
        exit();
    }
    $houseId = (int)$data['house_id'];
    $stmt = $pdo->prepare("DELETE FROM boarding_houses WHERE house_id = :house_id");
    $stmt->execute(['house_id' => $houseId]);

    echo json_encode(["success" => true, "message" => "Boarding house deleted successfully."]);
}
?>
