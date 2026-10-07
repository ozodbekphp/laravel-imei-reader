<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Support;

use GdImage;
use Ozodbek\LaravelImeiReader\Exceptions\ImageProcessingException;
use Ozodbek\LaravelImeiReader\Exceptions\InvalidBase64Exception;

class ImagePreprocessor
{
    /**
     * Create a GdImage from a Base64 encoded string.
     *
     * @throws InvalidBase64Exception
     * @throws ImageProcessingException
     */
    public static function fromBase64(string $base64): GdImage
    {
        $cleanBase64 = self::cleanBase64($base64);

        $binaryData = base64_decode($cleanBase64, true);
        if ($binaryData === false) {
            throw new InvalidBase64Exception("Failed to decode base64 string: invalid base64 data.");
        }

        return self::fromBinary($binaryData);
    }

    /**
     * Create a GdImage from binary string data.
     *
     * @throws ImageProcessingException
     */
    public static function fromBinary(string $binaryData): GdImage
    {
        if (strlen($binaryData) === 0) {
            throw new ImageProcessingException("Image binary data is empty.");
        }

        if (!function_exists('imagecreatefromstring')) {
            throw new ImageProcessingException("PHP GD extension (ext-gd) is not enabled on this server.");
        }

        // Suppress warning and check return
        $image = @imagecreatefromstring($binaryData);
        if (!$image instanceof GdImage) {
            throw new ImageProcessingException("Failed to create image from binary data. Format might be unsupported or corrupt.");
        }

        return $image;
    }

    /**
     * Create a GdImage from a local file path.
     *
     * @throws ImageProcessingException
     */
    public static function fromFile(string $filePath): GdImage
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new ImageProcessingException("Image file does not exist or is not readable: {$filePath}");
        }

        $binaryData = file_get_contents($filePath);
        if ($binaryData === false) {
            throw new ImageProcessingException("Failed to read image file: {$filePath}");
        }

        return self::fromBinary($binaryData);
    }

    /**
     * Clean base64 string by removing data URI schemes and whitespaces.
     */
    public static function cleanBase64(string $base64): string
    {
        // Strip data URI prefix if present (e.g. data:image/png;base64,)
        if (str_contains($base64, 'base64,')) {
            $parts = explode('base64,', $base64, 2);
            $base64 = $parts[1] ?? '';
        }

        // Remove all whitespace, newlines, carriage returns
        $clean = preg_replace('/\s+/', '', $base64) ?? '';

        // Handle URL-safe base64
        $clean = strtr($clean, '-_', '+/');

        // Pad if needed
        $mod = strlen($clean) % 4;
        if ($mod > 0) {
            $clean .= str_repeat('=', 4 - $mod);
        }

        return $clean;
    }

    /**
     * Convert an image to grayscale and return a new GdImage.
     */
    public static function toGrayscale(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        $gray = imagecreatetruecolor($w, $h);
        if (!$gray instanceof GdImage) {
            throw new ImageProcessingException("Failed to allocate grayscale image buffer.");
        }

        imagecopy($gray, $image, 0, 0, 0, 0, $w, $h);
        imagefilter($gray, IMG_FILTER_GRAYSCALE);

        return $gray;
    }

    /**
     * Calculate optimal Otsu threshold for binarization.
     */
    public static function calculateOtsuThreshold(GdImage $image): int
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $totalPixels = $w * $h;

        if ($totalPixels === 0) {
            return 128;
        }

        // Compute grayscale histogram
        $histogram = array_fill(0, 256, 0);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($image, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray = (int) (($r * 299 + $g * 587 + $b * 114) / 1000);
                $histogram[$gray]++;
            }
        }

        $sum = 0;
        for ($t = 0; $t < 256; $t++) {
            $sum += $t * $histogram[$t];
        }

        $sumB = 0;
        $wB = 0;
        $maxVar = 0.0;
        $threshold = 128;

        for ($t = 0; $t < 256; $t++) {
            $wB += $histogram[$t];
            if ($wB === 0) {
                continue;
            }

            $wF = $totalPixels - $wB;
            if ($wF === 0) {
                break;
            }

            $sumB += $t * $histogram[$t];
            $mB = $sumB / $wB;
            $mF = ($sum - $sumB) / $wF;

            $varBetween = (float) $wB * (float) $wF * ($mB - $mF) * ($mB - $mF);
            if ($varBetween > $maxVar) {
                $maxVar = $varBetween;
                $threshold = $t;
            }
        }

        return max(20, min(235, $threshold));
    }

    /**
     * Rotate image by angle in degrees.
     */
    public static function rotate(GdImage $image, float $angle): GdImage
    {
        $white = imagecolorallocate($image, 255, 255, 255);
        $rotated = imagerotate($image, $angle, (int) $white);
        if (!$rotated instanceof GdImage) {
            return $image;
        }
        return $rotated;
    }

    /**
     * Extract run-length encoded bars from a row of pixels.
     *
     * @return array<array{black: bool, width: int}>
     */
    public static function extractRowRuns(GdImage $image, int $y, int $threshold = 128): array
    {
        $w = imagesx($image);
        $runs = [];
        $currColor = null;
        $currLen = 0;

        for ($x = 0; $x < $w; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $gray = (int) (($r * 299 + $g * 587 + $b * 114) / 1000);
            $isBlack = ($gray < $threshold);

            if ($currColor === null) {
                $currColor = $isBlack;
                $currLen = 1;
            } elseif ($currColor === $isBlack) {
                $currLen++;
            } else {
                $runs[] = ['black' => $currColor, 'width' => $currLen];
                $currColor = $isBlack;
                $currLen = 1;
            }
        }

        if ($currColor !== null) {
            $runs[] = ['black' => $currColor, 'width' => $currLen];
        }

        return $runs;
    }
}
