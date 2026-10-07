<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

class ImeiScanResult implements JsonSerializable, Countable, IteratorAggregate
{
    /**
     * @param array<string> $imeis List of extracted 15-digit IMEIs
     * @param string|null $primaryImei Primary detected IMEI
     * @param string|null $imei1 Primary IMEI 1
     * @param string|null $imei2 Secondary IMEI 2 (for Dual-SIM devices)
     * @param bool $isLuhnValid True if all extracted IMEIs pass Luhn checksum
     * @param array<string, array<string, mixed>> $imeiDetails Validation details per IMEI
     * @param array<BarcodeResult> $barcodes Raw barcodes detected from image
     * @param array<string> $rawTexts Raw text strings decoded from barcodes
     */
    public function __construct(
        public readonly array $imeis = [],
        public readonly ?string $primaryImei = null,
        public readonly ?string $imei1 = null,
        public readonly ?string $imei2 = null,
        public readonly bool $isLuhnValid = false,
        public readonly array $imeiDetails = [],
        public readonly array $barcodes = [],
        public readonly array $rawTexts = []
    ) {
    }

    /**
     * Check if at least one valid 15-digit IMEI was found.
     */
    public function hasImei(): bool
    {
        return !empty($this->imeis);
    }

    /**
     * Get the count of found IMEIs.
     */
    public function count(): int
    {
        return count($this->imeis);
    }

    /**
     * Get list of all found IMEIs.
     *
     * @return array<string>
     */
    public function getImeis(): array
    {
        return $this->imeis;
    }

    /**
     * Get the first or primary IMEI.
     */
    public function getFirstImei(): ?string
    {
        return $this->primaryImei ?? ($this->imeis[0] ?? null);
    }

    /**
     * Get IMEI 1.
     */
    public function getImei1(): ?string
    {
        return $this->imei1 ?? ($this->imeis[0] ?? null);
    }

    /**
     * Get IMEI 2.
     */
    public function getImei2(): ?string
    {
        return $this->imei2 ?? ($this->imeis[1] ?? null);
    }

    /**
     * Determine if dual SIM IMEIs were detected.
     */
    public function isDualSim(): bool
    {
        return count($this->imeis) >= 2;
    }

    /**
     * Iterate over the list of IMEIs.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->imeis);
    }

    /**
     * Convert to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->hasImei(),
            'count' => $this->count(),
            'primary_imei' => $this->getFirstImei(),
            'imei1' => $this->getImei1(),
            'imei2' => $this->getImei2(),
            'is_dual_sim' => $this->isDualSim(),
            'is_luhn_valid' => $this->isLuhnValid,
            'imeis' => $this->imeis,
            'imei_details' => $this->imeiDetails,
            'barcodes' => array_map(fn (BarcodeResult $b) => $b->toArray(), $this->barcodes),
            'raw_texts' => $this->rawTexts,
        ];
    }

    /**
     * Convert to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return (string) json_encode($this->toArray(), $options | JSON_THROW_ON_ERROR);
    }

    /**
     * Serialize to JSON format.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
