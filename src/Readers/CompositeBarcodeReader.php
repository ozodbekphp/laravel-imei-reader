<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;

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

        // 1. Try decoding at original orientation (0 deg)
        $this->scanWithAllReaders($image, $results, $seenTexts);
        if (!empty($results)) {
            return $results;
        }

        // 2. Try with grayscale / contrast enhancement
        $gray = ImagePreprocessor::toGrayscale($image);
        $this->scanWithAllReaders($gray, $results, $seenTexts);
        if (!empty($results)) {
            return $results;
        }

        // 3. Try with rotations if enabled (90°, 180°, 270°)
        if ($this->enableRotations) {
            $angles = [90, 180, 270];
            foreach ($angles as $angle) {
                $rotated = ImagePreprocessor::rotate($image, (float) $angle);
                $this->scanWithAllReaders($rotated, $results, $seenTexts);

                if (!empty($results)) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * @param array<BarcodeResult> $results
     * @param array<string, bool> $seenTexts
     */
    protected function scanWithAllReaders(GdImage $image, array &$results, array &$seenTexts): void
    {
        foreach ($this->readers as $reader) {
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
        }
    }
}
