<?php
if (!defined('ENV')) {
    define('ENV', 'prod');
}
if (ENV == "dev" && php_sapi_name() !== 'cli') {    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);    header("Pragma: no-cache");
}
function logMessage($message) {    $logDir = __DIR__ . '/../logs';
    $logFile = $logDir . '/api.log';    
    if (!is_dir($logDir)) {        mkdir($logDir, 0755, true);
    }    
    $timestamp = date('Y-m-d H:i:s');    file_put_contents($logFile, "[$timestamp] $message
", FILE_APPEND | LOCK_EX);
}
function callGrokAI($userInput, $expected){
    $apiKey = X_AI;    $url = 'https://api.x.ai/v1/chat/completions';
    $maxRetries = 3;    $attempt = 0;

    while ($attempt < $maxRetries) {
        try {
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey
            ];

            $postData = json_encode([
                "model" => "grok-4",
                "temperature" => 0,
                "messages" => [
                    [
                        "role" => "system",
                        "content" => <<<EOD
Compare the user_answer to expected_answer and output only a valid JSON object with:
- "score": integer (1-100, 100 for full match, 70-95 for close match, 0-30 for mismatch).
- "feedback": string (Bootstrap-styled HTML table that evaluates the following criteria: Answer, Legal Basis  (without need for specific legal citation), Application, Conclusion, and Legal Writing. 
    - Each criterion should be graded individually (5/5 if perfect). 
    - Show subtotal per criterion (max 5 points each, total 25 = 100%).
    - Provide explanations for mistakes under each criterion.
    - After the table, include an "Additional Insights" section in plain text containing:
        a) The correct expected_answer (either provided or AI-generated if missing).
        b) A section titled: 
           🔎 Mistakes 
           ❌ List each mistake clearly and specifically.
        c) Suggestions for improvement.
        d) If the user scored perfectly, congratulate them in this section.
EOD
                    ],
                    [
                        "role" => "system",
                        "content" => "expected_answer: $expected"
                    ],
                    [
                        "role" => "user",
                        "content" => "user_answer: $userInput"
                    ]
                ]
            ]);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $response = curl_exec($ch);
            if ($response === false) {
                throw new Exception('cURL Error: ' . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            logMessage("API HTTP status: " . $httpCode . " for callGrokAI");
            if ($httpCode !== 200) {
                logMessage("Raw API response on non-200: " . $response);
                throw new Exception('HTTP error: ' . $httpCode);
            }

            $data = json_decode($response, true);
            if ($data === null) {
                logMessage("JSON decode error: " . json_last_error_msg());
                logMessage("Raw API response: " . $response);
                throw new Exception('Invalid JSON response');
            }

            logMessage("Decoded API response: " . json_encode($data, JSON_PRETTY_PRINT));

            if (isset($data['error'])) {
                throw new Exception('xAI API Error: ' . json_encode($data['error']));
            }

            if (!isset($data['choices'][0]['message']['content'])) {
                throw new Exception('Invalid Grok-4 response or missing content');
            }

            curl_close($ch);
            return $data;
        } catch (Exception $e) {
            $attempt++;
            curl_close($ch);
            logMessage("Grok-4 call attempt $attempt failed: " . $e->getMessage());
            if ($attempt >= $maxRetries) {
                throw new Exception('Grok-4 API call failed after retries: ' . $e->getMessage());
            }
            sleep(1);
        }
    }
}
function calculateAverageScore($answers, $totalQuestions)
{
    $totalScore = 0;
    $count = $totalQuestions;

    foreach ($answers as $answer) {
        if (isset($answer['score'])) {
            $totalScore += $answer['score'];
        }
    }

    return $count > 0 ? $totalScore / $count : 0;
}

function summarizeFeedback($answers)
{
    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';

    try {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $messages = [["role" => "system", "content" => "Provide a student summary based on the following feedback and scores. in less than 200 characters"]];

        foreach ($answers as $answer) {
            $messages[] = [
                "role" => "user",
                "content" => "Score: {$answer['score']}. Feedback: {$answer['feedback']}"
            ];
        }

        $postData = json_encode([
            "model" => "grok-4",
            "messages" => $messages,
        ]);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        logMessage("API HTTP status: " . $httpCode . " for summarizeFeedback");
        if ($httpCode !== 200) {
            logMessage("Raw API response on non-200: " . $response);
            throw new Exception('HTTP error: ' . $httpCode);
        }

        $data = json_decode($response, true);
        if ($data === null) {
            logMessage("JSON decode error: " . json_last_error_msg());
            logMessage("Raw API response: " . $response);
            throw new Exception('Invalid JSON response');
        }

        logMessage("Decoded API response: " . json_encode($data, JSON_PRETTY_PRINT));

        if (isset($data['error'])) {
            throw new Exception('xAI API Error: ' . json_encode($data['error']));
        }

        curl_close($ch);
        return $data['choices'][0]['message']['content'] ?? 'No summary available';
    } catch (Exception $e) {
        logMessage("summarizeFeedback error: " . $e->getMessage());
        return 'Error generating summary';
    }
}

function ai_email_diagnose($answers, $fullname)
{
    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';

    try {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ];

        $messages = [["role" => "system", "content" => "Provide a student (name: $fullname) an assessment email (just the body of the email in HTML format) content based on the following feedback and scores, but do not follow the feedback format, this needs to convince the student to use our online course 'TopBar Asssist PH'. note: the result will be emailed directly to do not put variable or text thats needed to be changed"]];

        foreach ($answers as $answer) {
            $messages[] = [
                "role" => "user",
                "content" => "Score: {$answer['score']}. Feedback: {$answer['feedback']}"
            ];
        }

        $postData = json_encode([
            "model" => "grok-4",
            "messages" => $messages,
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new Exception('cURL Error: ' . curl_error($ch));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        logMessage("API HTTP status: " . $httpCode . " for ai_email_diagnose");
        if ($httpCode !== 200) {
            logMessage("Raw API response on non-200: " . $response);
            throw new Exception('HTTP error: ' . $httpCode);
        }

        $data = json_decode($response, true);
        if ($data === null) {
            logMessage("JSON decode error: " . json_last_error_msg());
            logMessage("Raw API response: " . $response);
            throw new Exception('Invalid JSON response');
        }

        logMessage("Decoded API response: " . json_encode($data, JSON_PRETTY_PRINT));

        if (isset($data['error'])) {
            throw new Exception('xAI API Error: ' . json_encode($data['error']));
        }

        curl_close($ch);
        return $data['choices'][0]['message']['content'] ?? '<p>Error generating email content</p>';
    } catch (Exception $e) {
        logMessage("ai_email_diagnose error: " . $e->getMessage());
        return '<p>Unable to generate assessment email at this time.</p>';
    }
}

function processResponse($pdo, $userId, $questionId, $userInput, $courseId, $response, $is_practice, &$score, &$feedback)
{
    try {
        $content = $response['choices'][0]['message']['content'];
        logMessage("Response content for userId=$userId, questionId=$questionId: " . $content);
        if (empty($content)) {
            throw new Exception('Empty response content from Grok-4');
        }
        $decodedParams = json_decode($content, true);
        if ($decodedParams === null) {
            logMessage("JSON decode error in processResponse: " . json_last_error_msg());
            throw new Exception('Invalid JSON in response content');
        }
        if (!isset($decodedParams['score']) || !isset($decodedParams['feedback'])) {
            throw new Exception('Missing score or feedback in JSON');
        }
        $score = (int)$decodedParams['score'];
        $feedback = $decodedParams['feedback'];
        if (!$is_practice) {
            insertAnswer($pdo, $userId, $questionId, $userInput, $courseId, $score, $feedback);
        }
    } catch (Exception $e) {
        logMessage("processResponse error for userId=$userId, questionId=$questionId: " . $e->getMessage());
        $score = 0;
        $feedback = 'Error processing response';
    }
}

function manageTimer($pdo, $userId, $courseId, $is_practice, $totalQuestions, $timer_minutes)
{
    try {
        if ($is_practice) {
            return 9999;
        } elseif (isset($_POST['remaining-seconds'])) {
            $remainingSeconds = max(0, (int)$_POST['remaining-seconds']);
            updateRemainingSeconds($pdo, $userId, $remainingSeconds, $courseId);
            return $remainingSeconds;
        } else {
            $existingData = getRemainingSeconds($pdo, $userId, $courseId);
            if ($existingData && $existingData['remaining_seconds'] > 0) {
                return $existingData['remaining_seconds'];
            } else {
                createUserCourse($pdo, $userId, $courseId, $totalQuestions, $timer_minutes);
                return max(0, ($timer_minutes * 60) * $totalQuestions);
            }
        }
    } catch (Exception $e) {
        logMessage("manageTimer error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        return 0;
    }
}

function calculateProgress($pdo, $userId, $courseId, $is_practice, $remainingSeconds, $totalQuestions, &$answerCount)
{
    try {
        if ($is_practice) {
           return 0;
        } else {
            $answerCount = countUserAnswers($pdo, $userId, $courseId);
            return $remainingSeconds === 0 ? 100 : ($answerCount / $totalQuestions) * 100;
        }
    } catch (Exception $e) {
        logMessage("calculateProgress error for userId=$userId, courseId=$courseId: " . $e->getMessage());
        $answerCount = 0;
        return 0;
    }
}

function finalizeAssessment($pdo, $userId, $courseId, $totalQuestions, &$answers, &$averageScore)
{
    try {
        $answers = getAllUserAnswers($pdo, $userId, $courseId);
        $averageScore = calculateAverageScore($answers, $totalQuestions);
        if (!hasSummary($pdo, $userId, $courseId)) {
            $summary = summarizeFeedback($answers);
            updateSummary($pdo, $userId, $courseId, $averageScore, $summary);
            $user = getCurrentUser($pdo, $userId);
            if ($user) {
                $ai_email_diagnose = ai_email_diagnose($answers, $user['first_name'] . " " . $user['last_name']);
                send_email_with_phpmailer($pdo, $user['email'], 'Diagnostic Exam', $ai_email_diagnose, 'ehajjonlinephilippines@gmail.com');
            } else {
                logMessage("User not found for userId=$userId during finalization");
            }
        }
    } catch (Exception $e) {
        logMessage("finalizeAssessment error for userId=$userId, courseId=$courseId: " . $e->getMessage());
    }
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