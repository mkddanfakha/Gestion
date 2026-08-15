<?php

namespace App\Rules;

use App\Services\CustomerIdentityService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidIdentityDocumentNumber implements ValidationRule
{
    public function __construct(
        private readonly ?string $nationality,
        private readonly ?string $identityDocumentType,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (!$this->identityDocumentType) {
            return;
        }

        $service = app(CustomerIdentityService::class);
        $error = $service->getDocumentFormatError(
            $this->nationality,
            $this->identityDocumentType,
            (string) $value,
        );

        if ($error !== null) {
            $fail($error);
        }
    }
}
