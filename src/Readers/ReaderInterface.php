<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;

interface ReaderInterface
{
    /**
     * Decode barcodes from a GdImage instance.
     *
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array;

    /**
     * Determine if this reader is available and supported in the current environment.
     */
    public function isAvailable(): bool;
}
