<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Ozodbek\LaravelImeiReader\Support\ImagePreprocessor;

echo "\n========================================================\n";
echo "   📱 IMEI & BARCODE SCANNER TESTER (Ozodbek) 🔍\n";
echo "========================================================\n\n";

$input = $argv[1] ?? null;

// Agar argument berilmagan bo'lsa, foydalanuvchidan so'raymiz
if ($input === null) {
    echo "Siz o'zingizning Base64 rasmingizni yoki rasm fayl manzilini kiriting:\n";
    echo "(Eslatma: Agar bo'sh qoldirib [Enter] bossangiz, avtomatik DEMO test ishga tushadi)\n\n";
    echo "Kiritish: ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    $input = trim($line !== false ? $line : '');
}

// Agar bo'sh kiritilgan bo'lsa, namunaviy test o'tkazamiz
if (empty($input)) {
    echo "\nℹ️ Siz bo'sh qoldirdingiz. Demo 15-xonali IMEI (861234567890127) uchun shtrix-kod rasmi yaratilib test qilinmoqda...\n\n";
    $demoImei = "861234567890127";
    $input = ImeiReaderManager::generateBarcodeBase64($demoImei);
    echo "Yaratilgan demo Base64: " . substr($input, 0, 60) . "...\n\n";
}

$startTime = microtime(true);

try {
    // Fayl yoki Base64 ekanligini aniqlash
    if (file_exists($input)) {
        echo "📂 Fayldan o'qilmoqda: {$input}\n";
        $scan = ImeiReaderManager::scanFile($input);
    } else {
        echo "🖼️ Base64 rasmdan o'qilmoqda (uzunligi: " . strlen($input) . " belgi)...\n";
        $scan = ImeiReaderManager::scanBase64($input);
    }

    $elapsedMs = round((microtime(true) - $startTime) * 1000, 2);

    echo "\n--------------------------------------------------------\n";
    echo "📊 SKANERLASH NATIJASI (Vaqt: {$elapsedMs} ms):\n";
    echo "--------------------------------------------------------\n";

    if (!$scan->hasImei()) {
        echo "❌ Rasmdan hech qanday 15-xonali IMEI topilmadi!\n";
        if (!empty($scan->rawTexts)) {
            echo "ℹ️ Rasmdan o'qilgan xom matnlar: " . implode(', ', $scan->rawTexts) . "\n";
        }
        echo "\nMaslahat: Rasm aniq va shtrix-kod yaxshi ko'ringanligiga ishonch hosil qiling.\n\n";
        exit(1);
    }

    echo "✅ Muvaffaqiyatli! Topilgan IMEI soni: " . $scan->count() . "\n\n";
    echo "📌 Asosiy IMEI (Primary) : " . $scan->getFirstImei() . "\n";

    if ($scan->isDualSim()) {
        echo "📱 SIM 1 IMEI            : " . $scan->getImei1() . "\n";
        echo "📱 SIM 2 IMEI            : " . $scan->getImei2() . "\n";
    }

    echo "🛡️ Luhn algoritmi        : " . ($scan->isLuhnValid ? "TO'G'RI (Mathematically Valid)" : "NOTO'G'RI") . "\n\n";

    echo "📋 Barcha topilgan IMEI lar tafsilotlari:\n";
    foreach ($scan->getImeis() as $idx => $imei) {
        $details = $scan->imeiDetails[$imei] ?? [];
        $num = $idx + 1;
        echo "  [{$num}] IMEI: {$imei}\n";
        echo "      - TAC (Model kodi)  : " . ($details['tac'] ?? 'N/A') . "\n";
        echo "      - Seriya raqami     : " . ($details['serial'] ?? 'N/A') . "\n";
        echo "      - Nazorat raqami    : " . ($details['check_digit'] ?? 'N/A') . "\n";
        echo "      - Luhn Checksum     : " . (($details['is_luhn_valid'] ?? false) ? "To'g'ri (Valid)" : "Noto'g'ri (Invalid)") . "\n";
    }

    echo "\n🧩 Shtrix-kod formatlari:\n";
    foreach ($scan->barcodes as $barcode) {
        echo "  - Format: {$barcode->format->value} | Matn: {$barcode->text}\n";
    }

    echo "\n📦 To'liq JSON javob:\n";
    echo json_encode($scan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

} catch (Throwable $e) {
    echo "\n❌ Xatolik yuz berdi: " . $e->getMessage() . "\n\n";
    exit(1);
}
