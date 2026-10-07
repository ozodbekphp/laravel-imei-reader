<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Ozodbek\LaravelImeiReader\Readers\Code128Reader;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;

class Code128ReaderTest extends TestCase
{
    public function test_decode_generated_code128_imei_barcode(): void
    {
        $imei = '861234567890127';
        $base64 = ImeiReaderManager::generateBarcodeBase64($imei);

        $image = ImagePreprocessor::fromBase64($base64);
        $reader = new Code128Reader();
        $results = $reader->decode($image);

        $this->assertNotEmpty($results, 'Code128 barcode should be successfully decoded');
        $this->assertEquals($imei, $results[0]->text);
        $this->assertContains($imei, $results[0]->imeis);
    }
}
