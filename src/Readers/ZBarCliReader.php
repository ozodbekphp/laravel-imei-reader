<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Readers;

use GdImage;
use Ozodbek\LaravelImeiReader\DTO\BarcodeResult;
use Ozodbek\LaravelImeiReader\Enums\BarcodeFormat;
use Ozodbek\LaravelImeiReader\Support\ImeiExtractor;
use Symfony\Component\Process\Process;
use Throwable;

class ZBarCliReader implements ReaderInterface
{
    protected ?string $binaryPath;

    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath;
    }

    public function isAvailable(): bool
    {
        if ($this->binaryPath !== null && is_executable($this->binaryPath)) {
            return true;
        }

        // Check if zbarimg is in PATH
        $process = Process::fromShellCommandline('which zbarimg');
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array<BarcodeResult>
     */
    public function decode(GdImage $image): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'zbar_');
        if ($tmpFile === false) {
            return [];
        }

        $tmpPng = $tmpFile . '.png';
        @unlink($tmpFile);

        try {
            \imagepng($image, $tmpPng);

            $bin = $this->binaryPath ?? 'zbarimg';
            $process = new Process([$bin, '--raw', '-q', $tmpPng]);
            $process->run();

            if (!$process->isSuccessful()) {
                return [];
            }

            $output = trim($process->getOutput());
            if ($output === '') {
                return [];
            }

            $lines = explode("\n", $output);
            $results = [];

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $imeis = ImeiExtractor::extractFromText($line);
                $results[] = new BarcodeResult(
                    text: $line,
                    format: BarcodeFormat::UNKNOWN,
                    imeis: $imeis,
                    confidence: 1.0,
                    metadata: ['reader' => 'zbarimg']
                );
            }

            return $results;
        } catch (Throwable) {
            return [];
        } finally {
            if (file_exists($tmpPng)) {
                @unlink($tmpPng);
            }
        }
    }
}
