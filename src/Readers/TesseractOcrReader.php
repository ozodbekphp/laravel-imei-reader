<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;
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

        $w = \imagesx($image);
        $h = \imagesy($image);

        // Downscale large images (e.g. 12MP photos) to max 1500px to save RAM and run 5-10x faster
        $targetImage = $image;
        $destroyTarget = false;
        $maxDim = 1500;

        if ($w > $maxDim || $h > $maxDim) {
            $ratio = min($maxDim / $w, $maxDim / $h);
            $newW = (int) round($w * $ratio);
            $newH = (int) round($h * $ratio);

            $resized = \imagecreatetruecolor($newW, $newH);
            if ($resized instanceof GdImage) {
                \imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
                $targetImage = $resized;
                $destroyTarget = true;
            }
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ocr_');
        if ($tmpFile === false) {
            if ($destroyTarget) {
                \imagedestroy($targetImage);
            }
            return [];
        }

        $tmpPng = $tmpFile . '.png';
        @unlink($tmpFile);

        try {
            \imagepng($targetImage, $tmpPng);

            $bin = $this->binaryPath ?? 'tesseract';

            // Pass 1: Fast scan with PSM 6 (single uniform block) + whitelist (digits & IMEI keywords) + no dictionary lookup
            $text = $this->runTesseract($bin, $tmpPng, '6');
            $imeis = ImeiExtractor::extractFromText($text);

            // Pass 2: Fallback scan with PSM 11 (sparse text) if no IMEIs found in Pass 1
            if (empty($imeis)) {
                $textPsm11 = $this->runTesseract($bin, $tmpPng, '11');
                $imeisPsm11 = ImeiExtractor::extractFromText($textPsm11);
                if (!empty($imeisPsm11)) {
                    $text = $textPsm11;
                    $imeis = $imeisPsm11;
                }
            }

            if (empty($imeis)) {
                return [];
            }

            return [
                new BarcodeResult(
                    text: $text,
                    format: BarcodeFormat::OCR_TEXT,
                    imeis: $imeis,
                    confidence: 0.95,
                    metadata: ['reader' => 'tesseract']
                ),
            ];
        } catch (Throwable) {
            return [];
        } finally {
            if ($destroyTarget && $targetImage instanceof GdImage) {
                \imagedestroy($targetImage);
            }
            if (file_exists($tmpPng)) {
                @unlink($tmpPng);
            }
        }
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
            '-c',
            'tessedit_char_whitelist=0123456789IMEIimei/:-\ ',
            '-c',
            'load_system_dawg=0',
            '-c',
            'load_freq_dawg=0',
        ]);

        $process->setTimeout(5.0);
        $process->run();

        if (!$process->isSuccessful()) {
            return '';
        }

        return trim($process->getOutput());
    }
}
