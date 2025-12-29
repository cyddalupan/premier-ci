<?php
use PHPUnit\Framework\TestCase;

// This global variable will store the arguments passed to the mock function.
$GLOBALS['tavily_call_args'] = null;

/**
 * This is a mock of the real callTavily function.
 * It allows us to intercept the call and check the arguments.
 * IMPORTANT: This mock must be defined before lawgpt_premium.php is included.
 */
function callTavily(string $query, array $sites): array
{
    $GLOBALS['tavily_call_args'] = ['query' => $query, 'sites' => $sites];
    
    // Return a generic, valid-looking response to allow the parent function to continue execution.
    return [
        'results' => [
            ['title' => 'mock title', 'content' => 'mock content']
        ]
    ];
}

// Include the script that contains the function we want to test.
// The mock function above will be used instead of the real one.
require_once __DIR__ . '/../api/lawgpt_premium.php';

class LawGptSearchFormattingTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset the global variable before each test.
        $GLOBALS['tavily_call_args'] = null;
    }

    public function testGetStructuredCaseDataTransformsQueryAndRemovesSiteLimits()
    {
        // Define the input for our test.
        $gr_number = 'G.R. No. 189476';

        // Call the real function. This will trigger our mock `callTavily`.
        get_structured_case_data($gr_number);

        // --- Assertions ---

        // 1. Check that our mock function was actually called.
        $this->assertNotNull($GLOBALS['tavily_call_args'], 'The callTavily function was not called.');

        // 2. Check that the search query was correctly transformed to the "gr_XXXXXX" format.
        $this->assertEquals('gr_189476', $GLOBALS['tavily_call_args']['query'], 'The G.R. number was not transformed into the correct format.');

        // 3. Check that the site restrictions were removed (the 'sites' array should be empty).
        $this->assertEmpty($GLOBALS['tavily_call_args']['sites'], 'The site restrictions were not removed from the search.');
    }
}
