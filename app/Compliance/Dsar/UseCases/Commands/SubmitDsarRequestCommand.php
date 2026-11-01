<?php

namespace App\Compliance\Dsar\UseCases\Commands;

use App\Models\User;
use Spatie\LaravelData\Data;
use App\Companies\Entities\Company;
use Spatie\LaravelData\Attributes\WithCast;
use App\Foundation\UseCases\Casts\ModelCast;
use App\Foundation\UseCases\Attributes\OpaqueString;
use Spatie\LaravelData\Support\Validation\ValidationContext;

class SubmitDsarRequestCommand extends Data
{
    #[OpaqueString('authorization depends on it - resolved after the for-others permission check')]
    public ?string $subjectUserId = null;

    #[WithCast(ModelCast::class, Company::class)]
    public Company $company;

    #[WithCast(ModelCast::class, User::class)]
    public User $requestedBy;

    /**
     * company and requestedBy are server-sourced (the company context, the
     * actor) and carry no rule (amendment AQ).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'subjectUserId' => ['nullable', 'uuid'],
        ];
    }

    public function resolvedSubjectUserId(): string
    {
        return $this->subjectUserId ?? $this->requestedBy->getKey();
    }

    public function isForSomeoneElse(): bool
    {
        return $this->resolvedSubjectUserId() !== $this->requestedBy->getKey();
    }
}
