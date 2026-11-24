
<?php

require_once __DIR__ . '/php/index_config.php';

try {

    $pdo = db();

    echo "SUCCESS! Connected to Residentia database.";

    // Check if accounts table exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM accounts");

    echo "<br>Accounts table found!";
    echo "<br>Total accounts: " . $stmt->fetchColumn();

} catch (PDOException $e) {

    echo "DATABASE CONNECTION FAILED!";
    echo "<br>Error: " . htmlspecialchars($e->getMessage());

}