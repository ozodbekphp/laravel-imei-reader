<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Support;

use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\DTO\ImeiScanResult;

class ImeiExtractor
{
    /**
     * Extract 15-digit IMEIs from text.
     *
     * @return array<string>
     */
    public static function extractFromText(string $text, bool $strictLuhn = false): array
    {
        $imeis = [];

        // 1. Check for labeled patterns (IMEI1, IMEI2, IMEI, etc.)
        if (preg_match_all('/(?:IMEI|IMEI\s*1|IMEI\s*2|MEID|TAC)?[:\s\-\/]*([0-9]{15})/i', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (LuhnValidator::isValidStructure($candidate)) {
                    if (!$strictLuhn || LuhnValidator::validate($candidate)) {
                        $imeis[] = $candidate;
                    }
                }
            }
        }

        // 2. Generic 15-digit numeric sequence finder
        if (preg_match_all('/(?<!\d)(\d{15})(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (LuhnValidator::isValidStructure($candidate)) {
                    if (!$strictLuhn || LuhnValidator::validate($candidate)) {
                        $imeis[] = $candidate;
                    }
                }
            }
        }

        // 3. Check for 14-digit IMEIs with check digit appended after slash (e.g. 35693803564380/3)
        if (preg_match_all('/(?<!\d)(\d{14})[\/\-](\d{1,2})(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $idx => $first14) {
                $check = substr($matches[2][$idx], 0, 1);
                $candidate = $first14 . $check;
                if (LuhnValidator::isValidStructure($candidate)) {
                    if (!$strictLuhn || LuhnValidator::validate($candidate)) {
                        $imeis[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($imeis));
    }

    /**
     * Build an ImeiScanResult from a list of decoded barcode results.
     *
     * @param array<BarcodeResult> $barcodes
     */
    public static function buildScanResult(array $barcodes, bool $strictLuhn = false): ImeiScanResult
    {
        $allImeis = [];
        $rawTexts = [];
        $imei1 = null;
        $imei2 = null;

        foreach ($barcodes as $barcode) {
            $rawTexts[] = $barcode->text;

            // Check if barcode text explicitly specifies IMEI 1 or IMEI 2
            if (preg_match('/(?:IMEI\s*1|IMEI_1)[:\s\-]*([0-9]{15})/i', $barcode->text, $m1)) {
                $candidate = $m1[1];
                if (!$strictLuhn || LuhnValidator::validate($candidate)) {
                    $imei1 ??= $candidate;
                    $allImeis[] = $candidate;
                }
            }

            if (preg_match('/(?:IMEI\s*2|IMEI_2)[:\s\-]*([0-9]{15})/i', $barcode->text, $m2)) {
                $candidate = $m2[1];
                if (!$strictLuhn || LuhnValidator::validate($candidate)) {
                    $imei2 ??= $candidate;
                    $allImeis[] = $candidate;
                }
            }

            $extracted = self::extractFromText($barcode->text, $strictLuhn);
            foreach ($extracted as $imei) {
                $allImeis[] = $imei;
            }
        }

        $uniqueImeis = array_values(array_unique($allImeis));
        $primaryImei = $uniqueImeis[0] ?? null;

        if ($imei1 === null && isset($uniqueImeis[0])) {
            $imei1 = $uniqueImeis[0];
        }
        if ($imei2 === null && isset($uniqueImeis[1])) {
            $imei2 = $uniqueImeis[1];
        }

        $details = [];
        $allPassLuhn = !empty($uniqueImeis);
        foreach ($uniqueImeis as $imei) {
            $detail = LuhnValidator::getDetails($imei);
            $details[$imei] = $detail;
            if (!$detail['is_luhn_valid']) {
                $allPassLuhn = false;
            }
        }

        return new ImeiScanResult(
            imeis: $uniqueImeis,
            primaryImei: $primaryImei,
            imei1: $imei1,
            imei2: $imei2,
            isLuhnValid: $allPassLuhn,
            imeiDetails: $details,
            barcodes: $barcodes,
            rawTexts: $rawTexts
        );
    }
}
