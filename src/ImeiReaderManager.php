<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\ImeiScanResult;
use Ozodbek\LaravelImeiReader\Exceptions\ImageProcessingException;
use Ozodbek\LaravelImeiReader\Exceptions\InvalidBase64Exception;
use Ozodbek\LaravelImeiReader\Readers\CompositeBarcodeReader;
use Ozodbek\LaravelImeiReader\Readers\ReaderInterface;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;

class ImeiReaderManager
{
    protected ?GdImage $currentImage = null;
    protected bool $strictLuhn = false;
    protected bool $enableRotations = true;
    protected ?ReaderInterface $customReader = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected array $config = []
    ) {
        $this->strictLuhn = (bool) ($config['strict_luhn'] ?? false);
        $this->enableRotations = (bool) ($config['enable_rotations'] ?? true);
    }

    /**
     * Load image from Base64 encoded string.
     *
     * @throws InvalidBase64Exception
     * @throws ImageProcessingException
     */
    public function fromBase64(string $base64): self
    {
        $clone = clone $this;
        $clone->currentImage = ImagePreprocessor::fromBase64($base64);
        return $clone;
    }

    /**
     * Load image from local file path.
     *
     * @throws ImageProcessingException
     */
    public function fromFile(string $filePath): self
    {
        $clone = clone $this;
        $clone->currentImage = ImagePreprocessor::fromFile($filePath);
        return $clone;
    }

    /**
     * Load image from raw binary data.
     *
     * @throws ImageProcessingException
     */
    public function fromBinary(string $binaryData): self
    {
        $clone = clone $this;
        $clone->currentImage = ImagePreprocessor::fromBinary($binaryData);
        return $clone;
    }

    /**
     * Load image from existing GdImage instance.
     */
    public function fromGdImage(GdImage $image): self
    {
        $clone = clone $this;
        $clone->currentImage = $image;
        return $clone;
    }

    /**
     * Set whether strict Luhn checksum validation is enforced.
     */
    public function strictLuhn(bool $strict = true): self
    {
        $this->strictLuhn = $strict;
        return $this;
    }

    /**
     * Enable or disable rotation checks (90°, 180°, 270°).
     */
    public function enableRotations(bool $enable = true): self
    {
        $this->enableRotations = $enable;
        return $this;
    }

    /**
     * Set a custom barcode reader engine.
     */
    public function withReader(ReaderInterface $reader): self
    {
        $this->customReader = $reader;
        return $this;
    }

    /**
     * Read and decode barcodes from the loaded image and extract IMEIs.
     *
     * @throws ImageProcessingException
     */
    public function read(): ImeiScanResult
    {
        if (!$this->currentImage instanceof GdImage) {
            throw new ImageProcessingException("No image has been loaded. Call fromBase64(), fromFile(), or fromBinary() first.");
        }

        $reader = $this->customReader ?? new CompositeBarcodeReader(enableRotations: $this->enableRotations);
        $barcodes = $reader->decode($this->currentImage);

        return ImeiExtractor::buildScanResult($barcodes, $this->strictLuhn);
    }

    /**
     * Read IMEIs directly and return list of strings.
     *
     * @return array<string>
     */
    public function getImeis(): array
    {
        return $this->read()->getImeis();
    }

    /**
     * Read the first detected IMEI.
     */
    public function getFirstImei(): ?string
    {
        return $this->read()->getFirstImei();
    }

    /**
     * Read IMEI 1 (primary).
     */
    public function getImei1(): ?string
    {
        return $this->read()->getImei1();
    }

    /**
     * Read IMEI 2 (secondary).
     */
    public function getImei2(): ?string
    {
        return $this->read()->getImei2();
    }

    /**
     * Convenience static helper to scan base64.
     */
    public static function scanBase64(string $base64, bool $strictLuhn = false): ImeiScanResult
    {
        $instance = new self(['strict_luhn' => $strictLuhn]);
        return $instance->fromBase64($base64)->read();
    }

    /**
     * Convenience static helper to scan file.
     */
    public static function scanFile(string $filePath, bool $strictLuhn = false): ImeiScanResult
    {
        $instance = new self(['strict_luhn' => $strictLuhn]);
        return $instance->fromFile($filePath)->read();
    }

    /**
     * Utility: Generate a Code 128 barcode image as Base64 string for a given IMEI.
     */
    public static function generateBarcodeBase64(string $imei, int $scale = 3, int $height = 80): string
    {
        $patterns = [
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

        // Code 128 Set C encoding for digits + Code B for 15th digit
        $symbols = [105]; // Start C
        for ($i = 0; $i < strlen($imei) - 1; $i += 2) {
            $symbols[] = (int) substr($imei, $i, 2);
        }
        $symbols[] = 100; // Code B
        $symbols[] = ord($imei[strlen($imei) - 1]) - 32;

        $chk = $symbols[0];
        for ($i = 1; $i < count($symbols); $i++) {
            $chk += $i * $symbols[$i];
        }
        $symbols[] = $chk % 103;
        $symbols[] = 106; // Stop

        $bars = '';
        foreach ($symbols as $sym) {
            $pat = $patterns[$sym];
            for ($j = 0; $j < strlen($pat); $j++) {
                $bars .= str_repeat($j % 2 === 0 ? '1' : '0', (int) $pat[$j]);
            }
        }

        $quietZone = 30;
        $w = (strlen($bars) * $scale) + ($quietZone * 2);
        $im = \imagecreatetruecolor($w, $height);
        if (!$im instanceof GdImage) {
            throw new ImageProcessingException("Failed to create barcode image.");
        }

        $white = (int) \imagecolorallocate($im, 255, 255, 255);
        $black = (int) \imagecolorallocate($im, 0, 0, 0);
        \imagefilledrectangle($im, 0, 0, $w, $height, $white);

        for ($i = 0; $i < strlen($bars); $i++) {
            if ($bars[$i] === '1') {
                \imagefilledrectangle(
                    $im,
                    $quietZone + ($i * $scale),
                    8,
                    $quietZone + (($i + 1) * $scale) - 1,
                    $height - 8,
                    $black
                );
            }
        }

        ob_start();
        \imagepng($im);
        $png = (string) ob_get_clean();
        \imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($png);
    }
}
