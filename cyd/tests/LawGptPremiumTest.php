<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lawgpt_premium.php';

class LawGptPremiumTest extends TestCase
{
    public function testGetLastUserMessage()
    {
        $messages = [
            ['role' => 'system', 'content' => 'System message'],
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
            ['role' => 'user', 'content' => 'How are you?'],
        ];

        $this->assertEquals('How are you?', getLastUserMessage($messages));
    }

    public function testGetLastUserMessageNoUserMessage()
    {
        $messages = [
            ['role' => 'system', 'content' => 'System message'],
            ['role' => 'assistant', 'content' => 'Hi there!'],
        ];

        $this->assertEquals('', getLastUserMessage($messages));
    }

    public function testGetLastUserMessageEmptyMessages()
    {
        $messages = [];

        $this->assertEquals('', getLastUserMessage($messages));
    }
}
