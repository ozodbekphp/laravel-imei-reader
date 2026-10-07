<?php

declare(strict_types=1);

namespace Ozodbek\LaravelImeiReader\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ozodbek\LaravelImeiReader\Support\LuhnValidator;

class ImeiRule implements ValidationRule
{
    public function __construct(
        protected bool $strictLuhn = true
    ) {
    }

    /**
     * Run the validation rule.
     *
     * @param \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) && !is_numeric($value)) {
            $fail("The :attribute must be a string containing a valid 15-digit IMEI.");
            return;
        }

        $imei = LuhnValidator::sanitize((string) $value);

        if (!LuhnValidator::isValidStructure($imei)) {
            $fail("The :attribute must be a valid 15-digit numeric IMEI.");
            return;
        }

        if ($this->strictLuhn && !LuhnValidator::validate($imei)) {
            $fail("The :attribute has an invalid IMEI Luhn checksum.");
        }
    }
}
