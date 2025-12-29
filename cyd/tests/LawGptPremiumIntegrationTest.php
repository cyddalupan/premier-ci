<?php
use PHPUnit\Framework\TestCase;

class LawGptPremiumIntegrationTest extends TestCase
{
    private function runTestWithWebSearch(bool $webSearchFlag)
    {
        // Input data for the POST request
        $postData = [
            'thread_id' => 'test-thread-123',
            'user_id' => 1,
            'conversation' => [
                ['from' => 'user', 'text' => 'What is the weather like?']
            ],
            'web_search' => $webSearchFlag
        ];

        // Path to the script to be tested
        $scriptPath = __DIR__ . '/../api/lawgpt_premium.php?test_mode=true';

        // Execute the script with a POST request
        $command = sprintf(
            'php -f %s',
            escapeshellarg($scriptPath)
        );

        $process = proc_open($command, [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ], $pipes);

        $output = '';
        $error = '';
        if (is_resource($process)) {
            // Write the POST data to the script's stdin
            fwrite($pipes[0], json_encode($postData));
            fclose($pipes[0]);

            // Read the script's output
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Read the script's error output
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            // Close the process
            proc_close($process);
        }

        // Decode the JSON output
        $response = json_decode($output, true);

        // Assertions
        $this->assertNull($response['error'], "Unexpected error in response: $error");
        $this->assertArrayHasKey('response', $response);
        return $response['response'];
    }

    public function testIntegrationWithWebSearchEnabled()
    {
        $response = $this->runTestWithWebSearch(true);
        $this->assertStringContainsString('Here are the web search results:', $response);
    }

    public function testWebSearchIsAlwaysCalled()
    {
        $response = $this->runTestWithWebSearch(false);
        $this->assertStringContainsString('Here are the web search results:', $response);
    }
}