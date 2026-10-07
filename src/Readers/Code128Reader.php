<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;

class Code128Reader implements ReaderInterface
{
    /**
     * Complete Code 128 pattern table (107 patterns).
     *
     * @var array<int, string>
     */
    protected static array $patterns = [
        "212222", "222122", "222221", "121223", "121322", "131222", "122213", "122312", "132212", "221213",
        "221312", "231212", "112232", "122132", "122231", "113222", "123122", "123221", "223211", "221132",
        "221231", "213212", "223112", "312131", "311222", "321122", "321221", "312212", "322112", "322211",
        "212123", "212321", "232121", "111323", "131123", "131321", "112313", "132113", "132311", "211313",
        "231113", "231311", "112133", "112331", "132131", "113123", "113321", "133121", "313121", "211331",
        "231131", "213113", "213311", "213131", "311123", "311321", "331121", "312113", "312311", "332111",
        "314111", "221411", "431111", "111224", "111422", "121124", "121421", "141122", "141221", "112214",
        "112412", "122114", "122411", "142112", "142211", "241211", "221114", "413111", "241112", "134111",
        "111242", "121142", "121241", "114212", "124112", "124211", "411212", "421112", "421211", "212141",
        "214121", "412121", "111143", "111341", "131141", "114113", "114311", "411113", "411311", "113141",
        "114131", "311141", "411131", "211412", "211214", "211232", "2331112"
    ];

    /**
     * Map pattern string to symbol value.
     *
     * @var array<string, int>
     */
    protected static array $patternToValue = [];

    public function __construct()
    {
        if (empty(self::$patternToValue)) {
            self::$patternToValue = array_flip(self::$patterns);
        }
    }

    public function isAvailable(): bool
    {
        return function_exists('imagecreatetruecolor');
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        $h = imagesy($image);
        if ($h < 5) {
            return [];
        }

        $otsuThreshold = ImagePreprocessor::calculateOtsuThreshold($image);
        $thresholds = array_unique([$otsuThreshold, 128, 90, 165]);

        $results = [];
        $seenTexts = [];

        // Scan across rows (step by 5% or minimum 2 pixels)
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
                        format: BarcodeFormat::CODE_128,
                        imeis: $imeis,
                        confidence: 1.0,
                        metadata: ['scan_row' => $y, 'threshold' => $threshold]
                    );
                }
            }

            // If we found any barcodes with IMEIs, no need to keep scanning with other thresholds
            if (!empty($results)) {
                break;
            }
        }

        return $results;
    }

    /**
     * Decode a single row of runs.
     *
     * @param array<array{black: bool, width: int}> $runs
     */
    protected function decodeRowRuns(array $runs): ?string
    {
        $numRuns = count($runs);
        if ($numRuns < 13) {
            return null;
        }

        for ($i = 0; $i <= $numRuns - 7; $i++) {
            // Must begin with a black bar
            if (!$runs[$i]['black']) {
                continue;
            }

            // Check if $runs[$i..$i+5] matches Start A, Start B, or Start C
            $startTot = 0;
            for ($k = 0; $k < 6; $k++) {
                $startTot += $runs[$i + $k]['width'];
            }
            if ($startTot < 6) {
                continue;
            }

            $startPat = $this->normalizePattern(array_slice($runs, $i, 6), 11, $startTot);
            if (!isset(self::$patternToValue[$startPat])) {
                continue;
            }

            $startSym = self::$patternToValue[$startPat];
            if (!in_array($startSym, [103, 104, 105], true)) {
                continue;
            }

            // Start pattern found! Decode following symbols
            $symbols = [$startSym];
            $pos = $i + 6;
            $foundStop = false;

            while ($pos <= $numRuns - 6) {
                // Check if remaining runs match Stop pattern (7 runs, sum 13 modules)
                if ($pos <= $numRuns - 7) {
                    $stopTot = 0;
                    for ($k = 0; $k < 7; $k++) {
                        $stopTot += $runs[$pos + $k]['width'];
                    }
                    if ($stopTot >= 7) {
                        $stopPat = $this->normalizePattern(array_slice($runs, $pos, 7), 13, $stopTot);
                        if ($stopPat === '2331112') {
                            $symbols[] = 106;
                            $foundStop = true;
                            break;
                        }
                    }
                }

                $symTot = 0;
                for ($k = 0; $k < 6; $k++) {
                    $symTot += $runs[$pos + $k]['width'];
                }
                if ($symTot < 6) {
                    break;
                }

                $symPat = $this->normalizePattern(array_slice($runs, $pos, 6), 11, $symTot);
                if (!isset(self::$patternToValue[$symPat])) {
                    break;
                }

                $symbols[] = self::$patternToValue[$symPat];
                $pos += 6;
            }

            if ($foundStop && count($symbols) >= 3) {
                $text = $this->parseSymbols($symbols);
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Normalize an array of runs to a pattern string of module counts.
     *
     * @param array<array{black: bool, width: int}> $runs
     */
    protected function normalizePattern(array $runs, int $expectedModules, int $totalWidth): string
    {
        $pat = '';
        foreach ($runs as $r) {
            $val = (int) round($r['width'] * $expectedModules / $totalWidth);
            $pat .= max(1, min(4, $val));
        }
        return $pat;
    }

    /**
     * Parse symbols into decoded text after verifying checksum.
     *
     * @param array<int> $symbols
     */
    protected function parseSymbols(array $symbols): ?string
    {
        $count = count($symbols);
        if ($count < 3 || $symbols[$count - 1] !== 106) {
            return null;
        }

        $checksumGiven = $symbols[$count - 2];
        $checksumCalc = $symbols[0];

        for ($s = 1; $s < $count - 2; $s++) {
            $checksumCalc += $s * $symbols[$s];
        }

        if (($checksumCalc % 103) !== $checksumGiven) {
            return null;
        }

        // Determine initial mode from start symbol
        $mode = match ($symbols[0]) {
            103 => 'A',
            104 => 'B',
            105 => 'C',
            default => 'B',
        };

        $text = '';
        for ($s = 1; $s < $count - 2; $s++) {
            $sym = $symbols[$s];

            // Mode switching
            if ($sym === 100) {
                $mode = 'B';
                continue;
            }
            if ($sym === 101) {
                $mode = 'A';
                continue;
            }
            if ($sym === 99) {
                $mode = 'C';
                continue;
            }
            if ($sym === 102) {
                // FNC1 (GS1-128 prefix)
                continue;
            }

            if ($mode === 'C') {
                $text .= sprintf('%02d', $sym);
            } elseif ($mode === 'B') {
                $text .= chr($sym + 32);
            } elseif ($mode === 'A') {
                $text .= ($sym < 64) ? chr($sym + 32) : chr($sym - 64);
            }
        }

        return $text;
    }
}
