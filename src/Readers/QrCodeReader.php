<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;
use Throwable;
use Zxing\QrReader;

class QrCodeReader implements ReaderInterface
{
    public function isAvailable(): bool
    {
        return class_exists(QrReader::class);
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        try {
            // Convert to PNG blob in memory for maximum compatibility
            ob_start();
            imagepng($image);
            $blob = (string) ob_get_clean();

            $qrReader = new QrReader($blob, QrReader::SOURCE_TYPE_BLOB);
            $text = $qrReader->text();

            if (is_string($text) && trim($text) !== '') {
                $trimmed = trim($text);
                $imeis = ImeiExtractor::extractFromText($trimmed);

                return [
                    new BarcodeResult(
                        text: $trimmed,
                        format: BarcodeFormat::QR_CODE,
                        imeis: $imeis,
                        confidence: 1.0,
                        metadata: ['reader' => 'zxing_qr']
                    )
                ];
            }
        } catch (Throwable) {
            // Ignore decoding failure for QR
        }

        return [];
    }
}
