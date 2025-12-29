<?php
use PHPUnit\Framework\TestCase;

class LawgptTest extends TestCase
{
    /**
     * @runInSeparateProcess
     */
    public function testMissingInputReturnsError()
    {
        // Simulate a POST request with missing input
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        include __DIR__ . '/../../api/lawgpt.php';
        $output = ob_get_clean();

        $expectedJson = json_encode(['error' => 'Missing thread_id, user_id, or conversation']);
        $this->assertJsonStringEqualsJsonString($expectedJson, $output);
    }

    /**
     * @runInSeparateProcess
     */
    public function testOptionsRequestExitsCleanly()
    {
        // Simulate an OPTIONS request
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        // Capture output
        ob_start();
        include __DIR__ . '/../../api/lawgpt.php';
        $output = ob_get_clean();

        // For OPTIONS, the script should exit after setting headers, producing no body output.
        $this->assertEmpty($output);
    }
}
