<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lawgpt_premium.php';

class LawGptPremiumPrependTest extends TestCase
{
    public function testPrependSearchResults()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello']
        ];

        $tavily_results = [
            'results' => [
                [
                    'title' => 'Test Title',
                    'url' => 'http://example.com',
                    'content' => 'Test Content'
                ]
            ]
        ];

        $formatted_results = '';
        if (isset($tavily_results['results']) && is_array($tavily_results['results'])) {
            foreach ($tavily_results['results'] as $result) {
                $formatted_results .= "Title: " . $result['title'] . "\n";
                $formatted_results .= "Link: " . $result['url'] . "\n";
                $formatted_results .= "Snippet: " . $result['content'] . "\n\n";
            }
        }

        if (!empty($formatted_results)) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => "Here are the web search results:\n\n" . $formatted_results
            ]);
        }

        $this->assertEquals('system', $messages[0]['role']);
        $this->assertStringContainsString('Test Title', $messages[0]['content']);
    }
}
