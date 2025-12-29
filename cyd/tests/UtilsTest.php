<?php
use PHPUnit\Framework\TestCase;

class UtilsTest extends TestCase
{
    public function testFileIsReadable()
    {
        ob_start();
        require_once __DIR__ . '/../utils.php';
        ob_end_clean();
        $this->assertTrue(true);
    }
}