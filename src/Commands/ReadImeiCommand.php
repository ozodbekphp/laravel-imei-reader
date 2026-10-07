<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Commands;

use Illuminate\Console\Command;
use Ozodbek\LaravelImeiReader\ImeiReaderManager;
use Throwable;

class ReadImeiCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imei:read
                            {input : File path or Base64 string of the barcode image}
                            {--file : Specify if input is a file path}
                            {--strict : Enforce strict Luhn checksum validation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read barcodes and extract 15-digit IMEIs from a base64 image or file';

    /**
     * Execute the console command.
     */
    public function handle(ImeiReaderManager $reader): int
    {
        $input = (string) $this->argument('input');
        $isFile = (bool) $this->option('file') || file_exists($input);
        $strict = (bool) $this->option('strict');

        $this->info("Scanning image for IMEIs...");

        try {
            $scan = $isFile
                ? $reader->strictLuhn($strict)->fromFile($input)->read()
                : $reader->strictLuhn($strict)->fromBase64($input)->read();

            if (!$scan->hasImei()) {
                $this->warn("No 15-digit IMEIs found in the provided image.");
                if (!empty($scan->rawTexts)) {
                    $this->line("Decoded raw texts: " . implode(', ', $scan->rawTexts));
                }
                return self::FAILURE;
            }

            $this->info("Successfully detected " . $scan->count() . " IMEI(s):");

            $rows = [];
            foreach ($scan->getImeis() as $index => $imei) {
                $detail = $scan->imeiDetails[$imei] ?? [];
                $rows[] = [
                    '#' => $index + 1,
                    'IMEI' => $imei,
                    'TAC (Brand/Model)' => $detail['tac'] ?? 'N/A',
                    'Serial' => $detail['serial'] ?? 'N/A',
                    'Check Digit' => $detail['check_digit'] ?? 'N/A',
                    'Luhn Valid' => ($detail['is_luhn_valid'] ?? false) ? '<fg=green>YES</>' : '<fg=red>NO</>',
                ];
            }

            $this->table(['#', 'IMEI', 'TAC', 'Serial', 'Check Digit', 'Luhn Valid'], $rows);

            if ($scan->isDualSim()) {
                $this->line("<fg=cyan>Dual SIM detected:</> IMEI 1: {$scan->getImei1()} | IMEI 2: {$scan->getImei2()}");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Error reading image: " . $e->getMessage());
            return self::FAILURE;
        }
    }
}
