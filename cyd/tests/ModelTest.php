<?php
use PHPUnit\Framework\TestCase;

class ModelTest extends TestCase
{
    public function testFileIsReadable()
    {
        ob_start();
        require_once __DIR__ . '/../model.php';
        ob_end_clean();
        $this->assertTrue(true);
    }
}