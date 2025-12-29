<?php
/**
 * Fire off a search request to Tavily
 */
function callTavily(string $query, array $domains = []): array
{
    if (isset($_GET['test_mode']) && $_GET['test_mode'] === 'true') {
        return [
            "results" => [
                [
                    "title" => "Mock Tavily Result",
                    "url" => "https://example.com/mock-result",
                    "content" => "This is a mock search result from Tavily."
                ]
            ]
        ];
    }

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
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL Error: ' . $error);
    }
    curl_close($ch);

    if ($http_code !== 200) {
        throw new Exception("Tavily API request failed with status $http_code: $resp");
    }

    $decoded = json_decode($resp, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode JSON response from Tavily API: ' . json_last_error_msg() . ". Raw response: " . $resp);
    }

    if (!empty($decoded['error'])) {
        throw new Exception('Tavily API Error: ' . json_encode($decoded['error']));
    }

    return $decoded;
}
?>