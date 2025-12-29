<?php
// Turn off PHP warnings in output
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);

// CORS & JSON headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

require '../config.php';  // defines X_AI, $dsn, $username, $password

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

// Build messages array and store user messages in database
$messages = [];
$recap_messages = [];
foreach ($conversation as $m) {
    $from = strtolower(trim($m['from'] ?? 'user'));
    $text = $m['text'] ?? '';
    $role = in_array($from, ['assistant', 'bot', 'ai']) ? 'assistant' : ($from === 'system' ? 'system' : 'user');
    
    if (!is_string($text)) {
        $text = is_scalar($text) ? (string)$text : json_encode($text, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    
    // Store user message
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_history (thread_id, user_id, `from`, `text`, `role`, created_at)
            VALUES (:thread_id, :user_id, :from, :text, :role, NOW())
        ");
        $stmt->execute([
            'thread_id' => $thread_id,
            'user_id' => $user_id,
            'from' => $from,
            'text' => $text,
            'role' => $role
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save message: ' . $e->getMessage()]);
        exit;
    }
    
    $messages[] = [
        'role' => $role,
        'content' => $text
    ];
    
    // Collect messages for recap
    if ($role !== 'system') {
        $recap_messages[] = [
            'role' => $role,
            'content' => $text
        ];
    }
}

// Prepend system prompt
array_unshift($messages, [
    'role' => 'system',
    'content' => 'Ensure all responses are formatted in HTML using Bootstrap 5 classes and Font Awesome icons, containing only content that would reside within a <div> tag, excluding <html> or <body> tags. Focus solely on Philippine law, redirecting off-topic questions to relevant legal subjects and verifying compliance with the provided article number. When needed, activate web search to fetch the latest data on Philippine laws, prioritizing information from reputable sources such as https://lawphil.net/, https://www.officialgazette.gov.ph/section/republic-acts/, https://sc.judiciary.gov.ph/, and https://chanrobles.com/ to ensure accuracy.

- Fully understand the user\'s request and prioritize the latest created law before providing a detailed answer.
- Collect all necessary information or clarify questions.
- Provide detailed answers with basis, examples, and all relevant information, beginning with a conclusion summary or key finding.
- Include a suggestion for a related or potentially needed next topic at the bottom of each response in a Bootstrap alert.
- At the end of each response, include a recap section in a Bootstrap card that summarizes the key points of the conversation so far in a productive way to maintain context for future replies. The recap should be concise, relevant to Philippine law, and formatted in HTML with Bootstrap 5 and Font Awesome icons.

Output Format:
All outputs must use Bootstrap 5 components and Font Awesome icons, starting with a <div> tag. No markdown or line breaks outside HTML structure. Ensure the article number provided is accurate.

Notes:
- Ensure all references to articles are correct and precise.
- Maintain strict topic relevance to specified Philippine law topics.
- Ensure the HTML format inside <div> and no markdown or backslash formats.'
]);

// Get today's message count for the user
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as message_count
        FROM chat_history
        WHERE user_id = :user_id
        AND role = 'user'
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute(['user_id' => $user_id]);
    $message_count = $stmt->fetch(PDO::FETCH_ASSOC)['message_count'];
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve message count: ' . $e->getMessage()]);
    exit;
}

// Call xAI
try {
    $ai = callXAI($messages);
    $reply = $ai['choices'][0]['message']['content'] ?? '';
    
    // Store AI response in database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO chat_history (thread_id, user_id, `from`, `text`, `role`, created_at)
            VALUES (:thread_id, :user_id, :from, :text, :role, NOW())
        ");
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
 * Fire off a chat-completions request
 */
function callXAI(array $messages): array
{
    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';

    $payload = [
        'model' => 'grok-3-mini',
        'temperature' => 0,
        'messages' => $messages
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
        throw new Exception('xAI API Error: ' . json_encode($decoded['error']));
    }
    return $decoded;
}
?>
