<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class SubmitDsarRequestCommand extends Data
{
    public ?string $subject_user_id = null;

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
            'subject_user_id' => ['nullable', 'uuid'],
        ];
    }

    public function subjectUserId(): string
    {
        return $this->subject_user_id ?? $this->requestedByUserId;
    }

    public function isForSomeoneElse(): bool
    {
        return $this->subjectUserId() !== $this->requestedByUserId;
    }
}
