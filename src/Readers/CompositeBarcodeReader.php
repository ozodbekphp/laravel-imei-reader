<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;

class CompositeBarcodeReader implements ReaderInterface
{
    /**
     * @var array<ReaderInterface>
     */
    protected array $readers = [];

    protected bool $enableRotations;

    /**
     * @param array<ReaderInterface> $readers
     */
    public function __construct(array $readers = [], bool $enableRotations = true)
    {
        $this->enableRotations = $enableRotations;

        if (empty($readers)) {
            $this->readers = [
                new Code128Reader(),
                new Code39Reader(),
                new Ean13Reader(),
                new QrCodeReader(),
            ];
        } else {
            $this->readers = $readers;
        }
    }

    public function addReader(ReaderInterface $reader): self
    {
        $this->readers[] = $reader;
        return $this;
    }

    public function setEnableRotations(bool $enable): self
    {
        $this->enableRotations = $enable;
        return $this;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        $results = [];
        $seenTexts = [];

        $scanlineReaders = [];
        $otherReaders = [];

        foreach ($this->readers as $r) {
            if ($r instanceof Code128Reader || $r instanceof Code39Reader || $r instanceof Ean13Reader) {
                $scanlineReaders[] = $r;
            } else {
                $otherReaders[] = $r;
            }
        }

        // 1. FAST PATH: Scan 1D Scanline Readers (Code 128, Code 39, EAN-13)
        if (!empty($scanlineReaders)) {
            $results = $this->decodeScanlines($image, $scanlineReaders, $seenTexts);
            if (!empty($results)) {
                // If we found any barcode containing a 15-digit IMEI, return immediately!
                foreach ($results as $res) {
                    if (!empty($res->imeis)) {
                        return $results;
                    }
                }
            }
        }

        // 2. 2D / Other Readers (QR Code, ZBar, etc.)
        if (!empty($otherReaders)) {
            foreach ($otherReaders as $reader) {
                if (!$reader->isAvailable()) {
                    continue;
                }
                $decodedList = $reader->decode($image);
                foreach ($decodedList as $barcode) {
                    if (!isset($seenTexts[$barcode->text])) {
                        $seenTexts[$barcode->text] = true;
                        $results[] = $barcode;
                    }
                }
                if (!empty($results)) {
                    return $results;
                }
            }

            // If still not found, other custom readers are present, and rotations are enabled
            if ($this->enableRotations && empty($results)) {
                $hasCustomNonQr = false;
                foreach ($otherReaders as $reader) {
                    if (!$reader instanceof QrCodeReader) {
                        $hasCustomNonQr = true;
                        break;
                    }
                }

                if ($hasCustomNonQr) {
                    $angles = [90, 180, 270];
                    foreach ($angles as $angle) {
                        $rotated = ImagePreprocessor::rotate($image, (float) $angle);
                        foreach ($otherReaders as $reader) {
                            if (!$reader->isAvailable() || $reader instanceof QrCodeReader) {
                                continue;
                            }
                            $decodedList = $reader->decode($rotated);
                            foreach ($decodedList as $barcode) {
                                if (!isset($seenTexts[$barcode->text])) {
                                    $seenTexts[$barcode->text] = true;
                                    $results[] = $barcode;
                                }
                            }
                        }
                        if ($rotated instanceof GdImage && $rotated !== $image) {
                            \imagedestroy($rotated);
                        }
                        if (!empty($results)) {
                            return $results;
                        }
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param array<Code128Reader|Code39Reader|Ean13Reader> $scanlineReaders
     * @param array<string, bool> $seenTexts
     * @return array<BarcodeResult>
     */
    protected function decodeScanlines(GdImage $image, array $scanlineReaders, array &$seenTexts): array
    {
        $w = \imagesx($image);
        $h = \imagesy($image);
        if ($w < 5 || $h < 5) {
            return [];
        }

        $otsu = ImagePreprocessor::calculateOtsuThreshold($image);
        $thresholds = array_unique([$otsu, 128, 90, 160]);

        $results = [];

        $hasEnoughImeis = function () use (&$results): bool {
            $total = 0;
            foreach ($results as $r) {
                $total += count($r->imeis);
            }
            return $total >= 2;
        };

        // Phase 1: Horizontal Rows (0 deg & 180 deg)
        $stepY = max(2, (int) ($h / 30));
        foreach ($thresholds as $threshold) {
            for ($y = 2; $y < $h - 2; $y += $stepY) {
                $runs = ImagePreprocessor::extractRowRuns($image, $y, $threshold);
                $this->scanRunsWithReaders($runs, $scanlineReaders, $results, $seenTexts, $y, $threshold);

                if ($this->enableRotations) {
                    $runsRev = array_reverse($runs);
                    $this->scanRunsWithReaders($runsRev, $scanlineReaders, $results, $seenTexts, $y, $threshold);
                }

                if ($hasEnoughImeis()) {
                    return $results;
                }
            }

            if (!empty($results)) {
                $hasImei = false;
                foreach ($results as $r) {
                    if (!empty($r->imeis)) {
                        $hasImei = true;
                        break;
                    }
                }
                if ($hasImei) {
                    return $results;
                }
            }
        }

        // Phase 2: Vertical Columns (90 deg & 270 deg)
        if ($this->enableRotations) {
            $stepX = max(2, (int) ($w / 35));
            foreach ($thresholds as $threshold) {
                for ($x = 2; $x < $w - 2; $x += $stepX) {
                    $runs = ImagePreprocessor::extractColRuns($image, $x, $threshold);
                    // 90 deg
                    $this->scanRunsWithReaders($runs, $scanlineReaders, $results, $seenTexts, $x, $threshold);
                    // 270 deg
                    $runsRev = array_reverse($runs);
                    $this->scanRunsWithReaders($runsRev, $scanlineReaders, $results, $seenTexts, $x, $threshold);

                    if ($hasEnoughImeis()) {
                        return $results;
                    }
                }

                if (!empty($results)) {
                    $hasImei = false;
                    foreach ($results as $r) {
                        if (!empty($r->imeis)) {
                            $hasImei = true;
                            break;
                        }
                    }
                    if ($hasImei) {
                        return $results;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * @param array<array{black: bool, width: int}> $runs
     * @param array<Code128Reader|Code39Reader|Ean13Reader> $scanlineReaders
     * @param array<BarcodeResult> $results
     * @param array<string, bool> $seenTexts
     */
    protected function scanRunsWithReaders(array $runs, array $scanlineReaders, array &$results, array &$seenTexts, int $pos, int $th): void
    {
        foreach ($scanlineReaders as $reader) {
            if (!$reader->isAvailable()) {
                continue;
            }

            $decoded = $reader->decodeRowRuns($runs);
            if ($decoded !== null && !isset($seenTexts[$decoded])) {
                $seenTexts[$decoded] = true;
                $imeis = ImeiExtractor::extractFromText($decoded);
                $format = match (true) {
                    $reader instanceof Code128Reader => BarcodeFormat::CODE_128,
                    $reader instanceof Code39Reader => BarcodeFormat::CODE_39,
                    $reader instanceof Ean13Reader => BarcodeFormat::EAN_13,
                    default => BarcodeFormat::UNKNOWN,
                };

                $results[] = new BarcodeResult(
                    text: $decoded,
                    format: $format,
                    imeis: $imeis,
                    confidence: 1.0,
                    metadata: ['scan_pos' => $pos, 'threshold' => $th]
                );
            }
        }
    }
}
