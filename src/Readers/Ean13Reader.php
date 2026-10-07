<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;

class Ean13Reader implements ReaderInterface
{
    // L-code patterns (odd parity)
    protected static array $lCodes = [
        0 => '3211', 1 => '2221', 2 => '2122', 3 => '1411', 4 => '1132',
        5 => '1231', 6 => '1114', 7 => '1312', 8 => '1213', 9 => '3112',
    ];

    // G-code patterns (even parity)
    protected static array $gCodes = [
        0 => '1123', 1 => '1222', 2 => '2212', 3 => '1141', 4 => '2311',
        5 => '1321', 6 => '4111', 7 => '2131', 8 => '3121', 9 => '2113',
    ];

    // R-code patterns
    protected static array $rCodes = [
        0 => '3211', 1 => '2221', 2 => '2122', 3 => '1411', 4 => '1132',
        5 => '1231', 6 => '1114', 7 => '1312', 8 => '1213', 9 => '3112',
    ];

    // First digit parity structure
    protected static array $parityTable = [
        0 => 'LLLLLL', 1 => 'LLGLGG', 2 => 'LLGGLG', 3 => 'LLGGGL', 4 => 'LGLLGG',
        5 => 'LGGLLG', 6 => 'LGGGLL', 7 => 'LGLGLG', 8 => 'LGLGGL', 9 => 'LGGLGL',
    ];

    public function isAvailable(): bool
    {
        return \function_exists('imagecreatetruecolor');
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        $h = \imagesy($image);
        if ($h < 5) {
            return [];
        }

        $otsuThreshold = ImagePreprocessor::calculateOtsuThreshold($image);
        $thresholds = array_unique([$otsuThreshold, 128]);

        $results = [];
        $seenTexts = [];
        $step = max(2, (int) ($h / 20));

        foreach ($thresholds as $threshold) {
            for ($y = 2; $y < $h - 2; $y += $step) {
                $runs = ImagePreprocessor::extractRowRuns($image, $y, $threshold);
                $decoded = $this->decodeRowRuns($runs);

                if ($decoded !== null && !isset($seenTexts[$decoded])) {
                    $seenTexts[$decoded] = true;
                    $imeis = ImeiExtractor::extractFromText($decoded);

                    $results[] = new BarcodeResult(
                        text: $decoded,
                        format: BarcodeFormat::EAN_13,
                        imeis: $imeis,
                        confidence: 1.0,
                        metadata: ['scan_row' => $y, 'threshold' => $threshold]
                    );
                }
            }

            if (!empty($results)) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param array<array{black: bool, width: int}> $runs
     */
    public function decodeRowRuns(array $runs): ?string
    {
        $numRuns = count($runs);
        // EAN-13: 3 start + 24 left (6x4) + 5 center + 24 right (6x4) + 3 stop = 59 runs
        if ($numRuns < 59) {
            return null;
        }

        for ($i = 0; $i <= $numRuns - 59; $i++) {
            if (!$runs[$i]['black']) {
                continue;
            }

            // Guard pattern 1-0-1 (3 runs)
            $moduleEstimate = ($runs[$i]['width'] + $runs[$i + 1]['width'] + $runs[$i + 2]['width']) / 3.0;
            if ($moduleEstimate < 0.5) {
                continue;
            }

            // Center guard must be around index $i + 3 + 24 = $i + 27 (5 runs: 0-1-0-1-0)
            // Stop guard at $i + 27 + 5 + 24 = $i + 56 (3 runs: 1-0-1)
            $leftDigits = [];
            $parities = '';
            $pos = $i + 3;
            $failed = false;

            for ($d = 0; $d < 6; $d++) {
                $digitRuns = array_slice($runs, $pos, 4);
                $pos += 4;

                $decodedDigit = $this->decodeLeftDigit($digitRuns);
                if ($decodedDigit === null) {
                    $failed = true;
                    break;
                }
                $leftDigits[] = $decodedDigit['digit'];
                $parities .= $decodedDigit['parity'];
            }

            if ($failed) {
                continue;
            }

            // Skip center guard (5 runs)
            $pos += 5;

            $rightDigits = [];
            for ($d = 0; $d < 6; $d++) {
                $digitRuns = array_slice($runs, $pos, 4);
                $pos += 4;

                $digit = $this->decodeRightDigit($digitRuns);
                if ($digit === null) {
                    $failed = true;
                    break;
                }
                $rightDigits[] = $digit;
            }

            if ($failed) {
                continue;
            }

            // Determine first digit from parity
            $firstDigit = array_search($parities, self::$parityTable, true);
            if ($firstDigit === false) {
                continue;
            }

            $ean13 = (string) $firstDigit . implode('', $leftDigits) . implode('', $rightDigits);
            if ($this->validateChecksum($ean13)) {
                return $ean13;
            }
        }

        return null;
    }

    /**
     * @param array<array{black: bool, width: int}> $digitRuns
     * @return array{digit: int, parity: string}|null
     */
    protected function decodeLeftDigit(array $digitRuns): ?array
    {
        if (count($digitRuns) !== 4) {
            return null;
        }

        $totalWidth = array_sum(array_column($digitRuns, 'width'));
        if ($totalWidth < 4) {
            return null;
        }

        $pat = '';
        foreach ($digitRuns as $r) {
            $val = (int) round($r['width'] * 7.0 / $totalWidth);
            $pat .= max(1, min(4, $val));
        }

        foreach (self::$lCodes as $digit => $code) {
            if ($code === $pat) {
                return ['digit' => $digit, 'parity' => 'L'];
            }
        }

        foreach (self::$gCodes as $digit => $code) {
            if ($code === $pat) {
                return ['digit' => $digit, 'parity' => 'G'];
            }
        }

        return null;
    }

    /**
     * @param array<array{black: bool, width: int}> $digitRuns
     */
    protected function decodeRightDigit(array $digitRuns): ?int
    {
        if (count($digitRuns) !== 4) {
            return null;
        }

        $totalWidth = array_sum(array_column($digitRuns, 'width'));
        if ($totalWidth < 4) {
            return null;
        }

        $pat = '';
        foreach ($digitRuns as $r) {
            $val = (int) round($r['width'] * 7.0 / $totalWidth);
            $pat .= max(1, min(4, $val));
        }

        foreach (self::$rCodes as $digit => $code) {
            if ($code === $pat) {
                return $digit;
            }
        }

        return null;
    }

    protected function validateChecksum(string $ean): bool
    {
        if (strlen($ean) !== 13 || !ctype_digit($ean)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $val = (int) $ean[$i];
            $sum += ($i % 2 === 1) ? $val * 3 : $val;
        }

        $expectedCheck = (10 - ($sum % 10)) % 10;
        return $expectedCheck === (int) $ean[12];
    }
}
