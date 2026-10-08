<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\Readers\TesseractOcrReader;

class TesseractOcrReaderTest extends TestCase
{
    public function test_instantiation(): void
    {
        $reader = new TesseractOcrReader();
        $this->assertIsBool($reader->isAvailable());
    }

    public function test_decode_returns_array(): void
    {
        $im = imagecreatetruecolor(100, 100);
        $reader = new TesseractOcrReader();
        $results = $reader->decode($im);
        $this->assertIsArray($results);
        imagedestroy($im);
    }
}
