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
            $w = imagesx($image);
            $h = imagesy($image);

            $targetImage = $image;
            $needDestroy = false;
            $maxDim = 600;

            if ($w > $maxDim || $h > $maxDim) {
                if ($w > $h) {
                    $newW = $maxDim;
                    $newH = (int) round(($h / $w) * $maxDim);
                } else {
                    $newH = $maxDim;
                    $newW = (int) round(($w / $h) * $maxDim);
                }

                $resized = imagecreatetruecolor($newW, $newH);
                if ($resized instanceof GdImage) {
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $w, $h);
                    $targetImage = $resized;
                    $needDestroy = true;
                }
            }

            ob_start();
            imagepng($targetImage);
            $blob = (string) ob_get_clean();

            if ($needDestroy && $targetImage instanceof GdImage) {
                imagedestroy($targetImage);
            }

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
