<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class SubmitDsarRequestCommand extends Data
{
    public ?string $subjectUserId = null;

    public string $companyId;

    public string $requestedByUserId;

    /**
     * companyId and requestedByUserId are server-sourced (the company context,
     * the actor) and carry no rule (amendment AQ).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'subjectUserId' => ['nullable', 'uuid'],
        ];
    }

    public function subjectUserId(): string
    {
        return $this->subjectUserId ?? $this->requestedByUserId;
    }

    public function isForSomeoneElse(): bool
    {
        return $this->subjectUserId() !== $this->requestedByUserId;
    }
}
