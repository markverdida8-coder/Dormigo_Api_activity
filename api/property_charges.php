<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

function sendPropertyChargeChangeNotification($pdo, $houseId, $houseName, $changeDetail) {
    $msg = "Updated charges for " . $houseName . ":\n• " . $changeDetail;

    // Deduplicate affected students by user_id
    $stuStmt = $pdo->prepare("
        SELECT b.user_id, MAX(b.booking_id) AS booking_id
        FROM bookings b
        JOIN rooms r ON b.room_id = r.room_id
        WHERE r.house_id = ?
          AND UPPER(b.status::text) IN ('PENDING', 'APPROVED')
        GROUP BY b.user_id
    ");
    $stuStmt->execute([$houseId]);
    $students = $stuStmt->fetchAll();

    $nStmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, reference_id) VALUES (?, 'Rental Charges Updated', ?, 'BOOKING', ?)");
    foreach ($students as $stu) {
        $nStmt->execute([$stu['user_id'], $msg, $stu['booking_id']]);
    }
}

if ($method === 'GET') {
    $house_id = isset($_GET['house_id']) ? (int)$_GET['house_id'] : 0;
    if ($house_id <= 0) {
        echo json_encode(["success" => false, "message" => "house_id is required."]);
        exit();
    }

    // Check if house exists in boarding_houses
    $chkHouse = $pdo->prepare("SELECT house_id FROM boarding_houses WHERE house_id = :h");
    $chkHouse->execute(['h' => $house_id]);
    if (!$chkHouse->fetch()) {
        echo json_encode(["success" => true, "data" => ["deposits" => [], "additional_fees" => [], "utilities" => []]]);
        exit();
    }

    $deposits = $pdo->query("SELECT * FROM property_deposits WHERE house_id = $house_id ORDER BY deposit_id ASC")->fetchAll();
    $fees = $pdo->query("SELECT * FROM property_additional_fees WHERE house_id = $house_id ORDER BY fee_id ASC")->fetchAll();
    $utilities = $pdo->query("SELECT * FROM property_utilities WHERE house_id = $house_id ORDER BY utility_id ASC")->fetchAll();

    echo json_encode([
        "success" => true,
        "data" => [
            "deposits" => $deposits,
            "additional_fees" => $fees,
            "utilities" => $utilities
        ]
    ]);
} elseif ($method === 'POST') {
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);

    if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: Only landlords can update property charges."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $house_id = (int)($data['house_id'] ?? 0);

    if ($house_id <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "house_id is required."]);
        exit();
    }

    // Verify property ownership: boarding_houses.landlord_id === authenticated user_id
    $houseChk = $pdo->prepare("SELECT house_id, house_name, landlord_id FROM boarding_houses WHERE house_id = ?");
    $houseChk->execute([$house_id]);
    $houseInfo = $houseChk->fetch();

    if (!$houseInfo) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Property not found."]);
        exit();
    }

    if ((int)$houseInfo['landlord_id'] !== (int)$authUser['user_id']) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: You do not own this property."]);
        exit();
    }

    $houseName = $houseInfo['house_name'];

    try {
        $pdo->beginTransaction();
        $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
        $advisory->execute(['house_id' => $house_id]);
        $lockedHouseStmt = $pdo->prepare(
            'SELECT house_name, landlord_id
             FROM boarding_houses
             WHERE house_id = :house_id
             FOR UPDATE'
        );
        $lockedHouseStmt->execute(['house_id' => $house_id]);
        $lockedHouse = $lockedHouseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedHouse || (int)$lockedHouse['landlord_id'] !== (int)$authUser['user_id']) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Property ownership changed; reload and try again."]);
            exit();
        }
        $houseName = $lockedHouse['house_name'];

        if ($action === 'add_deposit' || $action === 'edit_deposit') {
            $deposit_id = (int)($data['deposit_id'] ?? 0);
            $name = trim($data['deposit_name'] ?? $data['name'] ?? 'Deposit');
            $methodType = $data['charging_method'] ?? $data['type'] ?? 'FIXED';
            $amount = (float)($data['amount'] ?? 0);
            $desc = trim($data['description'] ?? $data['desc'] ?? '');

            // Prevent duplicate Advance Payment or Security Deposit
            if ($deposit_id <= 0 && (strtolower($name) === 'advance payment' || strtolower($name) === 'security deposit')) {
                $chk = $pdo->prepare("SELECT deposit_id FROM property_deposits WHERE house_id = :h AND LOWER(deposit_name) = LOWER(:n)");
                $chk->execute(['h' => $house_id, 'n' => $name]);
                $found = $chk->fetch();
                if ($found) {
                    $deposit_id = (int)$found['deposit_id'];
                }
            }

            $oldAmount = -1.0;
            if ($deposit_id > 0) {
                $oldStmt = $pdo->prepare("SELECT amount FROM property_deposits WHERE deposit_id = ? AND house_id = ? FOR UPDATE");
                $oldStmt->execute([$deposit_id, $house_id]);
                $oldRec = $oldStmt->fetch();
                if ($oldRec) {
                    $oldAmount = (float)$oldRec['amount'];
                }
            }

            if ($deposit_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_deposits SET deposit_name=:n, deposit_type=:t, amount=:a, description=:d WHERE deposit_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 't'=>$methodType, 'a'=>$amount, 'd'=>$desc, 'id'=>$deposit_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_deposits (house_id, deposit_name, deposit_type, amount, description) VALUES (:h, :n, :t, :a, :d)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 't'=>$methodType, 'a'=>$amount, 'd'=>$desc]);
            }

            if ($deposit_id > 0 && $oldAmount >= 0 && abs($oldAmount - $amount) > 0.01) {
                sendPropertyChargeChangeNotification($pdo, $house_id, $houseName, $name . ": ₱" . number_format($oldAmount, 2, '.', ',') . " → ₱" . number_format($amount, 2, '.', ','));
            }

        } elseif ($action === 'add_fee' || $action === 'edit_fee') {
            $fee_id = (int)($data['fee_id'] ?? 0);
            $name = trim($data['fee_name'] ?? $data['name'] ?? 'Fee');
            $fee_type = strtoupper($data['fee_type'] ?? 'ONE_TIME');
            $amount = (float)($data['amount'] ?? 0);
            $desc = trim($data['description'] ?? $data['desc'] ?? '');

            $oldAmount = -1.0;
            if ($fee_id > 0) {
                $oldStmt = $pdo->prepare("SELECT amount FROM property_additional_fees WHERE fee_id = ? AND house_id = ? FOR UPDATE");
                $oldStmt->execute([$fee_id, $house_id]);
                $oldRec = $oldStmt->fetch();
                if ($oldRec) {
                    $oldAmount = (float)$oldRec['amount'];
                }
            }

            if ($fee_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_additional_fees SET fee_name=:n, fee_type=:t, amount=:a, description=:d WHERE fee_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 't'=>$fee_type, 'a'=>$amount, 'd'=>$desc, 'id'=>$fee_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_additional_fees (house_id, fee_name, fee_type, amount, description) VALUES (:h, :n, :t, :a, :d)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 't'=>$fee_type, 'a'=>$amount, 'd'=>$desc]);
            }

            if ($fee_id > 0 && $oldAmount >= 0 && abs($oldAmount - $amount) > 0.01) {
                sendPropertyChargeChangeNotification($pdo, $house_id, $houseName, $name . ": ₱" . number_format($oldAmount, 2, '.', ',') . " → ₱" . number_format($amount, 2, '.', ','));
            }

        } elseif ($action === 'add_utility' || $action === 'edit_utility') {
            $utility_id = (int)($data['utility_id'] ?? 0);
            $name = trim($data['utility_name'] ?? $data['name'] ?? 'Utility');
            $methodType = strtoupper($data['charging_method'] ?? $data['method'] ?? 'FREE');
            $amount = (float)($data['amount'] ?? 0);

            if ($utility_id <= 0) {
                $chk = $pdo->prepare("SELECT utility_id FROM property_utilities WHERE house_id = :h AND LOWER(utility_name) = LOWER(:n)");
                $chk->execute(['h' => $house_id, 'n' => $name]);
                $found = $chk->fetch();
                if ($found) {
                    $utility_id = (int)$found['utility_id'];
                }
            }

            $oldAmount = -1.0;
            if ($utility_id > 0) {
                $oldStmt = $pdo->prepare("SELECT fixed_amount FROM property_utilities WHERE utility_id = ? AND house_id = ? FOR UPDATE");
                $oldStmt->execute([$utility_id, $house_id]);
                $oldRec = $oldStmt->fetch();
                if ($oldRec) {
                    $oldAmount = (float)$oldRec['fixed_amount'];
                }
            }

            if ($utility_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_utilities SET utility_name=:n, charging_method=:m, fixed_amount=:a WHERE utility_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 'm'=>$methodType, 'a'=>$amount, 'id'=>$utility_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount) VALUES (:h, :n, :m, :a)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 'm'=>$methodType, 'a'=>$amount]);
            }

            if ($utility_id > 0 && $oldAmount >= 0 && 'FIXED' === $methodType && abs($oldAmount - $amount) > 0.01) {
                sendPropertyChargeChangeNotification($pdo, $house_id, $houseName, $name . " (Fixed Utility): ₱" . number_format($oldAmount, 2, '.', ',') . " → ₱" . number_format($amount, 2, '.', ','));
            }
        }

        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Charge saved successfully."]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    require_once 'auth_helper.php';
    $authUser = authenticateUser($pdo);

    if (strtoupper($authUser['user_type'] ?? '') !== 'LANDLORD') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: Only landlords can delete property charges."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $house_id = (int)($data['house_id'] ?? 0);
    $item_id = (int)($data['item_id'] ?? 0);

    if ($house_id <= 0 || $item_id <= 0) {
        echo json_encode(["success" => false, "message" => "house_id and item_id are required."]);
        exit();
    }

    $houseChk = $pdo->prepare("SELECT landlord_id FROM boarding_houses WHERE house_id = ?");
    $houseChk->execute([$house_id]);
    $houseInfo = $houseChk->fetch();

    if (!$houseInfo || (int)$houseInfo['landlord_id'] !== (int)$authUser['user_id']) {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Forbidden: You do not own this property."]);
        exit();
    }

    try {
        $pdo->beginTransaction();
        $advisory = $pdo->prepare('SELECT pg_advisory_xact_lock(164731, CAST(:house_id AS integer))');
        $advisory->execute(['house_id' => $house_id]);
        $lockedHouseStmt = $pdo->prepare(
            'SELECT landlord_id FROM boarding_houses WHERE house_id = :house_id FOR UPDATE'
        );
        $lockedHouseStmt->execute(['house_id' => $house_id]);
        $lockedHouse = $lockedHouseStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedHouse || (int)$lockedHouse['landlord_id'] !== (int)$authUser['user_id']) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(["success" => false, "message" => "Property ownership changed; reload and try again."]);
            exit();
        }
        if ($action === 'delete_deposit') {
            $pdo->prepare("DELETE FROM property_deposits WHERE deposit_id=? AND house_id=?")->execute([$item_id, $house_id]);
        } elseif ($action === 'delete_fee') {
            $pdo->prepare("DELETE FROM property_additional_fees WHERE fee_id=? AND house_id=?")->execute([$item_id, $house_id]);
        } elseif ($action === 'delete_utility') {
            $pdo->prepare("DELETE FROM property_utilities WHERE utility_id=? AND house_id=?")->execute([$item_id, $house_id]);
        }
        $pdo->commit();
        echo json_encode(["success" => true, "message" => "Item deleted successfully."]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
