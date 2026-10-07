<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use Illuminate\Support\Facades\Validator;
use Ozodbek\LaravelImeiReader\Facades\ImeiReader;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Ozodbek\LaravelImeiReader\Rules\ImeiRule;
use Ozodbek\LaravelImeiReader\Tests\TestCase;

class LaravelIntegrationTest extends TestCase
{
    public function test_service_container_resolution(): void
    {
        $manager = app('imei.reader');
        $this->assertInstanceOf(ImeiReaderManager::class, $manager);

        $manager2 = app(ImeiReaderManager::class);
        $this->assertInstanceOf(ImeiReaderManager::class, $manager2);
    }

    public function test_facade_usage(): void
    {
        $imei = '861234567890127';
        $base64 = ImeiReader::generateBarcodeBase64($imei);

        $result = ImeiReader::fromBase64($base64)->read();

        $this->assertTrue($result->hasImei());
        $this->assertEquals($imei, $result->getFirstImei());
        $this->assertEquals([$imei], ImeiReader::fromBase64($base64)->getImeis());
    }

    public function test_helpers(): void
    {
        $this->assertInstanceOf(ImeiReaderManager::class, imei_reader());

        $imei = '490154203237518';
        $base64 = ImeiReader::generateBarcodeBase64($imei);

        $result = read_imei_from_base64($base64);
        $this->assertTrue($result->hasImei());
        $this->assertEquals($imei, $result->getFirstImei());
    }

    public function test_validation_rule_string_name(): void
    {
        // Valid 15-digit IMEI
        $validator1 = Validator::make(['imei' => '861234567890127'], ['imei' => 'required|imei']);
        $this->assertTrue($validator1->passes());

        // Invalid structure
        $validator2 = Validator::make(['imei' => '12345'], ['imei' => 'required|imei']);
        $this->assertFalse($validator2->passes());

        // Strict Luhn check valid
        $validator3 = Validator::make(['imei' => '861234567890127'], ['imei' => 'required|imei_strict']);
        $this->assertTrue($validator3->passes());

        // Strict Luhn check invalid checksum
        $validator4 = Validator::make(['imei' => '861234567890129'], ['imei' => 'required|imei_strict']);
        $this->assertFalse($validator4->passes());
    }

    public function test_validation_rule_object(): void
    {
        $vPass = Validator::make(['imei' => '861234567890127'], ['imei' => [new ImeiRule(strictLuhn: true)]]);
        $this->assertTrue($vPass->passes());

        $vFail = Validator::make(['imei' => '861234567890129'], ['imei' => [new ImeiRule(strictLuhn: true)]]);
        $this->assertFalse($vFail->passes());
    }
}
