<?php
use PHPUnit\Framework\TestCase;

class DiagTest extends TestCase
{
    public function testFileIsReadable()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        ob_start();
        require __DIR__ . '/../diag.php';
        $output = ob_get_clean();
        $this->assertStringContainsString('Quiz Application', $output);
    }
}