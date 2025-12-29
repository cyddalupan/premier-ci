<?php
// Turn off PHP warnings in output
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// CORS & JSON headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php';  // defines $dsn, $username, $password

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Initialize PDO
try {
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Decode incoming JSON
$input = json_decode(file_get_contents('php://input'), true) ?: [];
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;

// Validate user_id
if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing user_id']);
    exit;
}

// Check premium trial status
try {
    $stmt = $pdo->prepare("
        SELECT has_used_premium_trial FROM users
        WHERE id = :user_id
        LIMIT 1
    ");
    $stmt->execute(['user_id' => $user_id]);
    $has_used_trial = (bool)$stmt->fetchColumn();

    // Return JSON response
    echo json_encode([
        'success' => true,
        'has_used_trial' => $has_used_trial
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to check premium trial status: ' . $e->getMessage()]);
    exit;
}
?>