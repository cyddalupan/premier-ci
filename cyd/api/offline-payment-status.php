<?php
// offline-payment-status.php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php'; // provides $dsn, $username, $password

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, // Good practice for consistent fetching
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed: ' . $e->getMessage()]);
    exit;
}

// Accept user_id from GET
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id']);
    exit;
}

// Check for paid record in offline_payment
// Let's simplify the conditions for amount and rely on SQL's implicit type conversion
// Also, fetch the amount for debugging purposes if needed.
$stmt = $pdo->prepare(
    "SELECT amount
     FROM offline_payment
     WHERE user_id = :user_id
       AND amount IS NOT NULL
       AND amount > 0
     LIMIT 1"
);

try {
    $stmt->execute(['user_id' => $user_id]);
    $result = $stmt->fetch();

    // Debugging: uncomment the next two lines to see what was fetched
    // error_log("SQL Result for user_id $user_id: " . print_r($result, true));

    $hasPaid = $result ? true : false;

    echo json_encode(['paid' => $hasPaid]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database query failed: ' . $e->getMessage()]);
    // Log the error for server-side debugging
    error_log("Error executing payment status query: " . $e->getMessage());
    exit;
}

?>
