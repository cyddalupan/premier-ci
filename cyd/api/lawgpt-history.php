<?php
// lawgpt-history.php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php'; // for $dsn, $username, $password

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB connect failed: ' . $e->getMessage()]);
    exit;
}

// Accept user_id from GET or POST
$user_id = 0;
if(isset($_GET['user_id'])) $user_id = (int)$_GET['user_id'];
if(isset($_POST['user_id'])) $user_id = (int)$_POST['user_id'];
if (!$user_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing user_id']);
    exit;
}

// Query: get all chats by user, sorted by thread then date
$stmt = $pdo->prepare(
    "SELECT thread_id, `from`, `text`, role, created_at, file_id
     FROM chat_history
     WHERE user_id = :user_id
     ORDER BY thread_id, created_at"
);
$stmt->execute(['user_id' => $user_id]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group into threads
$threads = [];
foreach($rows as $row) {
    $tid = $row['thread_id'];
    // Initialize if not seen
    if (!isset($threads[$tid])) {
        $threads[$tid] = [
            'thread_id' => $tid,
            'title' => null,
            'timestamp' => $row['created_at'],
            'messages' => [],
        ];
    }

    // First user message as thread title
    if (!$threads[$tid]['title'] && $row['role'] == 'user') {
        $text = strip_tags($row['text']); // or keep as-is
        $threads[$tid]['title'] = mb_strimwidth($text, 0, 50, '...');
    }

    $msgFrom = ($row['role'] == 'assistant') ? 'bot' : $row['role'];
    
    $msg = [
        'from' => $msgFrom,
        'text' => $row['text'],
        'timestamp' => $row['created_at'],
    ];
    // Include file_id if needed
    if ($row['file_id']) $msg['file_id'] = $row['file_id'];
    $threads[$tid]['messages'][] = $msg;
}
// Ensure last activity for thread timestamp
foreach($threads as &$t) {
    if (count($t['messages']) > 0) {
        $t['timestamp'] = end($t['messages'])['timestamp'];
        if (!$t['title']) $t['title'] = 'Conversation';
    }
}
unset($t);

// Output as indexed array, newest threads first
usort($threads, fn($a, $b) => strcmp($b['timestamp'], $a['timestamp']));
echo json_encode(array_values($threads), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
