<?php

namespace App\Companies\UseCases\Commands;

use Spatie\LaravelData\Data;

/**
 * Genesis payload for the Company aggregate (CD-1-02). Shape-level rules only —
 * BR/EPF global uniqueness is a cross-record guard enforced at the registration
 * HTTP seam (slice increment 05), not at command construction.
 */
class CreateCompanyCommand extends Data
{
    public function __construct(
        public string $name,
        public string $brNumber,
        public string $epfNumber,
        public string $country,
        public string $ownerUserId,
    ) {}

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'country' => ['required', 'string', 'size:2'],
            'ownerUserId' => ['required', 'uuid'],
        ];
    }
}
