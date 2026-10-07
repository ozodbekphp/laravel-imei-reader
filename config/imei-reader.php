<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Strict Luhn Checksum Validation
    |--------------------------------------------------------------------------
    |
    | When enabled, only IMEIs with mathematically valid 15th check digits
    | (calculated using Luhn formula / Modulo 10) will be extracted.
    | Set to false to allow test IMEIs or non-standard check digits.
    |
    */
    'strict_luhn' => false,

    /*
    |--------------------------------------------------------------------------
    | Enable Multi-angle Rotations
    |--------------------------------------------------------------------------
    |
    | When enabled, if no barcode is found in normal orientation (0°),
    | the reader will automatically rotate the image to 90°, 180°, and 270°.
    |
    */
    'enable_rotations' => true,

    /*
    |--------------------------------------------------------------------------
    | Active Decoders
    |--------------------------------------------------------------------------
    |
    | List of barcode readers enabled for scanning.
    |
    */
    'readers' => [
        Ozodbek\LaravelImeiReader\Readers\Code128Reader::class,
        Ozodbek\LaravelImeiReader\Readers\Code39Reader::class,
        Ozodbek\LaravelImeiReader\Readers\Ean13Reader::class,
        Ozodbek\LaravelImeiReader\Readers\QrCodeReader::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | ZBar CLI Driver (Optional)
    |--------------------------------------------------------------------------
    |
    | Path to zbarimg binary if installed on your server (e.g. /usr/bin/zbarimg).
    |
    */
    'zbar_binary' => env('ZBAR_BINARY_PATH', null),
];
