<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\Exceptions\InvalidBase64Exception;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;

class ImeiReaderManagerTest extends TestCase
{
    public function test_read_imei_from_base64_data_uri(): void
    {
        $imei = '356938035643809';
        $base64 = ImeiReaderManager::generateBarcodeBase64($imei);

        $manager = new ImeiReaderManager();
        $result = $manager->fromBase64($base64)->read();

        $this->assertTrue($result->hasImei());
        $this->assertEquals(1, $result->count());
        $this->assertEquals($imei, $result->getFirstImei());
        $this->assertEquals([$imei], $result->getImeis());
        $this->assertTrue($result->isLuhnValid);
    }

    public function test_read_imei_from_raw_base64_string(): void
    {
        $imei = '490154203237518';
        $dataUri = ImeiReaderManager::generateBarcodeBase64($imei);
        $rawBase64 = explode(',', $dataUri)[1];

        $manager = new ImeiReaderManager();
        $result = $manager->fromBase64($rawBase64)->read();

        $this->assertTrue($result->hasImei());
        $this->assertEquals($imei, $result->getFirstImei());
    }

    public function test_static_scan_base64(): void
    {
        $imei = '012345678901237';
        $base64 = ImeiReaderManager::generateBarcodeBase64($imei);

        $result = ImeiReaderManager::scanBase64($base64);

        $this->assertTrue($result->hasImei());
        $this->assertEquals($imei, $result->getFirstImei());
    }

    public function test_invalid_base64_throws_exception(): void
    {
        $this->expectException(InvalidBase64Exception::class);

        $manager = new ImeiReaderManager();
        $manager->fromBase64("!!! not valid base64 !!!");
    }

    public function test_scan_result_json_serialization(): void
    {
        $imei = '861234567890127';
        $base64 = ImeiReaderManager::generateBarcodeBase64($imei);

        $result = ImeiReaderManager::scanBase64($base64);
        $json = $result->toJson();

        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertTrue($decoded['success']);
        $this->assertEquals($imei, $decoded['primary_imei']);
        $this->assertTrue($decoded['is_luhn_valid']);
    }
}
