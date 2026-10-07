<?php

declare(strict_types=1);

use Ozodbek\LaravelImeiReader\DTO\ImeiScanResult;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;

if (!function_exists('imei_reader')) {
    /**
     * Get the ImeiReaderManager instance.
     */
    function imei_reader(): ImeiReaderManager
    {
        if (function_exists('app') && class_exists(\Illuminate\Container\Container::class)) {
            return app('imei.reader');
        }
        return new ImeiReaderManager();
    }
}

if (!function_exists('read_imei_from_base64')) {
    /**
     * Extract IMEIs from base64 image string.
     */
    function read_imei_from_base64(string $base64, bool $strictLuhn = false): ImeiScanResult
    {
        return ImeiReaderManager::scanBase64($base64, $strictLuhn);
    }
}

if (!function_exists('read_imei_from_file')) {
    /**
     * Extract IMEIs from an image file path.
     */
    function read_imei_from_file(string $filePath, bool $strictLuhn = false): ImeiScanResult
    {
        return ImeiReaderManager::scanFile($filePath, $strictLuhn);
    }
}
