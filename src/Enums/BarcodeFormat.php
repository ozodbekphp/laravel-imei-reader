<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Enums;

enum BarcodeFormat: string
{
    case CODE_128 = 'CODE_128';
    case CODE_39 = 'CODE_39';
    case EAN_13 = 'EAN_13';
    case ITF_14 = 'ITF_14';
    case QR_CODE = 'QR_CODE';
    case DATA_MATRIX = 'DATA_MATRIX';
    case OCR_TEXT = 'OCR_TEXT';
    case UNKNOWN = 'UNKNOWN';
}
