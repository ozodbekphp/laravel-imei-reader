<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Support;

class LuhnValidator
{
    /**
     * Check if a 15-digit IMEI passes the Luhn algorithm (Mod 10 checksum).
     */
    public static function validate(string $imei): bool
    {
        $clean = self::sanitize($imei);

        if (!self::isValidStructure($clean)) {
            return false;
        }

        $expectedCheckDigit = self::calculateCheckDigit(substr($clean, 0, 14));
        $actualCheckDigit = (int) $clean[14];

        return $expectedCheckDigit === $actualCheckDigit;
    }

    /**
     * Calculate the 15th Luhn check digit from the first 14 digits.
     */
    public static function calculateCheckDigit(string $first14Digits): int
    {
        $digits = self::sanitize($first14Digits);
        if (strlen($digits) < 14) {
            $digits = str_pad($digits, 14, '0', STR_PAD_RIGHT);
        } elseif (strlen($digits) > 14) {
            $digits = substr($digits, 0, 14);
        }

        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $digit = (int) $digits[$i];

            // In 0-based index: index 1, 3, 5, 7, 9, 11, 13 are doubled (odd positions in 0-indexed)
            if ($i % 2 === 1) {
                $doubled = $digit * 2;
                $sum += ($doubled > 9) ? ($doubled - 9) : $doubled;
            } else {
                $sum += $digit;
            }
        }

        return (10 - ($sum % 10)) % 10;
    }

    /**
     * Verify if the string is strictly a 15-digit numeric string.
     */
    public static function isValidStructure(string $imei): bool
    {
        return strlen($imei) === 15 && ctype_digit($imei);
    }

    /**
     * Strip all non-digit characters from the input.
     */
    public static function sanitize(string $input): string
    {
        return (string) preg_replace('/\D+/', '', $input);
    }

    /**
     * Get Type Allocation Code (TAC) - first 8 digits.
     */
    public static function getTac(string $imei): ?string
    {
        $clean = self::sanitize($imei);
        return (strlen($clean) >= 8) ? substr($clean, 0, 8) : null;
    }

    /**
     * Get Serial Number (SNR) - digits 9 to 14 (6 digits).
     */
    public static function getSerial(string $imei): ?string
    {
        $clean = self::sanitize($imei);
        return (strlen($clean) >= 14) ? substr($clean, 8, 6) : null;
    }

    /**
     * Get Check Digit (CD) - 15th digit.
     */
    public static function getCheckDigit(string $imei): ?int
    {
        $clean = self::sanitize($imei);
        return (strlen($clean) === 15) ? (int) $clean[14] : null;
    }

    /**
     * Get full details and diagnostics for an IMEI.
     *
     * @return array<string, mixed>
     */
    public static function getDetails(string $imei): array
    {
        $clean = self::sanitize($imei);
        $isStructureValid = self::isValidStructure($clean);
        $isLuhnValid = $isStructureValid && self::validate($clean);
        $calculatedCheck = $isStructureValid ? self::calculateCheckDigit(substr($clean, 0, 14)) : null;

        return [
            'imei' => $clean,
            'is_structure_valid' => $isStructureValid,
            'is_luhn_valid' => $isLuhnValid,
            'tac' => self::getTac($clean),
            'serial' => self::getSerial($clean),
            'check_digit' => self::getCheckDigit($clean),
            'calculated_check_digit' => $calculatedCheck,
        ];
    }
}
