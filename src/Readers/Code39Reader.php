<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;

class Code39Reader implements ReaderInterface
{
    /**
     * Code 39 character encodings (9 bits: 1 = wide, 0 = narrow).
     *
     * @var array<string, string>
     */
    protected static array $code39Table = [
        '0' => '000110100', '1' => '100100001', '2' => '001100001', '3' => '101100000',
        '4' => '000110001', '5' => '100110000', '6' => '001110000', '7' => '000100101',
        '8' => '100100100', '9' => '001100100', 'A' => '100001001', 'B' => '001001001',
        'C' => '101001000', 'D' => '000011001', 'E' => '100011000', 'F' => '001011000',
        'G' => '000001101', 'H' => '100001100', 'I' => '001001100', 'J' => '000011100',
        'K' => '100000011', 'L' => '001000011', 'M' => '101000010', 'N' => '000010011',
        'O' => '100010010', 'P' => '001010010', 'Q' => '000000111', 'R' => '100000110',
        'S' => '001000110', 'T' => '000010110', 'U' => '110000001', 'V' => '011000001',
        'W' => '111000000', 'X' => '010010001', 'Y' => '110010000', 'Z' => '011010000',
        '-' => '010000101', '.' => '110000100', ' ' => '011000100', '*' => '010010100',
        '$' => '010101000', '/' => '010100010', '+' => '010001010', '%' => '000101010',
    ];

    /**
     * Inverted table: pattern to character.
     *
     * @var array<string, string>
     */
    protected static array $patternToChar = [];

    public function __construct()
    {
        if (empty(self::$patternToChar)) {
            self::$patternToChar = array_flip(self::$code39Table);
        }
    }

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
        $thresholds = array_unique([$otsuThreshold, 128, 90, 160]);

        $results = [];
        $seenTexts = [];
        $step = max(2, (int) ($h / 25));

        foreach ($thresholds as $threshold) {
            for ($y = 2; $y < $h - 2; $y += $step) {
                $runs = ImagePreprocessor::extractRowRuns($image, $y, $threshold);
                $decoded = $this->decodeRowRuns($runs);

                if ($decoded !== null && !isset($seenTexts[$decoded])) {
                    $seenTexts[$decoded] = true;
                    $imeis = ImeiExtractor::extractFromText($decoded);

                    $results[] = new BarcodeResult(
                        text: $decoded,
                        format: BarcodeFormat::CODE_39,
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
        if ($numRuns < 19) {
            return null;
        }

        for ($i = 0; $i <= $numRuns - 19; $i++) {
            if (!$runs[$i]['black']) {
                continue;
            }

            // Check if 9 runs match start '*'
            $char = $this->decodeChar(array_slice($runs, $i, 9));
            if ($char !== '*') {
                continue;
            }

            $decodedText = '';
            $pos = $i + 9;
            $foundEnd = false;

            while ($pos <= $numRuns - 9) {
                // Skip 1 inter-character gap run (white space)
                if ($pos < $numRuns && !$runs[$pos]['black']) {
                    $pos++;
                }

                if ($pos + 9 > $numRuns) {
                    break;
                }

                $nextChar = $this->decodeChar(array_slice($runs, $pos, 9));
                if ($nextChar === null) {
                    break;
                }

                if ($nextChar === '*') {
                    $foundEnd = true;
                    break;
                }

                $decodedText .= $nextChar;
                $pos += 9;
            }

            if ($foundEnd && strlen($decodedText) > 0) {
                return $decodedText;
            }
        }

        return null;
    }

    /**
     * Decode 9 runs into a Code 39 character.
     *
     * @param array<array{black: bool, width: int}> $nineRuns
     */
    protected function decodeChar(array $nineRuns): ?string
    {
        if (count($nineRuns) !== 9) {
            return null;
        }

        // Must alternate black and white: B W B W B W B W B
        for ($k = 0; $k < 9; $k++) {
            $expectedBlack = ($k % 2 === 0);
            if ($nineRuns[$k]['black'] !== $expectedBlack) {
                return null;
            }
        }

        $widths = array_column($nineRuns, 'width');
        $minWidth = min($widths);
        $maxWidth = max($widths);

        if ($maxWidth < $minWidth * 1.5) {
            return null;
        }

        $threshold = ($minWidth + $maxWidth) / 2.0;
        $pattern = '';
        $wideCount = 0;

        foreach ($widths as $w) {
            if ($w >= $threshold) {
                $pattern .= '1';
                $wideCount++;
            } else {
                $pattern .= '0';
            }
        }

        // Code 39 strictly requires exactly 3 wide elements out of 9
        if ($wideCount !== 3) {
            return null;
        }

        if (!isset(self::$patternToChar[$pattern])) {
            return null;
        }

        return (string) self::$patternToChar[$pattern];
    }
}
