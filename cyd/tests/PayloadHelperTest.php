<?php
use PHPUnit\Framework\TestCase;

// Including the script is problematic as it's a controller, not a library.
// This will likely cause errors during testing.
require_once __DIR__ . '/../api/lawgpt_premium.php';

class PayloadHelperTest extends TestCase
{
    public function testCalculatePayloadSize()
    {
        $messages = [
            ['role' => 'system', 'content' => 'Hello'], // 5 chars
            ['role' => 'user', 'content' => 'World'], // 5 chars
            ['role' => 'assistant', 'content' => 'Test'], // 4 chars
            ['role' => 'user', 'content' => ''], // 0 chars
            ['role' => 'user'] // no content key
        ];
        
        $this->assertEquals(14, calculate_payload_size($messages));
    }

    public function testCalculatePayloadSizeWithEmptyArray()
    {
        $messages = [];
        $this->assertEquals(0, calculate_payload_size($messages));
    }

    public function testCalculatePayloadSizeWithMultiByteChars()
    {
        // strlen counts bytes, not characters. This is the expected behavior for payload calculation.
        $messages = [
            ['role' => 'user', 'content' => '你好'] // 6 bytes
        ];
        $this->assertEquals(6, calculate_payload_size($messages));
    }
}
