<?php
require_once 'db.php';

$results = [];
$databases = ["postgres", "dormigo", "dormigo_db"];

foreach ($databases as $db) {
    try {
        $dsn = "pgsql:host=localhost;port=5432;dbname=$db;";
        $testPdo = new PDO($dsn, "postgres", "root");
        $tables = $testPdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")->fetchAll(PDO::FETCH_COLUMN);
        $results[$db] = $tables;
    } catch (Exception $e) {
        $results[$db] = "Error: " . $e->getMessage();
    }
}

echo json_encode([
    "success" => true,
    "database_tables_summary" => $results
], JSON_PRETTY_PRINT);
?>
