<?php
// Set headers for JSON output
header('Content-Type: application/json; charset=utf-8');

// Include necessary files
require_once __DIR__ . '/config.php';      // For API Key
require_once __DIR__ . '/api/tavily_util.php'; // For the callTavily function

try {
    // Perform a static search
    $query = "philippine law on intellectual property";
    $results = callTavily($query);
    
    // Output the raw JSON response
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    // Output any errors
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>