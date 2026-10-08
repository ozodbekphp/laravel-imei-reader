<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Support;

use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\DTO\ImeiScanResult;

class ImeiExtractor
{
    /**
     * Map common OCR digit misrecognitions for phone label fonts.
     */
    public static function correctOcrDigits(string $raw): string
    {
        $map = [
            'B' => '8',
            'O' => '0',
            'o' => '0',
            'D' => '0',
            'I' => '1',
            'l' => '1',
            '|' => '1',
            ']' => '1',
            '[' => '1',
            ')' => '1',
            '(' => '1',
            '!' => '1',
            '}' => '1',
            '{' => '1',
            'S' => '5',
            's' => '5',
            'Z' => '2',
            'z' => '2',
            'G' => '6',
            'b' => '6',
            'q' => '9',
        ];
        return strtr($raw, $map);
    }

    /**
     * Extract purely 15-digit numeric IMEIs from raw text or OCR string.
     * Guaranteed to return only clean 15-digit numbers without letters or symbols.
     *
     * @return array<string>
     */
    public static function extractFromText(string $text, bool $strictLuhn = false): array
    {
        $luhnValidImeis = [];
        $otherImeis = [];

        $addCandidate = function (string $candidate) use (&$luhnValidImeis, &$otherImeis, $strictLuhn): void {
            if (strlen($candidate) !== 15 || !ctype_digit($candidate)) {
                return;
            }
            if (!LuhnValidator::isValidStructure($candidate)) {
                return;
            }

            $passesLuhn = LuhnValidator::validate($candidate);
            if ($passesLuhn) {
                if (!in_array($candidate, $luhnValidImeis, true)) {
                    $luhnValidImeis[] = $candidate;
                }
            } elseif (!$strictLuhn) {
                if (!in_array($candidate, $otherImeis, true)) {
                    $otherImeis[] = $candidate;
                }
            }
        };

        $lines = explode("\n", $text);

        // 1. Line-by-line inspection with OCR lookalike correction
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $matched = false;

            // Explicit IMEI 1 pattern (e.g. "IMEI1", "IME11", "IMEI 1", "IME!1", "IME'1")
            if (preg_match('/(?:IMEI\s*1|IME11|IMEI1|IME\s*1|IME!1|IME\'1|1MEI\s*1)[:\s\-]*(.+)/i', $line, $m1)) {
                $corrected = self::correctOcrDigits($m1[1]);
                $digits = preg_replace('/\D/', '', $corrected);
                if (strlen($digits) >= 15) {
                    $addCandidate(substr($digits, 0, 15));
                    $matched = true;
                }
            }

            // Explicit IMEI 2 pattern (e.g. "IMEI2", "IME12", "IMEI 2", "IME!2", "IME'2")
            if (preg_match('/(?:IMEI\s*2|IME12|IMEI2|IME\s*2|IME!2|IME\'2|1MEI\s*2)[:\s\-]*(.+)/i', $line, $m2)) {
                $corrected = self::correctOcrDigits($m2[1]);
                $digits = preg_replace('/\D/', '', $corrected);
                if (strlen($digits) >= 15) {
                    $addCandidate(substr($digits, 0, 15));
                    $matched = true;
                }
            }

            // Generic labeled pattern (only if not already matched by explicit 1/2)
            if (!$matched && preg_match('/(?:IMEI|MEID|TAC)[!\'\"\s_\-]*(?:[0-9]|SN|S\/N)?[:\s\-]*(.+)/i', $line, $mg)) {
                $corrected = self::correctOcrDigits($mg[1]);
                $digits = preg_replace('/\D/', '', $corrected);
                if (strlen($digits) >= 15) {
                    $addCandidate(substr($digits, 0, 15));
                }
            }
        }

        // 2. Continuous 15-digit numeric sequences
        if (preg_match_all('/(?<!\d)(\d{15})(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $addCandidate($candidate);
            }
        }

        // 3. Spaced or dashed 15-digit sequences
        if (preg_match_all('/(?<!\d)(\d{2,8}[\s\-\/]\d{2,8}(?:[\s\-\/]\d{1,8})+)(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $rawCandidate) {
                $digits = preg_replace('/\D/', '', $rawCandidate);
                if (strlen($digits) === 15) {
                    $addCandidate($digits);
                }
            }
        }

        // 4. 14-digit IMEIs with check digit appended after slash
        if (preg_match_all('/(?<!\d)(\d{14})[\/\-](\d{1,2})(?!\d)/', $text, $matches)) {
            foreach ($matches[1] as $idx => $first14) {
                $check = substr($matches[2][$idx], 0, 1);
                $addCandidate($first14 . $check);
            }
        }

        // Always prioritize Luhn-valid candidates
        if (!empty($luhnValidImeis)) {
            return array_values(array_unique($luhnValidImeis));
        }

        return array_values(array_unique($otherImeis));
    }

    /**
     * Build an ImeiScanResult from a list of decoded barcode/OCR results.
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

            // Check if text explicitly specifies IMEI 1 or IMEI 2 (including OCR lookalikes)
            $lines = explode("\n", $barcode->text);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                // Explicit IMEI 1
                if (preg_match('/(?:IMEI\s*1|IME11|IMEI1|IME\s*1|IME!1|IME\'1|1MEI\s*1)[:\s\-]*(.+)/i', $line, $m1)) {
                    $corrected = self::correctOcrDigits($m1[1]);
                    $digits = preg_replace('/\D/', '', $corrected);
                    if (strlen($digits) >= 15) {
                        $cand = substr($digits, 0, 15);
                        if (LuhnValidator::isValidStructure($cand) && (!$strictLuhn || LuhnValidator::validate($cand))) {
                            $imei1 ??= $cand;
                            $allImeis[] = $cand;
                        }
                    }
                }

                // Explicit IMEI 2
                if (preg_match('/(?:IMEI\s*2|IME12|IMEI2|IME\s*2|IME!2|IME\'2|1MEI\s*2)[:\s\-]*(.+)/i', $line, $m2)) {
                    $corrected = self::correctOcrDigits($m2[1]);
                    $digits = preg_replace('/\D/', '', $corrected);
                    if (strlen($digits) >= 15) {
                        $cand = substr($digits, 0, 15);
                        if (LuhnValidator::isValidStructure($cand) && (!$strictLuhn || LuhnValidator::validate($cand))) {
                            $imei2 ??= $cand;
                            $allImeis[] = $cand;
                        }
                    }
                }
            }

            // General extraction
            $extracted = self::extractFromText($barcode->text, $strictLuhn);
            foreach ($extracted as $imei) {
                $allImeis[] = $imei;
            }
        }

        $uniqueImeis = array_values(array_unique($allImeis));

        // If any candidates pass Luhn, keep only Luhn-valid ones
        $luhnValidOnly = array_values(array_filter($uniqueImeis, fn (string $im) => LuhnValidator::validate($im)));
        if (!empty($luhnValidOnly)) {
            $uniqueImeis = $luhnValidOnly;
            if ($imei1 !== null && !LuhnValidator::validate($imei1)) {
                $imei1 = null;
            }
            if ($imei2 !== null && !LuhnValidator::validate($imei2)) {
                $imei2 = null;
            }
        }

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
