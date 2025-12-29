<?php
// Turn off PHP warnings in output
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// CORS & JSON headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php';  // defines OPENAI_API_KEY, $dsn, $username, $password

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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
$thread_id = $input['thread_id'] ?? '';
$user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
$conversation = $input['conversation'] ?? [];

// Validate input
if (!$thread_id || !$user_id || !$conversation) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing thread_id, user_id, or conversation']);
    exit;
}

// Build messages array
$messages = [];

// Take only the last 3 messages
$conversation = array_slice($conversation, -3);

foreach ($conversation as $m) {
    $from = strtolower(trim($m['from'] ?? 'user'));
    $text = $m['text'] ?? '';
    $role = in_array($from, ['assistant', 'bot', 'ai']) ? 'assistant' : ($from === 'system' ? 'system' : 'user');

    if (!is_string($text)) {
        $text = is_scalar($text) ? (string)$text : json_encode($text, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    $messages[] = [
        'role' => $role,
        'content' => $text
    ];
}

// Prepend system prompt
array_unshift($messages, [
    'role' => 'system',
    'content' => "You are GPT-5, an AI assistant specializing in Philippine law. 
You must always perform a web search to ensure your information is up-to-date.
First, ask clarifying questions to fully understand the user's request. 
Only after gathering enough details, perform a deep search. 
When searching, prioritize authoritative sources such as:
- https://lawphil.net/
- https://www.officialgazette.gov.ph/section/republic-acts/
- https://sc.judiciary.gov.ph/
but you may use other reliable sources when needed. 
Provide accurate, detailed, and well-structured answers in Markdown."
]);

// Get today's message count for the user
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) as message_count
        FROM chat_history
        WHERE user_id = :user_id
        AND role = 'user'
        AND DATE(created_at) = CURDATE()"
    );
    $stmt->execute(['user_id' => $user_id]);
    $message_count = $stmt->fetch(PDO::FETCH_ASSOC)['message_count'];
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve message count: ' . $e->getMessage()]);
    exit;
}

// Call OpenAI
try {
    $ai = callOpenAI($messages);
    $reply = $ai['choices'][0]['message']['content'] ?? '';
    
    // Store AI response in database
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO chat_history (thread_id, user_id, `from`, `text`, `role`, created_at)
            VALUES (:thread_id, :user_id, :from, :text, :role, NOW())"
        );
        $stmt->execute([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'from' => 'assistant',
            'text' => $reply,
            'role' => 'assistant'
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save AI response: ' . $e->getMessage()]);
        exit;
    }
    
    echo json_encode(
        [
            'response' => $reply,
            'message_count_today' => (int)$message_count
        ],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Fire off a chat-completions request to OpenAI
 */
function callOpenAI(array $messages): array
{
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if (!$apiKey) {
        throw new Exception('OPENAI_API_KEY is not defined in config.php.');
    }
    $url = 'https://api.openai.com/v1/chat/completions';

    $payload = [
        'model' => 'gpt-5',
        'messages' => $messages,
        'tools' => [
            [
                'type' => 'web_search',
                'web_search' => [
                    'context_size' => 'high'
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode($resp, true);
    if (isset($decoded['error'])) {
        throw new Exception('OpenAI API Error: ' . json_encode($decoded['error']));
    }
    return $decoded;
}