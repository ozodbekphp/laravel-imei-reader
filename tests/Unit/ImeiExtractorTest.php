<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;

class ImeiExtractorTest extends TestCase
{
    public function test_extract_plain_imei(): void
    {
        $text = "861234567890127";
        $imeis = ImeiExtractor::extractFromText($text);

        $this->assertCount(1, $imeis);
        $this->assertEquals('861234567890127', $imeis[0]);
    }

    public function test_extract_labeled_imeis(): void
    {
        $text = "IMEI 1: 861234567890127\nIMEI 2: 356938035643809";
        $imeis = ImeiExtractor::extractFromText($text);

        $this->assertCount(2, $imeis);
        $this->assertEquals('861234567890127', $imeis[0]);
        $this->assertEquals('356938035643809', $imeis[1]);
    }

    public function test_extract_slash_formatted_imei(): void
    {
        $text = "TAC/SNR/CD: 86123456789012/7";
        $imeis = ImeiExtractor::extractFromText($text);

        $this->assertCount(1, $imeis);
        $this->assertEquals('861234567890127', $imeis[0]);
    }

    public function test_extract_spaced_and_dashed_imei(): void
    {
        $text = "IMEI 1: 86 1234 5678 9012 7\nIMEI2: 35-693803-564380-9";
        $imeis = ImeiExtractor::extractFromText($text);

        $this->assertCount(2, $imeis);
        $this->assertEquals('861234567890127', $imeis[0]);
        $this->assertEquals('356938035643809', $imeis[1]);
        $this->assertMatchesRegularExpression('/^[0-9]{15}$/', $imeis[0]);
        $this->assertMatchesRegularExpression('/^[0-9]{15}$/', $imeis[1]);
    }

    public function test_extract_ignores_non_digits_and_invalid_lengths(): void
    {
        $text = "Serial: ABC123456789012\nModel: SM-G998B\nIMEI: 861234567890127";
        $imeis = ImeiExtractor::extractFromText($text);

        $this->assertCount(1, $imeis);
        $this->assertEquals('861234567890127', $imeis[0]);
    }

    public function test_build_scan_result(): void
    {
        $barcodes = [
            new BarcodeResult("IMEI 1: 861234567890127", BarcodeFormat::CODE_128, ['861234567890127']),
            new BarcodeResult("IMEI 2: 356938035643809", BarcodeFormat::CODE_128, ['356938035643809']),
        ];

        $scan = ImeiExtractor::buildScanResult($barcodes);

        $this->assertTrue($scan->hasImei());
        $this->assertEquals(2, $scan->count());
        $this->assertEquals('861234567890127', $scan->getFirstImei());
        $this->assertEquals('861234567890127', $scan->getImei1());
        $this->assertEquals('356938035643809', $scan->getImei2());
        $this->assertTrue($scan->isDualSim());
        $this->assertTrue($scan->isLuhnValid);
    }

    public function test_extract_ocr_imperfect_text_with_lookalikes(): void
    {
        $ocrText = "SN 76812/R6T200470\nIME11 862177083545783\nIME12 8621770B3545791";
        $imeis = ImeiExtractor::extractFromText($ocrText, strictLuhn: true);

        $this->assertCount(2, $imeis);
        $this->assertEquals('862177083545783', $imeis[0]);
        $this->assertEquals('862177083545791', $imeis[1]);

        $barcodes = [
            new BarcodeResult($ocrText, BarcodeFormat::OCR_TEXT, $imeis),
        ];

        $scan = ImeiExtractor::buildScanResult($barcodes, strictLuhn: true);
        $this->assertEquals('862177083545783', $scan->getImei1());
        $this->assertEquals('862177083545791', $scan->getImei2());
        $this->assertTrue($scan->isLuhnValid);
    }
}
