<?php
use PHPUnit\Framework\TestCase;

class StyleOverTest extends TestCase
{
    public function testFileIsReadable()
    {
        ob_start();
        require_once __DIR__ . '/../style-over.php';
        ob_end_clean();
        $this->assertTrue(true);
    }
}