<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ozodbek\LaravelImeiReader\Support\LuhnValidator;

class LuhnValidatorTest extends TestCase
{
    public function test_valid_imei_passes_luhn_check(): void
    {
        $validImeis = [
            '861234567890127', // calculated check digit: 7
            '356938035643809', // calculated check digit: 9
            '490154203237518', // calculated check digit: 8
            '012345678901237', // calculated check digit: 7
            '353689098765434', // calculated check digit: 4
        ];

        foreach ($validImeis as $imei) {
            $expectedCheck = LuhnValidator::calculateCheckDigit(substr($imei, 0, 14));
            $this->assertEquals((int) $imei[14], $expectedCheck, "IMEI {$imei} should have valid check digit");
            $this->assertTrue(LuhnValidator::validate($imei), "IMEI {$imei} should pass Luhn validation");
        }
    }

    public function test_invalid_imei_fails_luhn_check(): void
    {
        $invalidImei = '861234567890129'; // wrong check digit
        $this->assertFalse(LuhnValidator::validate($invalidImei));
    }

    public function test_structure_validation(): void
    {
        $this->assertTrue(LuhnValidator::isValidStructure('123456789012345'));
        $this->assertFalse(LuhnValidator::isValidStructure('12345678901234')); // 14 digits
        $this->assertFalse(LuhnValidator::isValidStructure('1234567890123456')); // 16 digits
        $this->assertFalse(LuhnValidator::isValidStructure('12345678901234A')); // alphanumeric
    }

    public function test_details_extraction(): void
    {
        $imei = '861234567890127';
        $details = LuhnValidator::getDetails($imei);

        $this->assertEquals('86123456', $details['tac']);
        $this->assertEquals('789012', $details['serial']);
        $this->assertEquals(7, $details['check_digit']);
        $this->assertTrue($details['is_structure_valid']);
        $this->assertTrue($details['is_luhn_valid']);
    }
}
