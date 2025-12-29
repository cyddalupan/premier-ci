<?php
use PHPUnit\Framework\TestCase;

class StyleTest extends TestCase
{
    public function testFileIsReadable()
    {
        ob_start();
        require_once __DIR__ . '/../style.php';
        ob_end_clean();
        $this->assertTrue(true);
    }
}