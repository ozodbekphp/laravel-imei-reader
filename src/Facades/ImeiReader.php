<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Facades;

use GdImage;
use Illuminate\Support\Facades\Facade;
use Ozodbek\LaravelImeiReader\DTO\ImeiScanResult;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Ozodbek\LaravelImeiReader\Readers\ReaderInterface;

/**
 * @method static ImeiReaderManager fromBase64(string $base64)
 * @method static ImeiReaderManager fromFile(string $filePath)
 * @method static ImeiReaderManager fromBinary(string $binaryData)
 * @method static ImeiReaderManager fromGdImage(GdImage $image)
 * @method static ImeiReaderManager strictLuhn(bool $strict = true)
 * @method static ImeiReaderManager enableRotations(bool $enable = true)
 * @method static ImeiReaderManager withReader(ReaderInterface $reader)
 * @method static ImeiScanResult read()
 * @method static array<string> getImeis()
 * @method static string|null getFirstImei()
 * @method static string|null getImei1()
 * @method static string|null getImei2()
 * @method static ImeiScanResult scanBase64(string $base64, bool $strictLuhn = false)
 * @method static ImeiScanResult scanFile(string $filePath, bool $strictLuhn = false)
 * @method static string generateBarcodeBase64(string $imei, int $scale = 3, int $height = 80)
 *
 * @see \Ozodbek\LaravelImeiReader\ImeiReaderManager
 */
class ImeiReader extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'imei.reader';
    }
}
