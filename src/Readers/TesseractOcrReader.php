<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;
use Ozodbek\LaravelImeiReader\Support\LuhnValidator;
use Symfony\Component\Process\Process;
use Throwable;

class TesseractOcrReader implements ReaderInterface
{
    protected ?string $binaryPath;
    protected ?bool $isAvailableCache = null;

    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath;
    }

    public function isAvailable(): bool
    {
        if ($this->isAvailableCache !== null) {
            return $this->isAvailableCache;
        }

        if ($this->binaryPath !== null && is_executable($this->binaryPath)) {
            return $this->isAvailableCache = true;
        }

        // Check if tesseract is in PATH
        $process = Process::fromShellCommandline('which tesseract');
        $process->run();

        return $this->isAvailableCache = $process->isSuccessful();
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        // Dynamically raise memory limit if running under a low default (e.g. 128M)
        $currentLimit = ini_get('memory_limit');
        if ($currentLimit !== false && $currentLimit !== '-1') {
            $bytes = (int) $currentLimit;
            if (str_ends_with(strtoupper((string) $currentLimit), 'M')) {
                $bytes *= 1024 * 1024;
            } elseif (str_ends_with(strtoupper((string) $currentLimit), 'G')) {
                $bytes *= 1024 * 1024 * 1024;
            } elseif (str_ends_with(strtoupper((string) $currentLimit), 'K')) {
                $bytes *= 1024;
            }
            if ($bytes > 0 && $bytes < 256 * 1024 * 1024) {
                @ini_set('memory_limit', '256M');
            }
        }

        $w = \imagesx($image);
        $h = \imagesy($image);
        if ($w < 10 || $h < 10) {
            return [];
        }

        $bin = $this->binaryPath ?? 'tesseract';

        // Smartphone label sticker layout:
        // Scanning horizontal bands isolates the text from barcode interference.
        $bands = [
            ['y1' => 0.28, 'y2' => 0.75], // Main IMEI sticker area
            ['y1' => 0.40, 'y2' => 0.68], // Focused dual-IMEI center
            ['y1' => 0.48, 'y2' => 0.65], // Lower IMEI center (IMEI 2)
            ['y1' => 0.00, 'y2' => 1.00], // Full image fallback
        ];

        $allResults = [];
        $collectedLuhnImeis = [];

        foreach ($bands as $band) {
            $tmpPng = $this->prepareOptimizedImageBand($image, $w, $h, $band['y1'], $band['y2']);
            if ($tmpPng === null) {
                continue;
            }

            try {
                // Pass with PSM 4 (single column of text)
                $text = $this->runTesseract($bin, $tmpPng, '4');
                $imeis = ImeiExtractor::extractFromText($text);

                // Fallback pass with PSM 6 if needed
                if (empty($imeis)) {
                    $textPsm6 = $this->runTesseract($bin, $tmpPng, '6');
                    $imeisPsm6 = ImeiExtractor::extractFromText($textPsm6);
                    if (!empty($imeisPsm6)) {
                        $text = $textPsm6;
                        $imeis = $imeisPsm6;
                    }
                }

                if (!empty($imeis)) {
                    $allResults[] = new BarcodeResult(
                        text: $text,
                        format: BarcodeFormat::OCR_TEXT,
                        imeis: $imeis,
                        confidence: 0.95,
                        metadata: ['reader' => 'tesseract', 'band' => $band]
                    );

                    foreach ($imeis as $im) {
                        if (LuhnValidator::validate($im)) {
                            $collectedLuhnImeis[$im] = true;
                        }
                    }

                    // If we have found 2 unique Luhn-valid IMEIs (Dual SIM), stop early
                    if (count($collectedLuhnImeis) >= 2) {
                        break;
                    }
                }
            } finally {
                if (file_exists($tmpPng)) {
                    @unlink($tmpPng);
                }
                gc_collect_cycles();
            }
        }

        return $allResults;
    }

    /**
     * Preprocess an image band with memory-safe dimensions, grayscale conversion, and in-place contrast stretching.
     */
    protected function prepareOptimizedImageBand(GdImage $image, int $w, int $h, float $y1Ratio, float $y2Ratio): ?string
    {
        $y1 = (int)($h * $y1Ratio);
        $y2 = (int)($h * $y2Ratio);
        $bh = max(1, $y2 - $y1);

        // Normalize width to ideal OCR target (~1400px), never exceeding 1500px to protect memory
        $targetW = 1400;
        $scale = min(2.5, max(0.3, $targetW / max(1, $w)));
        $sw = min(1500, max(100, (int)round($w * $scale)));
        $sh = min(1500, max(50, (int)round($bh * $scale)));

        $gray = \imagecreatetruecolor($sw, $sh);
        if (!$gray instanceof GdImage) {
            return null;
        }

        // Resample directly from source image into $gray (zero extra image allocations!)
        \imagecopyresampled($gray, $image, 0, 0, 0, $y1, $sw, $sh, $w, $bh);

        // Fast in-place contrast stretch (samples every 4th pixel for speed)
        $minV = 255;
        $maxV = 0;
        for ($y = 0; $y < $sh; $y += 4) {
            for ($x = 0; $x < $sw; $x += 4) {
                $rgb = \imagecolorat($gray, $x, $y);
                $v = (int)((($rgb >> 16 & 0xFF) * 77 + ($rgb >> 8 & 0xFF) * 150 + ($rgb & 0xFF) * 29) >> 8);
                if ($v < $minV) $minV = $v;
                if ($v > $maxV) $maxV = $v;
            }
        }

        $rng = max(1, $maxV - $minV);
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgb = \imagecolorat($gray, $x, $y);
                $v = (int)((($rgb >> 16 & 0xFF) * 77 + ($rgb >> 8 & 0xFF) * 150 + ($rgb & 0xFF) * 29) >> 8);
                $nv = max(0, min(255, (int)(($v - $minV) * 255 / $rng)));
                $c = (int)\imagecolorallocate($gray, $nv, $nv, $nv);
                \imagesetpixel($gray, $x, $y, $c);
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ocr_band_');
        if ($tmpFile === false) {
            \imagedestroy($gray);
            return null;
        }

        $tmpPng = $tmpFile . '.png';
        @unlink($tmpFile);

        \imagepng($gray, $tmpPng);
        \imagedestroy($gray);

        return $tmpPng;
    }

    /**
     * Run tesseract command with optimized arguments.
     */
    protected function runTesseract(string $bin, string $imagePath, string $psm): string
    {
        $process = new Process([
            $bin,
            $imagePath,
            'stdout',
            '-l',
            'eng',
            '--psm',
            $psm,
        ]);

        $process->setTimeout(5.0);
        $process->run();

        if (!$process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }
}
