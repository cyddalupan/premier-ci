<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../config.php';

/**
 * A local copy of the callTavily function for isolated testing.
 */
function callTavily(string $query, array $domains = []): array
{
    $apiKey = TAVILY_API_KEY;
    $url = 'https://api.tavily.com/search';
    $payload = [
        'api_key' => $apiKey,
        'query' => $query,
        'search_depth' => 'advanced',
        'include_answer' => true,
        'max_results' => 5
    ];
    if (!empty($domains)) {
        $payload['include_domains'] = $domains;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $error);
    }
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

/**
 * A local copy of the callXAI function for isolated testing.
 */
function callXAI(array $messages, bool $web_search, bool $high_reasoning, string $model = 'grok-4'): array
{
    $apiKey = X_AI;
    $url = 'https://api.x.ai/v1/chat/completions';
    $payload = [
        'model' => $model,
        'temperature' => 0,
        'messages' => $messages,
        'web_search' => $web_search
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
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $error);
    }
    curl_close($ch);
    return json_decode($resp, true) ?? [];
}

class ExternalSearchApiTest extends TestCase
{
    /**
     * This single test will call the external APIs and print their raw output.
     * Its purpose is purely diagnostic.
     */
    public function testApiResponsesForGr189476()
    {
        // --- 1. Test Tavily API ---
        echo "\n--- [DIAGNOSTIC] PROBING TAVILY API ---\\n";
        $tavily_query = 'gr_189476';
        echo "Tavily Query: " . $tavily_query . "\n";
        $tavily_response = callTavily($tavily_query, []);
        echo "Tavily Raw Response:\n";
        print_r($tavily_response);
        echo "--- END TAVILY PROBE ---\\n";

        // --- 2. Test Grok-4 Web Search ---
        echo "\n--- [DIAGNOSTIC] PROBING GROK-4 WEB SEARCH ---\\n";
        $gr_number = '189476';
        $verification_prompt = <<<EOD
You are a meticulous legal fact-checker AI. Your sole task is to use your web search capability to find the official, correct data for the given G.R. number.

**Search Strategy:**
- **Primary Sources:** Prioritize `lawphil.net` and the official Supreme Court e-Library (`sc.judiciary.gov.ph`).
- **Be Flexible:** Try multiple search query formats to ensure you find the case. For example, search for both "G.R. No. {$gr_number}" and "gr {$gr_number}".

**G.R. No.:** {$gr_number}

Respond ONLY with a single, clean JSON object containing the verified data. Do not add any commentary.

JSON Schema:
{
  "G.R. No.": "string",
  "Case Title": "string",
  "Date of Decision": "string"
}
EOD;
        
        $messages = [['role' => 'system', 'content' => $verification_prompt]];
        $grok_response = callXAI($messages, true, false, 'grok-4');
        echo "Grok-4 Raw Response:\n";
        print_r($grok_response);
        echo "--- END GROK-4 PROBE ---\\n";

        // This test has no assertions. Its only job is to print the output.
        // We add one passing assertion to make PHPUnit report it as 'passed'.
        $this->assertTrue(true);
    }
}