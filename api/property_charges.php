<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];

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

    // Step 1: Safely delete duplicate Electricity and Water records for this house, keeping the oldest valid record (MIN utility_id)
    $pdo->exec("DELETE FROM property_utilities WHERE house_id = $house_id AND utility_id NOT IN (
        SELECT MIN(utility_id) FROM property_utilities WHERE house_id = $house_id GROUP BY LOWER(utility_name)
    )");

    // Step 2: Safely delete duplicate Advance Payment and Security Deposit records, keeping the oldest valid record (MIN deposit_id)
    $pdo->exec("DELETE FROM property_deposits WHERE house_id = $house_id AND deposit_id NOT IN (
        SELECT MIN(deposit_id) FROM property_deposits WHERE house_id = $house_id GROUP BY LOWER(deposit_name)
    ) AND LOWER(deposit_name) IN ('advance payment', 'security deposit')");

    // Step 3: Check existing utilities for house (case-insensitive)
    $chkUtil = $pdo->prepare("SELECT LOWER(utility_name) FROM property_utilities WHERE house_id = :h");
    $chkUtil->execute(['h' => $house_id]);
    $existingUtils = array_map('strtolower', $chkUtil->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array('electricity', $existingUtils)) {
        $ins = $pdo->prepare("INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount) VALUES (:h, 'Electricity', 'CONSUMPTION_BASED', 0.00)");
        $ins->execute(['h' => $house_id]);
    }
    if (!in_array('water', $existingUtils)) {
        $ins = $pdo->prepare("INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount) VALUES (:h, 'Water', 'FREE', 0.00)");
        $ins->execute(['h' => $house_id]);
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
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $house_id = (int)($data['house_id'] ?? 0);

    if ($house_id <= 0) {
        echo json_encode(["success" => false, "message" => "house_id is required."]);
        exit();
    }

    try {
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

            if ($deposit_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_deposits SET deposit_name=:n, deposit_type=:t, amount=:a, description=:d WHERE deposit_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 't'=>$methodType, 'a'=>$amount, 'd'=>$desc, 'id'=>$deposit_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_deposits (house_id, deposit_name, deposit_type, amount, description) VALUES (:h, :n, :t, :a, :d)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 't'=>$methodType, 'a'=>$amount, 'd'=>$desc]);
            }
        } elseif ($action === 'add_fee' || $action === 'edit_fee') {
            $fee_id = (int)($data['fee_id'] ?? 0);
            $name = trim($data['fee_name'] ?? $data['name'] ?? 'Fee');
            $fee_type = strtoupper($data['fee_type'] ?? 'ONE_TIME');
            $amount = (float)($data['amount'] ?? 0);
            $desc = trim($data['description'] ?? $data['desc'] ?? '');

            if ($fee_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_additional_fees SET fee_name=:n, fee_type=:t, amount=:a, description=:d WHERE fee_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 't'=>$fee_type, 'a'=>$amount, 'd'=>$desc, 'id'=>$fee_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_additional_fees (house_id, fee_name, fee_type, amount, description) VALUES (:h, :n, :t, :a, :d)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 't'=>$fee_type, 'a'=>$amount, 'd'=>$desc]);
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

            if ($utility_id > 0) {
                $stmt = $pdo->prepare("UPDATE property_utilities SET utility_name=:n, charging_method=:m, fixed_amount=:a WHERE utility_id=:id AND house_id=:h");
                $stmt->execute(['n'=>$name, 'm'=>$methodType, 'a'=>$amount, 'id'=>$utility_id, 'h'=>$house_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO property_utilities (house_id, utility_name, charging_method, fixed_amount) VALUES (:h, :n, :m, :a)");
                $stmt->execute(['h'=>$house_id, 'n'=>$name, 'm'=>$methodType, 'a'=>$amount]);
            }
        }

        echo json_encode(["success" => true, "message" => "Charge saved successfully."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
} elseif ($method === 'DELETE') {
    $data = json_decode(file_get_contents("php://input"), true);
    $action = $data['action'] ?? '';
    $house_id = (int)($data['house_id'] ?? 0);
    $item_id = (int)($data['item_id'] ?? 0);

    if ($house_id <= 0 || $item_id <= 0) {
        echo json_encode(["success" => false, "message" => "house_id and item_id are required."]);
        exit();
    }

    try {
        if ($action === 'delete_deposit') {
            $pdo->prepare("DELETE FROM property_deposits WHERE deposit_id=? AND house_id=?")->execute([$item_id, $house_id]);
        } elseif ($action === 'delete_fee') {
            $pdo->prepare("DELETE FROM property_additional_fees WHERE fee_id=? AND house_id=?")->execute([$item_id, $house_id]);
        } elseif ($action === 'delete_utility') {
            $pdo->prepare("DELETE FROM property_utilities WHERE utility_id=? AND house_id=?")->execute([$item_id, $house_id]);
        }
        echo json_encode(["success" => true, "message" => "Item deleted successfully."]);
    } catch (PDOException $e) {
        echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
    }
}
?>
