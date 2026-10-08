# Laravel IMEI & Barcode Reader 📱🔍

[![Latest Version](https://img.shields.io/badge/version-1.1.0-blue.svg)](https://packagist.org/packages/ozodbekphp/laravel-imei-reader)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4.svg)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-10.x%20%7C%2011.x%20%7C%2012.x-FF2D20.svg)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Base64 rasmlardan yoki fayllardan shtrix-kodlar (Code 128, Code 39, EAN-13, ITF, QR Code) hamda bosma matnlardan (OCR) **15 xonali IMEI** raqamlarini o'qiydigan, Dual-SIM telefon qutilarini taniydigan va **Luhn (Mod 10)** algoritmi orqali tekshiradigan yuqori tezlikdagi PHP va Laravel kutubxonasi.

---

## 🌟 Asosiy imkoniyatlari (Features)

- 📸 **Base64 rasmlar va fayllardan o'qish**: `data:image/png;base64,...` yoki toza base64 satrlar va rasm fayllari bilan ishlaydi.
- ⚡ **Tezkor ko'p bosqichli skanerlash (Tiered Pipeline)**: 
  1. Pure PHP 1D scanline (1-2ms)
  2. ZBar C-engine (15ms - qiyshiq/loyqa shtrix-kodlar uchun)
  3. Tesseract OCR (agar shtrix-kod o'qilmasa, shtrix ostidagi "IMEI 1: 86...", "IMEI 2: 86..." yozuvlarini lokal o'qiydi).
- 🔢 **Faqat toza 15 xonali raqamlar**: Matndan harflar, tirelar va belgilarni tozalab, faqat toza 15 xonali raqamlarni ajratadi.
- 📱 **Dual SIM qo'llab-quvvatlash**: 2 ta IMEI bo'lgan qutilardan `IMEI 1` va `IMEI 2` ni alohida ajratib beradi.
- 🛡️ **Luhn algoritmi (Checksum) tekshiruvi**: IMEI ning 15-chi nazorat raqami (Check Digit) to'g'riligini matematik tekshiradi.
- 🔄 **Avtomatik burish (Rotation)**: 90°, 180°, 270° burchak ostidagi shtrix-kodlarni ham taniydi.
- ⚡ **Sof PHP (Pure PHP GD)**: Tashqi tizim dasturlariga majburiy bog'liqlik yo'q (ixtiyoriy ravishda ZBar CLI ham qo'llab-quvvatlanadi).
- 🧩 **Laravel 10 / 11 / 12 integratsiyasi**: Facade, ServiceProvider, Artisan buyruq, Validator qoidasi (`'imei'`, `'imei_strict'`) va helper funksiyalar mavjud.

---

## 🚀 O'rnatish (Installation)

### 1. Packagist orqali (Kutubxona yuklash mutlaqo BEPUL):
```bash
composer require ozodbekphp/laravel-imei-reader
```

### 2. Mahalliy loyihada ishlatish (Local Path Repository):
Agar kutubxonani Packagist ga chiqarmasdan o'z kompyuteringizdagi Laravel loyihangizda to'g'ridan-to'g'ri ishlatmoqchi bo'lsangiz, asosiy loyihangizdagi `composer.json` fayliga quyidagini qo'shing:

```json
"repositories": [
    {
        "type": "path",
        "url": "/home/ozodbek/Documents/Ozodbek/laravel-imei-reader"
    }
]
```
So'ngra buyruqni bering:
```bash
composer require ozodbekphp/laravel-imei-reader
```

---

## 🛠️ Foydalanish (Usage)

### 1. Facade orqali Base64 rasmdan o'qish

```php
use Ozodbek\LaravelImeiReader\Facades\ImeiReader;

// Base64 rasm (masalan, frontend dan kelgan rasm)
$base64Image = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...";

$result = ImeiReader::fromBase64($base64Image)->read();

if ($result->hasImei()) {
    // 1. Birinchi topilgan IMEI
    $imei = $result->getFirstImei(); // "861234567890127"

    // 2. Barcha topilgan IMEI lar massivi
    $allImeis = $result->getImeis(); // ["861234567890127", "356938035643809"]

    // 3. Dual SIM ma'lumotlari
    if ($result->isDualSim()) {
        $imei1 = $result->getImei1(); // "861234567890127"
        $imei2 = $result->getImei2(); // "356938035643809"
    }

    // 4. Luhn tekshiruvi (Check Digit to'g'rimi?)
    $isValid = $result->isLuhnValid; // true / false

    // 5. To'liq natijani Array yoki JSON ko'rinishida olish
    $array = $result->toArray();
    $json = $result->toJson();
}
```

---

### 2. Rasm faylidan o'qish (File Upload)

```php
use Ozodbek\LaravelImeiReader\Facades\ImeiReader;
use Illuminate\Http\Request;

public function uploadBarcode(Request $request)
{
    $request->validate([
        'image' => 'required|image',
    ]);

    $filePath = $request->file('image')->getRealPath();

    // Fayldan o'qish
    $result = ImeiReader::fromFile($filePath)->read();

    return response()->json([
        'success' => $result->hasImei(),
        'imeis' => $result->getImeis(),
        'primary_imei' => $result->getFirstImei(),
        'is_dual_sim' => $result->isDualSim(),
        'details' => $result->imeiDetails,
    ]);
}
```

---

### 3. Qattiq Luhn nazorati (Strict Luhn Validation)

Agar siz faqatgina matematik 15-raqami to'g'ri bo'lgan haqiqiy IMEI larni qabul qilmoqchi bo'lsangiz:

```php
// Faqatgina Luhn formulasi to'g'ri kelgan IMEI larni qaytaradi
$result = ImeiReader::strictLuhn(true)
    ->fromBase64($base64Image)
    ->read();
```

---

### 4. Helper funksiyalar orqali o'qish

```php
// 1. Base64 dan to'g'ridan-to'g'ri o'qish
$result = read_imei_from_base64($base64Image);
echo $result->getFirstImei();

// 2. Fayldan to'g'ridan-to'g'ri o'qish
$result = read_imei_from_file('/path/to/phone_box_barcode.png');
print_r($result->getImeis());
```

---

### 5. Laravel Request Validation Qoidalari

Form request yoki Controller da IMEI ni tekshirish:

```php
use Ozodbek\LaravelImeiReader\Rules\ImeiRule;

// 1-usul: String qoida
$request->validate([
    'imei' => 'required|imei',        // 15 xonali raqam ekanligini tekshiradi
    'strict_imei' => 'required|imei_strict', // 15 xona + Luhn checksum tekshiradi
]);

// 2-usul: Rule obyekti
$request->validate([
    'imei' => ['required', new ImeiRule(strictLuhn: true)],
]);
```

---

### 6. Artisan CLI Buyrug'i orqali tekshirish

Terminalda rasm yoki fayldagi IMEI ni darhol skaner qilish:

```bash
# Fayldan skaner qilish
php artisan imei:read /path/to/sticker.png --file

# Strict tekshiruv bilan
php artisan imei:read /path/to/sticker.png --file --strict
```

---

### 7. Test / Chop etish uchun Barcode yaratish (Utility)

Ixtiyoriy IMEI uchun Code 128 shtrix-kod rasmini Base64 formatida generatsiya qilish:

```php
use Ozodbek\LaravelImeiReader\Facades\ImeiReader;

$base64Barcode = ImeiReader::generateBarcodeBase64("861234567890127");
// Natija: data:image/png;base64,iVBORw...
```

---

## ⚙️ Konfiguratsiya (Config)

Konfiguratsiya faylini loyihangizga ko'chirish:
```bash
php artisan vendor:publish --tag=imei-reader-config
```

`config/imei-reader.php` fayli tarkibi:
```php
return [
    // Luhn tekshiruvi majburiy bo'lsinmi
    'strict_luhn' => false,

    // Rasm burilgan bo'lsa avtomatik 90, 180, 270 gradusga burib qidirish
    'enable_rotations' => true,

    // Aktiv shtrix-kod o'quvchilari
    'readers' => [
        Ozodbek\LaravelImeiReader\Readers\Code128Reader::class,
        Ozodbek\LaravelImeiReader\Readers\Code39Reader::class,
        Ozodbek\LaravelImeiReader\Readers\Ean13Reader::class,
        Ozodbek\LaravelImeiReader\Readers\QrCodeReader::class,
    ],
];
```

---

## ❓ Kutubxonani Packagist.org ga yuklash bepulmi?

**HA, MUTLAQO BEPUL!**
PHP Composer kutubxonalari ekotizimi mutlaqo tekin:
1. Loyihangizni o'zingizning **GitHub** akkauntingizga yuklaysiz (`git push origin main`).
2. [Packagist.org](https://packagist.org) saytiga bepul ro'yxatdan o'tasiz.
3. **Submit** bo'limiga GitHub repository havolasini kiritasiz.
4. Shundan so'ng butun dunyo bo'ylab har qanday dasturchi `composer require ozodbekphp/laravel-imei-reader` orqali bepul yuklab oladi!

---

## 🧪 Testlarni ishga tushirish (Testing)

```bash
composer test
# yoki
./vendor/bin/phpunit
```

---

## 📄 Litsenziya (License)

Ushbu paket [MIT litsenziyasi](LICENSE) ostida ochiq manbali hisoblanadi.
Muallif: **Ozodbek**
