<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\DTO;

use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use JsonSerializable;

class BarcodeResult implements JsonSerializable
{
    /**
     * @param string $text Decoded text from barcode
     * @param BarcodeFormat $format Detected barcode symbology
     * @param array<string> $imeis Extracted IMEIs from this barcode text
     * @param float|null $confidence Confidence level (0.0 to 1.0)
     * @param array<string, mixed> $metadata Extra diagnostic data
     */
    public function __construct(
        public readonly string $text,
        public readonly BarcodeFormat $format,
        public readonly array $imeis = [],
        public readonly ?float $confidence = 1.0,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'format' => $this->format->value,
            'imeis' => $this->imeis,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
