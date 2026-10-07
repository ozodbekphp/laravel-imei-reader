<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;

class CompositeBarcodeReaderTest extends TestCase
{
    public function test_rotated_90_degrees_barcode(): void
    {
        $imei = '861234567890127';
        $base64 = ImeiReaderManager::generateBarcodeBase64($imei);
        $image = ImagePreprocessor::fromBase64($base64);

        $rotated = ImagePreprocessor::rotate($image, 90.0);
        ob_start();
        imagepng($rotated);
        $rotatedPng = (string) ob_get_clean();
        $rotatedBase64 = base64_encode($rotatedPng);

        $scan = ImeiReaderManager::scanBase64($rotatedBase64);

        $this->assertTrue($scan->hasImei());
        $this->assertEquals($imei, $scan->getFirstImei());
    }

    public function test_dual_imei_stickers_image(): void
    {
        $imei1 = '861234567890127';
        $imei2 = '356938035643809';

        $b1 = ImagePreprocessor::fromBase64(ImeiReaderManager::generateBarcodeBase64($imei1));
        $b2 = ImagePreprocessor::fromBase64(ImeiReaderManager::generateBarcodeBase64($imei2));

        $w = max(imagesx($b1), imagesx($b2));
        $h = imagesy($b1) + imagesy($b2) + 40;

        $composite = imagecreatetruecolor($w, $h);
        $white = (int) imagecolorallocate($composite, 255, 255, 255);
        imagefilledrectangle($composite, 0, 0, $w, $h, $white);

        imagecopy($composite, $b1, 0, 10, 0, 0, imagesx($b1), imagesy($b1));
        imagecopy($composite, $b2, 0, imagesy($b1) + 30, 0, 0, imagesx($b2), imagesy($b2));

        ob_start();
        imagepng($composite);
        $png = (string) ob_get_clean();
        $b64 = base64_encode($png);

        $scan = ImeiReaderManager::scanBase64($b64);

        $this->assertTrue($scan->hasImei());
        $this->assertEquals(2, $scan->count());
        $this->assertEquals($imei1, $scan->getImei1());
        $this->assertEquals($imei2, $scan->getImei2());
        $this->assertTrue($scan->isDualSim());
    }
}
