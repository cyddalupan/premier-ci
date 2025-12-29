<?php
use PHPUnit\Framework\TestCase;

class LawGptPremiumRootApiTest extends TestCase
{
    private $original_dir;

    protected function setUp(): void
    {
        $this->original_dir = getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->original_dir);
    }

    public function testApiDbConnectionError()
    {
        chdir(__DIR__ . '/../api');
        $api_script_content = file_get_contents('lawgpt_premium.php');

        // The script will fail because of the database connection.
        // We assert that the error is what we expect.
        ob_start();
        eval('?>' . $api_script_content);
        $output = ob_get_clean();

        $this->assertJson($output);
        $decoded_output = json_decode($output, true);
        $this->assertArrayHasKey('error', $decoded_output);
        $this->assertStringContainsString('Database connection failed', $decoded_output['error']);
    }
}
