<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT bh.*, array_to_json(array_remove(array_agg(hp.photo_path), NULL)) AS photo_paths
                          FROM boarding_houses bh
                          LEFT JOIN house_photos hp ON bh.house_id = hp.house_id
                          GROUP BY bh.house_id
                          ORDER BY bh.house_id DESC");
    $houses = $stmt->fetchAll();
    foreach ($houses as &$house) {
        if (isset($house['photo_paths'])) {
            $house['photo_paths'] = json_decode($house['photo_paths']);
        }
    }
    echo json_encode(["success" => true, "data" => $houses]);

} elseif ($method === 'POST') {
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

        $stmt = $pdo->prepare("INSERT INTO boarding_houses (landlord_id, house_name, description, address, house_rules, status)
                                VALUES (:landlord_id, :house_name, :description, :address, :house_rules, :status::house_status_enum) RETURNING house_id");
        $stmt->execute([
            'landlord_id' => $data['landlord_id'],
            'house_name' => $data['house_name'],
            'description' => $data['description'] ?? '',
            'address' => $data['address'] ?? '',
            'house_rules' => $data['house_rules'] ?? '',
            'status' => strtoupper($data['status'] ?? 'INACTIVE')
        ]);
        $houseId = $stmt->fetchColumn();

        if (isset($data['room'])) {
            $room = $data['room'];
            $roomStmt = $pdo->prepare("INSERT INTO rooms (house_id, room_number, room_type, capacity, monthly_rent, status)
                                       VALUES (:house_id, :room_number, :room_type, :capacity, :monthly_rent, :status::room_status_enum)");
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
    $stmt = $pdo->prepare("UPDATE boarding_houses SET house_name = :house_name, description = :description, address = :address, house_rules = :house_rules, status = :status::house_status_enum WHERE house_id = :house_id");
    $stmt->execute([
        'house_id' => $data['house_id'],
        'house_name' => $data['house_name'],
        'description' => $data['description'] ?? '',
        'address' => $data['address'] ?? '',
        'house_rules' => $data['house_rules'] ?? '',
        'status' => strtoupper($data['status'] ?? 'INACTIVE')
    ]);
    echo json_encode(["success" => true, "message" => "Boarding house updated successfully."]);
}
?>
